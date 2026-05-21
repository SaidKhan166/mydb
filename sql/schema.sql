-- USDT Withdrawal System Database Schema
-- MySQL 5.7+

CREATE DATABASE IF NOT EXISTS `usdt_withdrawal_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `usdt_withdrawal_system`;

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `email` VARCHAR(100) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100),
  `last_name` VARCHAR(100),
  `phone` VARCHAR(20),
  `role` ENUM('user', 'admin', 'moderator') DEFAULT 'user',
  `status` ENUM('active', 'inactive', 'suspended', 'banned') DEFAULT 'active',
  `tron_wallet_address` VARCHAR(255),
  `kyc_verified` BOOLEAN DEFAULT FALSE,
  `kyc_document` VARCHAR(255),
  `kyc_date` TIMESTAMP NULL,
  `two_factor_enabled` BOOLEAN DEFAULT FALSE,
  `two_factor_secret` VARCHAR(255),
  `last_login` TIMESTAMP NULL,
  `login_attempts` INT DEFAULT 0,
  `locked_until` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_status` (`status`),
  INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wallets Table
CREATE TABLE IF NOT EXISTS `wallets` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `wallet_address` VARCHAR(255) UNIQUE NOT NULL,
  `wallet_type` ENUM('tron', 'ethereum', 'bitcoin') DEFAULT 'tron',
  `balance` DECIMAL(20, 8) DEFAULT 0.00,
  `balance_locked` DECIMAL(20, 8) DEFAULT 0.00,
  `is_primary` BOOLEAN DEFAULT FALSE,
  `verified` BOOLEAN DEFAULT FALSE,
  `verification_token` VARCHAR(255),
  `verification_date` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `primary_wallet` (`user_id`, `is_primary`),
  INDEX `idx_wallet_address` (`wallet_address`),
  INDEX `idx_verified` (`verified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deposits Table
CREATE TABLE IF NOT EXISTS `deposits` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `wallet_id` INT NOT NULL,
  `transaction_hash` VARCHAR(255) UNIQUE NOT NULL,
  `from_address` VARCHAR(255) NOT NULL,
  `to_address` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(20, 8) NOT NULL,
  `token` VARCHAR(50) DEFAULT 'USDT',
  `confirmations` INT DEFAULT 0,
  `required_confirmations` INT DEFAULT 25,
  `status` ENUM('pending', 'confirmed', 'failed', 'cancelled') DEFAULT 'pending',
  `block_number` BIGINT,
  `block_timestamp` TIMESTAMP NULL,
  `detected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`) ON DELETE CASCADE,
  INDEX `idx_status` (`status`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_transaction_hash` (`transaction_hash`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Withdrawals Table
CREATE TABLE IF NOT EXISTS `withdrawals` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `wallet_id` INT NOT NULL,
  `withdrawal_address` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(20, 8) NOT NULL,
  `fee` DECIMAL(20, 8) DEFAULT 0,
  `token` VARCHAR(50) DEFAULT 'USDT',
  `status` ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
  `transaction_hash` VARCHAR(255),
  `error_message` TEXT,
  `retry_count` INT DEFAULT 0,
  `max_retries` INT DEFAULT 3,
  `notes` TEXT,
  `approved_by` INT,
  `approved_at` TIMESTAMP NULL,
  `processed_at` TIMESTAMP NULL,
  `completed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_status` (`status`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_transaction_hash` (`transaction_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction Logs Table (for audit trail)
CREATE TABLE IF NOT EXISTS `transaction_logs` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `user_id` INT,
  `transaction_type` ENUM('deposit', 'withdrawal', 'login', 'logout', 'admin_action') NOT NULL,
  `transaction_id` INT,
  `description` TEXT NOT NULL,
  `old_value` LONGTEXT,
  `new_value` LONGTEXT,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `status` ENUM('success', 'failed', 'pending') DEFAULT 'success',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_transaction_type` (`transaction_type`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings Table
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `setting_key` VARCHAR(100) UNIQUE NOT NULL,
  `setting_value` LONGTEXT NOT NULL,
  `description` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user (password: Admin@123)
INSERT IGNORE INTO `users` (`username`, `email`, `password_hash`, `first_name`, `last_name`, `role`, `status`) 
VALUES ('admin', 'admin@system.com', '$2y$10$YIjlrsPDtOSGvo6.7OhWeuK21ij6rWKV8sMwWGisDlwiAek0S7uDm', 'System', 'Admin', 'admin', 'active');

-- Insert default settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('app_name', 'USDT Withdrawal System', 'Application Name'),
('app_version', '1.0.0', 'Application Version'),
('maintenance_mode', '0', 'Enable/Disable Maintenance Mode'),
('min_withdrawal', '10', 'Minimum withdrawal amount in USDT'),
('max_withdrawal', '50000', 'Maximum withdrawal amount in USDT'),
('withdrawal_fee', '1', 'Withdrawal fee in USDT'),
('require_kyc', '1', 'Require KYC verification'),
('two_factor_enabled', '0', 'Enable two-factor authentication'),
('auto_withdrawal_enabled', '1', 'Enable automatic withdrawal processing');