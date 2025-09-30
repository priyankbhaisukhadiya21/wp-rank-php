<?php
/**
 * Database connection and management for WP-Rank
 * 
 * Provides PDO database connection with proper error handling and connection pooling.
 */

namespace WPRank;

use PDO;
use PDOException;

class Database 
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private static array $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct()
    {
        $this->pdo = $this->createConnection();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get PDO connection
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }
    
    /**
     * Create PDO connection to MySQL
     */
    private function createConnection(): PDO 
    {
        $host = Config::get('database.host', 'localhost');
        $port = Config::get('database.port', 3306);
        $database = Config::get('database.database', 'global_wp_rank');
        $username = Config::get('database.username', 'root');
        $password = Config::get('database.password', 'rootpass');
        
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, self::$options);
            
            // Test the connection
            $pdo->query('SELECT 1');
            
            return $pdo;
        } catch (PDOException $e) {
            error_log("MySQL connection failed: " . $e->getMessage());
            throw new \Exception("Database connection failed. Please check your MySQL configuration.");
        }
    }
    
    /**
     * Create demo tables for SQLite
     */
    private function createDemoTables(PDO $pdo): void
    {
        $tables = [
            'sites' => "
                CREATE TABLE IF NOT EXISTS sites (
                    id TEXT PRIMARY KEY,
                    domain TEXT UNIQUE NOT NULL,
                    is_wordpress INTEGER DEFAULT 1,
                    theme_name TEXT,
                    first_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    last_crawl_at TEXT,
                    status TEXT DEFAULT 'active'
                )
            ",
            'site_metrics' => "
                CREATE TABLE IF NOT EXISTS site_metrics (
                    id TEXT PRIMARY KEY,
                    site_id TEXT NOT NULL,
                    crawl_id TEXT NOT NULL,
                    psi_score INTEGER,
                    lcp_ms INTEGER,
                    cls REAL,
                    tbt_ms INTEGER,
                    plugin_est_count INTEGER,
                    theme_name TEXT,
                    evidence TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ",
            'ranks' => "
                CREATE TABLE IF NOT EXISTS ranks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    site_id TEXT NOT NULL,
                    efficiency_score REAL NOT NULL,
                    global_rank INTEGER DEFAULT 0,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ",
            'submissions' => "
                CREATE TABLE IF NOT EXISTS submissions (
                    id TEXT PRIMARY KEY,
                    domain TEXT NOT NULL,
                    ip_hash TEXT,
                    status TEXT DEFAULT 'pending',
                    submitted_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ",
            'crawl_queue' => "
                CREATE TABLE IF NOT EXISTS crawl_queue (
                    id TEXT PRIMARY KEY,
                    domain TEXT NOT NULL,
                    priority INTEGER DEFAULT 5,
                    source TEXT,
                    status TEXT DEFAULT 'pending',
                    retry_count INTEGER DEFAULT 0,
                    next_attempt_at TEXT,
                    last_error TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT
                )
            "
        ];
        
        foreach ($tables as $name => $sql) {
            $pdo->exec($sql);
        }
        
        // Insert demo data
        $this->insertDemoData($pdo);
    }
    
    /**
     * Insert demo data for testing
     */
    private function insertDemoData(PDO $pdo): void
    {
        // Check if data exists
        $count = $pdo->query("SELECT COUNT(*) FROM sites")->fetchColumn();
        if ($count > 0) return;
        
        $demoSites = [
            ['id' => '1', 'domain' => 'wordpress.org', 'is_wordpress' => 1, 'theme_name' => 'WordPressDotOrg'],
            ['id' => '2', 'domain' => 'automattic.com', 'is_wordpress' => 1, 'theme_name' => 'Automattic'],
            ['id' => '3', 'domain' => 'wpbeginner.com', 'is_wordpress' => 1, 'theme_name' => 'WPBeginner'],
            ['id' => '4', 'domain' => 'woocommerce.com', 'is_wordpress' => 1, 'theme_name' => 'Storefront'],
            ['id' => '5', 'domain' => 'jetpack.com', 'is_wordpress' => 1, 'theme_name' => 'Jetpack'],
        ];
        
        $demoMetrics = [
            ['id' => '1', 'site_id' => '1', 'crawl_id' => '1', 'psi_score' => 92, 'plugin_est_count' => 8, 'lcp_ms' => 1200, 'cls' => 0.02, 'tbt_ms' => 50],
            ['id' => '2', 'site_id' => '2', 'crawl_id' => '2', 'psi_score' => 88, 'plugin_est_count' => 12, 'lcp_ms' => 1400, 'cls' => 0.05, 'tbt_ms' => 80],
            ['id' => '3', 'site_id' => '3', 'crawl_id' => '3', 'psi_score' => 85, 'plugin_est_count' => 15, 'lcp_ms' => 1600, 'cls' => 0.08, 'tbt_ms' => 120],
            ['id' => '4', 'site_id' => '4', 'crawl_id' => '4', 'psi_score' => 90, 'plugin_est_count' => 10, 'lcp_ms' => 1300, 'cls' => 0.03, 'tbt_ms' => 60],
            ['id' => '5', 'site_id' => '5', 'crawl_id' => '5', 'psi_score' => 87, 'plugin_est_count' => 14, 'lcp_ms' => 1500, 'cls' => 0.06, 'tbt_ms' => 100],
        ];
        
        $demoRanks = [
            ['site_id' => '1', 'efficiency_score' => 51.11, 'global_rank' => 1],
            ['site_id' => '4', 'efficiency_score' => 45.00, 'global_rank' => 2],
            ['site_id' => '2', 'efficiency_score' => 40.00, 'global_rank' => 3],
            ['site_id' => '5', 'efficiency_score' => 35.41, 'global_rank' => 4],
            ['site_id' => '3', 'efficiency_score' => 34.00, 'global_rank' => 5],
        ];
        
        // Insert sites
        $stmt = $pdo->prepare("INSERT INTO sites (id, domain, is_wordpress, theme_name) VALUES (?, ?, ?, ?)");
        foreach ($demoSites as $site) {
            $stmt->execute([$site['id'], $site['domain'], $site['is_wordpress'], $site['theme_name']]);
        }
        
        // Insert metrics
        $stmt = $pdo->prepare("INSERT INTO site_metrics (id, site_id, crawl_id, psi_score, plugin_est_count, lcp_ms, cls, tbt_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($demoMetrics as $metric) {
            $stmt->execute([$metric['id'], $metric['site_id'], $metric['crawl_id'], $metric['psi_score'], $metric['plugin_est_count'], $metric['lcp_ms'], $metric['cls'], $metric['tbt_ms']]);
        }
        
        // Insert ranks
        $stmt = $pdo->prepare("INSERT INTO ranks (site_id, efficiency_score, global_rank) VALUES (?, ?, ?)");
        foreach ($demoRanks as $rank) {
            $stmt->execute([$rank['site_id'], $rank['efficiency_score'], $rank['global_rank']]);
        }
    }
    
    /**
     * Test database connectivity
     * 
     * @return bool True if connection is successful
     */
    public static function testConnection(): bool 
    {
        try {
            $pdo = self::getInstance()->getConnection();
            $pdo->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            error_log("Database health check failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Execute a prepared statement with error handling
     * 
     * @param string $query SQL query with placeholders
     * @param array $params Parameters for the query
     * @return \PDOStatement
     * @throws PDOException
     */
    public static function execute(string $query, array $params = []): \PDOStatement 
    {
        try {
            $pdo = self::getInstance()->getConnection();
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query failed: " . $e->getMessage() . " | Query: " . $query);
            throw $e;
        }
    }
    
    /**
     * Get the last inserted ID
     * 
     * @return string
     */
    public static function lastInsertId(): string 
    {
        return self::getInstance()->getConnection()->lastInsertId();
    }
    
    /**
     * Begin database transaction
     * 
     * @return bool
     */
    public static function beginTransaction(): bool 
    {
        return self::getInstance()->getConnection()->beginTransaction();
    }
    
    /**
     * Commit database transaction
     * 
     * @return bool
     */
    public static function commit(): bool 
    {
        return self::getInstance()->getConnection()->commit();
    }
    
    /**
     * Rollback database transaction
     * 
     * @return bool
     */
    public static function rollback(): bool 
    {
        return self::getInstance()->getConnection()->rollBack();
    }
    
    /**
     * Get MySQL date subtraction SQL
     */
    public static function getDateSubQuery(string $interval): string
    {
        // Using MySQL syntax only
        return "DATE_SUB(NOW(), INTERVAL {$interval})";
    }
    
    /**
     * Get MySQL current timestamp
     */
    public static function getCurrentTimestamp(): string
    {
        // Using MySQL syntax only
        return "NOW()";
    }

    /**
     * Close database connection
     */
    public static function disconnect(): void 
    {
        self::$instance = null;
    }
}