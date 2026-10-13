<?php
/**
 * Green AVIC CMS - Authentication API
 */

require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$ip = get_client_ip();

// Handle GET check
if ($method === 'GET') {
    $session = get_auth_session($config);
    if ($session) {
        json_response([
            'authenticated' => true,
            'email' => $session['email'],
            'csrf_token' => $session['csrf']
        ]);
    } else {
        json_response(['authenticated' => false]);
    }
}

// Read JSON or POST body
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? 'check';

if ($action === 'login') {
    list($allowed, $remaining_min) = check_rate_limit($ip, 5, 900);
    if (!$allowed) {
        json_response([
            'error' => "Too many failed attempts. Account locked for $remaining_min minute(s)."
        ], 429);
    }

    $email = trim($input['email'] ?? '');
    $password = (string)($input['password'] ?? '');

    if (empty($email) || empty($password)) {
        json_response(['error' => 'Please enter both email and password.'], 400);
    }

    $valid_email = strtolower($config['admin_email']);
    $entered_email = strtolower($email);

    if ($entered_email === $valid_email && verify_password($password, $config['admin_password_hash'])) {
        clear_failed_attempts($ip);
        list($token, $csrf) = create_session_token($entered_email, $config['session_secret'], $config['session_duration']);

        $cookie_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
        setcookie('greenavic_session', $token, [
            'expires' => time() + $config['session_duration'],
            'path' => '/',
            'domain' => '',
            'secure' => $cookie_secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        json_response([
            'success' => true,
            'email' => $entered_email,
            'csrf_token' => $csrf
        ]);
    } else {
        record_failed_attempt($ip);
        json_response(['error' => 'Invalid email or password.'], 401);
    }
}

if ($action === 'logout') {
    setcookie('greenavic_session', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    json_response(['success' => true]);
}

if ($action === 'check') {
    $session = get_auth_session($config);
    if ($session) {
        json_response([
            'authenticated' => true,
            'email' => $session['email'],
            'csrf_token' => $session['csrf']
        ]);
    } else {
        json_response(['authenticated' => false]);
    }
}

json_response(['error' => 'Invalid action.'], 400);
