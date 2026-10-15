<?php
/**
 * Green AVIC CMS — Common Security & Helper Functions
 */

defined('GREENAVIC_CORE') or define('GREENAVIC_CORE', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/db.php';

// ==========================================================
// Session Handling
// ==========================================================
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    // Hostinger shared session configuration
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '', // Host-specific for security
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_name('greenavic_admin_sess');
    session_start();

    // Regenerate session periodically
    if (!isset($_SESSION['created_time'])) {
        $_SESSION['created_time'] = time();
    } elseif (time() - $_SESSION['created_time'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created_time'] = time();
    }
}

// ==========================================================
// CSRF Protection
// ==========================================================
function get_csrf_token(): string {
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    start_secure_session();
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ==========================================================
// Authentication Guards
// ==========================================================
function get_current_user_data(PDO $pdo): ?array {
    start_secure_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, email, must_change_password FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_auth(PDO $pdo, bool $allow_must_change = false): array {
    $user = get_current_user_data($pdo);
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Authentication required.',
            'authenticated' => false
        ]);
        exit;
    }

    if (!$allow_must_change && !empty($user['must_change_password'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Password change required before accessing content.',
            'must_change_password' => true
        ]);
        exit;
    }

    return $user;
}

// ==========================================================
// Brute-force Login Rate Limiter (via MariaDB)
// ==========================================================
function get_client_ip(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function check_rate_limit(PDO $pdo, string $ip): array {
    $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if ($row) {
        $last = strtotime($row['last_attempt']);
        $diff = time() - $last;

        if ($row['attempts'] >= 5 && $diff < 900) { // 15 minutes lockout
            $remaining_minutes = ceil((900 - $diff) / 60);
            return [false, $remaining_minutes];
        }

        // If 15 minutes passed since last attempt, reset
        if ($diff >= 900) {
            $resetStmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $resetStmt->execute([$ip]);
        }
    }

    return [true, 0];
}

function record_failed_attempt(PDO $pdo, string $ip): void {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO login_attempts (ip_address, attempts, last_attempt)
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = ?
    ");
    $stmt->execute([$ip, $now, $now]);
}

function clear_failed_attempts(PDO $pdo, string $ip): void {
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip]);
}

// ==========================================================
// HTML Sanitization & Input Helpers
// ==========================================================
function sanitize_article_html(string $html): string {
    if (empty(trim($html))) {
        return '';
    }

    // Strip scripts, frames, objects, embeds
    $clean = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
    $clean = preg_replace('#<iframe(.*?)>(.*?)</iframe>#is', '', $clean);
    $clean = preg_replace('#<object(.*?)>(.*?)</object>#is', '', $clean);
    $clean = preg_replace('#<embed(.*?)>(.*?)</embed>#is', '', $clean);
    $clean = preg_replace('#<embed(.*?)>#is', '', $clean);

    // Strip inline on* event listeners
    $clean = preg_replace('#\s+on[a-z]+\s*=\s*(["\']).*?\1#is', '', $clean);
    $clean = preg_replace('#\s+on[a-z]+\s*=\s*[^ >]+#is', '', $clean);

    // Disallow javascript: URLs in links
    $clean = preg_replace('#href\s*=\s*(["\'])\s*javascript:.*?\1#is', 'href="#"', $clean);

    return trim($clean);
}

function normalize_image_url(?string $url): string {
    if (empty($url) || trim($url) === '') {
        return 'https://newsletter.greenavic.org/assets/images/brand/greenavic-placeholder.svg';
    }
    $url = trim($url);
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }
    if (str_starts_with($url, 'uploads/') || str_starts_with($url, '/uploads/')) {
        return 'https://newsletter.greenavic.org/' . ltrim($url, '/');
    }
    if (str_starts_with($url, 'assets/') || str_starts_with($url, '/assets/')) {
        return 'https://newsletter.greenavic.org/' . ltrim($url, '/');
    }
    return 'https://newsletter.greenavic.org/uploads/' . ltrim($url, '/');
}

function json_response(array $data, int $status_code = 200): void {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================================
// Administrator Invite System
// ==========================================================
function get_invites_file(): string {
    return __DIR__ . '/invites.json';
}

function load_invites(): array {
    $file = get_invites_file();
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_invites(array $invites): void {
    $file = get_invites_file();
    file_put_contents($file, json_encode($invites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function create_invite_token(string $creator = 'system', int $validity_days = 7): string {
    $token = bin2hex(random_bytes(24));
    $invites = load_invites();
    $invites[$token] = [
        'created_at' => time(),
        'expires_at' => time() + ($validity_days * 86400),
        'created_by' => $creator,
        'used'       => false
    ];
    save_invites($invites);
    return $token;
}

function verify_invite_token(string $token): bool {
    if (empty($token)) return false;
    $invites = load_invites();
    if (!isset($invites[$token])) return false;
    $item = $invites[$token];
    if (!empty($item['used'])) return false;
    if (!empty($item['expires_at']) && time() > $item['expires_at']) return false;
    return true;
}

function consume_invite_token(string $token): bool {
    if (!verify_invite_token($token)) return false;
    $invites = load_invites();
    $invites[$token]['used'] = true;
    $invites[$token]['used_at'] = time();
    save_invites($invites);
    return true;
}
