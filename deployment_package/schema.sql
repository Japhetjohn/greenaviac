-- ==========================================================
-- Green AVIC News & Insights CMS — MariaDB Database Schema
-- Compatible with Hostinger MariaDB & MySQL 8.x
-- ==========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- 1. Table: users (Administrator accounts)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 2. Table: articles (Current Live Publication)
-- Note: Green AVIC uses a single live publication architecture.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `articles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `excerpt` TEXT NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'Sustainability & Eco-Impact',
  `author` VARCHAR(100) NOT NULL DEFAULT 'Green AVIC',
  `cover_image` VARCHAR(500) DEFAULT NULL,
  `cover_alt` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('draft', 'published') NOT NULL DEFAULT 'published',
  `reading_time` INT UNSIGNED NOT NULL DEFAULT 3,
  `published_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_articles_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3. Table: drafts (Unpublished Working Draft)
-- Only one draft is stored at a time. Never exposed to public.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `drafts` (
  `id` INT UNSIGNED NOT NULL DEFAULT 1,
  `title` VARCHAR(255) DEFAULT NULL,
  `slug` VARCHAR(255) DEFAULT NULL,
  `excerpt` TEXT DEFAULT NULL,
  `body` MEDIUMTEXT DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT 'Sustainability & Eco-Impact',
  `author` VARCHAR(100) DEFAULT 'Green AVIC',
  `cover_image` VARCHAR(500) DEFAULT NULL,
  `cover_alt` VARCHAR(255) DEFAULT NULL,
  `reading_time` INT UNSIGNED DEFAULT 3,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. Table: login_attempts (Brute-force protection)
-- Automatically blocks IPs after 5 failed attempts for 15 mins.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_attempt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
