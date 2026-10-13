<?php
/**
 * Green AVIC Content Studio — Secure Administrator Onboarding / Invite
 * 
 * Allows an invited client or co-administrator to set up their own account
 * and choose their own password using a secure one-time invite token.
 */

require_once dirname(__DIR__) . '/cms-core/common.php';

$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$isValidToken = verify_invite_token($token);

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isValidToken) {
        $error = "This invitation token is invalid, expired, or has already been used.";
    } else {
        $email    = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please provide a valid email address.";
        } elseif (empty($password) || empty($confirm)) {
            $error = "Please enter and confirm your secret password.";
        } elseif ($password !== $confirm) {
            $error = "Passwords do not match. Please re-enter.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } else {
            try {
                $pdo = get_db();
                ensure_tables_exist($pdo);

                // Check if email already taken
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = "An administrator account with this email already exists. Please sign in instead.";
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare("
                        INSERT INTO users (email, password_hash, must_change_password, created_at, updated_at)
                        VALUES (?, ?, 0, NOW(), NOW())
                    ");
                    $ins->execute([$email, $hash]);
                    $userId = (int)$pdo->lastInsertId();

                    // Invalidate token
                    consume_invite_token($token);

                    // Auto login
                    start_secure_session();
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['email']   = $email;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    $success = true;
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Invitation | Green AVIC Content Studio</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" href="assets/images/brand/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #4CAF50;
            --green-dark: #0D3D2F;
            --danger: #D32F2F;
            --border: #E2E8E2;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Open Sans', sans-serif;
            background: linear-gradient(135deg, #F4F8F4 0%, #EBF3EB 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #333;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 12px 36px rgba(13,61,47,0.08);
            border: 1px solid var(--border);
            width: 100%;
            max-width: 480px;
            padding: 44px 38px;
            text-align: center;
        }
        .logo-wrap {
            margin-bottom: 24px;
        }
        .logo-wrap img {
            height: 48px;
            width: auto;
            object-fit: contain;
        }
        h1 {
            font-size: 22px;
            color: var(--green-dark);
            margin-bottom: 8px;
            font-weight: 700;
        }
        p.subtitle {
            font-size: 14px;
            color: #666;
            margin-bottom: 28px;
            line-height: 1.5;
        }
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
        }
        input[type="email"], input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        input[type="email"]:focus, input[type="password"]:focus {
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
            background: #43A047;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.5;
            text-align: left;
        }
        .alert-error {
            background: #FFEBEE;
            color: #C62828;
            border: 1px solid #FFCDD2;
        }
        .alert-warning {
            background: #FFF8E1;
            color: #F57F17;
            border: 1px solid #FFE082;
        }
        .alert-success {
            background: #E8F5E9;
            color: #2E7D32;
            border: 1px solid #C8E6C9;
        }
        .btn-login {
            display: inline-block;
            margin-top: 14px;
            padding: 12px 24px;
            background: var(--green-dark);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="card">
    <div class="logo-wrap">
        <img src="assets/images/brand/greenavic-logo-main.png" alt="Green AVIC" onerror="this.src='assets/images/brand/greenavic-logo.png'">
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>Welcome to Green AVIC!</strong><br>
            Your administrator account has been created successfully. Both you and your team now have full access to publish and manage content.
        </div>
        <a href="./" class="btn-login">Open Content Studio &rarr;</a>

    <?php elseif (!$isValidToken): ?>
        <div class="alert alert-warning">
            <strong>Invalid or Expired Invitation</strong><br>
            This invitation link is not valid or has already been used. Please request a new invite link from your website administrator.
        </div>
        <a href="login" class="btn-login">Go to Administrator Login &rarr;</a>

    <?php else: ?>
        <h1>Create Your Admin Account</h1>
        <p class="subtitle">You have been invited to manage the Green AVIC News & Insights publication. Choose your credentials below:</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="invite?token=<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label for="email">Administrator Email</label>
                <input type="email" id="email" name="email" required placeholder="e.g. vera@greenavic.org" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password">Create Secret Password</label>
                <input type="password" id="password" name="password" required minlength="8" placeholder="At least 8 characters">
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Repeat your password">
            </div>

            <button type="submit" class="btn-submit">Create My Admin Account &rarr;</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>
