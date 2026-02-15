<?php
/**
 * database/check_fk_issues.php
 *
 * Diagnoses common causes of "errno: 150 Foreign key constraint is incorrectly formed" errors.
 * It compares child and parent column types and table engines and prints suggested ALTER statements.
 *
 * Run from CLI:
 *   php check_fk_issues.php
 *
 * Or from browser (not recommended) with token env var set:
 *   http://.../database/check_fk_issues.php?token=YOUR_TOKEN
 *
 * This script does NOT modify the database by default. To apply recommendations automatically,
 * set environment variable APPLY_FK_FIX=1 and (if called via HTTP) provide matching token.
 */

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    $token = $_GET['token'] ?? '';
    $expected = getenv('SCHEMA_CREATION_TOKEN') ?: '';
    if (!$token || !$expected || !hash_equals($expected, $token)) {
        http_response_code(403);
        echo "Forbidden: missing or invalid token. Run from CLI or set SCHEMA_CREATION_TOKEN.\n";
        exit;
    }
}

$apply = getenv('APPLY_FK_FIX') ? true : false;

$schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!$schema) {
    echo "Unable to detect database name from connection.\n";
    exit(1);
}

// Child -> [ [child_col, parent_table, parent_col], ... ]
$relations = [
    'student_reminders' => [
        ['student_id','students','id'],
        ['created_by','users','id'],
    ],
    'content_coverage' => [
        ['school_id','schools','id'],
        ['teacher_id','users','id'],
        ['principal_id','users','id'],
        ['class_id','classes','id'],
        ['subject_id','subjects','id'],
    ],
    'access_logs' => [
        ['user_id','users','id'],
    ],
    'coverage_attachments' => [
        ['coverage_id','content_coverage','id'],
    ],
    'attendance' => [
        ['student_id','students','id'],
        ['class_id','classes','id'],
        ['recorded_by','users','id'],
        ['school_id','schools','id'],
    ],
    'results_complaints' => [
        ['result_id','results','id'],
        ['student_id','students','id'],
        ['school_id','schools','id'],
    ],
    'student_payments' => [
        ['student_id','students','id'],
        ['school_id','schools','id'],
    ],
];

function getColumnInfo($pdo, $schema, $table, $column) {
    $sql = "SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :col";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['schema'=>$schema,'table'=>$table,'col'=>$column]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getTableEngine($pdo, $schema, $table) {
    $sql = "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['schema'=>$schema,'table'=>$table]);
    return $stmt->fetchColumn();
}

echo "Diagnosing foreign key issues in database: $schema\n\n";
$recommended = [];

foreach ($relations as $childTable => $checks) {
    // Check child existence
    $tblExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:schema AND TABLE_NAME=:table");
    $tblExists->execute(['schema'=>$schema,'table'=>$childTable]);
    if (!$tblExists->fetchColumn()) {
        echo "WARNING: Child table '$childTable' does not exist in database. Create it first.\n\n";
        continue;
    }
    $childEngine = getTableEngine($pdo, $schema, $childTable);
    foreach ($checks as $rel) {
        list($childCol, $parentTable, $parentCol) = $rel;
        echo "Checking $childTable.$childCol -> $parentTable.$parentCol\n";

        // Check parent existence
        $pTblExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:schema AND TABLE_NAME=:table");
        $pTblExists->execute(['schema'=>$schema,'table'=>$parentTable]);
        if (!$pTblExists->fetchColumn()) {
            echo "  - Parent table '$parentTable' does not exist.\n";
            echo "    Suggested: create '$parentTable' before adding FK, or run full schema.\n\n";
            continue;
        }

        $parentEngine = getTableEngine($pdo, $schema, $parentTable);
        if (strtoupper($childEngine) !== strtoupper($parentEngine)) {
            echo "  - Engine mismatch: child engine={$childEngine}, parent engine={$parentEngine}.\n";
            $recommended[] = "ALTER TABLE `$childTable` ENGINE=InnoDB; -- or ALTER TABLE `$parentTable` ENGINE=InnoDB;";
        }

        $childInfo = getColumnInfo($pdo, $schema, $childTable, $childCol);
        $parentInfo = getColumnInfo($pdo, $schema, $parentTable, $parentCol);

        if (!$childInfo) {
            echo "  - Column '$childCol' not found in child table '$childTable'.\n";
            continue;
        }
        if (!$parentInfo) {
            echo "  - Column '$parentCol' not found in parent table '$parentTable'.\n";
            continue;
        }

        echo "  - child type: {$childInfo['COLUMN_TYPE']}, parent type: {$parentInfo['COLUMN_TYPE']}\n";

        // Compare canonical types (e.g., int vs bigint) and unsigned presence
        $childType = strtolower($childInfo['COLUMN_TYPE']);
        $parentType = strtolower($parentInfo['COLUMN_TYPE']);
        if ($childType !== $parentType) {
            echo "  - Type mismatch detected.\n";
            // Recommend altering child to match parent (safer)
            $suggest = "ALTER TABLE `$childTable` MODIFY `$childCol` {$parentInfo['COLUMN_TYPE']}";
            if ($parentInfo['IS_NULLABLE'] === 'NO') $suggest .= " NOT NULL";
            if (strpos($parentInfo['EXTRA'],'auto_increment') !== false) $suggest .= " AUTO_INCREMENT";
            $suggest .= ";";
            $recommended[] = $suggest;
            echo "    Suggested: $suggest\n";
        }

        // Parent column must be indexed (PK or INDEX). Check COLUMN_KEY
        if (!in_array($parentInfo['COLUMN_KEY'], ['PRI','UNI','MUL'])) {
            echo "  - Parent column '{$parentTable}.{$parentCol}' is not indexed (COLUMN_KEY={$parentInfo['COLUMN_KEY']}).\n";
            $recommended[] = "ALTER TABLE `$parentTable` ADD INDEX (`$parentCol`);";
            echo "    Suggested: create an index on parent column.\n";
        }

        echo "\n";
    }
}

if (count($recommended) === 0) {
    echo "No obvious mismatches found from the automated checks. If you still get errno:150, check for:\n";
    echo "  - Different character sets/collations for referenced string columns (not likely for integer PKs)\n";
    echo "  - Existing partially created foreign keys or duplicated FK names.\n";
    echo "  - Running CREATE TABLE statements while foreign key checks are enabled and parent tables don't exist yet.\n";
    echo "\n";
    exit(0);
}

echo "== Recommended SQL statements (review before running) ==\n";
foreach (array_unique($recommended) as $sql) {
    echo $sql . "\n";
}

if ($apply) {
    echo "\nAPPLY_FK_FIX=1 detected. Attempting to run recommendations now...\n";
    foreach (array_unique($recommended) as $sql) {
        try {
            echo "Executing: $sql\n";
            $pdo->exec($sql);
            echo "  -> OK\n";
        } catch (PDOException $e) {
            echo "  -> FAILED: " . $e->getMessage() . "\n";
        }
    }
    echo "Done applying recommended statements.\n";
} else {
    echo "\nTo apply automatically, set environment variable APPLY_FK_FIX=1 and run from CLI.\nBe careful: suggested ALTERs may modify existing table structure. Back up your DB first.\n";
}

?>