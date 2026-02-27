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

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['full_name'] ?? 'User';
$is_principal = ($user_role === 'principal');
$principal_name = $_SESSION['full_name'];

// Get current school for data isolation
$current_school_id = require_school_auth();


// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_evaluation'])) {
        // Add new evaluation
        $student_id = $_POST['student_id'];
        $class_id = $_POST['class_id'];
        $term = $_POST['term'];
        $year = $_POST['year'];
        $academic = $_POST['academic'];
        $non_academic = $_POST['non_academic'];
        $cognitive = $_POST['cognitive'];
        $psychomotor = $_POST['psychomotor'];
        $affective = $_POST['affective'];
        $comments = $_POST['comments'];
        
        $stmt = $pdo->prepare("INSERT INTO evaluations (school_id, student_id, class_id, term, academic_year, academic, non_academic, cognitive, psychomotor, affective, comments, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$current_school_id, $student_id, $class_id, $term, $year, $academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $_SESSION['user_id']]);
        
        $_SESSION['success'] = "Evaluation added successfully!";
    }
    
    if (isset($_POST['update_evaluation'])) {
        // Update evaluation
        $evaluation_id = $_POST['evaluation_id'];
        $academic = $_POST['academic'];
        $non_academic = $_POST['non_academic'];
        $cognitive = $_POST['cognitive'];
        $psychomotor = $_POST['psychomotor'];
        $affective = $_POST['affective'];
        $comments = $_POST['comments'];
        
        $stmt = $pdo->prepare("UPDATE evaluations SET academic = ?, non_academic = ?, cognitive = ?, psychomotor = ?, affective = ?, comments = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $evaluation_id]);
        
        $_SESSION['success'] = "Evaluation updated successfully!";
    }
    
    if (isset($_GET['delete_id'])) {
        // Delete evaluation
        $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id = ?");
        $stmt->execute([$_GET['delete_id']]);
        
        $_SESSION['success'] = "Evaluation deleted successfully!";
        header("Location: students-evaluations.php");
        exit();
    }
    
    // if (isset($_POST['export_pdf'])) {
    //     // Export to PDF functionality
    //     require_once 'includes/pdf_generator.php';
    //     generateEvaluationPDF($_POST['student_id'], $_POST['term'], $_POST['year']);
    //     exit();
    // }
}

