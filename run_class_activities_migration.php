<?php
// run_class_activities_migration.php - Execute class_activities school_id migration
require_once 'config/db.php';

try {
    echo "Running class_activities school_id migration...\n";

    // Read the migration file
    $migration_sql = file_get_contents('database/migrations/009_add_school_id_to_class_activities.sql');

    // Split into individual statements and execute
    $statements = array_filter(array_map('trim', explode(';', $migration_sql)));

    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }

    echo "Class_activities migration completed successfully!\n";
    echo "All class activities now have proper school isolation.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
