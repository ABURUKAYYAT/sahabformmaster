<?php
// run_attendance_school_id_migration.php - Execute attendance school_id migration
require_once 'config/db.php';

try {
    echo "Running attendance school_id migration...\n";

    // Execute the migration statements manually to handle the column position issue
    echo "Adding school_id column to attendance table...\n";
    $pdo->exec("
        ALTER TABLE `attendance`
        ADD COLUMN `school_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 FIRST
    ");

    echo "Updating existing attendance records with school_id...\n";
    $pdo->exec("
        UPDATE `attendance` a
        JOIN `students` s ON a.student_id = s.id
        SET a.school_id = s.school_id
        WHERE a.school_id = 0 AND s.school_id IS NOT NULL
    ");

    echo "Adding foreign key constraint...\n";
    $pdo->exec("
        ALTER TABLE `attendance`
        ADD CONSTRAINT `fk_attendance_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ");

    echo "Adding index...\n";
    $pdo->exec("
        ALTER TABLE `attendance`
        ADD INDEX `idx_attendance_school_id` (`school_id`)
    ");

    echo "Attendance school_id migration completed successfully!\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
