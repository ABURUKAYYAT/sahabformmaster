<?php
session_start();
require_once '../config/db.php';

// Validate principal access
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    die("Access denied. Principal access required.");
}

// Validate POST data
$class_id = $_POST['class_id'] ?? null;

if (!$class_id) {
    die("Class ID required.");
}

// Fetch class information
$class_stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
$class_stmt->execute([$class_id]);
$class = $class_stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    die("Class not found.");
}

// Fetch students for the class
$students_query = "
    SELECT s.*
    FROM students s
    WHERE s.class_id = ?
    ORDER BY s.full_name ASC
";

$students_stmt = $pdo->prepare($students_query);
$students_stmt->execute([$class_id]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get school info
$school = $pdo->query("SELECT * FROM school_profile WHERE id = 1")->fetch();

// CSV Headers
$headers = [
    'Admission Number',
    'Full Name',
    'Gender',
    'Date of Birth',
    'Phone',
    'Address',
    'Guardian Name',
    'Guardian Phone',
    'Guardian Email',
    'Guardian Relation',
    'Guardian Occupation',
    'Student Type',
    'Class',
    'Enrollment Date',
    'Nationality',
    'Religion',
    'Blood Group',
    'Medical Conditions',
    'Allergies',
    'Emergency Contact Name',
    'Emergency Contact Phone',
    'Emergency Contact Relation',
    'Registration Date'
];

// Start CSV output
$filename = $class['class_name'] . '_Student_List_' . date('Ymd_His') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

// Create output stream
$output = fopen('php://output', 'w');

// Write BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write headers
fputcsv($output, $headers);

// Write data rows
foreach ($students as $student) {
    $row = [
        $student['admission_no'] ?? '',
        $student['full_name'] ?? '',
        $student['gender'] ?? '',
        $student['dob'] ?? '',
        $student['phone'] ?? '',
        $student['address'] ?? '',
        $student['guardian_name'] ?? '',
        $student['guardian_phone'] ?? '',
        $student['guardian_email'] ?? '',
        $student['guardian_relation'] ?? '',
        $student['guardian_occupation'] ?? '',
        $student['student_type'] ?? '',
        $class['class_name'] ?? '',
        $student['enrollment_date'] ?? '',
        $student['nationality'] ?? '',
        $student['religion'] ?? '',
        $student['blood_group'] ?? '',
        $student['medical_conditions'] ?? '',
        $student['allergies'] ?? '',
        $student['emergency_contact_name'] ?? '',
        $student['emergency_contact_phone'] ?? '',
        $student['emergency_contact_relation'] ?? '',
        $student['created_at'] ?? ''
    ];

    fputcsv($output, $row);
}

// Log the export
$stmt = $pdo->prepare("
    INSERT INTO export_logs (user_id, export_type, file_path, exported_at, ip_address, metadata)
    VALUES (?, 'student_list_csv', ?, NOW(), ?, ?)
");
$metadata = json_encode([
    'class_id' => $class_id,
    'class_name' => $class['class_name'],
    'export_type' => 'csv',
    'total_students' => count($students)
]);
$stmt->execute([
    $_SESSION['user_id'] ?? null,
    $filename,
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $metadata
]);

fclose($output);
exit;
?>
