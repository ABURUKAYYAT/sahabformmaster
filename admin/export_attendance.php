<?php
session_start();
require_once '../config/db.php';
require_once 'helpers.php';
require_once '../includes/functions.php';

// Check if admin/principal is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'principal', 'vice_principal'])) {
    header("Location: ../index.php");
    exit;
}

$current_school_id = get_current_school_id();

// Get filter parameters
$selected_class = $_GET['class_id'] ?? 'all';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$selected_status = $_GET['status'] ?? 'all';
$selected_teacher = $_GET['teacher_id'] ?? 'all';
$search_term = trim($_GET['search'] ?? '');
$allowed_statuses = ['all', 'present', 'absent', 'late', 'leave'];

if (!in_array($selected_status, $allowed_statuses, true)) {
    $selected_status = 'all';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-t');
}
if ($start_date > $end_date) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

// Build query
$sql = "SELECT 
    a.date,
    s.full_name,
    s.admission_no,
    c.class_name,
    a.status,
    a.notes,
    u.full_name as recorded_by_name
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN users u ON a.recorded_by = u.id
    WHERE s.school_id = :student_school_id AND a.school_id = :attendance_school_id";
$params = [
    ':student_school_id' => $current_school_id,
    ':attendance_school_id' => $current_school_id
];

if ($selected_class !== 'all') {
    $sql .= " AND c.id = :class_id";
    $params[':class_id'] = $selected_class;
}

if ($selected_status !== 'all') {
    $sql .= " AND a.status = :status";
    $params[':status'] = $selected_status;
}

if ($selected_teacher !== 'all') {
    $sql .= " AND a.recorded_by = :teacher_id";
    $params[':teacher_id'] = (int)$selected_teacher;
}

if ($search_term !== '') {
    $sql .= " AND (s.full_name LIKE :search OR s.admission_no LIKE :search2)";
    $params[':search'] = "%$search_term%";
    $params[':search2'] = "%$search_term%";
}

$sql .= " AND a.date BETWEEN :start_date AND :end_date";
$params[':start_date'] = $start_date;
$params[':end_date'] = $end_date;

$sql .= " ORDER BY a.date DESC, c.class_name, s.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Generate CSV
$filename = 'attendance_report_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// CSV Header
fputcsv($output, ['Date', 'Student Name', 'Admission No', 'Class', 'Status', 'Notes', 'Recorded By']);

// Data rows
foreach ($records as $record) {
    fputcsv($output, [
        date('Y-m-d', strtotime($record['date'])),
        $record['full_name'],
        $record['admission_no'],
        $record['class_name'],
        ucfirst($record['status']),
        $record['notes'] ?? '',
        $record['recorded_by_name'] ?? 'System'
    ]);
}

fclose($output);
exit;
