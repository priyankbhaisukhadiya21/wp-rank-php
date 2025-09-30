-- Additional-- Update crawl_queue table with additional fields for automation
ALTER TABLE crawl_queue 
    ADD COLUMN attempt_count INT DEFAULT 0 AFTER status,
    ADD COLUMN next_attempt_at TIMESTAMP NULL AFTER attempt_count,
    ADD COLUMN last_error TEXT NULL AFTER next_attempt_at,
    ADD COLUMN result VARCHAR(100) NULL AFTER last_error,
    ADD COLUMN completed_at TIMESTAMP NULL AFTER result;

-- Update sites table with ranking timestamp
ALTER TABLE sites 
    ADD COLUMN last_ranked TIMESTAMP NULL AFTER last_crawled;

-- Add rank_position to ranks table if not exists
ALTER TABLE ranks 
    ADD COLUMN rank_position INT NULL AFTER efficiency_score;mated discovery and processing

-- Discovery log table to track where sites were discovered
CREATE TABLE IF NOT EXISTS discovery_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    source VARCHAR(100) NOT NULL,
    discovered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain_source (domain, source),
    KEY idx_discovered_at (discovered_at),
    KEY idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update crawl_queue table with additional fields for automation
ALTER TABLE crawl_queue 
    ADD COLUMN IF NOT EXISTS attempt_count INT DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS next_attempt_at TIMESTAMP NULL AFTER attempt_count,
    ADD COLUMN IF NOT EXISTS last_error TEXT NULL AFTER next_attempt_at,
    ADD COLUMN IF NOT EXISTS result VARCHAR(100) NULL AFTER last_error,
    ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL AFTER result;

-- Update sites table with ranking timestamp
ALTER TABLE sites 
    ADD COLUMN IF NOT EXISTS last_ranked TIMESTAMP NULL AFTER last_crawled;

-- Add rank_position to ranks table if not exists
ALTER TABLE ranks 
    ADD COLUMN IF NOT EXISTS rank_position INT NULL AFTER efficiency_score,
    ADD INDEX IF NOT EXISTS idx_rank_position (rank_position);

-- Create logs directory structure (this would be done by the application)
-- The actual log files will be created automatically by the PHP scripts

-- Add some useful indexes for performance
ALTER TABLE sites ADD INDEX idx_sites_status_crawled (status, last_crawled);
ALTER TABLE crawl_queue ADD INDEX idx_crawl_queue_status_priority (status, priority, created_at);
ALTER TABLE ranks ADD INDEX idx_ranks_efficiency (efficiency_score DESC);
ALTER TABLE sites ADD INDEX idx_sites_domain_status (domain, status);
ALTER TABLE ranks ADD INDEX idx_rank_position (rank_position);