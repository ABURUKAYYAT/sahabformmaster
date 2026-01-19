<?php
/**
 * Teacher Pages Data Isolation Audit Script (Simple Version)
 * Checks all teacher pages for proper multi-tenancy implementation
 * No authentication required - for development auditing
 */

echo "<h1>Teacher Pages Data Isolation Audit Report</h1>";
echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";
echo "<hr>";

// Get all teacher PHP files
$teacher_dir = __DIR__ . '/teacher';
$teacher_files = glob($teacher_dir . '/*.php');

$issues = [];
$compliant = [];

// Check each teacher file
foreach ($teacher_files as $file) {
    $filename = basename($file);
    $content = file_get_contents($file);

    $file_issues = [];

    // Check for school authentication
    if (!preg_match('/require_school_auth\(\)/', $content)) {
        $file_issues[] = "Missing school authentication (require_school_auth())";
    }

    // Check for potential data leakage patterns - SELECT queries without school_id
    if (preg_match('/SELECT.*FROM.*WHERE.*[^school_id]/i', $content) &&
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
        'students.*=.*\$pdo->query',
        'subjects.*=.*\$pdo->query',
        'classes.*=.*\$pdo->query',
        'results.*=.*\$pdo->query',
        'attendance.*=.*\$pdo->query'
    ];

    foreach ($filter_patterns as $pattern) {
        if (preg_match("/$pattern/i", $content) &&
            !preg_match('/school_id.*=.*\?|WHERE.*school_id/i', $content)) {
            $file_issues[] = "Filter dropdowns not school-filtered: " . $pattern;
            break;
        }
    }

    // Check for session-based school_id usage (should use require_school_auth)
    if (preg_match('/\$_SESSION\[.school_id.\]/', $content) &&
        !preg_match('/require_school_auth\(\)/', $content)) {
        $file_issues[] = "Uses session school_id directly instead of require_school_auth()";
    }

    if (empty($file_issues)) {
        $compliant[] = $filename;
    } else {
        $issues[$filename] = $file_issues;
    }
}

// Display results
echo "<h2>Non-Compliant Teacher Files (" . count($issues) . ")</h2>";

if (empty($issues)) {
    echo "<p style='color: green; font-weight: bold;'>✅ All teacher files are compliant!</p>";
} else {
    echo "<div style='background: #fee; border: 1px solid #fcc; padding: 10px; margin: 10px 0;'>";
    foreach ($issues as $file => $file_issues) {
        echo "<h3 style='color: red; margin-top: 20px;'>teacher/$file</h3>";
        echo "<ul style='margin-bottom: 20px;'>";
        foreach ($file_issues as $issue) {
            echo "<li style='margin-bottom: 5px;'>$issue</li>";
        }
        echo "</ul>";
    }
    echo "</div>";
}

echo "<h2>Compliant Teacher Files (" . count($compliant) . ")</h2>";
echo "<div style='background: #efe; border: 1px solid #cfc; padding: 10px; margin: 10px 0;'>";
foreach ($compliant as $file) {
    echo "✅ teacher/$file<br>";
}
echo "</div>";

// Additional checks for teacher-specific patterns
echo "<h2>Teacher-Specific Security Checks</h2>";

// Check for teacher role validation
$role_issues = [];
foreach ($teacher_files as $file) {
    $filename = basename($file);
    $content = file_get_contents($file);

    if (!preg_match('/\$_SESSION\[.role.\].*=.*teacher/i', $content) &&
        !preg_match('/in_array.*role.*teacher/i', $content) &&
        !preg_match('/require_school_auth/i', $content)) {
        $role_issues[] = $filename;
    }
}

if (!empty($role_issues)) {
    echo "<h3 style='color: orange;'>Files missing teacher role validation:</h3>";
    echo "<ul>";
    foreach ($role_issues as $file) {
        echo "<li>teacher/$file</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: green;'>✅ All teacher files have proper role validation</p>";
}

echo "<hr>";
echo "<p><strong>Summary:</strong></p>";
echo "<ul>";
echo "<li>Total teacher files checked: " . count($teacher_files) . "</li>";
echo "<li>Compliant files: " . count($compliant) . "</li>";
echo "<li>Non-compliant files: " . count($issues) . "</li>";
if (!empty($role_issues)) {
    echo "<li>Files missing role validation: " . count($role_issues) . "</li>";
}
echo "</ul>";

if (!empty($issues)) {
    echo "<p><strong>Next Steps for Non-Compliant Files:</strong></p>";
    echo "<ol>";
    echo "<li>Add <code>require_school_auth()</code> at the beginning of each non-compliant file</li>";
    echo "<li>Update all SELECT queries to include <code>WHERE school_id = ?</code> filtering</li>";
    echo "<li>Use helper functions like <code>get_school_students()</code> for dropdowns</li>";
    echo "<li>Replace direct session access with authenticated school context</li>";
    echo "<li>Test each file to ensure data isolation works correctly</li>";
    echo "</ol>";
}

echo "<hr>";
echo "<p><small>Report generated by teacher_audit_simple.php</small></p>";
?>
