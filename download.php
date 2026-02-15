<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();

$fileId = (int) ($_GET['id'] ?? 0);
if ($fileId <= 0) {
    http_response_code(400);
    exit('Bad Request');
}

$pdo = db();
$file = get_file_by_id($pdo, $fileId);
if (!$file) {
    http_response_code(404);
    exit('File Not Found');
}

$fullPath = STORAGE_DIR . '/' . (string) $file['stored_path'];
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File Missing');
}

log_file_action($pdo, (int) current_user()['id'], $fileId, 'download', '下载文件：' . (string) $file['original_name']);

header('Content-Description: File Transfer');
header('Content-Type: ' . (string) $file['mime_type']);
header('Content-Disposition: attachment; filename="' . rawurlencode((string) $file['original_name']) . '"');
header('Content-Length: ' . (string) filesize($fullPath));
header('Pragma: public');
header('Cache-Control: must-revalidate');

readfile($fullPath);
exit;
