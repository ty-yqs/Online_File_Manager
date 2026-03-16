<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('danger', '非法请求，请刷新后重试。');
        redirect('share_manage.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $shareId = (int) ($_POST['share_id'] ?? 0);

    if ($shareId <= 0) {
        set_flash('danger', '参数错误。');
        redirect('share_manage.php');
    }

    $share = get_share_by_id($pdo, $shareId);
    if (!$share) {
        set_flash('danger', '分享记录不存在。');
        redirect('share_manage.php');
    }

    if ($action === 'disable_share') {
        if ((int) $share['is_active'] === 0) {
            set_flash('warning', '该分享链接已失效。');
            redirect('share_manage.php');
        }

        $stmt = $pdo->prepare('UPDATE file_shares SET is_active = 0, updated_at = NOW() WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $shareId]);

        if ((int) $stmt->rowCount() === 0) {
            set_flash('danger', '失效操作未生效，请刷新后重试。');
        } else {
            set_flash('success', '分享链接已手动失效。');
        }

        redirect('share_manage.php');
    }

    if ($action === 'reset_download_count') {
        $stmt = $pdo->prepare('UPDATE file_shares SET download_count = 0, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $shareId]);

        if ((int) $stmt->rowCount() === 0) {
            set_flash('warning', '下载次数本来就是 0。');
        } else {
            set_flash('success', '下载次数已重置。');
        }

        redirect('share_manage.php');
    }

    set_flash('danger', '不支持的操作。');
    redirect('share_manage.php');
}

$keyword = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'active', 'inactive'], true)) {
    $status = 'all';
}

$shares = get_admin_shares($pdo, $keyword, $status);
$totalShares = (int) $pdo->query('SELECT COUNT(*) FROM file_shares')->fetchColumn();
$activeShares = (int) $pdo->query('SELECT COUNT(*) FROM file_shares WHERE is_active = 1')->fetchColumn();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$pathPrefix = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/share_manage.php')));
$pathPrefix = $pathPrefix === '/' ? '' : rtrim($pathPrefix, '/');

require __DIR__ . '/partials/header.php';
?>
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card border-0 card-shadow h-100">
            <div class="card-body">
                <h6 class="text-muted">分享总数</h6>
                <h2 class="mb-0"><?= $totalShares ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 card-shadow h-100">
            <div class="card-body">
                <h6 class="text-muted">有效分享数</h6>
                <h2 class="mb-0"><?= $activeShares ?></h2>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 card-shadow">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="mb-0">分享管理</h5>
            <small class="text-muted">仅管理员可进入</small>
        </div>

        <form class="row g-2 mb-3" method="get">
            <div class="col-md-6">
                <input type="text" name="q" value="<?= e($keyword) ?>" class="form-control" placeholder="搜索文件名 / Token / 创建者">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>全部状态</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>仅有效</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>仅失效</option>
                </select>
            </div>
            <div class="col-md-3 d-grid">
                <button type="submit" class="btn btn-primary">查询</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>文件名</th>
                    <th>创建者</th>
                    <th>分享链接</th>
                    <th>有效期</th>
                    <th>下载次数</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th style="width: 220px;">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$shares): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">暂无分享记录</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($shares as $share): ?>
                        <?php
                        $shareRelativeUrl = $pathPrefix . '/share.php?token=' . rawurlencode((string) $share['share_token']);
                        $shareUrl = $host !== '' ? $scheme . '://' . $host . $shareRelativeUrl : $shareRelativeUrl;
                        $isFileDeleted = !empty($share['deleted_at']);
                        ?>
                        <tr>
                            <td><?= (int) $share['id'] ?></td>
                            <td>
                                <?= e((string) $share['original_name']) ?>
                                <?php if ($isFileDeleted): ?>
                                    <span class="badge text-bg-warning ms-1">文件已删除</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $share['creator_name']) ?></td>
                            <td>
                                <a href="<?= e($shareRelativeUrl) ?>" target="_blank" rel="noopener">打开链接</a>
                                <div class="text-muted small text-break"><?= e($shareUrl) ?></div>
                            </td>
                            <td><?= $share['expires_at'] ? e((string) $share['expires_at']) : '永久有效' ?></td>
                            <td>
                                <?php if ($share['max_downloads'] === null): ?>
                                    <?= (int) $share['download_count'] ?> / 不限
                                <?php else: ?>
                                    <?= (int) $share['download_count'] ?> / <?= (int) $share['max_downloads'] ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $share['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">有效</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">失效</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $share['created_at']) ?></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if ((int) $share['is_active'] === 1): ?>
                                        <form method="post" onsubmit="return confirm('确认将该分享链接设为失效？');">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="disable_share">
                                            <input type="hidden" name="share_id" value="<?= (int) $share['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">手动失效</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="reset_download_count">
                                        <input type="hidden" name="share_id" value="<?= (int) $share['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">重置次数</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php';
