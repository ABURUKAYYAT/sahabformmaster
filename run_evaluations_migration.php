<?php
// run_evaluations_migration.php - Execute evaluations multi-school migration
require_once 'config/db.php';

try {
    echo "Running evaluations multi-school migration...\n";

    // Read the migration file
    $migration_sql = file_get_contents('database/migrations/006_add_school_id_to_evaluations.sql');

    // Split into individual statements and execute
    $statements = array_filter(array_map('trim', explode(';', $migration_sql)));

    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }

    echo "Evaluations multi-school migration completed successfully!\n";
    echo "Evaluations table now has school_id for proper data isolation.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
