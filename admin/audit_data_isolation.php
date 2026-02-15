<?php
/**
 * Data Isolation Audit Script
 * Checks all admin pages for proper multi-tenancy implementation
 */

session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow super admin to run this audit
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    die("Access denied. Only super admin can run this audit.");
}

$current_school_id = require_school_auth();

echo "<h1>Data Isolation Audit Report</h1>";
echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";
echo "<hr>";

// Get all admin PHP files
$admin_dir = __DIR__;
$admin_files = glob($admin_dir . '/*.php');

$issues = [];
$compliant = [];

// Check each admin file
foreach ($admin_files as $file) {
    $filename = basename($file);
    $content = file_get_contents($file);

    $file_issues = [];

    // Check for school authentication
    if (!preg_match('/require_school_auth\(\)/', $content)) {
        $file_issues[] = "Missing school authentication (require_school_auth())";
    }

    // Check for potential data leakage patterns
    if (preg_match('/SELECT.*FROM.*WHERE.*1=1/i', $content) &&
        !preg_match('/school_id.*=.*\?|WHERE.*school_id/i', $content)) {
        $file_issues[] = "Potential data leakage: SELECT query without school_id filter";
    }

    // Check for unprotected AJAX endpoints
    if (strpos($filename, 'ajax/') !== false &&
        !preg_match('/require_school_auth\(\)/', $content)) {
        $file_issues[] = "AJAX endpoint missing school authentication";
    }

    // Check for bulk operations without school validation
    if (preg_match('/bulk_|multiple|batch/i', $content) &&
        !preg_match('/school_id.*=.*\?|WHERE.*school_id/i', $content)) {
        $file_issues[] = "Bulk operations may affect multiple schools";
    }

    // Check for filter dropdowns without school filtering
    $filter_patterns = [
        'teachers.*=.*\$pdo->query',
        'subjects.*=.*\$pdo->query',
        'classes.*=.*\$pdo->query'
    ];

    foreach ($filter_patterns as $pattern) {
        if (preg_match("/$pattern/i", $content) &&
            !preg_match('/school_id.*=.*\?|WHERE.*school_id/i', $content)) {
            $file_issues[] = "Filter dropdowns not school-filtered";
            break;
        }
    }

    if (empty($file_issues)) {
        $compliant[] = $filename;
    } else {
        $issues[$filename] = $file_issues;
    }
}

// Display results
echo "<h2>Non-Compliant Files (" . count($issues) . ")</h2>";

if (empty($issues)) {
    echo "<p style='color: green;'>✅ All admin files are compliant!</p>";
} else {
    echo "<div style='background: #fee; border: 1px solid #fcc; padding: 10px; margin: 10px 0;'>";
    foreach ($issues as $file => $file_issues) {
        echo "<h3 style='color: red;'>$file</h3>";
        echo "<ul>";
        foreach ($file_issues as $issue) {
            echo "<li>$issue</li>";
        }
        echo "</ul>";
    }
    echo "</div>";
}

echo "<h2>Compliant Files (" . count($compliant) . ")</h2>";
echo "<div style='background: #efe; border: 1px solid #cfc; padding: 10px; margin: 10px 0;'>";
foreach ($compliant as $file) {
    echo "✅ $file<br>";
}
echo "</div>";

// Additional checks
echo "<h2>Database Schema Check</h2>";

// Check which tables have school_id columns
$tables_with_school_id = [];
$tables_without_school_id = [];

try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE 'school_id'");
        $stmt->execute();
        if ($stmt->fetch()) {
            $tables_with_school_id[] = $table;
        } else {
            $tables_without_school_id[] = $table;
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error checking database schema: " . $e->getMessage() . "</p>";
}

echo "<h3>Tables WITH school_id column (" . count($tables_with_school_id) . ")</h3>";
echo "<ul>";
foreach ($tables_with_school_id as $table) {
    echo "<li>$table</li>";
}
echo "</ul>";

if (!empty($tables_without_school_id)) {
    echo "<h3 style='color: orange;'>Tables WITHOUT school_id column (" . count($tables_without_school_id) . ")</h3>";
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0;'>";
    echo "<p>These tables may need school_id added for proper multi-tenancy:</p>";
    echo "<ul>";
    foreach ($tables_without_school_id as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    echo "</div>";
}

echo "<hr>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Fix all non-compliant files listed above</li>";
echo "<li>Add school_id columns to tables that need them</li>";
echo "<li>Test data isolation across multiple schools</li>";
echo "<li>Implement the helper functions in includes/functions.php</li>";
echo "</ol>";
?>
