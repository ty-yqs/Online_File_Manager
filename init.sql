CREATE DATABASE IF NOT EXISTS `challengecup` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `challengecup`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','user') NOT NULL DEFAULT 'user',
  `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
  `approved_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(255) NOT NULL,
  `file_ext` VARCHAR(16) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(120) NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `uploader_id` INT UNSIGNED NOT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_files_uploader` (`uploader_id`),
  KEY `idx_files_category` (`category_id`),
  KEY `idx_files_uploaded_at` (`uploaded_at`),
  CONSTRAINT `fk_files_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_files_uploader` FOREIGN KEY (`uploader_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `file_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `file_id` INT UNSIGNED NOT NULL,
  `action` ENUM('upload','delete','rename','download') NOT NULL,
  `detail` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_user` (`user_id`),
  KEY `idx_logs_file` (`file_id`),
  KEY `idx_logs_created_at` (`created_at`),
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_logs_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`username`, `email`, `password_hash`, `role`, `is_approved`, `approved_at`)
VALUES ('admin', 'admin@example.com', '$2y$10$0m.wXKmEVPSYL/qBFAxmOeBwzG8Kh/6JtUhSuHE1enAWqAWHaM45e', 'admin', 1, NOW())
ON DUPLICATE KEY UPDATE `username` = `username`;

INSERT INTO `categories` (`name`) VALUES
('产品文档'),
('项目资料'),
('财务报表'),
('其他')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
