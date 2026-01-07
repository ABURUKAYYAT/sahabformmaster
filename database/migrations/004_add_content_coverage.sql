-- =====================================================
-- CONTENT COVERAGE TRACKING SYSTEM
-- Migration Script v4.0
-- =====================================================

-- Create content_coverage table for tracking actual teaching coverage
CREATE TABLE IF NOT EXISTS `content_coverage` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `teacher_id` INT(11) NOT NULL,
    `subject_id` INT(11) NOT NULL,
    `class_id` INT(11) NOT NULL,
    `term` ENUM('1st Term','2nd Term','3rd Term') NOT NULL,
    `week` INT(11) DEFAULT 0,
    `academic_year` VARCHAR(20) DEFAULT NULL,
    `date_covered` DATE NOT NULL,
    `time_start` TIME DEFAULT NULL,
    `time_end` TIME DEFAULT NULL,
    `period` VARCHAR(50) DEFAULT NULL COMMENT 'e.g. Period 1, 2nd Period, Morning, etc.',
    `topics_covered` TEXT NOT NULL COMMENT 'Actual topics taught',
    `objectives_achieved` TEXT DEFAULT NULL,
    `resources_used` TEXT DEFAULT NULL,
    `assessment_done` TEXT DEFAULT NULL,
    `challenges` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('pending','approved','rejected','revision_required') DEFAULT 'pending',
    `principal_id` INT(11) DEFAULT NULL COMMENT 'Who approved/rejected',
    `approved_at` DATETIME DEFAULT NULL,
    `principal_comments` TEXT DEFAULT NULL,
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes for performance
    INDEX `idx_teacher_date` (`teacher_id`, `date_covered`),
    INDEX `idx_subject_class` (`subject_id`, `class_id`),
    INDEX `idx_term_week` (`term`, `week`),
    INDEX `idx_status` (`status`),
    INDEX `idx_date_covered` (`date_covered`),

    -- Foreign key constraints
    CONSTRAINT `fk_coverage_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_coverage_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_coverage_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_coverage_principal` FOREIGN KEY (`principal_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create coverage_attachments table for any supporting documents
CREATE TABLE IF NOT EXISTS `coverage_attachments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `coverage_id` INT(11) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_type` VARCHAR(50) DEFAULT NULL,
    `file_size` INT(11) DEFAULT NULL,
    `uploaded_by` INT(11) DEFAULT NULL,
    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_coverage_id` (`coverage_id`),
    CONSTRAINT `fk_coverage_attachment` FOREIGN KEY (`coverage_id`) REFERENCES `content_coverage`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- SAMPLE DATA FOR TESTING
-- =====================================================
-- Insert sample coverage entries (will be added via UI in production)

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. Teachers submit coverage entries weekly
-- 2. Principal reviews and approves/rejects
-- 3. Links from curriculum pages
-- 4. Tracks actual vs planned teaching
-- =====================================================