// Fetch evaluations
$stmt = $pdo->prepare("
    SELECT e.*, s.full_name, s.class_id, s.admission_no
    FROM evaluations e
    JOIN students s ON e.student_id = s.id
    WHERE e.school_id = ?
    ORDER BY e.created_at DESC
");
$stmt->execute([$current_school_id]);
$evaluations = $stmt->fetchAll();

// Fetch students for dropdown
$stmt = $pdo->prepare("SELECT id, full_name, class_id FROM students WHERE school_id = ? ORDER BY class_id, full_name");
$stmt->execute([$current_school_id]);
$students = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Evaluations - Principal Dashboard</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students-evaluations.css?v=1.1">
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

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
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
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students Registration</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students-evaluations.php" class="nav-link active">
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
                    <h2>👥 Student Evaluations</h2>
                    <p>Monitor and manage student performance evaluations</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-clipboard-check"></i>
                    <h3>Total Evaluations</h3>
                    <div class="count"><?php echo number_format(count($evaluations)); ?></div>
                    <p class="stat-description">All evaluations</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-star"></i>
                    <h3>Excellent Ratings</h3>
                    <div class="count">
                        <?php echo array_reduce($evaluations, function($carry, $item) {
                            return $carry + (($item['academic'] === 'excellent' ||
                                            $item['non_academic'] === 'excellent' ||
                                            $item['cognitive'] === 'excellent' ||
                                            $item['psychomotor'] === 'excellent' ||
                                            $item['affective'] === 'excellent') ? 1 : 0);
                        }, 0); ?>
                    </div>
                    <p class="stat-description">Outstanding performance</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-chart-line"></i>
                    <h3>Academic Excellence</h3>
                    <div class="count">
                        <?php echo array_reduce($evaluations, function($carry, $item) {
                            return $carry + (($item['academic'] === 'excellent' ||
                                            $item['academic'] === 'very-good') ? 1 : 0);
                        }, 0); ?>
                    </div>
                    <p class="stat-description">High academic ratings</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <h3>Needs Improvement</h3>
                    <div class="count">
                        <?php echo array_reduce($evaluations, function($carry, $item) {
                            return $carry + (($item['academic'] === 'needs-improvement' ||
                                            $item['non_academic'] === 'needs-improvement' ||
                                            $item['cognitive'] === 'needs-improvement' ||
                                            $item['psychomotor'] === 'needs-improvement' ||
                                            $item['affective'] === 'needs-improvement') ? 1 : 0);
                        }, 0); ?>
                    </div>
                    <p class="stat-description">Areas for development</p>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <!-- Evaluations Management Section -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-clipboard-check"></i> Student Evaluations</h2>
                    <button class="toggle-form-btn" onclick="showAddEvaluationModal()">
                        <i class="fas fa-plus"></i> Add New Evaluation
                    </button>
                </div>

                <!-- Search and Filter Section -->
                <div class="form-section">
                    <h3><i class="fas fa-search"></i> Search & Filter Evaluations</h3>
                    <div class="form-inline">
                        <input type="text" id="searchInput" placeholder="Search by student name..." onkeyup="filterTable()">
                        <select id="termFilter" onchange="filterTable()">
                            <option value="">All Terms</option>
                            <option value="1">Term 1</option>
                            <option value="2">Term 2</option>
                            <option value="3">Term 3</option>
                        </select>
                        <select id="ratingFilter" onchange="filterTable()">
                            <option value="">All Ratings</option>
                            <option value="excellent">Excellent</option>
                            <option value="very-good">Very Good</option>
                            <option value="good">Good</option>
                            <option value="needs-improvement">Needs Improvement</option>
                        </select>
                    </div>
                </div>

                <!-- Evaluations Table -->
                <div class="table-wrap">
                    <table class="table evaluations-table" id="evaluationsTable">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Term/Year</th>
                                <th>Academic</th>
                                <th>Non-Academic</th>
                                <th>Cognitive</th>
                                <th>Psychomotor</th>
                                <th>Affective</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($evaluations as $eval): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($eval['full_name']); ?></strong>
                                            <br><small style="color: #6b7280;">Roll: <?php echo $eval['admission_no']; ?></small>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($eval['class_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-info">Term <?php echo $eval['term']; ?></span>
                                        <br><small><?php echo $eval['academic_year'] ?? $eval['year']; ?></small>
                                    </td>
                                    <td><span class="badge badge-<?php echo str_replace('-', '', $eval['academic']); ?>"><?php echo ucfirst($eval['academic']); ?></span></td>
                                    <td><span class="badge badge-<?php echo str_replace('-', '', $eval['non_academic']); ?>"><?php echo ucfirst($eval['non_academic']); ?></span></td>
                                    <td><span class="badge badge-<?php echo str_replace('-', '', $eval['cognitive']); ?>"><?php echo ucfirst($eval['cognitive']); ?></span></td>
                                    <td><span class="badge badge-<?php echo str_replace('-', '', $eval['psychomotor']); ?>"><?php echo ucfirst($eval['psychomotor']); ?></span></td>
                                    <td><span class="badge badge-<?php echo str_replace('-', '', $eval['affective']); ?>"><?php echo ucfirst($eval['affective']); ?></span></td>
                                    <td class="actions">
                                        <button class="btn small" onclick="showViewModal(<?php echo $eval['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="btn small warning" onclick="showEditModal(<?php echo $eval['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this evaluation?');">
                                            <input type="hidden" name="delete_id" value="<?php echo $eval['id']; ?>">
                                            <button type="submit" class="btn small danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <button class="btn small success" onclick="printEvaluation(<?php echo $eval['id']; ?>)">
                                            <i class="fas fa-print"></i>
                                        </button>
                                    </td>
                                </tr>

                                        <!-- View Modal -->
                                        <div id="viewModal<?php echo $eval['id']; ?>" class="modal-overlay" style="display: none;">
                                            <div class="modal-content">
                                                <div class="modal-header modal-header-view">
                                                    <h3><i class="fas fa-eye"></i> Evaluation Details</h3>
                                                    <button type="button" class="modal-close" onclick="closeModal('viewModal<?php echo $eval['id']; ?>')">&times;</button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="rating-grid">
                                                        <div class="rating-card">
                                                            <h6><i class="fas fa-user"></i> Student Information</h6>
                                                            <p><strong>Name:</strong> <?php echo htmlspecialchars($eval['full_name']); ?></p>
                                                            <p><strong>Class:</strong> <?php echo htmlspecialchars($eval['class_name'] ?? 'N/A'); ?></p>
                                                            <p><strong>Admission No:</strong> <?php echo $eval['admission_no']; ?></p>
                                                            <p><strong>Term:</strong> <?php echo $eval['term']; ?> | <strong>Year:</strong> <?php echo $eval['academic_year'] ?? $eval['year']; ?></p>
                                                        </div>
                                                        <div class="rating-card">
                                                            <h6><i class="fas fa-star"></i> Performance Ratings</h6>
                                                            <p><strong>Academic:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['academic']); ?>"><?php echo ucfirst($eval['academic']); ?></span></p>
                                                            <p><strong>Non-Academic:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['non_academic']); ?>"><?php echo ucfirst($eval['non_academic']); ?></span></p>
                                                            <p><strong>Cognitive:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['cognitive']); ?>"><?php echo ucfirst($eval['cognitive']); ?></span></p>
                                                            <p><strong>Psychomotor:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['psychomotor']); ?>"><?php echo ucfirst($eval['psychomotor']); ?></span></p>
                                                            <p><strong>Affective:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['affective']); ?>"><?php echo ucfirst($eval['affective']); ?></span></p>
                                                        </div>
                                                    </div>
                                                    <?php if (!empty($eval['comments'])): ?>
                                                    <div class="comments-section">
                                                        <h6><i class="fas fa-comment"></i> Comments & Recommendations</h6>
                                                        <p><?php echo nl2br(htmlspecialchars($eval['comments'])); ?></p>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-actions">
                                                    <button type="button" class="btn secondary" onclick="closeModal('viewModal<?php echo $eval['id']; ?>')">Close</button>
                                                    <button type="button" class="btn success" onclick="exportEvaluation(<?php echo $eval['id']; ?>)">
                                                        <i class="fas fa-file-pdf"></i> Export PDF
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Edit Modal -->
                                        <div id="editModal<?php echo $eval['id']; ?>" class="modal-overlay" style="display: none;">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <div class="modal-header modal-header-edit">
                                                        <h3><i class="fas fa-edit"></i> Edit Evaluation</h3>
                                                        <button type="button" class="modal-close" onclick="closeModal('editModal<?php echo $eval['id']; ?>')">&times;</button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="evaluation_id" value="<?php echo $eval['id']; ?>">
                                                        <input type="hidden" name="update_evaluation" value="1">

                                                        <div class="rating-grid">
                                                            <div class="rating-card">
                                                                <h6><i class="fas fa-graduation-cap"></i> Academic Performance</h6>
                                                                <select class="form-control" name="academic" required>
                                                                    <option value="excellent" <?php echo ($eval['academic'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                    <option value="very-good" <?php echo ($eval['academic'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                    <option value="good" <?php echo ($eval['academic'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                    <option value="needs-improvement" <?php echo ($eval['academic'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                </select>
                                                            </div>
                                                            <div class="rating-card">
                                                                <h6><i class="fas fa-users"></i> Non-Academic Activities</h6>
                                                                <select class="form-control" name="non_academic" required>
                                                                    <option value="excellent" <?php echo ($eval['non_academic'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                    <option value="very-good" <?php echo ($eval['non_academic'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                    <option value="good" <?php echo ($eval['non_academic'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                    <option value="needs-improvement" <?php echo ($eval['non_academic'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                </select>
                                                            </div>
                                                            <div class="rating-card">
                                                                <h6><i class="fas fa-brain"></i> Cognitive Domain</h6>
                                                                <select class="form-control" name="cognitive" required>
                                                                    <option value="excellent" <?php echo ($eval['cognitive'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                    <option value="very-good" <?php echo ($eval['cognitive'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                    <option value="good" <?php echo ($eval['cognitive'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                    <option value="needs-improvement" <?php echo ($eval['cognitive'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                </select>
                                                            </div>
                                                            <div class="rating-card">
                                                                <h6><i class="fas fa-hand-paper"></i> Psychomotor Domain</h6>
                                                                <select class="form-control" name="psychomotor" required>
                                                                    <option value="excellent" <?php echo ($eval['psychomotor'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                    <option value="very-good" <?php echo ($eval['psychomotor'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                    <option value="good" <?php echo ($eval['psychomotor'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                    <option value="needs-improvement" <?php echo ($eval['psychomotor'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                </select>
                                                            </div>
                                                            <div class="rating-card">
                                                                <h6><i class="fas fa-heart"></i> Affective Domain</h6>
                                                                <select class="form-control" name="affective" required>
                                                                    <option value="excellent" <?php echo ($eval['affective'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                    <option value="very-good" <?php echo ($eval['affective'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                    <option value="good" <?php echo ($eval['affective'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                    <option value="needs-improvement" <?php echo ($eval['affective'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                </select>
                                                            </div>
                                                        </div>

                                                        <div class="comments-section">
                                                            <h6><i class="fas fa-comment"></i> Comments & Recommendations</h6>
                                                            <textarea class="form-control" name="comments" rows="4" placeholder="Enter additional comments, strengths, and areas for improvement..."><?php echo htmlspecialchars($eval['comments']); ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-actions">
                                                        <button type="button" class="btn secondary" onclick="closeModal('editModal<?php echo $eval['id']; ?>')">Cancel</button>
                                                        <button type="submit" class="btn primary">Update Evaluation</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Evaluation Modal -->
    <div id="addEvaluationModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header modal-header-add">
                    <h3><i class="fas fa-plus"></i> Add New Evaluation</h3>
                    <button type="button" class="modal-close" onclick="closeModal('addEvaluationModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_evaluation" value="1">

                    <!-- Student and Class Selection -->
                    <div class="form-section">
                        <h3><i class="fas fa-user-graduate"></i> Student Information</h3>
                        <div class="form-inline">
                            <select class="form-control" name="student_id" required>
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select class="form-control" name="class_id" required>
                                <option value="">Select Class</option>
                                <?php
                                $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name");
                                $stmt->execute([$user_school_id]);
                                $classes = $stmt->fetchAll();
                                foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-inline">
                            <select class="form-control" name="term" required>
                                <option value="">Select Term</option>
                                <option value="1">Term 1</option>
                                <option value="2">Term 2</option>
                                <option value="3">Term 3</option>
                            </select>
                            <input type="number" class="form-control" name="year" placeholder="Academic Year" value="<?php echo date('Y'); ?>" required>
                        </div>
                    </div>

                    <!-- Performance Ratings -->
                    <div class="form-section">
                        <h3><i class="fas fa-star"></i> Performance Evaluation</h3>
                        <div class="rating-grid">
                            <div class="rating-card">
                                <h6><i class="fas fa-graduation-cap"></i> Academic Performance</h6>
                                <select class="form-control" name="academic" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="rating-card">
                                <h6><i class="fas fa-users"></i> Non-Academic Activities</h6>
                                <select class="form-control" name="non_academic" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="rating-card">
                                <h6><i class="fas fa-brain"></i> Cognitive Domain</h6>
                                <select class="form-control" name="cognitive" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="rating-card">
                                <h6><i class="fas fa-hand-paper"></i> Psychomotor Domain</h6>
                                <select class="form-control" name="psychomotor" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="rating-card">
                                <h6><i class="fas fa-heart"></i> Affective Domain</h6>
                                <select class="form-control" name="affective" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Comments Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-comment"></i> Additional Comments</h3>
                        <textarea class="form-control" name="comments" rows="4" placeholder="Enter additional comments, strengths, areas for improvement, and recommendations..."></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn secondary" onclick="closeModal('addEvaluationModal')">Cancel</button>
                    <button type="submit" class="btn primary">
                        <i class="fas fa-save"></i> Save Evaluation
                    </button>
                </div>
            </form>
        </div>
    </div>

    

    <!-- Scripts -->
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
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        // Modal Functions
        function showAddEvaluationModal() {
            document.getElementById('addEvaluationModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function showViewModal(evaluationId) {
            document.getElementById('viewModal' + evaluationId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function showEditModal(evaluationId) {
            document.getElementById('editModal' + evaluationId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                e.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });

        // Table Filtering Function
        function filterTable() {
            const searchInput = document.getElementById('searchInput').value.toLowerCase();
            const termFilter = document.getElementById('termFilter').value;
            const ratingFilter = document.getElementById('ratingFilter').value;
            const table = document.getElementById('evaluationsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                let showRow = true;

                // Search filter (student name)
                if (searchInput) {
                    const studentName = cells[0].textContent.toLowerCase();
                    if (!studentName.includes(searchInput)) {
                        showRow = false;
                    }
                }

                // Term filter
                if (termFilter && showRow) {
                    const termText = cells[2].textContent;
                    if (!termText.includes('Term ' + termFilter)) {
                        showRow = false;
                    }
                }

                // Rating filter (check all rating columns)
                if (ratingFilter && showRow) {
                    let hasRating = false;
                    for (let j = 3; j <= 7; j++) { // Academic to Affective columns
                        const ratingText = cells[j].textContent.toLowerCase().replace(/\s+/g, '-');
                        if (ratingText.includes(ratingFilter)) {
                            hasRating = true;
                            break;
                        }
                    }
                    if (!hasRating) {
                        showRow = false;
                    }
                }

                row.style.display = showRow ? '' : 'none';
            }
        }

        // Print function
        function printEvaluation(evaluationId) {
            window.open(`print-evaluation.php?id=${evaluationId}`, '_blank');
        }

        // Export evaluation function
        function exportEvaluation(evaluationId) {
            window.open(`export-evaluation.php?id=${evaluationId}`, '_blank');
        }

        // Form submission with loading state
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.classList.add('loading');
                        submitBtn.disabled = true;
                    }
                });
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

        // Add active class on scroll
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Add ripple effect to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!this.classList.contains('loading')) {
                    const ripple = document.createElement('span');
                    ripple.style.position = 'absolute';
                    ripple.style.borderRadius = '50%';
                    ripple.style.background = 'rgba(255, 255, 255, 0.3)';
                    ripple.style.transform = 'scale(0)';
                    ripple.style.animation = 'ripple 0.6s linear';
                    ripple.style.left = (e.offsetX - 10) + 'px';
                    ripple.style.top = (e.offsetY - 10) + 'px';
                    ripple.style.width = '20px';
                    ripple.style.height = '20px';

                    this.style.position = 'relative';
                    this.style.overflow = 'hidden';
                    this.appendChild(ripple);

                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                }
            });
        });

        // Add CSS animation for ripple
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Intersection observer for fade-in animations
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

        // Initially hide elements for animation
        document.querySelectorAll('.panel, .stat-card, .alert').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'all 0.6s ease';
            observer.observe(el);
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>


</body>



