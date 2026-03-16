<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();
require_admin();

$pdo = db();
$currentUser = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('danger', '非法请求，请刷新后重试。');
        redirect('recycle_bin.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $isRestoreAction = in_array($action, ['restore', 'batch_restore'], true);
    $isPurgeAction = in_array($action, ['purge', 'batch_purge'], true);

    if (!$isRestoreAction && !$isPurgeAction) {
        set_flash('danger', '不支持的操作。');
        redirect('recycle_bin.php');
    }

    $fileIds = [];
    if (in_array($action, ['batch_restore', 'batch_purge'], true)) {
        $submittedIds = $_POST['file_ids'] ?? [];
        if (!is_array($submittedIds)) {
            set_flash('danger', '参数错误。');
            redirect('recycle_bin.php');
        }

        foreach ($submittedIds as $submittedId) {
            $id = (int) $submittedId;
            if ($id > 0) {
                $fileIds[$id] = $id;
            }
        }
        $fileIds = array_values($fileIds);
    } else {
        $fileId = (int) ($_POST['id'] ?? 0);
        if ($fileId > 0) {
            $fileIds[] = $fileId;
        }
    }

    if (!$fileIds) {
        set_flash('danger', '请先选择要操作的文件。');
        redirect('recycle_bin.php');
    }

    $placeholders = [];
    $params = [];
    foreach ($fileIds as $index => $id) {
        $key = ':id' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    $inClause = implode(', ', $placeholders);

    if ($isRestoreAction) {
        $selectStmt = $pdo->prepare("SELECT id, original_name FROM files WHERE deleted_at IS NOT NULL AND id IN ($inClause)");
        $selectStmt->execute($params);
        $targetFiles = $selectStmt->fetchAll();

        if (!$targetFiles) {
            set_flash('danger', '未找到可恢复的文件。');
            redirect('recycle_bin.php');
        }

        $pdo->beginTransaction();
        try {
            $updateStmt = $pdo->prepare("UPDATE files SET deleted_at = NULL, deleted_by = NULL, updated_at = NOW() WHERE deleted_at IS NOT NULL AND id IN ($inClause)");
            $updateStmt->execute($params);

            foreach ($targetFiles as $targetFile) {
                log_file_action($pdo, (int) ($currentUser['id'] ?? 0), (int) $targetFile['id'], 'delete', '从回收站恢复：' . (string) $targetFile['original_name']);
            }

            $pdo->commit();

            $restoredCount = (int) $updateStmt->rowCount();
            if ($restoredCount <= 0) {
                set_flash('danger', '恢复失败，文件状态已变化。');
            } else {
                set_flash('success', '已恢复 ' . $restoredCount . ' 个文件。');
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            set_flash('danger', '恢复失败：' . $e->getMessage());
        }

        redirect('recycle_bin.php');
    }

    $selectStmt = $pdo->prepare("SELECT id, original_name, stored_path FROM files WHERE deleted_at IS NOT NULL AND id IN ($inClause)");
    $selectStmt->execute($params);
    $targetFiles = $selectStmt->fetchAll();

    if (!$targetFiles) {
        set_flash('danger', '未找到可彻底删除的文件。');
        redirect('recycle_bin.php');
    }

    $pdo->beginTransaction();
    try {
        foreach ($targetFiles as $targetFile) {
            log_file_action($pdo, (int) ($currentUser['id'] ?? 0), (int) $targetFile['id'], 'delete', '回收站彻底删除：' . (string) $targetFile['original_name']);
        }

        $deleteStmt = $pdo->prepare("DELETE FROM files WHERE deleted_at IS NOT NULL AND id IN ($inClause)");
        $deleteStmt->execute($params);

        $deletedCount = (int) $deleteStmt->rowCount();
        if ($deletedCount <= 0) {
            throw new RuntimeException('文件状态已变化，请刷新后重试。');
        }

        $pdo->commit();

        foreach ($targetFiles as $targetFile) {
            $fullPath = STORAGE_DIR . '/' . (string) $targetFile['stored_path'];
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        set_flash('success', '已彻底删除 ' . $deletedCount . ' 个文件。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('danger', '彻底删除失败：' . $e->getMessage());
    }

    redirect('recycle_bin.php');
}

$keyword = trim((string) ($_GET['q'] ?? ''));
$files = get_recycle_bin_files($pdo, $keyword);

require __DIR__ . '/partials/header.php';
?>
<div class="card card-shadow border-0">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="mb-0">回收站</h5>
            <small class="text-muted">仅管理员可查看和操作</small>
        </div>

        <form class="row g-2 mb-3" method="get">
            <div class="col-md-9">
                <input type="text" name="q" value="<?= e($keyword) ?>" class="form-control" placeholder="搜索回收站文件名">
            </div>
            <div class="col-md-3 d-grid">
                <button type="submit" class="btn btn-primary">查询</button>
            </div>
        </form>

        <form method="post" id="batchActionForm" class="d-flex flex-wrap align-items-center gap-2 mb-3" onsubmit="return handleBatchSubmit(event);">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="form-check me-2">
                <input class="form-check-input" type="checkbox" id="selectAllItems">
                <label class="form-check-label" for="selectAllItems">全选</label>
            </div>
            <button class="btn btn-sm btn-outline-success" type="submit" name="action" value="batch_restore">批量恢复</button>
            <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="batch_purge">批量彻底删除</button>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                <tr>
                    <th style="width: 40px;"></th>
                    <th>文件名</th>
                    <th>大小</th>
                    <th>上传者</th>
                    <th>删除者</th>
                    <th>删除时间</th>
                    <th style="width: 220px;">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$files): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">回收站为空</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($files as $file): ?>
                        <tr>
                            <td>
                                <input
                                    type="checkbox"
                                    class="form-check-input js-batch-item"
                                    name="file_ids[]"
                                    value="<?= (int) $file['id'] ?>"
                                    form="batchActionForm"
                                >
                            </td>
                            <td><?= e($file['original_name']) ?></td>
                            <td><?= e(format_file_size((int) $file['file_size'])) ?></td>
                            <td><?= e((string) $file['uploader_name']) ?></td>
                            <td><?= e((string) ($file['deleted_by_name'] ?? '未知')) ?></td>
                            <td><?= e((string) $file['deleted_at']) ?></td>
                            <td>
                                <div class="d-flex gap-2 flex-wrap">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
                                        <button class="btn btn-sm btn-outline-success" type="submit">恢复</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('确认彻底删除？此操作不可恢复。');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="purge">
                                        <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">彻底删除</button>
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
<script>
    function handleBatchSubmit(event) {
        const checkedItems = document.querySelectorAll('.js-batch-item:checked');
        if (checkedItems.length === 0) {
            alert('请至少选择一个文件。');
            event.preventDefault();
            return false;
        }

        const submitter = event.submitter;
        if (submitter && submitter.value === 'batch_purge') {
            const ok = confirm(`确认彻底删除选中的 ${checkedItems.length} 个文件？此操作不可恢复。`);
            if (!ok) {
                event.preventDefault();
                return false;
            }
        }

        return true;
    }

    (function () {
        const selectAll = document.getElementById('selectAllItems');
        const items = Array.from(document.querySelectorAll('.js-batch-item'));

        if (!selectAll || items.length === 0) {
            return;
        }

        const syncSelectAllState = () => {
            const checkedCount = items.filter((item) => item.checked).length;
            selectAll.checked = checkedCount > 0 && checkedCount === items.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < items.length;
        };

        selectAll.addEventListener('change', () => {
            items.forEach((item) => {
                item.checked = selectAll.checked;
            });
            syncSelectAllState();
        });

        items.forEach((item) => {
            item.addEventListener('change', syncSelectAllState);
        });
    })();
</script>
<?php require __DIR__ . '/partials/footer.php';
