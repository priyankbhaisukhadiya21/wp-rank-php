#!/usr/bin/env php
<?php
/**
 * WP-Rank Crawl Worker
 * 
 * Processes sites from the crawl queue, analyzing them for performance
 * and plugin detection. Designed to run continuously or be called
 * periodically by cron.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use WPRank\Config;
use WPRank\Database;
use WPRank\Services\Crawler;

// Enable error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set memory limit for processing
ini_set('memory_limit', '512M');

// Set reasonable execution time limit
set_time_limit(300); // 5 minutes per site

class CrawlWorker
{
    private Database $db;
    private Crawler $crawler;
    private bool $verbose;
    private int $batchSize;
    private int $maxRetries;
    
    public function __construct(bool $verbose = false, int $batchSize = 10, int $maxRetries = 3)
    {
        $this->db = Database::getInstance();
        $this->crawler = new Crawler();
        $this->verbose = $verbose;
        $this->batchSize = $batchSize;
        $this->maxRetries = $maxRetries;
    }
    
    /**
     * Main worker loop
     */
    public function run(): void
    {
        $this->log("Starting crawl worker...");
        
        while (true) {
            try {
                $processed = $this->processBatch();
                
                if ($processed === 0) {
                    $this->log("No sites to process, sleeping for 30 seconds...");
                    sleep(30);
                    continue;
                }
                
                $this->log("Processed {$processed} sites in this batch");
                
                // Brief pause between batches
                sleep(5);
                
            } catch (Exception $e) {
                $this->log("Error in worker loop: " . $e->getMessage(), 'ERROR');
                sleep(60); // Wait longer on error
            }
        }
    }
    
    /**
     * Process a batch of sites from the queue
     */
    private function processBatch(): int
    {
        $sites = $this->getQueuedSites();
        
        if (empty($sites)) {
            return 0;
        }
        
        $processed = 0;
        
        foreach ($sites as $site) {
            try {
                $this->log("Processing: {$site['domain']}");
                
                // Mark as in progress
                $this->updateQueueStatus($site['id'], 'processing');
                
                // Perform the crawl
                $success = $this->crawler->crawlSite($site['domain']);
                
                if ($success) {
                    $this->log("✓ Successfully crawled: {$site['domain']}");
                    $this->completeQueueItem($site['id']);
                } else {
                    $this->log("✗ Failed to crawl: {$site['domain']}", 'WARNING');
                    $this->handleFailure($site);
                }
                
                $processed++;
                
                // Rate limiting - don't overwhelm external services
                sleep(2);
                
            } catch (Exception $e) {
                $this->log("Error processing {$site['domain']}: " . $e->getMessage(), 'ERROR');
                $this->handleFailure($site);
            }
        }
        
        return $processed;
    }
    
    /**
     * Get sites from the queue
     */
    private function getQueuedSites(): array
    {
        $sql = "
            SELECT id, domain, retry_count, last_error
            FROM crawl_queue 
            WHERE status = 'pending' 
            AND retry_count < :max_retries
            AND (next_attempt IS NULL OR next_attempt <= NOW())
            ORDER BY priority DESC, created_at ASC 
            LIMIT :batch_size
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':max_retries', $this->maxRetries, PDO::PARAM_INT);
        $stmt->bindValue(':batch_size', $this->batchSize, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update queue item status
     */
    private function updateQueueStatus(int $queueId, string $status): void
    {
        $sql = "UPDATE crawl_queue SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':id', $queueId, PDO::PARAM_INT);
        $stmt->execute();
    }
    
    /**
     * Mark queue item as complete
     */
    private function completeQueueItem(int $queueId): void
    {
        $sql = "
            UPDATE crawl_queue 
            SET status = 'completed', 
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':id', $queueId, PDO::PARAM_INT);
        $stmt->execute();
    }
    
    /**
     * Handle crawl failure
     */
    private function handleFailure(array $site): void
    {
        $retryCount = $site['retry_count'] + 1;
        
        if ($retryCount >= $this->maxRetries) {
            // Max retries reached, mark as failed
            $sql = "
                UPDATE crawl_queue 
                SET status = 'failed',
                    retry_count = :retry_count,
                    updated_at = NOW()
                WHERE id = :id
            ";
        } else {
            // Schedule retry with exponential backoff
            $delayMinutes = pow(2, $retryCount) * 10; // 20, 40, 80 minutes
            $sql = "
                UPDATE crawl_queue 
                SET status = 'pending',
                    retry_count = :retry_count,
                    next_attempt = DATE_ADD(NOW(), INTERVAL :delay MINUTE),
                    updated_at = NOW()
                WHERE id = :id
            ";
        }
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':retry_count', $retryCount, PDO::PARAM_INT);
        $stmt->bindValue(':id', $site['id'], PDO::PARAM_INT);
        
        if ($retryCount < $this->maxRetries) {
            $stmt->bindValue(':delay', $delayMinutes, PDO::PARAM_INT);
        }
        
        $stmt->execute();
    }
    
    /**
     * Log message with timestamp
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if (!$this->verbose && $level === 'INFO') {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    }
}

// CLI Interface
function showUsage(): void
{
    echo "Usage: php crawl_worker.php [options]\n";
    echo "\nOptions:\n";
    echo "  -v, --verbose     Enable verbose output\n";
    echo "  -b, --batch-size  Number of sites to process per batch (default: 10)\n";
    echo "  -r, --max-retries Maximum retry attempts per site (default: 3)\n";
    echo "  -h, --help        Show this help message\n";
    echo "\nExample:\n";
    echo "  php crawl_worker.php --verbose --batch-size=5\n";
}

// Parse command line arguments
$options = getopt('vb:r:h', ['verbose', 'batch-size:', 'max-retries:', 'help']);

if (isset($options['h']) || isset($options['help'])) {
    showUsage();
    exit(0);
}

$verbose = isset($options['v']) || isset($options['verbose']);
$batchSize = isset($options['b']) ? (int)$options['b'] : (isset($options['batch-size']) ? (int)$options['batch-size'] : 10);
$maxRetries = isset($options['r']) ? (int)$options['r'] : (isset($options['max-retries']) ? (int)$options['max-retries'] : 3);

// Validate parameters
if ($batchSize < 1 || $batchSize > 100) {
    echo "Error: Batch size must be between 1 and 100\n";
    exit(1);
}

if ($maxRetries < 1 || $maxRetries > 10) {
    echo "Error: Max retries must be between 1 and 10\n";
    exit(1);
}

// Handle signals for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() {
        echo "\nReceived SIGTERM, shutting down gracefully...\n";
        exit(0);
    });
    
    pcntl_signal(SIGINT, function() {
        echo "\nReceived SIGINT, shutting down gracefully...\n";
        exit(0);
    });
}

// Run the worker
try {
    $worker = new CrawlWorker($verbose, $batchSize, $maxRetries);
    $worker->run();
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}