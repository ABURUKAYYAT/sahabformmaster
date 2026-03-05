<?php
// teacher/permissions.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/permissions_helpers.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}

// School authentication and context
$current_school_id = require_school_auth();
ensure_permissions_schema($pdo);

$user_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$message = '';
$error = '';

if (!empty($_SESSION['message'])) {
    $message = (string) $_SESSION['message'];
    unset($_SESSION['message']);
}
if (!empty($_SESSION['error'])) {
    $error = (string) $_SESSION['error'];
    unset($_SESSION['error']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_type = $_POST['request_type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $duration_hours = $_POST['duration_hours'] ?? '';
    $priority = $_POST['priority'] ?? 'medium';

    // Validate inputs
    $start_date_mysql = to_mysql_datetime_or_null($start_date);
    $end_date_mysql = to_mysql_datetime_or_null($end_date);

    if (empty($request_type) || empty($title) || $start_date_mysql === null) {
        $error = 'Request type, title and start date are required.';
    } else {
        try {
            // Handle file upload
            $attachment_path = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir_fs = __DIR__ . '/uploads/permissions/';
                $upload_dir_web = '../teacher/uploads/permissions/';
                if (!is_dir($upload_dir_fs)) {
                    mkdir($upload_dir_fs, 0755, true);
                }

                $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['attachment']['name']));
                $target_file_fs = $upload_dir_fs . $file_name;

                // Check file type and size
                $allowed_types = [
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ];
                $max_size = 5 * 1024 * 1024; // 5MB

                if (
                    in_array($_FILES['attachment']['type'], $allowed_types, true)
                    && $_FILES['attachment']['size'] <= $max_size
                ) {
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file_fs)) {
                        $attachment_path = $upload_dir_web . $file_name;
                    } else {
                        $error = 'Attachment upload failed. Please try again.';
                    }
                } else {
                    $error = 'Invalid attachment type or file too large (max 5MB).';
                }
            }

            if ($error !== '') {
                throw new RuntimeException($error);
            }

            // Insert permission request
            $stmt = $pdo->prepare(
                '
                INSERT INTO permissions (
                    school_id, staff_id, request_type, title, description,
                    start_date, end_date, duration_hours, priority,
                    attachment_path, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")
            '
            );

            $stmt->execute([
                $current_school_id,
                $user_id,
                $request_type,
                $title,
                $description,
                $start_date_mysql,
                $end_date_mysql,
                $duration_hours !== '' ? $duration_hours : null,
                $priority,
                $attachment_path,
            ]);

            $message = 'Permission request submitted successfully.';
        } catch (Exception $e) {
            $error = 'Error submitting request: ' . $e->getMessage();
        }
    }
}

