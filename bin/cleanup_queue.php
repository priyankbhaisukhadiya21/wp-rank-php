#!/usr/bin/env php
<?php

/**
 * WP-Rank Queue Cleanup
 * 
 * Cleans up old completed and failed queue items to maintain database performance.
 * 
 * Usage:
 *   php bin/cleanup_queue.php [options]
 * 
 * Options:
 *   --days=N           Remove items older than N days (default: 7)
 *   --dry-run          Show what would be deleted without actually deleting
 *   --verbose          Show detailed output
 *   --help             Show this help message
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';

use WPRank\Database;

// Parse command line options
$options = getopt('', [
    'days::',
    'dry-run',
    'verbose',
    'help'
]);

if (isset($options['help'])) {
    echo "WP-Rank Queue Cleanup\n";
    echo "Usage: php bin/cleanup_queue.php [options]\n\n";
    echo "Options:\n";
    echo "  --days=N           Remove items older than N days (default: 7)\n";
    echo "  --dry-run          Show what would be deleted without actually deleting\n";
    echo "  --verbose          Show detailed output\n";
    echo "  --help             Show this help message\n";
    exit(0);
}

// Configuration
$config = [
    'days' => (int)($options['days'] ?? 7),
    'dry_run' => isset($options['dry-run']),
    'verbose' => isset($options['verbose'])
];

// Validate configuration
if ($config['days'] < 1 || $config['days'] > 365) {
    echo "Error: days must be between 1 and 365\n";
    exit(1);
}

try {
    $db = new Database();
    
    if ($config['verbose']) {
        echo "WP-Rank Queue Cleanup Starting\n";
        echo "Configuration:\n";
        echo "  Days: {$config['days']}\n";
        echo "  Dry Run: " . ($config['dry_run'] ? 'Yes' : 'No') . "\n";
        echo "  Verbose: " . ($config['verbose'] ? 'Yes' : 'No') . "\n";
        echo "\n";
    }
    
    // Get cleanup statistics first
    $sql = "
        SELECT 
            status,
            COUNT(*) as count,
            MIN(updated_at) as oldest,
            MAX(updated_at) as newest
        FROM crawl_queue 
        WHERE status IN ('completed', 'failed') 
        AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY status
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$config['days']]);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($stats)) {
        echo "No queue items to clean up.\n";
        exit(0);
    }
    
    $totalItems = 0;
    foreach ($stats as $stat) {
        $totalItems += $stat['count'];
        if ($config['verbose']) {
            echo "Found {$stat['count']} {$stat['status']} items (oldest: {$stat['oldest']}, newest: {$stat['newest']})\n";
        }
    }
    
    echo "Total items to clean up: {$totalItems}\n";
    
    if ($config['dry_run']) {
        echo "DRY RUN: No items were actually deleted.\n";
        exit(0);
    }
    
    // Perform cleanup
    $sql = "
        DELETE FROM crawl_queue 
        WHERE status IN ('completed', 'failed') 
        AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$config['days']]);
    $deletedItems = $stmt->rowCount();
    
    echo "Successfully deleted {$deletedItems} queue items.\n";
    
    // Also cleanup discovery log
    $sql = "DELETE FROM discovery_log WHERE discovered_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
    $stmt = $db->prepare($sql);
    $stmt->execute([$config['days'] * 4]); // Keep discovery log longer
    $deletedDiscovery = $stmt->rowCount();
    
    if ($config['verbose'] && $deletedDiscovery > 0) {
        echo "Also deleted {$deletedDiscovery} old discovery log entries.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Queue cleanup finished.\n";