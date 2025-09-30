#!/usr/bin/env php
<?php

/**
 * WP-Rank Health Check
 * 
 * Monitors the health of the WP-Rank system and reports issues.
 * 
 * Usage:
 *   php bin/health_check.php [options]
 * 
 * Options:
 *   --quiet            Only output errors and warnings
 *   --json             Output results in JSON format
 *   --help             Show this help message
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';

use WPRank\Database;
use WPRank\Config;

// Parse command line options
$options = getopt('', [
    'quiet',
    'json',
    'help'
]);

if (isset($options['help'])) {
    echo "WP-Rank Health Check\n";
    echo "Usage: php bin/health_check.php [options]\n\n";
    echo "Options:\n";
    echo "  --quiet            Only output errors and warnings\n";
    echo "  --json             Output results in JSON format\n";
    echo "  --help             Show this help message\n";
    exit(0);
}

// Configuration
$config = [
    'quiet' => isset($options['quiet']),
    'json' => isset($options['json'])
];

class HealthChecker {
    
    private Database $db;
    private Config $appConfig;
    private array $checks = [];
    
    public function __construct() {
        $this->db = new Database();
        $this->appConfig = new Config();
    }
    
    /**
     * Run all health checks
     */
    public function runHealthChecks(): array {
        $results = [
            'timestamp' => date('c'),
            'status' => 'healthy',
            'checks' => []
        ];
        
        // Database connectivity
        $results['checks']['database'] = $this->checkDatabase();
        
        // Queue health
        $results['checks']['queue'] = $this->checkQueue();
        
        // Discovery process
        $results['checks']['discovery'] = $this->checkDiscovery();
        
        // Ranking computation
        $results['checks']['ranking'] = $this->checkRanking();
        
        // API configuration
        $results['checks']['api_config'] = $this->checkApiConfig();
        
        // Log files
        $results['checks']['logs'] = $this->checkLogs();
        
        // Storage space
        $results['checks']['storage'] = $this->checkStorage();
        
        // Overall status
        $hasErrors = false;
        $hasWarnings = false;
        
        foreach ($results['checks'] as $check) {
            if ($check['status'] === 'error') {
                $hasErrors = true;
            } elseif ($check['status'] === 'warning') {
                $hasWarnings = true;
            }
        }
        
        if ($hasErrors) {
            $results['status'] = 'error';
        } elseif ($hasWarnings) {
            $results['status'] = 'warning';
        }
        
        return $results;
    }
    
    /**
     * Check database connectivity and basic statistics
     */
    private function checkDatabase(): array {
        try {
            // Test connection
            $stmt = $this->db->prepare("SELECT 1");
            $stmt->execute();
            
            // Get basic stats
            $stats = [];
            
            // Count sites
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM sites WHERE status = 'active'");
            $stmt->execute();
            $stats['active_sites'] = $stmt->fetchColumn();
            
            // Count queue items
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM crawl_queue WHERE status = 'pending'");
            $stmt->execute();
            $stats['pending_queue'] = $stmt->fetchColumn();
            
            return [
                'status' => 'ok',
                'message' => 'Database connection healthy',
                'details' => $stats
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'details' => null
            ];
        }
    }
    
    /**
     * Check queue processing health
     */
    private function checkQueue(): array {
        try {
            $sql = "
                SELECT 
                    status,
                    COUNT(*) as count,
                    MAX(updated_at) as last_update
                FROM crawl_queue 
                GROUP BY status
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $queueStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stats = [];
            $oldestPending = null;
            
            foreach ($queueStats as $stat) {
                $stats[$stat['status']] = $stat['count'];
                if ($stat['status'] === 'pending') {
                    $oldestPending = $stat['last_update'];
                }
            }
            
            // Check if queue is processing (no items stuck for too long)
            $status = 'ok';
            $message = 'Queue processing normally';
            
            if (isset($stats['pending']) && $stats['pending'] > 1000) {
                $status = 'warning';
                $message = 'Large number of pending items in queue';
            }
            
            if ($oldestPending && strtotime($oldestPending) < strtotime('-4 hours')) {
                $status = 'warning';
                $message = 'Some queue items are very old';
            }
            
            return [
                'status' => $status,
                'message' => $message,
                'details' => $stats
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Queue check failed: ' . $e->getMessage(),
                'details' => null
            ];
        }
    }
    
    /**
     * Check discovery process
     */
    private function checkDiscovery(): array {
        try {
            $sql = "
                SELECT 
                    DATE(discovered_at) as date,
                    COUNT(*) as count
                FROM discovery_log 
                WHERE discovered_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(discovered_at)
                ORDER BY date DESC
                LIMIT 7
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $recentDiscovery = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $status = 'ok';
            $message = 'Discovery process running normally';
            
            // Check if discovery ran today
            $today = date('Y-m-d');
            $discoveredToday = false;
            
            foreach ($recentDiscovery as $day) {
                if ($day['date'] === $today) {
                    $discoveredToday = true;
                    break;
                }
            }
            
            if (!$discoveredToday && date('H') > 6) { // After 6 AM
                $status = 'warning';
                $message = 'No sites discovered today';
            }
            
            return [
                'status' => $status,
                'message' => $message,
                'details' => $recentDiscovery
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Discovery check failed: ' . $e->getMessage(),
                'details' => null
            ];
        }
    }
    
    /**
     * Check ranking computation
     */
    private function checkRanking(): array {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_ranked,
                    COUNT(CASE WHEN computed_at > DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 END) as recent_ranked,
                    MAX(computed_at) as last_computation
                FROM ranks
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $rankingStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $status = 'ok';
            $message = 'Ranking computation up to date';
            
            if ($rankingStats['last_computation'] && strtotime($rankingStats['last_computation']) < strtotime('-2 days')) {
                $status = 'warning';
                $message = 'Rankings may be outdated';
            }
            
            return [
                'status' => $status,
                'message' => $message,
                'details' => $rankingStats
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Ranking check failed: ' . $e->getMessage(),
                'details' => null
            ];
        }
    }
    
    /**
     * Check API configuration
     */
    private function checkApiConfig(): array {
        $status = 'ok';
        $message = 'API configuration valid';
        $issues = [];
        
        // Check PageSpeed Insights API key
        $psiKey = $this->appConfig->get('PSI_API_KEY');
        if (!$psiKey) {
            $issues[] = 'PSI_API_KEY not configured';
            $status = 'error';
        }
        
        // Check database configuration
        $dbHost = $this->appConfig->get('DB_HOST');
        if (!$dbHost) {
            $issues[] = 'DB_HOST not configured';
            $status = 'error';
        }
        
        if (!empty($issues)) {
            $message = 'Configuration issues: ' . implode(', ', $issues);
        }
        
        return [
            'status' => $status,
            'message' => $message,
            'details' => ['issues' => $issues]
        ];
    }
    
    /**
     * Check log files
     */
    private function checkLogs(): array {
        $logDir = dirname(__DIR__) . '/logs';
        $status = 'ok';
        $message = 'Log files healthy';
        $details = [];
        
        if (!is_dir($logDir)) {
            return [
                'status' => 'warning',
                'message' => 'Logs directory does not exist',
                'details' => null
            ];
        }
        
        $logFiles = ['queue.log', 'discovery.log', 'ranking.log', 'api.log'];
        
        foreach ($logFiles as $logFile) {
            $filePath = $logDir . '/' . $logFile;
            if (file_exists($filePath)) {
                $size = filesize($filePath);
                $details[$logFile] = [
                    'size' => $size,
                    'size_mb' => round($size / 1024 / 1024, 2),
                    'modified' => date('c', filemtime($filePath))
                ];
                
                // Warn if log files are very large
                if ($size > 100 * 1024 * 1024) { // 100MB
                    $status = 'warning';
                    $message = 'Large log files detected';
                }
            }
        }
        
        return [
            'status' => $status,
            'message' => $message,
            'details' => $details
        ];
    }
    
    /**
     * Check storage space
     */
    private function checkStorage(): array {
        $projectDir = dirname(__DIR__);
        
        try {
            $totalSpace = disk_total_space($projectDir);
            $freeSpace = disk_free_space($projectDir);
            $usedSpace = $totalSpace - $freeSpace;
            $freePercent = ($freeSpace / $totalSpace) * 100;
            
            $status = 'ok';
            $message = 'Sufficient storage space';
            
            if ($freePercent < 10) {
                $status = 'error';
                $message = 'Critical: Low storage space';
            } elseif ($freePercent < 20) {
                $status = 'warning';
                $message = 'Warning: Low storage space';
            }
            
            return [
                'status' => $status,
                'message' => $message,
                'details' => [
                    'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
                    'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
                    'used_gb' => round($usedSpace / 1024 / 1024 / 1024, 2),
                    'free_percent' => round($freePercent, 1)
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Could not check storage space',
                'details' => null
            ];
        }
    }
}

