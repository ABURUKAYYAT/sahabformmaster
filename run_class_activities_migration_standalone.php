<?php
// run_class_activities_migration_standalone.php - Standalone class_activities school_id migration
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

    echo "Running class_activities school_id migration...\n";

    // Check if school_id column already exists
    $stmt = $pdo->prepare("DESCRIBE class_activities");
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
        echo "Adding school_id column to class_activities table...\n";
        $pdo->exec("
            ALTER TABLE `class_activities`
            ADD COLUMN `school_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `id`
        ");

        echo "Updating existing class_activities records with school_id...\n";
        $pdo->exec("
            UPDATE `class_activities` ca
            JOIN `classes` c ON ca.class_id = c.id
            SET ca.school_id = c.school_id
            WHERE ca.school_id = 0 AND c.school_id IS NOT NULL
        ");

        echo "Adding foreign key constraint...\n";
        $pdo->exec("
            ALTER TABLE `class_activities`
            ADD CONSTRAINT `fk_class_activities_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
        ");

        echo "Adding index...\n";
        $pdo->exec("
            ALTER TABLE `class_activities`
            ADD INDEX `idx_class_activities_school_id` (`school_id`)
        ");

        echo "Class_activities school_id migration completed successfully!\n";
    } else {
        echo "school_id column already exists in class_activities table.\n";
    }

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
