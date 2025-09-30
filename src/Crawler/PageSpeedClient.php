<?php
/**
 * Google PageSpeed Insights API client for WP-Rank
 * 
 * Fetches performance metrics from Google PageSpeed Insights API including
 * PSI score, Core Web Vitals (LCP, CLS, TBT), and other performance metrics.
 */

namespace WPRank\Crawler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WPRank\Config;

class PageSpeedClient 
{
    private Client $httpClient;
    private string $apiKey;
    private int $timeout;
    
    public function __construct() 
    {
        $this->apiKey = Config::get('api.psi_api_key', '');
        $this->timeout = Config::get('crawler.timeout', 30); // PSI can be slow
        
        $this->httpClient = new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'WP-Rank PSI Client/1.0'
            ]
        ]);
    }
    
    /**
     * Fetch PageSpeed Insights metrics for a domain
     * 
     * @param string $domain Domain to analyze
     * @param string $strategy Strategy: 'desktop' or 'mobile'
     * @return array|null PSI metrics or null on failure
     */
    public function fetchMetrics(string $domain, string $strategy = 'desktop'): ?array 
    {
        if (empty($this->apiKey)) {
            if (Config::isDebug()) {
                error_log("PSI API key not configured, skipping PSI analysis for {$domain}");
            }
            return null;
        }
        
        $url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
        $targetUrl = "https://{$domain}";
        
        $params = [
            'url' => $targetUrl,
            'category' => 'PERFORMANCE',
            'strategy' => strtoupper($strategy),
            'key' => $this->apiKey
        ];
        
        try {
            if (Config::isDebug()) {
                error_log("Fetching PSI metrics for {$domain} ({$strategy})");
            }
            
            $response = $this->httpClient->get($url, ['query' => $params]);
            
            if ($response->getStatusCode() !== 200) {
                error_log("PSI API returned status code: " . $response->getStatusCode());
                return null;
            }
            
            $data = json_decode((string)$response->getBody(), true);
            
            if (!$data || !isset($data['lighthouseResult'])) {
                error_log("Invalid PSI API response structure for {$domain}");
                return null;
            }
            
            return $this->parsePageSpeedResults($data);
            
        } catch (RequestException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
            
            if ($statusCode === 429) {
                error_log("PSI API rate limit exceeded for {$domain}");
            } elseif ($statusCode === 400) {
                error_log("PSI API returned 400 (bad request) for {$domain} - URL may not be accessible");
            } else {
                error_log("PSI API request failed for {$domain}: " . $e->getMessage());
            }
            
            return null;
        } catch (\Exception $e) {
            error_log("PSI analysis failed for {$domain}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Parse PageSpeed Insights API response and extract relevant metrics
     * 
     * @param array $data Raw PSI API response
     * @return array Parsed metrics
     */
    private function parsePageSpeedResults(array $data): array 
    {
        $lighthouse = $data['lighthouseResult'] ?? [];
        $categories = $lighthouse['categories'] ?? [];
        $audits = $lighthouse['audits'] ?? [];
        
        // Performance score (0-100)
        $performanceScore = null;
        if (isset($categories['performance']['score'])) {
            $performanceScore = (int)round($categories['performance']['score'] * 100);
        }
        
        // Core Web Vitals
        $metrics = [
            'psi_score' => $performanceScore,
            'lcp_ms' => null,     // Largest Contentful Paint
            'cls' => null,        // Cumulative Layout Shift  
            'tbt_ms' => null,     // Total Blocking Time
            'fcp_ms' => null,     // First Contentful Paint
            'si_ms' => null,      // Speed Index
            'tti_ms' => null,     // Time to Interactive
        ];
        
        // Extract Core Web Vitals from audits
        $auditMappings = [
            'largest-contentful-paint' => 'lcp_ms',
            'cumulative-layout-shift' => 'cls',
            'total-blocking-time' => 'tbt_ms',
            'first-contentful-paint' => 'fcp_ms',
            'speed-index' => 'si_ms',
            'interactive' => 'tti_ms'
        ];
        
        foreach ($auditMappings as $auditKey => $metricKey) {
            if (isset($audits[$auditKey]['numericValue'])) {
                $value = $audits[$auditKey]['numericValue'];
                
                // CLS is already a ratio, others are in milliseconds
                if ($metricKey === 'cls') {
                    $metrics[$metricKey] = round($value, 4);
                } else {
                    $metrics[$metricKey] = (int)round($value);
                }
            }
        }
        
        // Additional metadata
        $metrics['strategy'] = $lighthouse['configSettings']['emulatedFormFactor'] ?? 'desktop';
        $metrics['lighthouse_version'] = $lighthouse['lighthouseVersion'] ?? null;
        $metrics['fetch_time'] = $lighthouse['fetchTime'] ?? null;
        $metrics['final_url'] = $lighthouse['finalUrl'] ?? null;
        
        // Performance category details
        if (isset($categories['performance'])) {
            $perf = $categories['performance'];
            $metrics['performance_title'] = $perf['title'] ?? null;
            $metrics['performance_description'] = $perf['description'] ?? null;
        }
        
        return $metrics;
    }
    
    /**
     * Fetch metrics for both desktop and mobile strategies
     * 
     * @param string $domain Domain to analyze
     * @return array Combined metrics for both strategies
     */
    public function fetchBothStrategies(string $domain): array 
    {
        $result = [
            'domain' => $domain,
            'desktop' => null,
            'mobile' => null,
            'combined_score' => null
        ];
        
        // Fetch desktop metrics
        $result['desktop'] = $this->fetchMetrics($domain, 'desktop');
        
        // Add delay between requests to be respectful to the API
        sleep(1);
        
        // Fetch mobile metrics
        $result['mobile'] = $this->fetchMetrics($domain, 'mobile');
        
        // Calculate combined score (weighted average: 70% desktop, 30% mobile)
        if ($result['desktop'] && $result['mobile']) {
            $desktopScore = $result['desktop']['psi_score'] ?? 0;
            $mobileScore = $result['mobile']['psi_score'] ?? 0;
            
            if ($desktopScore > 0 && $mobileScore > 0) {
                $result['combined_score'] = (int)round(($desktopScore * 0.7) + ($mobileScore * 0.3));
            }
        } elseif ($result['desktop']) {
            $result['combined_score'] = $result['desktop']['psi_score'];
        } elseif ($result['mobile']) {
            $result['combined_score'] = $result['mobile']['psi_score'];
        }
        
        return $result;
    }
    
    /**
     * Check if PSI API is properly configured
     * 
     * @return bool True if API key is available
     */
    public function isConfigured(): bool 
    {
        return !empty($this->apiKey);
    }
    
    /**
     * Get API quota information (requires a test request)
     * 
     * @return array|null Quota information or null
     */
    public function getQuotaInfo(): ?array 
    {
        if (!$this->isConfigured()) {
            return null;
        }
        
        try {
            // Make a simple request to check quota
            $response = $this->httpClient->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', [
                'query' => [
                    'url' => 'https://www.google.com',
                    'key' => $this->apiKey
                ]
            ]);
            
            $headers = $response->getHeaders();
            
            return [
                'quota_used' => $headers['X-RateLimit-Used'][0] ?? null,
                'quota_limit' => $headers['X-RateLimit-Limit'][0] ?? null,
                'quota_remaining' => $headers['X-RateLimit-Remaining'][0] ?? null,
            ];
            
        } catch (RequestException $e) {
            return null;
        }
    }
}