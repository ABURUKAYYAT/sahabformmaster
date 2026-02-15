<?php
session_start();
require_once '../config/db.php';

// Include PHPExcel or PhpSpreadsheet
// For this example, we'll use a simple CSV approach, but in production you'd use PhpSpreadsheet
// require_once '../vendor/autoload.php'; // For PhpSpreadsheet

// Validate POST data
$export_type = $_POST['export_type'] ?? '';
$filters = $_POST['filters'] ?? [];

// Define available export types
$available_exports = [
    'students' => 'Student Data Export',
    'results' => 'Academic Results Export',
    'attendance' => 'Attendance Records Export',
    'payments' => 'Payment Records Export',
    'fee_structure' => 'Fee Structure Export',
    'staff' => 'Staff Directory Export',
    'classes' => 'Class Information Export'
];

if (!isset($available_exports[$export_type])) {
    die("Invalid export type.");
}

// Function to clean data for CSV
function cleanData($data) {
    if (is_array($data)) {
        return implode(', ', array_map('cleanData', $data));
    }
    // Remove any HTML, PHP tags and encode properly
    $data = strip_tags($data);
    // Escape quotes and wrap in quotes if contains comma, quote, or newline
    if (strpos($data, ',') !== false || strpos($data, '"') !== false || strpos($data, "\n") !== false) {
        $data = '"' . str_replace('"', '""', $data) . '"';
    }
    return $data;
}

// Function to send CSV headers
function sendCsvHeaders($filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
}

// Generate CSV based on export type
switch ($export_type) {
    case 'students':
        exportStudents($filters);
        break;
    case 'results':
        exportResults($filters);
        break;
    case 'attendance':
        exportAttendance($filters);
        break;
    case 'payments':
        exportPayments($filters);
        break;
    case 'fee_structure':
        exportFeeStructure($filters);
        break;
    case 'staff':
        exportStaff($filters);
        break;
    case 'classes':
        exportClasses($filters);
        break;
    default:
        die("Export type not implemented.");
}

