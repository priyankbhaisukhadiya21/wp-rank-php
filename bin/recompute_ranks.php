#!/usr/bin/env php
<?php
/**
 * WP-Rank Recompute Rankings Script
 * 
 * Recalculates efficiency scores and global rankings for all sites
 * based on their latest performance metrics. Designed to run daily
 * after crawling is complete.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use WPRank\Config;
use WPRank\Database;
use WPRank\Services\RankingService;

// Enable error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set memory limit for processing
ini_set('memory_limit', '512M');

// Set execution time limit
set_time_limit(3600); // 1 hour

class RecomputeRanks
{
    private Database $db;
    private RankingService $rankingService;
    private bool $verbose;
    private int $batchSize;
    
    public function __construct(bool $verbose = false, int $batchSize = 1000)
    {
        $this->db = Database::getInstance();
        $this->rankingService = new RankingService();
        $this->verbose = $verbose;
        $this->batchSize = $batchSize;
    }
    
    /**
     * Main recomputation process
     */
    public function run(): void
    {
        $this->log("Starting rankings recomputation...");
        
        $startTime = microtime(true);
        $stats = [
            'sites_processed' => 0,
            'rankings_updated' => 0,
            'new_rankings' => 0,
            'removed_rankings' => 0,
            'errors' => 0
        ];
        
        try {
            // Step 1: Clean up old rankings for sites that no longer exist
            $stats['removed_rankings'] = $this->cleanupOldRankings();
            
            // Step 2: Process all sites with recent metrics
            $totalSites = $this->getTotalSitesCount();
            $this->log("Processing {$totalSites} sites in batches of {$this->batchSize}...");
            
            $offset = 0;
            while ($offset < $totalSites) {
                $batchStats = $this->processBatch($offset);
                
                $stats['sites_processed'] += $batchStats['processed'];
                $stats['rankings_updated'] += $batchStats['updated'];
                $stats['new_rankings'] += $batchStats['new'];
                $stats['errors'] += $batchStats['errors'];
                
                $this->log("Processed batch: {$batchStats['processed']} sites, offset {$offset}");
                
                $offset += $this->batchSize;
                
                // Brief pause to avoid overwhelming the database
                usleep(100000); // 0.1 seconds
            }
            
            // Step 3: Update global rankings
            $this->log("Updating global rankings...");
            $this->updateGlobalRankings();
            
            // Step 4: Update ranking statistics
            $this->updateRankingStats($stats);
            
            $duration = round(microtime(true) - $startTime, 2);
            
            $this->log("Rankings recomputation completed in {$duration} seconds!");
            $this->log("Summary:");
            $this->log("  Sites processed: {$stats['sites_processed']}");
            $this->log("  Rankings updated: {$stats['rankings_updated']}");
            $this->log("  New rankings: {$stats['new_rankings']}");
            $this->log("  Old rankings removed: {$stats['removed_rankings']}");
            $this->log("  Errors: {$stats['errors']}");
            
        } catch (Exception $e) {
            $this->log("Fatal error during recomputation: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Get total count of sites to process
     */
    private function getTotalSitesCount(): int
    {
        $sql = "
            SELECT COUNT(DISTINCT s.id) 
            FROM sites s
            INNER JOIN site_metrics sm ON s.id = sm.site_id
            WHERE s.is_wordpress = 1
            AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Process a batch of sites
     */
    private function processBatch(int $offset): array
    {
        $stats = [
            'processed' => 0,
            'updated' => 0,
            'new' => 0,
            'errors' => 0
        ];
        
        // Get sites with their latest metrics
        $sites = $this->getSitesWithMetrics($offset);
        
        foreach ($sites as $site) {
            try {
                $stats['processed']++;
                
                // Calculate efficiency score
                $efficiency = $this->calculateEfficiencyScore(
                    $site['psi_score'],
                    $site['plugin_est_count']
                );
                
                // Update or insert ranking
                $isNew = $this->updateSiteRanking($site['id'], $efficiency);
                
                if ($isNew) {
                    $stats['new']++;
                } else {
                    $stats['updated']++;
                }
                
            } catch (Exception $e) {
                $stats['errors']++;
                $this->log("Error processing site {$site['id']}: " . $e->getMessage(), 'ERROR');
            }
        }
        
        return $stats;
    }
    
    /**
     * Get sites with their latest metrics
     */
    private function getSitesWithMetrics(int $offset): array
    {
        $sql = "
            SELECT 
                s.id,
                s.domain,
                sm.psi_score,
                sm.plugin_est_count,
                sm.created_at as metrics_date
            FROM sites s
            INNER JOIN (
                SELECT 
                    site_id,
                    MAX(created_at) as latest_date
                FROM site_metrics 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY site_id
            ) latest ON s.id = latest.site_id
            INNER JOIN site_metrics sm ON s.id = sm.site_id AND sm.created_at = latest.latest_date
            WHERE s.is_wordpress = 1
            ORDER BY s.id ASC
            LIMIT :batch_size OFFSET :offset
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':batch_size', $this->batchSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calculate efficiency score using the same formula as RankingService
     */
    private function calculateEfficiencyScore(?int $psiScore, ?int $pluginCount): float
    {
        if ($psiScore === null || $psiScore <= 0) {
            return 0.0;
        }
        
        $pluginCount = max(0, $pluginCount ?? 0);
        return $psiScore / (1 + $pluginCount * 0.1);
    }
    
    /**
     * Update or insert site ranking
     */
    private function updateSiteRanking(int $siteId, float $efficiency): bool
    {
        // Check if ranking exists
        $sql = "SELECT id FROM ranks WHERE site_id = :site_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->execute();
        $existingId = $stmt->fetchColumn();
        
        if ($existingId) {
            // Update existing ranking
            $sql = "
                UPDATE ranks 
                SET efficiency_score = :efficiency,
                    updated_at = NOW()
                WHERE id = :id
            ";
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->bindValue(':efficiency', $efficiency, PDO::PARAM_STR);
            $stmt->bindValue(':id', $existingId, PDO::PARAM_INT);
            $stmt->execute();
            
            return false; // Not new
        } else {
            // Insert new ranking
            $sql = "
                INSERT INTO ranks (site_id, efficiency_score, global_rank, created_at, updated_at)
                VALUES (:site_id, :efficiency, 0, NOW(), NOW())
            ";
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
            $stmt->bindValue(':efficiency', $efficiency, PDO::PARAM_STR);
            $stmt->execute();
            
            return true; // New ranking
        }
    }
    
    /**
     * Update global rankings based on efficiency scores
     */
    private function updateGlobalRankings(): void
    {
        // Use a single query with window function to assign ranks
        $sql = "
            UPDATE ranks r
            INNER JOIN (
                SELECT 
                    id,
                    ROW_NUMBER() OVER (ORDER BY efficiency_score DESC, updated_at ASC) as new_rank
                FROM ranks
                WHERE efficiency_score > 0
            ) ranked ON r.id = ranked.id
            SET r.global_rank = ranked.new_rank,
                r.updated_at = NOW()
        ";
        
        $this->db->getConnection()->exec($sql);
        
        // Set rank to 0 for sites with no efficiency score
        $sql = "
            UPDATE ranks 
            SET global_rank = 0, updated_at = NOW()
            WHERE efficiency_score <= 0
        ";
        
        $this->db->getConnection()->exec($sql);
    }
    
    /**
     * Clean up rankings for sites that no longer exist or have no recent metrics
     */
    private function cleanupOldRankings(): int
    {
        $sql = "
            DELETE r FROM ranks r
            LEFT JOIN sites s ON r.site_id = s.id
            LEFT JOIN (
                SELECT DISTINCT site_id 
                FROM site_metrics 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ) recent ON r.site_id = recent.site_id
            WHERE s.id IS NULL 
            OR recent.site_id IS NULL
            OR s.is_wordpress = 0
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Update ranking statistics
     */
    private function updateRankingStats(array $stats): void
    {
        // Get current ranking stats
        $sql = "
            SELECT 
                COUNT(*) as total_ranked,
                AVG(efficiency_score) as avg_efficiency,
                MIN(efficiency_score) as min_efficiency,
                MAX(efficiency_score) as max_efficiency
            FROM ranks 
            WHERE efficiency_score > 0
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        $rankingStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Store stats (you might want to create a ranking_stats table)
        $this->log("Current ranking statistics:");
        $this->log("  Total ranked sites: " . $rankingStats['total_ranked']);
        $this->log("  Average efficiency: " . round($rankingStats['avg_efficiency'], 2));
        $this->log("  Min efficiency: " . round($rankingStats['min_efficiency'], 2));
        $this->log("  Max efficiency: " . round($rankingStats['max_efficiency'], 2));
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
    echo "Usage: php recompute_ranks.php [options]\n";
    echo "\nOptions:\n";
    echo "  -v, --verbose      Enable verbose output\n";
    echo "  -b, --batch-size   Number of sites to process per batch (default: 1000)\n";
    echo "  -h, --help         Show this help message\n";
    echo "\nExample:\n";
    echo "  php recompute_ranks.php --verbose --batch-size=500\n";
}

// Parse command line arguments
$options = getopt('vb:h', ['verbose', 'batch-size:', 'help']);

if (isset($options['h']) || isset($options['help'])) {
    showUsage();
    exit(0);
}

$verbose = isset($options['v']) || isset($options['verbose']);
$batchSize = isset($options['b']) ? (int)$options['b'] : (isset($options['batch-size']) ? (int)$options['batch-size'] : 1000);

// Validate parameters
if ($batchSize < 100 || $batchSize > 10000) {
    echo "Error: Batch size must be between 100 and 10000\n";
    exit(1);
}

// Run the recomputation
try {
    $recompute = new RecomputeRanks($verbose, $batchSize);
    $recompute->run();
    
    echo "Rankings recomputation completed successfully.\n";
    exit(0);
    
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}