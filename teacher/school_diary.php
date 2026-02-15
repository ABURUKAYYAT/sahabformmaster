<?php
session_start();
require_once '../config/db.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
require_once '../includes/functions.php';
$current_school_id = require_school_auth();

$teacher_id = $_SESSION['user_id'];

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query - teachers see all activities - school-filtered
$query = "SELECT sd.*, u.full_name as coordinator_name
          FROM school_diary sd
          LEFT JOIN users u ON sd.coordinator_id = u.id
          WHERE sd.school_id = ?";
$params = [$current_school_id];

if ($search) {
    $query .= " AND (sd.activity_title LIKE ? OR sd.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($type_filter) {
    $query .= " AND sd.activity_type = ?";
    $params[] = $type_filter;
}

if ($date_from) {
    $query .= " AND sd.activity_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND sd.activity_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY sd.activity_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle activity details view
if (isset($_GET['id']) && !isset($_GET['pdf'])) {
    $activity_id = $_GET['id'];

    $stmt = $pdo->prepare("
        SELECT sd.*, u.full_name as coordinator_name
        FROM school_diary sd
        LEFT JOIN users u ON sd.coordinator_id = u.id
        WHERE sd.id = ?
    ");
    $stmt->execute([$activity_id]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        header("Location: school_diary.php");
        exit;
    }

    // Get attachments
    $attachments_stmt = $pdo->prepare("SELECT * FROM school_diary_attachments WHERE diary_id = ?");
    $attachments_stmt->execute([$activity_id]);
    $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Update view count
    $pdo->prepare("UPDATE school_diary SET view_count = view_count + 1 WHERE id = ?")->execute([$activity_id]);

    // Redirect to main page for activity details display
    header("Location: school_diary.php?id=" . $activity_id . "&view=details");
    exit;
}

// Handle PDF generation for activity details
if (isset($_GET['pdf']) && isset($_GET['id'])) {
    require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

    $activity_id = $_GET['id'];
    $stmt = $pdo->prepare("
        SELECT sd.*, u.full_name as coordinator_name
        FROM school_diary sd
        LEFT JOIN users u ON sd.coordinator_id = u.id
        WHERE sd.id = ?
    ");
    $stmt->execute([$activity_id]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activity) {
        // Get attachments
        $attachments_stmt = $pdo->prepare("SELECT * FROM school_diary_attachments WHERE diary_id = ?");
        $attachments_stmt->execute([$activity_id]);
        $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Create new PDF document
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('SahabFormMaster');
        $pdf->SetAuthor('School Administration');
        $pdf->SetTitle('Activity Details - ' . $activity['activity_title']);
        $pdf->SetSubject('School Activity Report');

        // Set default header data
        $pdf->SetHeaderData('', 0, 'SahabFormMaster - Activity Details', '', array(79, 70, 229), array(255, 255, 255));

        // Set header and footer fonts
        $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

        // Set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        // Set margins
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

        // Set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // Add a page
        $pdf->AddPage();

        // Set font
        $pdf->SetFont('helvetica', 'B', 16);

        // Title
        $pdf->Cell(0, 15, 'Activity Details', 0, 1, 'C', 0, '', 0, false, 'M', 'M');
        $pdf->Ln(5);

        // Activity Title
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetFillColor(102, 126, 234);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 10, $activity['activity_title'], 0, 1, 'L', 1);
        $pdf->Ln(2);

        // Basic Information
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(248, 250, 252);

        $basic_info = array(
            'Type' => $activity['activity_type'],
            'Date' => date('F j, Y', strtotime($activity['activity_date'])),
            'Time' => $activity['start_time'] ? $activity['start_time'] . ' - ' . $activity['end_time'] : 'All day',
            'Venue' => $activity['venue'] ?: 'Not specified',
            'Coordinator' => $activity['coordinator_name'] ?: 'Not assigned',
            'Target Audience' => $activity['target_audience'],
            'Status' => $activity['status']
        );

        foreach ($basic_info as $label => $value) {
            $pdf->Cell(40, 8, $label . ':', 1, 0, 'L', 1);
            $pdf->Cell(0, 8, $value, 1, 1, 'L', 1);
        }

        $pdf->Ln(5);

        // Description
        if ($activity['description']) {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetFillColor(102, 126, 234);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 8, 'Description', 0, 1, 'L', 1);
            $pdf->Ln(2);

            $pdf->SetFont('helvetica', '', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell(0, 6, $activity['description'], 0, 'L', false, 1, '', '', true, 0, false, false, 0, 'T', false);
            $pdf->Ln(3);
        }

        // Objectives
        if ($activity['objectives']) {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetFillColor(16, 185, 129);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 8, 'Objectives', 0, 1, 'L', 1);
            $pdf->Ln(2);

            $pdf->SetFont('helvetica', '', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell(0, 6, $activity['objectives'], 0, 'L', false, 1, '', '', true, 0, false, false, 0, 'T', false);
            $pdf->Ln(3);
        }

        // Resources
        if ($activity['resources']) {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetFillColor(245, 158, 11);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 8, 'Resources Required', 0, 1, 'L', 1);
            $pdf->Ln(2);

            $pdf->SetFont('helvetica', '', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell(0, 6, $activity['resources'], 0, 'L', false, 1, '', '', true, 0, false, false, 0, 'T', false);
            $pdf->Ln(3);
        }

        // Completion Details (if activity is completed)
        if ($activity['status'] === 'Completed') {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetFillColor(59, 130, 246);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 8, 'Completion Details', 0, 1, 'L', 1);
            $pdf->Ln(2);

            $pdf->SetFont('helvetica', '', 11);
            $pdf->SetTextColor(0, 0, 0);

            if ($activity['participant_count']) {
                $pdf->Cell(50, 8, 'Participants:', 1, 0, 'L', 0);
                $pdf->Cell(0, 8, $activity['participant_count'], 1, 1, 'L', 0);
            }

            if ($activity['winners_list']) {
                $pdf->Cell(50, 8, 'Winners:', 1, 0, 'L', 0);
                $pdf->Cell(0, 8, $activity['winners_list'], 1, 1, 'L', 0);
            }

            if ($activity['achievements']) {
                $pdf->Ln(3);
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 8, 'Achievements:', 0, 1, 'L', 0);
                $pdf->SetFont('helvetica', '', 11);
                $pdf->MultiCell(0, 6, $activity['achievements'], 0, 'L', false, 1, '', '', true, 0, false, false, 0, 'T', false);
            }

            if ($activity['feedback_summary']) {
                $pdf->Ln(3);
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 8, 'Feedback Summary:', 0, 1, 'L', 0);
                $pdf->SetFont('helvetica', '', 11);
                $pdf->MultiCell(0, 6, $activity['feedback_summary'], 0, 'L', false, 1, '', '', true, 0, false, false, 0, 'T', false);
            }
        }

        // Attachments
        if (!empty($attachments)) {
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetFillColor(139, 92, 246);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 8, 'Attachments', 0, 1, 'L', 1);
            $pdf->Ln(2);

            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            foreach ($attachments as $attachment) {
                $pdf->Cell(0, 6, '• ' . $attachment['file_name'] . ' (' . strtoupper($attachment['file_type']) . ')', 0, 1, 'L', 0);
            }
        }

        // Footer information
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 5, 'Generated by SahabFormMaster on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C', 0);

        // Output PDF
        $pdf->Output('activity_details_' . $activity_id . '.pdf', 'D');
        exit;
    }
}
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Diary - Teacher Portal | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Mobile Navigation Dropdown -->
    <div class="mobile-nav-dropdown" id="mobileNavDropdown">
        <div class="mobile-nav-header">
            <h3>Navigation</h3>
            <button class="mobile-nav-close" id="mobileNavClose">&times;</button>
        </div>
        <nav class="mobile-nav-menu">
            <a href="index.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="schoolfeed.php" class="mobile-nav-link">
                <i class="fas fa-newspaper"></i>
                <span>School Feeds</span>
            </a>
            <a href="school_diary.php" class="mobile-nav-link active">
                <i class="fas fa-book"></i>
                <span>School Diary</span>
            </a>
            <a href="students.php" class="mobile-nav-link">
                <i class="fas fa-users"></i>
                <span>Students</span>
            </a>
            <a href="results.php" class="mobile-nav-link">
                <i class="fas fa-chart-line"></i>
                <span>Results</span>
            </a>
            <a href="subjects.php" class="mobile-nav-link">
                <i class="fas fa-book-open"></i>
                <span>Subjects</span>
            </a>
            <a href="questions.php" class="mobile-nav-link">
                <i class="fas fa-question-circle"></i>
                <span>Questions</span>
            </a>
            <a href="lesson-plan.php" class="mobile-nav-link">
                <i class="fas fa-clipboard-list"></i>
                <span>Lesson Plans</span>
            </a>
            <a href="curricullum.php" class="mobile-nav-link">
                <i class="fas fa-graduation-cap"></i>
                <span>Curriculum</span>
            </a>
            <a href="teacher_class_activities.php" class="mobile-nav-link">
                <i class="fas fa-tasks"></i>
                <span>Class Activities</span>
            </a>
            <a href="student-evaluation.php" class="mobile-nav-link">
                <i class="fas fa-star"></i>
                <span>Evaluations</span>
            </a>
            <a href="class_attendance.php" class="mobile-nav-link">
                <i class="fas fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
            <a href="timebook.php" class="mobile-nav-link">
                <i class="fas fa-clock"></i>
                <span>Time Book</span>
            </a>
            <a href="permissions.php" class="mobile-nav-link">
                <i class="fas fa-key"></i>
                <span>Permissions</span>
            </a>
            <a href="payments.php" class="mobile-nav-link">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
        </nav>
    </div>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Teacher Portal</p>
                    </div>
                </div>
            </div>

            <!-- Teacher Info and Logout -->
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($teacher_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
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
                            <i class="fas fa-tachometer-alt nav-icon"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="schoolfeed.php" class="nav-link">
                            <i class="fas fa-newspaper nav-icon"></i>
                            <span class="nav-text">School Feeds</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link active">
                            <i class="fas fa-book nav-icon"></i>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <i class="fas fa-users nav-icon"></i>
                            <span class="nav-text">Students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="results.php" class="nav-link">
                            <i class="fas fa-chart-line nav-icon"></i>
                            <span class="nav-text">Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <i class="fas fa-book-open nav-icon"></i>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="questions.php" class="nav-link">
                            <i class="fas fa-question-circle nav-icon"></i>
                            <span class="nav-text">Questions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plan.php" class="nav-link">
                            <i class="fas fa-clipboard-list nav-icon"></i>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="curricullum.php" class="nav-link">
                            <i class="fas fa-graduation-cap nav-icon"></i>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="teacher_class_activities.php" class="nav-link">
                            <i class="fas fa-tasks nav-icon"></i>
                            <span class="nav-text">Class Activities</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="student-evaluation.php" class="nav-link">
                            <i class="fas fa-star nav-icon"></i>
                            <span class="nav-text">Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="class_attendance.php" class="nav-link">
                            <i class="fas fa-calendar-check nav-icon"></i>
                            <span class="nav-text">Attendance</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="timebook.php" class="nav-link">
                            <i class="fas fa-clock nav-icon"></i>
                            <span class="nav-text">Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <i class="fas fa-key nav-icon"></i>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments.php" class="nav-link">
                            <i class="fas fa-money-bill-wave nav-icon"></i>
                            <span class="nav-text">Payments</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>School Diary 📚</h2>
                    <p>Comprehensive view of all school activities and events</p>
                </div>
            </div>

            <!-- Activity Details View (when view=details) -->
            <?php if (isset($_GET['view']) && $_GET['view'] === 'details' && isset($_GET['id'])): ?>
                <div class="page-container">
                    <a href="school_diary.php" class="back-button">
                        <i class="fas fa-arrow-left"></i>
                        Back to School Diary
                    </a>

                    <!-- Activity Header -->
                    <div class="activity-header">
                        <h1 class="activity-title">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo htmlspecialchars($activity['activity_title']); ?>
                        </h1>

                        <div class="activity-meta">
                            <span class="activity-type-badge type-<?php echo strtolower($activity['activity_type']); ?>">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($activity['activity_type']); ?>
                            </span>
                            <span class="status-badge status-<?php echo strtolower($activity['status']); ?>">
                                <?php echo htmlspecialchars($activity['status']); ?>
                            </span>
                        </div>

                        <div class="action-buttons">
                            <a href="school_diary.php?pdf=1&id=<?php echo $activity['id']; ?>" class="btn-pdf" target="_blank">
                                <i class="fas fa-download"></i>
                                Download PDF Report
                            </a>
                        </div>
                    </div>

                    <!-- Stats Section (only for completed activities) -->
                    <?php if ($activity['status'] === 'Completed' && ($activity['participant_count'] || $activity['winners_list'])): ?>
                    <div class="stats-section">
                        <div class="stats-grid">
                            <?php if ($activity['participant_count']): ?>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $activity['participant_count']; ?></div>
                                <div class="stat-label">Participants</div>
                            </div>
                            <?php endif; ?>
                            <?php if ($activity['winners_list'] && strpos($activity['winners_list'], ',') !== false): ?>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo count(explode(',', $activity['winners_list'])); ?></div>
                                <div class="stat-label">Winners</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Content Grid -->
                    <div class="content-grid">
                        <!-- Basic Information -->
                        <div class="content-card">
                            <div class="content-header">
                                <h3 class="content-title">
                                    <i class="fas fa-info-circle"></i>
                                    Basic Information
                                </h3>
                            </div>
                            <div class="content-body">
                                <div class="info-list">
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-calendar"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Date</div>
                                            <div class="info-value"><?php echo date('l, F j, Y', strtotime($activity['activity_date'])); ?></div>
                                        </div>
                                    </div>

                                    <?php if ($activity['start_time'] && $activity['end_time']): ?>
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Time</div>
                                            <div class="info-value"><?php echo date('h:i A', strtotime($activity['start_time'])); ?> - <?php echo date('h:i A', strtotime($activity['end_time'])); ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Venue</div>
                                            <div class="info-value"><?php echo htmlspecialchars($activity['venue'] ?: 'Not specified'); ?></div>
                                        </div>
                                    </div>

                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Coordinator</div>
                                            <div class="info-value"><?php echo htmlspecialchars($activity['coordinator_name'] ?: 'Not assigned'); ?></div>
                                        </div>
                                    </div>

                                    <?php if ($activity['organizing_dept']): ?>
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-building"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Organizing Department</div>
                                            <div class="info-value"><?php echo htmlspecialchars($activity['organizing_dept']); ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($activity['target_audience']): ?>
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Target Audience</div>
                                            <div class="info-value"><?php echo htmlspecialchars($activity['target_audience']); ?>
                                            <?php if ($activity['target_classes']): ?>
                                                <br><small><?php echo htmlspecialchars($activity['target_classes']); ?></small>
                                            <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Description and Details -->
                        <div class="content-card">
                            <div class="content-header">
                                <h3 class="content-title">
                                    <i class="fas fa-align-left"></i>
                                    Description & Details
                                </h3>
                            </div>
                            <div class="content-body">
                                <?php if ($activity['description']): ?>
                                <div class="mb-4">
                                    <h5 class="text-dark mb-3">Description</h5>
                                    <div class="text-content">
                                        <?php echo nl2br(htmlspecialchars($activity['description'])); ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($activity['objectives']): ?>
                                <div class="mb-4">
                                    <h5 class="text-dark mb-3">
                                        <i class="fas fa-bullseye text-primary me-2"></i>
                                        Objectives
                                    </h5>
                                    <div class="text-content">
                                        <?php echo nl2br(htmlspecialchars($activity['objectives'])); ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($activity['resources']): ?>
                                <div class="mb-4">
                                    <h5 class="text-dark mb-3">
                                        <i class="fas fa-tools text-warning me-2"></i>
                                        Resources Required
                                    </h5>
                                    <div class="text-content">
                                        <?php echo nl2br(htmlspecialchars($activity['resources'])); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Completion Details (only for completed activities) -->
                    <?php if ($activity['status'] === 'Completed' && ($activity['achievements'] || $activity['winners_list'] || $activity['feedback_summary'])): ?>
                    <div class="content-card">
                        <div class="content-header">
                            <h3 class="content-title">
                                <i class="fas fa-trophy"></i>
                                Completion Details
                            </h3>
                        </div>
                        <div class="content-body">
                            <?php if ($activity['winners_list']): ?>
                            <div class="mb-4">
                                <h5 class="text-dark mb-3">
                                    <i class="fas fa-medal text-warning me-2"></i>
                                    Winners & Awards
                                </h5>
                                <div class="text-content">
                                    <?php echo nl2br(htmlspecialchars($activity['winners_list'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($activity['achievements']): ?>
                            <div class="mb-4">
                                <h5 class="text-dark mb-3">
                                    <i class="fas fa-star text-success me-2"></i>
                                    Achievements
                                </h5>
                                <div class="text-content">
                                    <?php echo nl2br(htmlspecialchars($activity['achievements'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($activity['feedback_summary']): ?>
                            <div class="mb-4">
                                <h5 class="text-dark mb-3">
                                    <i class="fas fa-comments text-info me-2"></i>
                                    Feedback Summary
                                </h5>
                                <div class="text-content">
                                    <?php echo nl2br(htmlspecialchars($activity['feedback_summary'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Attachments Section -->
                    <?php if (!empty($attachments)): ?>
                    <div class="attachments-section">
                        <div class="content-card">
                            <div class="content-header">
                                <h3 class="content-title">
                                    <i class="fas fa-paperclip"></i>
                                    Attachments (<?php echo count($attachments); ?>)
                                </h3>
                            </div>
                            <div class="content-body">
                                <div class="attachments-grid">
                                    <?php foreach ($attachments as $attachment): ?>
                                    <div class="attachment-card" onclick="openAttachment('<?php echo htmlspecialchars($attachment['file_path']); ?>', '<?php echo $attachment['file_type']; ?>')">
                                        <div class="attachment-preview">
                                            <?php if ($attachment['file_type'] === 'image'): ?>
                                                <img src="<?php echo htmlspecialchars($attachment['file_path']); ?>" alt="<?php echo htmlspecialchars($attachment['file_name']); ?>">
                                            <?php elseif ($attachment['file_type'] === 'video'): ?>
                                                <div class="file-icon">
                                                    <i class="fas fa-play-circle"></i>
                                                </div>
                                            <?php else: ?>
                                                <div class="file-icon">
                                                    <i class="fas fa-file"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="attachment-info">
                                            <div class="attachment-name">
                                                <?php echo htmlspecialchars($attachment['file_name']); ?>
                                            </div>
                                            <div class="attachment-meta">
                                                <i class="fas fa-tag"></i>
                                                <?php echo strtoupper($attachment['file_type']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Metadata Footer -->
                    <div class="content-card">
                        <div class="content-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <div class="info-icon mx-auto mb-2" style="background: var(--gradient-info);">
                                        <i class="fas fa-eye"></i>
                                    </div>
                                    <div class="info-label">Views</div>
                                    <div class="info-value fw-bold"><?php echo intval($activity['view_count']); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-icon mx-auto mb-2" style="background: var(--gradient-success);">
                                        <i class="fas fa-calendar-plus"></i>
                                    </div>
                                    <div class="info-label">Created</div>
                                    <div class="info-value fw-bold"><?php echo date('M j, Y', strtotime($activity['created_at'])); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-icon mx-auto mb-2" style="background: var(--gradient-warning);">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                    <div class="info-label">Last Updated</div>
                                    <div class="info-value fw-bold"><?php echo date('M j, Y', strtotime($activity['updated_at'] ?: $activity['created_at'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <style>
                    :root {
                        --primary-color: #4f46e5;
                        --primary-dark: #3730a3;
                        --secondary-color: #06b6d4;
                        --accent-color: #f59e0b;
                        --success-color: #10b981;
                        --warning-color: #f59e0b;
                        --error-color: #ef4444;
                        --info-color: #3b82f6;
                        --light-color: #f9fafb;
                        --dark-color: #1f2937;
                        --gray-color: #6b7280;
                        --card-bg: #ffffff;
                        --shadow: 0 20px 40px rgba(79, 70, 229, 0.15);
                        --shadow-hover: 0 30px 60px rgba(79, 70, 229, 0.25);
                        --radius: 20px;
                        --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                        --gradient-accent: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
                        --gradient-success: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
                        --gradient-warning: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
                        --gradient-info: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
                    }

                    * {
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                    }

                    body {
                        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                        min-height: 100vh;
                        color: var(--dark-color);
                        line-height: 1.6;
                    }

                    .page-container {
                        max-width: 1200px;
                        margin: 0 auto;
                        padding: 2rem;
                    }

                    .back-button {
                        display: inline-flex;
                        align-items: center;
                        gap: 0.5rem;
                        padding: 0.75rem 1.5rem;
                        background: var(--gradient-primary);
                        color: white;
                        text-decoration: none;
                        border-radius: 12px;
                        font-weight: 600;
                        transition: all 0.3s ease;
                        margin-bottom: 2rem;
                    }

                    .back-button:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 8px 25px rgba(79, 70, 229, 0.3);
                    }

                    .activity-header {
                        background: var(--card-bg);
                        border-radius: var(--radius);
                        box-shadow: var(--shadow);
                        padding: 3rem;
                        margin-bottom: 2rem;
                        position: relative;
                        overflow: hidden;
                    }

                    .activity-header::before {
                        content: '';
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        height: 4px;
                        background: var(--gradient-primary);
                    }

                    .activity-title {
                        font-family: 'Poppins', sans-serif;
                        font-size: 2.5rem;
                        font-weight: 700;
                        color: var(--dark-color);
                        margin-bottom: 1rem;
                        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
                        -webkit-background-clip: text;
                        -webkit-text-fill-color: transparent;
                        background-clip: text;
                    }

                    .activity-meta {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 1rem;
                        margin-bottom: 2rem;
                    }

                    .meta-item {
                        display: flex;
                        align-items: center;
                        gap: 0.5rem;
                        padding: 0.75rem 1rem;
                        background: var(--light-color);
                        border-radius: 12px;
                        font-size: 0.9rem;
                        color: var(--gray-color);
                        border: 1px solid rgba(79, 70, 229, 0.1);
                    }

                    .meta-item i {
                        color: var(--primary-color);
                    }

                    .activity-type-badge {
                        padding: 0.5rem 1rem;
                        border-radius: 20px;
                        font-size: 0.85rem;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }

                    .type-academics {
                        background: var(--gradient-success);
                        color: white;
                    }

                    .type-sports {
                        background: var(--gradient-accent);
                        color: var(--dark-color);
                    }

                    .type-cultural {
                        background: var(--gradient-secondary);
                        color: white;
                    }

                    .type-competition {
                        background: var(--gradient-warning);
                        color: var(--dark-color);
                    }

                    .status-badge {
                        padding: 0.5rem 1rem;
                        border-radius: 20px;
                        font-size: 0.85rem;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }

                    .status-upcoming { background: var(--gradient-info); color: white; }
                    .status-ongoing { background: var(--gradient-accent); color: var(--dark-color); }
                    .status-completed { background: var(--gradient-success); color: white; }
                    .status-cancelled { background: var(--gradient-secondary); color: white; }

                    .action-buttons {
                        display: flex;
                        gap: 1rem;
                        flex-wrap: wrap;
                    }

                    .btn-pdf {
                        display: inline-flex;
                        align-items: center;
                        gap: 0.5rem;
                        padding: 0.75rem 1.5rem;
                        background: var(--gradient-accent);
                        color: var(--dark-color);
                        text-decoration: none;
                        border-radius: 12px;
                        font-weight: 600;
                        transition: all 0.3s ease;
                        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
                    }

                    .btn-pdf:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
                    }

                    .content-grid {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 2rem;
                        margin-bottom: 2rem;
                    }

                    .content-card {
                        background: var(--card-bg);
                        border-radius: var(--radius);
                        box-shadow: var(--shadow);
                        overflow: hidden;
                        border: 1px solid rgba(79, 70, 229, 0.08);
                    }

                    .content-header {
                        padding: 1.5rem;
                        border-bottom: 1px solid #e5e7eb;
                        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                    }

                    .content-title {
                        font-size: 1.25rem;
                        font-weight: 700;
                        color: var(--dark-color);
                        margin-bottom: 0.5rem;
                        display: flex;
                        align-items: center;
                        gap: 0.5rem;
                    }

                    .content-title i {
                        color: var(--primary-color);
                    }

                    .content-body {
                        padding: 1.5rem;
                    }

                    .info-list {
                        display: flex;
                        flex-direction: column;
                        gap: 1rem;
                    }

                    .info-item {
                        display: flex;
                        align-items: flex-start;
                        gap: 1rem;
                    }

                    .info-icon {
                        width: 40px;
                        height: 40px;
                        border-radius: 50%;
                        background: var(--gradient-primary);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: white;
                        flex-shrink: 0;
                        margin-top: 0.25rem;
                    }

                    .info-content {
                        flex: 1;
                    }

                    .info-label {
                        font-weight: 600;
                        color: var(--dark-color);
                        margin-bottom: 0.25rem;
                    }

                    .info-value {
                        color: var(--gray-color);
                        line-height: 1.5;
                    }

                    .text-content {
                        color: var(--gray-color);
                        line-height: 1.7;
                        margin-bottom: 1rem;
                    }

                    .attachments-section {
                        margin-bottom: 2rem;
                    }

                    .attachments-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                        gap: 1rem;
                    }

                    .attachment-card {
                        background: var(--card-bg);
                        border: 2px solid #e5e7eb;
                        border-radius: 12px;
                        overflow: hidden;
                        transition: all 0.3s ease;
                        cursor: pointer;
                    }

                    .attachment-card:hover {
                        border-color: var(--primary-color);
                        transform: translateY(-3px);
                        box-shadow: 0 8px 25px rgba(79, 70, 229, 0.2);
                    }

                    .attachment-preview {
                        height: 120px;
                        background: #f8fafc;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        border-bottom: 1px solid #e5e7eb;
                    }

                    .attachment-preview img {
                        max-width: 100%;
                        max-height: 100%;
                        object-fit: cover;
                    }

                    .attachment-preview .file-icon {
                        font-size: 2.5rem;
                        color: var(--gray-color);
                    }

                    .attachment-info {
                        padding: 1rem;
                    }

                    .attachment-name {
                        font-weight: 600;
                        color: var(--dark-color);
                        margin-bottom: 0.5rem;
                        font-size: 0.9rem;
                        word-break: break-word;
                    }

                    .attachment-meta {
                        font-size: 0.8rem;
                        color: var(--gray-color);
                        display: flex;
                        align-items: center;
                        gap: 0.5rem;
                    }

                    .stats-section {
                        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
                        border-radius: var(--radius);
                        padding: 2rem;
                        margin-bottom: 2rem;
                        border: 2px solid rgba(59, 130, 246, 0.1);
                    }

                    .stats-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                        gap: 1.5rem;
                    }

                    .stat-item {
                        text-align: center;
                    }

                    .stat-number {
                        font-size: 2rem;
                        font-weight: 800;
                        color: #0369a1;
                        line-height: 1;
                        margin-bottom: 0.5rem;
                    }

                    .stat-label {
                        color: #64748b;
                        font-size: 0.9rem;
                        text-transform: uppercase;
                        letter-spacing: 1px;
                        font-weight: 600;
                    }

                    @media (max-width: 768px) {
                        .page-container {
                            padding: 1rem;
                        }

                        .activity-title {
                            font-size: 2rem;
                        }

                        .content-grid {
                            grid-template-columns: 1fr;
                        }

                        .activity-meta {
                            flex-direction: column;
                        }

                        .action-buttons {
                            flex-direction: column;
                        }

                        .stats-grid {
                            grid-template-columns: repeat(2, 1fr);
                        }
                    }

                    @media (max-width: 480px) {
                        .activity-header {
                            padding: 2rem 1.5rem;
                        }

                        .activity-title {
                            font-size: 1.75rem;
                        }

                        .stats-grid {
                            grid-template-columns: 1fr;
                        }

                        .attachments-grid {
                            grid-template-columns: 1fr;
                        }
                    }

                    /* Modal for image preview */
                    .image-modal .modal-dialog {
                        max-width: 90vw;
                        max-height: 90vh;
                    }

                    .image-modal img {
                        width: 100%;
                        height: auto;
                        max-height: 80vh;
                        object-fit: contain;
                    }
                </style>
            <?php else: ?>

            <!-- List View (Default) -->
            <div class="page-container">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="header-content">
                        <div class="header-left">
                            <h1 class="page-title">
                                <i class="fas fa-book"></i>
                                School Diary
                            </h1>
                            <p class="page-subtitle">Comprehensive view of all school activities and events</p>
                        </div>
                        <div class="header-right">
                            <a href="index.php" class="btn-dashboard">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </div>
                    </div>

                    <!-- Stats Overview -->
                    <div class="stats-overview">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="stat-value"><?php echo count($activities); ?></div>
                            <div class="stat-label">Total Activities</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="stat-value">
                                <?php echo count(array_filter($activities, fn($a) => $a['activity_type'] === 'Academics')); ?>
                            </div>
                            <div class="stat-label">Academic Events</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <div class="stat-value">
                                <?php echo count(array_filter($activities, fn($a) => $a['activity_type'] === 'Sports')); ?>
                            </div>
                            <div class="stat-label">Sports Events</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-palette"></i>
                            </div>
                            <div class="stat-value">
                                <?php echo count(array_filter($activities, fn($a) => $a['activity_type'] === 'Cultural')); ?>
                            </div>
                            <div class="stat-label">Cultural Events</div>
                        </div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section">
                    <form method="GET" class="filters-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-search"></i> Search Activities
                            </label>
                            <input type="text" class="form-input" name="search" placeholder="Search by title or description..." value="<?= htmlspecialchars($search) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-tag"></i> Activity Type
                            </label>
                            <select class="form-select" name="type">
                                <option value="">All Types</option>
                                <option value="Academics" <?= $type_filter == 'Academics' ? 'selected' : '' ?>>Academics</option>
                                <option value="Sports" <?= $type_filter == 'Sports' ? 'selected' : '' ?>>Sports</option>
                                <option value="Cultural" <?= $type_filter == 'Cultural' ? 'selected' : '' ?>>Cultural</option>
                                <option value="Competition" <?= $type_filter == 'Competition' ? 'selected' : '' ?>>Competition</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar"></i> From Date
                            </label>
                            <input type="date" class="form-input" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar"></i> To Date
                            </label>
                            <input type="date" class="form-input" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn-filter">
                                <i class="fas fa-filter"></i>
                                Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- View Toggle -->
                <div class="view-toggle">
                    <button class="view-btn active" id="listViewBtn">
                        <i class="fas fa-list"></i>
                        List View
                    </button>
                    <button class="view-btn" id="calendarViewBtn">
                        <i class="fas fa-calendar-alt"></i>
                        Calendar View
                    </button>
                </div>

                <!-- List View -->
                <div id="listView" class="activities-grid">
                    <?php if (empty($activities)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-calendar-times"></i>
                            </div>
                            <h3 class="empty-title">No Activities Found</h3>
                            <p class="empty-text">Try adjusting your search filters or check back later for new activities.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activities as $index => $activity): ?>
                            <div class="activity-card" style="--index: <?php echo $index; ?>">
                                <div class="activity-header">
                                    <h3 class="activity-title">
                                        <?php echo htmlspecialchars($activity['activity_title']); ?>
                                    </h3>
                                    <div class="activity-meta">
                                        <span class="activity-type type-<?php echo strtolower($activity['activity_type']); ?>">
                                            <?php echo htmlspecialchars($activity['activity_type']); ?>
                                        </span>
                                        <span class="meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M j, Y', strtotime($activity['activity_date'])); ?>
                                        </span>
                                        <span class="meta-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($activity['venue'] ?: 'TBD'); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="activity-content">
                                    <p class="activity-description">
                                        <?php echo htmlspecialchars($activity['description']); ?>
                                    </p>

                                    <div class="activity-meta">
                                        <span class="meta-item">
                                            <i class="fas fa-user"></i>
                                            Coordinator: <?php echo htmlspecialchars($activity['coordinator_name'] ?: 'Not assigned'); ?>
                                        </span>
                                        <span class="meta-item">
                                            <i class="fas fa-clock"></i>
                                            <?php echo $activity['start_time'] ? $activity['start_time'] . ' - ' . $activity['end_time'] : 'All day'; ?>
                                        </span>
                                    </div>

                                    <div class="activity-actions">
                                        <a href="school_diary.php?id=<?php echo $activity['id']; ?>" class="btn-details">
                                            <i class="fas fa-eye"></i>
                                            View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Calendar View -->
                <div id="calendarView" class="calendar-container" style="display: none;">
                    <div class="calendar-header">
                        <h2 class="calendar-title" id="currentMonth">December 2025</h2>
                        <div class="calendar-nav">
                            <button class="calendar-btn" id="prevMonth">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="calendar-btn" id="todayBtn">Today</button>
                            <button class="calendar-btn" id="nextMonth">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>

                    <div class="calendar-grid">
                        <div class="calendar-day-header">Sun</div>
                        <div class="calendar-day-header">Mon</div>
                        <div class="calendar-day-header">Tue</div>
                        <div class="calendar-day-header">Wed</div>
                        <div class="calendar-day-header">Thu</div>
                        <div class="calendar-day-header">Fri</div>
                        <div class="calendar-day-header">Sat</div>

                        <div id="calendarBody">
                            <!-- Calendar days will be populated by JavaScript -->
                        </div>
                    </div>

                    <div class="calendar-legend">
                        <h4 class="legend-title">Activity Types</h4>
                        <div class="legend-items">
                            <div class="legend-item">
                                <div class="legend-dot" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);"></div>
                                <span>Academics</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-dot" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);"></div>
                                <span>Sports</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-dot" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);"></div>
                                <span>Cultural</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-dot" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);"></div>
                                <span>Competition</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                :root {
                    --primary-color: #4f46e5;
                    --primary-dark: #3730a3;
                    --secondary-color: #06b6d4;
                    --accent-color: #f59e0b;
                    --success-color: #10b981;
                    --warning-color: #f59e0b;
                    --error-color: #ef4444;
                    --info-color: #3b82f6;
                    --light-color: #f9fafb;
                    --dark-color: #1f2937;
                    --gray-color: #6b7280;
                    --card-bg: #ffffff;
                    --shadow: 0 20px 40px rgba(79, 70, 229, 0.15);
                    --shadow-hover: 0 30px 60px rgba(79, 70, 229, 0.25);
                    --radius: 20px;
                    --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                    --gradient-accent: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
                    --gradient-success: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
                    --gradient-warning: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
                    --gradient-info: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
                }

                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                body {
                    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                    min-height: 100vh;
                    color: var(--dark-color);
                    line-height: 1.6;
                }

                .page-container {
                    max-width: 1400px;
                    margin: 0 auto;
                    padding: 2rem;
                }

                .page-header {
                    background: var(--card-bg);
                    border-radius: var(--radius);
                    box-shadow: var(--shadow);
                    padding: 2rem;
                    margin-bottom: 2rem;
                    position: relative;
                    overflow: hidden;
                }

                .page-header::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    height: 4px;
                    background: var(--gradient-primary);
                }

                .header-content {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    gap: 2rem;
                    margin-bottom: 2rem;
                }

                .header-left {
                    flex: 1;
                }

                .header-right {
                    flex-shrink: 0;
                }

                .btn-dashboard {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.5rem;
                    padding: 0.75rem 1.5rem;
                    background: var(--gradient-secondary);
                    color: white;
                    text-decoration: none;
                    border-radius: 12px;
                    font-weight: 600;
                    transition: all 0.3s ease;
                    box-shadow: 0 4px 12px rgba(240, 159, 251, 0.3);
                }

                .btn-dashboard:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 25px rgba(240, 159, 251, 0.4);
                }

                .page-title {
                    font-family: 'Poppins', sans-serif;
                    font-size: 2.5rem;
                    font-weight: 700;
                    color: var(--dark-color);
                    margin-bottom: 0.5rem;
                    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
                    -webkit-background-clip: text;
                    -webkit-text-fill-color: transparent;
                    background-clip: text;
                }

                .page-subtitle {
                    color: var(--gray-color);
                    font-size: 1.1rem;
                    margin-bottom: 2rem;
                }

                .stats-overview {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 1.5rem;
                    margin-bottom: 2rem;
                }

                .stat-card {
                    background: var(--card-bg);
                    border-radius: var(--radius);
                    padding: 1.5rem;
                    box-shadow: var(--shadow);
                    border: 1px solid rgba(79, 70, 229, 0.1);
                    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                    position: relative;
                    overflow: hidden;
                }

                .stat-card::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    height: 4px;
                    background: var(--gradient-primary);
                }

                .stat-card:hover {
                    transform: translateY(-5px);
                    box-shadow: var(--shadow-hover);
                }

                .stat-icon {
                    width: 50px;
                    height: 50px;
                    border-radius: 50%;
                    background: var(--gradient-primary);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: white;
                    font-size: 1.25rem;
                    margin-bottom: 1rem;
                }

                .stat-value {
                    font-size: 2rem;
                    font-weight: 700;
                    color: var(--primary-color);
                    margin-bottom: 0.5rem;
                }

                .stat-label {
                    color: var(--gray-color);
                    font-weight: 500;
                }

                .filters-section {
                    background: var(--card-bg);
                    border-radius: var(--radius);
                    padding: 2rem;
                    box-shadow: var(--shadow);
                    margin-bottom: 2rem;
                    border: 1px solid rgba(79, 70, 229, 0.1);
                }

                .filters-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 1.5rem;
                    align-items: end;
                }

                .form-group {
                    margin-bottom: 0;
                }

                .form-label {
                    font-weight: 600;
                    color: var(--dark-color);
                    margin-bottom: 0.5rem;
                    display: block;
                }

                .form-input {
                    width: 100%;
                    padding: 0.75rem 1rem;
                    border: 2px solid #e5e7eb;
                    border-radius: 12px;
                    font-size: 0.95rem;
                    transition: all 0.3s ease;
                    background: #f9fafb;
                }

                .form-input:focus {
                    outline: none;
                    border-color: var(--primary-color);
                    background: white;
                    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
                }

                .form-select {
                    width: 100%;
                    padding: 0.75rem 1rem;
                    border: 2px solid #e5e7eb;
                    border-radius: 12px;
                    font-size: 0.95rem;
                    transition: all 0.3s ease;
                    background: #f9fafb;
                    cursor: pointer;
                }

                .form-select:focus {
                    outline: none;
                    border-color: var(--primary-color);
                    background: white;
                    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
                }

                .btn-filter {
                    padding: 0.75rem 2rem;
                    background: var(--gradient-primary);
                    color: white;
                    border: none;
                    border-radius: 12px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    justify-content: center;
                }

                .btn-filter:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 25px rgba(79, 70, 229, 0.3);
                }

                .view-toggle {
                    display: flex;
                    background: #f1f5f9;
                    border-radius: 12px;
                    padding: 0.25rem;
                    margin-bottom: 2rem;
                }

                .view-btn {
                    flex: 1;
                    padding: 0.75rem 1.5rem;
                    border: none;
                    background: transparent;
                    border-radius: 8px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 0.5rem;
                }

                .view-btn.active {
                    background: var(--gradient-primary);
                    color: white;
                    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
                }

                .activities-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
                    gap: 2rem;
                }

                .activity-card {
                    background: var(--card-bg);
                    border-radius: var(--radius);
                    overflow: hidden;
                    box-shadow: var(--shadow);
                    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                    border: 1px solid rgba(79, 70, 229, 0.08);
                    position: relative;
                }

                .activity-card:hover {
                    transform: translateY(-8px);
                    box-shadow: var(--shadow-hover);
                }

                .activity-header {
                    padding: 1.5rem;
                    border-bottom: 1px solid #e5e7eb;
                    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                }

                .activity-title {
                    font-size: 1.25rem;
                    font-weight: 700;
                    color: var(--dark-color);
                    margin-bottom: 0.5rem;
                    line-height: 1.3;
                }

                .activity-meta {
                    display: flex;
                    align-items: center;
                    gap: 1rem;
                    flex-wrap: wrap;
                    margin-bottom: 1rem;
                }

                .meta-item {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    font-size: 0.9rem;
                    color: var(--gray-color);
                }

                .activity-type {
                    padding: 0.25rem 0.75rem;
                    border-radius: 20px;
                    font-size: 0.8rem;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                .type-academics {
                    background: var(--gradient-success);
                    color: white;
                }

                .type-sports {
                    background: var(--gradient-accent);
                    color: var(--dark-color);
                }

                .type-cultural {
                    background: var(--gradient-secondary);
                    color: white;
                }

                .type-competition {
                    background: var(--gradient-warning);
                    color: var(--dark-color);
                }

                .activity-content {
                    padding: 1.5rem;
                }

                .activity-description {
                    color: var(--gray-color);
                    line-height: 1.6;
                    margin-bottom: 1.5rem;
                    display: -webkit-box;
                    -webkit-line-clamp: 3;
                    -webkit-box-orient: vertical;
                    overflow: hidden;
                }

                .activity-actions {
                    display: flex;
                    gap: 0.75rem;
                    flex-wrap: wrap;
                }

                .btn-details {
                    padding: 0.75rem 1.5rem;
                    background: var(--gradient-primary);
                    color: white;
                    border: none;
                    border-radius: 12px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    text-decoration: none;
                }

                .btn-details:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(79, 70, 229, 0.3);
                }

                .btn-pdf {
                    padding: 0.75rem 1.5rem;
                    background: var(--gradient-accent);
                    color: var(--dark-color);
                    border: none;
                    border-radius: 12px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    text-decoration: none;
                }

                .btn-pdf:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(79, 70, 229, 0.2);
                }

                .empty-state {
                    text-align: center;
                    padding: 4rem 2rem;
                    background: var(--card-bg);
                    border-radius: var(--radius);
                    box-shadow: var(--shadow);
                    border: 1px solid rgba(79, 70, 229, 0.1);
                }

                .empty-icon {
                    font-size: 4rem;
                    color: var(--gray-color);
                    margin-bottom: 1.5rem;
                    opacity: 0.5;
                }

                .empty-title {
                    font-size: 1.5rem;
                    font-weight: 600;
                    color: var(--dark-color);
                    margin-bottom: 0.5rem;
                }

                .empty-text {
                    color: var(--gray-color);
                    font-size: 1rem;
                }

                /* Calendar View */
                .calendar-container {
                    background: var(--card-bg);
                    border-radius: var(--radius);
                    box-shadow: var(--shadow);
                    padding: 2rem;
                    border: 1px solid rgba(79, 70, 229, 0.1);
                }

                .calendar-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 2rem;
                }

                .calendar-title {
                    font-size: 1.75rem;
                    font-weight: 700;
                    color: var(--dark-color);
                }

                .calendar-nav {
                    display: flex;
                    gap: 0.5rem;
                }

                .calendar-btn {
                    padding: 0.5rem 1rem;
                    background: #f1f5f9;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                }

                .calendar-btn:hover {
                    background: var(--primary-color);
                    color: white;
                }

                .calendar-grid {
                    display: grid;
                    grid-template-columns: repeat(7, 1fr);
                    gap: 1px;
                    background: #e5e7eb;
                    border-radius: 12px;
                    overflow: hidden;
                }

                .calendar-day-header {
                    background: var(--gradient-primary);
                    color: white;
                    padding: 1rem;
                    text-align: center;
                    font-weight: 600;
                    font-size: 0.9rem;
                }

                .calendar-day {
                    background: var(--card-bg);
                    min-height: 120px;
                    padding: 0.75rem;
                    position: relative;
                    transition: all 0.3s ease;
                }

                .calendar-day:hover {
                    background: #f8fafc;
                }

                .calendar-day.today {
                    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
                }

                .calendar-day.today::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    height: 3px;
                    background: var(--accent-color);
                }

                .day-number {
                    font-weight: 600;
                    color: var(--dark-color);
                    margin-bottom: 0.5rem;
                }

                .activity-item {
                    background: var(--gradient-success);
                    color: white;
                    padding: 0.25rem 0.5rem;
                    border-radius: 6px;
                    font-size: 0.75rem;
                    margin-bottom: 0.25rem;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    font-weight: 500;
                }

                .activity-item:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
                }

                .activity-sports {
                    background: var(--gradient-accent);
                    color: var(--dark-color);
                }

                .activity-cultural {
                    background: var(--gradient-secondary);
                }

                .activity-competition {
                    background: var(--gradient-warning);
                    color: var(--dark-color);
                }

                .calendar-legend {
                    margin-top: 2rem;
                    padding-top: 2rem;
                    border-top: 1px solid #e5e7eb;
                }

                .legend-title {
                    font-weight: 600;
                    color: var(--dark-color);
                    margin-bottom: 1rem;
                }

                .legend-items {
                    display: flex;
                    gap: 1.5rem;
                    flex-wrap: wrap;
                }

                .legend-item {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    font-size: 0.9rem;
                    color: var(--gray-color);
                }

                .legend-dot {
                    width: 12px;
                    height: 12px;
                    border-radius: 50%;
                }

                /* Responsive Design */
                @media (max-width: 1024px) {
                    .page-container {
                        padding: 1.5rem;
                    }

                    .activities-grid {
                        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
                    }
                }

                @media (max-width: 768px) {
                    .page-header {
                        padding: 1.5rem;
                    }

                    .header-content {
                        flex-direction: column;
                        gap: 1rem;
                        text-align: center;
                    }

                    .header-left, .header-right {
                        flex: none;
                    }

                    .page-title {
                        font-size: 2rem;
                    }

                    .stats-overview {
                        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    }

                    .filters-grid {
                        grid-template-columns: 1fr;
                        gap: 1rem;
                    }

                    .activities-grid {
                        grid-template-columns: 1fr;
                    }

                    .calendar-header {
                        flex-direction: column;
                        gap: 1rem;
                        text-align: center;
                    }

                    .legend-items {
                        justify-content: center;
                    }
                }

                @media (max-width: 480px) {
                    .page-container {
                        padding: 1rem;
                    }

                    .page-header {
                        padding: 1rem;
                    }

                    .page-title {
                        font-size: 1.75rem;
                    }

                    .activity-actions {
                        flex-direction: column;
                    }

                    .btn-details, .btn-pdf {
                        width: 100%;
                        justify-content: center;
                    }

                    .calendar-grid {
                        font-size: 0.9rem;
                    }

                    .calendar-day {
                        min-height: 100px;
                        padding: 0.5rem;
                    }
                }

                /* Animations */
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(30px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                @keyframes slideInLeft {
                    from {
                        opacity: 0;
                        transform: translateX(-30px);
                    }
                    to {
                        opacity: 1;
                        transform: translateX(0);
                    }
                }

                .activity-card {
                    animation: fadeInUp 0.6s ease-out both;
                }

                .activity-card:nth-child(odd) {
                    animation-delay: 0.1s;
                }

                .activity-card:nth-child(even) {
                    animation-delay: 0.2s;
                }

                .stat-card {
                    animation: slideInLeft 0.6s ease-out both;
                }

                .stat-card:nth-child(1) { animation-delay: 0.1s; }
                .stat-card:nth-child(2) { animation-delay: 0.2s; }
                .stat-card:nth-child(3) { animation-delay: 0.3s; }
                .stat-card:nth-child(4) { animation-delay: 0.4s; }
            </style>
            <?php endif; ?>
        </main>
    </div>

    

    <!-- Activity Details Modal (for calendar view) -->
    <div class="modal fade" id="activityModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--gradient-primary); color: white;">
                    <h5 class="modal-title" id="activityModalTitle"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="activityModalBody">
                    <!-- Content populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <a href="#" class="btn-details" id="modalViewDetailsBtn">
                        <i class="fas fa-eye"></i>
                        View Full Details
                    </a>
                    <a href="#" class="btn-pdf" id="modalDownloadPdfBtn" target="_blank">
                        <i class="fas fa-download"></i>
                        Download PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // View toggle functionality
        const listViewBtn = document.getElementById('listViewBtn');
        const calendarViewBtn = document.getElementById('calendarViewBtn');
        const listView = document.getElementById('listView');
        const calendarView = document.getElementById('calendarView');

        listViewBtn.addEventListener('click', () => {
            listViewBtn.classList.add('active');
            calendarViewBtn.classList.remove('active');
            listView.style.display = 'grid';
            calendarView.style.display = 'none';
        });

        calendarViewBtn.addEventListener('click', () => {
            calendarViewBtn.classList.add('active');
            listViewBtn.classList.remove('active');
            calendarView.style.display = 'block';
            listView.style.display = 'none';
        });

        // Calendar functionality
        let currentDate = new Date();

        function generateCalendar(year, month) {
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const firstDayIndex = firstDay.getDay();
            const lastDate = lastDay.getDate();

            const monthNames = ["January", "February", "March", "April", "May", "June",
                "July", "August", "September", "October", "November", "December"];

            document.getElementById('currentMonth').textContent = `${monthNames[month]} ${year}`;

            let calendarHTML = '';
            let date = 1;
            const today = new Date();

            // Get activities for this month
            const monthActivities = <?= json_encode($activities) ?>;

            for (let i = 0; i < 6; i++) {
                for (let j = 0; j < 7; j++) {
                    if (i === 0 && j < firstDayIndex) {
                        calendarHTML += '<div class="calendar-day"></div>';
                    } else if (date > lastDate) {
                        calendarHTML += '<div class="calendar-day"></div>';
                    } else {
                        const currentDateObj = new Date(year, month, date);
                        const isToday = currentDateObj.toDateString() === today.toDateString();
                        const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(date).padStart(2,'0')}`;

                        // Filter activities for this date
                        const dayActivities = monthActivities.filter(a => a.activity_date === dateStr);

                        let activityHTML = '';
                        dayActivities.forEach(activity => {
                            const activityClass = `activity-${activity.activity_type.toLowerCase()}`;
                            activityHTML += `
                                <div class="activity-item ${activityClass}"
                                     data-activity='${JSON.stringify(activity)}'>
                                    ${activity.activity_title}
                                </div>`;
                        });

                        calendarHTML += `
                            <div class="calendar-day ${isToday ? 'today' : ''}">
                                <div class="day-number">${date}</div>
                                ${activityHTML}
                            </div>`;
                        date++;
                    }
                }
            }

            document.getElementById('calendarBody').innerHTML = calendarHTML;
        }

        // Event listeners for calendar navigation
        document.getElementById('prevMonth').addEventListener('click', () => {
            currentDate.setMonth(currentDate.getMonth() - 1);
            generateCalendar(currentDate.getFullYear(), currentDate.getMonth());
        });

        document.getElementById('nextMonth').addEventListener('click', () => {
            currentDate.setMonth(currentDate.getMonth() + 1);
            generateCalendar(currentDate.getFullYear(), currentDate.getMonth());
        });

        document.getElementById('todayBtn').addEventListener('click', () => {
            currentDate = new Date();
            generateCalendar(currentDate.getFullYear(), currentDate.getMonth());
        });

        // Activity modal handler
        const activityModal = new bootstrap.Modal(document.getElementById('activityModal'));

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('activity-item')) {
                e.preventDefault();
                const activity = JSON.parse(e.target.getAttribute('data-activity'));

                document.getElementById('activityModalTitle').textContent = activity.activity_title;
                document.getElementById('activityModalBody').innerHTML = `
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong><i class="fas fa-calendar"></i> Date:</strong> ${new Date(activity.activity_date).toLocaleDateString()}</p>
                            <p><strong><i class="fas fa-clock"></i> Time:</strong> ${activity.start_time ? activity.start_time + ' - ' + activity.end_time : 'All day'}</p>
                            <p><strong><i class="fas fa-map-marker-alt"></i> Venue:</strong> ${activity.venue || 'Not specified'}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong><i class="fas fa-tag"></i> Type:</strong> ${activity.activity_type}</p>
                            <p><strong><i class="fas fa-user"></i> Coordinator:</strong> ${activity.coordinator_name || 'Not assigned'}</p>
                            <p><strong><i class="fas fa-info-circle"></i> Status:</strong> ${activity.status}</p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <h6><i class="fas fa-align-left"></i> Description:</h6>
                        <p>${activity.description}</p>
                    </div>
                    ${activity.objectives ? `
                        <div class="mb-3">
                            <h6><i class="fas fa-bullseye"></i> Objectives:</h6>
                            <p>${activity.objectives}</p>
                        </div>` : ''}
                `;

                document.getElementById('modalViewDetailsBtn').href = `school_diary.php?id=${activity.id}`;
                document.getElementById('modalDownloadPdfBtn').href = `school_diary.php?pdf=1&id=${activity.id}`;

                activityModal.show();
            }
        });

        // Initialize calendar
        generateCalendar(currentDate.getFullYear(), currentDate.getMonth());

        // Add smooth scrolling for filter form
        document.querySelector('.filters-section').scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

    <script>
        // Mobile Menu Toggle - Dropdown Navigation
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileNavDropdown = document.getElementById('mobileNavDropdown');
        const mobileNavClose = document.getElementById('mobileNavClose');

        // Toggle dropdown menu
        mobileMenuToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            mobileNavDropdown.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        // Close dropdown when clicking close button
        mobileNavClose.addEventListener('click', () => {
            mobileNavDropdown.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!mobileNavDropdown.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                mobileNavDropdown.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            }
        });

        // Close dropdown when clicking on a navigation link
        document.querySelectorAll('.mobile-nav-link').forEach(link => {
            link.addEventListener('click', () => {
                mobileNavDropdown.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            });
        });

        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Add active class on scroll for header
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Animate cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe dashboard cards
        document.querySelectorAll('.card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });

        // Observe quick action cards
        document.querySelectorAll('.quick-action-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });

        // Add hover effects for cards
        document.querySelectorAll('.card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });

            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });

        // Quick action cards hover effect
        document.querySelectorAll('.quick-action-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.05)';
            });

            card.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });

        // Auto-refresh dashboard data every 5 minutes
        setInterval(() => {
            // You can add AJAX calls here to refresh dynamic data
            console.log('Dashboard data refresh check...');
        }, 300000);
    </script>

</body>
</html>
