<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only teachers
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// School authentication and context
$current_school_id = require_school_auth();
$teacher_id = intval($_SESSION['user_id']);

// Get student ID from URL
$student_id = intval($_GET['id'] ?? 0);
if ($student_id <= 0) {
    header("Location: students.php");
    exit;
}

// Fetch student details with class and school info
$stmt = $pdo->prepare("
    SELECT s.*, c.class_name,
           sp.school_name, sp.school_address, sp.school_phone,
           sp.school_email, sp.school_logo as logo_path
    FROM students s
    JOIN classes c ON s.class_id = c.id AND c.school_id = ?
    LEFT JOIN school_profile sp ON sp.school_id = s.school_id
    WHERE s.id = ? AND s.school_id = ?
");
$stmt->execute([$current_school_id, $student_id, $current_school_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $error_message = 'Student not found or school profile missing for this school.';
}

// Verify teacher access
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM students s
    WHERE s.id = ? AND s.school_id = ?
    AND (
        EXISTS (SELECT 1 FROM subject_assignments sa WHERE sa.class_id = s.class_id AND sa.teacher_id = ?)
        OR EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = s.class_id AND ct.teacher_id = ?)
    )
");
$stmt->execute([$student_id, $current_school_id, $teacher_id, $teacher_id]);
if ($stmt->fetchColumn() == 0) {
    $error_message = 'Access denied. You can only view students in your assigned classes.';
}

if (!empty($error_message)) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Student Details | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
        <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
        <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    </head>
    <body>
        <?php include '../includes/mobile_navigation.php'; ?>
        <header class="dashboard-header">
            <div class="header-container">
                <div class="header-left">
                    <div class="school-logo-container">
                        <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                        <div class="school-info">
                            <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                            <p class="school-tagline">Student Details</p>
                        </div>
                    </div>
                </div>
                <div class="header-right">
                    <div class="teacher-info">
                        <p class="teacher-label">Teacher</p>
                        <span class="teacher-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
                    </div>
                    <a href="logout.php" class="btn-logout">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </header>
        <div class="dashboard-container">
            <?php include '../includes/teacher_sidebar.php'; ?>
            <main class="main-content">
                <div class="main-container">
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                    <a href="students.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Students</span>
                    </a>
                </div>
            </main>
        </div>
        <?php include '../includes/floating-button.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

// Fetch student notes
$stmt = $pdo->prepare("
    SELECT sn.*, u.full_name as teacher_name
    FROM student_notes sn
    JOIN users u ON sn.teacher_id = u.id
    WHERE sn.student_id = ?
    ORDER BY sn.created_at DESC
    LIMIT 10
");
$stmt->execute([$student_id]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch attendance records (last 30 days)
$stmt = $pdo->prepare("
    SELECT *
    FROM attendance
    WHERE student_id = ? AND school_id = ?
    AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY date DESC
");
$stmt->execute([$student_id, $current_school_id]);
$attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate attendance stats
$total_days = count($attendance);
$present_days = count(array_filter($attendance, function($a) { return $a['status'] === 'present'; }));
$absent_days = count(array_filter($attendance, function($a) { return $a['status'] === 'absent'; }));
$late_days = count(array_filter($attendance, function($a) { return $a['status'] === 'late'; }));
$attendance_percentage = $total_days > 0 ? round(($present_days / $total_days) * 100, 1) : 0;

// Handle PDF generation
if (isset($_GET['action']) && $_GET['action'] === 'download_pdf') {
    require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator($student['school_name'] ?? 'School');
    $pdf->SetAuthor($student['school_name'] ?? 'School');
    $pdf->SetTitle('Student Information - ' . $student['full_name']);
    $pdf->SetSubject('Student Profile');

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(15, 15, 15);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Add a page
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', '', 12);

    // School Header
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 15, $student['school_name'], 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, $student['school_address'], 0, 1, 'C');
    $pdf->Cell(0, 8, 'Phone: ' . $student['school_phone'] . ' | Email: ' . $student['school_email'], 0, 1, 'C');

    // Line separator
    $pdf->Ln(5);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(10);

    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 12, 'STUDENT INFORMATION PROFILE', 0, 1, 'C');
    $pdf->Ln(5);

    // Student Photo Placeholder (if exists)
    if (!empty($student['photo_path']) && file_exists('../' . $student['photo_path'])) {
        $pdf->Image('../' . $student['photo_path'], 160, 45, 25, 30);
    }

    // Student Basic Information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(0, 10, 'PERSONAL INFORMATION', 1, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 11);

    $pdf->Cell(50, 8, 'Full Name:', 1);
    $pdf->Cell(0, 8, $student['full_name'], 1, 1);

    $pdf->Cell(50, 8, 'Admission No:', 1);
    $pdf->Cell(0, 8, $student['admission_no'], 1, 1);

    $pdf->Cell(50, 8, 'Class:', 1);
    $pdf->Cell(0, 8, $student['class_name'], 1, 1);

    $pdf->Cell(50, 8, 'Gender:', 1);
    $pdf->Cell(0, 8, $student['gender'], 1, 1);

    $pdf->Cell(50, 8, 'Date of Birth:', 1);
    $pdf->Cell(0, 8, $student['dob'] ? date('d/m/Y', strtotime($student['dob'])) : 'N/A', 1, 1);

    $pdf->Cell(50, 8, 'Student Type:', 1);
    $pdf->Cell(0, 8, ucfirst($student['student_type']), 1, 1);

    $pdf->Cell(50, 8, 'Enrollment Date:', 1);
    $pdf->Cell(0, 8, $student['enrollment_date'] ? date('d/m/Y', strtotime($student['enrollment_date'])) : 'N/A', 1, 1);

    $pdf->Ln(5);

    // Contact Information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'CONTACT INFORMATION', 1, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 11);

    $pdf->Cell(50, 8, 'Phone:', 1);
    $pdf->Cell(0, 8, $student['phone'] ?: 'N/A', 1, 1);

    $pdf->Cell(50, 8, 'Guardian Name:', 1);
    $pdf->Cell(0, 8, $student['guardian_name'] ?: 'N/A', 1, 1);

    $pdf->Cell(50, 8, 'Guardian Phone:', 1);
    $pdf->Cell(0, 8, $student['guardian_phone'] ?: 'N/A', 1, 1);

    if (!empty($student['address'])) {
        $pdf->Cell(50, 8, 'Address:', 1);
        $pdf->MultiCell(0, 8, $student['address'], 1, 'L');
    }

    $pdf->Ln(5);

    // Attendance Information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'ATTENDANCE SUMMARY (Last 30 Days)', 1, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 11);

    $pdf->Cell(60, 8, 'Total Days:', 1);
    $pdf->Cell(0, 8, $total_days, 1, 1);

    $pdf->Cell(60, 8, 'Present Days:', 1);
    $pdf->Cell(0, 8, $present_days, 1, 1);

    $pdf->Cell(60, 8, 'Absent Days:', 1);
    $pdf->Cell(0, 8, $absent_days, 1, 1);

    $pdf->Cell(60, 8, 'Late Days:', 1);
    $pdf->Cell(0, 8, $late_days, 1, 1);

    $pdf->Cell(60, 8, 'Attendance Percentage:', 1);
    $pdf->Cell(0, 8, $attendance_percentage . '%', 1, 1);

    // Recent Notes
    if (!empty($notes)) {
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'RECENT NOTES', 1, 1, 'L', true);
        $pdf->SetFont('helvetica', '', 10);

        foreach ($notes as $note) {
            $pdf->Cell(30, 8, date('d/m/Y', strtotime($note['created_at'])), 1);
            $pdf->Cell(30, 8, $note['teacher_name'], 1);
            $pdf->MultiCell(0, 8, $note['note_text'], 1, 'L');
        }
    }

    // Footer
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 5, 'Generated by SahabFormMaster on ' . date('d/m/Y H:i'), 0, 1, 'C');
    $pdf->Cell(0, 5, 'This document contains confidential student information', 0, 1, 'C');

    // Output PDF
    $filename = 'student_' . $student['admission_no'] . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($student['full_name']); ?> | Student Details</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/teacher-students.css">
</head>
<body style="background-color: #f5f7fb;">
    <?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">Student Details</p>
                    </div>
                </div>
            </div>

            <!-- Teacher Info and Logout -->
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include '../includes/teacher_sidebar.php'; ?>
        <main class="main-content">
            <div class="main-container">
            <!-- Breadcrumb -->
            <div style="margin-bottom: 1.5rem;">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb-modern">
                        <li style="display: flex; align-items: center; gap: 0.5rem;">
                            <a href="students.php">
                                <i class="fas fa-users"></i> Students
                            </a>
                        </li>
                        <li style="color: var(--gray-400);">/</li>
                        <li style="color: var(--gray-700); font-weight: 600;">
                            <i class="fas fa-eye"></i> <?php echo htmlspecialchars($student['full_name']); ?> Details
                        </li>
                    </ol>
                </nav>
            </div>

            <!-- Student Header -->
            <div class="modern-card animate-fade-in-up">
                <div class="card-header-modern">
                    <h2 class="card-title-modern">
                        <i class="fas fa-user"></i>
                        <?php echo htmlspecialchars($student['full_name']); ?>
                    </h2>
                    <p class="card-subtitle-modern">
                        <?php echo htmlspecialchars($student['class_name']); ?> •
                        <?php echo htmlspecialchars($student['admission_no']); ?>
                    </p>
                </div>
                <div class="card-body-modern">
                    <div class="stats-grid">
                        <div class="info-card">
                            <div class="info-label">Admission No</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['admission_no']); ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Class</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['class_name']); ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Gender</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['gender']); ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Student Type</div>
                            <div class="info-value"><?php echo ucfirst($student['student_type']); ?> Student</div>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top: 1.5rem;">
                        <a href="edit_student.php?id=<?php echo $student['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Student
                        </a>
                        <a href="?id=<?php echo $student['id']; ?>&action=download_pdf" class="btn btn-success">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                        <a href="students.php" class="btn">
                            <i class="fas fa-arrow-left"></i> Back to Students
                        </a>
                    </div>
                </div>
            </div>

            <div class="details-grid">
                <!-- Main Content -->
                <div>
                    <!-- Personal Information -->
                    <div class="panel">
                        <h2 style="margin-top: 0;">
                            <i class="fas fa-user"></i> Personal Information
                        </h2>
                        <div class="stats-grid">
                            <div class="info-card">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Admission Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['admission_no']); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Date of Birth</div>
                                <div class="info-value"><?php echo $student['dob'] ? date('F d, Y', strtotime($student['dob'])) : 'N/A'; ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Gender</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['gender']); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Student Type</div>
                                <div class="info-value"><?php echo ucfirst($student['student_type']); ?> Student</div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Enrollment Date</div>
                                <div class="info-value"><?php echo $student['enrollment_date'] ? date('F d, Y', strtotime($student['enrollment_date'])) : 'N/A'; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="panel">
                        <h2>
                            <i class="fas fa-phone"></i> Contact Information
                        </h2>
                        <div class="stats-grid">
                            <div class="info-card">
                                <div class="info-label">Phone Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['phone'] ?: 'N/A'); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Guardian Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['guardian_name'] ?: 'N/A'); ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Guardian Phone</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['guardian_phone'] ?: 'N/A'); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($student['address'])): ?>
                            <div class="info-card" style="margin-top: 1.5rem;">
                                <div class="info-label">Address</div>
                                <div class="info-value" style="white-space: pre-line;"><?php echo htmlspecialchars($student['address']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recent Notes -->
                    <div class="panel">
                        <h2>
                            <i class="fas fa-sticky-note"></i> Recent Notes
                        </h2>
                        <?php if (!empty($notes)): ?>
                            <?php foreach ($notes as $note): ?>
                                <div class="note-item">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                                        <div style="font-weight: 600; color: var(--primary-600);">
                                            <?php echo htmlspecialchars($note['teacher_name']); ?>
                                        </div>
                                        <div style="font-size: 0.85rem; color: var(--gray-500);">
                                            <?php echo date('M d, Y H:i', strtotime($note['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div style="color: var(--gray-700);"><?php echo htmlspecialchars($note['note_text']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                <i class="fas fa-sticky-note" style="font-size: 2rem; opacity: 0.5; margin-bottom: 1rem;"></i>
                                <p>No notes available for this student.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sidebar -->
                <div>
                    <!-- School Information -->
                    <div class="panel">
                        <h3 style="margin-top: 0;">
                            <i class="fas fa-school"></i> School Information
                        </h3>
                        <div class="info-card" style="margin-bottom: 1rem;">
                            <div class="info-label">School Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['school_name']); ?></div>
                        </div>
                        <div class="info-card" style="margin-bottom: 1rem;">
                            <div class="info-label">Address</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['school_address']); ?></div>
                        </div>
                        <div class="info-card" style="margin-bottom: 1rem;">
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['school_phone']); ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['school_email']); ?></div>
                        </div>
                    </div>

                    <!-- Attendance Summary -->
                    <div class="panel">
                        <h3 style="margin-top: 0;">
                            <i class="fas fa-calendar-check"></i> Attendance Summary
                        </h3>
                        <p style="color: var(--gray-600); font-size: 0.9rem; margin-bottom: 1rem;">Last 30 days</p>

                        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
                            <div class="attendance-ring" style="background: conic-gradient(var(--success-500) 0% <?php echo $attendance_percentage; ?>%, var(--gray-200) <?php echo $attendance_percentage; ?>% 100%);">
                                <div style="width: 60px; height: 60px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center; font-weight: bold; color: var(--gray-700);">
                                    <?php echo $attendance_percentage; ?>%
                                </div>
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--gray-700);">Attendance Rate</div>
                                <div style="font-size: 0.9rem; color: var(--gray-500);">Last 30 days</div>
                            </div>
                        </div>

                        <div style="display: grid; gap: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="width: 12px; height: 12px; background: var(--success-500); border-radius: 2px;"></span>
                                    Present
                                </span>
                                <span style="font-weight: 600;"><?php echo $present_days; ?> days</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="width: 12px; height: 12px; background: var(--error-500); border-radius: 2px;"></span>
                                    Absent
                                </span>
                                <span style="font-weight: 600;"><?php echo $absent_days; ?> days</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="width: 12px; height: 12px; background: var(--warning-500); border-radius: 2px;"></span>
                                    Late
                                </span>
                                <span style="font-weight: 600;"><?php echo $late_days; ?> days</span>
                            </div>
                        </div>

                        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                            <a href="class_attendance.php?student_id=<?php echo $student['id']; ?>" class="btn btn-primary btn-small" style="width: 100%;">
                                <i class="fas fa-calendar-alt"></i> View Full Attendance
                            </a>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="panel">
                        <h3 style="margin-top: 0;">
                            <i class="fas fa-bolt"></i> Quick Actions
                        </h3>

                        <form method="POST" style="margin-bottom: 1rem;">
                            <input type="hidden" name="action" value="add_note">
                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                            <div style="margin-bottom: 0.75rem;">
                                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; font-size: 0.9rem;">Add Note</label>
                                <textarea name="note" placeholder="Enter note..." class="form-control" rows="3" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-small" style="width: 100%;">
                                <i class="fas fa-plus"></i> Add Note
                            </button>
                        </form>

                        <form method="POST">
                            <input type="hidden" name="action" value="attendance">
                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                            <div style="margin-bottom: 0.75rem;">
                                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; font-size: 0.9rem;">Mark Attendance</label>
                                <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" class="form-control" style="margin-bottom: 0.5rem;">
                                <select name="status" class="form-control">
                                    <option value="present">Present</option>
                                    <option value="absent">Absent</option>
                                    <option value="late">Late</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-success btn-small" style="width: 100%;">
                                <i class="fas fa-check"></i> Mark Attendance
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            </div>
        </main>
    </div>
    
    

    <style>
        :root {
            --primary-50: #eff6ff;
            --primary-100: #dbeafe;
            --primary-200: #bfdbfe;
            --primary-300: #93c5fd;
            --primary-400: #60a5fa;
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --primary-700: #1d4ed8;
            --primary-800: #1e40af;
            --primary-900: #1e3a8a;

            --accent-50: #fdf4ff;
            --accent-100: #fae8ff;
            --accent-200: #f5d0fe;
            --accent-300: #f0abfc;
            --accent-400: #e879f9;
            --accent-500: #d946ef;
            --accent-600: #c026d3;
            --accent-700: #a21caf;
            --accent-800: #86198f;
            --accent-900: #701a75;

            --success-50: #f0fdf4;
            --success-100: #dcfce7;
            --success-500: #22c55e;
            --success-600: #16a34a;
            --success-700: #15803d;

            --error-50: #fef2f2;
            --error-100: #fee2e2;
            --error-500: #ef4444;
            --error-600: #dc2626;

            --warning-50: #fffbeb;
            --warning-100: #fef3c7;
            --warning-500: #f59e0b;
            --warning-600: #d97706;

            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;

            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 32px rgba(0, 0, 0, 0.12);
            --shadow-strong: 0 16px 48px rgba(0, 0, 0, 0.15);

            --gradient-primary: linear-gradient(135deg, var(--primary-500) 0%, var(--primary-700) 100%);
            --gradient-accent: linear-gradient(135deg, var(--accent-500) 0%, var(--accent-700) 100%);
            --gradient-bg: linear-gradient(135deg, var(--primary-50) 0%, var(--accent-50) 50%, var(--primary-100) 100%);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f7fb;
            color: var(--gray-800);
            line-height: 1.6;
            min-height: 100vh;
        }

        .dashboard-header {
            background: #ffffff;
        }

        .dashboard-container .main-content {
            width: 100%;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .modern-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header-modern {
            padding: 2rem;
            background: var(--gradient-primary);
            color: white;
            position: relative;
        }

        .card-header-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%);
            pointer-events: none;
        }

        .card-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-subtitle-modern {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .card-body-modern {
            padding: 2rem;
        }

        .stat-card-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow-soft);
        }

        .info-card {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 14px;
            padding: 1rem;
            box-shadow: var(--shadow-soft);
        }

        .info-label {
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .info-value {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 1rem;
        }

        .panel {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 18px;
            padding: 1.5rem;
            box-shadow: var(--shadow-soft);
            margin-bottom: 1.5rem;
        }

        .breadcrumb-modern {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 14px;
            padding: 0.75rem 1rem;
            list-style: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-soft);
        }

        .breadcrumb-modern a {
            color: var(--primary-600);
            text-decoration: none;
            font-weight: 600;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .details-grid > div {
            min-width: 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .action-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .note-item {
            border-left: 4px solid var(--primary-500);
            background: var(--gray-50);
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
        }

        .attendance-ring {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        @media (max-width: 1024px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 1rem;
            }

            .card-header-modern,
            .card-body-modern {
                padding: 1.5rem;
            }

            .panel {
                padding: 1.25rem;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }
    </style>

<script>
    // Mobile Menu Toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarClose = document.getElementById('sidebarClose');

    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            if (mobileMenuToggle) mobileMenuToggle.classList.remove('active');
        });
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768) {
            if (sidebar && !sidebar.contains(e.target) && mobileMenuToggle && !mobileMenuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            }
        }
    });
</script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
