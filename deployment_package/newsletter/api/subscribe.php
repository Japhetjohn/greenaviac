<?php
/**
 * Green AVIC Newsletter — Double Opt-In Subscription API
 * 
 * Handles public subscription requests, honeypot spam protection,
 * duplicate handling, rate limiting, and automated verification email dispatch.
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';
require_once dirname(__DIR__, 2) . '/cms-core/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed. Only POST is accepted.'], 405);
}

// Read payload (supports JSON and application/x-www-form-urlencoded)
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

// 1. Anti-bot Honeypot Check
if (!empty($data['website_url']) || !empty($data['hp_company'])) {
    // Silently succeed so bots don't adapt
    json_response([
        'success' => true,
        'message' => 'Thank you! Please check your email to confirm your subscription.'
    ]);
}

// 2. Validate Email
$email = isset($data['email']) ? trim((string)$data['email']) : '';
$email = strtolower($email);

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    json_response(['error' => 'Please provide a valid email address.'], 422);
}

// 3. Connect to Database
$pdo = get_db();
ensure_tables_exist($pdo);

// 4. Rate Limiting per IP (Max 10 signups per IP per hour)
$clientIp = get_client_ip();
try {
    $rateStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM subscribers 
        WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $rateStmt->execute([$clientIp]);
    if ((int)$rateStmt->fetchColumn() >= 10) {
        json_response([
            'error' => 'Too many requests from your network. Please try again later.'
        ], 429);
    }
} catch (Exception $e) {
    // Non-fatal fallback
}

$mailer = new GreenAvicMailer();

// 5. Handle Subscriber Record & Verification Dispatch
try {
    $checkStmt = $pdo->prepare("SELECT id, email, status, token FROM subscribers WHERE email = ? LIMIT 1");
    $checkStmt->execute([$email]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'active') {
            json_response([
                'success' => true,
                'already_subscribed' => true,
                'message' => 'You are already a confirmed subscriber to Green AVIC News & Insights!'
            ]);
        }

        // Resend confirmation email for pending or reactivating contact
        $token = bin2hex(random_bytes(32));
        $updateStmt = $pdo->prepare("
            UPDATE subscribers 
            SET status = 'pending', 
                token = ?, 
                ip_address = ?, 
                created_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$token, $clientIp, $existing['id']]);

        $confirmUrl = NEWSLETTER_URL . '/confirm?token=' . urlencode($token);
        $mailResult = $mailer->sendConfirmationEmail($email, $confirmUrl);

        if (!$mailResult['success']) {
            error_log("Failed to send confirmation email: " . ($mailResult['error'] ?? 'Unknown'));
        }

        json_response([
            'success' => true,
            'message' => 'A verification link has been sent to your email. Please check your inbox (and spam folder) to complete your subscription.'
        ]);
    }

    // New Subscriber: insert with status 'pending' (Double Opt-In)
    $token = bin2hex(random_bytes(32));
    $insertStmt = $pdo->prepare("
        INSERT INTO subscribers (email, status, token, ip_address, created_at)
        VALUES (?, 'pending', ?, ?, NOW())
    ");
    $insertStmt->execute([$email, $token, $clientIp]);

    $confirmUrl = NEWSLETTER_URL . '/confirm?token=' . urlencode($token);
    $mailResult = $mailer->sendConfirmationEmail($email, $confirmUrl);

    if (!$mailResult['success']) {
        error_log("Failed to send initial confirmation email: " . ($mailResult['error'] ?? 'Unknown'));
    }

    json_response([
        'success' => true,
        'message' => 'Thank you! Please check your inbox (and spam folder) and click the verification link to confirm your subscription.'
    ]);

} catch (Exception $e) {
    error_log("Subscription API error: " . $e->getMessage());
    json_response(['error' => 'An error occurred while saving your subscription. Please try again.'], 500);
}
