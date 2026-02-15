<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$fileId = (int) ($_GET['id'] ?? 0);
if ($fileId <= 0) {
    http_response_code(400);
    exit('Bad Request');
}

if (!is_logged_in()) {
    $expires = (int) ($_GET['expires'] ?? 0);
    $signature = (string) ($_GET['signature'] ?? '');
    if (!verify_preview_signature($fileId, $expires, $signature)) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

$pdo = db();
$file = get_file_by_id($pdo, $fileId);
if (!$file) {
    http_response_code(404);
    exit('File Not Found');
}

$ext = strtolower((string) ($file['file_ext'] ?? ''));
if (!in_array($ext, ['pdf', 'docx', 'pptx', 'xlsx'], true)) {
    http_response_code(415);
    exit('Preview Not Supported');
}

$fullPath = STORAGE_DIR . '/' . (string) $file['stored_path'];
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File Missing');
}

$mimeType = (string) ($file['mime_type'] ?? 'application/octet-stream');
$filename = (string) $file['original_name'];

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($filename));
header('Content-Length: ' . (string) filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

readfile($fullPath);
exit;
