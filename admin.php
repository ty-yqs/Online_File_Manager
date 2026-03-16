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
        redirect('admin.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_category') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            set_flash('danger', '分类名称不能为空。');
            redirect('admin.php');
        }

        $check = $pdo->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
        $check->execute([':name' => $name]);
        if ($check->fetch()) {
            set_flash('danger', '分类已存在。');
            redirect('admin.php');
        }

        $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
        $stmt->execute([':name' => $name]);
        set_flash('success', '分类创建成功。');
        redirect('admin.php');
    }

    if ($action === 'create_user') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'user');

        if ($username === '' || $email === '' || $password === '') {
            set_flash('danger', '请完整填写用户信息。');
            redirect('admin.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('danger', '邮箱格式不正确。');
            redirect('admin.php');
        }

        if (mb_strlen($password) < 6) {
            set_flash('danger', '密码长度至少为 6 位。');
            redirect('admin.php');
        }

        if (!in_array($role, ['admin', 'user'], true)) {
            $role = 'user';
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
        $check->execute([':username' => $username, ':email' => $email]);
        if ($check->fetch()) {
            set_flash('danger', '用户名或邮箱已存在。');
            redirect('admin.php');
        }

        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, is_approved, approved_at) VALUES (:username, :email, :password_hash, :role, :is_approved, :approved_at)');
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
            ':is_approved' => 1,
            ':approved_at' => date('Y-m-d H:i:s'),
        ]);

        set_flash('success', '用户创建成功。');
        redirect('admin.php');
    }

    if ($action === 'update_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'user');

        if ($userId <= 0 || $username === '') {
            set_flash('danger', '参数错误。');
            redirect('admin.php');
        }

        if (!in_array($role, ['admin', 'user'], true)) {
            $role = 'user';
        }

        $targetStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $targetStmt->execute([':id' => $userId]);
        $target = $targetStmt->fetch();
        if (!$target) {
            set_flash('danger', '用户不存在。');
            redirect('admin.php');
        }

        $dupStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
        $dupStmt->execute([':username' => $username, ':id' => $userId]);
        if ($dupStmt->fetch()) {
            set_flash('danger', '用户名已被占用。');
            redirect('admin.php');
        }

        if ((int) $target['id'] === (int) current_user()['id'] && $role !== 'admin') {
            set_flash('danger', '不能将当前登录管理员降级为普通用户。');
            redirect('admin.php');
        }

        if ((string) $target['role'] === 'admin' && $role !== 'admin') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                set_flash('danger', '系统至少需要保留一个管理员。');
                redirect('admin.php');
            }
        }

        if ($password !== '') {
            if (mb_strlen($password) < 6) {
                set_flash('danger', '新密码长度至少为 6 位。');
                redirect('admin.php');
            }

            $stmt = $pdo->prepare('UPDATE users SET username = :username, role = :role, password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                ':username' => $username,
                ':role' => $role,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $userId,
            ]);
        } else {
            $stmt = $pdo->prepare('UPDATE users SET username = :username, role = :role, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                ':username' => $username,
                ':role' => $role,
                ':id' => $userId,
            ]);
        }

        if ((int) current_user()['id'] === $userId) {
            $_SESSION['user']['username'] = $username;
            $_SESSION['user']['role'] = $role;
        }

        set_flash('success', '用户信息更新成功。');
        redirect('admin.php');
    }

    if ($action === 'approve_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            set_flash('danger', '参数错误。');
            redirect('admin.php');
        }

        $stmt = $pdo->prepare('UPDATE users SET is_approved = 1, approved_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $userId]);

        if ((int) $stmt->rowCount() === 0) {
            set_flash('danger', '用户不存在或已审核。');
            redirect('admin.php');
        }

        set_flash('success', '用户审核通过。');
        redirect('admin.php');
    }

    if ($action === 'delete_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            set_flash('danger', '参数错误。');
            redirect('admin.php');
        }

        if ($userId === (int) current_user()['id']) {
            set_flash('danger', '不能删除当前登录用户。');
            redirect('admin.php');
        }

        $targetStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $targetStmt->execute([':id' => $userId]);
        $target = $targetStmt->fetch();
        if (!$target) {
            set_flash('danger', '用户不存在。');
            redirect('admin.php');
        }

        if ((string) $target['role'] === 'admin') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                set_flash('danger', '系统至少需要保留一个管理员。');
                redirect('admin.php');
            }
        }

        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);

        set_flash('success', '用户删除成功。');
        redirect('admin.php');
    }

    if ($action === 'delete_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        if ($categoryId <= 0) {
            set_flash('danger', '参数错误。');
            redirect('admin.php');
        }

        $check = $pdo->prepare('SELECT COUNT(*) FROM files WHERE category_id = :id AND deleted_at IS NULL');
        $check->execute([':id' => $categoryId]);
        if ((int) $check->fetchColumn() > 0) {
            set_flash('danger', '该分类下仍有文件，无法删除。');
            redirect('admin.php');
        }

        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute([':id' => $categoryId]);
        set_flash('success', '分类删除成功。');
        redirect('admin.php');
    }
}

