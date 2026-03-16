<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();

$pdo = db();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('danger', '非法请求，请刷新后重试。');
        redirect('index.php');
    }

    $fileId = (int) ($_POST['file_id'] ?? 0);
    $newName = trim((string) ($_POST['new_name'] ?? ''));

    if ($fileId <= 0 || $newName === '') {
        set_flash('danger', '参数错误。');
        redirect('index.php');
    }

    $file = get_file_by_id($pdo, $fileId);
    if (!$file) {
        set_flash('danger', '文件不存在。');
        redirect('index.php');
    }

    $canRename = is_admin() || (int) $file['uploader_id'] === (int) ($user['id'] ?? 0);
    if (!$canRename) {
        set_flash('danger', '无权限重命名该文件。');
        redirect('index.php');
    }

    if (!preg_match('/^[^\\\\\/\:*?"<>|]{1,255}$/u', $newName)) {
        set_flash('danger', '文件名包含非法字符。');
        redirect('index.php');
    }

    $stmt = $pdo->prepare('UPDATE files SET original_name = :name, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':name' => $newName, ':id' => $fileId]);

    log_file_action($pdo, (int) $user['id'], $fileId, 'rename', '重命名为：' . $newName);
    set_flash('success', '重命名成功。');
    redirect('index.php');
}

$keyword = trim((string) ($_GET['q'] ?? ''));
$categoryId = (int) ($_GET['category_id'] ?? 0);

$categories = get_categories($pdo);
$files = get_files($pdo, $keyword, $categoryId);

require __DIR__ . '/partials/header.php';
?>
<div class="card card-shadow border-0">
    <div class="card-body">
        <form class="row g-2 mb-3" method="get">
            <div class="col-md-5">
                <input type="text" name="q" value="<?= e($keyword) ?>" class="form-control" placeholder="搜索文件名">
            </div>
            <div class="col-md-4">
                <select name="category_id" class="form-select">
                    <option value="0">全部分类</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= (int) $category['id'] === $categoryId ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
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
                    <th>文件名</th>
                    <th>分类</th>
                    <th>大小</th>
                    <th>上传者</th>
                    <th>上传时间</th>
                    <th style="width: 420px;">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$files): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">暂无文件</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($files as $file): ?>
                        <?php
                        $fileExt = strtolower((string) $file['file_ext']);
                        $previewUrl = 'preview.php?id=' . (int) $file['id'];
                        if (in_array($fileExt, ['docx', 'pptx', 'xlsx'], true)) {
                            $expires = time() + 600;
                            $signature = build_preview_signature((int) $file['id'], $expires);
                            $previewUrl .= '&expires=' . $expires . '&signature=' . rawurlencode($signature);
                        }
                        ?>
                        <tr>
                            <td><?= e($file['original_name']) ?></td>
                            <td><?= e((string) ($file['category_name'] ?? '未分类')) ?></td>
                            <td><?= e(format_file_size((int) $file['file_size'])) ?></td>
                            <td><?= e($file['uploader_name']) ?></td>
                            <td><?= e($file['uploaded_at']) ?></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-info js-preview-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#previewModal"
                                        data-file-id="<?= (int) $file['id'] ?>"
                                        data-file-ext="<?= e((string) $file['file_ext']) ?>"
                                        data-file-name="<?= e($file['original_name']) ?>"
                                        data-preview-url="<?= e($previewUrl) ?>"
                                    >预览</button>
                                    <a class="btn btn-sm btn-outline-primary" href="download.php?id=<?= (int) $file['id'] ?>">下载</a>
                                    <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="collapse" data-bs-target="#share-<?= (int) $file['id'] ?>">分享</button>
                                    <?php if (is_admin() || (int) $file['uploader_id'] === (int) $user['id']): ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#rename-<?= (int) $file['id'] ?>">重命名</button>
                                    <?php endif; ?>
                                    <?php if (is_admin() || (int) $file['uploader_id'] === (int) $user['id']): ?>
                                        <form method="post" action="delete.php" onsubmit="return confirm('确认删除该文件？');">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">删除</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if (is_admin() || (int) $file['uploader_id'] === (int) $user['id']): ?>
                                    <div class="collapse mt-2" id="rename-<?= (int) $file['id'] ?>">
                                        <form method="post" class="d-flex gap-2">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="rename">
                                            <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                                            <input type="text" class="form-control form-control-sm" name="new_name" value="<?= e($file['original_name']) ?>" required>
                                            <button class="btn btn-sm btn-secondary" type="submit">保存</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                                <div class="collapse mt-2" id="share-<?= (int) $file['id'] ?>">
                                    <form method="post" action="share.php" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="create_share">
                                        <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                                        <div class="col-md-4">
                                            <input type="datetime-local" class="form-control form-control-sm" name="expires_at" title="有效期，可选">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="password" class="form-control form-control-sm" name="share_password" maxlength="64" placeholder="分享密码(可选)">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" class="form-control form-control-sm" name="max_downloads" min="1" placeholder="次数上限(可选)">
                                        </div>
                                        <div class="col-md-2 d-grid">
                                            <button class="btn btn-sm btn-success" type="submit">生成链接</button>
                                        </div>
                                        <div class="col-12">
                                            <small class="text-muted">留空表示永久有效、无密码、下载次数不限。</small>
                                        </div>
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

<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewModalTitle">在线预览</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" style="height: 75vh;">
                <iframe id="previewFrame" title="文件预览" class="w-100 h-100 border-0" src="about:blank"></iframe>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <small class="text-muted" id="previewHint">正在加载预览...</small>
                <a id="previewOpenNew" class="btn btn-sm btn-outline-secondary" href="#" target="_blank" rel="noopener">新窗口打开</a>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const modalElement = document.getElementById('previewModal');
        const previewFrame = document.getElementById('previewFrame');
        const previewTitle = document.getElementById('previewModalTitle');
        const previewHint = document.getElementById('previewHint');
        const previewOpenNew = document.getElementById('previewOpenNew');

        if (!modalElement || !previewFrame) {
            return;
        }

        const officeExts = ['docx', 'pptx', 'xlsx'];

        document.querySelectorAll('.js-preview-btn').forEach((button) => {
            button.addEventListener('click', () => {
                const fileId = button.getAttribute('data-file-id');
                const fileExt = (button.getAttribute('data-file-ext') || '').toLowerCase();
                const fileName = button.getAttribute('data-file-name') || '文件预览';

                const previewUrl = button.getAttribute('data-preview-url') || `preview.php?id=${encodeURIComponent(fileId)}`;
                const absolutePreviewUrl = new URL(previewUrl, window.location.href).toString();

                previewTitle.textContent = `在线预览 - ${fileName}`;
                previewOpenNew.href = previewUrl;

                if (fileExt === 'pdf') {
                    previewFrame.src = previewUrl;
                    previewHint.textContent = 'PDF 已使用内嵌预览。';
                } else if (officeExts.includes(fileExt)) {
                    previewFrame.src = `https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(absolutePreviewUrl)}`;
                    previewHint.textContent = 'Office 预览依赖 Office Online，若无法加载请使用“新窗口打开”或下载。';
                } else {
                    previewFrame.src = 'about:blank';
                    previewHint.textContent = '该文件类型暂不支持在线预览。';
                }
            });
        });

        modalElement.addEventListener('hidden.bs.modal', () => {
            previewFrame.src = 'about:blank';
        });
    })();
</script>
<?php require __DIR__ . '/partials/footer.php';
