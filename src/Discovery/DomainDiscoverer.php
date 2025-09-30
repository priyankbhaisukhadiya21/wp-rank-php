<?php
/**
 * Domain discovery service for WP-Rank
 * 
 * Discovers potential WordPress domains from various sources including
 * search engines, directories, and seed lists for automated crawling.
 */

namespace WPRank\Discovery;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WPRank\Database;
use WPRank\Utils\Uuid;
use WPRank\Utils\Domain;
use WPRank\Config;

class DomainDiscoverer 
{
    private Client $httpClient;
    private array $discoveredDomains = [];
    private int $maxDomainsPerRun;
    private int $discoveryDelay;
    
    public function __construct() 
    {
        $this->httpClient = new Client([
            'timeout' => Config::get('crawler.timeout', 30),
            'headers' => [
                'User-Agent' => 'WP-Rank Discovery Bot/1.0 (+https://wp-rank.org; contact@wp-rank.org)'
            ]
        ]);
        
        $this->maxDomainsPerRun = Config::get('crawler.max_domains_per_run', 50);
        $this->discoveryDelay = Config::get('crawler.discovery_delay', 2);
    }
    
    /**
     * Main discovery method that combines all sources
     * 
     * @param int|null $maxDomains Maximum domains to discover (overrides config)
     * @return array Discovery results
     */
    public function discoverDomains(?int $maxDomains = null): array 
    {
        $maxDomains = $maxDomains ?? $this->maxDomainsPerRun;
        $startTime = microtime(true);
        
        $results = [
            'total_discovered' => 0,
            'sources' => [],
            'processing_time' => 0,
            'domains' => []
        ];
        
        try {
            if (Config::isDebug()) {
                error_log("Starting domain discovery (max: {$maxDomains})");
            }
            
            // Discovery source 1: Seed domains
            $seedResults = $this->discoverFromSeeds();
            $results['sources']['seeds'] = $seedResults;
            
            // Discovery source 2: Search engines (if configured)
            if (Config::get('external_apis.google_search_key')) {
                sleep($this->discoveryDelay);
                $googleResults = $this->discoverFromGoogle();
                $results['sources']['google'] = $googleResults;
            }
            
            if (Config::get('external_apis.bing_api_key')) {
                sleep($this->discoveryDelay);
                $bingResults = $this->discoverFromBing();
                $results['sources']['bing'] = $bingResults;
            }
            
            // Discovery source 3: WordPress directories
            sleep($this->discoveryDelay);
            $directoryResults = $this->discoverFromDirectories();
            $results['sources']['directories'] = $directoryResults;
            
            // Combine and deduplicate all discovered domains
            $allDomains = $this->combineAndDeduplicate();
            
            // Limit to max domains
            $finalDomains = array_slice($allDomains, 0, $maxDomains);
            
            // Enqueue domains for crawling
            $enqueuedCount = $this->enqueueDomains($finalDomains);
            
            $results['total_discovered'] = count($finalDomains);
            $results['domains'] = $finalDomains;
            $results['enqueued_count'] = $enqueuedCount;
            $results['processing_time'] = round(microtime(true) - $startTime, 2);
            
            if (Config::isDebug()) {
                error_log("Discovery completed: {$results['total_discovered']} domains found, {$enqueuedCount} enqueued");
            }
            
        } catch (\Exception $e) {
            error_log("Domain discovery failed: " . $e->getMessage());
            $results['error'] = $e->getMessage();
            $results['processing_time'] = round(microtime(true) - $startTime, 2);
        }
        
        return $results;
    }
    
    /**
     * Discover domains from predefined seed list
     * 
     * @return array Discovery results from seeds
     */
    private function discoverFromSeeds(): array 
    {
        $seedDomains = [
            // Popular WordPress sites
            'wordpress.org',
            'wordpress.com', 
            'automattic.com',
            'wpengine.com',
            'elementor.com',
            'yoast.com',
            'wpbeginner.com',
            'wpmudev.org',
            'ninjaforms.com',
            'gravityforms.com',
            'woocommerce.com',
            'jetpack.com',
            
            // Popular sites that might use WordPress
            'techcrunch.com',
            'variety.com',
            'sony.com',
            'mercedes-benz.com',
            'newyorker.com',
            'reuters.com',
            'cnn.com',
            'bbc.co.uk',
            'usatoday.com',
            
            // Sample sites for testing
            'smashingmagazine.com',
            'a11yproject.com',
            'css-tricks.com'
        ];
        
        $discovered = [];
        foreach ($seedDomains as $domain) {
            $normalized = Domain::normalize($domain);
            if ($normalized && !in_array($normalized, $this->discoveredDomains)) {
                $discovered[] = [
                    'domain' => $normalized,
                    'source' => 'seed',
                    'confidence' => 0.8, // High confidence for curated seeds
                    'priority' => 100
                ];
                $this->discoveredDomains[] = $normalized;
            }
        }
        
        return [
            'count' => count($discovered),
            'domains' => $discovered
        ];
    }
    
