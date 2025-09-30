<?php

namespace WPRank\Services;

use WPRank\Database;
use WPRank\Config;
use Exception;
use PDO;

class MonitoringService {
    
    private Database $db;
    private Config $config;
    
    public function __construct() {
        $this->db = new Database();
        $this->config = new Config();
    }
    
    /**
     * Get comprehensive system status
     */
    public function getSystemStatus(): array {
        return [
            'timestamp' => date('c'),
            'uptime' => $this->getSystemUptime(),
            'queue' => $this->getQueueStatus(),
            'discovery' => $this->getDiscoveryStatus(),
            'ranking' => $this->getRankingStatus(),
            'performance' => $this->getPerformanceMetrics(),
            'errors' => $this->getRecentErrors(),
            'statistics' => $this->getSystemStatistics()
        ];
    }
    
    /**
     * Get queue processing status
     */
    public function getQueueStatus(): array {
        $sql = "
            SELECT 
                status,
                COUNT(*) as count,
                MIN(created_at) as oldest,
                MAX(updated_at) as latest_update
            FROM crawl_queue 
            GROUP BY status
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $status = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'total' => 0,
            'oldest_pending' => null,
            'latest_update' => null
        ];
        
        foreach ($results as $row) {
            $status[$row['status']] = (int)$row['count'];
            $status['total'] += (int)$row['count'];
            
            if ($row['status'] === 'pending' && $row['oldest']) {
                $status['oldest_pending'] = $row['oldest'];
            }
            
            if ($row['latest_update'] && (!$status['latest_update'] || $row['latest_update'] > $status['latest_update'])) {
                $status['latest_update'] = $row['latest_update'];
            }
        }
        
        // Get processing rate (items per hour)
        $sql = "
            SELECT COUNT(*) as completed_last_hour
            FROM crawl_queue 
            WHERE status = 'completed' 
            AND completed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $status['processing_rate'] = $stmt->fetchColumn();
        
