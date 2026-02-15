<?php
/**
 * database/create_schema.php
 *
 * Run this from the command line with: php create_schema.php
 * Or from a browser with a secret token: /database/create_schema.php?token=YOUR_TOKEN
 *
 * The script uses the existing PDO connection in `config/db.php` so it will run with the
 * same credentials as the application.
 *
 * Security: If invoked via HTTP it requires the environment variable SCHEMA_CREATION_TOKEN
 * to be set and to match the `token` GET param.
 */

// Run only from CLI or with a valid token when called from the web
if (PHP_SAPI !== 'cli') {
    $token = $_GET['token'] ?? '';
    $expected = getenv('SCHEMA_CREATION_TOKEN') ?: '';
    if (!$token || !$expected || !hash_equals($expected, $token)) {
        http_response_code(403);
        echo "Forbidden: missing or invalid token. Set SCHEMA_CREATION_TOKEN environment variable or run from CLI.\n";
        exit;
    }
}

require_once __DIR__ . '/../config/db.php';

$schemaFile = __DIR__ . '/schema_admin.sql';
if (!file_exists($schemaFile)) {
    echo "Schema file not found: $schemaFile\n";
    exit(1);
}

$sql = file_get_contents($schemaFile);
if ($sql === false) {
    echo "Failed to read schema file.\n";
    exit(1);
}

try {
    // Start transaction and disable foreign key checks for safe ordering
    $pdo->beginTransaction();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    // Split by semicolon followed by newline — keeps statements intact for our file.
    $statements = preg_split('/;\s*\n/', $sql);

    foreach ($statements as $i => $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        // Skip SQL comment-only blocks
        if (strpos($stmt, '--') === 0) {
            continue;
        }

        // Execute statement
        try {
            $pdo->exec($stmt);
            echo "[OK] Statement #" . ($i+1) . " executed.\n";
        } catch (PDOException $e) {
            // Provide helpful debug output and abort
            echo "[ERROR] Statement #" . ($i+1) . " failed:\n";
            echo $e->getMessage() . "\n";
            echo "Failed SQL (preview):\n" . substr($stmt, 0, 500) . (strlen($stmt) > 500 ? "...\n" : "\n");
            // Rollback and restore FK checks
            $pdo->rollBack();
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Exception $x) {}
            exit(1);
        }
    }

    // Re-enable foreign key checks and commit
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->commit();

    echo "\nSchema executed successfully.\n";
    exit(0);

} catch (PDOException $e) {
    // Attempt to rollback and re-enable foreign key checks
    try { $pdo->rollBack(); } catch (Exception $x) {}
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Exception $x) {}

    echo "Fatal error executing schema: " . $e->getMessage() . "\n";
    exit(1);
}