    /**
     * Discover domains using Google Custom Search API
     * 
     * @return array Discovery results from Google
     */
    private function discoverFromGoogle(): array 
    {
        $apiKey = Config::get('external_apis.google_search_key');
        $engineId = Config::get('external_apis.google_search_engine_id');
        
        if (empty($apiKey) || empty($engineId)) {
            return ['count' => 0, 'domains' => [], 'error' => 'API not configured'];
        }
        
        $queries = [
            'site:*.com "wp-content" "wordpress"',
            'site:*.org "wp-json" "wordpress"',
            '"powered by wordpress" site:*.com',
            '"wp-theme" site:*.org'
        ];
        
        $discovered = [];
        
        foreach (array_slice($queries, 0, 2) as $query) { // Limit queries to avoid quota issues
            try {
                $response = $this->httpClient->get('https://www.googleapis.com/customsearch/v1', [
                    'query' => [
                        'key' => $apiKey,
                        'cx' => $engineId,
                        'q' => $query,
                        'num' => 10
                    ]
                ]);
                
                $data = json_decode((string)$response->getBody(), true);
                
                if (isset($data['items'])) {
                    foreach ($data['items'] as $item) {
                        $domain = Domain::extractFromUrl($item['link']);
                        if ($domain && !in_array($domain, $this->discoveredDomains)) {
                            $discovered[] = [
                                'domain' => $domain,
                                'source' => 'google_search',
                                'confidence' => 0.7,
                                'priority' => 80,
                                'url' => $item['link'],
                                'title' => $item['title'] ?? null
                            ];
                            $this->discoveredDomains[] = $domain;
                        }
                    }
                }
                
                sleep($this->discoveryDelay); // Rate limiting
                
            } catch (RequestException $e) {
                error_log("Google search failed for query '{$query}': " . $e->getMessage());
            }
        }
        
        return [
            'count' => count($discovered),
            'domains' => $discovered
        ];
    }
    
    /**
     * Discover domains using Bing Search API
     * 
     * @return array Discovery results from Bing
     */
    private function discoverFromBing(): array 
    {
        $apiKey = Config::get('external_apis.bing_api_key');
        
        if (empty($apiKey)) {
            return ['count' => 0, 'domains' => [], 'error' => 'API not configured'];
        }
        
        $queries = [
            'site:*.com "wp-content"',
            'site:*.org "wordpress"'
        ];
        
        $discovered = [];
        
        foreach ($queries as $query) {
            try {
                $response = $this->httpClient->get('https://api.cognitive.microsoft.com/bing/v7.0/search', [
                    'headers' => [
                        'Ocp-Apim-Subscription-Key' => $apiKey
                    ],
                    'query' => [
                        'q' => $query,
                        'count' => 10
                    ]
                ]);
                
                $data = json_decode((string)$response->getBody(), true);
                
                if (isset($data['webPages']['value'])) {
                    foreach ($data['webPages']['value'] as $item) {
                        $domain = Domain::extractFromUrl($item['url']);
                        if ($domain && !in_array($domain, $this->discoveredDomains)) {
                            $discovered[] = [
                                'domain' => $domain,
                                'source' => 'bing_search',
                                'confidence' => 0.7,
                                'priority' => 80,
                                'url' => $item['url'],
                                'title' => $item['name'] ?? null
                            ];
                            $this->discoveredDomains[] = $domain;
                        }
                    }
                }
                
                sleep($this->discoveryDelay); // Rate limiting
                
            } catch (RequestException $e) {
                error_log("Bing search failed for query '{$query}': " . $e->getMessage());
            }
        }
        
        return [
            'count' => count($discovered),
            'domains' => $discovered
        ];
    }
    
