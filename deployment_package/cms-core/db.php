<?php
/**
 * Green AVIC CMS — MariaDB Database Connection & Helper
 */

defined('GREENAVIC_CORE') or define('GREENAVIC_CORE', true);
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (DB_PASS === 'YOUR_DATABASE_PASSWORD_HERE') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Database configuration required. Please update DB_NAME, DB_USER, and DB_PASS in cms-core/config.php.'
        ]);
        exit;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Unable to connect to database. Please verify MariaDB credentials in cms-core/config.php.'
        ]);
        exit;
    }
}

/**
 * Helper to ensure database tables are created if schema.sql was not imported via phpMyAdmin yet.
 */
function ensure_tables_exist(PDO $pdo): bool {
    try {
        // 1. Check if base users table exists
        $checkUsers = $pdo->query("SHOW TABLES LIKE 'users'");
        if ($checkUsers->rowCount() === 0) {
            $schemaFile = __DIR__ . '/../schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
                return true;
            }
        }

        // 2. Check and migrate newsletter subscription tables if upgrading an existing installation
        $checkSubscribers = $pdo->query("SHOW TABLES LIKE 'subscribers'");
        if ($checkSubscribers->rowCount() === 0) {
            $pdo->exec("
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

                CREATE TABLE IF NOT EXISTS `system_settings` (
                  `setting_key` VARCHAR(64) NOT NULL,
                  `setting_value` TEXT DEFAULT NULL,
                  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) 
                VALUES ('cron_secret', 'greenavic_cron_sec_8f7b2a9e3d4c1');
            ");
        } else {
            // Table exists: verify column migrations
            try {
                // Ensure status enum includes 'pending'
                $pdo->exec("ALTER TABLE `subscribers` MODIFY COLUMN `status` ENUM('pending', 'active', 'unsubscribed') NOT NULL DEFAULT 'pending'");
                
                // Ensure confirmed_at column exists
                $colCheck = $pdo->query("SHOW COLUMNS FROM `subscribers` LIKE 'confirmed_at'");
                if ($colCheck->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `subscribers` ADD COLUMN `confirmed_at` DATETIME DEFAULT NULL AFTER `created_at`");
                }

                // Ensure system_settings table exists
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `system_settings` (
                      `setting_key` VARCHAR(64) NOT NULL,
                      `setting_value` TEXT DEFAULT NULL,
                      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                      PRIMARY KEY (`setting_key`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                    INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) 
                    VALUES ('cron_secret', 'greenavic_cron_sec_8f7b2a9e3d4c1');
                ");

                // Ensure newsletter_deliveries has attempts column and pending status
                $delCheck = $pdo->query("SHOW COLUMNS FROM `newsletter_deliveries` LIKE 'attempts'");
                if ($delCheck->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `newsletter_deliveries` ADD COLUMN `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`");
                    $pdo->exec("ALTER TABLE `newsletter_deliveries` MODIFY COLUMN `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending'");
                }
            } catch (Exception $alterEx) {
                // Non-fatal if already up to date
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Schema initialization error: " . $e->getMessage());
        return false;
    }
}

