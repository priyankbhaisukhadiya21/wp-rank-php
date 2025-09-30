-- WP-Rank MySQL Schema
-- Compatible with MySQL 8.0+

CREATE DATABASE IF NOT EXISTS global_wp_rank
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

USE global_wp_rank;

-- Sites table - stores WordPress site information
CREATE TABLE IF NOT EXISTS sites (
  id CHAR(36) NOT NULL PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  is_wordpress TINYINT(1) DEFAULT NULL,
  theme_name VARCHAR(255) DEFAULT NULL,
  first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_crawl_at TIMESTAMP NULL DEFAULT NULL,
  status ENUM('pending','active','error','blocked') DEFAULT 'pending',
  UNIQUE KEY uq_sites_domain (domain),
  INDEX idx_sites_status (status),
  INDEX idx_sites_is_wordpress (is_wordpress),
  INDEX idx_sites_last_crawl (last_crawl_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Site metrics table - stores performance and plugin metrics for each crawl
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Ranks table - stores computed efficiency scores and global rankings
CREATE TABLE IF NOT EXISTS ranks (
  id CHAR(36) NOT NULL PRIMARY KEY,
  site_id CHAR(36) NOT NULL,
  efficiency_score DECIMAL(6,4) NOT NULL,
  global_rank INT NOT NULL,
  computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  UNIQUE KEY uq_ranks_site (site_id),
  INDEX idx_ranks_global (global_rank),
  INDEX idx_ranks_efficiency (efficiency_score DESC),
  
  CONSTRAINT fk_ranks_site 
    FOREIGN KEY (site_id) REFERENCES sites(id) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Submissions table - stores user-submitted sites for crawling
CREATE TABLE IF NOT EXISTS submissions (
  id CHAR(36) NOT NULL PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  status ENUM('queued','processing','done','rejected','error') DEFAULT 'queued',
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ip_hash VARCHAR(128) DEFAULT NULL,
  
  INDEX idx_submissions_status (status),
  INDEX idx_submissions_domain (domain),
  INDEX idx_submissions_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Crawl queue table - tracks sites that need to be crawled
CREATE TABLE IF NOT EXISTS crawl_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) NOT NULL,
  priority INT DEFAULT 5,
  source VARCHAR(100) DEFAULT NULL,
  status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
  retry_count INT DEFAULT 0,
  next_attempt TIMESTAMP NULL DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  
  INDEX idx_crawl_queue_status (status),
  INDEX idx_crawl_queue_priority (priority DESC),
  INDEX idx_crawl_queue_next_attempt (next_attempt),
  INDEX idx_crawl_queue_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Discovery stats table - tracks daily discovery performance
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;