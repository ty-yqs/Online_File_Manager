<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('APP_NAME', '共享文件管理系统');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'DB_NAME');
define('DB_USER', 'DB_USER');
define('DB_PASS', 'DB_PASS');
define('MAX_FILE_SIZE', 20 * 1024 * 1024);
define('STORAGE_DIR', __DIR__ . '/storage');

define('ALLOWED_EXTENSIONS', ['pdf', 'docx', 'pptx', 'xlsx', 'zip']);
define('ALLOWED_MIME_TYPES', [
    'pdf' => ['application/pdf'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
    ],
    'pptx' => [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip',
    ],
    'xlsx' => [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ],
    'zip' => [
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
    ],
]);

function db(): PDO
{
    static $pdo = null;
    static $schemaEnsured = false;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    if (!$schemaEnsured) {
        ensure_user_approval_schema($pdo);
        $schemaEnsured = true;
    }

    return $pdo;
}

function ensure_user_approval_schema(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
    $stmt->execute([
        ':schema_name' => DB_NAME,
        ':table_name' => 'users',
        ':column_name' => 'is_approved',
    ]);

    $exists = (int) $stmt->fetchColumn() > 0;
    if ($exists) {
        return;
    }

    $pdo->exec('ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER role');
    $pdo->exec('ALTER TABLE users ADD COLUMN approved_at DATETIME NULL DEFAULT NULL AFTER is_approved');
    $pdo->exec('UPDATE users SET is_approved = 1, approved_at = NOW()');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}
