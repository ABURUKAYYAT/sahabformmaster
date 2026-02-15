<?php
// fix_students_school_id_simple.php - Simplified version to add school_id to students table
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

    echo "Checking students table structure...\n";

    // Get all columns
    $stmt = $pdo->prepare("DESCRIBE students");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Students table columns:\n";
    foreach ($columns as $col) {
        echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }

    // Check if school_id column exists
    $schoolIdExists = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'school_id') {
            $schoolIdExists = true;
            break;
        }
    }

    if (!$schoolIdExists) {
        echo "school_id column not found. Adding it...\n";

        // Add the column
        $pdo->exec("
            ALTER TABLE `students`
            ADD COLUMN `school_id` INT(11) UNSIGNED NULL AFTER `id`,
            ADD INDEX `idx_school_id` (`school_id`),
            ADD INDEX `idx_school_admission` (`school_id`, `admission_no`)
        ");

        echo "school_id column added successfully!\n";

        // Check if schools table exists and has data
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM schools");
        $stmt->execute();
        $schoolCount = $stmt->fetch()['count'];

        if ($schoolCount > 0) {
            // Assign existing students to first school
            $stmt = $pdo->prepare("UPDATE students SET school_id = (SELECT id FROM schools ORDER BY id LIMIT 1) WHERE school_id IS NULL");
            $stmt->execute();
            echo "Existing students assigned to default school.\n";
        } else {
            echo "Warning: No schools found in database. You may need to run the multi-school migration first.\n";
        }

    } else {
        echo "school_id column already exists.\n";
    }

    echo "Fix completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
