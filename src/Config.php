<?php
/**
 * Configuration management for WP-Rank
 * 
 * Handles loading environment variables and providing access to configuration values
 * throughout the application.
 */

namespace WPRank;

use Dotenv\Dotenv;

class Config 
{
    private static ?array $config = null;
    
    /**
     * Load configuration from environment variables
     * 
     * @return array Configuration array
     */
    public static function load(): array 
    {
        if (self::$config !== null) {
            return self::$config;
        }
        
        // Load .env file if it exists
        $rootPath = dirname(__DIR__);
        if (file_exists($rootPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($rootPath);
            $dotenv->load();
        }
        
        self::$config = [
            'app' => [
                'env' => $_ENV['APP_ENV'] ?? 'local',
                'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
            'database' => [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => (int)($_ENV['DB_PORT'] ?? 3306),
                'database' => $_ENV['DB_DATABASE'] ?? 'global_wp_rank',
                'username' => $_ENV['DB_USERNAME'] ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? 'rootpass',
            ],
            'api' => [
                'admin_token' => $_ENV['ADMIN_TOKEN'] ?? '',
                'psi_api_key' => $_ENV['PSI_API_KEY'] ?? '',
                'cors_origins' => explode(',', $_ENV['CORS_ORIGINS'] ?? 'http://localhost:8000'),
            ],
            'crawler' => [
                'concurrency' => (int)($_ENV['CRAWL_CONCURRENCY'] ?? 1),
                'timeout' => (int)($_ENV['REQUEST_TIMEOUT_SEC'] ?? 8),
                'per_domain_rps' => (int)($_ENV['PER_DOMAIN_RPS'] ?? 1),
                'max_domains_per_run' => (int)($_ENV['MAX_DOMAINS_PER_RUN'] ?? 50),
                'discovery_delay' => (int)($_ENV['DISCOVERY_DELAY_SECONDS'] ?? 2),
                'max_plugin_evidence' => (int)($_ENV['MAX_PLUGIN_EVIDENCE'] ?? 20),
            ],
            'external_apis' => [
                'google_search_key' => $_ENV['GOOGLE_SEARCH_API_KEY'] ?? '',
                'google_search_engine_id' => $_ENV['GOOGLE_SEARCH_ENGINE_ID'] ?? '',
                'bing_api_key' => $_ENV['BING_API_KEY'] ?? '',
            ]
        ];
        
        return self::$config;
    }
    
    /**
     * Get configuration value by dot notation path
     * 
     * @param string $path Configuration path (e.g., 'db.host')
     * @param mixed $default Default value if not found
     * @return mixed Configuration value
     */
    public static function get(string $path, $default = null) 
    {
        $config = self::load();
        $keys = explode('.', $path);
        $current = $config;
        
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }
        
        return $current;
    }
    
    /**
     * Check if we're in production environment
     * 
     * @return bool
     */
    public static function isProduction(): bool 
    {
        return self::get('app.env') === 'production';
    }
    
    /**
     * Check if debug mode is enabled
     * 
     * @return bool
     */
    public static function isDebug(): bool 
    {
        return self::get('app.debug', false);
    }
}