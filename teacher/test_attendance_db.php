<?php
// test_attendance_db.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'principal'])) {
    header('Location: ../index.php');
    exit();
}

$current_school_id = require_school_auth();
$teacher_id = $_SESSION['user_id'];

// Test database connection and constraints
echo "<!DOCTYPE html>
<html>
<head>
    <title>Attendance Database Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-result { margin: 10px 0; padding: 10px; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Attendance Database Test</h1>
    <p>Testing database connectivity and constraints for attendance functionality.</p>
    
    <h2>Test Results:</h2>";

// Test 1: Database Connection
echo "<div class='test-result info'><strong>Test 1:</strong> Database Connection</div>";
try {
    $pdo->query("SELECT 1");
    echo "<div class='test-result success'>✓ Database connection successful</div>";
} catch (Exception $e) {
    echo "<div class='test-result error'>✗ Database connection failed: " . $e->getMessage() . "</div>";
    exit;
}

// Test 2: Check if attendance table exists
echo "<div class='test-result info'><strong>Test 2:</strong> Attendance Table Structure</div>";
try {
    $stmt = $pdo->query("DESCRIBE attendance");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<div class='test-result success'>✓ Attendance table exists with " . count($columns) . " columns</div>";
    echo "<pre>";
    print_r($columns);
    echo "</pre>";
} catch (Exception $e) {
    echo "<div class='test-result error'>✗ Attendance table not found: " . $e->getMessage() . "</div>";
}

// Test 3: Check for unique constraints
echo "<div class='test-result info'><strong>Test 3:</strong> Database Constraints</div>";
try {
    $stmt = $pdo->query("SHOW INDEXES FROM attendance WHERE Key_name = 'PRIMARY'");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<div class='test-result success'>✓ Primary key constraints found</div>";
    echo "<pre>";
    print_r($indexes);
    echo "</pre>";
} catch (Exception $e) {
    echo "<div class='test-result error'>✗ Error checking constraints: " . $e->getMessage() . "</div>";
}

// Test 4: Check teacher's assigned classes
echo "<div class='test-result info'><strong>Test 4:</strong> Teacher Class Assignments</div>";
try {
    $assigned_classes_sql = "SELECT c.id, c.class_name
                            FROM class_teachers ct
                            JOIN classes c ON ct.class_id = c.id
                            WHERE ct.teacher_id = :teacher_id AND c.school_id = :school_id";
    $assigned_stmt = $pdo->prepare($assigned_classes_sql);
    $assigned_stmt->execute([':teacher_id' => $teacher_id, ':school_id' => $current_school_id]);
    $assigned_classes = $assigned_stmt->fetchAll();
    
    if (empty($assigned_classes)) {
        echo "<div class='test-result error'>✗ No classes assigned to teacher</div>";
    } else {
        echo "<div class='test-result success'>✓ Teacher has " . count($assigned_classes) . " assigned classes</div>";
        echo "<pre>";
        print_r($assigned_classes);
        echo "</pre>";
    }
} catch (Exception $e) {
    echo "<div class='test-result error'>✗ Error checking class assignments: " . $e->getMessage() . "</div>";
}

// Test 5: Check students in assigned classes
echo "<div class='test-result info'><strong>Test 5:</strong> Students in Assigned Classes</div>";
try {
    if (!empty($assigned_classes)) {
        $assigned_class_ids = array_column($assigned_classes, 'id');
        $placeholders = str_repeat('?,', count($assigned_class_ids) - 1) . '?';
        
        $students_sql = "SELECT s.id, s.full_name, s.admission_no, s.class_id 
                        FROM students s 
                        WHERE s.class_id IN ($placeholders) AND s.school_id = ?";
        $students_stmt = $pdo->prepare($students_sql);
        $params = array_merge($assigned_class_ids, [$current_school_id]);
        $students_stmt->execute($params);
        $students = $students_stmt->fetchAll();
        
        if (empty($students)) {
            echo "<div class='test-result error'>✗ No students found in assigned classes</div>";
        } else {
            echo "<div class='test-result success'>✓ Found " . count($students) . " students in assigned classes</div>";
            echo "<pre>";
            print_r(array_slice($students, 0, 5)); // Show first 5 students
            echo "</pre>";
        }
    }
} catch (Exception $e) {
    echo "<div class='test-result error'>✗ Error checking students: " . $e->getMessage() . "</div>";
}

// Test 6: Test a sample attendance insert
echo "<div class='test-result info'><strong>Test 6:</strong> Sample Attendance Insert</div>";
try {
    if (!empty($students)) {
        $test_student = $students[0];
        $test_date = date('Y-m-d');
        
        // Check if record exists
        $check_sql = "SELECT id FROM attendance WHERE student_id = ? AND date = ? AND school_id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$test_student['id'], $test_date, $current_school_id]);
        $existing = $check_stmt->fetch();
        
        if ($existing) {
            echo "<div class='test-result info'>✓ Test record already exists for student " . $test_student['id'] . " on " . $test_date . "</div>";
        } else {
            // Try to insert
            $insert_sql = "INSERT INTO attendance (student_id, class_id, date, status, recorded_by, notes, school_id, recorded_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $result = $insert_stmt->execute([
                $test_student['id'],
                $test_student['class_id'],
                $test_date,
                'present',
                $teacher_id,
                'Test record',
                $current_school_id
            ]);
            
            if ($result) {
                echo "<div class='test-result success'>✓ Successfully inserted test attendance record</div>";
                
                // Clean up test record
                $delete_sql = "DELETE FROM attendance WHERE student_id = ? AND date = ? AND school_id = ? AND notes = 'Test record'";
                $delete_stmt = $pdo->prepare($delete_sql);
                $delete_stmt->execute([$test_student['id'], $test_date, $current_school_id]);
                echo "<div class='test-result info'>✓ Test record cleaned up</div>";
            } else {
                echo "<div class='test-result error'>✗ Failed to insert test attendance record</div>";
            }
        }
    }
} catch (Exception $e) {
    echo "<div class='test-result error'>✗ Error testing attendance insert: " . $e->getMessage() . "</div>";
}

echo "
    <h2>Summary</h2>
    <p>If all tests passed, the database should be working correctly for attendance functionality.</p>
    <p><a href='class_attendance.php'>Return to Class Attendance</a></p>
</body>
</html>";
?>