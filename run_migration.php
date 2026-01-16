<?php
// run_migration.php - Execute database migration
require_once 'config/db.php';

try {
    echo "Running content_coverage migration...\n";

    // Read the migration file
    $migration_sql = file_get_contents('database/migrations/004_add_content_coverage.sql');

    // Split into individual statements and execute
    $statements = array_filter(array_map('trim', explode(';', $migration_sql)));

    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }

    echo "Migration completed successfully!\n";
    echo "Content coverage tables created.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
