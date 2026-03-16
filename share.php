<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'create_share') {
    require_login();

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('danger', '非法请求，请刷新后重试。');
        redirect('index.php');
    }

    $fileId = (int) ($_POST['file_id'] ?? 0);
    $expiresAtInput = trim((string) ($_POST['expires_at'] ?? ''));
    $password = trim((string) ($_POST['share_password'] ?? ''));
    $maxDownloadsInput = trim((string) ($_POST['max_downloads'] ?? ''));

    if ($fileId <= 0) {
        set_flash('danger', '参数错误。');
        redirect('index.php');
    }

    $file = get_file_by_id($pdo, $fileId);
    if (!$file) {
        set_flash('danger', '文件不存在。');
        redirect('index.php');
    }

    $expiresAt = null;
    if ($expiresAtInput !== '') {
        $timestamp = strtotime($expiresAtInput);
        if ($timestamp === false || $timestamp <= time()) {
            set_flash('danger', '有效期必须是未来时间。');
            redirect('index.php');
        }
        $expiresAt = date('Y-m-d H:i:s', $timestamp);
    }

    $maxDownloads = null;
    if ($maxDownloadsInput !== '') {
        if (!ctype_digit($maxDownloadsInput) || (int) $maxDownloadsInput <= 0) {
            set_flash('danger', '下载次数必须是大于 0 的整数。');
            redirect('index.php');
        }
        $maxDownloads = (int) $maxDownloadsInput;
    }

    if ($password !== '' && mb_strlen($password) > 64) {
        set_flash('danger', '分享密码长度不能超过 64 个字符。');
        redirect('index.php');
    }

    $creatorId = (int) (current_user()['id'] ?? 0);

    try {
        $token = create_file_share($pdo, $fileId, $creatorId, $expiresAt, $password !== '' ? $password : null, $maxDownloads);
    } catch (Throwable $e) {
        set_flash('danger', '创建分享失败：' . $e->getMessage());
        redirect('index.php');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $pathPrefix = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/share.php')));
    $pathPrefix = $pathPrefix === '/' ? '' : rtrim($pathPrefix, '/');

    $relativeUrl = $pathPrefix . '/share.php?token=' . rawurlencode($token);
    $shareUrl = $host !== '' ? $scheme . '://' . $host . $relativeUrl : $relativeUrl;

    $message = '分享链接已创建：' . $shareUrl;
    if ($password !== '') {
        $message .= '（已设置密码）';
    }
    if ($maxDownloads !== null) {
        $message .= '（最多下载 ' . $maxDownloads . ' 次）';
    }

    set_flash('success', $message);
    redirect('index.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('Bad Request');
}

$share = get_share_by_token($pdo, $token);
if (!$share || (int) ($share['is_active'] ?? 0) !== 1) {
    http_response_code(404);
    exit('Share Not Found');
}

if (!empty($share['deleted_at'])) {
    http_response_code(404);
    exit('File Not Found');
}

$isExpired = !empty($share['expires_at']) && strtotime((string) $share['expires_at']) < time();
$isLimitReached = $share['max_downloads'] !== null && (int) $share['download_count'] >= (int) $share['max_downloads'];

$downloadError = '';
$requiresPassword = !empty($share['password_hash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'download') {
    if ($isExpired) {
        $downloadError = '分享链接已过期。';
    } elseif ($isLimitReached) {
        $downloadError = '分享链接下载次数已用完。';
    } else {
        $inputPassword = (string) ($_POST['password'] ?? '');

        $pdo->beginTransaction();
        try {
            $lockStmt = $pdo->prepare(
                'SELECT s.id, s.password_hash, s.expires_at, s.max_downloads, s.download_count, s.is_active,
                        f.original_name, f.stored_path, f.mime_type, f.deleted_at
                 FROM file_shares s
                 INNER JOIN files f ON f.id = s.file_id
                 WHERE s.share_token = :token
                 LIMIT 1
                 FOR UPDATE'
            );
            $lockStmt->execute([':token' => $token]);
            $lockedShare = $lockStmt->fetch();

            if (!$lockedShare || (int) $lockedShare['is_active'] !== 1 || !empty($lockedShare['deleted_at'])) {
                throw new RuntimeException('分享链接不可用。');
            }

            if (!empty($lockedShare['expires_at']) && strtotime((string) $lockedShare['expires_at']) < time()) {
                throw new RuntimeException('分享链接已过期。');
            }

            if ($lockedShare['max_downloads'] !== null && (int) $lockedShare['download_count'] >= (int) $lockedShare['max_downloads']) {
                throw new RuntimeException('分享链接下载次数已用完。');
            }

            if (!empty($lockedShare['password_hash']) && !password_verify($inputPassword, (string) $lockedShare['password_hash'])) {
                throw new RuntimeException('分享密码错误。');
            }

            $fullPath = STORAGE_DIR . '/' . (string) $lockedShare['stored_path'];
            if (!is_file($fullPath)) {
                throw new RuntimeException('文件不存在。');
            }

            $updateStmt = $pdo->prepare('UPDATE file_shares SET download_count = download_count + 1, updated_at = NOW() WHERE id = :id');
            $updateStmt->execute([':id' => (int) $lockedShare['id']]);

            $pdo->commit();

            header('Content-Description: File Transfer');
            header('Content-Type: ' . (string) $lockedShare['mime_type']);
            header('Content-Disposition: attachment; filename="' . rawurlencode((string) $lockedShare['original_name']) . '"');
            header('Content-Length: ' . (string) filesize($fullPath));
            header('Pragma: public');
            header('Cache-Control: must-revalidate');

            readfile($fullPath);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $downloadError = $e->getMessage();
        }
    }
}

$remainingDownloads = null;
if ($share['max_downloads'] !== null) {
    $remainingDownloads = max(0, (int) $share['max_downloads'] - (int) $share['download_count']);
}

require __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 card-shadow">
            <div class="card-body p-4">
                <h4 class="mb-3">文件分享</h4>

                <?php if ($isExpired): ?>
                    <div class="alert alert-danger">该分享链接已过期。</div>
                <?php elseif ($isLimitReached): ?>
                    <div class="alert alert-danger">该分享链接下载次数已用完。</div>
                <?php else: ?>
                    <?php if ($downloadError !== ''): ?>
                        <div class="alert alert-danger"><?= e($downloadError) ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <ul class="list-group list-group-flush mb-3">
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">文件名</span>
                        <span><?= e((string) $share['original_name']) ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">文件大小</span>
                        <span><?= e(format_file_size((int) $share['file_size'])) ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">有效期</span>
                        <span><?= $share['expires_at'] ? e((string) $share['expires_at']) : '永久有效' ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">下载次数</span>
                        <span>
                            <?php if ($share['max_downloads'] === null): ?>
                                不限次数
                            <?php else: ?>
                                <?= (int) $share['download_count'] ?> / <?= (int) $share['max_downloads'] ?>
                                （剩余 <?= (int) $remainingDownloads ?> 次）
                            <?php endif; ?>
                        </span>
                    </li>
                </ul>

                <?php if (!$isExpired && !$isLimitReached): ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="download">
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <?php if ($requiresPassword): ?>
                            <div class="col-12">
                                <label class="form-label">分享密码</label>
                                <input type="password" name="password" class="form-control" placeholder="请输入分享密码" required>
                            </div>
                        <?php endif; ?>
                        <div class="col-12 d-grid d-sm-block">
                            <button class="btn btn-primary" type="submit">下载文件</button>
                            <a class="btn btn-outline-secondary" href="index.php">返回首页</a>
                        </div>
                    </form>
                <?php else: ?>
                    <a class="btn btn-outline-secondary" href="index.php">返回首页</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php';
