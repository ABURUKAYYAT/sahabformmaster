-- =====================================================
-- ADD SCHOOL_ID TO EVALUATIONS TABLE FOR MULTI-TENANCY
-- Migration Script v6.0 - Complete Data Isolation
-- =====================================================

-- Add school_id to evaluations table
ALTER TABLE `evaluations` ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_evaluations_school_id` (`school_id`),
ADD CONSTRAINT `fk_evaluations_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- =====================================================
-- DATA MIGRATION: Assign existing data to schools
-- =====================================================

-- Assign evaluations to schools based on student school association
UPDATE `evaluations` e
INNER JOIN `students` s ON e.student_id = s.id
SET e.school_id = s.school_id
WHERE e.school_id IS NULL AND s.school_id IS NOT NULL;

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. This migration ensures evaluations are properly isolated by school
-- 2. Evaluations are tied to students, so school_id comes from student record
-- 3. After this migration, all evaluation queries must filter by school_id
-- 4. Test thoroughly after migration
-- =====================================================
