<?php
// run_attendance_migration_standalone.php - Standalone attendance school_id migration
// Database configuration from environment variables
$host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'sahabformmaster';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
    echo "Connecting to database...\n";
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    echo "Running attendance school_id migration...\n";

    // Check if school_id column already exists
    $stmt = $pdo->prepare("DESCRIBE attendance");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $schoolIdExists = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'school_id') {
            $schoolIdExists = true;
            break;
        }
    }

    if (!$schoolIdExists) {
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
    } else {
        echo "school_id column already exists in attendance table.\n";
    }

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
