<?php
/**
 * Main crawler orchestrator for WP-Rank
 * 
 * Coordinates WordPress detection, plugin estimation, PSI analysis,
 * and database storage for discovered domains.
 */

namespace WPRank\Crawler;

use WPRank\Database;
use WPRank\Utils\Uuid;
use WPRank\Config;

class Crawler 
{
    private WordPressDetector $wpDetector;
    private PageSpeedClient $psiClient;
    private array $domainLastRequest = [];
    private float $rateLimitDelay;
    
    public function __construct() 
    {
        $this->wpDetector = new WordPressDetector();
        $this->psiClient = new PageSpeedClient();
        
        // Calculate delay between requests per domain (respecting rate limits)
        $rps = Config::get('crawler.per_domain_rps', 1);
        $this->rateLimitDelay = 1.0 / $rps;
    }
    
    /**
     * Crawl a single domain and store results in database
     * 
     * @param string $domain Domain to crawl
     * @return array Crawl results
     */
    public function crawlDomain(string $domain): array 
    {
        $crawlId = Uuid::v4();
        $startTime = microtime(true);
        
        $result = [
            'domain' => $domain,
            'crawl_id' => $crawlId,
            'success' => false,
            'error' => null,
            'wp_detected' => false,
            'psi_score' => null,
            'plugin_count' => null,
            'processing_time' => 0
        ];
        
        try {
            // Apply rate limiting
            $this->applyRateLimit($domain);
            
            if (Config::isDebug()) {
                error_log("Starting crawl for domain: {$domain}");
            }
            
            // Step 1: WordPress analysis
            $wpAnalysis = $this->wpDetector->analyzeWordPressSite($domain);
            
            if (isset($wpAnalysis['error'])) {
                $result['error'] = $wpAnalysis['error'];
                $this->recordCrawlError($domain, $wpAnalysis['error']);
                return $result;
            }
            
            $result['wp_detected'] = $wpAnalysis['is_wordpress'];
            $result['plugin_count'] = $wpAnalysis['plugin_count'];
            
            Database::beginTransaction();
            
            try {
                // Step 2: Upsert site record
                $siteId = $this->upsertSite($domain, $wpAnalysis);
                
                // Step 3: PSI analysis (for WordPress sites or if configured for all)
                $psiMetrics = null;
                if ($wpAnalysis['is_wordpress'] || Config::get('crawler.analyze_all_sites', false)) {
                    $psiMetrics = $this->psiClient->fetchMetrics($domain);
                    $result['psi_score'] = $psiMetrics['psi_score'] ?? null;
                }
                
                // Step 4: Store metrics
                $this->storeSiteMetrics($siteId, $crawlId, $wpAnalysis, $psiMetrics);
                
                // Step 5: Update site status
                $this->updateSiteStatus($siteId, 'active');
                
                Database::commit();
                
                $result['success'] = true;
                $result['processing_time'] = round(microtime(true) - $startTime, 2);
                
                if (Config::isDebug()) {
                    error_log("Successfully crawled {$domain} - WP: {$wpAnalysis['is_wordpress']}, Plugins: {$wpAnalysis['plugin_count']}, PSI: " . ($psiMetrics['psi_score'] ?? 'N/A'));
                }
                
            } catch (\Exception $e) {
                Database::rollback();
                throw $e;
            }
            
        } catch (\Exception $e) {
            $result['error'] = 'crawl_failed: ' . $e->getMessage();
            $this->recordCrawlError($domain, $result['error']);
            
            error_log("Crawl failed for {$domain}: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Apply rate limiting per domain
     * 
     * @param string $domain Domain being crawled
     */
    private function applyRateLimit(string $domain): void 
    {
        $now = microtime(true);
        $lastRequest = $this->domainLastRequest[$domain] ?? 0;
        $timeSinceLastRequest = $now - $lastRequest;
        
        if ($timeSinceLastRequest < $this->rateLimitDelay) {
            $sleepTime = $this->rateLimitDelay - $timeSinceLastRequest;
            usleep((int)($sleepTime * 1000000)); // Convert to microseconds
        }
        
        $this->domainLastRequest[$domain] = microtime(true);
    }
    
    /**
     * Insert or update site record
     * 
     * @param string $domain Domain name
     * @param array $wpAnalysis WordPress analysis results
     * @return string Site ID
     */
    private function upsertSite(string $domain, array $wpAnalysis): string 
    {
        // Check if site exists
        $stmt = Database::execute(
            "SELECT id FROM sites WHERE domain = ?",
            [$domain]
        );
        
        $existingSite = $stmt->fetch();
        
        if ($existingSite) {
            // Update existing site
            $siteId = $existingSite['id'];
            
            Database::execute(
                "UPDATE sites SET 
                 is_wordpress = ?, 
                 theme_name = ?, 
                 last_crawl_at = NOW(), 
                 status = 'active' 
                 WHERE id = ?",
                [
                    $wpAnalysis['is_wordpress'] ? 1 : 0,
                    $wpAnalysis['theme_name'],
                    $siteId
                ]
            );
            
        } else {
            // Insert new site
            $siteId = Uuid::v4();
            
            Database::execute(
                "INSERT INTO sites (id, domain, is_wordpress, theme_name, status) 
                 VALUES (?, ?, ?, ?, 'active')",
                [
                    $siteId,
                    $domain,
                    $wpAnalysis['is_wordpress'] ? 1 : 0,
                    $wpAnalysis['theme_name']
                ]
            );
        }
        
        return $siteId;
    }
    
    /**
     * Store site metrics from crawl
     * 
     * @param string $siteId Site ID
     * @param string $crawlId Crawl ID
     * @param array $wpAnalysis WordPress analysis results
     * @param array|null $psiMetrics PSI metrics (optional)
     */
    private function storeSiteMetrics(string $siteId, string $crawlId, array $wpAnalysis, ?array $psiMetrics): void 
    {
        $metricsId = Uuid::v4();
        
        Database::execute(
            "INSERT INTO site_metrics (
                id, site_id, crawl_id, 
                psi_score, lcp_ms, cls, tbt_ms,
                plugin_est_count, theme_name, evidence
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $metricsId,
                $siteId,
                $crawlId,
                $psiMetrics['psi_score'] ?? null,
                $psiMetrics['lcp_ms'] ?? null,
                $psiMetrics['cls'] ?? null,
                $psiMetrics['tbt_ms'] ?? null,
                $wpAnalysis['plugin_count'],
                $wpAnalysis['theme_name'],
                json_encode($wpAnalysis['plugin_evidence'])
            ]
        );
    }
    
    /**
     * Update site status
     * 
     * @param string $siteId Site ID
     * @param string $status New status
     */
    private function updateSiteStatus(string $siteId, string $status): void 
    {
        Database::execute(
            "UPDATE sites SET status = ?, last_crawl_at = NOW() WHERE id = ?",
            [$status, $siteId]
        );
    }
    
    /**
     * Record crawl error in queue table
     * 
     * @param string $domain Domain that failed
     * @param string $error Error message
     */
    private function recordCrawlError(string $domain, string $error): void 
    {
        try {
            Database::execute(
                "UPDATE crawl_queue SET 
                 attempt_count = attempt_count + 1,
                 last_error = ?,
                 next_attempt_at = DATE_ADD(NOW(), INTERVAL LEAST(attempt_count * 15, 240) MINUTE)
                 WHERE domain = ?",
                [substr($error, 0, 1000), $domain]
            );
        } catch (\Exception $e) {
            error_log("Failed to record crawl error for {$domain}: " . $e->getMessage());
        }
    }
    
    /**
     * Crawl multiple domains from the queue
     * 
     * @param int $limit Maximum number of domains to crawl
     * @return array Summary of crawl results
     */
    public function crawlFromQueue(int $limit = 20): array 
    {
        $stmt = Database::execute(
            "SELECT id, domain FROM crawl_queue 
             WHERE next_attempt_at <= NOW() 
             ORDER BY priority ASC, next_attempt_at ASC 
             LIMIT ?",
            [$limit]
        );
        
        $queueItems = $stmt->fetchAll();
        $results = [
            'total_processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        foreach ($queueItems as $item) {
            $crawlResult = $this->crawlDomain($item['domain']);
            $results['details'][] = $crawlResult;
            $results['total_processed']++;
            
            if ($crawlResult['success']) {
                $results['successful']++;
                
                // Remove from queue on success
                Database::execute(
                    "DELETE FROM crawl_queue WHERE id = ?",
                    [$item['id']]
                );
                
            } else {
                $results['failed']++;
            }
            
            // Small delay between crawls for ethical behavior
            if (count($queueItems) > 1) {
                sleep(1);
            }
        }
        
        return $results;
    }
    
    /**
     * Get crawl queue statistics
     * 
     * @return array Queue statistics
     */
    public function getQueueStats(): array 
    {
        $stmt = Database::execute(
            "SELECT 
                COUNT(*) as total_queued,
                COUNT(CASE WHEN next_attempt_at <= NOW() THEN 1 END) as ready_now,
                COUNT(CASE WHEN attempt_count = 0 THEN 1 END) as new_items,
                COUNT(CASE WHEN attempt_count > 0 THEN 1 END) as retry_items,
                AVG(attempt_count) as avg_attempts
             FROM crawl_queue"
        );
        
        return $stmt->fetch() ?: [];
    }
}