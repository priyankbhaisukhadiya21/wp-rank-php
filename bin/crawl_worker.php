#!/usr/bin/env php
<?php
/**
 * WP-Rank Crawl Worker
 * 
 * Continuously processes the crawl queue to analyze WordPress sites.
 * This script should be run as a background service or daemon.
 * 
 * Usage:
 *   php bin/crawl_worker.php [options]
 * 
 * Options:
 *   --batch-size=N     Process N sites per batch (default: 10)
 *   --max-retries=N    Maximum retry attempts (default: 3)
 *   --verbose          Show detailed output
 *   --continuous       Run continuously (default: true)
 *   --help             Show this help message
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Services/QueueProcessor.php';
require_once __DIR__ . '/../src/Services/AnalysisService.php';
require_once __DIR__ . '/../src/Services/RankingService.php';

use WPRank\Services\QueueProcessor;

// Parse command line options
$options = getopt('', [
    'batch-size::',
    'max-retries::',
    'verbose',
    'continuous',
    'help'
]);

if (isset($options['help'])) {
    echo "WP-Rank Crawl Worker\n";
    echo "Usage: php bin/crawl_worker.php [options]\n\n";
    echo "Options:\n";
    echo "  --batch-size=N     Process N sites per batch (default: 10)\n";
    echo "  --max-retries=N    Maximum retry attempts (default: 3)\n";
    echo "  --verbose          Show detailed output\n";
    echo "  --continuous       Run continuously (default: true)\n";
    echo "  --help             Show this help message\n";
    exit(0);
}

// Configuration
$config = [
    'batch_size' => (int)($options['batch-size'] ?? 10),
    'max_retries' => (int)($options['max-retries'] ?? 3),
    'verbose' => isset($options['verbose']),
    'continuous' => !isset($options['continuous']) || $options['continuous'] !== false
];

// Validate configuration
if ($config['batch_size'] < 1 || $config['batch_size'] > 100) {
    echo "Error: batch-size must be between 1 and 100\n";
    exit(1);
}

if ($config['max_retries'] < 1 || $config['max_retries'] > 10) {
    echo "Error: max-retries must be between 1 and 10\n";
    exit(1);
}

// Signal handling for graceful shutdown
$running = true;

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use (&$running) {
        global $running;
        $running = false;
        echo "\nReceived SIGTERM, shutting down gracefully...\n";
    });

    pcntl_signal(SIGINT, function() use (&$running) {
        global $running;
        $running = false;
        echo "\nReceived SIGINT, shutting down gracefully...\n";
    });
}

// Initialize processor
try {
    $processor = new QueueProcessor();
    
    if ($config['verbose']) {
        echo "WP-Rank Crawl Worker Starting\n";
        echo "Configuration:\n";
        echo "  Batch Size: {$config['batch_size']}\n";
        echo "  Max Retries: {$config['max_retries']}\n";
        echo "  Continuous: " . ($config['continuous'] ? 'Yes' : 'No') . "\n";
        echo "  Verbose: " . ($config['verbose'] ? 'Yes' : 'No') . "\n";
        echo "\n";
    }
    
    // Show initial queue stats
    if ($config['verbose']) {
        $stats = $processor->getQueueStats();
        echo "Initial Queue Statistics:\n";
        echo "  Pending: {$stats['pending']}\n";
        echo "  Processing: {$stats['processing']}\n";
        echo "  Completed: {$stats['completed']}\n";
        echo "  Failed: {$stats['failed']}\n";
        echo "  Total: {$stats['total']}\n";
        echo "\n";
    }
    
    // Process queue
    $processor->processQueue($config);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "Crawl worker finished.\n";

// Run the worker
try {
    $worker = new CrawlWorker($verbose, $batchSize, $maxRetries);
    $worker->run();
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}