<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Log reports access
log_super_action('view_reports', 'system', null, 'Accessed reports dashboard');

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['generate_report'])) {
            $report_type = $_POST['report_type'] ?? '';
            $format = $_POST['format'] ?? 'html';
            $filters = $_POST['filters'] ?? [];

            // Generate report based on type
            switch ($report_type) {
                case 'students':
                    generateStudentsReport($format, $filters);
                    break;
                case 'attendance':
                    generateAttendanceReport($format, $filters);
                    break;
                case 'payments':
                    generatePaymentsReport($format, $filters);
                    break;
                case 'academic_performance':
                    generateAcademicPerformanceReport($format, $filters);
                    break;
                case 'user_activity':
                    generateUserActivityReport($format, $filters);
                    break;
                case 'system_usage':
                    generateSystemUsageReport($format, $filters);
                    break;
                default:
                    $message = 'Invalid report type selected.';
                    $message_type = 'error';
            }

        } elseif (isset($_POST['schedule_report'])) {
            // Schedule automated report (placeholder for future implementation)
            $message = 'Report scheduling feature coming soon.';
            $message_type = 'info';

        } elseif (isset($_POST['delete_report'])) {
            $report_id = $_POST['report_id'] ?? null;
            if ($report_id) {
                // Delete report file (placeholder)
                $message = 'Report deleted successfully.';
                $message_type = 'success';
            }
        }

    } catch (Exception $e) {
        $message = 'Error generating report: ' . $e->getMessage();
        $message_type = 'error';
        error_log('Reports error: ' . $e->getMessage());
    }
}

// Get available schools for filtering
$schools = [];
try {
    $stmt = $pdo->query("SELECT id, school_name, school_code FROM schools WHERE status = 'active' ORDER BY school_name");
    $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $schools = [];
}

// Get report history (placeholder - would be stored in database)
$report_history = [
    // Sample data
    [
        'id' => 1,
        'name' => 'Student Enrollment Report',
        'type' => 'students',
        'generated_at' => '2024-01-20 14:30:00',
        'format' => 'PDF',
        'size' => '2.4 MB',
        'status' => 'completed'
    ],
    [
        'id' => 2,
        'name' => 'Attendance Summary',
        'type' => 'attendance',
        'generated_at' => '2024-01-19 09:15:00',
        'format' => 'CSV',
        'size' => '1.8 MB',
        'status' => 'completed'
    ]
];

// Report generation functions
function generateStudentsReport($format, $filters) {
    global $pdo;

    // Build query
    $where = [];
    $params = [];

    if (!empty($filters['school_id'])) {
        $where[] = "s.school_id = ?";
        $params[] = $filters['school_id'];
    }

    if (!empty($filters['class_id'])) {
        $where[] = "s.class_id = ?";
        $params[] = $filters['class_id'];
    }

    if (!empty($filters['status'])) {
        $status = $filters['status'] === 'active' ? 1 : 0;
        $where[] = "s.is_active = ?";
        $params[] = $status;
    }

    if (!empty($filters['gender'])) {
        $where[] = "s.gender = ?";
        $params[] = $filters['gender'];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT s.*, c.class_name, sch.school_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN schools sch ON s.school_id = sch.id
        $whereClause
        ORDER BY sch.school_name, c.class_name, s.full_name
    ");
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        exportStudentsCSV($students);
    } elseif ($format === 'pdf') {
        exportStudentsPDF($students, $filters);
    } else {
        displayStudentsHTML($students, $filters);
    }
}

function generateAttendanceReport($format, $filters) {
    global $pdo;

    $where = [];
    $params = [];

    if (!empty($filters['school_id'])) {
        $where[] = "s.school_id = ?";
        $params[] = $filters['school_id'];
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
        SELECT a.*, s.full_name, s.admission_no, c.class_name, sch.school_name, u.full_name as recorded_by_name
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN schools sch ON s.school_id = sch.id
        LEFT JOIN users u ON a.recorded_by = u.id
        $whereClause
        ORDER BY a.date DESC, sch.school_name, s.full_name
    ");
    $stmt->execute($params);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        exportAttendanceCSV($attendance);
    } elseif ($format === 'pdf') {
        exportAttendancePDF($attendance, $filters);
    } else {
        displayAttendanceHTML($attendance, $filters);
    }
}