function exportStudents($filters) {
    global $pdo;

    $filename = 'Students_Export_' . date('Ymd_His') . '.csv';
    sendCsvHeaders($filename);

    // CSV headers
    $headers = [
        'Admission No',
        'Full Name',
        'Class',
        'Gender',
        'Date of Birth',
        'Phone',
        'Email',
        'Address',
        'Guardian Name',
        'Guardian Phone',
        'Guardian Relationship',
        'Enrollment Date',
        'Student Type',
        'Status'
    ];

    echo implode(',', array_map('cleanData', $headers)) . "\n";

    // Build query with filters
    $where = [];
    $params = [];

    if (!empty($filters['class_id'])) {
        $where[] = "s.class_id = ?";
        $params[] = $filters['class_id'];
    }

    if (!empty($filters['status'])) {
        $where[] = "s.is_active = ?";
        $params[] = $filters['status'] === 'active' ? 1 : 0;
    }

    if (!empty($filters['gender'])) {
        $where[] = "s.gender = ?";
        $params[] = $filters['gender'];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT s.*, c.class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        $whereClause
        ORDER BY s.full_name
    ");

    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($students as $student) {
        $row = [
            $student['admission_no'],
            $student['full_name'],
            $student['class_name'],
            $student['gender'],
            $student['dob'] ? date('Y-m-d', strtotime($student['dob'])) : '',
            $student['phone'] ?: '',
            '', // Email not in current schema
            $student['address'] ?: '',
            $student['guardian_name'] ?: '',
            $student['guardian_phone'] ?: '',
            $student['guardian_relation'] ?: '',
            $student['enrollment_date'] ? date('Y-m-d', strtotime($student['enrollment_date'])) : '',
            $student['student_type'] ?: 'fresh',
            $student['is_active'] ? 'Active' : 'Inactive'
        ];

        echo implode(',', array_map('cleanData', $row)) . "\n";
    }

    // Log the export
    logExport('students', '../exports/reports/' . $filename);
}

function exportResults($filters) {
    global $pdo;

    $filename = 'Results_Export_' . date('Ymd_His') . '.csv';
    sendCsvHeaders($filename);

    // CSV headers
    $headers = [
        'Student Name',
        'Admission No',
        'Class',
        'Subject',
        'Term',
        'Academic Year',
        'First CA',
        'Second CA',
        'Exam Score',
        'Total Score',
        'Grade',
        'Remark',
        'Date Recorded'
    ];

    echo implode(',', array_map('cleanData', $headers)) . "\n";

    // Build query with filters
    $where = [];
    $params = [];

    if (!empty($filters['class_id'])) {
        $where[] = "s.class_id = ?";
        $params[] = $filters['class_id'];
    }

    if (!empty($filters['term'])) {
        $where[] = "r.term = ?";
        $params[] = $filters['term'];
    }

    if (!empty($filters['subject_id'])) {
        $where[] = "r.subject_id = ?";
        $params[] = $filters['subject_id'];
    }

    if (!empty($filters['academic_year'])) {
        $where[] = "r.academic_session = ?";
        $params[] = $filters['academic_year'];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT r.*, s.full_name, s.admission_no, c.class_name, sub.subject_name
        FROM results r
        JOIN students s ON r.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        JOIN subjects sub ON r.subject_id = sub.id
        $whereClause
        ORDER BY s.full_name, sub.subject_name
    ");

    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $result) {
        // Calculate grade
        $total_score = $result['total_score'];
        if ($total_score >= 90) {
            $grade = 'A'; $remark = 'Excellent';
        } elseif ($total_score >= 80) {
            $grade = 'B'; $remark = 'Very Good';
        } elseif ($total_score >= 70) {
            $grade = 'C'; $remark = 'Good';
        } elseif ($total_score >= 60) {
            $grade = 'D'; $remark = 'Fair';
        } elseif ($total_score >= 50) {
            $grade = 'E'; $remark = 'Pass';
        } else {
            $grade = 'F'; $remark = 'Fail';
        }

        $row = [
            $result['full_name'],
            $result['admission_no'],
            $result['class_name'],
            $result['subject_name'],
            $result['term'],
            $result['academic_session'],
            $result['first_ca'],
            $result['second_ca'],
            $result['exam'],
            $total_score,
            $grade,
            $remark,
            $result['created_at'] ? date('Y-m-d', strtotime($result['created_at'])) : ''
        ];

        echo implode(',', array_map('cleanData', $row)) . "\n";
    }

    logExport('results', '../exports/reports/' . $filename);
}

function exportAttendance($filters) {
    global $pdo;

    $filename = 'Attendance_Export_' . date('Ymd_His') . '.csv';
    sendCsvHeaders($filename);

    // CSV headers
    $headers = [
        'Student Name',
        'Admission No',
        'Class',
        'Date',
        'Status',
        'Recorded By',
        'Notes'
    ];

    echo implode(',', array_map('cleanData', $headers)) . "\n";

    // Build query with filters
    $where = [];
    $params = [];

    if (!empty($filters['class_id'])) {
        $where[] = "s.class_id = ?";
        $params[] = $filters['class_id'];
    }

    if (!empty($filters['start_date'])) {
        $where[] = "a.date >= ?";
        $params[] = $filters['start_date'];
    }

    if (!empty($filters['end_date'])) {
        $where[] = "a.date <= ?";
        $params[] = $filters['end_date'];
    }

    if (!empty($filters['status'])) {
        $where[] = "a.status = ?";
        $params[] = $filters['status'];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT a.*, s.full_name, s.admission_no, c.class_name, u.full_name as recorded_by_name
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN users u ON a.recorded_by = u.id
        $whereClause
        ORDER BY a.date DESC, s.full_name
    ");

    $stmt->execute($params);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($attendance_records as $record) {
        $row = [
            $record['full_name'],
            $record['admission_no'],
            $record['class_name'],
            $record['date'],
            ucfirst($record['status']),
            $record['recorded_by_name'] ?: 'System',
            $record['notes'] ?: ''
        ];

        echo implode(',', array_map('cleanData', $row)) . "\n";
    }

    logExport('attendance', '../exports/reports/' . $filename);
}

function exportPayments($filters) {
    global $pdo;

    $filename = 'Payments_Export_' . date('Ymd_His') . '.csv';
    sendCsvHeaders($filename);

    // CSV headers
    $headers = [
        'Student Name',
        'Admission No',
        'Class',
        'Receipt No',
        'Payment Date',
        'Term',
        'Academic Year',
        'Total Amount',
        'Amount Paid',
        'Balance',
        'Payment Method',
        'Payment Type',
        'Status',
        'Verified By'
    ];

    echo implode(',', array_map('cleanData', $headers)) . "\n";

    // Build query with filters
    $where = [];
    $params = [];

    if (!empty($filters['class_id'])) {
        $where[] = "s.class_id = ?";
        $params[] = $filters['class_id'];
    }

    if (!empty($filters['term'])) {
        $where[] = "sp.term = ?";
        $params[] = $filters['term'];
    }

    if (!empty($filters['academic_year'])) {
        $where[] = "sp.academic_year = ?";
        $params[] = $filters['academic_year'];
    }

    if (!empty($filters['status'])) {
        $where[] = "sp.status = ?";
        $params[] = $filters['status'];
    }

    if (!empty($filters['start_date'])) {
        $where[] = "sp.payment_date >= ?";
        $params[] = $filters['start_date'];
    }

    if (!empty($filters['end_date'])) {
        $where[] = "sp.payment_date <= ?";
        $params[] = $filters['end_date'];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT sp.*, s.full_name, s.admission_no, c.class_name, u.full_name as verified_by_name
        FROM student_payments sp
        JOIN students s ON sp.student_id = s.id
        JOIN classes c ON sp.class_id = c.id
        LEFT JOIN users u ON sp.verified_by = u.id
        $whereClause
        ORDER BY sp.payment_date DESC, s.full_name
    ");

    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($payments as $payment) {
        $row = [
            $payment['full_name'],
            $payment['admission_no'],
            $payment['class_name'],
            $payment['receipt_number'],
            $payment['payment_date'],
            $payment['term'],
            $payment['academic_year'],
            $payment['total_amount'],
            $payment['amount_paid'],
            $payment['balance'],
            ucfirst(str_replace('_', ' ', $payment['payment_method'])),
            ucfirst($payment['payment_type']),
            ucfirst($payment['status']),
            $payment['verified_by_name'] ?: 'Not Verified'
        ];

        echo implode(',', array_map('cleanData', $row)) . "\n";
    }

    logExport('payments', '../exports/reports/' . $filename);
}

function exportFeeStructure($filters) {
    global $pdo;

    $filename = 'Fee_Structure_Export_' . date('Ymd_His') . '.csv';
    sendCsvHeaders($filename);

    // CSV headers
    $headers = [
        'Class',
        'Academic Year',
        'Term',
        'Fee Type',
        'Description',
        'Amount',
        'Due Date',
        'Late Fee %',
        'Installments Allowed',
        'Max Installments',
        'Status'
    ];

    echo implode(',', array_map('cleanData', $headers)) . "\n";

    // Build query with filters
    $where = [];
    $params = [];

    if (!empty($filters['class_id'])) {
        $where[] = "fs.class_id = ?";
        $params[] = $filters['class_id'];
    }

    if (!empty($filters['academic_year'])) {
        $where[] = "fs.academic_year = ?";
        $params[] = $filters['academic_year'];
    }

    if (!empty($filters['term'])) {
        $where[] = "fs.term = ?";
        $params[] = $filters['term'];
    }

    if (!empty($filters['fee_type'])) {
        $where[] = "fs.fee_type = ?";
        $params[] = $filters['fee_type'];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT fs.*, c.class_name
        FROM fee_structure fs
        JOIN classes c ON fs.class_id = c.id
        $whereClause
        ORDER BY c.class_name, fs.fee_type
    ");

    $stmt->execute($params);
    $fee_structures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fee_structures as $fee) {
        $row = [
            $fee['class_name'],
            $fee['academic_year'],
            $fee['term'],
            ucfirst($fee['fee_type']),
            $fee['description'] ?: '',
            $fee['amount'],
            $fee['due_date'] ?: '',
            $fee['late_fee_percentage'] ?: 0,
            $fee['allow_installments'] ? 'Yes' : 'No',
            $fee['max_installments'] ?: 1,
            ucfirst($fee['status'])
        ];

        echo implode(',', array_map('cleanData', $row)) . "\n";
    }

    logExport('fee_structure', '../exports/reports/' . $filename);
}

function exportStaff($filters) {
    global $pdo;

    $filename = 'Staff_Export_' . date('Ymd_His') . '.csv';
    sendCsvHeaders($filename);

    // CSV headers
    $headers = [
        'Staff ID',
        'Full Name',
        'Designation',
        'Department',
        'Email',
        'Phone',
        'Qualification',
        'Employment Date',
        'Employment Type',
        'Status'
    ];

    echo implode(',', array_map('cleanData', $headers)) . "\n";

    // Build query with filters
    $where = [];
    $params = [];

    if (!empty($filters['department'])) {
        $where[] = "department = ?";
        $params[] = $filters['department'];
    }

    if (!empty($filters['designation'])) {
        $where[] = "designation = ?";
        $params[] = $filters['designation'];
    }

    if (!empty($filters['status'])) {
        $where[] = "is_active = ?";
        $params[] = $filters['status'] === 'active' ? 1 : 0;
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT * FROM users
        WHERE role IN ('teacher', 'staff', 'principal')
        $whereClause
        ORDER BY full_name
    ");

    $stmt->execute($params);
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($staff as $member) {
        $row = [
            $member['staff_id'] ?: '',
            $member['full_name'],
            $member['designation'] ?: '',
            $member['department'] ?: '',
            $member['email'] ?: '',
            $member['phone'] ?: '',
            $member['qualification'] ?: '',
            $member['date_employed'] ? date('Y-m-d', strtotime($member['date_employed'])) : '',
            ucfirst($member['employment_type'] ?: ''),
            $member['is_active'] ? 'Active' : 'Inactive'
        ];

        echo implode(',', array_map('cleanData', $row)) . "\n";
    }

    logExport('staff', '../exports/reports/' . $filename);
}

function exportClasses($filters) {
    global $pdo;

    $filename = 'Classes_Export_' . date('Ymd_His') . '.csv';
    sendCsvHeaders($filename);

    // CSV headers
    $headers = [
        'Class Name',
        'Class Teacher',
        'Total Students',
        'Created Date'
    ];

    echo implode(',', array_map('cleanData', $headers)) . "\n";

    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name as teacher_name,
               COUNT(s.id) as student_count
        FROM classes c
        LEFT JOIN users u ON c.assigned_teacher_id = u.id
        LEFT JOIN students s ON c.id = s.class_id AND s.is_active = 1
        GROUP BY c.id
        ORDER BY c.class_name
    ");

    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($classes as $class) {
        $row = [
            $class['class_name'],
            $class['teacher_name'] ?: 'Not Assigned',
            $class['student_count'],
            $class['created_at'] ? date('Y-m-d', strtotime($class['created_at'])) : ''
        ];

        echo implode(',', array_map('cleanData', $row)) . "\n";
    }

    logExport('classes', '../exports/reports/' . $filename);
}

function logExport($export_type, $file_path) {
    global $pdo;

    // Log the export
    $stmt = $pdo->prepare("
        INSERT INTO export_logs (user_id, export_type, file_path, exported_at, ip_address, status)
        VALUES (?, ?, ?, NOW(), ?, 'success')
    ");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $export_type,
        $file_path,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
}

exit;
?>
