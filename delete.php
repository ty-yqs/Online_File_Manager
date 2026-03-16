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

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE files SET deleted_at = NOW(), deleted_by = :deleted_by, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([
        ':id' => $fileId,
        ':deleted_by' => (int) ($currentUser['id'] ?? 0),
    ]);

    if ((int) $stmt->rowCount() === 0) {
        throw new RuntimeException('文件不存在或已在回收站中。');
    }

    log_file_action($pdo, (int) ($currentUser['id'] ?? 0), $fileId, 'delete', '移入回收站：' . (string) $file['original_name']);

    $pdo->commit();
    set_flash('success', '文件已移入回收站。');
} catch (Throwable $e) {
    $pdo->rollBack();
    set_flash('danger', '删除失败：' . $e->getMessage());
}

redirect('index.php');