    /**
     * Discover domains from WordPress directories and showcases
     * 
     * @return array Discovery results from directories
     */
    private function discoverFromDirectories(): array 
    {
        $directoryUrls = [
            'https://wordpress.org/showcase/',
            // Add more directory URLs as available
        ];
        
        $discovered = [];
        
        foreach ($directoryUrls as $url) {
            try {
                $response = $this->httpClient->get($url);
                $html = (string)$response->getBody();
                
                // Extract domains from showcase links
                // This is a simplified implementation - would need more sophisticated parsing for real directories
                if (preg_match_all('/https?:\/\/([^\/\s"\']+)/i', $html, $matches)) {
                    foreach ($matches[1] as $domain) {
                        $normalized = Domain::normalize($domain);
                        if ($normalized && !in_array($normalized, $this->discoveredDomains)) {
                            $discovered[] = [
                                'domain' => $normalized,
                                'source' => 'directory',
                                'confidence' => 0.9, // High confidence from WordPress showcase
                                'priority' => 90
                            ];
                            $this->discoveredDomains[] = $normalized;
                        }
                    }
                }
                
                sleep($this->discoveryDelay);
                
            } catch (RequestException $e) {
                error_log("Directory discovery failed for {$url}: " . $e->getMessage());
            }
        }
        
        return [
            'count' => count($discovered),
            'domains' => $discovered
        ];
    }
    
    /**
     * Combine results from all sources and remove duplicates
     * 
     * @return array Deduplicated domain list
     */
    private function combineAndDeduplicate(): array 
    {
        // For now, we already deduplicated during discovery
        // Return unique domains from the discovered list
        $unique = [];
        $seenDomains = [];
        
        foreach ($this->discoveredDomains as $domain) {
            if (!in_array($domain, $seenDomains)) {
                $unique[] = [
                    'domain' => $domain,
                    'discovered_at' => date('Y-m-d H:i:s')
                ];
                $seenDomains[] = $domain;
            }
        }
        
        return $unique;
    }
    
    /**
     * Enqueue discovered domains for crawling
     * 
     * @param array $domains Domains to enqueue
     * @return int Number of domains successfully enqueued
     */
    private function enqueueDomains(array $domains): int 
    {
        $enqueuedCount = 0;
        
        foreach ($domains as $domainData) {
            $domain = $domainData['domain'];
            
            try {
                // Check if domain already exists in sites or queue
                $existsStmt = Database::execute(
                    "SELECT COUNT(*) FROM sites WHERE domain = ?
                     UNION ALL
                     SELECT COUNT(*) FROM crawl_queue WHERE domain = ?",
                    [$domain, $domain]
                );
                
                $exists = $existsStmt->fetchColumn();
                
                if (!$exists) {
                    // Add to crawl queue
                    $queueId = Uuid::v4();
                    Database::execute(
                        "INSERT INTO crawl_queue (id, domain, priority, next_attempt_at) 
                         VALUES (?, ?, 200, NOW())", // Lower priority for discovered domains
                        [$queueId, $domain]
                    );
                    
                    $enqueuedCount++;
                }
                
            } catch (\Exception $e) {
                error_log("Failed to enqueue domain {$domain}: " . $e->getMessage());
            }
        }
        
        return $enqueuedCount;
    }
    
    /**
     * Get discovery statistics
     * 
     * @return array Discovery statistics
     */
    public function getDiscoveryStats(): array 
    {
        $stmt = Database::execute("
            SELECT 
                COUNT(*) as total_in_queue,
                COUNT(CASE WHEN priority <= 100 THEN 1 END) as high_priority,
                COUNT(CASE WHEN attempt_count = 0 THEN 1 END) as new_discoveries,
                AVG(attempt_count) as avg_attempts
            FROM crawl_queue
        ");
        
        $queueStats = $stmt->fetch();
        
        $stmt = Database::execute("
            SELECT COUNT(*) as total_sites
            FROM sites
        ");
        
        $siteStats = $stmt->fetch();
        
        return [
            'queue' => [
                'total_queued' => (int)($queueStats['total_in_queue'] ?? 0),
                'high_priority' => (int)($queueStats['high_priority'] ?? 0),
                'new_discoveries' => (int)($queueStats['new_discoveries'] ?? 0),
                'avg_attempts' => round((float)($queueStats['avg_attempts'] ?? 0), 1)
            ],
            'sites' => [
                'total_analyzed' => (int)($siteStats['total_sites'] ?? 0)
            ]
        ];
    }
}