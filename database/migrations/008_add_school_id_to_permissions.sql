-- =====================================================
-- ADD SCHOOL_ID TO PERMISSIONS TABLE FOR DATA ISOLATION
-- Migration Script v8.0
-- =====================================================

-- Add school_id to permissions table
ALTER TABLE `permissions`
ADD COLUMN `school_id` INT(11) NOT NULL DEFAULT 0 AFTER `id`;

-- Update existing permissions records with school_id from staff (users table)
UPDATE `permissions` p
JOIN `users` u ON p.staff_id = u.id
SET p.school_id = u.school_id
WHERE p.school_id = 0;

-- Add foreign key constraint for school_id
ALTER TABLE `permissions`
ADD CONSTRAINT `fk_permissions_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE;

-- Add index for performance
ALTER TABLE `permissions`
ADD INDEX `idx_permissions_school_id` (`school_id`);

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. Adds school_id column to permissions table
-- 2. Populates existing records with school_id from staff user
-- 3. Adds foreign key constraint and index
-- 4. Ensures permissions are properly isolated by school
-- =====================================================