// Run health checks
try {
    $checker = new HealthChecker();
    $results = $checker->runHealthChecks();
    
    if ($config['json']) {
        echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
    } else {
        if (!$config['quiet']) {
            echo "WP-Rank Health Check - " . date('Y-m-d H:i:s') . "\n";
            echo "Overall Status: " . strtoupper($results['status']) . "\n\n";
        }
        
        foreach ($results['checks'] as $checkName => $check) {
            $shouldShow = !$config['quiet'] || in_array($check['status'], ['warning', 'error']);
            
            if ($shouldShow) {
                echo "{$checkName}: " . strtoupper($check['status']) . " - {$check['message']}\n";
                
                if (!$config['quiet'] && $check['details']) {
                    foreach ($check['details'] as $key => $value) {
                        if (is_array($value)) {
                            echo "  {$key}: " . json_encode($value) . "\n";
                        } else {
                            echo "  {$key}: {$value}\n";
                        }
                    }
                }
            }
        }
    }
    
    // Exit with appropriate code
    if ($results['status'] === 'error') {
        exit(2);
    } elseif ($results['status'] === 'warning') {
        exit(1);
    } else {
        exit(0);
    }
    
} catch (Exception $e) {
    echo "Health check failed: " . $e->getMessage() . "\n";
    exit(2);
}