<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth-check.php';

// Only principal/admin with school authentication
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();
$principal_id = $_SESSION['user_id']; // Define principal_id for database operations

// Get student ID from URL
$student_id = intval($_GET['id'] ?? 0);
if ($student_id <= 0) {
    header("Location: students.php");
    exit;
}

// Fetch student details with class and school info
$stmt = $pdo->prepare("
    SELECT s.*, c.class_name, sp.school_name, sp.school_address,
           sp.school_phone, sp.school_email, sp.school_logo as logo_path
    FROM students s
    JOIN classes c ON s.class_id = c.id
    CROSS JOIN school_profile sp
    WHERE s.id = ? AND s.school_id = ?
");
$stmt->execute([$student_id, $current_school_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    // Student not found or doesn't belong to this school
    header("Location: students.php");
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

// Fetch student documents
$stmt = $pdo->prepare("
    SELECT *
    FROM student_documents
    WHERE student_id = ?
    ORDER BY uploaded_at DESC
");
$stmt->execute([$student_id]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch attendance records (last 30 days)
$stmt = $pdo->prepare("
    SELECT *
    FROM attendance
    WHERE student_id = ?
    AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY date DESC
");
$stmt->execute([$student_id]);
$attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate attendance stats
$total_days = count($attendance);
$present_days = count(array_filter($attendance, function($a) { return $a['status'] === 'present'; }));
$absent_days = count(array_filter($attendance, function($a) { return $a['status'] === 'absent'; }));
$late_days = count(array_filter($attendance, function($a) { return $a['status'] === 'late'; }));
$attendance_percentage = $total_days > 0 ? round(($present_days / $total_days) * 100, 1) : 0;

// Handle POST actions (add note, delete student)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_note') {
        $note_text = trim($_POST['note'] ?? '');
        if (!empty($note_text)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO student_notes (student_id, teacher_id, note_text, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$student_id, $principal_id, $note_text]);
                header("Location: student_details.php?id=$student_id");
                exit;
            } catch (Exception $e) {
                $error = 'Failed to add note: ' . $e->getMessage();
            }
        } else {
            $error = 'Note cannot be empty.';
        }
    }

    elseif ($action === 'delete_student') {
        // Verify student exists and belongs to this school
        $stmt = $pdo->prepare("SELECT id FROM students WHERE id = ? AND school_id = ?");
        $stmt->execute([$student_id, $current_school_id]);
        if ($stmt->fetch()) {
            try {
                $pdo->beginTransaction();

                // Delete related records first
                $pdo->prepare("DELETE FROM student_documents WHERE student_id = ?")->execute([$student_id]);
                $pdo->prepare("DELETE FROM student_academic_history WHERE student_id = ?")->execute([$student_id]);
                $pdo->prepare("DELETE FROM student_notes WHERE student_id = ?")->execute([$student_id]);
                $pdo->prepare("DELETE FROM attendance WHERE student_id = ?")->execute([$student_id]);

                // Delete the student
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
                $stmt->execute([$student_id]);

                if ($stmt->rowCount() > 0) {
                    $pdo->commit();
                    header("Location: students.php?success=Student deleted successfully");
                    exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Delete failed: ' . $e->getMessage();
            }
        } else {
            $error = 'Student not found or access denied.';
        }
    }
}

// Handle PDF generation
if (isset($_GET['action']) && $_GET['action'] === 'download_pdf') {
    require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('SahabFormMaster');
    $pdf->SetAuthor('School Management System');
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
    if (!empty($student['passport_photo']) && file_exists('../' . $student['passport_photo'])) {
        $pdf->Image('../' . $student['passport_photo'], 160, 45, 25, 30);
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
    $pdf->Cell(0, 8, $student['date_of_birth'] ? date('d/m/Y', strtotime($student['date_of_birth'])) : 'N/A', 1, 1);

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
    $pdf->Output($filename, 'I');
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

    

    <style>
        .info-card {
            background: var(--gray-50);
            padding: 1rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-200);
        }

        .info-label {
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1.1rem;
            color: var(--gray-900);
            font-weight: 500;
        }

        /* Responsive Design for Student Details */
        @media (max-width: 1024px) {
            .student-header {
                flex-direction: column;
                text-align: center;
                gap: 1.5rem;
            }

            .student-header .photo-container {
                align-self: center;
            }

            .student-info-grid {
                grid-template-columns: 1fr;
                text-align: center;
            }
        }

        @media (max-width: 768px) {
            .main-content-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .student-header {
                padding: 1.5rem;
            }

            .student-header h1 {
                font-size: 1.5rem;
            }

            .action-buttons {
                flex-direction: column;
                gap: 0.75rem;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            .info-cards-grid {
                grid-template-columns: 1fr;
            }

            .documents-grid {
                gap: 0.75rem;
            }

            .document-item {
                padding: 0.75rem;
            }

            .document-actions {
                flex-direction: column;
                gap: 0.5rem;
                width: 100%;
            }

            .document-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .student-header {
                padding: 1rem;
            }

            .student-header .photo-container {
                width: 80px;
                height: 80px;
            }

            .student-header .photo-container img,
            .student-header .photo-container .placeholder-icon {
                font-size: 2rem;
            }

            .student-header h1 {
                font-size: 1.25rem;
            }

            .info-card {
                padding: 0.75rem;
            }

            .panel {
                padding: 1rem;
            }
        }
    </style>

</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>

            <!-- Principal Info and Logout -->
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon">🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📰</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link">
                            <span class="nav-icon">📔</span>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link active">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students Registration</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students-evaluations.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Students Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_class.php" class="nav-link">
                            <span class="nav-icon">🎓</span>
                            <span class="nav-text">Manage Classes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage-school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                                                            <li class="nav-item">
                        <a href="support.php" class="nav-link">
                            <span class="nav-icon">🛟</span>
                            <span class="nav-text">Support</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subscription.php" class="nav-link">
                            <span class="nav-icon">💳</span>
                            <span class="nav-text">Subscription</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <span class="nav-icon">📖</span>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">👤</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🚶</span>
                            <span class="nav-text">Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_timebook.php" class="nav-link">
                            <span class="nav-icon">⏰</span>
                            <span class="nav-text">Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_attendance.php" class="nav-link">
                            <span class="nav-icon">📋</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments_dashboard.php" class="nav-link">
                            <span class="nav-icon">💰</span>
                            <span class="nav-text">School Fees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="sessions.php" class="nav-link">
                            <span class="nav-icon">📅</span>
                            <span class="nav-text">School Sessions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_calendar.php" class="nav-link">
                            <span class="nav-icon">🗓️</span>
                            <span class="nav-text">School Calendar</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="applicants.php" class="nav-link">
                            <span class="nav-icon">📄</span>
                            <span class="nav-text">Applicants</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>👤 Student Details</h2>
                    <p>Comprehensive information about <?php echo htmlspecialchars($student['full_name']); ?></p>
                </div>
            </div>

            <!-- Dashboard Link -->
            <a href="students.php" class="dashboard-link">
                <i class="fas fa-arrow-left"></i> Back to Students
            </a>

            <!-- Error/Success Messages -->
            <?php if(isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Student Header -->
            <div class="panel student-header" style="margin-bottom: 2rem;">
                <div style="display: flex; align-items: center; gap: 2rem; margin-bottom: 2rem;">
                    <?php if (!empty($student['passport_photo']) && file_exists('../' . $student['passport_photo'])): ?>
                        <div class="photo-container" style="width: 100px; height: 100px; border-radius: 50%; overflow: hidden; flex-shrink: 0; border: 4px solid var(--primary-color);">
                            <img src="../<?php echo htmlspecialchars($student['passport_photo']); ?>" alt="Passport Photo" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    <?php else: ?>
                        <div class="photo-container" style="width: 100px; height: 100px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center; color: white; font-size: 2.5rem; flex-shrink: 0;">
                            <i class="fas fa-user placeholder-icon"></i>
                        </div>
                    <?php endif; ?>
                    <div style="flex: 1;">
                        <h1 style="margin: 0 0 0.5rem 0; font-size: 2rem; color: var(--primary-color);">
                            <?php echo htmlspecialchars($student['full_name']); ?>
                            <?php if($student['student_type'] === 'fresh'): ?>
                                <span class="badge badge-success" style="font-size: 0.8rem; margin-left: 1rem;">New Student</span>
                            <?php endif; ?>
                        </h1>
                        <div class="student-info-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-id-card" style="color: var(--primary-color);"></i>
                                <div>
                                    <div style="font-weight: 600; color: var(--gray-700);">Admission No</div>
                                    <div style="color: var(--gray-600);"><?php echo htmlspecialchars($student['admission_no']); ?></div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-school" style="color: var(--primary-color);"></i>
                                <div>
                                    <div style="font-weight: 600; color: var(--gray-700);">Class</div>
                                    <div style="color: var(--gray-600);"><?php echo htmlspecialchars($student['class_name']); ?></div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <i class="fas fa-<?php echo strtolower($student['gender']) === 'male' ? 'mars' : 'venus'; ?>" style="color: var(--primary-color);"></i>
                                <div>
                                    <div style="font-weight: 600; color: var(--gray-700);">Gender</div>
                                    <div style="color: var(--gray-600);"><?php echo htmlspecialchars($student['gender']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="action-buttons" style="display: flex; gap: 1rem; flex-wrap: wrap;">
                            <a href="?id=<?php echo $student['id']; ?>&action=download_pdf" class="btn btn-success">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                            <button type="button" class="btn btn-warning" onclick="editStudent(<?php echo $student['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit Student
                            </button>
                            <button type="button" class="btn btn-danger" onclick="deleteStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['full_name']); ?>')">
                                <i class="fas fa-trash"></i> Delete Student
                            </button>
                            <a href="students.php" class="btn">
                                <i class="fas fa-arrow-left"></i> Back to Students
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
                <!-- Main Content -->
                <div>
                    <!-- Personal Information -->
                    <div class="panel">
                        <h2 style="margin-top: 0;">
                            <i class="fas fa-user"></i> Personal Information
                        </h2>
                        <div class="info-cards-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
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
                                <div class="info-value"><?php echo $student['date_of_birth'] ? date('F d, Y', strtotime($student['date_of_birth'])) : 'N/A'; ?></div>
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
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
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

                    <!-- Student Documents -->
                    <div class="panel">
                        <h2>
                            <i class="fas fa-file-alt"></i> Student Documents
                        </h2>
                        <?php if (!empty($documents)): ?>
                            <div class="documents-grid" style="display: grid; gap: 1rem;">
                                <?php foreach ($documents as $doc): ?>
                                    <div class="document-item" style="border: 1px solid var(--gray-200); border-radius: var(--border-radius); padding: 1rem; background: var(--gray-50);">
                                        <div style="display: flex; align-items: center; justify-content: space-between;">
                                            <div style="flex: 1;">
                                                <div style="font-weight: 600; color: var(--gray-900); margin-bottom: 0.25rem;">
                                                    <?php echo htmlspecialchars($doc['document_name']); ?>
                                                </div>
                                                <div style="font-size: 0.85rem; color: var(--gray-600);">
                                                    <span style="text-transform: capitalize;"><?php echo htmlspecialchars($doc['document_type']); ?></span> •
                                                    Uploaded <?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?>
                                                </div>
                                            </div>
                                            <div class="document-actions" style="display: flex; gap: 0.5rem;">
                                                <?php
                                                $file_path = '../' . $doc['file_path'];
                                                $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                                                $is_image = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif']);
                                                $is_pdf = $file_extension === 'pdf';
                                                ?>

                                                <?php if (file_exists($file_path)): ?>
                                                    <?php if ($is_image): ?>
                                                        <button type="button" class="btn btn-small" onclick="viewDocument('<?php echo htmlspecialchars($file_path); ?>', 'image')">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    <?php elseif ($is_pdf): ?>
                                                        <button type="button" class="btn btn-small" onclick="viewDocument('<?php echo htmlspecialchars($file_path); ?>', 'pdf')">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    <?php endif; ?>

                                                    <a href="<?php echo htmlspecialchars($file_path); ?>" download class="btn btn-primary btn-small">
                                                        <i class="fas fa-download"></i> Download
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--danger-color); font-size: 0.8rem;">File not found</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                <i class="fas fa-file-alt" style="font-size: 2rem; opacity: 0.5; margin-bottom: 1rem;"></i>
                                <p>No documents available for this student.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recent Notes -->
                    <div class="panel">
                        <h2>
                            <i class="fas fa-sticky-note"></i> Recent Notes
                        </h2>
                        <?php if (!empty($notes)): ?>
                            <div style="space-y: 1rem;">
                                <?php foreach ($notes as $note): ?>
                                    <div style="border-left: 4px solid var(--primary-color); background: var(--gray-50); padding: 1rem; border-radius: var(--border-radius);">
                                        <div style="display: flex; justify-content: between; align-items: start; margin-bottom: 0.5rem;">
                                            <div style="font-weight: 600; color: var(--primary-color);">
                                                <?php echo htmlspecialchars($note['teacher_name']); ?>
                                            </div>
                                            <div style="font-size: 0.85rem; color: var(--gray-500);">
                                                <?php echo date('M d, Y H:i', strtotime($note['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div style="color: var(--gray-700);"><?php echo htmlspecialchars($note['note_text']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
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
                            <div style="width: 80px; height: 80px; border-radius: 50%; background: conic-gradient(var(--success-color) 0% <?php echo $attendance_percentage; ?>%, var(--gray-300) <?php echo $attendance_percentage; ?>% 100%); display: flex; align-items: center; justify-content: center; position: relative;">
                                <div style="width: 60px; height: 60px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center; font-weight: bold; color: var(--gray-700);">
                                    <?php echo $attendance_percentage; ?>%
                                </div>
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--gray-700);">Attendance Rate</div>
                                <div style="font-size: 0.9rem; color: var(--gray-500);">Last 30 days</div>
                            </div>
                        </div>

                        <div style="space-y: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="width: 12px; height: 12px; background: var(--success-color); border-radius: 2px;"></span>
                                    Present
                                </span>
                                <span style="font-weight: 600;"><?php echo $present_days; ?> days</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="width: 12px; height: 12px; background: var(--danger-color); border-radius: 2px;"></span>
                                    Absent
                                </span>
                                <span style="font-weight: 600;"><?php echo $absent_days; ?> days</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="width: 12px; height: 12px; background: var(--warning-color); border-radius: 2px;"></span>
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
                    </div>
                </div>
            </div>
        </main>
    </div>

<script>
    // Mobile Menu Toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarClose = document.getElementById('sidebarClose');

    mobileMenuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
        mobileMenuToggle.classList.toggle('active');
    });

    sidebarClose.addEventListener('click', () => {
        sidebar.classList.remove('active');
        mobileMenuToggle.classList.remove('active');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768) {
            if (sidebar && !sidebar.contains(e.target) && mobileMenuToggle && !mobileMenuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            }
        }
    });

    // Edit student function
    function editStudent(id) {
        // Redirect to edit page (you may need to create this)
        window.location.href = 'edit_student.php?id=' + id;
    }

    // Delete student function
    function deleteStudent(id, name) {
        if (confirm('Are you sure you want to delete student "' + name + '"? This action cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_student">
                <input type="hidden" name="id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    // View document function
    function viewDocument(filePath, type) {
        if (type === 'image') {
            // Open image in new tab
            window.open(filePath, '_blank');
        } else if (type === 'pdf') {
            // Open PDF in new tab
            window.open(filePath, '_blank');
        }
    }
</script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>



