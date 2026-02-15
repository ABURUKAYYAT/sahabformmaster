-- =====================================================
-- MULTI-SCHOOL DATA ISOLATION + ROLE-BASED ACCESS
-- Migration Script v1.0
-- =====================================================

-- Step 1: Create Schools Table
CREATE TABLE IF NOT EXISTS `schools` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `school_name` VARCHAR(255) NOT NULL,
    `school_code` VARCHAR(50) NOT NULL UNIQUE,
    `address` TEXT,
    `phone` VARCHAR(20),
    `email` VARCHAR(100),
    `logo` VARCHAR(255),
    `established_date` DATE,
    `motto` VARCHAR(255),
    `principal_name` VARCHAR(255),
    `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_school_code` (`school_code`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 2: Add school_id to users table
ALTER TABLE `users` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_id` (`school_id`),
    ADD INDEX `idx_school_role` (`school_id`, `role`),
    ADD CONSTRAINT `fk_users_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 3: Update role column to include more granular roles
ALTER TABLE `users` 
    MODIFY COLUMN `role` ENUM('super_admin', 'principal', 'vice_principal', 'teacher', 'admin_staff', 'student') NOT NULL DEFAULT 'teacher';

-- Step 4: Add school_id to students table
ALTER TABLE `students` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_id` (`school_id`),
    ADD INDEX `idx_school_admission` (`school_id`, `admission_no`),
    ADD CONSTRAINT `fk_students_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 5: Add school_id to classes table
ALTER TABLE `classes` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_id` (`school_id`),
    ADD CONSTRAINT `fk_classes_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 6: Add school_id to subjects table
ALTER TABLE `subjects` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_id` (`school_id`),
    ADD CONSTRAINT `fk_subjects_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 7: Add school_id to results table (for direct filtering)
ALTER TABLE `results` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_id` (`school_id`),
    ADD INDEX `idx_school_term` (`school_id`, `term`),
    ADD CONSTRAINT `fk_results_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 8: Add school_id to lesson_plans table
ALTER TABLE `lesson_plans` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_id` (`school_id`),
    ADD CONSTRAINT `fk_lesson_plans_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 9: Add school_id to attendance table
ALTER TABLE `attendance` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_date` (`school_id`, `date`),
    ADD CONSTRAINT `fk_attendance_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 10: Add school_id to student_notes table (if exists)
ALTER TABLE `student_notes` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_id` (`school_id`),
    ADD CONSTRAINT `fk_student_notes_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 11: Add school_id to subject_assignments table
ALTER TABLE `subject_assignments` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_id` (`school_id`),
    ADD CONSTRAINT `fk_subject_assignments_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 12: Add school_id to class_teachers table
ALTER TABLE `class_teachers` 
    ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_school_id` (`school_id`),
    ADD CONSTRAINT `fk_class_teachers_school` 
        FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Step 13: Create permissions table for fine-grained access control
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `role` ENUM('super_admin', 'principal', 'vice_principal', 'teacher', 'admin_staff', 'student') NOT NULL,
    `permission` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_role_permission` (`role`, `permission`),
    INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 14: Insert default permissions
INSERT INTO `role_permissions` (`role`, `permission`, `description`) VALUES
-- Super Admin (System-wide access)
('super_admin', 'manage_schools', 'Create, edit, delete schools'),
('super_admin', 'manage_all_users', 'Manage users across all schools'),
('super_admin', 'view_all_data', 'View data from all schools'),
('super_admin', 'system_settings', 'Modify system-wide settings'),

-- Principal (School-wide access)
('principal', 'manage_school', 'Manage own school settings'),
('principal', 'manage_teachers', 'Add, edit, remove teachers in school'),
('principal', 'manage_students', 'Add, edit, remove students in school'),
('principal', 'manage_classes', 'Manage classes in school'),
('principal', 'manage_subjects', 'Manage subjects in school'),
('principal', 'view_all_results', 'View all student results in school'),
('principal', 'view_all_lesson_plans', 'View all lesson plans in school'),
('principal', 'view_all_attendance', 'View attendance for all classes'),
('principal', 'manage_curriculum', 'Manage school curriculum'),
('principal', 'view_reports', 'Generate and view school reports'),

-- Vice Principal (Similar to Principal but limited)
('vice_principal', 'view_school_data', 'View school-wide data'),
('vice_principal', 'manage_students', 'Manage students in school'),
('vice_principal', 'view_all_results', 'View all student results'),
('vice_principal', 'view_all_attendance', 'View attendance records'),

-- Teacher (Class-scoped access within school)
('teacher', 'view_assigned_classes', 'View only assigned classes'),
('teacher', 'manage_assigned_students', 'Manage students in assigned classes'),
('teacher', 'submit_results', 'Submit results for assigned subjects'),
('teacher', 'create_lesson_plans', 'Create lesson plans for assigned subjects'),
('teacher', 'mark_attendance', 'Mark attendance for assigned classes'),
('teacher', 'view_curriculum', 'View school curriculum'),

-- Admin Staff
('admin_staff', 'manage_students', 'Manage student records'),
('admin_staff', 'view_attendance', 'View attendance records'),

-- Student (Self-only access within school)
('student', 'view_own_results', 'View own results only'),
('student', 'view_own_attendance', 'View own attendance only'),
('student', 'view_assignments', 'View own assignments');

-- Step 15: Create audit log for tracking cross-school access attempts
CREATE TABLE IF NOT EXISTS `access_logs` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) UNSIGNED NOT NULL,
    `school_id` INT(11) UNSIGNED NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `resource_type` VARCHAR(50),
    `resource_id` INT(11),
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `status` ENUM('success', 'denied', 'error') DEFAULT 'success',
    `message` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_school_id` (`school_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 16: Insert sample schools for testing
INSERT INTO `schools` (`school_name`, `school_code`, `address`, `phone`, `email`, `motto`, `status`) VALUES
('Green Valley Academy', 'GVA001', '123 Education Street, Lagos', '+234-800-0001', 'info@greenvalley.edu.ng', 'Excellence in Learning', 'active'),
('Bright Future International School', 'BFIS002', '456 Learning Avenue, Abuja', '+234-800-0002', 'admin@brightfuture.edu.ng', 'Building Tomorrow\'s Leaders', 'active'),
('Royal Crown College', 'RCC003', '789 Academic Road, Port Harcourt', '+234-800-0003', 'contact@royalcrown.edu.ng', 'Knowledge is Power', 'active');

-- Step 17: Add super admin user (not tied to any school)
INSERT INTO `users` (`school_id`, `username`, `password`, `full_name`, `role`, `email`) VALUES
(NULL, 'superadmin', 'SuperAdmin@2025', 'System Administrator', 'super_admin', 'admin@sahabformmaster.com');

-- =====================================================
-- IMPORTANT NOTES:
-- =====================================================
-- 1. After running this migration, you MUST update existing data:
--    - Assign school_id to existing users
--    - Assign school_id to existing students, classes, subjects, etc.
--
-- 2. Application code MUST filter by school_id in ALL queries
--
-- 3. Use the access control helper functions in all modules
--
-- 4. NEVER allow school_id to be modified by non-super-admin users
-- =====================================================
