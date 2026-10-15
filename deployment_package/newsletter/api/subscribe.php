<?php
/**
 * Green AVIC Newsletter — Public Subscription API
 * 
 * Handles single opt-in email subscription with honeypot spam protection,
 * duplicate handling, rate limiting, and unique unsubscribe token generation.
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';

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
// Real users won't fill this hidden field; bots will.
if (!empty($data['website_url']) || !empty($data['hp_company'])) {
    // Pretend success so bots don't adapt
    json_response([
        'success' => true,
        'message' => 'Thank you for subscribing to Green AVIC News & Insights!'
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
        WHERE ip_address = ? AND subscribed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $rateStmt->execute([$clientIp]);
    if ((int)$rateStmt->fetchColumn() >= 10) {
        json_response([
            'error' => 'Too many subscription requests from your network. Please try again later.'
        ], 429);
    }
} catch (Exception $e) {
    // Graceful fallback if rate check fails
}

// 5. Check Existing Subscriber Record
try {
    $checkStmt = $pdo->prepare("SELECT id, email, status, token FROM subscribers WHERE email = ? LIMIT 1");
    $checkStmt->execute([$email]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'active') {
            json_response([
                'success' => true,
                'already_subscribed' => true,
                'message' => 'You are already subscribed to Green AVIC updates!'
            ]);
        }

        // Reactivate previously unsubscribed contact
        $newToken = bin2hex(random_bytes(32));
        $updateStmt = $pdo->prepare("
            UPDATE subscribers 
            SET status = 'active', 
                token = ?, 
                ip_address = ?, 
                subscribed_at = NOW(), 
                unsubscribed_at = NULL 
            WHERE id = ?
        ");
        $updateStmt->execute([$newToken, $clientIp, $existing['id']]);

        json_response([
            'success' => true,
            'message' => 'Welcome back! Your subscription to Green AVIC updates has been reactivated.'
        ]);
    }

    // New Subscriber
    $token = bin2hex(random_bytes(32));
    $insertStmt = $pdo->prepare("
        INSERT INTO subscribers (email, status, token, ip_address, subscribed_at)
        VALUES (?, 'active', ?, ?, NOW())
    ");
    $insertStmt->execute([$email, $token, $clientIp]);

    json_response([
        'success' => true,
        'message' => 'Thank you for subscribing to Green AVIC News & Insights!'
    ]);

} catch (Exception $e) {
    error_log("Subscription API error: " . $e->getMessage());
    json_response(['error' => 'An error occurred while saving your subscription. Please try again.'], 500);
}
