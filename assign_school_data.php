<?php
/**
 * Data Migration Script: Assign school_id to existing data
 * This script assigns school_id values to existing users, students, classes, etc.
 * Run this after the multi-school migration but before using the application.
 */

session_start();
require_once 'config/db.php';

echo "<h1>Multi-School Data Assignment Script</h1>";
echo "<pre>";

// Check if user is super admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    die("Access denied. Only super admin can run this script.");
}

try {
    echo "Starting data assignment process...\n\n";

    // Step 1: Get all schools
    $schools = $pdo->query("SELECT id, school_name, school_code FROM schools ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($schools) . " schools:\n";
    foreach ($schools as $school) {
        echo "- {$school['school_name']} ({$school['school_code']})\n";
    }
    echo "\n";

    if (empty($schools)) {
        die("No schools found. Please create schools first using super admin panel.\n");
    }

    // Step 2: Assign school_id to users (skip super admin)
    echo "Assigning school_id to users...\n";
    foreach ($schools as $school) {
        // For demo purposes, we'll assign users based on some pattern
        // In real scenario, this would be done manually or based on business logic

        // Example: Assign first school to users with ID 1-10, second school to 11-20, etc.
        $user_range_start = (($school['id'] - 1) * 10) + 1;
        $user_range_end = $school['id'] * 10;

        $stmt = $pdo->prepare("UPDATE users SET school_id = ? WHERE id BETWEEN ? AND ? AND role != 'super_admin' AND school_id IS NULL");
        $result = $stmt->execute([$school['id'], $user_range_start, $user_range_end]);
        $affected = $stmt->rowCount();
        echo "Assigned {$affected} users to {$school['school_name']}\n";
    }

    // Check for unassigned users
    $unassigned_users = $pdo->query("SELECT COUNT(*) FROM users WHERE school_id IS NULL AND role != 'super_admin'")->fetchColumn();
    if ($unassigned_users > 0) {
        echo "\nWARNING: {$unassigned_users} users still unassigned to schools.\n";
        echo "You need to manually assign these users to schools using the super admin panel.\n";
    }

    // Step 3: Assign school_id to students based on their associated users
    echo "\nAssigning school_id to students...\n";

    // Method 1: Students associated with classes
    $stmt = $pdo->prepare("
        UPDATE students s
        INNER JOIN classes c ON s.class_id = c.id
        SET s.school_id = c.school_id
        WHERE s.school_id IS NULL AND c.school_id IS NOT NULL
    ");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "Assigned {$affected} students based on class association\n";

    // Method 2: If still unassigned, assign based on admission pattern or other logic
    // For demo, assign remaining students to first school
    $stmt = $pdo->prepare("UPDATE students SET school_id = ? WHERE school_id IS NULL");
    $stmt->execute([$schools[0]['id']]);
    $affected = $stmt->rowCount();
    if ($affected > 0) {
        echo "Assigned {$affected} remaining students to {$schools[0]['school_name']}\n";
    }

    // Step 4: Assign school_id to classes
    echo "\nAssigning school_id to classes...\n";
    foreach ($schools as $school) {
        // For demo: assign classes based on ID ranges
        $class_range_start = (($school['id'] - 1) * 5) + 1;
        $class_range_end = $school['id'] * 5;

        $stmt = $pdo->prepare("UPDATE classes SET school_id = ? WHERE id BETWEEN ? AND ? AND school_id IS NULL");
        $stmt->execute([$school['id'], $class_range_start, $class_range_end]);
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            echo "Assigned {$affected} classes to {$school['school_name']}\n";
        }
    }

    // Assign remaining classes to first school
    $stmt = $pdo->prepare("UPDATE classes SET school_id = ? WHERE school_id IS NULL");
    $stmt->execute([$schools[0]['id']]);
    $affected = $stmt->rowCount();
    if ($affected > 0) {
        echo "Assigned {$affected} remaining classes to {$schools[0]['school_name']}\n";
    }

    // Step 5: Assign school_id to subjects based on classes
    echo "\nAssigning school_id to subjects...\n";

    // Subjects assigned to classes
    $stmt = $pdo->prepare("
        UPDATE subjects s
        INNER JOIN subject_assignments sa ON s.id = sa.subject_id
        INNER JOIN classes c ON sa.class_id = c.id
        SET s.school_id = c.school_id
        WHERE s.school_id IS NULL AND c.school_id IS NOT NULL
    ");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "Assigned {$affected} subjects based on class assignments\n";

    // Assign remaining subjects to schools
    foreach ($schools as $school) {
        $stmt = $pdo->prepare("UPDATE subjects SET school_id = ? WHERE school_id IS NULL LIMIT 10");
        $stmt->execute([$school['id']]);
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            echo "Assigned {$affected} subjects to {$school['school_name']}\n";
        }
    }

    // Step 6: Assign school_id to results based on students
    echo "\nAssigning school_id to results...\n";
    $stmt = $pdo->prepare("
        UPDATE results r
        INNER JOIN students s ON r.student_id = s.id
        SET r.school_id = s.school_id
        WHERE r.school_id IS NULL AND s.school_id IS NOT NULL
    ");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "Assigned {$affected} results based on student association\n";

    // Step 7: Assign school_id to lesson_plans based on teacher
    echo "\nAssigning school_id to lesson_plans...\n";
    $stmt = $pdo->prepare("
        UPDATE lesson_plans lp
        INNER JOIN users u ON lp.teacher_id = u.id
        SET lp.school_id = u.school_id
        WHERE lp.school_id IS NULL AND u.school_id IS NOT NULL
    ");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "Assigned {$affected} lesson plans based on teacher association\n";

    // Step 8: Assign school_id to attendance based on students
    echo "\nAssigning school_id to attendance...\n";
    $stmt = $pdo->prepare("
        UPDATE attendance a
        INNER JOIN students s ON a.student_id = s.id
        SET a.school_id = s.school_id
        WHERE a.school_id IS NULL AND s.school_id IS NOT NULL
    ");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "Assigned {$affected} attendance records based on student association\n";

    // Step 9: Summary Report
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "ASSIGNMENT SUMMARY:\n";
    echo str_repeat("=", 50) . "\n";

    $tables = ['users', 'students', 'classes', 'subjects', 'results', 'lesson_plans', 'attendance'];
    foreach ($tables as $table) {
        $total = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        $assigned = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE school_id IS NOT NULL")->fetchColumn();
        $unassigned = $total - $assigned;

        echo sprintf("%-15s: %4d total, %4d assigned, %4d unassigned\n",
            ucfirst($table), $total, $assigned, $unassigned);
    }

    echo "\n" . str_repeat("=", 50) . "\n";

    if ($unassigned_users > 0) {
        echo "⚠️  WARNING: {$unassigned_users} users are not assigned to schools.\n";
        echo "   These users will not be able to log in until assigned to a school.\n";
        echo "   Use the super admin panel to assign them.\n\n";
    }

    echo "✅ Data assignment completed!\n";
    echo "📝 Note: Review and manually adjust assignments as needed.\n";
    echo "🔄 You may need to run this script again if new data is added.\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Data assignment failed. Please check database structure and try again.\n";
}

echo "</pre>";
echo "<p><a href='super/dashboard.php'>← Back to Super Admin Dashboard</a></p>";
?>
