<?php
// run_subscription_migration.php - Execute subscription billing migration
require_once 'config/db.php';

try {
    echo "Running subscription billing migration...\n";

    $migration_sql = file_get_contents('database/migrations/013_add_subscription_billing.sql');
    if ($migration_sql === false) {
        throw new RuntimeException('Unable to read migration file.');
    }

    $statements = array_filter(array_map('trim', explode(';', $migration_sql)));

    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Executing: " . substr($statement, 0, 60) . "...\n";
            $pdo->exec($statement);
        }
    }

    echo "Migration completed successfully.\n";
    echo "Subscription billing tables are ready.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
