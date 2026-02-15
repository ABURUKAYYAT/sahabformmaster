<?php
// run_multi_school_migration.php - Execute multi-school database migration
require_once 'config/db.php';

try {
    echo "Running multi-school setup migration...\n";

    // Read the migration file
    $migration_sql = file_get_contents('database/migrations/001_multi_school_setup.sql');

    // Split into individual statements and execute
    $statements = array_filter(array_map('trim', explode(';', $migration_sql)));

    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }

    echo "Multi-school migration completed successfully!\n";
    echo "Schools, roles, and access control tables created.\n";
    echo "Default super admin account created.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