$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$fileCount = (int) $pdo->query('SELECT COUNT(*) FROM files WHERE deleted_at IS NULL')->fetchColumn();
$categories = get_categories($pdo);
$users = $pdo->query('SELECT id, username, email, role, is_approved, approved_at, created_at FROM users ORDER BY created_at DESC')->fetchAll();

$logsStmt = $pdo->query('SELECT l.action, l.detail, l.created_at, u.username, f.original_name
                         FROM file_logs l
                         INNER JOIN users u ON u.id = l.user_id
                         LEFT JOIN files f ON f.id = l.file_id
                         ORDER BY l.created_at DESC
                         LIMIT 30');
$logs = $logsStmt->fetchAll();

require __DIR__ . '/partials/header.php';
?>
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card border-0 card-shadow h-100">
            <div class="card-body">
                <h6 class="text-muted">用户总数</h6>
                <h2 class="mb-0"><?= $userCount ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 card-shadow h-100">
            <div class="card-body">
                <h6 class="text-muted">文件总数</h6>
                <h2 class="mb-0"><?= $fileCount ?></h2>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 card-shadow mb-3">
            <div class="card-body">
                <h5 class="mb-3">新建分类</h5>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_category">
                    <div class="input-group">
                        <input type="text" name="name" class="form-control" placeholder="分类名称" required>
                        <button class="btn btn-primary" type="submit">新增</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 card-shadow">
            <div class="card-body">
                <h5 class="mb-3">分类列表</h5>
                <?php if (!$categories): ?>
                    <p class="text-muted mb-0">暂无分类</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($categories as $category): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><?= e($category['name']) ?></span>
                                <form method="post" onsubmit="return confirm('确认删除该分类？');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">删除</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 card-shadow mb-3">
            <div class="card-body">
                <h5 class="mb-3">用户管理</h5>

                <div class="border rounded p-3 mb-3 bg-light-subtle">
                    <h6 class="mb-3">新增用户</h6>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_user">
                        <div class="col-md-3">
                            <input type="text" class="form-control" name="username" placeholder="用户名" required>
                        </div>
                        <div class="col-md-4">
                            <input type="email" class="form-control" name="email" placeholder="邮箱" required>
                        </div>
                        <div class="col-md-3">
                            <input type="password" class="form-control" name="password" placeholder="初始密码" required>
                        </div>
                        <div class="col-md-2">
                            <select name="role" class="form-select">
                                <option value="user">user</option>
                                <option value="admin">admin</option>
                            </select>
                        </div>
                        <div class="col-12 d-grid d-md-block">
                            <button class="btn btn-primary" type="submit">创建用户</button>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>邮箱</th>
                            <th>角色</th>
                            <th>审核状态</th>
                            <th>创建时间</th>
                            <th style="width: 320px;">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$users): ?>
                            <tr><td colspan="7" class="text-center text-muted">暂无用户</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $managedUser): ?>
                                <tr>
                                    <td><?= (int) $managedUser['id'] ?></td>
                                    <td><?= e($managedUser['username']) ?></td>
                                    <td><?= e($managedUser['email']) ?></td>
                                    <td><span class="badge text-bg-<?= $managedUser['role'] === 'admin' ? 'danger' : 'secondary' ?>"><?= e($managedUser['role']) ?></span></td>
                                    <td>
                                        <?php if ((int) $managedUser['is_approved'] === 1): ?>
                                            <span class="badge text-bg-success">已审核</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-warning">待审核</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($managedUser['created_at']) ?></td>
                                    <td>
                                        <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update_user">
                                            <input type="hidden" name="user_id" value="<?= (int) $managedUser['id'] ?>">
                                            <input type="text" name="username" class="form-control form-control-sm" style="max-width: 110px;" value="<?= e($managedUser['username']) ?>" required>
                                            <input type="password" name="password" class="form-control form-control-sm" style="max-width: 120px;" placeholder="新密码(可空)">
                                            <select name="role" class="form-select form-select-sm" style="max-width: 100px;">
                                                <option value="user" <?= $managedUser['role'] === 'user' ? 'selected' : '' ?>>user</option>
                                                <option value="admin" <?= $managedUser['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary" type="submit">保存</button>
                                        </form>
                                        <?php if ((int) $managedUser['is_approved'] !== 1): ?>
                                            <form method="post" class="mt-2" onsubmit="return confirm('确认审核通过该用户？');">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="approve_user">
                                                <input type="hidden" name="user_id" value="<?= (int) $managedUser['id'] ?>">
                                                <button class="btn btn-sm btn-outline-success" type="submit">审核通过</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" class="mt-2" onsubmit="return confirm('确认删除该用户？');">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= (int) $managedUser['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">删除</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 card-shadow">
            <div class="card-body">
                <h5 class="mb-3">操作日志</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                        <tr>
                            <th>时间</th>
                            <th>用户</th>
                            <th>动作</th>
                            <th>详情</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$logs): ?>
                            <tr><td colspan="4" class="text-center text-muted">暂无日志</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= e($log['created_at']) ?></td>
                                    <td><?= e($log['username']) ?></td>
                                    <td><?= e($log['action']) ?></td>
                                    <td><?= e((string) ($log['detail'] ?: $log['original_name'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php';
