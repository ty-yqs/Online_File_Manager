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
            WHERE f.deleted_at IS NULL';

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
    $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch();

    return $file ?: null;
}

function get_file_by_id_with_deleted(PDO $pdo, int $fileId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch();

    return $file ?: null;
}

function get_recycle_bin_files(PDO $pdo, string $keyword = ''): array
{
    $sql = 'SELECT f.id, f.original_name, f.file_ext, f.file_size, f.stored_path, f.uploaded_at,
                   f.deleted_at, u.username AS uploader_name,
                   d.username AS deleted_by_name
            FROM files f
            INNER JOIN users u ON u.id = f.uploader_id
            LEFT JOIN users d ON d.id = f.deleted_by
            WHERE f.deleted_at IS NOT NULL';

    $params = [];

    if ($keyword !== '') {
        $sql .= ' AND f.original_name LIKE :keyword';
        $params[':keyword'] = '%' . $keyword . '%';
    }

    $sql .= ' ORDER BY f.deleted_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function generate_share_token(PDO $pdo): string
{
    for ($i = 0; $i < 8; $i++) {
        $token = bin2hex(random_bytes(16));

        $stmt = $pdo->prepare('SELECT id FROM file_shares WHERE share_token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);

        if (!$stmt->fetch()) {
            return $token;
        }
    }

    throw new RuntimeException('生成分享链接失败，请重试。');
}

function create_file_share(
    PDO $pdo,
    int $fileId,
    int $creatorId,
    ?string $expiresAt,
    ?string $password,
    ?int $maxDownloads
): string {
    $token = generate_share_token($pdo);
    $passwordHash = ($password !== null && $password !== '') ? password_hash($password, PASSWORD_DEFAULT) : null;

    $stmt = $pdo->prepare(
        'INSERT INTO file_shares (file_id, creator_id, share_token, password_hash, expires_at, max_downloads)
         VALUES (:file_id, :creator_id, :share_token, :password_hash, :expires_at, :max_downloads)'
    );

    $stmt->execute([
        ':file_id' => $fileId,
        ':creator_id' => $creatorId,
        ':share_token' => $token,
        ':password_hash' => $passwordHash,
        ':expires_at' => $expiresAt,
        ':max_downloads' => $maxDownloads,
    ]);

    return $token;
}

function get_share_by_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.file_id, s.creator_id, s.share_token, s.password_hash, s.expires_at,
                s.max_downloads, s.download_count, s.is_active, s.created_at,
                f.original_name, f.stored_path, f.mime_type, f.file_size, f.deleted_at
         FROM file_shares s
         INNER JOIN files f ON f.id = s.file_id
         WHERE s.share_token = :token
         LIMIT 1'
    );
    $stmt->execute([':token' => $token]);

    $share = $stmt->fetch();
    return $share ?: null;
}

function get_share_by_id(PDO $pdo, int $shareId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.file_id, s.creator_id, s.share_token, s.password_hash, s.expires_at,
                s.max_downloads, s.download_count, s.is_active, s.created_at,
                f.original_name, f.deleted_at,
                u.username AS creator_name
         FROM file_shares s
         INNER JOIN files f ON f.id = s.file_id
         INNER JOIN users u ON u.id = s.creator_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $shareId]);

    $share = $stmt->fetch();
    return $share ?: null;
}

function get_admin_shares(PDO $pdo, string $keyword = '', string $status = 'all'): array
{
    $sql = 'SELECT s.id, s.file_id, s.creator_id, s.share_token, s.expires_at,
                   s.max_downloads, s.download_count, s.is_active, s.created_at,
                   f.original_name, f.deleted_at,
                   u.username AS creator_name
            FROM file_shares s
            INNER JOIN files f ON f.id = s.file_id
            INNER JOIN users u ON u.id = s.creator_id
            WHERE 1=1';

    $params = [];

    if ($keyword !== '') {
        $sql .= ' AND (f.original_name LIKE :keyword OR s.share_token LIKE :keyword OR u.username LIKE :keyword)';
        $params[':keyword'] = '%' . $keyword . '%';
    }

    if ($status === 'active') {
        $sql .= ' AND s.is_active = 1';
    } elseif ($status === 'inactive') {
        $sql .= ' AND s.is_active = 0';
    }

    $sql .= ' ORDER BY s.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
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
