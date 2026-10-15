-- ==========================================================
-- Green AVIC News & Insights CMS — MariaDB Database Migration
-- Adds Email Newsletter Subscription, Double Opt-In & Delivery Queue
-- ==========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- 1. Table: subscribers (Double Opt-In Audience)
-- Statuses: 'pending' (unconfirmed), 'active' (confirmed), 'unsubscribed'
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscribers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) NOT NULL,
  `status` ENUM('pending', 'active', 'unsubscribed') NOT NULL DEFAULT 'pending',
  `token` VARCHAR(64) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` DATETIME DEFAULT NULL,
  `unsubscribed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_subscribers_email` (`email`),
  KEY `idx_subscribers_status` (`status`),
  KEY `idx_subscribers_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If table already existed without 'pending' or 'confirmed_at', ensure columns exist
ALTER TABLE `subscribers` 
  MODIFY COLUMN `status` ENUM('pending', 'active', 'unsubscribed') NOT NULL DEFAULT 'pending';

-- ----------------------------------------------------------
-- 2. Table: newsletter_campaigns (Broadcast Campaigns)
-- Tracks broadcast status, recipient counts, and delivery progress
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `newsletter_campaigns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` INT UNSIGNED NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `status` ENUM('pending', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  `total_subscribers` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `pending_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaigns_article` (`article_id`),
  KEY `idx_campaigns_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3. Table: newsletter_deliveries (Per-Subscriber Queue & Logs)
-- Guarantees duplicate-send prevention and tracks attempts & 24h quotas
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `newsletter_deliveries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NOT NULL,
  `subscriber_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_campaign_subscriber` (`campaign_id`, `subscriber_id`),
  KEY `idx_deliveries_campaign` (`campaign_id`),
  KEY `idx_deliveries_status` (`status`),
  KEY `idx_deliveries_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. Table: system_settings (Key-Value Store for Cron & Limits)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default cron secret if not exists
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) 
VALUES ('cron_secret', 'greenavic_cron_sec_8f7b2a9e3d4c1');

SET FOREIGN_KEY_CHECKS = 1;
