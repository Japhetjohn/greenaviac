<?php
/**
 * Green AVIC Newsletter — Direct Email Subscription API
 * 
 * Handles public subscription requests with verified consent, honeypot spam protection,
 * rate limiting, and instant activation without requiring an email verification link.
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

// 1. Anti-bot Honeypot Check (Silently drop bots)
if (!empty($data['website_url']) || !empty($data['hp_company'])) {
    json_response([
        'success' => true,
        'message' => 'Thank you for subscribing to Green AVIC News & Insights!'
    ]);
}

// 2. Validate Email Address
$email = isset($data['email']) ? trim((string)$data['email']) : '';
$email = strtolower($email);

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    json_response(['error' => 'Please provide a valid email address.'], 422);
}

// 3. Verify Explicit Consent
$consent = !empty($data['consent']) || (isset($data['consent']) && $data['consent'] !== 'false');
if (!$consent) {
    json_response(['error' => 'Please check the box to confirm your consent to receive newsletter updates.'], 422);
}

// 4. Connect to Database
$pdo = get_db();
ensure_tables_exist($pdo);

// 5. Rate Limiting per IP (Max 10 submissions per IP per hour)
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

// 6. Direct Subscription Handler
try {
    $checkStmt = $pdo->prepare("SELECT id, email, status, token FROM subscribers WHERE email = ? LIMIT 1");
    $checkStmt->execute([$email]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        // Address is already active
        if ($existing['status'] === 'active') {
            json_response([
                'success' => true,
                'already_subscribed' => true,
                'message' => 'You are already subscribed to Green AVIC News & Insights updates!'
            ]);
        }

        // Resubscribing with new consent (previously unsubscribed or legacy pending)
        $token = bin2hex(random_bytes(32));
        $updateStmt = $pdo->prepare("
            UPDATE subscribers 
            SET status = 'active', 
                token = ?, 
                ip_address = ?, 
                unsubscribed_at = NULL,
                created_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$token, $clientIp, $existing['id']]);

        json_response([
            'success' => true,
            'message' => 'Welcome back! You have been successfully resubscribed to Green AVIC News & Insights.'
        ]);
    }

    // New Subscriber: immediately store as 'active' (Direct Single Opt-In)
    $token = bin2hex(random_bytes(32));
    $insertStmt = $pdo->prepare("
        INSERT INTO subscribers (email, status, token, ip_address, created_at)
        VALUES (?, 'active', ?, ?, NOW())
    ");
    $insertStmt->execute([$email, $token, $clientIp]);

    // Optional: Only send a welcome email if explicitly enabled in system_settings
    try {
        $settingStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'send_welcome_email' LIMIT 1");
        if ($settingStmt && $settingStmt->fetchColumn() === '1') {
            $mailer = new GreenAvicMailer();
            if ($mailer->isConfigured()) {
                $unsubscribeUrl = NEWSLETTER_URL . '/unsubscribe?token=' . urlencode($token);
                $welcomeSubject = 'Welcome to Green AVIC News & Insights';
                $welcomeBody = '
                    <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; color: #333;">
                        <h2 style="color: #0D3D2F;">Welcome to Green AVIC!</h2>
                        <p>Thank you for subscribing to Green AVIC News & Insights. You will receive updates on sustainable menstrual health, biodegradable materials, eco-innovation, and community impact.</p>
                        <p style="font-size: 12px; color: #777; margin-top: 30px;">
                            You can unsubscribe at any time: <a href="' . htmlspecialchars($unsubscribeUrl) . '">Unsubscribe</a>
                        </p>
                    </div>
                ';
                $mailer->send($email, $welcomeSubject, $welcomeBody, $unsubscribeUrl);
            }
        }
    } catch (Exception $wEx) {
        // Welcome email failure is non-fatal
    }

    json_response([
        'success' => true,
        'message' => 'Thank you for subscribing! You are now subscribed to Green AVIC News & Insights.'
    ]);

} catch (Exception $e) {
    error_log("Subscription API error: " . $e->getMessage());
    json_response(['error' => 'An error occurred while saving your subscription. Please try again.'], 500);
}
