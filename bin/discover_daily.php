#!/usr/bin/env php
<?php

/**
 * WP-Rank Daily Discovery
 * 
 * Discovers new WordPress sites from online sources and adds them to the crawl queue.
 * This script should be run daily via cron.
 * 
 * Usage:
 *   php bin/discover_daily.php [options]
 * 
 * Options:
 *   --max-sites=N      Maximum number of sites to discover (default: 100)
 *   --verbose          Show detailed output
 *   --cleanup          Clean up old discovery records
 *   --help             Show this help message
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Services/DiscoveryService.php';
require_once __DIR__ . '/../src/Services/SubmissionService.php';

use WPRank\Services\DiscoveryService;

// Parse command line options
$options = getopt('', [
    'max-sites::',
    'verbose',
    'cleanup',
    'help'
]);

if (isset($options['help'])) {
    echo "WP-Rank Daily Discovery\n";
    echo "Usage: php bin/discover_daily.php [options]\n\n";
    echo "Options:\n";
    echo "  --max-sites=N      Maximum number of sites to discover (default: 100)\n";
    echo "  --verbose          Show detailed output\n";
    echo "  --cleanup          Clean up old discovery records\n";
    echo "  --help             Show this help message\n";
    exit(0);
}

// Configuration
$config = [
    'max_sites' => (int)($options['max-sites'] ?? 100),
    'verbose' => isset($options['verbose']),
    'cleanup' => isset($options['cleanup'])
];

// Validate configuration
if ($config['max_sites'] < 1 || $config['max_sites'] > 1000) {
    echo "Error: max-sites must be between 1 and 1000\n";
    exit(1);
}

class DailyDiscovery
{
    private Database $db;
    private DomainDiscoverer $discoverer;
    private bool $verbose;
    private int $maxNewSites;
    
    public function __construct(bool $verbose = false, int $maxNewSites = 100)
    {
        $this->db = Database::getInstance();
        $this->discoverer = new DomainDiscoverer();
        $this->verbose = $verbose;
        $this->maxNewSites = $maxNewSites;
    }
    
    /**
     * Run daily discovery process
     */
    public function run(): void
    {
        $this->log("Starting daily WordPress site discovery...");
        
        $stats = [
            'discovered' => 0,
            'queued' => 0,
            'skipped' => 0,
            'errors' => 0
        ];
        
        try {
            // Discover from multiple sources
            $this->log("Discovering from WordPress.com...");
            $wpcomSites = $this->discoverer->discoverFromWordPressCom(50);
            $stats = $this->processBatch($wpcomSites, $stats, 'WordPress.com');
            
            $this->log("Discovering from WP.org showcase...");
            $wporgSites = $this->discoverer->discoverFromWpOrgShowcase(30);
            $stats = $this->processBatch($wporgSites, $stats, 'WP.org');
            
            $this->log("Discovering from existing site links...");
            $linkedSites = $this->discoverer->discoverFromExistingSites(20);
            $stats = $this->processBatch($linkedSites, $stats, 'Site Links');
            
            // Check if we need more sites
            if ($stats['queued'] < $this->maxNewSites) {
                $remaining = $this->maxNewSites - $stats['queued'];
                $this->log("Need {$remaining} more sites, searching technical blogs...");
                $blogSites = $this->discoverer->discoverFromTechBlogs($remaining);
                $stats = $this->processBatch($blogSites, $stats, 'Tech Blogs');
            }
            
            // Log final statistics
            $this->log("Discovery completed!");
            $this->log("Summary:");
            $this->log("  Discovered: {$stats['discovered']} sites");
            $this->log("  Queued for crawling: {$stats['queued']} sites");
            $this->log("  Skipped (duplicates): {$stats['skipped']} sites");
            $this->log("  Errors: {$stats['errors']} sites");
            
            // Update discovery statistics
            $this->updateDiscoveryStats($stats);
            
        } catch (Exception $e) {
            $this->log("Fatal error during discovery: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Process a batch of discovered domains
     */
    private function processBatch(array $domains, array $stats, string $source): array
    {
        foreach ($domains as $domain) {
            try {
                $stats['discovered']++;
                
                // Check if we already have this domain
                if ($this->domainExists($domain)) {
                    $stats['skipped']++;
                    $this->log("Skipping {$domain} (already exists)", 'DEBUG');
                    continue;
                }
                
                // Add to crawl queue
                if ($this->addToCrawlQueue($domain, $source)) {
                    $stats['queued']++;
                    $this->log("✓ Queued {$domain} from {$source}");
                } else {
                    $stats['errors']++;
                    $this->log("✗ Failed to queue {$domain}", 'WARNING');
                }
                
                // Stop if we've reached the limit
                if ($stats['queued'] >= $this->maxNewSites) {
                    $this->log("Reached maximum new sites limit ({$this->maxNewSites})");
                    break;
                }
                
            } catch (Exception $e) {
                $stats['errors']++;
                $this->log("Error processing {$domain}: " . $e->getMessage(), 'ERROR');
            }
        }
        
        return $stats;
    }
    
    /**
     * Check if domain already exists in our database
     */
    private function domainExists(string $domain): bool
    {
        $sql = "
            SELECT COUNT(*) 
            FROM sites 
            WHERE domain = :domain
            UNION ALL
            SELECT COUNT(*)
            FROM crawl_queue
            WHERE domain = :domain AND status != 'failed'
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_sum($results) > 0;
    }
    
    /**
     * Add domain to crawl queue
     */
    private function addToCrawlQueue(string $domain, string $source): bool
    {
        $sql = "
            INSERT INTO crawl_queue (domain, priority, source, status, created_at, updated_at)
            VALUES (:domain, :priority, :source, 'pending', NOW(), NOW())
        ";
        
        // Set priority based on source
        $priority = match($source) {
            'WordPress.com' => 8,
            'WP.org' => 9,
            'Site Links' => 7,
            'Tech Blogs' => 6,
            default => 5
        };
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
        $stmt->bindValue(':priority', $priority, PDO::PARAM_INT);
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        
        return $stmt->execute();
    }
    
    /**
     * Update discovery statistics
     */
    private function updateDiscoveryStats(array $stats): void
    {
        $sql = "
            INSERT INTO discovery_stats (date, discovered_count, queued_count, skipped_count, error_count)
            VALUES (CURDATE(), :discovered, :queued, :skipped, :errors)
            ON DUPLICATE KEY UPDATE
                discovered_count = :discovered,
                queued_count = :queued,
                skipped_count = :skipped,
                error_count = :errors,
                updated_at = NOW()
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':discovered', $stats['discovered'], PDO::PARAM_INT);
        $stmt->bindValue(':queued', $stats['queued'], PDO::PARAM_INT);
        $stmt->bindValue(':skipped', $stats['skipped'], PDO::PARAM_INT);
        $stmt->bindValue(':errors', $stats['errors'], PDO::PARAM_INT);
        $stmt->execute();
    }
    
    /**
     * Clean up old discovery stats and failed queue items
     */
    public function cleanup(): void
    {
        $this->log("Performing cleanup...");
        
        // Remove discovery stats older than 90 days
        $sql = "DELETE FROM discovery_stats WHERE date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
        $this->db->getConnection()->exec($sql);
        
        // Remove failed crawl queue items older than 7 days
        $sql = "
            DELETE FROM crawl_queue 
            WHERE status = 'failed' 
            AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ";
        $this->db->getConnection()->exec($sql);
        
        // Remove completed crawl queue items older than 1 day
        $sql = "
            DELETE FROM crawl_queue 
            WHERE status = 'completed' 
            AND completed_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
        ";
        $this->db->getConnection()->exec($sql);
        
        $this->log("Cleanup completed");
    }
    
    /**
     * Log message with timestamp
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if (!$this->verbose && $level === 'DEBUG') {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    }
}

// CLI Interface
function showUsage(): void
{
    echo "Usage: php discover_daily.php [options]\n";
    echo "\nOptions:\n";
    echo "  -v, --verbose     Enable verbose output\n";
    echo "  -m, --max-sites   Maximum new sites to discover (default: 100)\n";
    echo "  -c, --cleanup     Perform cleanup of old data\n";
    echo "  -h, --help        Show this help message\n";
    echo "\nExample:\n";
    echo "  php discover_daily.php --verbose --max-sites=50 --cleanup\n";
}

// Parse command line arguments
$options = getopt('vm:ch', ['verbose', 'max-sites:', 'cleanup', 'help']);

if (isset($options['h']) || isset($options['help'])) {
    showUsage();
    exit(0);
}

$verbose = isset($options['v']) || isset($options['verbose']);
$maxSites = isset($options['m']) ? (int)$options['m'] : (isset($options['max-sites']) ? (int)$options['max-sites'] : 100);
$cleanup = isset($options['c']) || isset($options['cleanup']);

// Validate parameters
if ($maxSites < 1 || $maxSites > 1000) {
    echo "Error: Max sites must be between 1 and 1000\n";
    exit(1);
}

// Run the discovery
try {
    $discovery = new DailyDiscovery($verbose, $maxSites);
    
    if ($cleanup) {
        $discovery->cleanup();
    }
    
    $discovery->run();
    
    echo "Daily discovery completed successfully.\n";
    exit(0);
    
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}