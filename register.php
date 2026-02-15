<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('danger', '非法请求，请刷新后重试。');
        redirect('register.php');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirm'] ?? '');

    if ($username === '' || $email === '' || $password === '') {
        set_flash('danger', '请完整填写注册信息。');
        redirect('register.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('danger', '邮箱格式不正确。');
        redirect('register.php');
    }

    if ($password !== $password2) {
        set_flash('danger', '两次输入的密码不一致。');
        redirect('register.php');
    }

    if (mb_strlen($password) < 6) {
        set_flash('danger', '密码长度至少为 6 位。');
        redirect('register.php');
    }

    $pdo = db();
    $check = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
    $check->execute([':username' => $username, ':email' => $email]);

    if ($check->fetch()) {
        set_flash('danger', '用户名或邮箱已存在。');
        redirect('register.php');
    }

    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, is_approved, approved_at) VALUES (:username, :email, :password_hash, :role, :is_approved, :approved_at)');
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => 'user',
        ':is_approved' => 0,
        ':approved_at' => null,
    ]);

    set_flash('success', '注册成功，请等待管理员审核后再登录。');
    redirect('login.php');
}

require __DIR__ . '/partials/header.php';
?>
<div class="auth-wrapper">
    <div class="card card-shadow border-0">
        <div class="card-body p-4">
            <h3 class="mb-3">注册</h3>
            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="mb-3">
                    <label class="form-label">用户名</label>
                    <input type="text" class="form-control" name="username" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">邮箱</label>
                    <input type="email" class="form-control" name="email" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">密码</label>
                    <input type="password" class="form-control" name="password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">确认密码</label>
                    <input type="password" class="form-control" name="password_confirm" required>
                </div>
                <button class="btn btn-primary w-100" type="submit">注册</button>
            </form>
            <p class="text-muted mt-3 mb-0">已有账号？<a href="login.php">去登录</a></p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php';