// Handle export (PDF)
if (isset($_GET['export']) && $_GET['export'] === 'permissions_pdf') {
    require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

    $stmt = $pdo->prepare(
        '
        SELECT p.*, u.full_name as approved_by_name
        FROM permissions p
        LEFT JOIN users u ON p.approved_by = u.id
        WHERE p.staff_id = ? AND p.school_id = ?
        ORDER BY p.created_at DESC
    '
    );
    $stmt->execute([$user_id, $current_school_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get school info
    $schoolStmt = $pdo->prepare('SELECT * FROM school_profile WHERE school_id = ? LIMIT 1');
    $schoolStmt->execute([$current_school_id]);
    $school = $schoolStmt->fetch(PDO::FETCH_ASSOC);
    if (!$school) {
        $schoolStmt = $pdo->query('SELECT * FROM school_profile LIMIT 1');
        $school = $schoolStmt->fetch(PDO::FETCH_ASSOC);
    }

    $schoolName = $school['school_name'] ?? 'School';
    $schoolAddress = $school['school_address'] ?? '';
    $schoolPhone = $school['school_phone'] ?? '';
    $schoolEmail = $school['school_email'] ?? '';

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator($schoolName);
    $pdf->SetAuthor($schoolName);
    $pdf->SetTitle('Permission Requests');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 8, $schoolName, 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    if ($schoolAddress) {
        $pdf->Cell(0, 6, $schoolAddress, 0, 1, 'C');
    }
    if ($schoolPhone || $schoolEmail) {
        $pdf->Cell(0, 6, trim('Phone: ' . $schoolPhone . ' | Email: ' . $schoolEmail), 0, 1, 'C');
    }
    $pdf->Ln(4);

    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 8, 'Permission Requests Report', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Teacher: ' . $teacher_name, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Generated: ' . date('M d, Y H:i'), 0, 1, 'L');
    $pdf->Ln(3);

    $table = '<table border="1" cellpadding="4">';
    $table .= '<tr style="background-color:#f1f5f9;">';
    $table .= '<th width="10%"><b>ID</b></th>';
    $table .= '<th width="18%"><b>Type</b></th>';
    $table .= '<th width="30%"><b>Title</b></th>';
    $table .= '<th width="14%"><b>Date</b></th>';
    $table .= '<th width="10%"><b>Priority</b></th>';
    $table .= '<th width="10%"><b>Status</b></th>';
    $table .= '<th width="8%"><b>Hours</b></th>';
    $table .= '</tr>';

    foreach ($requests as $req) {
        $dateLabel = date('M d, Y', strtotime($req['start_date']));
        if (!empty($req['end_date'])) {
            $dateLabel .= ' - ' . date('M d, Y', strtotime($req['end_date']));
        }
        $hours = $req['duration_hours'] ? $req['duration_hours'] : '-';
        $table .= '<tr>';
        $table .= '<td>' . str_pad((string) $req['id'], 5, '0', STR_PAD_LEFT) . '</td>';
        $table .= '<td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $req['request_type']))) . '</td>';
        $table .= '<td>' . htmlspecialchars((string) $req['title']) . '</td>';
        $table .= '<td>' . htmlspecialchars($dateLabel) . '</td>';
        $table .= '<td>' . htmlspecialchars(ucfirst((string) $req['priority'])) . '</td>';
        $table .= '<td>' . htmlspecialchars(ucfirst((string) $req['status'])) . '</td>';
        $table .= '<td>' . htmlspecialchars((string) $hours) . '</td>';
        $table .= '</tr>';
    }

    $table .= '</table>';
    $pdf->writeHTML($table, true, false, true, false, '');

    $pdf->Output('permission_requests.pdf', 'D');
    exit();
}

// Fetch teacher's permission requests
try {
    $stmt = $pdo->prepare(
        '
        SELECT p.*, u.full_name as approved_by_name
        FROM permissions p
        LEFT JOIN users u ON p.approved_by = u.id
        WHERE p.staff_id = ? AND p.school_id = ?
        ORDER BY p.created_at DESC
    '
    );
    $stmt->execute([$user_id, $current_school_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Error fetching requests: ' . $e->getMessage();
    $requests = [];
}

// Get statistics
try {
    $totalRequests = count($requests);
    $pendingRequests = count(array_filter($requests, static fn($r) => ($r['status'] ?? '') === 'pending'));
    $approvedRequests = count(array_filter($requests, static fn($r) => ($r['status'] ?? '') === 'approved'));
    $rejectedRequests = count(array_filter($requests, static fn($r) => ($r['status'] ?? '') === 'rejected'));
    $cancelledRequests = count(array_filter($requests, static fn($r) => ($r['status'] ?? '') === 'cancelled'));
} catch (Exception $e) {
    $totalRequests = $pendingRequests = $approvedRequests = $rejectedRequests = $cancelledRequests = 0;
}

$priority_badge_classes = [
    'high' => 'priority-high',
    'medium' => 'priority-medium',
    'low' => 'priority-low',
];

$status_badge_classes = [
    'approved' => 'status-approved',
    'rejected' => 'status-rejected',
    'cancelled' => 'status-cancelled',
    'pending' => 'status-pending',
];

include '../includes/teacher_permissions_page.php';