function generatePaymentsReport($format, $filters) {
    global $pdo;

    $where = [];
    $params = [];

    if (!empty($filters['school_id'])) {
        $where[] = "s.school_id = ?";
        $params[] = $filters['school_id'];
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
        SELECT sp.*, s.full_name, s.admission_no, c.class_name, sch.school_name, u.full_name as verified_by_name
        FROM student_payments sp
        JOIN students s ON sp.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        JOIN schools sch ON s.school_id = sch.id
        LEFT JOIN users u ON sp.verified_by = u.id
        $whereClause
        ORDER BY sp.payment_date DESC, sch.school_name, s.full_name
    ");
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        exportPaymentsCSV($payments);
    } elseif ($format === 'pdf') {
        exportPaymentsPDF($payments, $filters);
    } else {
        displayPaymentsHTML($payments, $filters);
    }
}

function generateAcademicPerformanceReport($format, $filters) {
    global $pdo;

    $where = [];
    $params = [];

    if (!empty($filters['school_id'])) {
        $where[] = "s.school_id = ?";
        $params[] = $filters['school_id'];
    }

    if (!empty($filters['class_id'])) {
        $where[] = "s.class_id = ?";
        $params[] = $filters['class_id'];
    }

    if (!empty($filters['term'])) {
        $where[] = "r.term = ?";
        $params[] = $filters['term'];
    }

    if (!empty($filters['academic_year'])) {
        $where[] = "r.academic_session = ?";
        $params[] = $filters['academic_year'];
    }

    if (!empty($filters['subject_id'])) {
        $where[] = "r.subject_id = ?";
        $params[] = $filters['subject_id'];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT r.*, s.full_name, s.admission_no, c.class_name, sch.school_name, sub.subject_name,
               ROUND(r.total_score, 2) as total_score
        FROM results r
        JOIN students s ON r.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        JOIN schools sch ON s.school_id = sch.id
        JOIN subjects sub ON r.subject_id = sub.id
        $whereClause
        ORDER BY sch.school_name, c.class_name, s.full_name, sub.subject_name
    ");
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        exportResultsCSV($results);
    } elseif ($format === 'pdf') {
        exportResultsPDF($results, $filters);
    } else {
        displayResultsHTML($results, $filters);
    }
}

function generateUserActivityReport($format, $filters) {
    global $pdo;

    $where = [];
    $params = [];

    if (!empty($filters['school_id'])) {
        $where[] = "al.school_id = ?";
        $params[] = $filters['school_id'];
    }

    if (!empty($filters['user_role'])) {
        $where[] = "u.role = ?";
        $params[] = $filters['user_role'];
    }

    if (!empty($filters['action'])) {
        $where[] = "al.action = ?";
        $params[] = $filters['action'];
    }

    if (!empty($filters['start_date'])) {
        $where[] = "al.created_at >= ?";
        $params[] = $filters['start_date'] . ' 00:00:00';
    }

    if (!empty($filters['end_date'])) {
        $where[] = "al.created_at <= ?";
        $params[] = $filters['end_date'] . ' 23:59:59';
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT al.*, u.full_name, u.email, u.role, sch.school_name
        FROM access_logs al
        LEFT JOIN users u ON al.user_id = u.id
        LEFT JOIN schools sch ON al.school_id = sch.id
        $whereClause
        ORDER BY al.created_at DESC
        LIMIT 10000
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        exportActivityCSV($logs);
    } elseif ($format === 'pdf') {
        exportActivityPDF($logs, $filters);
    } else {
        displayActivityHTML($logs, $filters);
    }
}

