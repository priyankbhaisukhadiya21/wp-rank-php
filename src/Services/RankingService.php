<?php
/**
 * Ranking service for WP-Rank
 * 
 * Calculates efficiency scores and manages global rankings for WordPress sites
 * based on PageSpeed Insights scores and estimated plugin counts.
 */

namespace WPRank\Services;

use WPRank\Database;
use WPRank\Utils\Uuid;
use WPRank\Config;

class RankingService 
{
    // Efficiency score weights
    private float $psiWeight = 0.70;      // 70% weight for PSI score
    private float $pluginWeight = 0.30;   // 30% weight for plugin efficiency
    
    public function __construct() 
    {
        // Allow configuration override of weights
        $this->psiWeight = Config::get('ranking.psi_weight', 0.70);
        $this->pluginWeight = Config::get('ranking.plugin_weight', 0.30);
    }
    
    /**
     * Calculate efficiency score for given metrics
     * 
     * @param int|null $psiScore PSI score (0-100)
     * @param int|null $pluginCount Estimated plugin count
     * @return float Efficiency score (0.0-1.0)
     */
    public function calculateEfficiencyScore(?int $psiScore, ?int $pluginCount): float 
    {
        // Normalize PSI score (0-100 to 0.0-1.0)
        $psiNormalized = max(0.0, min(1.0, ($psiScore ?? 0) / 100.0));
        
        // Normalize plugin count (lower is better, with winsorization at 50)
        $pluginCountCapped = min($pluginCount ?? 0, 50);
        $pluginNormalized = 1.0 / (1 + $pluginCountCapped);
        
        // Calculate weighted efficiency score
        $efficiencyScore = ($this->psiWeight * $psiNormalized) + ($this->pluginWeight * $pluginNormalized);
        
        return round($efficiencyScore, 4);
    }
    
