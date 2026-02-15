<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();

$pdo = db();
$user = current_user();
$categories = get_categories($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('danger', '非法请求，请刷新后重试。');
        redirect('upload.php');
    }

    if (!isset($_FILES['file'])) {
        set_flash('danger', '请选择上传文件。');
        redirect('upload.php');
    }

    [$valid, $error, $ext, $mime] = validate_uploaded_file($_FILES['file']);
    if (!$valid) {
        set_flash('danger', $error);
        redirect('upload.php');
    }

    $categoryId = (int) ($_POST['category_id'] ?? 0);
    if ($categoryId > 0) {
        $checkCategory = $pdo->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
        $checkCategory->execute([':id' => $categoryId]);
        if (!$checkCategory->fetch()) {
            set_flash('danger', '分类不存在。');
            redirect('upload.php');
        }
    } else {
        $categoryId = null;
    }

    try {
        [$relativeDir, $absoluteDir] = ensure_storage_path();
        $storedName = generate_stored_name((string) $ext);
        $absolutePath = $absoluteDir . '/' . $storedName;

        if (!move_uploaded_file((string) $_FILES['file']['tmp_name'], $absolutePath)) {
            throw new RuntimeException('文件保存失败。');
        }

        $storedPath = $relativeDir . '/' . $storedName;

        $stmt = $pdo->prepare('INSERT INTO files (original_name, stored_name, stored_path, file_ext, file_size, mime_type, category_id, uploader_id) VALUES (:original_name, :stored_name, :stored_path, :file_ext, :file_size, :mime_type, :category_id, :uploader_id)');
        $stmt->execute([
            ':original_name' => (string) $_FILES['file']['name'],
            ':stored_name' => $storedName,
            ':stored_path' => $storedPath,
            ':file_ext' => $ext,
            ':file_size' => (int) $_FILES['file']['size'],
            ':mime_type' => $mime,
            ':category_id' => $categoryId,
            ':uploader_id' => (int) $user['id'],
        ]);

        $fileId = (int) $pdo->lastInsertId();
        log_file_action($pdo, (int) $user['id'], $fileId, 'upload', '上传文件：' . (string) $_FILES['file']['name']);

        set_flash('success', '文件上传成功。');
        redirect('index.php');
    } catch (Throwable $e) {
        set_flash('danger', '上传失败：' . $e->getMessage());
        redirect('upload.php');
    }
}

require __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card card-shadow border-0">
            <div class="card-body p-4">
                <h4 class="mb-3">上传文件</h4>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label">选择文件</label>
                        <input type="file" class="form-control" name="file" accept=".pdf,.docx,.pptx,.xlsx,.zip" required>
                        <div class="form-text">最大 20MB，仅支持 pdf/docx/pptx/xlsx/zip。</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">文件分类</label>
                        <select name="category_id" class="form-select">
                            <option value="0">未分类</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit">上传</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php';
