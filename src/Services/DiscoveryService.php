<?php

namespace WPRank\Services;

use WPRank\Database;
use WPRank\Config;
use Exception;
use PDO;

class DiscoveryService {
    
    private Database $db;
    private Config $config;
    private SubmissionService $submissionService;
    private array $logger;
    
    // WordPress directory and listing sources
    private array $sources = [
        'wordpress_showcase' => 'https://wordpress.org/showcase/',
        'wpengine_sites' => 'https://wpengine.com/customers/',
        'elementor_showcase' => 'https://elementor.com/library/',
        'yoast_customers' => 'https://yoast.com/customers/',
    ];
    
    // RSS feeds that might contain WordPress sites
    private array $feeds = [
        'wp_tavern' => 'https://wptavern.com/feed',
        'wp_beginner' => 'https://www.wpbeginner.com/feed/',
        'wp_engine_blog' => 'https://wpengine.com/blog/feed/',
    ];
    
    public function __construct() {
        $this->db = new Database();
        $this->config = new Config();
        $this->submissionService = new SubmissionService();
        $this->logger = [];
    }
    
    /**
     * Run daily discovery process
     */
    public function runDailyDiscovery(array $options = []): array {
        $maxSites = $options['max_sites'] ?? 100;
        $verbose = $options['verbose'] ?? false;
        $cleanup = $options['cleanup'] ?? false;
        
        $this->log("Starting daily discovery process", $verbose);
        
        $stats = [
            'discovered' => 0,
            'submitted' => 0,
            'duplicates' => 0,
            'errors' => 0
        ];
        
        try {
            // Discover from various sources
            $discoveredSites = [];
            
            // Method 1: Scrape WordPress showcase and directories
            $showcaseSites = $this->discoverFromShowcases($maxSites / 4, $verbose);
            $discoveredSites = array_merge($discoveredSites, $showcaseSites);
            
            // Method 2: Analyze RSS feeds for mentioned sites
            $feedSites = $this->discoverFromFeeds($maxSites / 4, $verbose);
            $discoveredSites = array_merge($discoveredSites, $feedSites);
            
            // Method 3: Use web APIs and services
            $apiSites = $this->discoverFromAPIs($maxSites / 4, $verbose);
            $discoveredSites = array_merge($discoveredSites, $apiSites);
            
            // Method 4: Discover from existing site references
            $referenceSites = $this->discoverFromReferences($maxSites / 4, $verbose);
            $discoveredSites = array_merge($discoveredSites, $referenceSites);
            
            // Remove duplicates and validate
            $discoveredSites = array_unique($discoveredSites);
            $stats['discovered'] = count($discoveredSites);
            
            $this->log("Discovered " . count($discoveredSites) . " potential sites", $verbose);
            
            // Process and submit sites
            foreach ($discoveredSites as $domain) {
                try {
                    if ($this->isAlreadyKnown($domain)) {
                        $stats['duplicates']++;
                        continue;
                    }
                    
                    if ($this->validateAndSubmitSite($domain, $verbose)) {
                        $stats['submitted']++;
                    }
                    
                    // Respect rate limits
                    usleep(500000); // 0.5 second delay
                    
                } catch (Exception $e) {
                    $stats['errors']++;
                    $this->log("Error processing {$domain}: " . $e->getMessage(), $verbose);
                }
            }
            
            // Cleanup old discovery records if requested
            if ($cleanup) {
                $this->cleanupOldRecords();
            }
            
            $this->log("Discovery completed: " . json_encode($stats), $verbose);
            
        } catch (Exception $e) {
            $this->log("Discovery process failed: " . $e->getMessage(), true);
            $stats['errors']++;
        }
        
        return $stats;
    }
    
    /**
     * Discover sites from WordPress showcases and directories
     */
    private function discoverFromShowcases(int $maxSites, bool $verbose): array {
        $sites = [];
        
        $showcasePages = [
            'https://wordpress.org/showcase/',
            'https://www.elegantthemes.com/blog/divi/wordpress-websites-using-divi',
            'https://elementor.com/library/?type=site'
        ];
        
        foreach ($showcasePages as $url) {
            try {
                $this->log("Scraping showcase: {$url}", $verbose);
                $content = $this->fetchContent($url);
                
                if ($content) {
                    $pageSites = $this->extractDomainsFromContent($content);
                    $sites = array_merge($sites, array_slice($pageSites, 0, $maxSites - count($sites)));
                    
                    if (count($sites) >= $maxSites) {
                        break;
                    }
                }
                
                sleep(2); // Be respectful to servers
                
            } catch (Exception $e) {
                $this->log("Error scraping {$url}: " . $e->getMessage(), $verbose);
            }
        }
        
        return array_slice($sites, 0, $maxSites);
    }
    
