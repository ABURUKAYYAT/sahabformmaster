-- =====================================================
-- ADD SCHOOL_ID TO TEACHER TABLES FOR DATA ISOLATION
-- Migration Script v7.0
-- =====================================================

-- Add school_id to curriculum table
ALTER TABLE `curriculum`
ADD COLUMN `school_id` INT(11) NOT NULL DEFAULT 0 AFTER `id`;

-- Add school_id to content_coverage table
ALTER TABLE `content_coverage`
ADD COLUMN `school_id` INT(11) NOT NULL DEFAULT 0 AFTER `id`;

-- Update existing curriculum records with school_id from classes
UPDATE `curriculum` c
JOIN `classes` cl ON c.class_id = cl.id
SET c.school_id = cl.school_id
WHERE c.school_id = 0;

-- Update existing content_coverage records with school_id from classes
UPDATE `content_coverage` cc
JOIN `classes` cl ON cc.class_id = cl.id
SET cc.school_id = cl.school_id
WHERE cc.school_id = 0;

-- Add foreign key constraints for school_id
ALTER TABLE `curriculum`
ADD CONSTRAINT `fk_curriculum_school` FOREIGN KEY (`school_id`) REFERENCES `users`(`school_id`) ON DELETE CASCADE;

ALTER TABLE `content_coverage`
ADD CONSTRAINT `fk_content_coverage_school` FOREIGN KEY (`school_id`) REFERENCES `users`(`school_id`) ON DELETE CASCADE;

-- Add indexes for performance
ALTER TABLE `curriculum`
ADD INDEX `idx_curriculum_school_id` (`school_id`);

ALTER TABLE `content_coverage`
ADD INDEX `idx_content_coverage_school_id` (`school_id`);

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. Adds school_id columns to curriculum and content_coverage tables
-- 2. Populates existing records with school_id from related classes
-- 3. Adds foreign key constraints and indexes
-- 4. Ensures proper data isolation for multi-tenancy
-- =====================================================
