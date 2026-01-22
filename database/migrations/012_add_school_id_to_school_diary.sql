-- =====================================================
-- ADD SCHOOL_ID TO SCHOOL_DIARY TABLE FOR MULTI-TENANCY
-- Migration Script v12.0 - School Diary Multi-Tenancy Support
-- =====================================================

-- Add school_id to school_diary table
ALTER TABLE `school_diary` ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_school_diary_school_id` (`school_id`),
ADD CONSTRAINT `fk_school_diary_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- =====================================================
-- DATA MIGRATION: Assign existing school_diary data to schools
-- =====================================================

-- Option 1: Assign all existing school_diary entries to the first school
-- This assumes there's at least one school in the schools table
UPDATE `school_diary` SET `school_id` = (SELECT id FROM schools ORDER BY id LIMIT 1) WHERE `school_id` IS NULL;

-- Option 2 (Alternative): If you want to assign based on coordinator's school
-- Uncomment the following if you prefer this approach:
-- UPDATE `school_diary` sd
-- INNER JOIN `users` u ON sd.coordinator_id = u.id
-- SET sd.school_id = u.school_id
-- WHERE sd.school_id IS NULL AND u.school_id IS NOT NULL;

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. Run this migration to enable school-specific school diary entries
-- 2. Students will only see activities for their school
-- 3. Teachers/Admins will only manage activities for their school
-- 4. Super admin can see all activities (school_id = NULL handling)
-- 5. Test thoroughly after migration
-- =====================================================
