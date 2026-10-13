<?php
/**
 * Green AVIC CMS - Single-Publication Article API
 */

require_once __DIR__ . '/common.php';

$article_file = __DIR__ . '/../data/article.json';
$draft_file   = __DIR__ . '/../data/draft.json';
$backup_file  = __DIR__ . '/../data/article.bak.json';

$method = $_SERVER['REQUEST_METHOD'];

// ==========================================
// GET: Public or Admin Article Data
// ==========================================
if ($method === 'GET') {
    $is_admin_req = isset($_GET['admin']) && $_GET['admin'] == '1';

    if ($is_admin_req) {
        $session = require_auth($config);

        $published = null;
        if (file_exists($article_file)) {
            $published = json_decode(file_get_contents($article_file), true);
        }

        $draft = null;
        if (file_exists($draft_file)) {
            $draft = json_decode(file_get_contents($draft_file), true);
        }

        json_response([
            'published' => $published,
            'draft' => $draft
        ]);
    } else {
        // Public request
        $article = null;
        if (file_exists($article_file)) {
            $data = json_decode(file_get_contents($article_file), true);
            if ($data && isset($data['status']) && $data['status'] === 'published') {
                $article = $data;
            }
        }

        json_response(['article' => $article]);
    }
}

// ==========================================
// POST: Manage Draft or Publish (Admin Only)
// ==========================================
if ($method === 'POST') {
    $session = require_auth($config);

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';

    // Verify CSRF token
    $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($session['csrf'], $csrf)) {
        json_response(['error' => 'Security token expired. Please refresh the page.'], 403);
    }

    $title = trim($input['title'] ?? '');
    $excerpt = trim($input['excerpt'] ?? '');
    $body = trim($input['body'] ?? '');
    $category = trim($input['category'] ?? 'Sustainability');
    $author = trim($input['author'] ?? 'Green AVIC');
    if (empty($author)) $author = 'Green AVIC';
    $cover_image = trim($input['cover_image'] ?? '');
    $cover_alt = trim($input['cover_alt'] ?? '');

    // Sanitization
    $clean_body = sanitize_article_html($body);
    $clean_title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $clean_excerpt = htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8');
    $clean_category = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
    $clean_author = htmlspecialchars($author, ENT_QUOTES, 'UTF-8');
    $clean_cover_alt = htmlspecialchars($cover_alt, ENT_QUOTES, 'UTF-8');

    // Calculate reading time
    $words = str_word_count(strip_tags($clean_body));
    $reading_time = max(1, (int)ceil($words / 200));

    // Generate slug
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
    if (empty($slug)) $slug = 'latest-publication';

    // ------------------------------------------
    // ACTION: Save Draft
    // ------------------------------------------
    if ($action === 'save_draft') {
        if (empty($clean_title) && empty($clean_body)) {
            json_response(['error' => 'Draft must contain at least a title or body text.'], 400);
        }

        $now = date('c');
        $draft_data = [
            'id' => 'current-draft',
            'title' => $clean_title,
            'slug' => $slug,
            'excerpt' => $clean_excerpt,
            'body' => $clean_body,
            'category' => $clean_category,
            'author' => $clean_author,
            'cover_image' => $cover_image,
            'cover_alt' => $clean_cover_alt,
            'status' => 'draft',
            'updated_at' => $now,
            'reading_time' => $reading_time
        ];

        file_put_contents($draft_file, json_encode($draft_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        json_response([
            'success' => true,
            'message' => 'Draft saved successfully.',
            'draft' => $draft_data
        ]);
    }

    // ------------------------------------------
    // ACTION: Publish Article (Replaces Current)
    // ------------------------------------------
    if ($action === 'publish') {
        if (empty($clean_title)) {
            json_response(['error' => 'Article title is required.'], 400);
        }
        if (empty($clean_excerpt)) {
            json_response(['error' => 'Article short excerpt/introduction is required.'], 400);
        }
        if (empty($clean_body) || strlen(strip_tags($clean_body)) < 20) {
            json_response(['error' => 'Article body content is required (minimum 20 characters).'], 400);
        }
        if (!empty($cover_image) && empty($clean_cover_alt)) {
            json_response(['error' => 'Please provide descriptive Alt Text for the cover image (for accessibility).'], 400);
        }

        $now = date('c');

        // Check if editing existing published article
        $published_at = $now;
        if (file_exists($article_file)) {
            $prev = json_decode(file_get_contents($article_file), true);
            if ($prev && isset($prev['published_at']) && ($input['is_edit'] ?? false)) {
                $published_at = $prev['published_at'];
            }
        }

        $article_data = [
            'id' => 'current-publication',
            'title' => $clean_title,
            'slug' => $slug,
            'excerpt' => $clean_excerpt,
            'body' => $clean_body,
            'category' => $clean_category,
            'author' => $clean_author,
            'cover_image' => $cover_image,
            'cover_alt' => $clean_cover_alt,
            'status' => 'published',
            'published_at' => $published_at,
            'updated_at' => $now,
            'reading_time' => $reading_time
        ];

        // Safe atomic write with backup
        if (file_exists($article_file)) {
            copy($article_file, $backup_file);
        }

        $tmp_file = $article_file . '.tmp';
        $json_str = json_encode($article_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($tmp_file, $json_str, LOCK_EX) !== false) {
            if (rename($tmp_file, $article_file)) {
                // Clean up draft if one was active
                if (file_exists($draft_file)) {
                    @unlink($draft_file);
                }
                json_response([
                    'success' => true,
                    'message' => 'Article published successfully! The live site has been updated.',
                    'article' => $article_data
                ]);
            }
        }

        // If atomic rename failed, restore backup
        if (file_exists($backup_file)) {
            copy($backup_file, $article_file);
        }

        json_response(['error' => 'Failed to save article safely. The existing publication was preserved.'], 500);
    }

    json_response(['error' => 'Invalid action.'], 400);
}

json_response(['error' => 'Method not allowed.'], 405);
