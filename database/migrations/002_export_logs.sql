-- Migration: Create export_logs table for tracking PDF/Excel exports

CREATE TABLE IF NOT EXISTS `export_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'User who performed the export',
  `export_type` enum('transcript','receipt','bulk_data','report','student_list_pdf','student_list_csv','evaluations_report','evaluations_export') NOT NULL DEFAULT 'transcript',
  `file_path` varchar(500) NOT NULL COMMENT 'Path to the exported file',
  `file_size` int(11) DEFAULT NULL COMMENT 'File size in bytes',
  `exported_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP address of the exporter',
  `user_agent` text DEFAULT NULL COMMENT 'Browser user agent',
  `status` enum('success','failed','pending') DEFAULT 'success',
  `metadata` json DEFAULT NULL COMMENT 'Additional export metadata',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_export_type` (`export_type`),
  KEY `idx_exported_at` (`exported_at`),
  KEY `fk_export_logs_user` (`user_id`),
  CONSTRAINT `fk_export_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Modify enum to include additional export types
ALTER TABLE `export_logs` MODIFY COLUMN `export_type` enum('transcript','receipt','bulk_data','report','student_list_pdf','student_list_csv','evaluations_report','evaluations_export') NOT NULL DEFAULT 'transcript';

-- Add some helpful comments
ALTER TABLE `export_logs` COMMENT = 'Tracks all PDF and Excel export activities in the system';
