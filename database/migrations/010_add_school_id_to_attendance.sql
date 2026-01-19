-- =====================================================
-- ADD SCHOOL_ID TO ATTENDANCE TABLE FOR DATA ISOLATION
-- Migration Script v10.0
-- =====================================================

-- Add school_id to attendance table
ALTER TABLE `attendance`
ADD COLUMN `school_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `id`;

-- Update existing attendance records with school_id from related students
UPDATE `attendance` a
JOIN `students` s ON a.student_id = s.id
SET a.school_id = s.school_id
WHERE a.school_id = 0 AND s.school_id IS NOT NULL;

-- Add foreign key constraint for school_id
ALTER TABLE `attendance`
ADD CONSTRAINT `fk_attendance_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Add index for performance
ALTER TABLE `attendance`
ADD INDEX `idx_attendance_school_id` (`school_id`);

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. Adds school_id column to attendance table for multi-tenancy
-- 2. Populates existing records with school_id from related students
-- 3. Adds foreign key constraint and index
-- 4. Ensures proper data isolation for attendance records
-- =====================================================
