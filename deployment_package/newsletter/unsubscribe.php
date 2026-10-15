<?php
/**
 * Green AVIC Newsletter — Unsubscribe Handler
 * 
 * Secure, one-click unsubscribe endpoint complying with CAN-SPAM and RFC 8058.
 * Allows instant suppression without requiring authentication.
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
    $error = 'Invalid or expired unsubscribe link. If you need assistance, please contact us at info@greenavic.org.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT id, email, status FROM subscribers WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $subscriber = $stmt->fetch();

        if (!$subscriber) {
            $error = 'Subscriber record not found or link has expired.';
        } else {
            if ($subscriber['status'] !== 'unsubscribed') {
                $updateStmt = $pdo->prepare("
                    UPDATE subscribers 
                    SET status = 'unsubscribed', unsubscribed_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$subscriber['id']]);
            }
            $success = true;
        }
    } catch (Exception $e) {
        error_log("Unsubscribe error: " . $e->getMessage());
        $error = 'A temporary database error occurred. Please try again or contact us.';
    }
}

// Return JSON for automated requests
$isJsonRequest = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
              || (!empty($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

if ($isJsonRequest) {
    if ($success) {
        json_response(['success' => true, 'message' => 'Successfully unsubscribed.']);
    } else {
        json_response(['error' => $error ?: 'Unable to process unsubscribe request.'], 400);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $success ? 'Unsubscribed' : 'Unsubscribe Error'; ?> | Green AVIC</title>
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
        .unsub-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color);
            max-width: 520px;
            width: 100%;
            padding: 40px 32px;
            text-align: center;
        }
        .logo {
            margin-bottom: 24px;
        }
        .logo img {
            max-height: 48px;
            width: auto;
        }
        .icon-circle {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon-success {
            background-color: var(--green-light);
            color: var(--green-primary);
        }
        .icon-error {
            background-color: #FEECEB;
            color: #D32F2F;
        }
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
        .btn-primary {
            background-color: var(--green-primary);
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: var(--green-hover);
            transform: translateY(-1px);
        }
        .btn-outline {
            background-color: transparent;
            color: var(--green-dark);
            border: 1px solid var(--border-color);
        }
        .btn-outline:hover {
            background-color: #F8FAF8;
            border-color: #D0D7D2;
        }
        .footer-note {
            margin-top: 32px;
            font-size: 0.8rem;
            color: #999999;
        }
        .footer-note a {
            color: var(--green-primary);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="unsub-card">
        <div class="logo">
            <a href="https://greenavic.org">
                <img src="assets/images/brand/greenavic-logo-real.png" alt="Green AVIC" onerror="this.onerror=null; this.src='https://greenavic.org/assets/images/brand/greenavic-logo-real.png';">
            </a>
        </div>

        <?php if ($success && $subscriber): ?>
            <div class="icon-circle icon-success">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h1>You have been unsubscribed</h1>
            <p>
                <span class="subscriber-email"><?php echo htmlspecialchars($subscriber['email']); ?></span> will no longer receive newsletter broadcasts and editorial updates from Green AVIC.
            </p>
            <div class="actions">
                <a href="https://newsletter.greenavic.org/" class="btn btn-primary">Visit News &amp; Insights</a>
                <a href="https://greenavic.org/" class="btn btn-outline">Return to Green AVIC Home</a>
            </div>
        <?php else: ?>
            <div class="icon-circle icon-error">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <h1>Unsubscribe Link Unavailable</h1>
            <p><?php echo htmlspecialchars($error ?: 'An unknown error occurred.'); ?></p>
            <div class="actions">
                <a href="https://newsletter.greenavic.org/" class="btn btn-primary">Return to Newsletter</a>
                <a href="https://greenavic.org/" class="btn btn-outline">Green AVIC Home</a>
            </div>
        <?php endif; ?>

        <div class="footer-note">
            Green AVIC &bull; Sustainable Menstrual Care &bull; <a href="https://greenavic.org/#contact">Contact Support</a>
        </div>
    </div>
</body>
</html>
