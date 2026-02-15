-- =====================================================
-- ADD SCHOOL_ID TO SCHOOL_NEWS_COMMENTS TABLE
-- Migration Script v11.0 - Fix for missing school_id in comments
-- =====================================================

-- Add school_id to school_news_comments table (currently system-wide shared)
ALTER TABLE `school_news_comments` ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_school_news_comments_school_id` (`school_id`),
ADD CONSTRAINT `fk_school_news_comments_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- =====================================================
-- DATA MIGRATION: Assign existing comments to schools
-- =====================================================

-- Assign school_news_comments based on news article school association
UPDATE `school_news_comments` snc
INNER JOIN `school_news` sn ON snc.news_id = sn.id
SET snc.school_id = sn.school_id
WHERE snc.school_id IS NULL AND sn.school_id IS NOT NULL;

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. This migration fixes the missing school_id column in school_news_comments table
-- 2. Comments are assigned to the same school as their parent news article
-- 3. Run this migration to fix the "Unknown column 'school_id'" error
-- =====================================================
