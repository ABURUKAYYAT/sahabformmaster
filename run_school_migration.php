<?php
// run_school_migration.php - Execute school_id migration
require_once 'config/db.php';

try {
    echo "Running school_id multi-tenancy migration...\n";

    // Read the migration file
    $migration_sql = file_get_contents('database/migrations/005_add_school_id_to_shared_tables.sql');

    // Split into individual statements and execute
    $statements = array_filter(array_map('trim', explode(';', $migration_sql)));

    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }

    echo "Migration completed successfully!\n";
    echo "School isolation columns added.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