    /**
     * Discover sites from RSS feeds and blog posts
     */
    private function discoverFromFeeds(int $maxSites, bool $verbose): array {
        $sites = [];
        
        foreach ($this->feeds as $name => $feedUrl) {
            try {
                $this->log("Processing feed: {$name}", $verbose);
                $content = $this->fetchContent($feedUrl);
                
                if ($content) {
                    $xml = simplexml_load_string($content);
                    if ($xml && isset($xml->channel->item)) {
                        foreach ($xml->channel->item as $item) {
                            $description = (string)$item->description;
                            $link = (string)$item->link;
                            $title = (string)$item->title;
                            
                            // Extract domains from content
                            $content = $description . ' ' . $title . ' ' . $link;
                            $feedSites = $this->extractDomainsFromContent($content);
                            $sites = array_merge($sites, $feedSites);
                            
                            if (count($sites) >= $maxSites) {
                                break 2;
                            }
                        }
                    }
                }
                
                sleep(1);
                
            } catch (Exception $e) {
                $this->log("Error processing feed {$name}: " . $e->getMessage(), $verbose);
            }
        }
        
        return array_slice(array_unique($sites), 0, $maxSites);
    }
    
    /**
     * Discover sites using web APIs and services
     */
    private function discoverFromAPIs(int $maxSites, bool $verbose): array {
        $sites = [];
        
        try {
            // Use BuiltWith API to find WordPress sites (if API key available)
            $sites = array_merge($sites, $this->queryBuiltWithAPI($maxSites / 2, $verbose));
            
            // Use WappalyzerAPI to find WordPress sites (if API key available)
            $sites = array_merge($sites, $this->queryWappalyzerAPI($maxSites / 2, $verbose));
            
            // Query domain listing services
            $sites = array_merge($sites, $this->queryDomainServices($maxSites / 2, $verbose));
            
        } catch (Exception $e) {
            $this->log("Error in API discovery: " . $e->getMessage(), $verbose);
        }
        
        return array_slice(array_unique($sites), 0, $maxSites);
    }
    
    /**
     * Discover sites from references in existing sites
     */
    private function discoverFromReferences(int $maxSites, bool $verbose): array {
        $sites = [];
        
        try {
            // Get random existing sites from our database
            $existingSites = $this->getRandomExistingSites(10);
            
            foreach ($existingSites as $site) {
                $this->log("Checking references from: {$site['domain']}", $verbose);
                
                // Check their footer, about page, etc. for other WordPress sites
                $referencedSites = $this->findReferencedSites($site['domain']);
                $sites = array_merge($sites, $referencedSites);
                
                if (count($sites) >= $maxSites) {
                    break;
                }
                
                sleep(1);
            }
            
        } catch (Exception $e) {
            $this->log("Error in reference discovery: " . $e->getMessage(), $verbose);
        }
        
        return array_slice(array_unique($sites), 0, $maxSites);
    }
    
