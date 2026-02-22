<?php
session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_name = $_SESSION['full_name'];
$principal_id = $_SESSION['user_id'];

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_activity'])) {
        // Add new activity
        $stmt = $pdo->prepare("INSERT INTO school_diary (activity_title, activity_type, activity_date, start_time, end_time, venue, organizing_dept, coordinator_id, target_audience, target_classes, description, objectives, resources, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['activity_title'],
            $_POST['activity_type'],
            $_POST['activity_date'],
            $_POST['start_time'],
            $_POST['end_time'],
            $_POST['venue'],
            $_POST['organizing_dept'],
            $_POST['coordinator_id'] ?: null,
            $_POST['target_audience'],
            $_POST['target_classes'] ?: null,
            $_POST['description'],
            $_POST['objectives'],
            $_POST['resources'],
            $_POST['status'],
            $principal_id
        ]);
        
        $diary_id = $pdo->lastInsertId();
        
        // Handle file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $file_name = basename($_FILES['attachments']['name'][$key]);
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $new_file_name = time() . '_' . uniqid() . '.' . $file_ext;
                    $upload_dir = 'uploads/diary_attachments/';
                    
                    // Create directory if not exists
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_path = $upload_dir . $new_file_name;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        // Determine file type
                        $file_type = 'other';
                        if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                            $file_type = 'image';
                        } elseif (in_array($file_ext, ['mp4', 'avi', 'mov', 'wmv'])) {
                            $file_type = 'video';
                        } elseif (in_array($file_ext, ['pdf', 'doc', 'docx', 'txt'])) {
                            $file_type = 'document';
                        }
                        
                        $stmt = $pdo->prepare("INSERT INTO school_diary_attachments (diary_id, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $diary_id,
                            $file_name,
                            $file_path,
                            $file_type,
                            $_FILES['attachments']['size'][$key],
                            $principal_id
                        ]);
                    }
                }
            }
        }
        
        $_SESSION['success'] = 'Activity added successfully!';
        header('Location: school_diary.php');
        exit();
    }
    
    // Handle update
    if (isset($_POST['update_activity'])) {
        $stmt = $pdo->prepare("UPDATE school_diary SET activity_title=?, activity_type=?, activity_date=?, start_time=?, end_time=?, venue=?, organizing_dept=?, coordinator_id=?, target_audience=?, target_classes=?, description=?, objectives=?, resources=?, status=?, participant_count=?, winners_list=?, achievements=?, feedback_summary=? WHERE id=? AND created_by=?");
        $stmt->execute([
            $_POST['activity_title'],
            $_POST['activity_type'],
            $_POST['activity_date'],
            $_POST['start_time'],
            $_POST['end_time'],
            $_POST['venue'],
            $_POST['organizing_dept'],
            $_POST['coordinator_id'] ?: null,
            $_POST['target_audience'],
            $_POST['target_classes'] ?: null,
            $_POST['description'],
            $_POST['objectives'],
            $_POST['resources'],
            $_POST['status'],
            $_POST['participant_count'] ?: 0,
            $_POST['winners_list'],
            $_POST['achievements'],
            $_POST['feedback_summary'],
            $_POST['activity_id'],
            $principal_id
        ]);
        
        $_SESSION['success'] = 'Activity updated successfully!';
        header('Location: school_diary.php');
        exit();
    }
    
    // Handle delete
    if (isset($_POST['delete_activity'])) {
        $stmt = $pdo->prepare("DELETE FROM school_diary WHERE id=? AND created_by=?");
        $stmt->execute([$_POST['activity_id'], $principal_id]);
        
        $_SESSION['success'] = 'Activity deleted successfully!';
        header('Location: school_diary.php');
        exit();
    }
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "SELECT sd.*, u.full_name as coordinator_name 
          FROM school_diary sd 
          LEFT JOIN users u ON sd.coordinator_id = u.id 
          WHERE 1=1";
$params = [];

