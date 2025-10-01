<?php
/**
 * Submission service for WP-Rank
 * 
 * Handles user submissions of WordPress sites for analysis and ranking.
 * Includes validation, spam prevention, and queue management.
 */

namespace WPRank\Services;

use WPRank\Database;
use WPRank\Utils\Uuid;
use WPRank\Utils\Domain;
use WPRank\Config;

class SubmissionService 
{
    private int $maxSubmissionsPerIP = 10;
    private int $maxSubmissionsPerHour = 100;
    
    public function __construct() 
    {
        $this->maxSubmissionsPerIP = Config::get('submissions.max_per_ip', 10);
        $this->maxSubmissionsPerHour = Config::get('submissions.max_per_hour', 100);
    }
    
    /**
     * Submit a domain for analysis
     * 
     * @param string $rawUrl Raw URL or domain input
     * @param string|null $userIP User's IP address for spam prevention
     * @param array $options Additional options
     * @return array Submission result
     */
    public function submitDomain(string $rawUrl, ?string $userIP = null, array $options = []): array 
    {
        $result = [
            'success' => false,
            'domain' => null,
            'message' => '',
            'submission_id' => null
        ];
        
        try {
            // Step 1: Validate and normalize domain
            $domain = Domain::normalize($rawUrl);
            
            if (!$domain) {
                $result['message'] = 'Invalid domain format';
                return $result;
            }
            
            $result['domain'] = $domain;
            
            // Step 2: Check if domain is valid
            if (!Domain::isValid($domain)) {
                $result['message'] = 'Domain format is not valid';
                return $result;
            }
            
            // Step 3: Anti-spam checks
            $spamCheck = $this->performSpamChecks($domain, $userIP);
            if (!$spamCheck['allowed']) {
                $result['message'] = $spamCheck['reason'];
                return $result;
            }
            
            // Step 4: Check if domain is already in system
            $existingStatus = $this->getDomainStatus($domain);
            if ($existingStatus) {
                $result['message'] = $this->getExistingDomainMessage($existingStatus);
                $result['existing_status'] = $existingStatus;
                return $result;
            }
            
            Database::beginTransaction();
            
            try {
                // Step 5: Record submission
                $submissionId = $this->recordSubmission($domain, $userIP, $options);
                $result['submission_id'] = $submissionId;
                
                // Step 6: Add to crawl queue
                $this->enqueueCrawl($domain, $options);
                
                Database::commit();
                
                $result['success'] = true;
                $result['message'] = 'Domain submitted successfully and queued for analysis';
                
                if (Config::isDebug()) {
                    error_log("Domain submitted successfully: {$domain} (ID: {$submissionId})");
                }
                
            } catch (\Exception $e) {
                Database::rollback();
                throw $e;
            }
            
        } catch (\Exception $e) {
            error_log("Submission failed for {$rawUrl}: " . $e->getMessage());
            $result['message'] = 'Submission failed due to a system error';
        }
        
        return $result;
    }
    
