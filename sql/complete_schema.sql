-- WP-Rank Complete Database Schema
-- Compatible with MySQL 8.0+ and all current automation services
-- This schema is validated against all service requirements

-- Create database
CREATE DATABASE IF NOT EXISTS global_wp_rank
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE global_wp_rank;

-- ========================================
-- SITES TABLE
-- ========================================
-- Primary storage for WordPress sites with all metrics
CREATE TABLE IF NOT EXISTS sites (
  id CHAR(36) NOT NULL PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  is_wordpress TINYINT(1) DEFAULT NULL,
  theme_name VARCHAR(255) DEFAULT NULL,
  
  -- Performance scores
  mobile_score INT NULL,
  desktop_score INT NULL,
  plugin_count INT NULL,
  
  -- Timestamps
  first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_crawl_at TIMESTAMP NULL DEFAULT NULL,
  last_crawled TIMESTAMP NULL DEFAULT NULL,  -- Used by QueueProcessor
  last_ranked TIMESTAMP NULL DEFAULT NULL,   -- Used by RankingService
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  -- Status tracking
  status ENUM('pending','active','error','blocked') DEFAULT 'pending',
  
  -- Indexes for performance
  UNIQUE KEY uq_sites_domain (domain),
  INDEX idx_sites_status (status),
  INDEX idx_sites_is_wordpress (is_wordpress),
  INDEX idx_sites_last_crawl (last_crawl_at),
  INDEX idx_sites_status_crawled (status, last_crawled),
  INDEX idx_sites_domain_status (domain, status),
  INDEX idx_sites_scores (mobile_score, desktop_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- CRAWL QUEUE TABLE
-- ========================================
-- Queue management for automated processing
CREATE TABLE IF NOT EXISTS crawl_queue (
  id CHAR(36) NOT NULL PRIMARY KEY,  -- UUID format used by services
  domain VARCHAR(255) NOT NULL,
  priority INT DEFAULT 5,
  source VARCHAR(100) DEFAULT NULL,
  
  -- Status and processing
  status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
  attempt_count INT DEFAULT 0,
  next_attempt_at TIMESTAMP NULL DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  result VARCHAR(100) NULL,
  
  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  
  -- Indexes for queue processing
  INDEX idx_crawl_queue_status (status),
  INDEX idx_crawl_queue_priority (priority DESC),
  INDEX idx_crawl_queue_next_attempt (next_attempt_at),
  INDEX idx_crawl_queue_created (created_at),
  INDEX idx_crawl_queue_status_priority (status, priority, created_at),
  INDEX idx_crawl_queue_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- RANKS TABLE
-- ========================================
-- Global rankings and efficiency scores
CREATE TABLE IF NOT EXISTS ranks (
  id CHAR(36) NOT NULL PRIMARY KEY,
  site_id CHAR(36) NOT NULL,
  domain VARCHAR(255) NULL,  -- Denormalized for performance
  
  -- Ranking data
  efficiency_score DECIMAL(6,4) NOT NULL,
  mobile_score INT NULL,
  desktop_score INT NULL,
  plugin_count INT NULL,
  global_rank INT NULL,
  rank_position INT NULL,
  
  -- Timestamps
  computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- Foreign key and indexes
  UNIQUE KEY uq_ranks_site (site_id),
  INDEX idx_ranks_global (global_rank),
  INDEX idx_ranks_efficiency (efficiency_score DESC),
  INDEX idx_rank_position (rank_position),
  INDEX idx_ranks_domain (domain),
  
  CONSTRAINT fk_ranks_site 
    FOREIGN KEY (site_id) REFERENCES sites(id) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- SITE METRICS TABLE
-- ========================================
-- Historical performance metrics for each crawl
CREATE TABLE IF NOT EXISTS site_metrics (
  id CHAR(36) NOT NULL PRIMARY KEY,
  site_id CHAR(36) NOT NULL,
  crawl_id CHAR(36) NOT NULL,
  
  -- PageSpeed Insights metrics
  psi_score INT DEFAULT NULL,
  lcp_ms INT DEFAULT NULL,
  cls DECIMAL(6,4) DEFAULT NULL,
  tbt_ms INT DEFAULT NULL,
  
  -- Plugin estimation
  plugin_est_count INT DEFAULT NULL,
  theme_name VARCHAR(255) DEFAULT NULL,
  evidence JSON DEFAULT NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  UNIQUE KEY uq_site_metrics_site_crawl (site_id, crawl_id),
  INDEX idx_site_metrics_site_created (site_id, created_at DESC),
  INDEX idx_site_metrics_psi (psi_score),
  INDEX idx_site_metrics_plugins (plugin_est_count),
  
  CONSTRAINT fk_site_metrics_site 
    FOREIGN KEY (site_id) REFERENCES sites(id) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- SUBMISSIONS TABLE
-- ========================================
-- User-submitted sites tracking
CREATE TABLE IF NOT EXISTS submissions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  status ENUM('queued','processing','done','rejected','error') DEFAULT 'queued',
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ip_hash VARCHAR(128) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  INDEX idx_submissions_status (status),
  INDEX idx_submissions_domain (domain),
  INDEX idx_submissions_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- DISCOVERY LOG TABLE
-- ========================================
-- Track automated site discovery sources
CREATE TABLE IF NOT EXISTS discovery_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  source VARCHAR(100) NOT NULL,
  discovered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  UNIQUE KEY unique_domain_source (domain, source),
  INDEX idx_discovered_at (discovered_at),
  INDEX idx_source (source),
  INDEX idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- DISCOVERY STATS TABLE
-- ========================================
-- Daily discovery performance metrics
CREATE TABLE IF NOT EXISTS discovery_stats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,
  discovered_count INT DEFAULT 0,
  queued_count INT DEFAULT 0,
  skipped_count INT DEFAULT 0,
  error_count INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  UNIQUE KEY uq_discovery_stats_date (date),
  INDEX idx_discovery_stats_date (date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- DEMO DATA INSERTION
-- ========================================
-- Insert sample data for development/testing
INSERT IGNORE INTO sites (id, domain, is_wordpress, theme_name, mobile_score, desktop_score, plugin_count, status, created_at) VALUES
('550e8400-e29b-41d4-a716-446655440001', 'example.com', 1, 'twentytwentythree', 85, 92, 8, 'active', NOW()),
('550e8400-e29b-41d4-a716-446655440002', 'demo.wordpress.org', 1, 'twentytwentytwo', 78, 88, 12, 'active', NOW()),
('550e8400-e29b-41d4-a716-446655440003', 'sample.blog', 1, 'astra', 72, 85, 15, 'active', NOW()),
('550e8400-e29b-41d4-a716-446655440004', 'test.site', 1, 'generatepress', 88, 90, 10, 'active', NOW()),
('550e8400-e29b-41d4-a716-446655440005', 'blog.example', 1, 'kadence', 75, 87, 14, 'active', NOW());

INSERT IGNORE INTO ranks (id, site_id, domain, efficiency_score, mobile_score, desktop_score, plugin_count, global_rank, rank_position) VALUES
('650e8400-e29b-41d4-a716-446655440001', '550e8400-e29b-41d4-a716-446655440001', 'example.com', 0.8750, 85, 92, 8, 1, 1),
('650e8400-e29b-41d4-a716-446655440002', '550e8400-e29b-41d4-a716-446655440004', 'test.site', 0.8500, 88, 90, 10, 2, 2),
('650e8400-e29b-41d4-a716-446655440003', '550e8400-e29b-41d4-a716-446655440002', 'demo.wordpress.org', 0.7800, 78, 88, 12, 3, 3),
('650e8400-e29b-41d4-a716-446655440004', '550e8400-e29b-41d4-a716-446655440005', 'blog.example', 0.7500, 75, 87, 14, 4, 4),
('650e8400-e29b-41d4-a716-446655440005', '550e8400-e29b-41d4-a716-446655440003', 'sample.blog', 0.7200, 72, 85, 15, 5, 5);

-- Add sample queue items for testing
INSERT IGNORE INTO crawl_queue (id, domain, priority, status, created_at, updated_at) VALUES
('750e8400-e29b-41d4-a716-446655440001', 'new-site.example.com', 5, 'pending', NOW(), NOW()),
('750e8400-e29b-41d4-a716-446655440002', 'another-blog.org', 3, 'pending', NOW(), NOW());

-- ========================================
-- FINAL VALIDATION
-- ========================================
-- Show table structures for verification
SHOW CREATE TABLE sites;
SHOW CREATE TABLE crawl_queue; 
SHOW CREATE TABLE ranks;
SHOW CREATE TABLE discovery_log;