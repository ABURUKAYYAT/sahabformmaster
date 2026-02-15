<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) && !isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Check if activity ID is provided
if (!isset($_GET['id'])) {
    header("Location: school_diary.php");
    exit;
}

$activity_id = intval($_GET['id']);
$student_id = isset($_SESSION['student_id']) ? $_SESSION['student_id'] : $_SESSION['user_id'];
$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];

// Get student details including class
try {
    $student_stmt = $pdo->prepare("
        SELECT s.*, c.class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.id = ? OR s.user_id = ?
    ");
    $student_stmt->execute([$student_id, $student_id]);
    $student = $student_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception("Student not found");
    }

    // Get activity details with permission check
    $query = "
        SELECT sd.*, ac.category_name, ac.color, ac.icon, u.full_name as coordinator_name
        FROM school_diary sd
        LEFT JOIN activity_categories ac ON sd.category_id = ac.id
        LEFT JOIN users u ON sd.coordinator_id = u.id
        WHERE sd.id = ? AND sd.status != 'Cancelled'
        AND (sd.target_audience = 'All'
             OR sd.target_audience = 'Secondary Only'
             OR (sd.target_audience = 'Specific Classes' AND FIND_IN_SET(?, REPLACE(sd.target_classes, ', ', ','))))
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$activity_id, $student['class_name'] ?: '']);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        header("Location: school_diary.php?error=Activity not found or access denied");
        exit;
    }

    // Get attachments
    $attachments_stmt = $pdo->prepare("SELECT * FROM school_diary_attachments WHERE diary_id = ?");
    $attachments_stmt->execute([$activity_id]);
    $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format date and time
    $activity_date = date('F j, Y', strtotime($activity['activity_date']));
    $start_time = $activity['start_time'] ? date('h:i A', strtotime($activity['start_time'])) : 'Not specified';
    $end_time = $activity['end_time'] ? date('h:i A', strtotime($activity['end_time'])) : 'Not specified';
    $time_display = ($activity['start_time'] && $activity['end_time']) ?
                   "$start_time to $end_time" : $start_time;

    // Determine status badge color
    $status_colors = [
        'Upcoming' => 'success',
        'Ongoing' => 'info',
        'Completed' => 'primary',
        'Cancelled' => 'danger'
    ];
    $status_color = $status_colors[$activity['status']] ?? 'secondary';

    // Check if activity has completion details
    $has_completion_details = $activity['status'] === 'Completed' &&
                             ($activity['participant_count'] || $activity['winners_list'] ||
                              $activity['achievements'] || $activity['feedback_summary']);

} catch (PDOException $e) {
    error_log("Database error in activity_details.php: " . $e->getMessage());
    header("Location: school_diary.php?error=Database error occurred");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($activity['activity_title']); ?> | Activity Details</title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* ===================================================
           Activity Details Page - Modern Internal Styles
           =================================================== */

        :root {
            /* Inherit dashboard color palette */
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --secondary-color: #06b6d4;
            --accent-color: #8b5cf6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #3b82f6;

            /* Modern gradients */
            --gradient-primary: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            --gradient-secondary: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-warning: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --gradient-error: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);

            /* Enhanced shadows */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);

            /* Modern border radius */
            --border-radius-sm: 0.375rem;
            --border-radius-md: 0.5rem;
            --border-radius-lg: 0.75rem;
            --border-radius-xl: 1rem;

            /* Smooth transitions */
            --transition-fast: 0.15s ease-in-out;
            --transition-normal: 0.3s ease-in-out;
            --transition-slow: 0.5s ease-in-out;
        }

        /* ===================================================
           Layout Fixes for Sidebar Integration
           =================================================== */

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 80px);
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .main-content {
            flex: 1;
            margin-left: 0;
            padding: 2rem;
            max-width: 100%;
            background: transparent;
        }

        /* Desktop sidebar layout */
        @media (min-width: 1024px) {
            .main-content {
                margin-left: 280px;
                max-width: calc(100vw - 280px);
            }
        }

        /* ===================================================
           Activity Header Section
           =================================================== */

        .activity-header-section {
            background: var(--gradient-primary);
            color: white;
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            margin-bottom: 2rem;
            position: relative;
        }

        .activity-header-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="50" r="1" fill="white" opacity="0.05"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.1;
        }

        .activity-header-content {
            padding: 2.5rem;
            position: relative;
            z-index: 1;
        }

        .activity-title-large {
            font-family: 'Poppins', sans-serif;
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .activity-title-large i {
            font-size: 2.5rem;
            opacity: 0.9;
        }

        .activity-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 1.5rem;
        }

        .activity-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--border-radius-lg);
            backdrop-filter: blur(10px);
        }

        .meta-item i {
            font-size: 1.5rem;
            opacity: 0.9;
        }

        .meta-content h4 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .meta-content p {
            margin: 0.25rem 0 0 0;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* ===================================================
           Content Cards
           =================================================== */

        .content-card {
            background: white;
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 2rem;
            border: 1px solid #e5e7eb;
        }

        .card-header-custom {
            padding: 1.5rem 2rem;
            background: var(--gradient-primary);
            color: white;
            border-bottom: none;
            position: relative;
        }

        .card-header-custom::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
        }

        .card-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .card-header-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }

        .card-header-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }

        .card-body-custom {
            padding: 2rem;
        }

        /* ===================================================
           Status Badge
           =================================================== */

        .status-badge-large {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 2rem;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            text-transform: uppercase;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .badge-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .badge-info {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
        }

        .badge-primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
        }

        .badge-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        /* ===================================================
           Information Grid
           =================================================== */

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: #f8fafc;
            border: 2px solid #e5e7eb;
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            transition: var(--transition-normal);
        }

        .info-card:hover {
            border-color: var(--primary-color);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .info-card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .info-icon {
            width: 40px;
            height: 40px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
        }

        .info-title {
            font-weight: 600;
            color: #374151;
            margin: 0;
            font-size: 1.1rem;
        }

        .info-content {
            color: #6b7280;
            line-height: 1.6;
            margin: 0;
        }

        /* ===================================================
           Attachments Section
           =================================================== */

        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .attachment-card {
            border: 2px solid #e5e7eb;
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition-normal);
            background: white;
        }

        .attachment-card:hover {
            border-color: var(--primary-color);
            box-shadow: var(--shadow-lg);
            transform: translateY(-3px);
        }

        .attachment-image {
            height: 180px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .attachment-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition-normal);
        }

        .attachment-image img:hover {
            transform: scale(1.05);
        }

        .attachment-icon {
            font-size: 3rem;
            color: #9ca3af;
        }

        .attachment-info {
            padding: 1rem;
        }

        .attachment-name {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .attachment-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-attachment {
            flex: 1;
            padding: 0.5rem;
            font-size: 0.8rem;
            border-radius: var(--border-radius-md);
            text-align: center;
            text-decoration: none;
            transition: var(--transition-fast);
        }

        .btn-attachment:hover {
            transform: translateY(-1px);
        }

        /* ===================================================
           Completion Details Section
           =================================================== */

        .completion-section {
            background: linear-gradient(135deg, #f0f9ff 0%, #f8fafc 100%);
            border-radius: var(--border-radius-xl);
            padding: 2.5rem;
            border-left: 5px solid var(--primary-color);
            box-shadow: var(--shadow-md);
        }

        .completion-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .completion-icon {
            width: 60px;
            height: 60px;
            background: var(--gradient-success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .completion-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.75rem;
            font-weight: 600;
            color: #374151;
            margin: 0;
        }

        /* ===================================================
           Back Button
           =================================================== */

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: var(--border-radius-lg);
            color: #6b7280;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition-fast);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }

        .back-button:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* ===================================================
           Statistics Box
           =================================================== */

        .stats-box {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px solid #bae6fd;
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            text-align: center;
            min-width: 150px;
            box-shadow: var(--shadow-sm);
        }

        .stats-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: #0369a1;
            line-height: 1;
            text-shadow: 0 2px 4px rgba(3, 105, 161, 0.2);
            margin-bottom: 0.5rem;
        }

        .stats-label {
            font-size: 1rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        /* ===================================================
           Responsive Design
           =================================================== */

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1.5rem;
                max-width: 100%;
            }

            .activity-meta-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .attachments-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .activity-title-large {
                font-size: 2rem;
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .activity-subtitle {
                text-align: center;
                font-size: 1.1rem;
            }

            .activity-header-content {
                padding: 2rem;
            }

            .activity-meta-grid {
                grid-template-columns: 1fr;
            }

            .card-body-custom {
                padding: 1.5rem;
            }

            .completion-section {
                padding: 2rem;
            }

            .back-button {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .activity-title-large {
                font-size: 1.75rem;
            }

            .activity-header-content {
                padding: 1.5rem;
            }

            .card-header-custom {
                padding: 1rem 1.5rem;
            }

            .card-header-title {
                font-size: 1.25rem;
            }

            .card-body-custom {
                padding: 1.25rem;
            }

            .info-card {
                padding: 1.25rem;
            }

            .attachment-card {
                margin-bottom: 1rem;
            }

            .attachment-image {
                height: 140px;
            }

            .stats-number {
                font-size: 2rem;
            }

            .completion-section {
                padding: 1.5rem;
            }
        }

        /* ===================================================
           Print Styles
           =================================================== */

        @media print {
            .back-button,
            .btn-attachment {
                display: none !important;
            }

            .content-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }

            .activity-header-section {
                -webkit-print-color-adjust: exact;
                color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <!-- Mobile Navigation Component -->
    <?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Student Portal</p>
                    </div>
                </div>
            </div>

            <!-- Student Info and Logout -->
            <div class="header-right">
                <div class="student-info">
                    <p class="student-label">Student</p>
                    <span class="student-name"><?php echo htmlspecialchars($student_name); ?></span>
                    <span class="admission-number"><?php echo htmlspecialchars($admission_number); ?></span>
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
        <?php include '../includes/student_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Back Button -->
            <a href="school_diary.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Back to School Diary
            </a>

            <!-- Activity Header -->
            <div class="activity-header-section">
                <div class="activity-header-content">
                    <div>
                        <h1 class="activity-title-large">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo htmlspecialchars($activity['activity_title']); ?>
                        </h1>
                        <p class="activity-subtitle"><?php echo htmlspecialchars($activity['category_name'] ?: $activity['activity_type']); ?></p>

                        <!-- Status Badge -->
                        <span class="status-badge-large badge-<?php echo $status_color; ?>">
                            <?php echo $activity['status']; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Activity Information Grid -->
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class="fas fa-calendar"></i>
                        </div>
                        <h3 class="info-title">Date & Time</h3>
                    </div>
                    <div class="info-content">
                        <strong><?php echo $activity_date; ?></strong>
                        <?php if ($time_display !== 'Not specified'): ?>
                            <br><i class="fas fa-clock me-1"></i><?php echo $time_display; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($activity['venue']): ?>
                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <h3 class="info-title">Venue</h3>
                    </div>
                    <div class="info-content">
                        <?php echo htmlspecialchars($activity['venue']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="info-title">Target Audience</h3>
                    </div>
                    <div class="info-content">
                        <?php echo htmlspecialchars($activity['target_audience']); ?>
                        <?php if ($activity['target_classes']): ?>
                            <br><small>Classes: <?php echo htmlspecialchars($activity['target_classes']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <h3 class="info-title">Coordinator</h3>
                    </div>
                    <div class="info-content">
                        <?php echo $activity['coordinator_name'] ? htmlspecialchars($activity['coordinator_name']) : '<em>Not assigned</em>'; ?>
                    </div>
                </div>

                <?php if ($activity['organizing_dept']): ?>
                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <h3 class="info-title">Organizing Department</h3>
                    </div>
                    <div class="info-content">
                        <?php echo htmlspecialchars($activity['organizing_dept']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($activity['participant_count']): ?>
                <div class="info-card">
                    <div class="info-card-header">
                        <div class="info-icon">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <h3 class="info-title">Participants</h3>
                    </div>
                    <div class="info-content">
                        <div class="stats-box d-inline-block">
                            <div class="stats-number"><?php echo $activity['participant_count']; ?></div>
                            <div class="stats-label">Total</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Description Section -->
            <div class="content-card">
                <div class="card-header-custom">
                    <div class="card-header-content">
                        <div class="card-header-icon">
                            <i class="fas fa-align-left"></i>
                        </div>
                        <h2 class="card-header-title">Description</h2>
                    </div>
                </div>
                <div class="card-body-custom">
                    <p class="info-content" style="font-size: 1rem; margin: 0;">
                        <?php echo nl2br(htmlspecialchars($activity['description'])); ?>
                    </p>
                </div>
            </div>

            <!-- Objectives Section -->
            <?php if ($activity['objectives']): ?>
            <div class="content-card">
                <div class="card-header-custom">
                    <div class="card-header-content">
                        <div class="card-header-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <h2 class="card-header-title">Objectives</h2>
                    </div>
                </div>
                <div class="card-body-custom">
                    <p class="info-content" style="font-size: 1rem; margin: 0;">
                        <?php echo nl2br(htmlspecialchars($activity['objectives'])); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Resources Section -->
            <?php if ($activity['resources']): ?>
            <div class="content-card">
                <div class="card-header-custom">
                    <div class="card-header-content">
                        <div class="card-header-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                        <h2 class="card-header-title">Resources Required</h2>
                    </div>
                </div>
                <div class="card-body-custom">
                    <p class="info-content" style="font-size: 1rem; margin: 0;">
                        <?php echo nl2br(htmlspecialchars($activity['resources'])); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Attachments Section -->
            <?php if (!empty($attachments)): ?>
            <div class="content-card">
                <div class="card-header-custom">
                    <div class="card-header-content">
                        <div class="card-header-icon">
                            <i class="fas fa-paperclip"></i>
                        </div>
                        <h2 class="card-header-title">Attachments</h2>
                    </div>
                </div>
                <div class="card-body-custom">
                    <div class="attachments-grid">
                        <?php foreach ($attachments as $attachment): ?>
                            <div class="attachment-card">
                                <?php if ($attachment['file_type'] == 'image'): ?>
                                    <div class="attachment-image">
                                        <img src="<?php echo htmlspecialchars('../admin/' . $attachment['file_path']); ?>"
                                             alt="<?php echo htmlspecialchars($attachment['file_name']); ?>">
                                    </div>
                                <?php else: ?>
                                    <div class="attachment-image">
                                        <div class="attachment-icon">
                                            <i class="fas fa-file"></i>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="attachment-info">
                                    <div class="attachment-name">
                                        <?php echo htmlspecialchars($attachment['file_name']); ?>
                                    </div>
                                    <div class="attachment-actions">
                                        <a href="<?php echo htmlspecialchars('../admin/' . $attachment['file_path']); ?>"
                                           download
                                           class="btn-attachment"
                                           style="background: var(--gradient-primary); color: white; border: none;">
                                            <i class="fas fa-download me-1"></i> Download
                                        </a>
                                        <?php if ($attachment['file_type'] == 'image'): ?>
                                            <button onclick="openImageModal('<?php echo htmlspecialchars('../admin/' . $attachment['file_path']); ?>')"
                                                    class="btn-attachment"
                                                    style="background: var(--gradient-secondary); color: white; border: none;">
                                                <i class="fas fa-eye me-1"></i> View
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Completion Details (Only for Completed Activities) -->
            <?php if ($has_completion_details): ?>
            <div class="completion-section">
                <div class="completion-header">
                    <div class="completion-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2 class="completion-title">Activity Completion Details</h2>
                </div>

                <div class="row">
                    <?php if ($activity['participant_count']): ?>
                    <div class="col-md-4 mb-4">
                        <div class="stats-box">
                            <div class="stats-number"><?php echo $activity['participant_count']; ?></div>
                            <div class="stats-label">Participants</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-8">
                        <?php if ($activity['winners_list']): ?>
                        <div class="mb-4">
                            <h4 style="color: #374151; margin-bottom: 1rem;">
                                <i class="fas fa-trophy me-2" style="color: var(--warning-color);"></i>
                                Winners List
                            </h4>
                            <div style="background: white; padding: 1.5rem; border-radius: var(--border-radius-lg); border: 1px solid #e5e7eb;">
                                <p class="info-content mb-0"><?php echo nl2br(htmlspecialchars($activity['winners_list'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($activity['achievements']): ?>
                        <div class="mb-4">
                            <h4 style="color: #374151; margin-bottom: 1rem;">
                                <i class="fas fa-star me-2" style="color: var(--success-color);"></i>
                                Achievements
                            </h4>
                            <div style="background: white; padding: 1.5rem; border-radius: var(--border-radius-lg); border: 1px solid #e5e7eb;">
                                <p class="info-content mb-0"><?php echo nl2br(htmlspecialchars($activity['achievements'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($activity['feedback_summary']): ?>
                        <div class="mb-4">
                            <h4 style="color: #374151; margin-bottom: 1rem;">
                                <i class="fas fa-comments me-2" style="color: var(--info-color);"></i>
                                Feedback Summary
                            </h4>
                            <div style="background: white; padding: 1.5rem; border-radius: var(--border-radius-lg); border: 1px solid #e5e7eb;">
                                <p class="info-content mb-0"><?php echo nl2br(htmlspecialchars($activity['feedback_summary'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Metadata Footer -->
            <div class="content-card">
                <div class="card-body-custom" style="background: #f8fafc; border-radius: var(--border-radius-lg);">
                    <div class="row align-items-center text-center">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <i class="fas fa-user-plus me-2" style="color: var(--primary-color);"></i>
                            <strong>Created by:</strong> Principal
                        </div>
                        <div class="col-md-6">
                            <i class="fas fa-clock me-2" style="color: var(--primary-color);"></i>
                            <strong>Last updated:</strong> <?php echo date('M j, Y \a\t h:i A', strtotime($activity['updated_at'] ?? $activity['created_at'])); ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    

    <!-- Image Modal for Attachments -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true" style="z-index: 9999;">
        <div class="modal-dialog modal-lg" style="z-index: 10000;">
            <div class="modal-content">
                <div class="modal-body text-center p-0">
                    <img src="" class="img-fluid" style="max-height: 80vh;" alt="Attachment Image">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Image modal function (for attachments)
        function openImageModal(imageSrc) {
            const imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
            document.querySelector('#imageModal img').src = imageSrc;
            imageModal.show();
        }
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
