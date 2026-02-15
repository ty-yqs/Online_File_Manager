<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

logout_user();
set_flash('success', '已退出登录。');
redirect('login.php');
