-- Setup system_settings table
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default teacher signin setting
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('teacher_signin_enabled', '1')
ON DUPLICATE KEY UPDATE `setting_value` = '1';
