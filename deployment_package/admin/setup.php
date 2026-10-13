<?php
/**
 * Green AVIC Content Studio — Secure One-Time Administrator Setup
 * 
 * Sets up primary admin account and automatically generates a client onboarding invite.
 * Automatically self-locks permanently after successful initialization.
 */

require_once dirname(__DIR__) . '/cms-core/common.php';

$lockFile = dirname(__DIR__) . '/cms-core/setup.lock';
$isLocked = file_exists($lockFile);

$dbError = null;
$pdo = null;
$adminCount = 0;

try {
    $pdo = get_db();
    ensure_tables_exist($pdo);

    $checkStmt = $pdo->query("SELECT COUNT(*) FROM users");
    $adminCount = (int)$checkStmt->fetchColumn();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

$success = false;
$error = null;
$clientInviteUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked && $adminCount === 0 && !$dbError) {
    $email    = strtolower(trim($_POST['email'] ?? 'japhetjohnk@gmail.com'));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid administrator email address.";
    } elseif (empty($password) || empty($confirm)) {
        $error = "Please enter and confirm the initial administrator password.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match. Please re-enter.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO users (email, password_hash, must_change_password, created_at, updated_at)
                VALUES (?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([$email, $hash]);

            // Generate ready-to-use client onboarding invite token
            $token = create_invite_token($email, 7);
            $clientInviteUrl = ADMIN_URL . '/invite?token=' . $token;

            // Create permanent lockfile
            file_put_contents($lockFile, "Installed at: " . date('c') . " by " . $email . "\n");
            $success = true;
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Administrator | Green AVIC Content Studio</title>
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
        .setup-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 12px 36px rgba(13,61,47,0.08);
            border: 1px solid var(--border);
            width: 100%;
            max-width: 480px;
            padding: 44px 38px;
            position: relative;
            overflow: hidden;
        }
        .logo-wrap {
            text-align: center;
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
            text-align: center;
            font-weight: 700;
        }
        p.subtitle {
            font-size: 14px;
            color: #666;
            margin-bottom: 28px;
            text-align: center;
            line-height: 1.5;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
        }
        input[type="email"], input[type="password"], input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        input:focus {
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
        .invite-box {
            margin-top: 24px;
            padding: 18px;
            background: #F4FBF4;
            border: 1.5px dashed #A5D6A7;
            border-radius: 12px;
            text-align: left;
        }
        .invite-box h3 {
            font-size: 15px;
            color: var(--green-dark);
            margin-bottom: 6px;
        }
        .invite-box p {
            font-size: 13px;
            color: #555;
            margin-bottom: 12px;
            line-height: 1.4;
        }
        .invite-input-row {
            display: flex;
            gap: 8px;
        }
        .invite-input-row input {
            font-size: 12px;
            background: #fff;
        }
        .btn-copy {
            padding: 10px 16px;
            background: var(--green-dark);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-copy:hover {
            background: #145945;
        }
        .btn-login {
            display: block;
            margin-top: 20px;
            text-align: center;
            padding: 13px;
            background: var(--green);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="setup-card">
    <div class="logo-wrap">
        <img src="assets/images/brand/greenavic-logo-main.png" alt="Green AVIC" onerror="this.src='assets/images/brand/greenavic-logo.png'">
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>Administrator Account Created!</strong><br>
            Your account (<code><?= htmlspecialchars($email) ?></code>) has been securely configured in MariaDB.<br><br>
            <em>Installer has locked itself for security. Proceed to sign in to start publishing.</em>
        </div>

        <?php if ($clientInviteUrl): ?>
            <div class="invite-box">
                <h3>👥 Onboard Your Client / Co-Admin</h3>
                <p>Send this private link to your client so she can set her own email and choose her own private password:</p>
                <div class="invite-input-row">
                    <input type="text" id="inviteUrlInput" value="<?= htmlspecialchars($clientInviteUrl) ?>" readonly>
                    <button type="button" class="btn-copy" id="copyBtn" onclick="copyInvite()">Copy Link</button>
                </div>
            </div>
            <script>
                function copyInvite() {
                    const input = document.getElementById('inviteUrlInput');
                    input.select();
                    navigator.clipboard.writeText(input.value).then(() => {
                        const btn = document.getElementById('copyBtn');
                        btn.textContent = 'Copied!';
                        btn.style.background = '#4CAF50';
                        setTimeout(() => {
                            btn.textContent = 'Copy Link';
                            btn.style.background = '';
                        }, 2500);
                    });
                }
            </script>
        <?php endif; ?>

        <a href="login" class="btn-login">Proceed to Admin Login &rarr;</a>

    <?php elseif ($isLocked || $adminCount > 0): ?>
        <div class="alert alert-warning">
            <strong>Administrator Already Configured</strong><br>
            An administrator account is already active in the database.<br><br>
            For security, the initial setup installer is permanently disabled. You can generate new client invite links directly inside the dashboard.
        </div>
        <a href="login" class="btn-login">Go to Administrator Login &rarr;</a>

    <?php elseif ($dbError): ?>
        <div class="alert alert-error">
            <strong>Database Connection Error</strong><br>
            Could not connect to MariaDB with settings in <code>cms-core/.env</code> or <code>cms-core/config.php</code>.<br><br>
            <em>Details:</em> <?= htmlspecialchars($dbError) ?><br><br>
            Please check that your database name, user, and password are correctly set.
        </div>

    <?php else: ?>
        <h1>Initialize Administrator</h1>
        <p class="subtitle">Green AVIC Content Studio — One-Time Setup</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="setup">
            <div class="form-group">
                <label for="email">Administrator Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? 'japhetjohnk@gmail.com') ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Initial Administrator Password</label>
                <input type="password" id="password" name="password" required minlength="8" placeholder="At least 8 characters">
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Repeat your password">
            </div>

            <button type="submit" class="btn-submit">Complete Setup &rarr;</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>
