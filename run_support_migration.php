<?php
// run_support_migration.php - Execute support ticket migration
require_once 'config/db.php';

try {
    echo "Running support tickets migration...\n";

    $migration_sql = file_get_contents('database/migrations/014_add_support_tickets.sql');
    if ($migration_sql === false) {
        throw new RuntimeException('Unable to read support migration file.');
    }

    $statements = array_filter(array_map('trim', explode(';', $migration_sql)));
    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Executing: " . substr($statement, 0, 60) . "...\n";
            $pdo->exec($statement);
        }
    }

    echo "Support migration completed successfully.\n";
} catch (Exception $e) {
    echo "Support migration failed: " . $e->getMessage() . "\n";
}
?>