        return $status;
    }
    
    /**
     * Get discovery status
     */
    public function getDiscoveryStatus(): array {
        // Recent discovery statistics
        $sql = "
            SELECT 
                DATE(discovered_at) as date,
                source,
                COUNT(*) as count
            FROM discovery_log 
            WHERE discovered_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(discovered_at), source
            ORDER BY date DESC, source
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $recentDiscovery = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Today's discovery
        $sql = "
            SELECT COUNT(*) as count
            FROM discovery_log 
            WHERE DATE(discovered_at) = CURDATE()
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $todayCount = $stmt->fetchColumn();
        
        // Last discovery time
        $sql = "
            SELECT MAX(discovered_at) as last_discovery
            FROM discovery_log
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $lastDiscovery = $stmt->fetchColumn();
        
        return [
            'last_discovery' => $lastDiscovery,
            'discovered_today' => (int)$todayCount,
            'recent_activity' => $recentDiscovery
        ];
    }
    
    /**
     * Get ranking computation status
     */
    public function getRankingStatus(): array {
        // Latest ranking computation
        $sql = "
            SELECT 
                COUNT(*) as total_ranked,
                MAX(computed_at) as last_computation,
                COUNT(CASE WHEN computed_at > DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 END) as ranked_today,
                AVG(efficiency_score) as avg_efficiency,
                MAX(efficiency_score) as max_efficiency,
                MIN(efficiency_score) as min_efficiency
            FROM ranks
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $rankingStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Sites needing ranking update
        $sql = "
            SELECT COUNT(*) as sites_needing_update
            FROM sites s
            LEFT JOIN ranks r ON s.domain = r.domain
            WHERE s.status = 'active' 
            AND s.mobile_score IS NOT NULL 
            AND s.desktop_score IS NOT NULL
            AND (r.computed_at IS NULL OR r.computed_at < s.last_crawled)
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $rankingStats['sites_needing_update'] = $stmt->fetchColumn();
        
        return $rankingStats;
    }
    
    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(): array {
        // Average processing time (estimated from queue)
        $sql = "
            SELECT 
                AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_processing_time,
                COUNT(*) as completed_count
            FROM crawl_queue 
            WHERE status = 'completed' 
            AND completed_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            AND completed_at IS NOT NULL
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $processingStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // API response times (if logged)
        $apiStats = [
            'avg_response_time' => null,
            'api_calls_today' => null
        ];
        
        // Database performance
        $sql = "SELECT COUNT(*) as active_connections FROM INFORMATION_SCHEMA.PROCESSLIST WHERE DB = DATABASE()";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $dbConnections = $stmt->fetchColumn();
        } catch (Exception $e) {
            $dbConnections = null;
        }
        
        return [
            'avg_processing_time' => $processingStats['avg_processing_time'] ? round($processingStats['avg_processing_time'], 2) : null,
            'completed_today' => (int)$processingStats['completed_count'],
            'db_connections' => $dbConnections,
            'api_performance' => $apiStats
        ];
    }
    
    /**
     * Get recent error information
     */
    public function getRecentErrors(): array {
        // Queue errors
        $sql = "
            SELECT 
                domain,
                last_error,
                attempt_count,
                updated_at
            FROM crawl_queue 
            WHERE status = 'failed' 
            AND last_error IS NOT NULL
            AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY updated_at DESC
            LIMIT 10
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $queueErrors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Error patterns
        $sql = "
            SELECT 
                SUBSTRING(last_error, 1, 50) as error_pattern,
                COUNT(*) as count
            FROM crawl_queue 
            WHERE status = 'failed' 
            AND last_error IS NOT NULL
            AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY SUBSTRING(last_error, 1, 50)
            ORDER BY count DESC
            LIMIT 5
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $errorPatterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'recent_errors' => $queueErrors,
            'error_patterns' => $errorPatterns
        ];
    }
    
    /**
     * Get system statistics
     */
    public function getSystemStatistics(): array {
        $stats = [];
        
        // Total sites
        $sql = "SELECT COUNT(*) FROM sites WHERE status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['total_active_sites'] = $stmt->fetchColumn();
        
        // Sites crawled today
        $sql = "SELECT COUNT(*) FROM sites WHERE DATE(last_crawled) = CURDATE()";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['crawled_today'] = $stmt->fetchColumn();
        
        // Average scores
        $sql = "
            SELECT 
                AVG(mobile_score) as avg_mobile,
                AVG(desktop_score) as avg_desktop,
                AVG(plugin_count) as avg_plugins
            FROM sites 
            WHERE status = 'active' 
            AND mobile_score IS NOT NULL 
            AND desktop_score IS NOT NULL
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $avgStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stats['avg_mobile_score'] = $avgStats['avg_mobile'] ? round($avgStats['avg_mobile'], 1) : null;
        $stats['avg_desktop_score'] = $avgStats['avg_desktop'] ? round($avgStats['avg_desktop'], 1) : null;
        $stats['avg_plugin_count'] = $avgStats['avg_plugins'] ? round($avgStats['avg_plugins'], 1) : null;
        
        // Top performing sites
        $sql = "
            SELECT domain, efficiency_score, rank_position
            FROM ranks 
            ORDER BY efficiency_score DESC 
            LIMIT 5
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['top_sites'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
    }
    
    /**
     * Get system uptime (estimated from oldest completed queue item)
     */
    private function getSystemUptime(): ?string {
        $sql = "
            SELECT MIN(completed_at) as first_completion
            FROM crawl_queue 
            WHERE status = 'completed' 
            AND completed_at IS NOT NULL
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $firstCompletion = $stmt->fetchColumn();
        
        if ($firstCompletion) {
            $uptime = time() - strtotime($firstCompletion);
            return $this->formatUptime($uptime);
        }
        
        return null;
    }
    
    /**
     * Format uptime in human readable format
     */
    private function formatUptime(int $seconds): string {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";
        
        return implode(' ', $parts) ?: '0m';
    }
    
    /**
     * Get alerts based on system status
     */
    public function getAlerts(): array {
        $alerts = [];
        
        $queueStatus = $this->getQueueStatus();
        $discoveryStatus = $this->getDiscoveryStatus();
        $rankingStatus = $this->getRankingStatus();
        
        // Queue alerts
        if ($queueStatus['pending'] > 1000) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'queue',
                'message' => "Large queue backlog: {$queueStatus['pending']} pending items"
            ];
        }
        
        if ($queueStatus['oldest_pending'] && strtotime($queueStatus['oldest_pending']) < strtotime('-4 hours')) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'queue',
                'message' => 'Some queue items are very old and may be stuck'
            ];
        }
        
        if ($queueStatus['processing_rate'] === 0 && $queueStatus['pending'] > 0) {
            $alerts[] = [
                'type' => 'error',
                'category' => 'queue',
                'message' => 'Queue processing appears to be stopped'
            ];
        }
        
        // Discovery alerts
        if ($discoveryStatus['discovered_today'] === 0 && date('H') > 6) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'discovery',
                'message' => 'No new sites discovered today'
            ];
        }
        
        // Ranking alerts
        if ($rankingStatus['last_computation'] && strtotime($rankingStatus['last_computation']) < strtotime('-2 days')) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'ranking',
                'message' => 'Rankings may be outdated'
            ];
        }
        
        if ($rankingStatus['sites_needing_update'] > 100) {
            $alerts[] = [
                'type' => 'info',
                'category' => 'ranking',
                'message' => "Many sites need ranking updates: {$rankingStatus['sites_needing_update']}"
            ];
        }
        
        return $alerts;
    }
}