<?php
/**
 * UUID generation utilities for WP-Rank
 */

namespace WPRank\Utils;

use Ramsey\Uuid\Uuid as RamseyUuid;

class Uuid 
{
    /**
     * Generate a version 4 (random) UUID
     * 
     * @return string UUID string
     */
    public static function v4(): string 
    {
        return RamseyUuid::uuid4()->toString();
    }
    
    /**
     * Validate if a string is a valid UUID
     * 
     * @param string $uuid UUID string to validate
     * @return bool True if valid UUID
     */
    public static function isValid(string $uuid): bool 
    {
        return RamseyUuid::isValid($uuid);
    }
}