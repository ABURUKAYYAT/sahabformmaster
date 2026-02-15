-- =====================================================
-- ADD SCHOOL_ID TO SHARED TABLES FOR MULTI-TENANCY
-- Migration Script v5.0 - Critical Security Fix
-- =====================================================

-- Add school_id to school_news table (currently system-wide shared)
ALTER TABLE `school_news` ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_school_news_school_id` (`school_id`),
ADD CONSTRAINT `fk_school_news_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Add school_id to student_payments table (currently system-wide shared)
ALTER TABLE `student_payments` ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_student_payments_school_id` (`school_id`),
ADD CONSTRAINT `fk_student_payments_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Add school_id to school_profile table (currently system-wide shared)
ALTER TABLE `school_profile` ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_school_profile_school_id` (`school_id`),
ADD CONSTRAINT `fk_school_profile_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Add school_id to export_logs table (currently system-wide shared)
ALTER TABLE `export_logs` ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_export_logs_school_id` (`school_id`),
ADD CONSTRAINT `fk_export_logs_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- =====================================================
-- DATA MIGRATION: Assign existing data to schools
-- =====================================================

-- Assign school_news to first school (can be reassigned by super admin later)
UPDATE `school_news` SET `school_id` = (SELECT id FROM schools ORDER BY id LIMIT 1) WHERE `school_id` IS NULL;

-- Assign student_payments based on student school association
UPDATE `student_payments` sp
INNER JOIN `students` s ON sp.student_id = s.id
SET sp.school_id = s.school_id
WHERE sp.school_id IS NULL AND s.school_id IS NOT NULL;

-- Assign school_profile to first school (each school should have its own profile)
UPDATE `school_profile` SET `school_id` = (SELECT id FROM schools ORDER BY id LIMIT 1) WHERE `school_id` IS NULL;

-- Assign export_logs based on user school association
UPDATE `export_logs` el
INNER JOIN `users` u ON el.user_id = u.id
SET el.school_id = u.school_id
WHERE el.school_id IS NULL AND u.school_id IS NOT NULL;

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. Run this migration BEFORE applying application fixes
-- 2. After migration, principals will only see their school data
-- 3. Super admin can still see all data (school_id = NULL handling)
-- 4. Test thoroughly after migration - some data may need manual reassignment
-- =====================================================
