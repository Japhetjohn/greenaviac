<?php
/**
 * Green AVIC Content Studio — Automated Database Installer & Migration Assistant
 * 
 * Safely inspects, initializes, and migrates the MariaDB database for Green AVIC:
 * - Detects existing tables, columns, indexes, and schema versions.
 * - Runs only necessary pending migrations automatically without duplicate operations.
 * - Preserves all existing administrator accounts, passwords, and permissions.
 * - Preserves all existing articles, drafts, and publication data.
 * - Migrates subscribers to direct single opt-in (activating any legacy pending records).
 * - Creates missing campaign broadcast and delivery queue tables.
 * - Enforces strong installer security: requires an active admin session or private SETUP_TOKEN.
 * - Permanently self-locks upon successful installation to prevent unauthorized re-execution.
 */

require_once dirname(__DIR__) . '/cms-core/common.php';

start_secure_session();

$lockFile = dirname(__DIR__) . '/cms-core/setup.lock';
$isLocked = file_exists($lockFile);

$pdo = null;
$dbError = null;
try {
    $pdo = get_db();
} catch (Exception $e) {
    $dbError = "Unable to connect to the database. Please verify your MariaDB credentials in cms-core/.env.";
}

// ----------------------------------------------------------
// 1. Authentication & Security Check
// ----------------------------------------------------------
$currentUser = null;
if ($pdo) {
    try {
        $currentUser = get_current_user_data($pdo);
    } catch (Exception $e) {
        $currentUser = null;
    }
}

$isAdminLoggedIn = ($currentUser !== null);
$configuredToken = defined('SETUP_TOKEN') ? SETUP_TOKEN : 'greenavic_setup_9a8b7c6d5e4f';

// Check token from POST, GET, or existing authorized session
$tokenProvided = $_POST['setup_token'] ?? $_GET['token'] ?? ($_SESSION['setup_authorized_token'] ?? null);
$isTokenAuthorized = false;

if ($tokenProvided && hash_equals($configuredToken, (string)$tokenProvided)) {
    $isTokenAuthorized = true;
    $_SESSION['setup_authorized_token'] = $configuredToken;
}

$isAuthorized = $isAdminLoggedIn || $isTokenAuthorized;

// Handle manual re-run request by authenticated admin
$allowRerun = false;
if (isset($_GET['action']) && $_GET['action'] === 'rerun' && $isAuthorized) {
    $allowRerun = true;
}

// ----------------------------------------------------------
// 2. Migration & Installation Logic
// ----------------------------------------------------------
$migrationSteps = [];
$migrationSuccess = false;
$migrationError = null;
$adminCount = 0;
$newAdminCreated = false;
$clientInviteUrl = null;

