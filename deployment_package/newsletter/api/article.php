<?php
/**
 * Green AVIC Newsletter — Public Article API
 * Reads the latest published article from MariaDB.
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';

$pdo = get_db();
ensure_tables_exist($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed.'], 405);
}

try {
    $stmt = $pdo->query("
        SELECT id, title, slug, excerpt, body, category, author, cover_image, cover_alt, status, reading_time, published_at, updated_at 
        FROM articles 
        WHERE status = 'published' 
        ORDER BY published_at DESC 
        LIMIT 1
    ");
    $article = $stmt->fetch();

    json_response(['article' => $article ?: null]);
} catch (Exception $e) {
    error_log("Public article fetch error: " . $e->getMessage());
    json_response(['error' => 'Unable to load article.'], 500);
}
