<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    set_flash('danger', '非法请求，请刷新后重试。');
    redirect('index.php');
}

$fileId = (int) ($_POST['id'] ?? 0);
if ($fileId <= 0) {
    set_flash('danger', '参数错误。');
    redirect('index.php');
}

$pdo = db();
$currentUser = current_user();
$file = get_file_by_id($pdo, $fileId);
if (!$file) {
    set_flash('danger', '文件不存在。');
    redirect('index.php');
}

$canDelete = is_admin() || (int) $file['uploader_id'] === (int) ($currentUser['id'] ?? 0);
if (!$canDelete) {
    set_flash('danger', '无权限删除该文件。');
    redirect('index.php');
}

$fullPath = STORAGE_DIR . '/' . (string) $file['stored_path'];

$pdo->beginTransaction();
try {
    log_file_action($pdo, (int) ($currentUser['id'] ?? 0), $fileId, 'delete', '删除文件：' . (string) $file['original_name']);

    $stmt = $pdo->prepare('DELETE FROM files WHERE id = :id');
    $stmt->execute([':id' => $fileId]);

    $pdo->commit();

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }

    set_flash('success', '文件已删除。');
} catch (Throwable $e) {
    $pdo->rollBack();
    set_flash('danger', '删除失败：' . $e->getMessage());
}

redirect('index.php');
