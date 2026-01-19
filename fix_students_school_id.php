<?php
// fix_students_school_id.php - Add school_id to students table if missing
require_once 'config/db.php';

try {
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
            ADD INDEX `idx_school_admission` (`school_id`, `admission_no`),
            ADD CONSTRAINT `fk_students_school`
                FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ");

        echo "school_id column added successfully!\n";

        // Assign existing students to first school
        $stmt = $pdo->prepare("UPDATE students SET school_id = (SELECT id FROM schools ORDER BY id LIMIT 1) WHERE school_id IS NULL");
        $stmt->execute();

        echo "Existing students assigned to default school.\n";

    } else {
        echo "school_id column already exists.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
