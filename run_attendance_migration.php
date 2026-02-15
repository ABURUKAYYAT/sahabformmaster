<?php
/**
 * Attendance Migration Runner
 * Adds school_id to attendance table for data isolation
 */

echo "========================================\n";
echo "ATTENDANCE TABLE MIGRATION\n";
echo "========================================\n";

require_once 'config/db.php';

try {
    // Check if migration already run
    $stmt = $pdo->query("SHOW COLUMNS FROM `attendance` LIKE 'school_id'");
    $exists = $stmt->fetch();

    if ($exists) {
        echo "✅ Migration already applied - school_id column exists\n";
        exit(0);
    }

    echo "📋 Running migration...\n";

    // Read and execute migration file
    $migration_sql = file_get_contents('database/migrations/010_add_school_id_to_attendance.sql');

    // Split into individual statements and execute
    $statements = array_filter(array_map('trim', explode(';', $migration_sql)));

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }

    echo "\n✅ Migration completed successfully!\n";
    echo "========================================\n";

    // Verify the migration
    echo "🔍 Verifying migration...\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM `attendance` LIKE 'school_id'");
    $column = $stmt->fetch();

    if ($column) {
        echo "✅ school_id column added successfully\n";

        // Check if data was migrated
        $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN school_id > 0 THEN 1 ELSE 0 END) as migrated FROM attendance");
        $stats = $stmt->fetch();

        echo "📊 Migration stats:\n";
        echo "   - Total attendance records: {$stats['total']}\n";
        echo "   - Records with school_id: {$stats['migrated']}\n";

        if ($stats['total'] > 0 && $stats['migrated'] == 0) {
            echo "⚠️  Warning: No records were migrated. Please check student-school relationships.\n";
        }
    } else {
        echo "❌ Migration failed - school_id column not found\n";
        exit(1);
    }

} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "========================================\n";
    exit(1);
}

echo "\n🎉 Attendance table migration completed!\n";
echo "========================================\n";
?>
