<?php

namespace WPRank\Services;

use WPRank\Database;
use WPRank\Config;
use WPRank\Utils\Uuid;
use Exception;
use PDO;

class QueueProcessor {
    
    private Database $db;
    private Config $config;
    private AnalysisService $analysisService;
    private RankingService $rankingService;
    private array $logger;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->config = new Config();
        $this->analysisService = new AnalysisService();
        $this->rankingService = new RankingService();
        $this->logger = [];
    }
    
    /**
     * Process the crawl queue continuously
     */
    public function processQueue(array $options = []): void {
        $batchSize = $options['batch_size'] ?? 10;
        $maxRetries = $options['max_retries'] ?? 3;
        $verbose = $options['verbose'] ?? false;
        $continuous = $options['continuous'] ?? true;
        
        $this->log("Queue processor started", $verbose);
        
        do {
            try {
                $processed = $this->processBatch($batchSize, $maxRetries, $verbose);
                
                if ($processed === 0 && $continuous) {
                    $this->log("No items to process, sleeping for 30 seconds...", $verbose);
                    sleep(30);
                } elseif ($processed === 0) {
                    $this->log("No items to process, exiting...", $verbose);
                    break;
                }
                
                // Brief pause between batches to prevent overwhelming the system
                if ($processed > 0 && $continuous) {
                    sleep(5);
                }
                
            } catch (Exception $e) {
                $this->log("Error in queue processing: " . $e->getMessage(), true);
                if (!$continuous) {
                    throw $e;
                }
                sleep(60); // Wait longer on error
            }
            
        } while ($continuous);
        
        $this->log("Queue processor finished", $verbose);
    }
    
    /**
     * Process a batch of queue items
     */
    private function processBatch(int $batchSize, int $maxRetries, bool $verbose): int {
        $items = $this->getQueueItems($batchSize, $maxRetries);
        
        if (empty($items)) {
            return 0;
        }
        
        $this->log("Processing batch of " . count($items) . " items", $verbose);
        
        foreach ($items as $item) {
            try {
                $this->processQueueItem($item, $verbose);
            } catch (Exception $e) {
                $this->log("Error processing {$item['domain']}: " . $e->getMessage(), true);
                $this->markItemFailed($item['id'], $e->getMessage());
            }
        }
        
        return count($items);
    }
    
    /**
     * Get queue items to process
     */
    private function getQueueItems(int $limit, int $maxRetries): array {
        $sql = "
            SELECT id, domain, priority, attempt_count, created_at
            FROM crawl_queue 
            WHERE status = 'pending' 
            AND attempt_count < ? 
            AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
            ORDER BY priority DESC, created_at ASC 
            LIMIT ?
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$maxRetries, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Process a single queue item
     */
    private function processQueueItem(array $item, bool $verbose): void {
        $domain = $item['domain'];
        $this->log("Processing: {$domain}", $verbose);
        
        // Mark as processing
        $this->updateQueueItemStatus($item['id'], 'processing');
        
        try {
            // Check if site exists and is WordPress
            if (!$this->isWordPressSite($domain)) {
                $this->markItemCompleted($item['id'], 'not_wordpress');
                $this->log("Skipped {$domain}: Not a WordPress site", $verbose);
                return;
            }
            
            // Analyze the site
            $analysis = $this->analysisService->analyzeSite($domain);
            
            if (!$analysis) {
                throw new Exception("Failed to analyze site");
            }
            
            // Save or update site data
            $this->saveSiteData($domain, $analysis);
            
            // Update rankings
            $this->rankingService->updateSiteRanking($domain);
            
            // Mark as completed
            $this->markItemCompleted($item['id'], 'completed');
            $this->log("Completed: {$domain}", $verbose);
            
        } catch (Exception $e) {
            $this->markItemRetry($item['id'], $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if a domain is a WordPress site
     */
    private function isWordPressSite(string $domain): bool {
        try {
            $url = "https://{$domain}";
            
            // Try to fetch the homepage
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'WP-Rank-Bot/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_MAXREDIRS => 5
            ]);
            
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$html) {
                return false;
            }
            
            // Check for WordPress indicators
            $wpIndicators = [
                '/wp-content/',
                '/wp-includes/',
                'wp-json',
                'wordpress',
                'generator.*wordpress',
                'wp-version',
                '/wp-admin/',
                'wpnonce'
            ];
            
            foreach ($wpIndicators as $indicator) {
                if (stripos($html, $indicator) !== false) {
                    return true;
                }
            }
            
            // Check wp-json endpoint
            $jsonUrl = $url . '/wp-json/wp/v2/';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $jsonUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'WP-Rank-Bot/1.0',
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200 && $response;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Save site analysis data
     */
    private function saveSiteData(string $domain, array $analysis): void {
        // Check if site already exists
        $stmt = $this->db->getConnection()->prepare("SELECT id FROM sites WHERE domain = ?");
        $stmt->execute([$domain]);
        $existingSite = $stmt->fetch();
        
        if ($existingSite) {
            // Update existing site
            $sql = "
                UPDATE sites SET 
                    mobile_score = ?, 
                    desktop_score = ?, 
                    plugin_count = ?, 
                    last_crawled = NOW(),
                    status = 'active',
                    is_wordpress = 1
                WHERE domain = ?
            ";
            
            $this->db->execute($sql, [
                $analysis['mobile_score'],
                $analysis['desktop_score'],
                $analysis['plugin_count'],
                $domain
            ]);
            
        } else {
            // Insert new site with UUID
            $siteId = \WPRank\Utils\Uuid::v4();
            $sql = "
                INSERT INTO sites (id, domain, mobile_score, desktop_score, plugin_count, last_crawled, status, is_wordpress, first_seen_at, created_at)
                VALUES (?, ?, ?, ?, ?, NOW(), 'active', 1, NOW(), NOW())
            ";
            
            $this->db->execute($sql, [
                $siteId,
                $domain,
                $analysis['mobile_score'],
                $analysis['desktop_score'],
                $analysis['plugin_count']
            ]);
        }
    }
    
    /**
     * Update queue item status
     */
    private function updateQueueItemStatus(int $id, string $status): void {
        $sql = "UPDATE crawl_queue SET status = ?, updated_at = NOW() WHERE id = ?";
        Database::execute($sql, [$status, $id]);
    }
    
    /**
     * Mark queue item as completed
     */
    private function markItemCompleted(int $id, string $result): void {
        $sql = "
            UPDATE crawl_queue SET 
                status = 'completed',
                completed_at = NOW(),
                result = ?,
                updated_at = NOW()
            WHERE id = ?
        ";
        Database::execute($sql, [$result, $id]);
    }
    
    /**
     * Mark queue item for retry
     */
    private function markItemRetry(int $id, string $error): void {
        $sql = "
            UPDATE crawl_queue SET 
                status = 'pending',
                attempt_count = attempt_count + 1,
                last_error = ?,
                next_attempt_at = DATE_ADD(NOW(), INTERVAL POW(2, attempt_count) MINUTE),
                updated_at = NOW()
            WHERE id = ?
        ";
        $this->db->execute($sql, [$error, $id]);
    }
    
    /**
     * Mark queue item as failed
     */
    private function markItemFailed(int $id, string $error): void {
        $sql = "
            UPDATE crawl_queue SET 
                status = 'failed',
                last_error = ?,
                updated_at = NOW()
            WHERE id = ?
        ";
        $this->db->execute($sql, [$error, $id]);
    }
    
    /**
     * Get queue statistics
     */
    public function getQueueStats(): array {
        $sql = "
            SELECT 
                status,
                COUNT(*) as count
            FROM crawl_queue 
            GROUP BY status
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];
        
        foreach ($results as $row) {
            $stats[$row['status']] = (int)$row['count'];
        }
        
        $stats['total'] = array_sum($stats);
        
        return $stats;
    }
    
    /**
     * Clean up old queue items
     */
    public function cleanupQueue(int $daysOld = 7): int {
        $sql = "
            DELETE FROM crawl_queue 
            WHERE status IN ('completed', 'failed') 
            AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$daysOld]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Simple logging function
     */
    private function log(string $message, bool $show = false): void {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}";
        
        $this->logger[] = $logMessage;
        
        if ($show) {
            echo $logMessage . "\n";
        }
        
        // Write to log file
        $logFile = dirname(__DIR__, 2) . '/logs/queue.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents($logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get recent log entries
     */
    public function getRecentLogs(int $lines = 100): array {
        return array_slice($this->logger, -$lines);
    }
}