if ($search) {
    $query .= " AND (sd.activity_title LIKE ? OR sd.description LIKE ? OR sd.venue LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($type_filter) {
    $query .= " AND sd.activity_type = ?";
    $params[] = $type_filter;
}

if ($status_filter) {
    $query .= " AND sd.status = ?";
    $params[] = $status_filter;
}

if ($date_from) {
    $query .= " AND sd.activity_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND sd.activity_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY sd.activity_date DESC, sd.start_time ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all teachers for coordinator dropdown
$teachers_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name");
$teachers_stmt->execute();
$teachers = $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Diary | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Custom styles for School Diary page */

        /* Welcome Section Enhancement */
        .welcome-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: #ffffff;
            padding: 2rem;
            border-radius: var(--border-radius-xl);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .welcome-section h2 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
            color: #ffffff;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .welcome-section p {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.95);
            position: relative;
            z-index: 2;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }

        /* Header Actions Enhancement */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--border-radius-lg);
            margin: 1rem 0;
            font-weight: 600;
            border-left: 4px solid;
        }

        .alert-success {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #16a34a;
        }

        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-sm);
            margin-bottom: 3rem;
            overflow: hidden;
            border: 1px solid var(--gray-200);
        }

        .filter-form-container {
            padding: 2rem;
        }

        /* Enhanced Form Controls */
        .form-control {
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            padding: 0.875rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: var(--gray-50);
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
            background: var(--white);
            outline: none;
        }

        .form-control:hover {
            border-color: var(--primary-color);
        }

        /* Form Row Layout */
        .form-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 120px;
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-actions {
            display: flex;
            align-items: center;
        }

        /* Button Enhancements */
        .btn-gold {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: #ffffff;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: #ffffff;
        }

        .btn-small {
            padding: 0.625rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            text-decoration: none;
            border: none;
        }

        .btn-small.btn-view {
            background: var(--info-color);
            color: white;
        }

        .btn-small.btn-view:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }

        .btn-small.btn-edit {
            background: var(--primary-color);
            color: white;
        }

        .btn-small.btn-edit:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-small.btn-delete {
            background: var(--error-color);
            color: white;
        }

        .btn-small.btn-delete:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }

        /* Activities Section */
        .activities-section {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 3rem;
        }

        .activities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            padding: 2rem;
        }

        /* Activity Cards */
        .activity-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition-normal);
            border: 2px solid var(--gray-200);
        }

        .activity-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary-color);
        }

        /* Activity Images */
        .activity-images {
            position: relative;
            width: 100%;
            height: 200px;
            overflow: hidden;
        }

        .image-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(2, 100px);
            gap: 2px;
            height: 100%;
        }

        .image-item {
            position: relative;
            overflow: hidden;
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .image-item:hover {
            transform: scale(1.05);
        }

        .activity-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition-fast);
        }

        .image-item.more-images {
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
        }

        /* Activity Content */
        .activity-content {
            padding: 1.5rem;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            gap: 1rem;
        }

        .activity-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
            line-height: 1.3;
        }

        .activity-meta {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            color: var(--gray-600);
        }

        .meta-item i {
            color: var(--primary-color);
            width: 16px;
            text-align: center;
        }

        .activity-description {
            color: var(--gray-700);
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 1.5rem;
        }

        .activity-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-600);
        }

        .empty-state-icon {
            font-size: 4rem;
            color: var(--gray-400);
            margin-bottom: 1.5rem;
        }

        .empty-state h4 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .form-row {
                grid-template-columns: 1fr 1fr 1fr;
                gap: 1rem;
            }

            .activities-grid {
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 1.5rem;
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .welcome-section {
                padding: 1.5rem;
            }

            .welcome-section h2 {
                font-size: 1.8rem;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .filter-form-container {
                padding: 1.5rem;
            }

            .activities-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                padding: 1.5rem;
            }

            .activity-card {
                margin-bottom: 1rem;
            }

            .activity-images {
                height: 180px;
            }

            .image-grid {
                grid-template-rows: repeat(2, 90px);
            }

            .activity-content {
                padding: 1.25rem;
            }

            .activity-title {
                font-size: 1.1rem;
            }

            .activity-actions {
                gap: 0.5rem;
            }

            .btn-small {
                padding: 0.5rem 0.75rem !important;
                font-size: 0.8rem !important;
            }
        }

        @media (max-width: 480px) {
            .welcome-section::before {
                width: 150px;
                height: 150px;
            }

            .welcome-section h2 {
                font-size: 1.5rem;
            }

            .form-row {
                gap: 0.75rem;
            }

            .activities-grid {
                padding: 1rem;
                gap: 1rem;
            }

            .activity-images {
                height: 160px;
            }

            .image-grid {
                grid-template-rows: repeat(2, 80px);
            }

            .activity-content {
                padding: 1rem;
            }

            .activity-title {
                font-size: 1rem;
            }

            .activity-description {
                font-size: 0.9rem;
            }

            .empty-state {
                padding: 3rem 1rem;
            }

            .empty-state-icon {
                font-size: 3rem;
            }

            .empty-state h4 {
                font-size: 1.25rem;
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
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt logout-icon"></i>
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
                        <a href="school_diary.php" class="nav-link active">
                            <span class="nav-icon">📔</span>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
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
                        <a href="content_coverage.php" class="nav-link">
                            <span class="nav-icon">✅</span>
                            <span class="nav-text">Content Coverage</span>
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
                    <h2>📔 School Diary Management</h2>
                    <p>Manage and track all school activities, events, and programs</p>
                </div>
                <div class="header-actions">
                    <a href="add_activity.php" class="btn-gold">
                        <i class="fas fa-plus-circle"></i> Add New Activity
                    </a>
                </div>
            </div>

            <!-- Success Message -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <?= $_SESSION['success'] ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <!-- Search and Filter Section -->
            <div class="filter-section">
                <div class="section-header">
                    <h3>🔍 Search & Filter</h3>
                </div>
                <div class="filter-form-container">
                    <form method="GET" class="form-row">
                        <div class="form-group">
                            <input type="text" class="form-control" name="search" placeholder="Search activities..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="form-group">
                            <select class="form-control" name="type">
                                <option value="">All Types</option>
                                <option value="Academics" <?= $type_filter == 'Academics' ? 'selected' : '' ?>>Academics</option>
                                <option value="Sports" <?= $type_filter == 'Sports' ? 'selected' : '' ?>>Sports</option>
                                <option value="Cultural" <?= $type_filter == 'Cultural' ? 'selected' : '' ?>>Cultural</option>
                                <option value="Competition" <?= $type_filter == 'Competition' ? 'selected' : '' ?>>Competition</option>
                                <option value="Workshop" <?= $type_filter == 'Workshop' ? 'selected' : '' ?>>Workshop</option>
                                <option value="Celebration" <?= $type_filter == 'Celebration' ? 'selected' : '' ?>>Celebration</option>
                                <option value="Assembly" <?= $type_filter == 'Assembly' ? 'selected' : '' ?>>Assembly</option>
                                <option value="Examination" <?= $type_filter == 'Examination' ? 'selected' : '' ?>>Examination</option>
                                <option value="Holiday" <?= $type_filter == 'Holiday' ? 'selected' : '' ?>>Holiday</option>
                                <option value="Other" <?= $type_filter == 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <select class="form-control" name="status">
                                <option value="">All Status</option>
                                <option value="Upcoming" <?= $status_filter == 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
                                <option value="Ongoing" <?= $status_filter == 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="Cancelled" <?= $status_filter == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="form-group">
                            <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-gold">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Activities Grid -->
            <div class="activities-section">
                <div class="section-header">
                    <h3>📅 Activities</h3>
                    <span class="section-badge"><?= count($activities) ?> activities</span>
                </div>

                <?php if (empty($activities)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-journal-whills"></i>
                        </div>
                        <h4>No activities found</h4>
                        <p>Start by adding your first school activity to the diary.</p>
                        <a href="add_activity.php" class="btn-gold">
                            <i class="fas fa-plus-circle"></i> Add First Activity
                        </a>
                    </div>
                <?php else: ?>
                    <div class="activities-grid">
                        <?php foreach ($activities as $activity): ?>
                            <?php
                            // Get attachments for this activity
                            $attachments_stmt = $pdo->prepare("SELECT * FROM school_diary_attachments WHERE diary_id = ?");
                            $attachments_stmt->execute([$activity['id']]);
                            $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

                            // Get images for preview
                            $images = array_filter($attachments, fn($a) => $a['file_type'] == 'image');

                            // Status badge class
                            $status_class = '';
                            switch($activity['status']) {
                                case 'Upcoming': $status_class = 'badge-success'; break;
                                case 'Ongoing': $status_class = 'badge-info'; break;
                                case 'Completed': $status_class = 'badge-primary'; break;
                                case 'Cancelled': $status_class = 'badge-danger'; break;
                            }
                            ?>

                            <div class="activity-card">
                                <?php if (!empty($images)): ?>
                                    <div class="activity-images">
                                        <div class="image-grid">
                                            <?php foreach (array_slice($images, 0, 3) as $img): ?>
                                                <div class="image-item">
                                                    <img src="../<?= $img['file_path'] ?>" alt="<?= htmlspecialchars($img['file_name']) ?>"
                                                         class="activity-image"
                                                         onclick="openImageModal('../<?= $img['file_path'] ?>')">
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($images) > 3): ?>
                                                <div class="image-item more-images">
                                                    <span>+<?= count($images) - 3 ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="activity-content">
                                    <div class="activity-header">
                                        <h4 class="activity-title"><?= htmlspecialchars($activity['activity_title']) ?></h4>
                                        <span class="badge <?= $status_class ?>"><?= $activity['status'] ?></span>
                                    </div>

                                    <div class="activity-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span><?= date('F j, Y', strtotime($activity['activity_date'])) ?></span>
                                        </div>
                                        <?php if ($activity['start_time']): ?>
                                            <div class="meta-item">
                                                <i class="fas fa-clock"></i>
                                                <span><?= date('h:i A', strtotime($activity['start_time'])) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="meta-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?= htmlspecialchars($activity['venue'] ?: 'Venue not specified') ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-user-tie"></i>
                                            <span>Coordinator: <?= htmlspecialchars($activity['coordinator_name'] ?: 'Not assigned') ?></span>
                                        </div>
                                    </div>

                                    <p class="activity-description">
                                        <?= strlen($activity['description']) > 120 ?
                                            substr(htmlspecialchars($activity['description']), 0, 120) . '...' :
                                            htmlspecialchars($activity['description']) ?>
                                    </p>

                                    <div class="activity-actions">
                                        <a href="get_activity_details.php?id=<?= $activity['id'] ?>" class="btn-small btn-view">
                                            <i class="fas fa-info-circle"></i> Details
                                        </a>
                                        <a href="edit_activity.php?id=<?= $activity['id'] ?>" class="btn-small btn-edit">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this activity?')">
                                            <input type="hidden" name="activity_id" value="<?= $activity['id'] ?>">
                                            <button type="submit" name="delete_activity" class="btn-small btn-delete">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    

    <!-- Image Preview Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <img src="" alt="Preview" class="img-fluid" id="modalImage">
                </div>
            </div>
        </div>
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
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        // Image preview function
        function openImageModal(imageSrc) {
            const modalImage = document.getElementById('modalImage');
            modalImage.src = imageSrc;
            const modal = new bootstrap.Modal(document.getElementById('imageModal'));
            modal.show();
        }

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

        // Add active class on scroll
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>
</body>
</html>