    /**
     * Extract domain names from HTML/text content
     */
    private function extractDomainsFromContent(string $content): array {
        $domains = [];
        
        // Pattern to match domain names
        $pattern = '/(?:https?:\/\/)?(?:www\.)?([a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9]*\.(?:[a-zA-Z]{2,}))(?:\/[^\s]*)?/';
        
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $domain) {
                $domain = strtolower(trim($domain));
                
                // Filter out common non-site domains
                if (!$this->isValidDomainForDiscovery($domain)) {
                    continue;
                }
                
                $domains[] = $domain;
            }
        }
        
        return array_unique($domains);
    }
    
    /**
     * Validate if domain is worth checking for discovery
     */
    private function isValidDomainForDiscovery(string $domain): bool {
        // Filter out common service domains, social media, etc.
        $blacklist = [
            'google.com', 'facebook.com', 'twitter.com', 'instagram.com',
            'youtube.com', 'linkedin.com', 'github.com', 'stackoverflow.com',
            'wikipedia.org', 'amazon.com', 'apple.com', 'microsoft.com',
            'cloudflare.com', 'wordpress.org', 'gravatar.com', 'wp.com'
        ];
        
        foreach ($blacklist as $blocked) {
            if (strpos($domain, $blocked) !== false) {
                return false;
            }
        }
        
        // Must have valid TLD
        if (!preg_match('/\.[a-z]{2,}$/', $domain)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if domain is already known in our system
     */
    private function isAlreadyKnown(string $domain): bool {
        $stmt = $this->db->prepare("
            SELECT 1 FROM sites WHERE domain = ? 
            UNION 
            SELECT 1 FROM crawl_queue WHERE domain = ?
            LIMIT 1
        ");
        $stmt->execute([$domain, $domain]);
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Validate and submit a site for processing
     */
    private function validateAndSubmitSite(string $domain, bool $verbose): bool {
        try {
            // Basic domain validation
            if (!filter_var($domain, FILTER_VALIDATE_DOMAIN)) {
                return false;
            }
            
            // Check if site is accessible
            if (!$this->isAccessible($domain)) {
                $this->log("Site not accessible: {$domain}", $verbose);
                return false;
            }
            
            // Submit to queue with low priority (discovery)
            $this->submissionService->enqueueCrawl($domain, 1);
            $this->log("Submitted for analysis: {$domain}", $verbose);
            
            // Record discovery
            $this->recordDiscovery($domain, 'daily_discovery');
            
            return true;
            
        } catch (Exception $e) {
            $this->log("Failed to validate {$domain}: " . $e->getMessage(), $verbose);
            return false;
        }
    }
    
    /**
     * Check if site is accessible
     */
    private function isAccessible(string $domain): bool {
        $url = "https://{$domain}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'WP-Rank-Discovery-Bot/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_NOBODY => true, // HEAD request only
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode >= 200 && $httpCode < 400;
    }
    
    /**
     * Record discovery for tracking
     */
    private function recordDiscovery(string $domain, string $source): void {
        $sql = "
            INSERT INTO discovery_log (domain, source, discovered_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE discovered_at = NOW()
        ";
        
        $this->db->execute($sql, [$domain, $source]);
    }
    
    /**
     * Fetch content from URL
     */
    private function fetchContent(string $url): ?string {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'WP-Rank-Discovery-Bot/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5
        ]);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode === 200 && $content) ? $content : null;
    }
    
    /**
     * Get random existing sites for reference checking
     */
    private function getRandomExistingSites(int $limit): array {
        $sql = "
            SELECT domain FROM sites 
            WHERE status = 'active' 
            AND last_crawled > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY RAND() 
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find sites referenced by an existing site
     */
    private function findReferencedSites(string $domain): array {
        $sites = [];
        
        try {
            // Check common pages for references
            $pages = ['', '/about', '/contact', '/partners', '/clients'];
            
            foreach ($pages as $page) {
                $url = "https://{$domain}{$page}";
                $content = $this->fetchContent($url);
                
                if ($content) {
                    $pageSites = $this->extractDomainsFromContent($content);
                    $sites = array_merge($sites, $pageSites);
                }
                
                sleep(1); // Be respectful
            }
            
        } catch (Exception $e) {
            // Ignore errors for individual sites
        }
        
        return array_unique($sites);
    }
    
    /**
     * Query BuiltWith API for WordPress sites
     */
    private function queryBuiltWithAPI(int $maxSites, bool $verbose): array {
        // This would require a BuiltWith API key
        // Implementation depends on API availability
        return [];
    }
    
    /**
     * Query Wappalyzer API for WordPress sites
     */
    private function queryWappalyzerAPI(int $maxSites, bool $verbose): array {
        // This would require a Wappalyzer API key
        // Implementation depends on API availability
        return [];
    }
    
    /**
     * Query domain listing services
     */
    private function queryDomainServices(int $maxSites, bool $verbose): array {
        $sites = [];
        
        try {
            // Query services like DomainTools, Whois, etc.
            // This is a placeholder for actual implementation
            
        } catch (Exception $e) {
            $this->log("Error querying domain services: " . $e->getMessage(), $verbose);
        }
        
        return $sites;
    }
    
    /**
     * Clean up old discovery records
     */
    private function cleanupOldRecords(): int {
        $sql = "DELETE FROM discovery_log WHERE discovered_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Get discovery statistics
     */
    public function getDiscoveryStats(): array {
        $sql = "
            SELECT 
                DATE(discovered_at) as date,
                source,
                COUNT(*) as count
            FROM discovery_log 
            WHERE discovered_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(discovered_at), source
            ORDER BY date DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Simple logging function
     */
    private function log(string $message, bool $show = false): void {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}";
        
        $this->logger[] = $logMessage;
        
        if ($show) {
            echo $logMessage . "\n";
        }
        
        // Write to log file
        $logFile = dirname(__DIR__, 2) . '/logs/discovery.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents($logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
    }
}