function generateSystemUsageReport($format, $filters) {
    global $pdo;

    // Get system statistics
    $stats = [];

    // User counts by role
    $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
    $stats['user_counts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // School statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total_schools, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_schools FROM schools");
    $stats['school_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // Database size
    $stmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb FROM information_schema.tables WHERE table_schema = DATABASE()");
    $stats['db_size'] = $stmt->fetchColumn();

    // Recent activity (last 30 days)
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_actions, COUNT(DISTINCT user_id) as active_users FROM access_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $stats['recent_activity'] = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        exportSystemStatsCSV($stats);
    } elseif ($format === 'pdf') {
        exportSystemStatsPDF($stats);
    } else {
        displaySystemStatsHTML($stats);
    }
}

// CSV Export functions
function exportStudentsCSV($students) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_report_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Admission No', 'Full Name', 'Class', 'School', 'Gender', 'Date of Birth', 'Phone', 'Email', 'Address', 'Guardian Name', 'Guardian Phone', 'Enrollment Date', 'Status']);

    foreach ($students as $student) {
        fputcsv($output, [
            $student['admission_no'],
            $student['full_name'],
            $student['class_name'],
            $student['school_name'],
            $student['gender'],
            $student['dob'] ?: '',
            $student['phone'] ?: '',
            '', // Email not in schema
            $student['address'] ?: '',
            $student['guardian_name'] ?: '',
            $student['guardian_phone'] ?: '',
            $student['enrollment_date'] ?: '',
            $student['is_active'] ? 'Active' : 'Inactive'
        ]);
    }
    fclose($output);
    exit;
}

function exportAttendanceCSV($attendance) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student Name', 'Admission No', 'Class', 'School', 'Date', 'Status', 'Recorded By', 'Notes']);

    foreach ($attendance as $record) {
        fputcsv($output, [
            $record['full_name'],
            $record['admission_no'],
            $record['class_name'],
            $record['school_name'],
            $record['date'],
            ucfirst($record['status']),
            $record['recorded_by_name'] ?: 'System',
            $record['notes'] ?: ''
        ]);
    }
    fclose($output);
    exit;
}

function exportPaymentsCSV($payments) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments_report_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student Name', 'Admission No', 'Class', 'School', 'Receipt No', 'Payment Date', 'Term', 'Academic Year', 'Total Amount', 'Amount Paid', 'Balance', 'Payment Method', 'Status', 'Verified By']);

    foreach ($payments as $payment) {
        fputcsv($output, [
            $payment['full_name'],
            $payment['admission_no'],
            $payment['class_name'],
            $payment['school_name'],
            $payment['receipt_number'],
            $payment['payment_date'],
            $payment['term'],
            $payment['academic_year'],
            $payment['total_amount'],
            $payment['amount_paid'],
            $payment['balance'],
            ucfirst(str_replace('_', ' ', $payment['payment_method'])),
            ucfirst($payment['status']),
            $payment['verified_by_name'] ?: 'Not Verified'
        ]);
    }
    fclose($output);
    exit;
}

function exportResultsCSV($results) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="academic_performance_report_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student Name', 'Admission No', 'Class', 'School', 'Subject', 'Term', 'Academic Year', 'First CA', 'Second CA', 'Exam Score', 'Total Score', 'Grade']);

    foreach ($results as $result) {
        // Calculate grade
        $total_score = $result['total_score'];
        if ($total_score >= 90) $grade = 'A';
        elseif ($total_score >= 80) $grade = 'B';
        elseif ($total_score >= 70) $grade = 'C';
        elseif ($total_score >= 60) $grade = 'D';
        elseif ($total_score >= 50) $grade = 'E';
        else $grade = 'F';

        fputcsv($output, [
            $result['full_name'],
            $result['admission_no'],
            $result['class_name'],
            $result['school_name'],
            $result['subject_name'],
            $result['term'],
            $result['academic_session'],
            $result['first_ca'],
            $result['second_ca'],
            $result['exam'],
            $total_score,
            $grade
        ]);
    }
    fclose($output);
    exit;
}

function exportActivityCSV($logs) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="user_activity_report_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['User', 'Role', 'School', 'Action', 'Resource Type', 'Resource ID', 'Status', 'IP Address', 'User Agent', 'Timestamp']);

    foreach ($logs as $log) {
        fputcsv($output, [
            $log['full_name'] ?: 'System',
            $log['role'] ?: '',
            $log['school_name'] ?: '',
            $log['action'],
            $log['resource_type'] ?: '',
            $log['resource_id'] ?: '',
            $log['status'],
            $log['ip_address'],
            substr($log['user_agent'], 0, 100), // Truncate user agent
            $log['created_at']
        ]);
    }
    fclose($output);
    exit;
}

