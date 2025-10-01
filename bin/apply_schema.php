#!/usr/bin/env php
<?php

/**
 * Database Schema Applier
 * 
 * Safely applies the complete WP-Rank database schema.
 * This script will backup existing data and apply the new schema.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Config.php';

use WPRank\Config;

// Configuration
$config = [
    'backup' => true,
    'force' => false,
    'verbose' => true
];

// Parse command line options
$options = getopt('', ['no-backup', 'force', 'quiet', 'help']);

if (isset($options['help'])) {
    echo "WP-Rank Schema Applier\n";
    echo "Usage: php bin/apply_schema.php [options]\n\n";
    echo "Options:\n";
    echo "  --no-backup        Skip database backup\n";
    echo "  --force            Force apply even if tables exist\n";
    echo "  --quiet            Minimal output\n";
    echo "  --help             Show this help message\n";
    exit(0);
}

$config['backup'] = !isset($options['no-backup']);
$config['force'] = isset($options['force']);
$config['verbose'] = !isset($options['quiet']);

class SchemaApplier {
    
    private PDO $pdo;
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
        $this->connectDatabase();
    }
    
    private function connectDatabase(): void {
        $host = Config::get('database.host', 'localhost');
        $port = Config::get('database.port', 3306);
        $dbname = Config::get('database.name', 'global_wp_rank');
        $username = Config::get('database.username', 'root');
        $password = Config::get('database.password', '');
        
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        
        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);
            
            $this->log("Connected to MySQL server");
            
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage() . "\n");
        }
    }
    
    public function applySchema(): void {
        $this->log("Starting schema application...");
        
        // Check if database exists
        $this->createDatabase();
        
        // Backup existing data if requested
        if ($this->config['backup']) {
            $this->backupData();
        }
        
        // Apply the schema
        $this->executeSchemaFile();
        
        // Verify the schema
        $this->verifySchema();
        
        $this->log("Schema application completed successfully!");
    }
    
    private function createDatabase(): void {
        $dbname = Config::get('database.name', 'global_wp_rank');
        
        $sql = "CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $this->pdo->exec($sql);
        $this->pdo->exec("USE `{$dbname}`");
        
        $this->log("Database '{$dbname}' ready");
    }
    
    private function backupData(): void {
        $this->log("Creating backup of existing data...");
        
        $dbname = Config::get('database.name', 'global_wp_rank');
        $backupFile = __DIR__ . "/../backups/backup_" . date('Y-m-d_H-i-s') . ".sql";
        
        // Create backups directory if it doesn't exist
        $backupDir = dirname($backupFile);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Check if tables exist before backup
        $tables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tables)) {
            $this->log("No tables to backup");
            return;
        }
        
        $username = Config::get('database.username');
        $password = Config::get('database.password');
        $host = Config::get('database.host');
        
        $cmd = "mysqldump -h{$host} -u{$username}";
        if ($password) {
            $cmd .= " -p{$password}";
        }
        $cmd .= " {$dbname} > {$backupFile}";
        
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0) {
            $this->log("Backup created: {$backupFile}");
        } else {
            $this->log("Warning: Backup failed, continuing anyway...");
        }
    }
    
    private function executeSchemaFile(): void {
        $schemaFile = __DIR__ . '/../sql/complete_schema.sql';
        
        if (!file_exists($schemaFile)) {
            die("Schema file not found: {$schemaFile}\n");
        }
        
        $this->log("Applying schema from: {$schemaFile}");
        
        $sql = file_get_contents($schemaFile);
        
        // Split into individual statements
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) {
                return !empty($stmt) && !preg_match('/^--/', $stmt);
            }
        );
        
        foreach ($statements as $statement) {
            if (stripos($statement, 'SHOW CREATE TABLE') !== false) {
                continue; // Skip verification statements
            }
            
            try {
                $this->pdo->exec($statement);
                $this->log("✓ " . substr($statement, 0, 50) . "...", false);
            } catch (PDOException $e) {
                // Ignore duplicate table/column errors if not forcing
                if (strpos($e->getMessage(), 'already exists') !== false && !$this->config['force']) {
                    $this->log("- " . substr($statement, 0, 50) . "... (already exists)", false);
                } else {
                    throw $e;
                }
            }
        }
    }
    
    private function verifySchema(): void {
        $this->log("Verifying schema...");
        
        $requiredTables = [
            'sites' => ['id', 'domain', 'mobile_score', 'desktop_score', 'plugin_count', 'last_crawled', 'last_ranked'],
            'crawl_queue' => ['id', 'domain', 'status', 'result', 'completed_at', 'created_at', 'updated_at'],
            'ranks' => ['id', 'site_id', 'domain', 'mobile_score', 'desktop_score', 'plugin_count', 'rank_position'],
            'discovery_log' => ['id', 'domain', 'source', 'discovered_at']
        ];
        
        foreach ($requiredTables as $table => $columns) {
            // Check if table exists
            $stmt = $this->pdo->query("SHOW TABLES LIKE '{$table}'");
            $tableExists = $stmt->fetch();
            $stmt->closeCursor();
            
            if (!$tableExists) {
                throw new Exception("Required table '{$table}' not found");
            }
            
            // Check required columns
            $stmt = $this->pdo->query("SHOW COLUMNS FROM {$table}");
            $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt->closeCursor();
            
            foreach ($columns as $column) {
                if (!in_array($column, $existingColumns)) {
                    throw new Exception("Required column '{$column}' not found in table '{$table}'");
                }
            }
            
            $this->log("✓ Table '{$table}' verified");
        }
        
        // Test basic operations
        $stmt = $this->pdo->query("SELECT 1 FROM sites LIMIT 1");
        $stmt->closeCursor();
        
        $stmt = $this->pdo->query("SELECT 1 FROM crawl_queue LIMIT 1");
        $stmt->closeCursor();
        
        $stmt = $this->pdo->query("SELECT 1 FROM ranks LIMIT 1");
        $stmt->closeCursor();
        
        $this->log("✓ All tables accessible");
    }
    
    private function log(string $message, bool $verbose = true): void {
        if ($this->config['verbose'] || !$verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
        }
    }
}

// Run the schema applier
try {
    $applier = new SchemaApplier($config);
    $applier->applySchema();
    
    echo "\n";
    echo "✅ Database schema applied successfully!\n";
    echo "\n";
    echo "Next steps:\n";
    echo "1. Test the automation: php test_automation.php\n";
    echo "2. Start queue processing: php bin/crawl_worker.php --verbose\n";
    echo "3. Run daily discovery: php bin/discover_daily.php --verbose --max-sites=10\n";
    
} catch (Exception $e) {
    echo "\n";
    echo "❌ Schema application failed: " . $e->getMessage() . "\n";
    echo "\n";
    echo "Check your database configuration in .env file.\n";
    exit(1);
}