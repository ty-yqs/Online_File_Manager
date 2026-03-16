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
        ensure_recycle_bin_schema($pdo);
        ensure_file_share_schema($pdo);
        $schemaEnsured = true;
    }

    return $pdo;
}

function ensure_user_approval_schema(PDO $pdo): void
{
    if (schema_column_exists($pdo, 'users', 'is_approved')) {
        return;
    }

    $pdo->exec('ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER role');
    $pdo->exec('ALTER TABLE users ADD COLUMN approved_at DATETIME NULL DEFAULT NULL AFTER is_approved');
    $pdo->exec('UPDATE users SET is_approved = 1, approved_at = NOW()');
}

function ensure_recycle_bin_schema(PDO $pdo): void
{
    if (!schema_column_exists($pdo, 'files', 'deleted_at')) {
        $pdo->exec('ALTER TABLE files ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER uploaded_at');
    }

    if (!schema_column_exists($pdo, 'files', 'deleted_by')) {
        $pdo->exec('ALTER TABLE files ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL AFTER deleted_at');
    }

    if (!schema_index_exists($pdo, 'files', 'idx_files_deleted_at')) {
        $pdo->exec('ALTER TABLE files ADD KEY idx_files_deleted_at (deleted_at)');
    }

    if (!schema_index_exists($pdo, 'files', 'idx_files_deleted_by')) {
        $pdo->exec('ALTER TABLE files ADD KEY idx_files_deleted_by (deleted_by)');
    }

    if (!schema_foreign_key_exists($pdo, 'files', 'fk_files_deleted_by')) {
        $pdo->exec('ALTER TABLE files ADD CONSTRAINT fk_files_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL');
    }
}

function ensure_file_share_schema(PDO $pdo): void
{
    if (schema_table_exists($pdo, 'file_shares')) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS file_shares (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_id INT UNSIGNED NOT NULL,
            creator_id INT UNSIGNED NOT NULL,
            share_token CHAR(32) NOT NULL,
            password_hash VARCHAR(255) NULL DEFAULT NULL,
            expires_at DATETIME NULL DEFAULT NULL,
            max_downloads INT UNSIGNED NULL DEFAULT NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_file_shares_token (share_token),
            KEY idx_file_shares_file (file_id),
            KEY idx_file_shares_creator (creator_id),
            KEY idx_file_shares_expires (expires_at),
            CONSTRAINT fk_file_shares_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_shares_creator FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function schema_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name');
    $stmt->execute([
        ':schema_name' => DB_NAME,
        ':table_name' => $tableName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function schema_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
    $stmt->execute([
        ':schema_name' => DB_NAME,
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function schema_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name');
    $stmt->execute([
        ':schema_name' => DB_NAME,
        ':table_name' => $tableName,
        ':index_name' => $indexName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function schema_foreign_key_exists(PDO $pdo, string $tableName, string $constraintName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = :schema_name AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = :constraint_name');
    $stmt->execute([
        ':schema_name' => DB_NAME,
        ':table_name' => $tableName,
        ':constraint_name' => $constraintName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
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