    /**
     * Recompute all site rankings
     * 
     * @return array Summary of ranking computation
     */
    public function recomputeRankings(): array 
    {
        $startTime = microtime(true);
        
        // Get latest metrics for all WordPress sites
        $stmt = Database::execute("
            SELECT 
                s.id as site_id,
                s.domain,
                m.psi_score,
                m.plugin_est_count
            FROM sites s
            INNER JOIN (
                SELECT 
                    site_id,
                    MAX(created_at) as latest_crawl
                FROM site_metrics
                GROUP BY site_id
            ) latest ON latest.site_id = s.id
            INNER JOIN site_metrics m ON m.site_id = s.id AND m.created_at = latest.latest_crawl
            WHERE s.is_wordpress = 1 AND s.status = 'active'
        ");
        
        $sites = $stmt->fetchAll();
        
        if (empty($sites)) {
            return [
                'success' => true,
                'sites_processed' => 0,
                'processing_time' => 0,
                'message' => 'No WordPress sites found to rank'
            ];
        }
        
        // Calculate efficiency scores
        $sitesWithScores = [];
        foreach ($sites as $site) {
            $efficiencyScore = $this->calculateEfficiencyScore(
                $site['psi_score'],
                $site['plugin_est_count']
            );
            
            $sitesWithScores[] = [
                'site_id' => $site['site_id'],
                'domain' => $site['domain'],
                'psi_score' => $site['psi_score'],
                'plugin_count' => $site['plugin_est_count'],
                'efficiency_score' => $efficiencyScore
            ];
        }
        
        // Sort by efficiency score (descending), then by PSI score (descending),
        // then by plugin count (ascending), then by domain (ascending)
        usort($sitesWithScores, function($a, $b) {
            // Primary: efficiency score (higher is better)
            $effDiff = $b['efficiency_score'] <=> $a['efficiency_score'];
            if ($effDiff !== 0) return $effDiff;
            
            // Secondary: PSI score (higher is better)
            $psiDiff = ($b['psi_score'] ?? 0) <=> ($a['psi_score'] ?? 0);
            if ($psiDiff !== 0) return $psiDiff;
            
            // Tertiary: plugin count (lower is better)
            $pluginDiff = ($a['plugin_count'] ?? 999) <=> ($b['plugin_count'] ?? 999);
            if ($pluginDiff !== 0) return $pluginDiff;
            
            // Final: domain name (alphabetical)
            return $a['domain'] <=> $b['domain'];
        });
        
        // Update rankings in database
        Database::beginTransaction();
        
        try {
            $rank = 1;
            $updatedCount = 0;
            
            foreach ($sitesWithScores as $site) {
                $this->upsertRanking($site['site_id'], $site['efficiency_score'], $rank);
                $rank++;
                $updatedCount++;
            }
            
            Database::commit();
            
            $processingTime = round(microtime(true) - $startTime, 2);
            
            if (Config::isDebug()) {
                error_log("Rankings recomputed for {$updatedCount} sites in {$processingTime}s");
            }
            
            return [
                'success' => true,
                'sites_processed' => $updatedCount,
                'processing_time' => $processingTime,
                'top_site' => $sitesWithScores[0] ?? null
            ];
            
        } catch (\Exception $e) {
            Database::rollback();
            error_log("Failed to recompute rankings: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'sites_processed' => 0,
                'processing_time' => round(microtime(true) - $startTime, 2)
            ];
        }
    }
    
    /**
     * Insert or update ranking for a site
     * 
     * @param string $siteId Site ID
     * @param float $efficiencyScore Efficiency score
     * @param int $globalRank Global rank position
     */
    private function upsertRanking(string $siteId, float $efficiencyScore, int $globalRank): void 
    {
        // Check if ranking exists
        $stmt = Database::execute(
            "SELECT id FROM ranks WHERE site_id = ?",
            [$siteId]
        );
        
        $existingRank = $stmt->fetch();
        
        if ($existingRank) {
            // Update existing ranking
            Database::execute(
                "UPDATE ranks SET 
                 efficiency_score = ?, 
                 global_rank = ?, 
                 computed_at = " . Database::getCurrentTimestamp() . " 
                 WHERE id = ?",
                [$efficiencyScore, $globalRank, $existingRank['id']]
            );
        } else {
            // Insert new ranking
            $rankId = Uuid::v4();
            Database::execute(
                "INSERT INTO ranks (id, site_id, efficiency_score, global_rank) 
                 VALUES (?, ?, ?, ?)",
                [$rankId, $siteId, $efficiencyScore, $globalRank]
            );
        }
    }
    
    /**
     * Get leaderboard with pagination and filtering
     * 
     * @param array $params Query parameters
     * @return array Leaderboard data
     */
    public function getLeaderboard(array $params = []): array 
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(1, (int)($params['per_page'] ?? 50)));
        $sort = $params['sort'] ?? 'efficiency';
        $order = strtolower($params['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $offset = ($page - 1) * $perPage;
        
        // Build ORDER BY clause
        $orderByMap = [
            'efficiency' => 'r.efficiency_score',
            'psi' => 'm.psi_score',
            'plugins' => 'm.plugin_est_count',
            'rank' => 'r.global_rank'
        ];
        
        $orderBy = $orderByMap[$sort] ?? 'r.efficiency_score';
        
        // Build WHERE conditions
        $whereConditions = ["s.is_wordpress = 1", "s.status = 'active'"];
        $params_values = [];
        
        if (isset($params['min_psi']) && is_numeric($params['min_psi'])) {
            $whereConditions[] = "m.psi_score >= ?";
            $params_values[] = (int)$params['min_psi'];
        }
        
        if (isset($params['max_plugins']) && is_numeric($params['max_plugins'])) {
            $whereConditions[] = "IFNULL(m.plugin_est_count, 0) <= ?";
            $params_values[] = (int)$params['max_plugins'];
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Get leaderboard entries
        $query = "
            SELECT 
                r.global_rank as `rank`,
                s.domain,
                s.theme_name,
                m.psi_score,
                m.plugin_est_count,
                m.created_at as last_crawl,
                r.efficiency_score
            FROM ranks r
            INNER JOIN sites s ON s.id = r.site_id
            INNER JOIN (
                SELECT 
                    sm1.*
                FROM site_metrics sm1
                INNER JOIN (
                    SELECT site_id, MAX(created_at) as latest 
                    FROM site_metrics 
                    GROUP BY site_id
                ) latest ON latest.site_id = sm1.site_id AND latest.latest = sm1.created_at
            ) m ON m.site_id = s.id
            WHERE {$whereClause}
            ORDER BY {$orderBy} {$order}
            LIMIT {$perPage} OFFSET {$offset}
        ";
        
        $stmt = Database::execute($query, $params_values);
        $items = $stmt->fetchAll();
        
        // Get total count for pagination
        $countQuery = "
            SELECT COUNT(*) as total
            FROM ranks r
            INNER JOIN sites s ON s.id = r.site_id
            INNER JOIN (
                SELECT 
                    sm1.*
                FROM site_metrics sm1
                INNER JOIN (
                    SELECT site_id, MAX(created_at) as latest 
                    FROM site_metrics 
                    GROUP BY site_id
                ) latest ON latest.site_id = sm1.site_id AND latest.latest = sm1.created_at
            ) m ON m.site_id = s.id
            WHERE {$whereClause}
        ";
        
        $countStmt = Database::execute($countQuery, $params_values);
        $totalCount = (int)$countStmt->fetchColumn();
        
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $perPage)
            ],
            'filters' => [
                'sort' => $sort,
                'order' => $order,
                'min_psi' => $params['min_psi'] ?? null,
                'max_plugins' => $params['max_plugins'] ?? null
            ]
        ];
    }
    
    /**
     * Get ranking statistics
     * 
     * @return array Statistics about current rankings
     */
    public function getRankingStats(): array 
    {
        $stmt = Database::execute("
            SELECT 
                COUNT(*) as total_ranked_sites,
                AVG(r.efficiency_score) as avg_efficiency,
                MIN(r.efficiency_score) as min_efficiency,
                MAX(r.efficiency_score) as max_efficiency,
                AVG(m.psi_score) as avg_psi,
                AVG(m.plugin_est_count) as avg_plugins,
                MAX(r.computed_at) as last_computation
            FROM ranks r
            INNER JOIN sites s ON s.id = r.site_id
            INNER JOIN (
                SELECT 
                    sm1.*
                FROM site_metrics sm1
                INNER JOIN (
                    SELECT site_id, MAX(created_at) as latest 
                    FROM site_metrics 
                    GROUP BY site_id
                ) latest ON latest.site_id = sm1.site_id AND latest.latest = sm1.created_at
            ) m ON m.site_id = s.id
            WHERE s.is_wordpress = 1 AND s.status = 'active'
        ");
        
        $stats = $stmt->fetch();
        
        // Format the results
        return [
            'total_ranked_sites' => (int)($stats['total_ranked_sites'] ?? 0),
            'avg_efficiency_score' => round((float)($stats['avg_efficiency'] ?? 0), 4),
            'min_efficiency_score' => round((float)($stats['min_efficiency'] ?? 0), 4),
            'max_efficiency_score' => round((float)($stats['max_efficiency'] ?? 0), 4),
            'avg_psi_score' => round((float)($stats['avg_psi'] ?? 0), 1),
            'avg_plugin_count' => round((float)($stats['avg_plugins'] ?? 0), 1),
            'last_computation' => $stats['last_computation'],
            'weights' => [
                'psi_weight' => $this->psiWeight,
                'plugin_weight' => $this->pluginWeight
            ]
        ];
    }
    
    /**
     * Update site ranking after analysis
     * 
     * @param string $domain Domain to update ranking for
     */
    public function updateSiteRanking(string $domain): void {
        // Get site data
        $stmt = Database::execute("SELECT id, mobile_score, desktop_score, plugin_count FROM sites WHERE domain = ?", [$domain]);
        $site = $stmt->fetch();
        
        if (!$site) {
            return;
        }
        
        // Calculate average PSI score
        $avgPsiScore = null;
        if ($site['mobile_score'] !== null && $site['desktop_score'] !== null) {
            $avgPsiScore = ($site['mobile_score'] + $site['desktop_score']) / 2;
        }
        
        // Calculate efficiency score
        $efficiencyScore = $this->calculateEfficiencyScore($avgPsiScore, $site['plugin_count']);
        
        // Update or insert ranking record
        $rankStmt = Database::execute("SELECT id FROM ranks WHERE site_id = ?", [$site['id']]);
        $existingRank = $rankStmt->fetch();
        
        if ($existingRank) {
            // Update existing ranking
            Database::execute(
                "UPDATE ranks SET efficiency_score = ?, domain = ?, mobile_score = ?, desktop_score = ?, plugin_count = ?, computed_at = NOW() WHERE site_id = ?",
                [$efficiencyScore, $domain, $site['mobile_score'], $site['desktop_score'], $site['plugin_count'], $site['id']]
            );
        } else {
            // Insert new ranking
            $rankId = Uuid::v4();
            Database::execute(
                "INSERT INTO ranks (id, site_id, efficiency_score, domain, mobile_score, desktop_score, plugin_count, global_rank, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())",
                [$rankId, $site['id'], $efficiencyScore, $domain, $site['mobile_score'], $site['desktop_score'], $site['plugin_count']]
            );
        }
        
        // Update last_ranked timestamp in sites table
        Database::execute("UPDATE sites SET last_ranked = NOW() WHERE id = ?", [$site['id']]);
        
        // Recompute global rankings
        $this->recomputeGlobalRankings();
    }
    
    /**
     * Recompute global rankings for all sites
     */
    public function recomputeGlobalRankings(): void {
        // First, create a temporary view/table with rankings
        $db = Database::getInstance();
        
        // Use a multi-step approach to avoid MySQL's limitation
        // Step 1: Create a temporary table with calculated ranks
        $db->getConnection()->exec("DROP TEMPORARY TABLE IF EXISTS temp_ranks");
        
        $db->getConnection()->exec("
            CREATE TEMPORARY TABLE temp_ranks AS
            SELECT 
                site_id,
                ROW_NUMBER() OVER (ORDER BY efficiency_score DESC) as new_rank
            FROM ranks 
            WHERE efficiency_score IS NOT NULL
            ORDER BY efficiency_score DESC
        ");
        
        // Step 2: Update the original table using the temporary table
        $db->getConnection()->exec("
            UPDATE ranks r
            INNER JOIN temp_ranks t ON r.site_id = t.site_id
            SET r.global_rank = t.new_rank,
                r.rank_position = t.new_rank
        ");
        
        // Step 3: Clean up temporary table
        $db->getConnection()->exec("DROP TEMPORARY TABLE temp_ranks");
    }
}