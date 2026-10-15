<?php
/**
 * Green AVIC Content Studio — Admin Article Management API (MariaDB)
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';

$pdo = get_db();
ensure_tables_exist($pdo);

$user = require_auth($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// ==========================================================
// GET: Fetch Admin Article Status (Published + Draft)
// ==========================================================
if ($method === 'GET') {
    try {
        $pubStmt = $pdo->query("SELECT * FROM articles WHERE status = 'published' ORDER BY published_at DESC LIMIT 1");
        $published = $pubStmt->fetch() ?: null;
        if ($published) {
            $published['cover_image'] = normalize_image_url($published['cover_image'] ?? null);
        }

        $draftStmt = $pdo->query("SELECT * FROM drafts WHERE id = 1 LIMIT 1");
        $draftRow = $draftStmt->fetch() ?: null;

        // Only return draft if it has actual content
        $draft = null;
        if ($draftRow && (!empty($draftRow['title']) || !empty($draftRow['body']))) {
            $draft = $draftRow;
            if (!empty($draft['cover_image'])) {
                $draft['cover_image'] = normalize_image_url($draft['cover_image']);
            }
        }

        json_response([
            'published' => $published,
            'draft'     => $draft
        ]);
    } catch (Exception $e) {
        AppLogger::error('article', 'Admin article fetch error: ' . $e->getMessage());
        json_response(['error' => 'Unable to retrieve publication records.'], 500);
    }
}

// ==========================================================
// POST: Save Draft or Atomically Publish
// ==========================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';

    // Verify CSRF Token
    $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verify_csrf_token($csrf)) {
        json_response(['error' => 'Security token expired. Please refresh the page.'], 403);
    }

    $title       = trim($input['title'] ?? '');
    $excerpt     = trim($input['excerpt'] ?? '');
    $body        = trim($input['body'] ?? '');
    $category    = trim($input['category'] ?? 'Sustainability & Eco-Impact');
    $author      = trim($input['author'] ?? 'Green AVIC');
    if (empty($author)) $author = 'Green AVIC';
    $cover_image = trim($input['cover_image'] ?? '');
    $cover_alt   = trim($input['cover_alt'] ?? '');

    // Sanitization
    $clean_body      = sanitize_article_html($body);
    $clean_title     = strip_tags($title);
    $clean_excerpt   = strip_tags($excerpt);
    $clean_category  = strip_tags($category);
    $clean_author    = strip_tags($author);
    $clean_cover_alt = strip_tags($cover_alt);

    // Calculate reading time
    $words = str_word_count(strip_tags($clean_body));
    $reading_time = max(1, (int)ceil($words / 200));

    // Generate slug
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
    if (empty($slug)) $slug = 'latest-publication';

    $now = date('Y-m-d H:i:s');

    // ------------------------------------------------------
    // ACTION: Save Draft
    // ------------------------------------------------------
    if ($action === 'save_draft') {
        if (empty($clean_title) && empty($clean_body)) {
            json_response(['error' => 'Draft must contain at least a title or body text.'], 400);
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO drafts (id, title, slug, excerpt, body, category, author, cover_image, cover_alt, reading_time, updated_at)
                VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    title = VALUES(title),
                    slug = VALUES(slug),
                    excerpt = VALUES(excerpt),
                    body = VALUES(body),
                    category = VALUES(category),
                    author = VALUES(author),
                    cover_image = VALUES(cover_image),
                    cover_alt = VALUES(cover_alt),
                    reading_time = VALUES(reading_time),
                    updated_at = VALUES(updated_at)
            ");
            $stmt->execute([
                $clean_title, $slug, $clean_excerpt, $clean_body,
                $clean_category, $clean_author, $cover_image, $clean_cover_alt,
                $reading_time, $now
            ]);

            json_response([
                'success' => true,
                'message' => 'Draft saved successfully.',
                'draft'   => [
                    'title'        => $clean_title,
                    'slug'         => $slug,
                    'excerpt'      => $clean_excerpt,
                    'body'         => $clean_body,
                    'category'     => $clean_category,
                    'author'       => $clean_author,
                    'cover_image'  => $cover_image,
                    'cover_alt'    => $clean_cover_alt,
                    'reading_time' => $reading_time,
                    'updated_at'   => $now
                ]
            ]);
        } catch (Exception $e) {
            error_log("Save draft error: " . $e->getMessage());
            json_response(['error' => 'Database error while saving draft.'], 500);
        }
    }

    // ------------------------------------------------------
    // ACTION: Publish (Atomic Replacement)
    // ------------------------------------------------------
    if ($action === 'publish') {
        if (empty($clean_title)) {
            json_response(['error' => 'Article headline is required.'], 400);
        }
        if (empty($clean_excerpt)) {
            json_response(['error' => 'Article introduction/excerpt is required.'], 400);
        }
        if (empty($clean_body) || strlen(strip_tags($clean_body)) < 20) {
            json_response(['error' => 'Article body content is required (minimum 20 characters).'], 400);
        }
        if (!empty($cover_image) && empty($clean_cover_alt)) {
            json_response(['error' => 'Please provide descriptive Alt Text for the cover image.'], 400);
        }

        $is_edit = !empty($input['is_edit']);

        try {
            // Begin MariaDB transaction to guarantee atomic replacement
            $pdo->beginTransaction();

            $published_at = $now;
            if ($is_edit) {
                $prevStmt = $pdo->query("SELECT published_at FROM articles WHERE status = 'published' LIMIT 1");
                $prev = $prevStmt->fetch();
                if ($prev && !empty($prev['published_at'])) {
                    $published_at = $prev['published_at'];
                }
            }

            // Replace existing publication
            $pdo->exec("DELETE FROM articles");

            $insStmt = $pdo->prepare("
                INSERT INTO articles (title, slug, excerpt, body, category, author, cover_image, cover_alt, status, reading_time, published_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'published', ?, ?, ?)
            ");
            $insStmt->execute([
                $clean_title, $slug, $clean_excerpt, $clean_body,
                $clean_category, $clean_author, $cover_image, $clean_cover_alt,
                $reading_time, $published_at, $now
            ]);

            // Clear draft
            $pdo->exec("DELETE FROM drafts WHERE id = 1");

            $articleId = (int)$pdo->lastInsertId();
            $pdo->commit();

            $articleData = [
                'id'           => $articleId,
                'title'        => $clean_title,
                'slug'         => $slug,
                'excerpt'      => $clean_excerpt,
                'body'         => $clean_body,
                'category'     => $clean_category,
                'author'       => $clean_author,
                'cover_image'  => $cover_image,
                'cover_alt'    => $clean_cover_alt,
                'status'       => 'published',
                'reading_time' => $reading_time,
                'published_at' => $published_at,
                'updated_at'   => $now
            ];

            // ------------------------------------------------------
            // Automatic Email Campaign Creation & Batch Dispatch
            // ------------------------------------------------------
            $emailDelivery = null;
            try {
                require_once dirname(__DIR__, 2) . '/cms-core/newsletter_service.php';
                $emailDelivery = create_newsletter_campaign($pdo, $articleId, $articleData);
            } catch (Exception $emailEx) {
                error_log("Newsletter auto-delivery error: " . $emailEx->getMessage());
                $emailDelivery = [
                    'attempted' => false,
                    'error'     => 'Email delivery system encountered an error: ' . $emailEx->getMessage()
                ];
            }

            json_response([
                'success'        => true,
                'message'        => 'Article published successfully! Live newsletter is updated.',
                'article'        => $articleData,
                'email_delivery' => $emailDelivery
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Publish error: " . $e->getMessage());
            json_response(['error' => 'Failed to publish article. Existing publication preserved.'], 500);
        }
    }

    json_response(['error' => 'Invalid action.'], 400);
}

json_response(['error' => 'Method not allowed.'], 405);
