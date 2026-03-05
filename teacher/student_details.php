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
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

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
        <link rel="stylesheet" href="../assets/css/tailwind.css">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            .error-wrap {
                max-width: 760px;
                margin: 0 auto;
            }

            .error-card {
                border-radius: 1.75rem;
                border: 1px solid rgba(15, 31, 45, 0.08);
                background: rgba(255, 255, 255, 0.96);
                box-shadow: 0 18px 40px rgba(15, 31, 45, 0.1);
                padding: 1.75rem;
            }

            .error-alert {
                display: flex;
                gap: 0.9rem;
                align-items: flex-start;
                border-radius: 1.2rem;
                border: 1px solid rgba(225, 29, 72, 0.18);
                background: #fff1f2;
                color: #be123c;
                padding: 1rem 1.1rem;
            }
        </style>
    </head>
    <body class="landing bg-slate-50">
        <header class="site-header">
            <div class="container nav-wrap">
                <div class="flex items-center gap-4">
                    <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <div class="flex items-center gap-3">
                        <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="h-10 w-10 rounded-xl object-cover">
                        <div class="hidden sm:block">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                            <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                    <a class="btn btn-outline" href="students.php">Students</a>
                    <a class="btn btn-primary" href="logout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </header>

        <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

        <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
            <aside class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:translate-x-0" data-sidebar>
                <?php include '../includes/teacher_sidebar.php'; ?>
            </aside>
            <main class="space-y-6">
                <div class="error-wrap">
                    <div class="error-card">
                        <div class="error-alert">
                            <i class="fas fa-exclamation-circle mt-1"></i>
                            <div>
                                <h1 class="mb-2 text-2xl font-display text-rose-700">Student record unavailable</h1>
                                <p><?php echo htmlspecialchars($error_message); ?></p>
                            </div>
                        </div>
                        <div class="mt-5 flex flex-wrap gap-3">
                            <a href="students.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left"></i>
                                <span>Back to Students</span>
                            </a>
                        </div>
                    </div>
                </div>
            </main>
        </div>
        <script>
            const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
            const sidebar = document.querySelector('[data-sidebar]');
            const overlay = document.querySelector('[data-sidebar-overlay]');
            const body = document.body;

            const openSidebar = () => {
                if (!sidebar || !overlay) return;
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('opacity-0', 'pointer-events-none');
                overlay.classList.add('opacity-100');
                body.classList.add('nav-open');
            };

            const closeSidebar = () => {
                if (!sidebar || !overlay) return;
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('opacity-0', 'pointer-events-none');
                overlay.classList.remove('opacity-100');
                body.classList.remove('nav-open');
            };

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', () => {
                    if (sidebar.classList.contains('-translate-x-full')) {
                        openSidebar();
                    } else {
                        closeSidebar();
                    }
                });
            }

            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }
        </script>
        <?php include '../includes/floating-button.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_note') {
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') {
            $stmt = $pdo->prepare("INSERT INTO student_notes (student_id, teacher_id, note_text, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$student_id, $teacher_id, $note]);
        }
        header("Location: student_details.php?id=" . $student_id);
        exit;
    }

    if ($action === 'attendance') {
        $date = $_POST['date'] ?? date('Y-m-d');
        $status = $_POST['status'] ?? 'present';

        $stmt = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ? AND school_id = ? LIMIT 1");
        $stmt->execute([$student_id, $date, $current_school_id]);
        $existing_attendance = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_attendance) {
            $stmt = $pdo->prepare("UPDATE attendance SET status = ?, recorded_by = ?, recorded_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $teacher_id, $existing_attendance['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO attendance (student_id, class_id, date, status, recorded_by, notes, school_id, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$student_id, $student['class_id'], $date, $status, $teacher_id, '', $current_school_id]);
        }

        header("Location: student_details.php?id=" . $student_id);
        exit;
    }
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
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="landing bg-slate-50">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="h-10 w-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                <a class="btn btn-outline" href="students.php">Students</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:translate-x-0" data-sidebar>
            <?php include '../includes/teacher_sidebar.php'; ?>
        </aside>
        <main class="space-y-6">
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
                        <a href="students.php" class="btn btn-outline">
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
                            <a href="class_attendance.php?class_id=<?php echo (int) $student['class_id']; ?>&date=<?php echo urlencode(date('Y-m-d')); ?>" class="btn btn-primary btn-small" style="width: 100%;">
                                <i class="fas fa-calendar-alt"></i> Open Class Attendance
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
            --brand-500: #168575;
            --brand-600: #0f6a5c;
            --success-500: #0f9f6e;
            --success-600: #0c7f58;
            --danger-500: #e11d48;
            --warning-500: #d97706;
            --slate-50: #f8fafc;
            --slate-200: #dbe3ee;
            --slate-500: #64748b;
            --slate-700: #334155;
            --ink-900: #0f1f2d;
            --shadow-soft: 0 14px 32px rgba(15, 31, 45, 0.08);
            --hero-gradient: linear-gradient(135deg, #0f6a5c 0%, #168575 55%, #1e9bb3 100%);
        }

        body {
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            color: var(--ink-900);
        }

        .main-container {
            max-width: 1120px;
            margin: 0 auto;
            width: 100%;
        }

        .modern-card {
            background: rgba(255, 255, 255, 0.97);
            border: 1px solid rgba(15, 31, 45, 0.08);
            border-radius: 1.75rem;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header-modern {
            position: relative;
            padding: 1.75rem;
            background: var(--hero-gradient);
            color: #fff;
        }

        .card-header-modern::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(45deg, rgba(255, 255, 255, 0.12) 0%, transparent 100%);
            pointer-events: none;
        }

        .card-title-modern {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.4rem;
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.85rem;
            font-weight: 600;
        }

        .card-subtitle-modern {
            position: relative;
            z-index: 1;
            color: rgba(255, 255, 255, 0.86);
        }

        .card-body-modern {
            padding: 1.75rem;
        }

        .info-card,
        .panel {
            border: 1px solid rgba(15, 31, 45, 0.08);
            box-shadow: var(--shadow-soft);
        }

        .info-card {
            background: linear-gradient(180deg, #ffffff 0%, #f8fbfd 100%);
            border-radius: 1rem;
            padding: 1rem;
        }

        .info-label {
            font-weight: 700;
            color: var(--slate-500);
            font-size: 0.78rem;
            margin-bottom: 0.3rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .info-value {
            font-weight: 700;
            color: var(--ink-900);
            font-size: 1rem;
        }

        .panel {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 1.35rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .panel h2,
        .panel h3 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0;
            margin-bottom: 1rem;
            color: var(--ink-900);
        }

        .breadcrumb-modern {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(15, 31, 45, 0.08);
            border-radius: 999px;
            padding: 0.75rem 1rem;
            list-style: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-soft);
        }

        .breadcrumb-modern a {
            color: var(--brand-600);
            text-decoration: none;
            font-weight: 700;
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .action-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .note-item {
            border-left: 4px solid var(--brand-500);
            background: var(--slate-50);
            padding: 1rem;
            border-radius: 1rem;
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

        .form-control {
            width: 100%;
            border-radius: 1rem;
            border: 1px solid rgba(15, 31, 45, 0.12);
            background: #fff;
            padding: 0.88rem 1rem;
            color: var(--ink-900);
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--brand-500);
            box-shadow: 0 0 0 4px rgba(22, 133, 117, 0.12);
        }

        .btn-success {
            background: var(--success-500);
            color: #fff;
            box-shadow: 0 12px 24px rgba(15, 159, 110, 0.22);
        }

        .btn-success:hover {
            background: var(--success-600);
        }

        @media (max-width: 1024px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 0;
            }

            .card-header-modern,
            .card-body-modern,
            .panel {
                padding: 1.25rem;
            }

            .card-title-modern {
                font-size: 1.55rem;
            }

            .breadcrumb-modern {
                border-radius: 1rem;
                flex-wrap: wrap;
                align-items: flex-start;
            }

            .action-row {
                flex-direction: column;
                align-items: stretch;
            }

            .action-row .btn,
            .btn-success,
            .btn-small {
                width: 100%;
            }
        }
    </style>

<script>
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const body = document.body;

    const openSidebar = () => {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('opacity-0', 'pointer-events-none');
        overlay.classList.add('opacity-100');
        body.classList.add('nav-open');
    };

    const closeSidebar = () => {
        if (!sidebar || !overlay) return;
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('opacity-0', 'pointer-events-none');
        overlay.classList.remove('opacity-100');
        body.classList.remove('nav-open');
    };

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            if (sidebar.classList.contains('-translate-x-full')) {
                openSidebar();
            } else {
                closeSidebar();
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }
</script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