if ($isAuthorized && $pdo && (!$isLocked || $allowRerun)) {
    try {
        // Step 1: Users table (Administrator accounts)
        $usersExist = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
        if (!$usersExist) {
            $pdo->exec("
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
            ");
            $migrationSteps[] = ['type' => 'success', 'msg' => 'Created administrator users table.'];
        } else {
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
            $migrationSteps[] = ['type' => 'info', 'msg' => "Preserved {$adminCount} existing administrator account(s). Logins and passwords remain untouched."];
        }

        // Step 2: Articles table (Current Live Publication)
        $articlesExist = $pdo->query("SHOW TABLES LIKE 'articles'")->rowCount() > 0;
        if (!$articlesExist) {
            $pdo->exec("
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
            ");
            $migrationSteps[] = ['type' => 'success', 'msg' => 'Created public articles publication table.'];
        } else {
            $artCount = (int)$pdo->query("SELECT COUNT(*) FROM `articles`")->fetchColumn();
            $migrationSteps[] = ['type' => 'info', 'msg' => "Verified live articles table ({$artCount} article(s) present)."];
        }

        // Step 3: Drafts table (Unpublished Working Draft)
        $draftsExist = $pdo->query("SHOW TABLES LIKE 'drafts'")->rowCount() > 0;
        if (!$draftsExist) {
            $pdo->exec("
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
            ");
            $migrationSteps[] = ['type' => 'success', 'msg' => 'Created editorial working draft workbench.'];
        } else {
            $migrationSteps[] = ['type' => 'info', 'msg' => 'Verified editorial draft workbench.'];
        }

        // Step 4: Login Attempts (Brute-force protection)
        $loginAttemptsExist = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->rowCount() > 0;
        if (!$loginAttemptsExist) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `login_attempts` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `ip_address` VARCHAR(45) NOT NULL,
                  `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
                  `last_attempt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uk_ip_address` (`ip_address`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $migrationSteps[] = ['type' => 'success', 'msg' => 'Created rate-limiting security table.'];
        } else {
            $migrationSteps[] = ['type' => 'info', 'msg' => 'Verified login security & rate-limiting table.'];
        }

        // Step 5: Subscribers table (Direct Single Opt-In Audience)
        $subscribersExist = $pdo->query("SHOW TABLES LIKE 'subscribers'")->rowCount() > 0;
        if (!$subscribersExist) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `subscribers` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `email` VARCHAR(191) NOT NULL,
                  `status` ENUM('pending', 'active', 'unsubscribed') NOT NULL DEFAULT 'active',
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
            ");
            $migrationSteps[] = ['type' => 'success', 'msg' => 'Created subscriber audience table with direct single opt-in.'];
        } else {
            // Verify column migrations
            $colConfirmed = $pdo->query("SHOW COLUMNS FROM `subscribers` LIKE 'confirmed_at'")->rowCount() > 0;
            if (!$colConfirmed) {
                $pdo->exec("ALTER TABLE `subscribers` ADD COLUMN `confirmed_at` DATETIME DEFAULT NULL AFTER `created_at`");
                $migrationSteps[] = ['type' => 'success', 'msg' => 'Added missing confirmed_at column to subscribers table.'];
            }
            $colUnsub = $pdo->query("SHOW COLUMNS FROM `subscribers` LIKE 'unsubscribed_at'")->rowCount() > 0;
            if (!$colUnsub) {
                $pdo->exec("ALTER TABLE `subscribers` ADD COLUMN `unsubscribed_at` DATETIME DEFAULT NULL AFTER `confirmed_at`");
                $migrationSteps[] = ['type' => 'success', 'msg' => 'Added missing unsubscribed_at column to subscribers table.'];
            }

            // Migrate any legacy 'pending' subscribers to 'active'
            $migrated = $pdo->exec("UPDATE `subscribers` SET `status` = 'active' WHERE `status` = 'pending'");
            $subCount = (int)$pdo->query("SELECT COUNT(*) FROM `subscribers` WHERE `status` = 'active'")->fetchColumn();
            
            if ($migrated > 0) {
                $migrationSteps[] = ['type' => 'success', 'msg' => "Migrated {$migrated} legacy pending subscriber(s) to confirmed active status."];
            }
            $migrationSteps[] = ['type' => 'info', 'msg' => "Direct single opt-in audience verified ({$subCount} active subscribers ready for broadcasts)."];
        }

        // Step 6: Newsletter Campaigns table
        $campExist = $pdo->query("SHOW TABLES LIKE 'newsletter_campaigns'")->rowCount() > 0;
        if (!$campExist) {
            $pdo->exec("
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
            ");
            $migrationSteps[] = ['type' => 'success', 'msg' => 'Created newsletter campaign broadcast history table.'];
        } else {
            $campCount = (int)$pdo->query("SELECT COUNT(*) FROM `newsletter_campaigns`")->fetchColumn();
            $migrationSteps[] = ['type' => 'info', 'msg' => "Verified campaign broadcast history ({$campCount} campaign(s) tracked)."];
        }

        // Step 7: Newsletter Deliveries queue table
        $delExist = $pdo->query("SHOW TABLES LIKE 'newsletter_deliveries'")->rowCount() > 0;
        if (!$delExist) {
            $pdo->exec("
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
            ");
            $migrationSteps[] = ['type' => 'success', 'msg' => 'Created delivery queue table with Hostinger 24h quota enforcement.'];
        } else {
            $colAttempts = $pdo->query("SHOW COLUMNS FROM `newsletter_deliveries` LIKE 'attempts'")->rowCount() > 0;
            if (!$colAttempts) {
                $pdo->exec("ALTER TABLE `newsletter_deliveries` ADD COLUMN `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`");
                $migrationSteps[] = ['type' => 'success', 'msg' => 'Added attempts column to newsletter deliveries queue table.'];
            }
            $migrationSteps[] = ['type' => 'info', 'msg' => 'Verified delivery queue & Hostinger 24h quota engine.'];
        }

        // Step 8: System Settings table & defaults
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
        
        $pdo->exec("INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('schema_version', '2.0') ON DUPLICATE KEY UPDATE setting_value = '2.0'");
        $pdo->exec("INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('installer_locked', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        $pdo->exec("INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('installed_at', '" . date('Y-m-d H:i:s') . "') ON DUPLICATE KEY UPDATE setting_value = '" . date('Y-m-d H:i:s') . "'");
        
        $migrationSteps[] = ['type' => 'success', 'msg' => 'Registered schema version 2.0 and automated background cron credentials.'];

        // Write permanent lockfile
        file_put_contents($lockFile, "Installed at: " . date('c') . "\nSchema: 2.0 (Direct Single Opt-In & Delivery Queue)\n");
        $isLocked = true;
        $migrationSuccess = true;

    } catch (Exception $e) {
        $migrationError = "Database migration error: " . $e->getMessage();
    }
}

// ----------------------------------------------------------
// 3. Handle Initial Admin Account Creation (if 0 admins exist)
// ----------------------------------------------------------
if ($isAuthorized && $pdo && $adminCount === 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_initial_admin') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $migrationError = "Please enter a valid administrator email address.";
    } elseif (empty($password) || empty($confirm)) {
        $migrationError = "Please enter and confirm your password.";
    } elseif ($password !== $confirm) {
        $migrationError = "Passwords do not match. Please re-enter.";
    } elseif (strlen($password) < 8) {
        $migrationError = "Password must be at least 8 characters long.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO users (email, password_hash, must_change_password, created_at, updated_at)
                VALUES (?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([$email, $hash]);
            $adminCount = 1;
            $newAdminCreated = true;

            // Generate ready-to-use client onboarding invite token
            $token = create_invite_token($email, 7);
            $clientInviteUrl = ADMIN_URL . '/invite?token=' . $token;
        } catch (Exception $e) {
            $migrationError = "Unable to create administrator account: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup &amp; Migration Assistant | Green AVIC Content Studio</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" href="assets/images/brand/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #4CAF50;
            --green-dark: #0D3D2F;
            --green-hover: #43A047;
            --bg-page: #F4F8F4;
            --text-dark: #222;
            --text-muted: #555;
            --border: #DDE5DD;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #F4F8F4 0%, #E8F2E8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: var(--text-dark);
        }
        .setup-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 16px 40px rgba(13,61,47,0.08);
            border: 1px solid var(--border);
            width: 100%;
            max-width: 580px;
            padding: 40px 36px;
            position: relative;
        }
        .logo-wrap {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo-wrap img {
            height: 48px;
            width: auto;
            object-fit: contain;
        }
        .badge-header {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #E8F5E9;
            color: #2E7D32;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        h1 {
            font-size: 22px;
            color: var(--green-dark);
            margin-bottom: 6px;
            text-align: center;
            font-weight: 700;
        }
        p.subtitle {
            font-size: 13.5px;
            color: var(--text-muted);
            margin-bottom: 24px;
            text-align: center;
            line-height: 1.5;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            font-size: 13.5px;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .alert-error {
            background: #FFEBEE;
            color: #C62828;
            border: 1px solid #FFCDD2;
        }
        .alert-warning {
            background: #FFF8E1;
            color: #E65100;
            border: 1px solid #FFE082;
        }
        .alert-success {
            background: #E8F5E9;
            color: #2E7D32;
            border: 1px solid #C8E6C9;
        }
        .checklist-box {
            background: #F8FAF8;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 24px;
        }
        .checklist-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--green-dark);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .checklist-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: #333;
            margin-bottom: 9px;
            line-height: 1.45;
        }
        .checklist-item:last-child {
            margin-bottom: 0;
        }
        .checklist-icon-success {
            color: #2E7D32;
            font-weight: 700;
            flex-shrink: 0;
        }
        .checklist-icon-info {
            color: #0288D1;
            font-weight: 700;
            flex-shrink: 0;
        }
        .form-group {
            margin-bottom: 18px;
            text-align: left;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
        }
        input[type="email"], input[type="password"], input[type="text"] {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        input:focus {
            border-color: var(--green);
        }
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: var(--green);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 8px;
        }
        .btn-submit:hover {
            background: var(--green-hover);
        }
        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }
        .btn-action {
            flex: 1;
            display: inline-block;
            text-align: center;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-action-primary {
            background: var(--green);
            color: #fff;
        }
        .btn-action-primary:hover {
            background: var(--green-hover);
        }
        .btn-action-outline {
            background: #fff;
            color: var(--green-dark);
            border: 1.5px solid var(--green-dark);
        }
        .btn-action-outline:hover {
            background: #F4FBF4;
        }
        .invite-box {
            margin-top: 20px;
            padding: 16px;
            background: #F4FBF4;
            border: 1.5px dashed #A5D6A7;
            border-radius: 10px;
            text-align: left;
        }
        .invite-box h3 {
            font-size: 14px;
            color: var(--green-dark);
            margin-bottom: 6px;
        }
        .invite-box p {
            font-size: 12.5px;
            color: #555;
            margin-bottom: 10px;
            line-height: 1.4;
        }
        .invite-input-row {
            display: flex;
            gap: 8px;
        }
        .invite-input-row input {
            font-size: 12px;
            background: #fff;
        }
        .btn-copy {
            padding: 9px 14px;
            background: var(--green-dark);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
    </style>
</head>
<body>

<div class="setup-card">
    <div class="logo-wrap">
        <img src="assets/images/brand/greenavic-logo-main.png" alt="Green AVIC" onerror="this.src='assets/images/brand/greenavic-logo.png'">
    </div>

    <!-- Error State: Database Connection Failure -->
    <?php if ($dbError): ?>
        <div style="text-align: center;">
            <div class="badge-header" style="background: #FFEBEE; color: #C62828;">Connection Required</div>
            <h1>Database Offline</h1>
            <p class="subtitle">Green AVIC Content Studio could not establish a connection to MariaDB.</p>
            <div class="alert alert-error">
                <strong>Connection Error:</strong><br>
                <?= htmlspecialchars($dbError) ?><br><br>
                Please verify that <code>DB_NAME</code>, <code>DB_USER</code>, and <code>DB_PASS</code> in <code>cms-core/.env</code> match your Hostinger database credentials.
            </div>
            <a href="setup" class="btn-action btn-action-outline" style="display: block;">Retry Connection &rarr;</a>
        </div>

    <!-- Security Gate: Token Required if Not Authenticated -->
    <?php elseif (!$isAuthorized): ?>
        <div style="text-align: center;">
            <div class="badge-header" style="background: #E3F2FD; color: #1565C0;">Security Verification</div>
            <h1>Installer Security Gate</h1>
            <p class="subtitle">Please provide your private Setup Token from <code>cms-core/.env</code> to run database migrations.</p>

            <?php if (isset($_POST['setup_token'])): ?>
                <div class="alert alert-error">Invalid setup token. Please check <code>SETUP_TOKEN</code> in your server's <code>cms-core/.env</code> file.</div>
            <?php endif; ?>

            <form method="POST" action="setup">
                <div class="form-group">
                    <label for="setup_token">Installer Security Token</label>
                    <input type="password" id="setup_token" name="setup_token" required placeholder="Enter SETUP_TOKEN from cms-core/.env" autofocus autocomplete="off">
                </div>
                <button type="submit" class="btn-submit">Authenticate &amp; Run Setup &rarr;</button>
            </form>

            <div style="margin-top: 20px; font-size: 13px; color: var(--text-muted);">
                Already configured an administrator account? <a href="login" style="color: var(--green); font-weight: 600; text-decoration: none;">Sign In directly &rarr;</a>
            </div>
        </div>

    <!-- Success State: Migration Complete and Admin Exists -->
    <?php elseif ($migrationSuccess && $adminCount > 0): ?>
        <div style="text-align: center;">
            <div class="badge-header">Setup &amp; Migration Complete</div>
            <h1>Database Up to Date</h1>
            <p class="subtitle">Green AVIC schema 2.0 and direct newsletter subscription are fully initialized.</p>

            <div class="checklist-box">
                <div class="checklist-title">Verification &amp; Migration Summary</div>
                <?php foreach ($migrationSteps as $step): ?>
                    <div class="checklist-item">
                        <span class="<?= $step['type'] === 'success' ? 'checklist-icon-success' : 'checklist-icon-info' ?>">
                            <?= $step['type'] === 'success' ? '✓' : 'ℹ' ?>
                        </span>
                        <span><?= htmlspecialchars($step['msg']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="alert alert-success" style="text-align: left;">
                <strong>Security Lock Activated:</strong><br>
                The installer has created <code>cms-core/setup.lock</code> and locked itself. All administrator accounts and passwords remain safe and intact.
            </div>

            <div class="btn-row">
                <a href="login" class="btn-action btn-action-primary">Launch Content Studio &rarr;</a>
                <a href="<?= NEWSLETTER_URL ?>" target="_blank" class="btn-action btn-action-outline">View Newsletter</a>
            </div>
        </div>

    <!-- Setup State: Migrations Complete, but 0 Admins exist (Fresh installation) -->
    <?php elseif ($migrationSuccess && $adminCount === 0 && !$newAdminCreated): ?>
        <div style="text-align: center;">
            <div class="badge-header">Step 2: Create Administrator</div>
            <h1>Create Initial Administrator</h1>
            <p class="subtitle">Database tables created successfully. Now set up your initial Content Studio login.</p>

            <?php if ($migrationError): ?>
                <div class="alert alert-error"><?= htmlspecialchars($migrationError) ?></div>
            <?php endif; ?>

            <form method="POST" action="setup">
                <input type="hidden" name="action" value="create_initial_admin">
                <input type="hidden" name="setup_token" value="<?= htmlspecialchars($configuredToken) ?>">

                <div class="form-group">
                    <label for="email">Administrator Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? 'japhetjohnk@gmail.com') ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">Initial Administrator Password</label>
                    <input type="password" id="password" name="password" required minlength="8" placeholder="At least 8 characters">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Repeat your password">
                </div>

                <button type="submit" class="btn-submit">Save Administrator &amp; Finish &rarr;</button>
            </form>
        </div>

    <!-- Success State: Fresh Initial Admin Account Created -->
    <?php elseif ($newAdminCreated): ?>
        <div style="text-align: center;">
            <div class="badge-header">Setup Complete</div>
            <h1>Administrator Configured</h1>
            <p class="subtitle">Your administrator account (<code><?= htmlspecialchars($email) ?></code>) is active in MariaDB.</p>

            <?php if ($clientInviteUrl): ?>
                <div class="invite-box">
                    <h3>👥 Onboard Your Client / Co-Admin</h3>
                    <p>Send this private link to your client so she can set her own email and choose her own private password:</p>
                    <div class="invite-input-row">
                        <input type="text" id="inviteUrlInput" value="<?= htmlspecialchars($clientInviteUrl) ?>" readonly>
                        <button type="button" class="btn-copy" id="copyBtn" onclick="copyInvite()">Copy Link</button>
                    </div>
                </div>
                <script>
                    function copyInvite() {
                        const input = document.getElementById('inviteUrlInput');
                        input.select();
                        navigator.clipboard.writeText(input.value).then(() => {
                            const btn = document.getElementById('copyBtn');
                            btn.textContent = 'Copied!';
                            btn.style.background = '#4CAF50';
                            setTimeout(() => {
                                btn.textContent = 'Copy Link';
                                btn.style.background = '';
                            }, 2500);
                        });
                    }
                </script>
            <?php endif; ?>

            <div class="btn-row" style="margin-top: 20px;">
                <a href="login" class="btn-action btn-action-primary">Proceed to Admin Login &rarr;</a>
                <a href="<?= NEWSLETTER_URL ?>" target="_blank" class="btn-action btn-action-outline">View Newsletter</a>
            </div>
        </div>

    <!-- Locked State: Already Installed -->
    <?php elseif ($isLocked && !$allowRerun): ?>
        <div style="text-align: center;">
            <div class="badge-header" style="background: #E8F5E9; color: #2E7D32;">System Healthy</div>
            <h1>Installer Locked</h1>
            <p class="subtitle">The Green AVIC database is fully initialized and operational.</p>

            <div class="alert alert-success" style="text-align: left;">
                <strong>Database Ready:</strong><br>
                All 7 CMS and newsletter tables are active. To make changes or publish articles, sign in to the Content Studio dashboard.
            </div>

            <div class="btn-row">
                <a href="login" class="btn-action btn-action-primary">Sign In to Dashboard &rarr;</a>
                <a href="<?= NEWSLETTER_URL ?>" target="_blank" class="btn-action btn-action-outline">Public Website</a>
            </div>

            <?php if ($isAdminLoggedIn): ?>
                <div style="margin-top: 20px; font-size: 12px; color: var(--text-muted);">
                    Logged in as admin? <a href="setup?action=rerun" style="color: var(--green); text-decoration: underline;">Re-verify schema integrity</a>
                </div>
            <?php endif; ?>
        </div>

    <!-- Error State -->
    <?php elseif ($migrationError): ?>
        <div style="text-align: center;">
            <div class="badge-header" style="background: #FFEBEE; color: #C62828;">Migration Error</div>
            <h1>Setup Interrupted</h1>
            <div class="alert alert-error"><?= htmlspecialchars($migrationError) ?></div>
            <a href="setup" class="btn-action btn-action-outline" style="display: block;">Try Again &rarr;</a>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