function exportSystemStatsCSV($stats) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="system_usage_report_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');

    // User counts
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Schools', $stats['school_stats']['total_schools'] ?? 0]);
    fputcsv($output, ['Active Schools', $stats['school_stats']['active_schools'] ?? 0]);
    fputcsv($output, ['Database Size (MB)', $stats['db_size'] ?? 0]);
    fputcsv($output, ['Total Actions (30 days)', $stats['recent_activity']['total_actions'] ?? 0]);
    fputcsv($output, ['Active Users (30 days)', $stats['recent_activity']['active_users'] ?? 0]);

    // User counts by role
    fputcsv($output, ['', '']);
    fputcsv($output, ['Role', 'Count']);
    if (!empty($stats['user_counts'])) {
        foreach ($stats['user_counts'] as $user_count) {
            fputcsv($output, [$user_count['role'], $user_count['count']]);
        }
    }

    fclose($output);
    exit;
}

// HTML Display functions (for inline viewing)
function displayStudentsHTML($students, $filters) {
    echo '<div class="report-results">';
    echo '<h4>Student Enrollment Report</h4>';
    echo '<p><strong>Total Students:</strong> ' . count($students) . '</p>';

    if (!empty($students)) {
        echo '<div class="table-responsive"><table class="table table-striped">';
        echo '<thead><tr><th>Admission No</th><th>Name</th><th>Class</th><th>School</th><th>Gender</th><th>Status</th></tr></thead><tbody>';

        foreach ($students as $student) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($student['admission_no']) . '</td>';
            echo '<td>' . htmlspecialchars($student['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($student['class_name']) . '</td>';
            echo '<td>' . htmlspecialchars($student['school_name']) . '</td>';
            echo '<td>' . htmlspecialchars($student['gender']) . '</td>';
            echo '<td><span class="badge bg-' . ($student['is_active'] ? 'success' : 'secondary') . '">' . ($student['is_active'] ? 'Active' : 'Inactive') . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
    echo '</div>';
}

function displayAttendanceHTML($attendance, $filters) {
    echo '<div class="report-results">';
    echo '<h4>Attendance Report</h4>';
    echo '<p><strong>Total Records:</strong> ' . count($attendance) . '</p>';

    if (!empty($attendance)) {
        echo '<div class="table-responsive"><table class="table table-striped">';
        echo '<thead><tr><th>Student</th><th>Class</th><th>School</th><th>Date</th><th>Status</th><th>Recorded By</th></tr></thead><tbody>';

        foreach ($attendance as $record) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($record['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($record['class_name']) . '</td>';
            echo '<td>' . htmlspecialchars($record['school_name']) . '</td>';
            echo '<td>' . htmlspecialchars($record['date']) . '</td>';
            echo '<td><span class="badge bg-success">' . ucfirst($record['status']) . '</span></td>';
            echo '<td>' . htmlspecialchars($record['recorded_by_name'] ?: 'System') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
    echo '</div>';
}

function displayPaymentsHTML($payments, $filters) {
    echo '<div class="report-results">';
    echo '<h4>Payments Report</h4>';
    echo '<p><strong>Total Payments:</strong> ' . count($payments) . '</p>';

    if (!empty($payments)) {
        echo '<div class="table-responsive"><table class="table table-striped">';
        echo '<thead><tr><th>Student</th><th>Receipt No</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead><tbody>';

        foreach ($payments as $payment) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($payment['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($payment['receipt_number']) . '</td>';
            echo '<td>₦' . number_format($payment['amount_paid'], 2) . '</td>';
            echo '<td>' . htmlspecialchars($payment['payment_date']) . '</td>';
            echo '<td><span class="badge bg-success">' . ucfirst($payment['status']) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
    echo '</div>';
}

function displayResultsHTML($results, $filters) {
    echo '<div class="report-results">';
    echo '<h4>Academic Performance Report</h4>';
    echo '<p><strong>Total Results:</strong> ' . count($results) . '</p>';

    if (!empty($results)) {
        echo '<div class="table-responsive"><table class="table table-striped">';
        echo '<thead><tr><th>Student</th><th>Subject</th><th>Class</th><th>Total Score</th><th>Grade</th></tr></thead><tbody>';

        foreach ($results as $result) {
            $total_score = $result['total_score'];
            if ($total_score >= 90) $grade = 'A';
            elseif ($total_score >= 80) $grade = 'B';
            elseif ($total_score >= 70) $grade = 'C';
            elseif ($total_score >= 60) $grade = 'D';
            elseif ($total_score >= 50) $grade = 'E';
            else $grade = 'F';

            echo '<tr>';
            echo '<td>' . htmlspecialchars($result['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($result['subject_name']) . '</td>';
            echo '<td>' . htmlspecialchars($result['class_name']) . '</td>';
            echo '<td>' . number_format($total_score, 2) . '</td>';
            echo '<td><span class="badge bg-primary">' . $grade . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
    echo '</div>';
}

function displayActivityHTML($logs, $filters) {
    echo '<div class="report-results">';
    echo '<h4>User Activity Report</h4>';
    echo '<p><strong>Total Activities:</strong> ' . count($logs) . '</p>';

    if (!empty($logs)) {
        echo '<div class="table-responsive"><table class="table table-striped">';
        echo '<thead><tr><th>User</th><th>Action</th><th>Status</th><th>Timestamp</th></tr></thead><tbody>';

        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($log['full_name'] ?: 'System') . '</td>';
            echo '<td>' . htmlspecialchars($log['action']) . '</td>';
            echo '<td><span class="badge bg-' . ($log['status'] === 'success' ? 'success' : 'danger') . '">' . ucfirst($log['status']) . '</span></td>';
            echo '<td>' . htmlspecialchars($log['created_at']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
    echo '</div>';
}

function displaySystemStatsHTML($stats) {
    echo '<div class="report-results">';
    echo '<h4>System Usage Report</h4>';

    echo '<div class="row">';
    echo '<div class="col-md-6">';
    echo '<h5>School Statistics</h5>';
    echo '<p>Total Schools: ' . ($stats['school_stats']['total_schools'] ?? 0) . '</p>';
    echo '<p>Active Schools: ' . ($stats['school_stats']['active_schools'] ?? 0) . '</p>';
    echo '<p>Database Size: ' . ($stats['db_size'] ?? 0) . ' MB</p>';
    echo '</div>';

    echo '<div class="col-md-6">';
    echo '<h5>Recent Activity (30 days)</h5>';
    echo '<p>Total Actions: ' . ($stats['recent_activity']['total_actions'] ?? 0) . '</p>';
    echo '<p>Active Users: ' . ($stats['recent_activity']['active_users'] ?? 0) . '</p>';
    echo '</div>';
    echo '</div>';

    if (!empty($stats['user_counts'])) {
        echo '<h5>User Distribution by Role</h5>';
        echo '<div class="table-responsive"><table class="table table-striped">';
        echo '<thead><tr><th>Role</th><th>Count</th></tr></thead><tbody>';

        foreach ($stats['user_counts'] as $user_count) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($user_count['role']) . '</td>';
            echo '<td>' . number_format($user_count['count']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</div>';
}

// PDF Export functions (simplified - would use TCPDF in production)
function exportStudentsPDF($students, $filters) {
    // Placeholder - would implement with TCPDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="students_report_' . date('Ymd_His') . '.pdf"');
    echo 'PDF generation would be implemented here using TCPDF library.';
    exit;
}

function exportAttendancePDF($attendance, $filters) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Ymd_His') . '.pdf"');
    echo 'PDF generation would be implemented here using TCPDF library.';
    exit;
}

function exportPaymentsPDF($payments, $filters) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="payments_report_' . date('Ymd_His') . '.pdf"');
    echo 'PDF generation would be implemented here using TCPDF library.';
    exit;
}

function exportResultsPDF($results, $filters) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="academic_performance_report_' . date('Ymd_His') . '.pdf"');
    echo 'PDF generation would be implemented here using TCPDF library.';
    exit;
}

function exportActivityPDF($logs, $filters) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="user_activity_report_' . date('Ymd_His') . '.pdf"');
    echo 'PDF generation would be implemented here using TCPDF library.';
    exit;
}

function exportSystemStatsPDF($stats) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="system_usage_report_' . date('Ymd_His') . '.pdf"');
    echo 'PDF generation would be implemented here using TCPDF library.';
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #334155;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
            color: white;
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 24px;
            border-bottom: 1px solid #475569;
            background: rgba(255, 255, 255, 0.05);
        }

        .sidebar-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #f8fafc;
        }

        .sidebar-header p {
            font-size: 14px;
            color: #cbd5e1;
            margin-top: 4px;
        }

        .sidebar-nav {
            padding: 16px 0;
        }

        .nav-item {
            margin: 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 14px 24px;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border-left-color: #3b82f6;
        }

        .nav-link.active {
            background: rgba(59, 130, 246, 0.2);
            color: #f8fafc;
            border-left-color: #3b82f6;
        }

        .nav-icon {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 32px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 800;
            color: #1e293b;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .admin-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
        }

        .logout-btn {
            padding: 8px 16px;
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .logout-btn:hover {
            background: #b91c1c;
        }

        /* Messages */
        .message {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .message.info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        /* Reports Container */
        .reports-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        /* Tabs */
        .reports-tabs {
            display: flex;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            overflow-x: auto;
        }

        .tab-button {
            flex: 1;
            min-width: 120px;
            padding: 16px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #64748b;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }

        .tab-button:hover {
            color: #3b82f6;
            background: rgba(59, 130, 246, 0.05);
        }

        .tab-button.active {
            color: #3b82f6;
            border-bottom-color: #3b82f6;
            background: white;
        }

        /* Tab Content */
        .tab-content {
            display: none;
            padding: 32px;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
        }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        /* Report Cards */
        .report-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            transition: transform 0.3s ease;
        }

        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .report-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
        }

        .report-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .report-description {
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Filter Section */
        .filter-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        /* Report Results */
        .report-results {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-top: 24px;
            border: 1px solid #e2e8f0;
        }

        /* Tables */
        .table-responsive {
            margin-top: 16px;
        }

        .table {
            background: white;
        }

        .table th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #475569;
            padding: 12px;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        /* Badge styles */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            .reports-tabs {
                flex-direction: column;
            }
            .tab-content {
                padding: 20px;
            }
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Color schemes for report cards */
        .report-students .report-icon { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; }
        .report-attendance .report-icon { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .report-payments .report-icon { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .report-academic .report-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; }
        .report-activity .report-icon { background: linear-gradient(135deg, #06b6d4, #0891b2); color: white; }
        .report-system .report-icon { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-crown"></i> Super Admin</h2>
                <p>System Control Panel</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_schools.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-school"></i></span>
                            <span>Manage Schools</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_users.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-users"></i></span>
                            <span>Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="system_settings.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                            <span>System Settings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="audit_logs.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-history"></i></span>
                            <span>Audit Logs</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="database_tools.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-database"></i></span>
                            <span>Database Tools</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="analytics.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                            <span>Analytics</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link active">
                            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                            <span>Reports</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-file-alt"></i> System Reports</h1>
                <div class="header-actions">
                    <div class="admin-info">
                        <div class="admin-avatar">
                            <?php echo strtoupper(substr($super_admin['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($super_admin['full_name']); ?></div>
                            <div style="font-size: 12px; color: #64748b;">Super Administrator</div>
                        </div>
                    </div>
                    <a href="logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Reports Container -->
            <div class="reports-container">
                <!-- Tabs -->
                <div class="reports-tabs">
                    <button class="tab-button active" onclick="showTab('quick')">
                        <i class="fas fa-bolt"></i> Quick Reports
                    </button>
                    <button class="tab-button" onclick="showTab('custom')">
                        <i class="fas fa-sliders-h"></i> Custom Reports
                    </button>
                    <button class="tab-button" onclick="showTab('scheduled')">
                        <i class="fas fa-clock"></i> Scheduled
                    </button>
                    <button class="tab-button" onclick="showTab('history')">
                        <i class="fas fa-history"></i> Report History
                    </button>
                </div>

                <!-- Quick Reports Tab -->
                <div id="quick" class="tab-content active">
                    <div class="row">
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="report-card report-students">
                                <div class="report-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="report-title">Student Enrollment</div>
                                <div class="report-description">
                                    Generate comprehensive student enrollment reports across all schools.
                                </div>
                                <div class="mt-3">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="report_type" value="students">
                                        <input type="hidden" name="format" value="html">
                                        <button type="submit" name="generate_report" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline; margin-left: 8px;">
                                        <input type="hidden" name="report_type" value="students">
                                        <input type="hidden" name="format" value="csv">
                                        <button type="submit" name="generate_report" class="btn btn-success btn-sm">
                                            <i class="fas fa-download"></i> CSV
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="report-card report-attendance">
                                <div class="report-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="report-title">Attendance Reports</div>
                                <div class="report-description">
                                    View attendance patterns and statistics across all schools.
                                </div>
                                <div class="mt-3">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="report_type" value="attendance">
                                        <input type="hidden" name="format" value="html">
                                        <button type="submit" name="generate_report" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline; margin-left: 8px;">
                                        <input type="hidden" name="report_type" value="attendance">
                                        <input type="hidden" name="format" value="csv">
                                        <button type="submit" name="generate_report" class="btn btn-success btn-sm">
                                            <i class="fas fa-download"></i> CSV
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="report-card report-payments">
                                <div class="report-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="report-title">Payment Reports</div>
                                <div class="report-description">
                                    Analyze payment collection and outstanding balances.
                                </div>
                                <div class="mt-3">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="report_type" value="payments">
                                        <input type="hidden" name="format" value="html">
                                        <button type="submit" name="generate_report" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline; margin-left: 8px;">
                                        <input type="hidden" name="report_type" value="payments">
                                        <input type="hidden" name="format" value="csv">
                                        <button type="submit" name="generate_report" class="btn btn-success btn-sm">
                                            <i class="fas fa-download"></i> CSV
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="report-card report-academic">
                                <div class="report-icon">
                                    <i class="fas fa-graduation-cap"></i>
                                </div>
                                <div class="report-title">Academic Performance</div>
                                <div class="report-description">
                                    View student grades and academic performance metrics.
                                </div>
                                <div class="mt-3">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="report_type" value="academic_performance">
                                        <input type="hidden" name="format" value="html">
                                        <button type="submit" name="generate_report" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline; margin-left: 8px;">
                                        <input type="hidden" name="report_type" value="academic_performance">
                                        <input type="hidden" name="format" value="csv">
                                        <button type="submit" name="generate_report" class="btn btn-success btn-sm">
                                            <i class="fas fa-download"></i> CSV
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="report-card report-activity">
                                <div class="report-icon">
                                    <i class="fas fa-activity"></i>
                                </div>
                                <div class="report-title">User Activity</div>
                                <div class="report-description">
                                    Monitor user actions and system access patterns.
                                </div>
                                <div class="mt-3">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="report_type" value="user_activity">
                                        <input type="hidden" name="format" value="html">
                                        <button type="submit" name="generate_report" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline; margin-left: 8px;">
                                        <input type="hidden" name="report_type" value="user_activity">
                                        <input type="hidden" name="format" value="csv">
                                        <button type="submit" name="generate_report" class="btn btn-success btn-sm">
                                            <i class="fas fa-download"></i> CSV
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="report-card report-system">
                                <div class="report-icon">
                                    <i class="fas fa-server"></i>
                                </div>
                                <div class="report-title">System Usage</div>
                                <div class="report-description">
                                    System statistics and usage analytics.
                                </div>
                                <div class="mt-3">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="report_type" value="system_usage">
                                        <input type="hidden" name="format" value="html">
                                        <button type="submit" name="generate_report" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline; margin-left: 8px;">
                                        <input type="hidden" name="report_type" value="system_usage">
                                        <input type="hidden" name="format" value="csv">
                                        <button type="submit" name="generate_report" class="btn btn-success btn-sm">
                                            <i class="fas fa-download"></i> CSV
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Custom Reports Tab -->
                <div id="custom" class="tab-content">
                    <div class="filter-section">
                        <h4 class="mb-4"><i class="fas fa-filter"></i> Custom Report Builder</h4>
                        <form method="POST" id="customReportForm">
                            <input type="hidden" name="generate_report" value="1">

                            <div class="filter-grid">
                                <div class="form-group">
                                    <label>Report Type</label>
                                    <select name="report_type" id="customReportType" required>
                                        <option value="">Select Report Type</option>
                                        <option value="students">Student Enrollment</option>
                                        <option value="attendance">Attendance Records</option>
                                        <option value="payments">Payment Records</option>
                                        <option value="academic_performance">Academic Performance</option>
                                        <option value="user_activity">User Activity</option>
                                        <option value="system_usage">System Usage</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>School</label>
                                    <select name="filters[school_id]">
                                        <option value="">All Schools</option>
                                        <?php foreach ($schools as $school): ?>
                                            <option value="<?php echo $school['id']; ?>">
                                                <?php echo htmlspecialchars($school['school_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Format</label>
                                    <select name="format" required>
                                        <option value="html">View Online</option>
                                        <option value="csv">CSV Export</option>
                                        <option value="pdf">PDF Export</option>
                                    </select>
                                </div>

                                <div class="form-group" id="startDateField" style="display: none;">
                                    <label>Start Date</label>
                                    <input type="date" name="filters[start_date]">
                                </div>

                                <div class="form-group" id="endDateField" style="display: none;">
                                    <label>End Date</label>
                                    <input type="date" name="filters[end_date]">
                                </div>

                                <div class="form-group" id="statusField" style="display: none;">
                                    <label>Status</label>
                                    <select name="filters[status]">
                                        <option value="">All Status</option>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-chart-bar"></i> Generate Report
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="resetCustomForm()">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Scheduled Reports Tab -->
                <div id="scheduled" class="tab-content">
                    <div class="text-center py-5">
                        <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                        <h4>Scheduled Reports</h4>
                        <p class="text-muted">Automated report generation and email delivery features coming soon.</p>
                        <small class="text-muted">This feature will allow you to schedule reports to run automatically and receive them via email.</small>
                    </div>
                </div>

                <!-- Report History Tab -->
                <div id="history" class="tab-content">
                    <h4 class="mb-4"><i class="fas fa-history"></i> Report History</h4>

                    <?php if (empty($report_history)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                            <h5>No Reports Generated Yet</h5>
                            <p class="text-muted">Generated reports will appear here for easy access and re-downloading.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Report Name</th>
                                        <th>Type</th>
                                        <th>Generated</th>
                                        <th>Format</th>
                                        <th>Size</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_history as $report): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($report['name']); ?></td>
                                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($report['type']); ?></span></td>
                                            <td><?php echo htmlspecialchars($report['generated_at']); ?></td>
                                            <td><?php echo htmlspecialchars($report['format']); ?></td>
                                            <td><?php echo htmlspecialchars($report['size']); ?></td>
                                            <td><span class="badge bg-success"><?php echo htmlspecialchars($report['status']); ?></span></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="downloadReport(<?php echo $report['id']; ?>)">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteReport(<?php echo $report['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Tab switching functionality
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));

            // Remove active class from all tab buttons
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => button.classList.remove('active'));

            // Show selected tab content
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Custom report form handling
        document.getElementById('customReportType').addEventListener('change', function() {
            const reportType = this.value;
            const startDateField = document.getElementById('startDateField');
            const endDateField = document.getElementById('endDateField');
            const statusField = document.getElementById('statusField');

            // Show/hide fields based on report type
            if (['attendance', 'payments', 'user_activity'].includes(reportType)) {
                startDateField.style.display = 'block';
                endDateField.style.display = 'block';
            } else {
                startDateField.style.display = 'none';
                endDateField.style.display = 'none';
            }

            if (reportType === 'students') {
                statusField.style.display = 'block';
            } else {
                statusField.style.display = 'none';
            }
        });

        function resetCustomForm() {
            document.getElementById('customReportForm').reset();
            document.getElementById('startDateField').style.display = 'none';
            document.getElementById('endDateField').style.display = 'none';
            document.getElementById('statusField').style.display = 'none';
        }

        function downloadReport(reportId) {
            // Placeholder for download functionality
            alert('Download functionality would be implemented here');
        }

        function deleteReport(reportId) {
            if (confirm('Are you sure you want to delete this report?')) {
                // Create and submit delete form
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="report_id" value="' + reportId + '"><input type="hidden" name="delete_report" value="1">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Mobile sidebar toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
        }
    </script>
</body>
</html>
