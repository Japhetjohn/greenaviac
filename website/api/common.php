<?php
/**
 * Green AVIC CMS - Shared Security & API Helper
 */

define('GREENAVIC_APP', true);

// Set security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Content-Type: application/json; charset=UTF-8');

// Load configuration
$config_file = __DIR__ . '/../data/config.php';
$config = file_exists($config_file) ? require $config_file : [
    'admin_email' => getenv('ADMIN_EMAIL') ?: 'admin@greenavic.org',
    'admin_password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: 'pbkdf2:sha256:100000$6abcd0e4b1389973e6ea22dddc9e6438$f458034d4b1234ef055c22d37a667e407c8faee597ef511fae8f135698b5ce7b',
    'session_secret' => getenv('SESSION_SECRET') ?: '1fc37160935cffb2ef4d118bee73ca959b16d256255c69b2a841e965f0884320',
    'session_duration' => 86400
];

function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function get_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function verify_password($password, $stored_hash) {
    if (strpos($stored_hash, 'pbkdf2:sha256:') === 0) {
        $parts = explode('$', $stored_hash);
        if (count($parts) === 3) {
            $iter_parts = explode(':', $parts[0]);
            $iterations = isset($iter_parts[2]) ? (int)$iter_parts[2] : 100000;
            $salt = $parts[1];
            $expected_hash = $parts[2];
            $computed = hash_pbkdf2('sha256', $password, $salt, $iterations, 0, false);
            return hash_equals($expected_hash, $computed);
        }
    }
    return password_verify($password, $stored_hash);
}

function check_rate_limit($ip, $max_attempts = 5, $decay_seconds = 900) {
    $file = __DIR__ . '/../data/ratelimit.json';
    $now = time();
    $data = [];

    if (file_exists($file)) {
        $content = file_get_contents($file);
        $data = json_decode($content, true) ?: [];
    }

    // Clean old records
    foreach ($data as $k => $rec) {
        if ($now - $rec['last'] > $decay_seconds) {
            unset($data[$k]);
        }
    }

    if (isset($data[$ip])) {
        if ($data[$ip]['attempts'] >= $max_attempts && ($now - $data[$ip]['last'] < $decay_seconds)) {
            $remaining = ceil(($decay_seconds - ($now - $data[$ip]['last'])) / 60);
            return [false, $remaining];
        }
    }

    return [true, 0];
}

function record_failed_attempt($ip) {
    $file = __DIR__ . '/../data/ratelimit.json';
    $now = time();
    $data = [];

    if (file_exists($file)) {
        $content = file_get_contents($file);
        $data = json_decode($content, true) ?: [];
    }

    if (!isset($data[$ip])) {
        $data[$ip] = ['attempts' => 1, 'last' => $now];
    } else {
        $data[$ip]['attempts']++;
        $data[$ip]['last'] = $now;
    }

    file_put_contents($file, json_encode($data), LOCK_EX);
}

function clear_failed_attempts($ip) {
    $file = __DIR__ . '/../data/ratelimit.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
        if (isset($data[$ip])) {
            unset($data[$ip]);
            file_put_contents($file, json_encode($data), LOCK_EX);
        }
    }
}

function create_session_token($email, $secret, $duration) {
    $exp = time() + $duration;
    $csrf = bin2hex(random_bytes(16));
    $payload = json_encode(['email' => $email, 'exp' => $exp, 'csrf' => $csrf]);
    $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $encoded, $secret);
    return [$encoded . '.' . $signature, $csrf];
}

function verify_session_token($token, $secret) {
    if (!$token || strpos($token, '.') === false) return null;
    list($encoded, $signature) = explode('.', $token, 2);
    $expected_sig = hash_hmac('sha256', $encoded, $secret);
    if (!hash_equals($expected_sig, $signature)) return null;

    $json = base64_decode(strtr($encoded, '-_', '+/'));
    $payload = json_decode($json, true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

function get_auth_session($config) {
    $token = $_COOKIE['greenavic_session'] ?? null;
    if (!$token) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $auth_header, $m)) {
            $token = $m[1];
        }
    }
    return verify_session_token($token, $config['session_secret']);
}

function require_auth($config) {
    $session = get_auth_session($config);
    if (!$session) {
        json_response(['error' => 'Authentication required', 'authenticated' => false], 401);
    }
    return $session;
}

function sanitize_article_html($html) {
    if (empty($html)) return '';
    // Allowed tags for article formatting
    $allowed_tags = '<h2><h3><h4><p><br><strong><b><em><i><u><ul><ol><li><blockquote><a><hr><span><div>';
    $clean = strip_tags($html, $allowed_tags);

    // Remove any javascript: links or inline event handlers
    $clean = preg_replace('/href\s*=\s*["\']\s*javascript:[^"\']*["\']/i', 'href="#"', $clean);
    $clean = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);
    return trim($clean);
}
