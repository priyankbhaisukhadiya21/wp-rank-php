<?php

namespace WPRank\Services;

use WPRank\Config;
use Exception;

class AnalysisService {
    
    private Config $config;
    private string $psiApiKey;
    
    public function __construct() {
        $this->config = new Config();
        $this->psiApiKey = $this->config->get('PSI_API_KEY');
    }
    
    /**
     * Analyze a website for performance and plugin metrics
     */
    public function analyzeSite(string $domain): ?array {
        try {
            // Get PageSpeed Insights scores
            $psiData = $this->getPageSpeedInsights($domain);
            
            if (!$psiData) {
                throw new Exception("Failed to get PageSpeed Insights data");
            }
            
            // Get plugin count estimation
            $pluginCount = $this->estimatePluginCount($domain);
            
            return [
                'mobile_score' => $psiData['mobile_score'] ?? 0,
                'desktop_score' => $psiData['desktop_score'] ?? 0,
                'plugin_count' => $pluginCount,
                'analyzed_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            error_log("Analysis failed for {$domain}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get PageSpeed Insights data for both mobile and desktop
     */
    private function getPageSpeedInsights(string $domain): ?array {
        $url = "https://{$domain}";
        $results = [];
        
        // Get mobile score
        $mobileData = $this->callPageSpeedAPI($url, 'mobile');
        $results['mobile_score'] = $this->extractScore($mobileData);
        
        // Small delay between API calls
        sleep(1);
        
        // Get desktop score
        $desktopData = $this->callPageSpeedAPI($url, 'desktop');
        $results['desktop_score'] = $this->extractScore($desktopData);
        
        return $results;
    }
    
    /**
     * Call PageSpeed Insights API
     */
    private function callPageSpeedAPI(string $url, string $strategy): ?array {
        $apiUrl = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?" . http_build_query([
            'url' => $url,
            'key' => $this->psiApiKey,
            'strategy' => $strategy,
            'category' => 'performance'
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'WP-Rank-Bot/1.0',
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Extract performance score from PageSpeed data
     */
    private function extractScore(?array $data): int {
        if (!$data || !isset($data['lighthouseResult']['categories']['performance']['score'])) {
            return 0;
        }
        
        $score = $data['lighthouseResult']['categories']['performance']['score'];
        return (int)round($score * 100);
    }
    
    /**
     * Estimate plugin count by analyzing frontend assets
     */
    private function estimatePluginCount(string $domain): int {
        try {
            $url = "https://{$domain}";
            
            // Get the homepage HTML
            $html = $this->fetchPageContent($url);
            if (!$html) {
                return 0;
            }
            
            // Look for plugin indicators
            $pluginIndicators = $this->findPluginIndicators($html);
            
            // Also check common plugin endpoints
            $apiPlugins = $this->checkPluginEndpoints($domain);
            
            // Combine and deduplicate
            $allPlugins = array_unique(array_merge($pluginIndicators, $apiPlugins));
            
            return count($allPlugins);
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Fetch page content
     */
    private function fetchPageContent(string $url): ?string {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'WP-Rank-Bot/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_MAXREDIRS => 5
        ]);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode === 200 && $content) ? $content : null;
    }
    
    /**
     * Find plugin indicators in HTML content
     */
    private function findPluginIndicators(string $html): array {
        $plugins = [];
        
        // Common patterns for plugin detection
        $patterns = [
            // CSS files in plugin directories
            '/wp-content\/plugins\/([^\/\'"]+)/i',
            // JavaScript files in plugin directories
            '/wp-content\/plugins\/([^\/\'"]+).*\.js/i',
            // Plugin-specific HTML comments
            '/<!-- .*?([a-zA-Z\-]+) plugin/i',
            // Generator meta tags
            '/generator.*?([a-zA-Z\-]+) \d+\.\d+/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $match) {
                    $pluginName = $this->normalizePluginName($match);
                    if ($pluginName && !in_array($pluginName, $plugins)) {
                        $plugins[] = $pluginName;
                    }
                }
            }
        }
        
        // Look for specific plugin signatures
        $knownPlugins = [
            'yoast' => '/yoast/i',
            'elementor' => '/elementor/i',
            'woocommerce' => '/woocommerce/i',
            'contact-form-7' => '/contact-form-7/i',
            'jetpack' => '/jetpack/i',
            'akismet' => '/akismet/i',
            'wordfence' => '/wordfence/i',
            'wpbakery' => '/js_composer|wpbakery/i',
            'slider-revolution' => '/revslider|slider.*revolution/i',
            'wpml' => '/wpml/i'
        ];
        
        foreach ($knownPlugins as $plugin => $regex) {
            if (preg_match($regex, $html)) {
                if (!in_array($plugin, $plugins)) {
                    $plugins[] = $plugin;
                }
            }
        }
        
        return $plugins;
    }
    
    /**
     * Check plugin endpoints for additional detection
     */
    private function checkPluginEndpoints(string $domain): array {
        $plugins = [];
        
        // Check wp-json endpoints that might reveal plugins
        $endpoints = [
            '/wp-json/wp/v2/',
            '/wp-json/',
            '/?rest_route=/wp/v2/'
        ];
        
        foreach ($endpoints as $endpoint) {
            $url = "https://{$domain}{$endpoint}";
            $content = $this->fetchPageContent($url);
            
            if ($content) {
                $data = json_decode($content, true);
                if ($data && is_array($data)) {
                    // Look for plugin-specific API endpoints
                    $this->findPluginsInApiResponse($data, $plugins);
                }
            }
        }
        
        return $plugins;
    }
    
    /**
     * Find plugins in API response
     */
    private function findPluginsInApiResponse(array $data, array &$plugins): void {
        // Look for plugin-specific namespaces or routes
        if (isset($data['routes'])) {
            foreach ($data['routes'] as $route => $info) {
                if (preg_match('/\/wp\/v2\/([^\/]+)/', $route, $matches)) {
                    $namespace = $matches[1];
                    if (!in_array($namespace, ['posts', 'pages', 'users', 'comments', 'media', 'types', 'statuses'])) {
                        $plugins[] = $namespace;
                    }
                }
            }
        }
        
        if (isset($data['namespaces'])) {
            foreach ($data['namespaces'] as $namespace) {
                if (!in_array($namespace, ['wp/v2', 'oembed/1.0']) && !in_array($namespace, $plugins)) {
                    $plugins[] = $namespace;
                }
            }
        }
    }
    
    /**
     * Normalize plugin name
     */
    private function normalizePluginName(string $name): ?string {
        $name = strtolower(trim($name));
        
        // Remove common suffixes/prefixes
        $name = preg_replace('/^(wp-|wordpress-|plugin-)/', '', $name);
        $name = preg_replace('/(-plugin|-wp|-wordpress)$/', '', $name);
        
        // Remove version numbers
        $name = preg_replace('/[0-9\.\-]+$/', '', $name);
        
        // Must be at least 3 characters and contain letters
        if (strlen($name) < 3 || !preg_match('/[a-z]/', $name)) {
            return null;
        }
        
        // Filter out common false positives
        $blacklist = ['min', 'css', 'js', 'jquery', 'ajax', 'admin', 'front', 'public', 'assets', 'dist', 'build'];
        if (in_array($name, $blacklist)) {
            return null;
        }
        
        return $name;
    }
    
    /**
     * Get detailed analysis including metrics
     */
    public function getDetailedAnalysis(string $domain): ?array {
        $basicAnalysis = $this->analyzeSite($domain);
        
        if (!$basicAnalysis) {
            return null;
        }
        
        // Add additional metrics
        $basicAnalysis['efficiency_score'] = $this->calculateEfficiencyScore(
            $basicAnalysis['mobile_score'],
            $basicAnalysis['desktop_score'],
            $basicAnalysis['plugin_count']
        );
        
        return $basicAnalysis;
    }
    
    /**
     * Calculate efficiency score based on performance and plugin count
     */
    private function calculateEfficiencyScore(int $mobileScore, int $desktopScore, int $pluginCount): float {
        $avgScore = ($mobileScore + $desktopScore) / 2;
        
        // Efficiency = Performance / (Plugin Count Factor)
        // Plugin count factor: more plugins = higher factor = lower efficiency
        $pluginFactor = max(1, log($pluginCount + 1) * 10);
        
        return round($avgScore / $pluginFactor, 2);
    }
}