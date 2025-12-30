-- Migration: Add featured column to school_news table
-- Created: <?php echo date('Y-m-d H:i:s'); ?>

ALTER TABLE `school_news` ADD COLUMN `featured` TINYINT(1) DEFAULT 0 NOT NULL COMMENT 'Whether this news is featured on homepage' AFTER `allow_comments`;

-- Add index for featured news queries
ALTER TABLE `school_news` ADD INDEX `idx_featured` (`featured`);
ALTER TABLE `school_news` ADD INDEX `idx_featured_published` (`featured`, `published_date`);

-- Update existing records to have default values
UPDATE `school_news` SET `featured` = 0 WHERE `featured` IS NULL;
