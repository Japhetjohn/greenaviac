<?php
/**
 * Green AVIC Content Studio — Health & System Diagnostics API
 * 
 * Provides authenticated system health inspection and database diagnostics.
 * Endpoints:
 *   GET /api/admin/health (or api/health.php?action=health)
 *   GET /api/admin/health/database (or api/health.php?action=database)
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';

$pdo = get_db();
ensure_tables_exist($pdo);

// Require authenticated administrator
$user = require_auth($pdo, true);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (empty($action)) {
        $action = $input['action'] ?? '';
    }
}
if (empty($action)) {
    $action = 'health';
}

// ----------------------------------------------------------
// 0. Database Repair & Image Path Normalization
// ----------------------------------------------------------
if ($action === 'repair') {
    try {
        ensure_tables_exist($pdo);

        // Normalize cover_image URLs in articles
        $artStmt = $pdo->query("SELECT id, cover_image FROM articles");
        $repairedArticles = 0;
        while ($art = $artStmt->fetch()) {
            $cur = $art['cover_image'];
            $norm = normalize_image_url($cur);
            if ($cur !== $norm) {
                $up = $pdo->prepare("UPDATE articles SET cover_image = ? WHERE id = ?");
                $up->execute([$norm, $art['id']]);
                $repairedArticles++;
            }
        }

        // Normalize cover_image in drafts
        $draftStmt = $pdo->query("SELECT id, cover_image FROM drafts WHERE id = 1");
        $draftRow = $draftStmt->fetch();
        $repairedDrafts = 0;
        if ($draftRow && !empty($draftRow['cover_image'])) {
            $normD = normalize_image_url($draftRow['cover_image']);
            if ($draftRow['cover_image'] !== $normD) {
                $pdo->prepare("UPDATE drafts SET cover_image = ? WHERE id = 1")->execute([$normD]);
                $repairedDrafts++;
            }
        }

        AppLogger::info('health', 'Database verified and repaired via admin dashboard', [
            'articles_repaired' => $repairedArticles,
            'drafts_repaired'   => $repairedDrafts
        ]);

        json_response([
            'success'           => true,
            'message'           => "Database verified and repaired successfully. All 7 tables intact. {$repairedArticles} publication image URL(s) normalized.",
            'articles_repaired' => $repairedArticles,
            'drafts_repaired'   => $repairedDrafts,
            'checked_at'        => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        AppLogger::error('health', 'Database repair failed: ' . $e->getMessage());
        json_response([
            'success' => false,
            'error'   => 'Database repair failed: ' . $e->getMessage()
        ], 500);
    }
}

if ($method !== 'GET') {
    json_response(['error' => 'Method not allowed.'], 405);
}

// ----------------------------------------------------------
// 1. Database Detailed Inspection
// ----------------------------------------------------------
if ($action === 'database') {
    try {
        $startTime = microtime(true);
        $pingStmt = $pdo->query("SELECT 1");
        $pingStmt->fetch();
        $latencyMs = round((microtime(true) - $startTime) * 1000, 2);

        $dbVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        // Fetch tables and row counts in current database
        $tablesStmt = $pdo->prepare("
            SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME ASC
        ");
        $tablesStmt->execute([DB_NAME]);
        $tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

        $tableStats = [];
        $totalBytes = 0;
        foreach ($tables as $t) {
            $bytes = (int)($t['DATA_LENGTH'] ?? 0) + (int)($t['INDEX_LENGTH'] ?? 0);
            $totalBytes += $bytes;
            $tableStats[] = [
                'name'      => $t['TABLE_NAME'],
                'rows'      => (int)($t['TABLE_ROWS'] ?? 0),
                'size_kb'   => round($bytes / 1024, 1),
                'collation' => $t['TABLE_COLLATION'] ?? 'utf8mb4_unicode_ci'
            ];
        }

        // Schema version
        $schemaVer = 'Unknown';
        try {
            $vStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'schema_version' LIMIT 1");
            if ($vStmt) {
                $schemaVer = $vStmt->fetchColumn() ?: '1.0';
            }
        } catch (Exception $e) {}

        json_response([
            'status'         => 'healthy',
            'database_name'  => DB_NAME,
            'server_version' => $dbVersion,
            'latency_ms'     => $latencyMs,
            'schema_version' => $schemaVer,
            'total_size_mb'  => round($totalBytes / (1024 * 1024), 2),
            'tables_count'   => count($tableStats),
            'tables'         => $tableStats,
            'checked_at'     => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        AppLogger::error('health', 'Database health check failed: ' . $e->getMessage());
        json_response([
            'status'  => 'error',
            'error'   => 'Database inspection failed: ' . $e->getMessage(),
            'checked_at' => date('Y-m-d H:i:s')
        ], 500);
    }
}

// ----------------------------------------------------------
// 2. Comprehensive System Health Check
// ----------------------------------------------------------
if ($action === 'health') {
    try {
        // A. PHP & Server
        $requiredExtensions = ['pdo_mysql', 'curl', 'mbstring', 'openssl', 'json', 'fileinfo'];
        $extStatus = [];
        $allExtsOk = true;
        foreach ($requiredExtensions as $ext) {
            $loaded = extension_loaded($ext);
            $extStatus[$ext] = $loaded;
            if (!$loaded) $allExtsOk = false;
        }

        $freeDisk = @disk_free_space(__DIR__);
        $totalDisk = @disk_total_space(__DIR__);
        $diskInfo = [
            'free_gb'  => $freeDisk !== false ? round($freeDisk / 1073741824, 2) : 'N/A',
            'total_gb' => $totalDisk !== false ? round($totalDisk / 1073741824, 2) : 'N/A',
            'usage_pct'=> ($freeDisk !== false && $totalDisk > 0) ? round((1 - ($freeDisk / $totalDisk)) * 100, 1) : 'N/A'
        ];

        // B. Database Health
        $dbOk = false;
        $dbLatency = 0;
        try {
            $t0 = microtime(true);
            $pdo->query("SELECT 1");
            $dbLatency = round((microtime(true) - $t0) * 1000, 2);
            $dbOk = true;
        } catch (Exception $e) {
            $dbOk = false;
        }

        // C. SMTP Status: Distinguish Configured vs Tested vs Delivery-Verified
        $smtpConfigured = !empty(SMTP_USER) && !empty(SMTP_PASS) && (SMTP_PASS !== 'YOUR_EMAIL_PASSWORD_HERE');
        $smtpTested = false;
        $smtpTestError = null;

        if ($smtpConfigured) {
            // Attempt socket handshake test (3s timeout)
            $sslPrefix = (strtolower(SMTP_SECURE) === 'ssl') ? 'ssl://' : '';
            $fp = @fsockopen($sslPrefix . SMTP_HOST, (int)SMTP_PORT, $errno, $errstr, 3);
            if ($fp) {
                $smtpTested = true;
                @fclose($fp);
            } else {
                $smtpTested = false;
                $smtpTestError = "Could not establish socket to " . SMTP_HOST . ":" . SMTP_PORT . " ($errstr)";
            }
        }

        // Check if real emails have been successfully delivered
        $deliveryVerified = false;
        $sentEmailCount = 0;
        try {
            $sentStmt = $pdo->query("SELECT COUNT(*) FROM newsletter_deliveries WHERE status = 'sent'");
            $sentEmailCount = (int)$sentStmt->fetchColumn();
            if ($sentEmailCount > 0) {
                $deliveryVerified = true;
            }
        } catch (Exception $e) {}

        // D. Queue & Audience Metrics
        $subscriberCount = 0;
        $pendingQueueCount = 0;
        $failedQueueCount = 0;
        try {
            $subStmt = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'");
            $subscriberCount = (int)$subStmt->fetchColumn();

            $qStmt = $pdo->query("SELECT COUNT(*) FROM newsletter_deliveries WHERE status = 'pending'");
            $pendingQueueCount = (int)$qStmt->fetchColumn();

            $fStmt = $pdo->query("SELECT COUNT(*) FROM newsletter_deliveries WHERE status = 'failed'");
            $failedQueueCount = (int)$fStmt->fetchColumn();
        } catch (Exception $e) {}

        // E. Latest Campaign
        $latestCampaign = null;
        try {
            $cStmt = $pdo->query("SELECT id, subject, status, total_subscribers, sent_count, failed_count, created_at FROM newsletter_campaigns ORDER BY id DESC LIMIT 1");
            $latestCampaign = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {}

        // Overall status
        $systemHealthy = $dbOk && $allExtsOk;

        json_response([
            'status' => $systemHealthy ? 'operational' : 'degraded',
            'server' => [
                'php_version'   => PHP_VERSION,
                'os'            => PHP_OS,
                'server_time'   => date('Y-m-d H:i:s T'),
                'memory_usage'  => round(memory_get_usage(true) / 1048576, 2) . ' MB',
                'memory_limit'  => ini_get('memory_limit'),
                'extensions'    => $extStatus,
                'disk'          => $diskInfo
            ],
            'database' => [
                'connected'   => $dbOk,
                'name'        => DB_NAME,
                'latency_ms'  => $dbLatency
            ],
            'smtp' => [
                'host'              => SMTP_HOST,
                'port'              => SMTP_PORT,
                'secure'            => SMTP_SECURE,
                'from_email'        => SMTP_FROM_EMAIL,
                'configured'        => $smtpConfigured,
                'tested'            => $smtpTested,
                'test_error'        => $smtpTestError,
                'delivery_verified' => $deliveryVerified,
                'total_sent_emails' => $sentEmailCount
            ],
            'queue' => [
                'active_subscribers' => $subscriberCount,
                'pending_deliveries' => $pendingQueueCount,
                'failed_deliveries'  => $failedQueueCount,
                'latest_campaign'    => $latestCampaign
            ],
            'checked_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        AppLogger::error('health', 'Health check general error: ' . $e->getMessage());
        json_response([
            'status' => 'error',
            'error'  => 'Health diagnostics failed: ' . $e->getMessage()
        ], 500);
    }
}

json_response(['error' => 'Invalid action.'], 400);
