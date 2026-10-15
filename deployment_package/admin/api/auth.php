<?php
/**
 * Green AVIC Content Studio — Authentication & Session API
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';

$pdo = get_db();
ensure_tables_exist($pdo);

$method = $_SERVER['REQUEST_METHOD'];

// ==========================================================
// GET: Check Current Session Status
// ==========================================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'check';

    if ($action === 'check') {
        $user = get_current_user_data($pdo);
        if ($user) {
            json_response([
                'authenticated'        => true,
                'email'                => $user['email'],
                'must_change_password' => (bool)$user['must_change_password'],
                'csrf_token'           => get_csrf_token()
            ]);
        } else {
            json_response([
                'authenticated' => false
            ]);
        }
    }

    if ($action === 'list_admins') {
        try {
            $user = require_auth($pdo, true);
            $stmt = $pdo->query("SELECT id, name, email, role, status, must_change_password, created_at FROM users ORDER BY id ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $admins = [];
            foreach ($rows as $row) {
                $status = !empty($row['must_change_password']) ? 'Pending Setup' : ucfirst($row['status'] ?? 'active');
                $admins[] = [
                    'id'         => (int)$row['id'],
                    'name'       => !empty($row['name']) ? $row['name'] : 'Administrator',
                    'email'      => $row['email'],
                    'role'       => !empty($row['role']) ? $row['role'] : 'admin',
                    'status'     => $status,
                    'is_current' => ((int)$row['id'] === (int)$user['id']),
                    'created_at' => $row['created_at'] ?? null
                ];
            }
            AppLogger::info('auth', 'Administrator roster retrieved', ['admin_id' => $user['id'], 'count' => count($admins)]);
            json_response([
                'success' => true,
                'count'   => count($admins),
                'admins'  => $admins
            ]);
        } catch (Exception $e) {
            AppLogger::error('auth', 'list_admins error: ' . $e->getMessage());
            json_response([
                'success' => false,
                'error'   => 'Unable to load administrators: ' . $e->getMessage(),
                'admins'  => []
            ], 500);
        }
    }

    json_response(['error' => 'Invalid action.'], 400);
}

// ==========================================================
// POST: Login / Logout / Change Password
// ==========================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';

    // ------------------------------------------------------
    // ACTION: Login
    // ------------------------------------------------------
    if ($action === 'login') {
        $ip = get_client_ip();
        [$allowed, $remaining] = check_rate_limit($pdo, $ip);

        if (!$allowed) {
            json_response([
                'error' => sprintf('Too many failed login attempts. Access is locked for %d more minute(s).', $remaining)
            ], 429);
        }

        $email    = strtolower(trim($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if (empty($email) || empty($password)) {
            json_response(['error' => 'Please provide both administrator email and password.'], 400);
        }

        $stmt = $pdo->prepare("SELECT id, email, password_hash, must_change_password FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Success: clear failed rate limiting attempts
            clear_failed_attempts($pdo, $ip);

            start_secure_session();
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            json_response([
                'success'              => true,
                'message'              => 'Sign in successful.',
                'email'                => $user['email'],
                'must_change_password' => (bool)$user['must_change_password'],
                'csrf_token'           => $_SESSION['csrf_token']
            ]);
        } else {
            record_failed_attempt($pdo, $ip);
            json_response(['error' => 'Invalid administrator credentials.'], 401);
        }
    }

    // ------------------------------------------------------
    // ACTION: Change Password (First Login Requirement)
    // ------------------------------------------------------
    if ($action === 'change_password') {
        $user = require_auth($pdo, true); // Allow users who must change password

        $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!verify_csrf_token($csrf)) {
            json_response(['error' => 'Security token expired. Please refresh the page.'], 403);
        }

        $current_pass = (string)($input['current_password'] ?? '');
        $new_pass     = (string)($input['new_password'] ?? '');
        $confirm_pass = (string)($input['confirm_password'] ?? '');

        if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
            json_response(['error' => 'All password fields are required.'], 400);
        }

        if ($new_pass !== $confirm_pass) {
            json_response(['error' => 'New password and confirmation do not match.'], 400);
        }

        if (strlen($new_pass) < 8) {
            json_response(['error' => 'New password must be at least 8 characters long.'], 400);
        }

        // Verify current password
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current_pass, $row['password_hash'])) {
            json_response(['error' => 'Current password is incorrect.'], 400);
        }

        if (password_verify($new_pass, $row['password_hash'])) {
            json_response(['error' => 'New password must be different from your current password.'], 400);
        }

        $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
        $updateStmt->execute([$new_hash, $user['id']]);

        json_response([
            'success' => true,
            'message' => 'Password updated successfully! Welcome to Green AVIC Content Studio.'
        ]);
    }

    // ------------------------------------------------------
    // ACTION: Logout
    // ------------------------------------------------------
    if ($action === 'logout') {
        start_secure_session();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        json_response([
            'success' => true,
            'message' => 'Signed out successfully.'
        ]);
    }

    // ------------------------------------------------------
    // ACTION: Create Client/Team Invite Token
    // ------------------------------------------------------
    if ($action === 'create_invite') {
        $user = require_auth($pdo);
        $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!verify_csrf_token($csrf)) {
            json_response(['error' => 'Security token expired. Please refresh the page.'], 403);
        }

        $token = create_invite_token($user['email'], 7);
        $inviteUrl = ADMIN_URL . '/invite?token=' . $token;

        json_response([
            'success'    => true,
            'token'      => $token,
            'invite_url' => $inviteUrl
        ]);
    }

    // ------------------------------------------------------
    // ACTION: Add Admin Directly
    // ------------------------------------------------------
    if ($action === 'add_admin') {
        $user = require_auth($pdo);
        $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!verify_csrf_token($csrf)) {
            json_response(['error' => 'Security token expired. Please refresh the page.'], 403);
        }

        $newEmail = strtolower(trim($input['email'] ?? ''));
        $newPass  = (string)($input['password'] ?? '');

        if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(['error' => 'Valid email address required.'], 400);
        }
        if (strlen($newPass) < 8) {
            json_response(['error' => 'Password must be at least 8 characters long.'], 400);
        }

        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->execute([$newEmail]);
        if ($check->fetch()) {
            json_response(['error' => 'An administrator with this email already exists.'], 400);
        }

        $newName = trim($input['name'] ?? '');
        if (empty($newName)) {
            $parts = explode('@', $newEmail);
            $newName = ucwords(str_replace(['.', '_', '-'], ' ', $parts[0]));
        }
        $newRole = trim($input['role'] ?? 'admin');
        if (!in_array($newRole, ['admin', 'editor'], true)) {
            $newRole = 'admin';
        }

        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $ins = $pdo->prepare("
            INSERT INTO users (email, name, role, password_hash, must_change_password, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $ins->execute([$newEmail, $newName, $newRole, $hash]);
        AppLogger::info('auth', 'New administrator added', ['email' => $newEmail, 'role' => $newRole, 'by' => $user['id']]);

        json_response([
            'success' => true,
            'message' => 'New administrator added. They will be prompted to change their password on first sign in.'
        ]);
    }

    // ------------------------------------------------------
    // ACTION: Delete Admin
    // ------------------------------------------------------
    if ($action === 'delete_admin') {
        $user = require_auth($pdo);
        $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!verify_csrf_token($csrf)) {
            json_response(['error' => 'Security token expired. Please refresh the page.'], 403);
        }

        $delId = (int)($input['user_id'] ?? 0);
        if ($delId === (int)$user['id']) {
            json_response(['error' => 'You cannot delete your own active administrator account.'], 400);
        }

        $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $del->execute([$delId]);
        AppLogger::warning('auth', 'Administrator removed', ['target_id' => $delId, 'by' => $user['id']]);

        json_response([
            'success' => true,
            'message' => 'Administrator removed successfully.'
        ]);
    }

    json_response(['error' => 'Invalid action.'], 400);
}

json_response(['error' => 'Method not allowed.'], 405);
