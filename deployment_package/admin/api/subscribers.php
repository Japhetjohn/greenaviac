<?php
/**
 * Green AVIC Content Studio — Subscriber & Campaign Management API
 * 
 * Provides subscriber viewing, suppression, campaign status, delivery logs,
 * failure retries, and SMTP testing for administrators.
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';
require_once dirname(__DIR__, 2) . '/cms-core/newsletter_service.php';

$pdo = get_db();
ensure_tables_exist($pdo);

$user = require_auth($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ==========================================================
// GET: Subscriber List, Stats & Campaign History
// ==========================================================
if ($method === 'GET') {
    try {
        // 1. Stats
        $statsStmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) as unsubscribed
            FROM subscribers
        ");
        $stats = $statsStmt->fetch() ?: ['total' => 0, 'active' => 0, 'pending' => 0, 'unsubscribed' => 0];

        // 2. Rolling 24-hour quota
        $rollingUsed = get_rolling_24h_sent_count($pdo);
        $availableQuota = get_available_smtp_quota($pdo);

        // 3. Subscribers list (most recent 200)
        $subStmt = $pdo->query("
            SELECT id, email, status, created_at, confirmed_at, unsubscribed_at 
            FROM subscribers 
            ORDER BY created_at DESC 
            LIMIT 200
        ");
        $subscribers = $subStmt->fetchAll();

        // 4. Latest Campaign
        $campStmt = $pdo->query("
            SELECT * FROM newsletter_campaigns 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $latestCampaign = $campStmt->fetch() ?: null;

        $failedDeliveries = [];
        if ($latestCampaign && !empty($latestCampaign['id'])) {
            $failStmt = $pdo->prepare("
                SELECT id as delivery_id, email, error_message, sent_at 
                FROM newsletter_deliveries 
                WHERE campaign_id = ? AND status = 'failed' 
                ORDER BY id DESC 
                LIMIT 50
            ");
            $failStmt->execute([$latestCampaign['id']]);
            $failedDeliveries = $failStmt->fetchAll();
        }

        // 5. Cron & System Settings
        $cronStmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('last_cron_run', 'cron_secret')");
        $cronStmt->execute();
        $settingsRows = $cronStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $lastCronRun = $settingsRows['last_cron_run'] ?? null;
        $cronSecret  = $settingsRows['cron_secret'] ?? 'greenavic_cron_sec_8f7b2a9e3d4c1';

        // 6. SMTP configuration status
        $mailer = new GreenAvicMailer();
        $smtpStatus = [
            'configured' => $mailer->isConfigured(),
            'host'       => defined('SMTP_HOST') ? SMTP_HOST : '',
            'port'       => defined('SMTP_PORT') ? SMTP_PORT : 465,
            'security'   => defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl',
            'from_email' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '',
            'user'       => defined('SMTP_USER') ? SMTP_USER : ''
        ];

        json_response([
            'stats'             => [
                'total'        => (int)($stats['total'] ?? 0),
                'active'       => (int)($stats['active'] ?? 0),
                'pending'      => (int)($stats['pending'] ?? 0),
                'unsubscribed' => (int)($stats['unsubscribed'] ?? 0)
            ],
            'quota'             => [
                'rolling_24h_used'  => $rollingUsed,
                'rolling_24h_limit' => HOSTINGER_24H_LIMIT,
                'available_quota'   => $availableQuota
            ],
            'cron'              => [
                'last_run' => $lastCronRun,
                'secret'   => $cronSecret
            ],
            'subscribers'       => $subscribers,
            'latest_campaign'   => $latestCampaign,
            'failed_deliveries' => $failedDeliveries,
            'smtp'              => $smtpStatus,
            'csrf_token'        => get_csrf_token()
        ]);
    } catch (Exception $e) {
        error_log("Subscribers API GET error: " . $e->getMessage());
        json_response(['error' => 'Unable to load subscriber records.'], 500);
    }
}

// ==========================================================
// POST: Actions (Suppress, Activate, Delete, Retry, Test SMTP)
// ==========================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';

    // Verify CSRF
    $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verify_csrf_token($csrf)) {
        json_response(['error' => 'Security token expired. Please refresh the page.'], 403);
    }

    // ------------------------------------------------------
    // ACTION: Suppress / Unsubscribe
    // ------------------------------------------------------
    if ($action === 'suppress') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['error' => 'Invalid subscriber ID.'], 400);
        }

        try {
            $stmt = $pdo->prepare("UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            json_response(['success' => true, 'message' => 'Subscriber has been suppressed (unsubscribed).']);
        } catch (Exception $e) {
            error_log("Subscriber suppress error: " . $e->getMessage());
            json_response(['error' => 'Failed to update subscriber status.'], 500);
        }
    }

    // ------------------------------------------------------
    // ACTION: Reactivate Subscriber
    // ------------------------------------------------------
    if ($action === 'activate') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['error' => 'Invalid subscriber ID.'], 400);
        }

        try {
            $stmt = $pdo->prepare("UPDATE subscribers SET status = 'active', unsubscribed_at = NULL WHERE id = ?");
            $stmt->execute([$id]);
            json_response(['success' => true, 'message' => 'Subscriber reactivated successfully.']);
        } catch (Exception $e) {
            error_log("Subscriber activate error: " . $e->getMessage());
            json_response(['error' => 'Failed to reactivate subscriber.'], 500);
        }
    }

    // ------------------------------------------------------
    // ACTION: Delete Subscriber
    // ------------------------------------------------------
    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['error' => 'Invalid subscriber ID.'], 400);
        }

        try {
            $pdo->beginTransaction();
            $delLogs = $pdo->prepare("DELETE FROM newsletter_deliveries WHERE subscriber_id = ?");
            $delLogs->execute([$id]);

            $delSub = $pdo->prepare("DELETE FROM subscribers WHERE id = ?");
            $delSub->execute([$id]);
            $pdo->commit();

            json_response(['success' => true, 'message' => 'Subscriber record removed completely.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Subscriber delete error: " . $e->getMessage());
            json_response(['error' => 'Failed to remove subscriber.'], 500);
        }
    }

    // ------------------------------------------------------
    // ACTION: Add Subscriber Manually
    // ------------------------------------------------------
    if ($action === 'add') {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['error' => 'Please enter a valid email address.'], 422);
        }

        try {
            $check = $pdo->prepare("SELECT id, status FROM subscribers WHERE email = ? LIMIT 1");
            $check->execute([$email]);
            $existing = $check->fetch();

            if ($existing) {
                if ($existing['status'] === 'active') {
                    json_response(['error' => 'This email address is already an active subscriber.'], 409);
                }
                $token = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("UPDATE subscribers SET status = 'active', token = ?, subscribed_at = NOW(), unsubscribed_at = NULL WHERE id = ?");
                $stmt->execute([$token, $existing['id']]);
                json_response(['success' => true, 'message' => 'Subscriber reactivated as active.']);
            }

            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO subscribers (email, status, token, ip_address, subscribed_at) VALUES (?, 'active', ?, 'admin-manual', NOW())");
            $stmt->execute([$email, $token]);

            json_response(['success' => true, 'message' => 'Subscriber added successfully.']);
        } catch (Exception $e) {
            error_log("Add subscriber error: " . $e->getMessage());
            json_response(['error' => 'Database error while saving subscriber.'], 500);
        }
    }

    // ------------------------------------------------------
    // ACTION: Retry Failed Deliveries
    // ------------------------------------------------------
    if ($action === 'retry_campaign') {
        $campaignId = (int)($input['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            json_response(['error' => 'Invalid campaign ID.'], 400);
        }

        $result = retry_failed_campaign_deliveries($pdo, $campaignId);
        if ($result['success']) {
            json_response($result);
        } else {
            json_response(['error' => $result['error'] ?? 'Retry attempt failed.'], 500);
        }
    }

    // ------------------------------------------------------
    // ACTION: Process Next Queue Batch (Manual Trigger)
    // ------------------------------------------------------
    if ($action === 'process_batch') {
        $result = process_campaign_queue($pdo, 15);
        json_response([
            'success' => true,
            'result'  => $result,
            'message' => 'Queue batch processed.'
        ]);
    }


    // ------------------------------------------------------
    // ACTION: Send Test Email
    // ------------------------------------------------------
    if ($action === 'test_smtp') {
        $targetEmail = trim((string)($input['email'] ?? ''));
        if (empty($targetEmail) || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            $targetEmail = $user['email'];
        }

        $mailer = new GreenAvicMailer();
        if (!$mailer->isConfigured()) {
            json_response(['error' => 'SMTP is not configured in cms-core/.env. Please enter your SMTP credentials first.'], 400);
        }

        $testSubject = 'Green AVIC — SMTP Connection Test';
        $testBody = '
            <div style="font-family: sans-serif; max-width: 500px; margin: 20px auto; padding: 24px; border: 1px solid #4CAF50; border-radius: 8px;">
                <h2 style="color: #0D3D2F; margin-top: 0;">Green AVIC SMTP Test Successful!</h2>
                <p style="color: #333; line-height: 1.5;">This email confirms that your Hostinger SMTP configuration in <code>cms-core/.env</code> is operating correctly.</p>
                <p style="color: #666; font-size: 13px;">Timestamp: ' . date('Y-m-d H:i:s T') . '</p>
            </div>
        ';

        $sendResult = $mailer->send($targetEmail, $testSubject, $testBody);
        if ($sendResult['success']) {
            json_response([
                'success' => true,
                'message' => "Test email successfully sent to {$targetEmail}!"
            ]);
        } else {
            json_response([
                'error' => "SMTP Test Failed: " . ($sendResult['error'] ?? 'Unknown error')
            ], 500);
        }
    }

    json_response(['error' => 'Invalid action.'], 400);
}

json_response(['error' => 'Method not allowed.'], 405);
