-- =====================================================
-- ADD SCHOOL_ID TO CLASS_ACTIVITIES TABLE FOR DATA ISOLATION
-- Migration Script v9.0
-- =====================================================

-- Add school_id to class_activities table
ALTER TABLE `class_activities`
ADD COLUMN `school_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `id`;

-- Update existing class_activities records with school_id from related classes
UPDATE `class_activities` ca
JOIN `classes` c ON ca.class_id = c.id
SET ca.school_id = c.school_id
WHERE ca.school_id = 0 AND c.school_id IS NOT NULL;

-- Add foreign key constraint for school_id
ALTER TABLE `class_activities`
ADD CONSTRAINT `fk_class_activities_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Add index for performance
ALTER TABLE `class_activities`
ADD INDEX `idx_class_activities_school_id` (`school_id`);

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. Adds school_id column to class_activities table for multi-tenancy
-- 2. Populates existing records with school_id from related classes
-- 3. Adds foreign key constraint and index
-- 4. Ensures proper data isolation for teacher activities
-- =====================================================
