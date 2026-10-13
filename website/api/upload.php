<?php
/**
 * Green AVIC CMS - Secure Cover Image Upload API
 */

require_once __DIR__ . '/common.php';

$session = require_auth($config);

// Verify CSRF
$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals($session['csrf'], $csrf)) {
    json_response(['error' => 'Security token expired. Please refresh the page.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $err_code = $_FILES['image']['error'] ?? 'missing';
    $msg = 'File upload failed.';
    if ($err_code === UPLOAD_ERR_INI_SIZE || $err_code === UPLOAD_ERR_FORM_SIZE) {
        $msg = 'File size exceeds server upload limit (maximum 5MB).';
    }
    json_response(['error' => $msg], 400);
}

$file = $_FILES['image'];

// Max size 5MB
$max_size = 5 * 1024 * 1024;
if ($file['size'] > $max_size) {
    json_response(['error' => 'Image exceeds the maximum allowed size of 5MB.'], 400);
}

// Check extension
$original_name = strtolower($file['name']);
$ext = pathinfo($original_name, PATHINFO_EXTENSION);
$allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];

if (!in_array($ext, $allowed_exts, true)) {
    json_response(['error' => 'Invalid image format. Allowed formats: JPG, JPEG, PNG, WebP.'], 400);
}

// Check MIME type via finfo if available
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed_mimes, true)) {
        json_response(['error' => 'Invalid image MIME type.'], 400);
    }
}

// Verify magic bytes
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
    json_response(['error' => 'Uploaded file header does not match valid image signatures.'], 400);
}

// Generate secure random filename
$upload_dir = __DIR__ . '/../assets/images/uploads';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$safe_filename = 'cover_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$target_path = $upload_dir . '/' . $safe_filename;

if (move_uploaded_file($file['tmp_name'], $target_path)) {
    chmod($target_path, 0644);
    $relative_url = 'assets/images/uploads/' . $safe_filename;

    json_response([
        'success' => true,
        'url' => $relative_url,
        'filename' => $safe_filename
    ]);
} else {
    json_response(['error' => 'Failed to save uploaded image to destination folder.'], 500);
}
