<?php
/**
 * Domain validation and normalization utilities for WP-Rank
 */

namespace WPRank\Utils;

class Domain 
{
    /**
     * Normalize a domain string by removing protocol, path, and converting to lowercase
     * 
     * @param string $input Raw domain input (may include protocol, path, etc.)
     * @return string|null Normalized domain or null if invalid
     */
    public static function normalize(string $input): ?string 
    {
        $input = trim($input);
        
        if (empty($input)) {
            return null;
        }
        
        // Remove protocol if present
        if (str_starts_with($input, 'http://') || str_starts_with($input, 'https://')) {
            $parsed = parse_url($input);
            $host = $parsed['host'] ?? null;
        } else {
            // Remove any path if present
            $parts = explode('/', $input);
            $host = $parts[0];
        }
        
        if (!$host) {
            return null;
        }
        
        // Convert to lowercase
        $host = strtolower($host);
        
        // Remove www. prefix
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        
        // Convert international domain names to ASCII if possible
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = $ascii;
            }
        }
        
        // Remove trailing dots
        $host = rtrim($host, '.');
        
        // Basic validation
        if (!self::isValid($host)) {
            return null;
        }
        
        return $host;
    }
    
    /**
     * Validate if a domain is properly formatted
     * 
     * @param string $domain Domain to validate
     * @return bool True if valid domain
     */
    public static function isValid(string $domain): bool 
    {
        // Check basic format
        if (empty($domain) || strlen($domain) > 253) {
            return false;
        }
        
        // Must contain at least one dot
        if (strpos($domain, '.') === false) {
            return false;
        }
        
        // Must not start or end with hyphen or dot
        if (str_starts_with($domain, '-') || str_ends_with($domain, '-') ||
            str_starts_with($domain, '.') || str_ends_with($domain, '.')) {
            return false;
        }
        
        // Check each label
        $labels = explode('.', $domain);
        foreach ($labels as $label) {
            if (empty($label) || strlen($label) > 63) {
                return false;
            }
            
            // Label must not start or end with hyphen
            if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return false;
            }
            
            // Label must contain only alphanumeric characters and hyphens
            if (!preg_match('/^[a-z0-9-]+$/i', $label)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Extract domain from a full URL
     * 
     * @param string $url Full URL
     * @return string|null Domain or null if extraction fails
     */
    public static function extractFromUrl(string $url): ?string 
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;
        
        if (!$host) {
            return null;
        }
        
        return self::normalize($host);
    }
    
    /**
     * Generate common URL variations for a domain
     * 
     * @param string $domain Normalized domain
     * @return array Array of URL variations
     */
    public static function getUrlVariations(string $domain): array 
    {
        return [
            "https://{$domain}",
            "http://{$domain}",
            "https://www.{$domain}",
            "http://www.{$domain}"
        ];
    }
}