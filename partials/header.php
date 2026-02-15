<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
$flash = get_flash();
$user = current_user();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php"><?= e(APP_NAME) ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="appNav">
            <?php if ($user): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="index.php">文件列表</a></li>
                    <li class="nav-item"><a class="nav-link" href="upload.php">上传文件</a></li>
                    <?php if (($user['role'] ?? '') === 'admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="admin.php">管理后台</a></li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center gap-3 text-white">
                    <span><?= e($user['username']) ?> (<?= e($user['role']) ?>)</span>
                    <a class="btn btn-outline-light btn-sm" href="logout.php">退出</a>
                </div>
            <?php else: ?>
                <div class="ms-auto d-flex gap-2">
                    <a class="btn btn-outline-light btn-sm" href="login.php">登录</a>
                    <a class="btn btn-light btn-sm" href="register.php">注册</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="container py-4">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
