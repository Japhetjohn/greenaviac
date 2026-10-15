<?php
/**
 * Green AVIC CMS — Centralized Structured Logger
 * 
 * Provides database storage (system_logs) with a rotating file fallback (cms-core/logs/app.log).
 * Automatically redacts sensitive data (passwords, tokens, cookies, full email addresses).
 */

defined('GREENAVIC_CORE') or define('GREENAVIC_CORE', true);

class AppLogger {
    private const MAX_LOG_FILE_SIZE = 5242880; // 5 MB
    private const MAX_LOG_ROTATIONS = 3;

    private static ?string $logDir = null;

    public static function getLogDir(): string {
        if (self::$logDir === null) {
            self::$logDir = __DIR__ . '/logs';
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0755, true);
                // Secure log folder from direct web access
                @file_put_contents(self::$logDir . '/.htaccess', "Order deny,allow\nDeny from all\nRequire all denied\n");
                @file_put_contents(self::$logDir . '/index.html', "");
            }
        }
        return self::$logDir;
    }

    public static function info(string $channel, string $message, array $context = []): void {
        self::log('info', $channel, $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void {
        self::log('warning', $channel, $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void {
        self::log('error', $channel, $message, $context);
    }

    public static function debug(string $channel, string $message, array $context = []): void {
        self::log('debug', $channel, $message, $context);
    }

    public static function log(string $level, string $channel, string $message, array $context = []): void {
        $level = strtolower($level);
        $cleanContext = self::redactSensitiveData($context);
        $cleanMessage = self::redactString($message);

        $ip = self::getClientIp();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System', 0, 250);

        // 1. Write to rotating file
        self::writeToFile($level, $channel, $cleanMessage, $cleanContext, $ip);

        // 2. Write to MariaDB system_logs if database is available
        self::writeToDatabase($level, $channel, $cleanMessage, $cleanContext, $ip, $userAgent);
    }

    private static function writeToDatabase(string $level, string $channel, string $message, array $context, string $ip, string $userAgent): void {
        try {
            if (!function_exists('get_db')) {
                return;
            }
            $pdo = get_db();
            if (!$pdo) {
                return;
            }

            $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

            $stmt = $pdo->prepare("
                INSERT INTO system_logs (level, channel, message, context, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $level,
                substr($channel, 0, 64),
                $message,
                $contextJson,
                $ip,
                $userAgent
            ]);
        } catch (\Throwable $e) {
            // Silently fall back to file without throwing
        }
    }

    private static function writeToFile(string $level, string $channel, string $message, array $context, string $ip): void {
        try {
            $dir = self::getLogDir();
            $logFile = $dir . '/app.log';

            // Check file rotation
            if (file_exists($logFile) && filesize($logFile) >= self::MAX_LOG_FILE_SIZE) {
                self::rotateLogFiles($logFile);
            }

            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
            $entry = sprintf("[%s] [%s.%s] [ip:%s] %s%s\n", $timestamp, strtoupper($channel), strtoupper($level), $ip, $message, $contextStr);

            @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Non-blocking fallback
        }
    }

    private static function rotateLogFiles(string $logFile): void {
        for ($i = self::MAX_LOG_ROTATIONS - 1; $i >= 1; $i--) {
            $oldFile = $logFile . '.' . $i;
            $newFile = $logFile . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                @rename($oldFile, $newFile);
            }
        }
        @rename($logFile, $logFile . '.1');
    }

    /**
     * Recursively masks sensitive fields: passwords, tokens, API keys, session ids, and emails.
     */
    public static function redactSensitiveData($data) {
        if (is_array($data)) {
            $redacted = [];
            foreach ($data as $key => $value) {
                $lowerKey = strtolower((string)$key);
                if (preg_match('/(pass|password|pwd|secret|token|csrf|key|auth|cookie|hash)/i', $lowerKey)) {
                    $redacted[$key] = '[REDACTED]';
                } elseif ($lowerKey === 'email' && is_string($value)) {
                    $redacted[$key] = self::maskEmail($value);
                } else {
                    $redacted[$key] = self::redactSensitiveData($value);
                }
            }
            return $redacted;
        } elseif (is_string($data)) {
            return self::redactString($data);
        }
        return $data;
    }

    public static function redactString(string $str): string {
        // Redact authorization / bearer headers
        $str = preg_replace('/(Bearer\s+)[a-zA-Z0-9_\-\.]{10,}/i', '$1[REDACTED]', $str);
        // Redact passwords in strings (e.g. password=xyz)
        $str = preg_replace('/(password|pass|secret|token)\s*[:=]\s*["\']?[^"\'\s,;]+["\']?/i', '$1=[REDACTED]', $str);
        return $str;
    }

    public static function maskEmail(string $email): string {
        $parts = explode('@', trim($email));
        if (count($parts) !== 2) {
            return '***@***';
        }
        $name = $parts[0];
        $domain = $parts[1];
        $maskedName = mb_substr($name, 0, 1) . '***' . (mb_strlen($name) > 2 ? mb_substr($name, -1) : '');
        return $maskedName . '@' . $domain;
    }

    private static function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
