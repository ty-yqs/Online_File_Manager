<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function is_logged_in(): bool
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function is_admin(): bool
{
    return is_logged_in() && (current_user()['role'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('warning', '请先登录。');
        redirect('login.php');
    }
}

function require_admin(): void
{
    if (!is_admin()) {
        http_response_code(403);
        set_flash('danger', '无权限执行该操作。');
        redirect('index.php');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    if (!isset($_SESSION['csrf_token']) || !is_string($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}
