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
$rawConsent = $data['consent'] ?? null;
$consent = ($rawConsent === true || $rawConsent === 1 || $rawConsent === '1' || $rawConsent === 'true' || $rawConsent === 'on');
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
            AppLogger::info('subscribers', 'Subscription request for already active subscriber', ['email' => $email]);
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

        AppLogger::info('subscribers', 'Subscriber resubscribed', ['email' => $email]);
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

    AppLogger::info('subscribers', 'New subscriber registered', ['email' => $email, 'ip' => $clientIp]);

    // Send welcome email if enabled in system_settings or default
    try {
        $settingStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'send_welcome_email' LIMIT 1");
        $shouldSendWelcome = true;
        if ($settingStmt) {
            $val = $settingStmt->fetchColumn();
            if ($val !== false && $val === '0') {
                $shouldSendWelcome = false;
            }
        }
        if ($shouldSendWelcome) {
            $mailer = new GreenAvicMailer();
            if ($mailer->isConfigured()) {
                $unsubscribeUrl = NEWSLETTER_URL . '/unsubscribe?token=' . urlencode($token);
                $welcomeSubject = 'Welcome to Green AVIC News & Insights';
                $logoUrl = rtrim(MAIN_SITE_URL, '/') . '/assets/images/brand/greenavic-logo-real.png';
                $welcomeBody = '
                    <!DOCTYPE html>
                    <html lang="en">
                    <head>
                        <meta charset="UTF-8">
                        <title>Welcome to Green AVIC</title>
                    </head>
                    <body style="margin: 0; padding: 0; background-color: #F4F7F4; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
                        <center style="width: 100%; background-color: #F4F7F4; padding: 32px 0;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #E6EAE7;">
                                <tr>
                                    <td style="background-color: #0D3D2F; padding: 24px 32px; text-align: center;">
                                        <img src="' . htmlspecialchars($logoUrl) . '" alt="Green AVIC" width="160" style="max-height: 44px; width: auto; display: block; margin: 0 auto;">
                                        <div style="font-size: 11px; color: #B8F542; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; margin-top: 8px;">
                                            News &amp; Insights
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 36px 32px; color: #333333;">
                                        <h1 style="color: #0D3D2F; font-size: 22px; font-weight: 700; margin: 0 0 16px;">Welcome to the Green AVIC Community</h1>
                                        <p style="font-size: 15px; line-height: 1.6; color: #555555; margin: 0 0 20px;">
                                            Thank you for subscribing to Green AVIC News &amp; Insights! You will receive our latest updates on sustainable menstrual health, biodegradable materials, eco-innovation, and community empowerment.
                                        </p>
                                        <div style="text-align: center; margin: 28px 0;">
                                            <a href="' . htmlspecialchars(NEWSLETTER_URL) . '" target="_blank" style="background-color: #2E7D32; color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 600; padding: 12px 30px; border-radius: 8px; display: inline-block;">
                                                Visit News &amp; Insights
                                            </a>
                                        </div>
                                        <hr style="border: none; border-top: 1px solid #E6EAE7; margin: 28px 0;">
                                        <p style="font-size: 12px; line-height: 1.5; color: #888888; margin: 0;">
                                            You are receiving this because you subscribed on <a href="' . htmlspecialchars(NEWSLETTER_URL) . '" style="color: #2E7D32; text-decoration: none;">newsletter.greenavic.org</a>.<br>
                                            If you wish to unsubscribe, you can <a href="' . htmlspecialchars($unsubscribeUrl) . '" style="color: #666; text-decoration: underline;">unsubscribe at any time</a>.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </center>
                    </body>
                    </html>
                ';
                $sendResult = $mailer->send($email, $welcomeSubject, $welcomeBody, $unsubscribeUrl);
                if ($sendResult['success']) {
                    AppLogger::info('subscribers', 'Welcome email sent successfully to subscriber', ['email' => $email]);
                } else {
                    AppLogger::warning('subscribers', 'Welcome email failed to send', ['email' => $email, 'error' => $sendResult['error']]);
                }
            }
        }
    } catch (Exception $wEx) {
        AppLogger::warning('subscribers', 'Welcome email exception', ['error' => $wEx->getMessage()]);
    }

    json_response([
        'success' => true,
        'message' => 'Thank you for subscribing! You are now subscribed to Green AVIC News & Insights.'
    ]);

} catch (Exception $e) {
    AppLogger::error('subscribers', 'Subscription error: ' . $e->getMessage(), ['email' => $email]);
    error_log("Subscription API error: " . $e->getMessage());
    json_response(['error' => 'An error occurred while saving your subscription. Please try again.'], 500);
}
