<?php
/**
 * Green AVIC Content Studio — Secure Image Upload API
 * Saves images to public_html/newsletter/uploads/
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';

$pdo = get_db();
$user = require_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

// Verify CSRF
$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verify_csrf_token($csrf)) {
    json_response(['error' => 'Security token expired. Please refresh the page.'], 403);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $err_code = $_FILES['image']['error'] ?? 'missing';
    $msg = 'File upload failed.';
    if ($err_code === UPLOAD_ERR_INI_SIZE || $err_code === UPLOAD_ERR_FORM_SIZE) {
        $msg = 'Image exceeds server upload limit (maximum 5MB).';
    }
    json_response(['error' => $msg], 400);
}

$file = $_FILES['image'];

// Max 5MB
$max_size = 5 * 1024 * 1024;
if ($file['size'] > $max_size) {
    json_response(['error' => 'Image exceeds 5MB size limit.'], 400);
}

$original_name = strtolower($file['name']);
$ext = pathinfo($original_name, PATHINFO_EXTENSION);
$allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];

if (!in_array($ext, $allowed_exts, true)) {
    json_response(['error' => 'Invalid image format. Allowed formats: JPG, PNG, WebP.'], 400);
}

// MIME check
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed_mimes, true)) {
        json_response(['error' => 'Invalid image MIME signature.'], 400);
    }
}

// Magic bytes validation
$handle = fopen($file['tmp_name'], 'rb');
$bytes = fread($handle, 12);
fclose($handle);

$is_valid_magic = false;
if (strpos($bytes, "\xFF\xD8\xFF") === 0) {
    $is_valid_magic = true; // JPEG
    if ($ext === 'jpeg') $ext = 'jpg';
} elseif (strpos($bytes, "\x89PNG\r\n\x1a\n") === 0) {
    $is_valid_magic = true; // PNG
} elseif (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
    $is_valid_magic = true; // WebP
}

if (!$is_valid_magic) {
    json_response(['error' => 'File header does not match valid image signatures.'], 400);
}

// Destination directory in newsletter/uploads
$upload_dir = UPLOADS_DIR;
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

$safe_filename = 'cover_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$target_path = $upload_dir . '/' . $safe_filename;

if (move_uploaded_file($file['tmp_name'], $target_path)) {
    @chmod($target_path, 0644);
    $public_url = UPLOADS_URL . '/' . $safe_filename;

    json_response([
        'success'  => true,
        'url'      => $public_url,
        'filename' => $safe_filename
    ]);
} else {
    json_response(['error' => 'Failed to save image to uploads directory. Check directory permissions.'], 500);
}
