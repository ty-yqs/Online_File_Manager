# 团队共享文件管理系统（PHP + MySQL）

一个基于 **PHP 8+ / MySQL / PDO** 的纯 PHP 团队文件管理系统，支持注册审核、角色权限控制、文件分类、上传下载、在线预览与操作日志。

---

## 1. 项目特性

### 1.1 用户与权限
- 用户注册 / 登录 / 退出
- 密码加密存储：`password_hash` / `password_verify`
- Session 鉴权
- RBAC 角色：`admin`、`user`
- 注册审核机制：新用户默认待审核，管理员审核通过后才可登录

### 1.2 文件管理
- 文件上传（最大 20MB）
- 文件下载（统一经 `download.php`）
- 文件删除
   - `admin`：可将任意文件放入回收站
   - `user`：仅可将自己上传的文件放入回收站
- 回收站（仅管理员）
   - 查看已删除文件
   - 恢复文件（支持批量）
   - 彻底删除文件（支持批量）
- 文件重命名（管理员或上传者本人）
- 文件分类（后台可增删分类）
- 文件搜索与分类筛选

### 1.3 在线预览
- `pdf`：站内 iframe 直接预览
- `docx/pptx/xlsx`：使用 Office Online 嵌入预览
- 预览入口通过 `preview.php`，并对 Office 预览使用短期签名 URL

### 1.4 安全机制
- 全部数据库读写使用 PDO 预处理语句
- 上传文件扩展名白名单：`pdf/docx/pptx/xlsx/zip`
- MIME 类型校验，防止扩展名伪造
- 存储目录禁止直接访问（`storage/.htaccess`）
- 所有敏感操作均校验登录态 + CSRF Token

---

## 2. 技术栈

- PHP 8.0+
- MySQL 5.7+ / 8.0+
- Bootstrap 5
- 纯 PHP（无框架）

---

## 3. 目录结构

```text
Online-File-Manager/
├── admin.php                # 管理后台（分类、用户、审核、日志）
├── auth.php                 # 登录态与权限校验、CSRF
├── config.php               # 数据库连接、系统配置、兼容迁移
├── delete.php               # 删除文件（权限校验）
├── download.php             # 文件下载入口
├── functions.php            # 业务函数（分类/文件/校验/日志/预览签名）
├── index.php                # 文件列表页（搜索、筛选、重命名、预览）
├── init.sql                 # 数据库初始化脚本
├── login.php                # 登录
├── logout.php               # 退出
├── preview.php              # 文件在线预览流接口
├── register.php             # 注册（默认待审核）
├── recycle_bin.php          # 回收站（仅管理员）
├── upload.php               # 上传页
├── README.md
├── assets/
│   └── style.css
├── partials/
│   ├── header.php
│   └── footer.php
└── storage/                 # 文件存储目录（按 年/月 自动建目录）
      ├── .htaccess
      └── index.html
```

---

## 4. 快速部署

### 4.1 上传代码
将项目放置于站点目录，例如：

```bash
/www/wwwroot/Online-File-Manager
```

### 4.2 配置数据库连接
编辑 `config.php`：

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', '你的数据库名');
define('DB_USER', '你的数据库用户名');
define('DB_PASS', '你的数据库密码');
```

### 4.3 初始化数据库
导入 `init.sql`：

```bash
mysql -u <db_user> -p <db_name> < init.sql
```

默认管理员账号：
- 用户名：`admin`
- 密码：`Admin@123`

> 首次运行时，`config.php` 内置了 `users` 审核字段兼容补齐逻辑（`is_approved`、`approved_at`）。

### 4.4 PHP 上传限制
在 `php.ini` 中设置：

```ini
file_uploads = On
upload_max_filesize = 20M
post_max_size = 25M
```

修改后重启 PHP-FPM / Web 服务。

---

## 5. Web 服务器安全配置

### 5.1 Apache
项目已提供 `storage/.htaccess`，禁止目录访问。

### 5.2 Nginx（建议额外配置）

```nginx
location ^~ /storage/ {
      deny all;
      return 403;
}
```

---

## 6. 页面说明

- `index.php`：文件列表、搜索、分类筛选、下载、预览、重命名、删除
- `recycle_bin.php`：回收站（管理员可恢复或彻底删除）
- `upload.php`：上传文件并选择分类
- `login.php`：登录
- `register.php`：注册（提交后待管理员审核）
- `admin.php`：分类管理、用户管理、注册审核、操作日志
- `download.php`：下载入口（防止直接暴露存储目录）
- `preview.php`：在线预览入口

---

## 7. 权限规则（RBAC）

### 7.1 角色
- `admin`
- `user`

### 7.2 权限矩阵

| 功能 | admin | user |
|---|---:|---:|
| 登录系统 | ✅ | ✅（需审核通过） |
| 上传文件 | ✅ | ✅ |
| 下载文件 | ✅ | ✅ |
| 重命名文件 | ✅ | ✅（仅本人上传） |
| 删除文件 | ✅（任意文件） | ✅（仅本人上传） |
| 回收站查看/恢复/彻底删除 | ✅ | ❌ |
| 分类管理 | ✅ | ❌ |
| 用户管理/审核 | ✅ | ❌ |

---

## 8. 注册审核流程

1. 用户在 `register.php` 提交注册
2. 系统创建用户并设置 `is_approved = 0`
3. 用户尝试登录时会被提示“待审核”
4. 管理员在 `admin.php -> 用户管理` 执行“审核通过”
5. 用户审核通过后可正常登录

---

## 9. 数据库设计

数据库初始化脚本在 `init.sql`，包含 4 张业务表：

- `users`
   - 账号基础信息、角色、审核状态
   - 关键字段：`username`、`email`、`password_hash`、`role`、`is_approved`
- `files`
   - 文件元数据与上传者、分类关联
   - 关键字段：`original_name`、`stored_path`、`file_ext`、`mime_type`、`uploader_id`、`deleted_at`、`deleted_by`
- `categories`
   - 文件分类表
- `file_logs`
   - 操作日志（上传/删除/重命名/下载）

---

## 10. 文件存储规则

- 存储根目录：`storage/`
- 自动按 `年/月` 建目录，例如：`storage/2026/02/`
- 保存名自动重命名：`时间戳_随机数.扩展名`
- 数据库存储相对路径：`stored_path`

---

## 11. 在线预览说明

- PDF：直接使用浏览器内置 PDF 能力展示
- Office 文件（docx/pptx/xlsx）：通过 Office Online 预览
- Office 预览需要公网可访问站点 URL（Office 服务需要拉取源文件）
- 如果预览失败，可使用“新窗口打开”或“下载”

---

## 12. 常见问题排查

### 12.1 `Table 'xxx.users' doesn't exist`
- 数据库未导入 `init.sql`，或 `config.php` 的库名与实际不一致。

### 12.2 登录报 `HY093 Invalid parameter number`
- 多处复用同名命名参数导致，当前版本已修复为独立参数绑定。

### 12.3 删除文件外键报错
- 当前版本已调整删除流程顺序：先写日志，再删文件记录。

### 12.4 Office 文件点击预览无反应
- 请清缓存强刷页面；当前版本已修复前端触发与签名预览链路。

---

## 13. 访问入口

- 登录页：`/login.php`
- 文件列表：首页：`/index.php`
- 管理后台：`/admin.php`