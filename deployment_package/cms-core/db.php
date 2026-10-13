<?php
/**
 * Green AVIC CMS — MariaDB Database Connection & Helper
 */

defined('GREENAVIC_CORE') or define('GREENAVIC_CORE', true);
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (DB_PASS === 'YOUR_DATABASE_PASSWORD_HERE') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Database configuration required. Please update DB_NAME, DB_USER, and DB_PASS in cms-core/config.php.'
        ]);
        exit;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Unable to connect to database. Please verify MariaDB credentials in cms-core/config.php.'
        ]);
        exit;
    }
}

/**
 * Helper to ensure database tables are created if schema.sql was not imported via phpMyAdmin yet.
 */
function ensure_tables_exist(PDO $pdo): bool {
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'users'");
        if ($check->rowCount() > 0) {
            return true;
        }

        $schemaFile = __DIR__ . '/../schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $pdo->exec($sql);
            return true;
        }
        return false;
    } catch (Exception $e) {
        error_log("Schema initialization error: " . $e->getMessage());
        return false;
    }
}
