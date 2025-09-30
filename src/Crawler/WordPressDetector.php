<?php
/**
 * WordPress detection and plugin estimation for WP-Rank
 * 
 * Analyzes public HTML content and HTTP responses to determine if a site
 * is running WordPress and estimates plugin usage based on public assets.
 */

namespace WPRank\Crawler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WPRank\Config;

class WordPressDetector 
{
    private Client $httpClient;
    private int $timeout;
    
    public function __construct() 
    {
        $this->timeout = Config::get('crawler.timeout', 8);
        
        $this->httpClient = new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 5,
            'headers' => [
                'User-Agent' => 'WP-Rank Crawler/1.0 (+https://wp-rank.org; contact@wp-rank.org)'
            ],
            'verify' => false, // Skip SSL verification for crawling
            'http_errors' => false // Don't throw exceptions on HTTP errors
        ]);
    }
    
    /**
     * Check if robots.txt allows crawling the root path
     * 
     * @param string $domain Domain to check
     * @return bool True if allowed to crawl
     */
    public function isRobotsAllowed(string $domain): bool 
    {
        try {
            $robotsUrl = "https://{$domain}/robots.txt";
            $response = $this->httpClient->get($robotsUrl);
            
            if ($response->getStatusCode() === 200) {
                $robotsContent = (string)$response->getBody();
                
                // Simple robots.txt parsing - look for "Disallow: /" under "User-agent: *"
                $lines = explode("\n", $robotsContent);
                $currentUserAgent = '';
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    
                    if (str_starts_with(strtolower($line), 'user-agent:')) {
                        $currentUserAgent = strtolower(trim(substr($line, 11)));
                    } elseif ($currentUserAgent === '*' && str_starts_with(strtolower($line), 'disallow:')) {
                        $disallowPath = trim(substr($line, 9));
                        if ($disallowPath === '/' || $disallowPath === '') {
                            return false; // Disallowed
                        }
                    }
                }
            }
            
            return true; // Default to allowed if robots.txt is missing or doesn't block
            
        } catch (RequestException $e) {
            // If we can't fetch robots.txt, assume it's allowed
            return true;
        }
    }
    
    /**
     * Fetch the HTML content of a domain's homepage
     * 
     * @param string $domain Domain to fetch
     * @return array|null ['html' => string, 'status_code' => int, 'headers' => array] or null on failure
     */
    public function fetchHomepage(string $domain): ?array 
    {
        $urlsToTry = [
            "https://{$domain}",
            "http://{$domain}"
        ];
        
        foreach ($urlsToTry as $url) {
            try {
                $response = $this->httpClient->get($url);
                $statusCode = $response->getStatusCode();
                
                if ($statusCode >= 200 && $statusCode < 400) {
                    return [
                        'html' => (string)$response->getBody(),
                        'status_code' => $statusCode,
                        'headers' => $response->getHeaders(),
                        'final_url' => $url
                    ];
                }
                
            } catch (RequestException $e) {
                // Try next URL
                continue;
            }
        }
        
        return null;
    }
    
    /**
     * Detect if a site is running WordPress based on public signals
     * 
     * @param string $domain Domain to check
     * @param string|null $html Optional HTML content (will fetch if not provided)
     * @return bool True if WordPress is detected
     */
    public function isWordPress(string $domain, ?string $html = null): bool 
    {
        // Try to detect via wp-json endpoint first
        if ($this->hasWordPressAPI($domain)) {
            return true;
        }
        
        // Analyze HTML content
        if ($html === null) {
            $homepage = $this->fetchHomepage($domain);
            $html = $homepage['html'] ?? '';
        }
        
        if (empty($html)) {
            return false;
        }
        
        // Look for WordPress signatures in HTML
        $wordpressSignatures = [
            '/wp-content/',
            '/wp-includes/',
            'wp-emoji-release.min.js',
            'wp-json',
            'meta name="generator" content="WordPress',
            'class="wp-',
            'id="wp-',
            'wp-block-',
            'wpforms',
            'wp-content/themes/',
            'wp-content/plugins/'
        ];
        
        $signatureCount = 0;
        foreach ($wordpressSignatures as $signature) {
            if (stripos($html, $signature) !== false) {
                $signatureCount++;
            }
        }
        
        // Require at least 2 signatures to reduce false positives
        return $signatureCount >= 2;
    }
    
    /**
     * Check if WordPress REST API endpoint is available
     * 
     * @param string $domain Domain to check
     * @return bool True if wp-json endpoint responds correctly
     */
    private function hasWordPressAPI(string $domain): bool 
    {
        $apiEndpoints = [
            "https://{$domain}/wp-json/",
            "https://{$domain}/wp-json/wp/v2/",
            "http://{$domain}/wp-json/",
            "http://{$domain}/wp-json/wp/v2/"
        ];
        
        foreach ($apiEndpoints as $endpoint) {
            try {
                $response = $this->httpClient->get($endpoint);
                
                if ($response->getStatusCode() === 200) {
                    $contentType = $response->getHeaderLine('Content-Type');
                    if (str_contains($contentType, 'application/json')) {
                        $body = (string)$response->getBody();
                        $data = json_decode($body, true);
                        
                        // Check for WordPress API structure
                        if (is_array($data) && (
                            isset($data['name']) || 
                            isset($data['description']) || 
                            isset($data['namespaces'])
                        )) {
                            return true;
                        }
                    }
                }
                
            } catch (RequestException $e) {
                continue;
            }
        }
        
        return false;
    }
    
    /**
     * Estimate the number of active plugins based on public assets
     * 
     * @param string $html HTML content to analyze
     * @return array ['count' => int, 'evidence' => array]
     */
    public function estimatePlugins(string $html): array 
    {
        if (empty($html)) {
            return ['count' => 0, 'evidence' => []];
        }
        
        $pluginSlugs = [];
        $evidence = [];
        $maxEvidence = Config::get('crawler.max_plugin_evidence', 20);
        
        // Pattern to match /wp-content/plugins/{slug}/...
        $pattern = '#/wp-content/plugins/([^/\s\'"?]+)/#i';
        
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullMatch = $match[0];
                $slug = strtolower($match[1]);
                
                // Skip common false positives
                $skipSlugs = ['wp-content', 'plugins', 'admin', 'includes'];
                if (in_array($slug, $skipSlugs) || strlen($slug) < 2) {
                    continue;
                }
                
                if (!isset($pluginSlugs[$slug])) {
                    $pluginSlugs[$slug] = true;
                    
                    // Collect evidence URLs (limited to prevent bloat)
                    if (count($evidence) < $maxEvidence) {
                        // Extract a cleaner URL from the match
                        $evidenceUrl = $this->extractCleanUrl($fullMatch, $html);
                        if ($evidenceUrl) {
                            $evidence[] = $evidenceUrl;
                        }
                    }
                }
            }
        }
        
        return [
            'count' => count($pluginSlugs),
            'evidence' => array_unique($evidence)
        ];
    }
    
    /**
     * Extract a clean, representative URL from HTML context
     * 
     * @param string $match Matched plugin path
     * @param string $html Full HTML content
     * @return string|null Clean URL or null
     */
    private function extractCleanUrl(string $match, string $html): ?string 
    {
        // Look for the full URL containing this match
        $patterns = [
            "#(https?://[^\\s'\"]+{$match}[^\\s'\">]*)#i",
            "#([^\\s'\"]+{$match}[^\\s'\">]*)#i"
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $urlMatch)) {
                $url = $urlMatch[1];
                
                // Clean up the URL
                $url = htmlspecialchars_decode($url);
                $url = preg_replace('/[\'"><].*$/', '', $url); // Remove trailing HTML
                
                if (filter_var($url, FILTER_VALIDATE_URL) || str_contains($url, '/wp-content/plugins/')) {
                    return $url;
                }
            }
        }
        
        return $match; // Fallback to the original match
    }
    
    /**
     * Detect the active WordPress theme
     * 
     * @param string $html HTML content to analyze
     * @return string|null Theme name or null if not detected
     */
    public function detectTheme(string $html): ?string 
    {
        if (empty($html)) {
            return null;
        }
        
        // Pattern to match /wp-content/themes/{theme-name}/
        $pattern = '#/wp-content/themes/([^/\s\'"?]+)/#i';
        
        if (preg_match($pattern, $html, $match)) {
            $themeName = $match[1];
            
            // Clean up theme name
            $themeName = strtolower($themeName);
            $themeName = str_replace(['-', '_'], ' ', $themeName);
            $themeName = ucwords($themeName);
            
            return $themeName;
        }
        
        return null;
    }
    
    /**
     * Get comprehensive WordPress information for a domain
     * 
     * @param string $domain Domain to analyze
     * @return array WordPress information
     */
    public function analyzeWordPressSite(string $domain): array 
    {
        $result = [
            'domain' => $domain,
            'is_wordpress' => false,
            'theme_name' => null,
            'plugin_count' => 0,
            'plugin_evidence' => [],
            'robots_allowed' => true,
            'status_code' => null,
            'error' => null
        ];
        
        try {
            // Check robots.txt
            $result['robots_allowed'] = $this->isRobotsAllowed($domain);
            
            if (!$result['robots_allowed']) {
                $result['error'] = 'robots_txt_disallowed';
                return $result;
            }
            
            // Fetch homepage
            $homepage = $this->fetchHomepage($domain);
            
            if (!$homepage) {
                $result['error'] = 'fetch_failed';
                return $result;
            }
            
            $result['status_code'] = $homepage['status_code'];
            $html = $homepage['html'];
            
            // WordPress detection
            $result['is_wordpress'] = $this->isWordPress($domain, $html);
            
            if ($result['is_wordpress']) {
                // Plugin estimation
                $pluginData = $this->estimatePlugins($html);
                $result['plugin_count'] = $pluginData['count'];
                $result['plugin_evidence'] = $pluginData['evidence'];
                
                // Theme detection
                $result['theme_name'] = $this->detectTheme($html);
            }
            
        } catch (\Exception $e) {
            $result['error'] = 'analysis_failed: ' . $e->getMessage();
        }
        
        return $result;
    }
}