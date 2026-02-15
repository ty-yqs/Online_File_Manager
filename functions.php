<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function get_categories(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC');
    return $stmt->fetchAll();
}

function get_files(PDO $pdo, string $keyword = '', int $categoryId = 0): array
{
    $sql = 'SELECT f.id, f.original_name, f.file_ext, f.file_size, f.stored_path, f.uploaded_at,
                   u.username AS uploader_name, u.id AS uploader_id,
                   c.name AS category_name, c.id AS category_id
            FROM files f
            INNER JOIN users u ON u.id = f.uploader_id
            LEFT JOIN categories c ON c.id = f.category_id
            WHERE 1=1';

    $params = [];

    if ($keyword !== '') {
        $sql .= ' AND f.original_name LIKE :keyword';
        $params[':keyword'] = '%' . $keyword . '%';
    }

    if ($categoryId > 0) {
        $sql .= ' AND f.category_id = :category_id';
        $params[':category_id'] = $categoryId;
    }

    $sql .= ' ORDER BY f.uploaded_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function get_file_by_id(PDO $pdo, int $fileId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch();

    return $file ?: null;
}

function format_file_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $power = 0;

    while ($size >= 1024 && $power < count($units) - 1) {
        $size /= 1024;
        $power++;
    }

    return number_format($size, 2) . ' ' . $units[$power];
}

function ensure_storage_path(): array
{
    $year = date('Y');
    $month = date('m');
    $relativeDir = $year . '/' . $month;
    $absoluteDir = STORAGE_DIR . '/' . $relativeDir;

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('创建存储目录失败。');
    }

    return [$relativeDir, $absoluteDir];
}

function generate_stored_name(string $ext): string
{
    return sprintf('%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(4)), $ext);
}

function validate_uploaded_file(array $upload): array
{
    if (!isset($upload['error'], $upload['name'], $upload['tmp_name'], $upload['size'])) {
        return [false, '上传参数错误。', null, null];
    }

    if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        return [false, '文件上传失败，请重试。', null, null];
    }

    if ((int) $upload['size'] <= 0 || (int) $upload['size'] > MAX_FILE_SIZE) {
        return [false, '文件大小不合法，最大 20MB。', null, null];
    }

    $ext = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        return [false, '文件类型不允许。仅支持 pdf/docx/pptx/xlsx/zip。', null, null];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, (string) $upload['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowedMimes = ALLOWED_MIME_TYPES[$ext] ?? [];
    if (!in_array($mime, $allowedMimes, true)) {
        return [false, '文件内容与扩展名不匹配。', null, null];
    }

    return [true, '', $ext, (string) $mime];
}

function log_file_action(PDO $pdo, int $userId, int $fileId, string $action, string $detail = ''): void
{
    $stmt = $pdo->prepare('INSERT INTO file_logs (user_id, file_id, action, detail) VALUES (:user_id, :file_id, :action, :detail)');
    $stmt->execute([
        ':user_id' => $userId,
        ':file_id' => $fileId,
        ':action' => $action,
        ':detail' => $detail,
    ]);
}

function build_preview_signature(int $fileId, int $expires): string
{
    $payload = $fileId . '|' . $expires;
    return hash_hmac('sha256', $payload, DB_PASS . '|' . APP_NAME);
}

function verify_preview_signature(int $fileId, int $expires, string $signature): bool
{
    if ($expires < time() || $signature === '') {
        return false;
    }

    $expected = build_preview_signature($fileId, $expires);
    return hash_equals($expected, $signature);
}
