-- Migration to align database with automation system
-- This updates the existing schema to work with the new automation features

-- Add missing columns to crawl_queue table
-- Check and add status column
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE table_name = 'crawl_queue' 
     AND table_schema = DATABASE() 
     AND column_name = 'status') > 0,
    'SELECT "Column status already exists"',
    'ALTER TABLE crawl_queue ADD COLUMN status VARCHAR(50) DEFAULT "pending" AFTER priority'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add result column
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE table_name = 'crawl_queue' 
     AND table_schema = DATABASE() 
     AND column_name = 'result') > 0,
    'SELECT "Column result already exists"',
    'ALTER TABLE crawl_queue ADD COLUMN result VARCHAR(100) NULL AFTER last_error'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add completed_at column
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE table_name = 'crawl_queue' 
     AND table_schema = DATABASE() 
     AND column_name = 'completed_at') > 0,
    'SELECT "Column completed_at already exists"',
    'ALTER TABLE crawl_queue ADD COLUMN completed_at TIMESTAMP NULL AFTER result'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add created_at column to crawl_queue
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE table_name = 'crawl_queue' 
     AND table_schema = DATABASE() 
     AND column_name = 'created_at') > 0,
    'SELECT "Column created_at already exists"',
    'ALTER TABLE crawl_queue ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER completed_at'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add updated_at column to crawl_queue
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE table_name = 'crawl_queue' 
     AND table_schema = DATABASE() 
     AND column_name = 'updated_at') > 0,
    'SELECT "Column updated_at already exists"',
    'ALTER TABLE crawl_queue ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add missing columns to sites table
ALTER TABLE sites 
ADD COLUMN IF NOT EXISTS mobile_score INT NULL AFTER theme_name,
ADD COLUMN IF NOT EXISTS desktop_score INT NULL AFTER mobile_score,
ADD COLUMN IF NOT EXISTS plugin_count INT NULL AFTER desktop_score,
ADD COLUMN IF NOT EXISTS last_crawled TIMESTAMP NULL AFTER last_crawl_at,
ADD COLUMN IF NOT EXISTS last_ranked TIMESTAMP NULL AFTER last_crawled,
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER last_ranked;

-- Update sites table to use consistent column names
UPDATE sites SET last_crawled = last_crawl_at WHERE last_crawled IS NULL AND last_crawl_at IS NOT NULL;

-- Add missing columns to ranks table
ALTER TABLE ranks 
ADD COLUMN IF NOT EXISTS domain VARCHAR(255) AFTER site_id,
ADD COLUMN IF NOT EXISTS mobile_score INT NULL AFTER efficiency_score,
ADD COLUMN IF NOT EXISTS desktop_score INT NULL AFTER mobile_score,
ADD COLUMN IF NOT EXISTS plugin_count INT NULL AFTER desktop_score,
ADD COLUMN IF NOT EXISTS rank_position INT NULL AFTER plugin_count;

-- Update ranks table with domain information from sites
UPDATE ranks r 
JOIN sites s ON r.site_id = s.id 
SET r.domain = s.domain 
WHERE r.domain IS NULL;

-- Create discovery_log table if it doesn't exist
CREATE TABLE IF NOT EXISTS discovery_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    source VARCHAR(100) NOT NULL,
    discovered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain_source (domain, source),
    KEY idx_discovered_at (discovered_at),
    KEY idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add useful indexes for automation
ALTER TABLE sites ADD INDEX IF NOT EXISTS idx_sites_status_crawled (status, last_crawled);
ALTER TABLE crawl_queue ADD INDEX IF NOT EXISTS idx_crawl_queue_status_priority (status, priority, created_at);
ALTER TABLE ranks ADD INDEX IF NOT EXISTS idx_ranks_efficiency (efficiency_score DESC);
ALTER TABLE ranks ADD INDEX IF NOT EXISTS idx_rank_position (rank_position);

-- Update any NULL status in crawl_queue to 'pending'
UPDATE crawl_queue SET status = 'pending' WHERE status IS NULL;

-- Ensure sites have proper status
UPDATE sites SET status = 'active' WHERE status IS NULL AND is_wordpress = 1;
UPDATE sites SET status = 'inactive' WHERE status IS NULL AND (is_wordpress = 0 OR is_wordpress IS NULL);