    /**
     * Perform anti-spam and rate limiting checks
     * 
     * @param string $domain Domain being submitted
     * @param string|null $userIP User's IP address
     * @return array Check results
     */
    private function performSpamChecks(string $domain, ?string $userIP): array 
    {
        // Check 1: Rate limiting by IP
        if ($userIP) {
            $ipHash = hash('sha256', $userIP);
            
            // Check submissions in last hour
            $stmt = Database::execute(
                "SELECT COUNT(*) FROM submissions 
                 WHERE ip_hash = ? AND submitted_at > " . Database::getDateSubQuery('1 HOUR'),
                [$ipHash]
            );
            
            $recentSubmissions = (int)$stmt->fetchColumn();
            
            if ($recentSubmissions >= $this->maxSubmissionsPerIP) {
                return [
                    'allowed' => false,
                    'reason' => 'Too many submissions from your IP address. Please try again later.'
                ];
            }
        }
        
        // Check 2: Global rate limiting
        $stmt = Database::execute(
            "SELECT COUNT(*) FROM submissions 
             WHERE submitted_at > " . Database::getDateSubQuery('1 HOUR')
        );
        
        $globalSubmissions = (int)$stmt->fetchColumn();
        
        if ($globalSubmissions >= $this->maxSubmissionsPerHour) {
            return [
                'allowed' => false,
                'reason' => 'System is currently at capacity. Please try again later.'
            ];
        }
        
        // Check 3: Domain-specific checks
        if ($this->isDomainBlacklisted($domain)) {
            return [
                'allowed' => false,
                'reason' => 'This domain cannot be submitted for analysis.'
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Check if domain is blacklisted
     * 
     * @param string $domain Domain to check
     * @return bool True if blacklisted
     */
    private function isDomainBlacklisted(string $domain): bool 
    {
        // List of domains/patterns that should not be analyzed
        $blacklistedPatterns = [
            'localhost',
            '127.0.0.1',
            '192.168.',
            '10.0.',
            '172.16.',
            'test.com',
            'example.com',
            'example.org'
        ];
        
        foreach ($blacklistedPatterns as $pattern) {
            if (str_contains($domain, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get existing domain status in the system
     * 
     * @param string $domain Domain to check
     * @return array|null Existing status or null if not found
     */
    private function getDomainStatus(string $domain): ?array 
    {
        // Check if site exists
        $stmt = Database::execute(
            "SELECT id, status, is_wordpress, last_crawl_at FROM sites WHERE domain = ?",
            [$domain]
        );
        
        $site = $stmt->fetch();
        
        if ($site) {
            // Check if there's a recent submission
            $stmt = Database::execute(
                "SELECT status, submitted_at FROM submissions 
                 WHERE domain = ? 
                 ORDER BY submitted_at DESC 
                 LIMIT 1",
                [$domain]
            );
            
            $recentSubmission = $stmt->fetch();
            
            return [
                'site_status' => $site['status'],
                'is_wordpress' => $site['is_wordpress'],
                'last_crawl' => $site['last_crawl_at'],
                'recent_submission' => $recentSubmission
            ];
        }
        
        // Check if domain is in queue
        $stmt = Database::execute(
            "SELECT next_attempt_at, attempt_count FROM crawl_queue WHERE domain = ?",
            [$domain]
        );
        
        $queueItem = $stmt->fetch();
        
        if ($queueItem) {
            return [
                'queue_status' => 'queued',
                'next_attempt' => $queueItem['next_attempt_at'],
                'attempt_count' => $queueItem['attempt_count']
            ];
        }
        
        return null;
    }
    
    /**
     * Get message for existing domain
     * 
     * @param array $status Existing domain status
     * @return string Message for user
     */
    private function getExistingDomainMessage(array $status): string 
    {
        if (isset($status['site_status'])) {
            switch ($status['site_status']) {
                case 'active':
                    if ($status['is_wordpress']) {
                        return 'This domain is already analyzed and ranked in our system.';
                    } else {
                        return 'This domain has been analyzed but is not running WordPress.';
                    }
                case 'blocked':
                    return 'This domain cannot be analyzed (blocked by robots.txt or other restrictions).';
                case 'error':
                    return 'This domain had analysis errors. It will be retried automatically.';
                default:
                    return 'This domain is already in our system.';
            }
        }
        
        if (isset($status['queue_status'])) {
            return 'This domain is already queued for analysis.';
        }
        
        return 'This domain is already known to our system.';
    }
    
    /**
     * Record submission in database
     * 
     * @param string $domain Domain being submitted
     * @param string|null $userIP User's IP address
     * @param array $options Submission options
     * @return string Submission ID
     */
    private function recordSubmission(string $domain, ?string $userIP, array $options): string 
    {
        $submissionId = Uuid::v4();
        $ipHash = $userIP ? hash('sha256', $userIP) : null;
        
        Database::execute(
            "INSERT INTO submissions (id, domain, status, ip_hash, submitted_at) VALUES (?, ?, 'queued', ?, " . Database::getCurrentTimestamp() . ")",
            [$submissionId, $domain, $ipHash]
        );
        
        return $submissionId;
    }
    
    /**
     * Add domain to crawl queue
     * 
     * @param string $domain Domain to queue
     * @param array $options Queue options
     */
    private function enqueueCrawl(string $domain, array $options): void 
    {
        // Check if already in queue
        $stmt = Database::execute(
            "SELECT id FROM crawl_queue WHERE domain = ?",
            [$domain]
        );
        
        if ($stmt->fetch()) {
            return; // Already queued
        }
        
        $queueId = Uuid::v4();
        $priority = (int)($options['priority'] ?? 50); // User submissions get higher priority
        
        Database::execute(
            "INSERT INTO crawl_queue (id, domain, priority, status, next_attempt_at, attempt_count, created_at, updated_at) 
             VALUES (?, ?, ?, 'pending', " . Database::getCurrentTimestamp() . ", 0, " . Database::getCurrentTimestamp() . ", " . Database::getCurrentTimestamp() . ")",
            [$queueId, $domain, $priority]
        );
    }
    
    /**
     * Get submission statistics
     * 
     * @return array Submission statistics
     */
    public function getSubmissionStats(): array 
    {
        $stmt = Database::execute("
            SELECT 
                COUNT(*) as total_submissions,
                COUNT(CASE WHEN status = 'queued' THEN 1 END) as queued,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'done' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
                COUNT(CASE WHEN status = 'error' THEN 1 END) as errors,
                COUNT(CASE WHEN submitted_at > " . Database::getDateSubQuery('24 HOUR') . " THEN 1 END) as last_24h,
                COUNT(CASE WHEN submitted_at > " . Database::getDateSubQuery('1 HOUR') . " THEN 1 END) as last_hour
            FROM submissions
        ");
        
        $stats = $stmt->fetch();
        
        return [
            'total_submissions' => (int)($stats['total_submissions'] ?? 0),
            'by_status' => [
                'queued' => (int)($stats['queued'] ?? 0),
                'processing' => (int)($stats['processing'] ?? 0),
                'completed' => (int)($stats['completed'] ?? 0),
                'rejected' => (int)($stats['rejected'] ?? 0),
                'errors' => (int)($stats['errors'] ?? 0)
            ],
            'recent_activity' => [
                'last_24_hours' => (int)($stats['last_24h'] ?? 0),
                'last_hour' => (int)($stats['last_hour'] ?? 0)
            ]
        ];
    }
    
    /**
     * Get recent submissions for admin monitoring
     * 
     * @param int $limit Number of submissions to retrieve
     * @return array Recent submissions
     */
    public function getRecentSubmissions(int $limit = 50): array 
    {
        $stmt = Database::execute(
            "SELECT domain, status, submitted_at, ip_hash 
             FROM submissions 
             ORDER BY submitted_at DESC 
             LIMIT ?",
            [$limit]
        );
        
        return $stmt->fetchAll();
    }
}