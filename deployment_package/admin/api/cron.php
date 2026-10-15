<?php
/**
 * Green AVIC CMS — Background Newsletter Cron Task
 * 
 * Executes scheduled batch delivery runs, respecting Hostinger's 100/24h quota.
 * Compatible with Hostinger hPanel Cron Jobs (CLI or HTTP GET).
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';
require_once dirname(__DIR__, 2) . '/cms-core/newsletter_service.php';

$pdo = get_db();
ensure_tables_exist($pdo);

// 1. Verify Secret Key (CLI arguments or GET parameter)
$providedSecret = '';

if (PHP_SAPI === 'cli') {
    // Parse CLI arguments: php cron.php secret=...
    global $argv;
    if (isset($argv)) {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, 'secret=')) {
                $providedSecret = substr($arg, 7);
                break;
            }
        }
    }
} else {
    $providedSecret = $_GET['secret'] ?? ($_POST['secret'] ?? '');
}

// Fetch configured cron secret from database or env
$dbSecretStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'cron_secret' LIMIT 1");
$dbSecretStmt->execute();
$validSecret = $dbSecretStmt->fetchColumn() ?: 'greenavic_cron_sec_8f7b2a9e3d4c1';

if (empty($providedSecret) || !hash_equals((string)$validSecret, (string)$providedSecret)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized cron request. Invalid or missing secret key.']);
    exit;
}

// 2. Record Last Cron Execution
$now = date('Y-m-d H:i:s');
$setStmt = $pdo->prepare("
    INSERT INTO system_settings (setting_key, setting_value, updated_at) 
    VALUES ('last_cron_run', ?, ?)
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)
");
$setStmt->execute([$now, $now]);

// 3. Process Next Batch From Queue
$result = process_campaign_queue($pdo, 15);

// 4. Output Execution Diagnostics
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success'         => true,
    'timestamp'       => $now,
    'rolling_24h_used'=> get_rolling_24h_sent_count($pdo),
    'available_quota' => get_available_smtp_quota($pdo),
    'batch_result'    => $result
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
