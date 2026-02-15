<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('danger', '非法请求，请刷新后重试。');
        redirect('login.php');
    }

    $account = trim((string) ($_POST['account'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($account === '' || $password === '') {
        set_flash('danger', '请输入账号和密码。');
        redirect('login.php');
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash, role, is_approved FROM users WHERE username = :username OR email = :email LIMIT 1');
    $stmt->execute([':username' => $account, ':email' => $account]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        set_flash('danger', '账号或密码错误。');
        redirect('login.php');
    }

    if ((int) ($user['is_approved'] ?? 0) !== 1) {
        set_flash('warning', '账号待管理员审核，通过后方可登录。');
        redirect('login.php');
    }

    login_user($user);
    set_flash('success', '登录成功。');
    redirect('index.php');
}

require __DIR__ . '/partials/header.php';
?>
<div class="auth-wrapper">
    <div class="card card-shadow border-0">
        <div class="card-body p-4">
            <h3 class="mb-3">登录</h3>
            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="mb-3">
                    <label class="form-label">用户名或邮箱</label>
                    <input type="text" class="form-control" name="account" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">密码</label>
                    <input type="password" class="form-control" name="password" required>
                </div>
                <button class="btn btn-primary w-100" type="submit">登录</button>
            </form>
            <p class="text-muted mt-3 mb-0">没有账号？<a href="register.php">去注册</a></p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php';
