<?php
/**
 * Green AVIC Newsletter — Subscription Confirmation Handler
 * 
 * Verifies email confirmation token and activates subscriber (Double Opt-In).
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';

$pdo = get_db();
ensure_tables_exist($pdo);

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if (empty($token) && isset($_POST['token'])) {
    $token = trim((string)$_POST['token']);
}

$subscriber = null;
$error = null;
$success = false;

if (empty($token) || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
    $error = 'Invalid or expired confirmation link. Please subscribe again to receive a fresh verification link.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT id, email, status FROM subscribers WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $subscriber = $stmt->fetch();

        if (!$subscriber) {
            $error = 'Subscriber record not found or confirmation link has expired.';
        } else {
            if ($subscriber['status'] === 'pending') {
                $updateStmt = $pdo->prepare("
                    UPDATE subscribers 
                    SET status = 'active', confirmed_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$subscriber['id']]);
            }
            $success = true;
        }
    } catch (Exception $e) {
        error_log("Confirmation error: " . $e->getMessage());
        $error = 'A temporary database error occurred. Please try again.';
    }
}

// Support JSON requests if needed
$isJsonRequest = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
              || (!empty($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

if ($isJsonRequest) {
    if ($success) {
        json_response(['success' => true, 'message' => 'Subscription confirmed. Welcome!']);
    } else {
        json_response(['error' => $error ?: 'Unable to confirm subscription.'], 400);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $success ? 'Subscription Confirmed' : 'Confirmation Error'; ?> | Green AVIC</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
        :root {
            --green-primary: #4CAF50;
            --green-hover: #45a049;
            --green-dark: #0D3D2F;
            --green-light: #E8F5E9;
            --text-main: #333333;
            --text-muted: #666666;
            --border-color: #E6EAE7;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #F8FAF8;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .confirm-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color);
            max-width: 520px;
            width: 100%;
            padding: 40px 32px;
            text-align: center;
        }
        .logo { margin-bottom: 24px; }
        .logo img { max-height: 48px; width: auto; }
        .icon-circle {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon-success { background-color: var(--green-light); color: var(--green-primary); }
        .icon-error { background-color: #FEECEB; color: #D32F2F; }
        h1 {
            font-size: 1.5rem;
            color: var(--green-dark);
            margin-bottom: 12px;
            font-weight: 700;
        }
        p {
            font-size: 0.95rem;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .subscriber-email {
            font-weight: 600;
            color: var(--green-dark);
            background: #F1F5F2;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
        }
        .btn-primary { background-color: var(--green-primary); color: #ffffff; }
        .btn-primary:hover { background-color: var(--green-hover); transform: translateY(-1px); }
        .btn-outline { background-color: transparent; color: var(--green-dark); border: 1px solid var(--border-color); }
        .btn-outline:hover { background-color: #F8FAF8; border-color: #D0D7D2; }
        .footer-note { margin-top: 32px; font-size: 0.8rem; color: #999999; }
        .footer-note a { color: var(--green-primary); text-decoration: none; }
    </style>
</head>
<body>
    <div class="confirm-card">
        <div class="logo">
            <a href="https://greenavic.org">
                <img src="assets/images/brand/greenavic-logo-real.png" alt="Green AVIC" onerror="this.onerror=null; this.src='https://greenavic.org/assets/images/brand/greenavic-logo-real.png';">
            </a>
        </div>

        <?php if ($success && $subscriber): ?>
            <div class="icon-circle icon-success">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <h1>Your Subscription is Confirmed!</h1>
            <p>
                <span class="subscriber-email"><?php echo htmlspecialchars($subscriber['email']); ?></span> has been verified and added to the Green AVIC News &amp; Insights audience.
            </p>
            <p style="font-size: 0.9rem; color: #777;">
                You will receive our latest reflections on sustainable menstrual health, biodegradable materials, and community impact directly to your inbox.
            </p>
            <div class="actions">
                <a href="https://newsletter.greenavic.org/" class="btn btn-primary">Read Latest News &amp; Insights &rarr;</a>
                <a href="https://greenavic.org/" class="btn btn-outline">Visit Green AVIC Website</a>
            </div>
        <?php else: ?>
            <div class="icon-circle icon-error">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <h1>Verification Link Expired</h1>
            <p><?php echo htmlspecialchars($error ?: 'Unable to verify email.'); ?></p>
            <div class="actions">
                <a href="https://newsletter.greenavic.org/" class="btn btn-primary">Subscribe Again</a>
                <a href="https://greenavic.org/" class="btn btn-outline">Green AVIC Home</a>
            </div>
        <?php endif; ?>

        <div class="footer-note">
            Green AVIC &bull; Empowering women sustainably &bull; <a href="https://greenavic.org/#contact">Contact Support</a>
        </div>
    </div>
</body>
</html>
