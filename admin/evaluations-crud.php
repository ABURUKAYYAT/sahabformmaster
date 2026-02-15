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

// Handle CRUD operations
$success_message = '';
$error_message = '';

// Add new evaluation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_evaluation'])) {
    try {
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
        
        // Validate required fields
        if (empty($student_id) || empty($class_id) || empty($term) || empty($year)) {
            throw new Exception("Please fill in all required fields.");
        }
        
        $stmt = $pdo->prepare("INSERT INTO evaluations (school_id, student_id, class_id, term, academic_year, academic, non_academic, cognitive, psychomotor, affective, comments, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$current_school_id, $student_id, $class_id, $term, $year, $academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $_SESSION['user_id']]);
        
        $success_message = "Evaluation added successfully!";
        
        // Clear form data after successful submission
        unset($_POST);
        
    } catch (Exception $e) {
        $error_message = "Error adding evaluation: " . $e->getMessage();
    }
}

// Update evaluation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_evaluation'])) {
    try {
        $evaluation_id = $_POST['evaluation_id'];
        $academic = $_POST['academic'];
        $non_academic = $_POST['non_academic'];
        $cognitive = $_POST['cognitive'];
        $psychomotor = $_POST['psychomotor'];
        $affective = $_POST['affective'];
        $comments = $_POST['comments'];
        
        $stmt = $pdo->prepare("UPDATE evaluations SET academic = ?, non_academic = ?, cognitive = ?, psychomotor = ?, affective = ?, comments = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
        $stmt->execute([$academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $evaluation_id, $current_school_id]);
        
        $success_message = "Evaluation updated successfully!";
        
    } catch (Exception $e) {
        $error_message = "Error updating evaluation: " . $e->getMessage();
    }
}

// Delete evaluation
if (isset($_GET['delete_id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id = ? AND school_id = ?");
        $stmt->execute([$_GET['delete_id'], $current_school_id]);
        
        $success_message = "Evaluation deleted successfully!";
        header("Location: evaluations-crud.php");
        exit();
    } catch (Exception $e) {
        $error_message = "Error deleting evaluation: " . $e->getMessage();
    }
}

// Bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    try {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id IN ($placeholders) AND school_id = ?");
            $params = array_merge($ids, [$current_school_id]);
            $stmt->execute($params);
            
            $success_message = "Selected evaluations deleted successfully!";
        }
    } catch (Exception $e) {
        $error_message = "Error deleting evaluations: " . $e->getMessage();
    }
}

// Fetch evaluations with filtering
$search = $_GET['search'] ?? '';
$term_filter = $_GET['term_filter'] ?? '';
$rating_filter = $_GET['rating_filter'] ?? '';
$class_filter = $_GET['class_filter'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where = ["e.school_id = ?"];
$params = [$current_school_id];

if (!empty($search)) {
    $where[] = "(s.full_name LIKE ? OR s.admission_no LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($term_filter)) {
    $where[] = "e.term = ?";
    $params[] = $term_filter;
}

if (!empty($rating_filter)) {
    $where[] = "(e.academic = ? OR e.non_academic = ? OR e.cognitive = ? OR e.psychomotor = ? OR e.affective = ?)";
    $params = array_merge($params, array_fill(0, 5, $rating_filter));
}

if (!empty($class_filter)) {
    $where[] = "e.class_id = ?";
    $params[] = $class_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where);

// Get total count for pagination
$stmt = $pdo->prepare("SELECT COUNT(*) FROM evaluations e JOIN students s ON e.student_id = s.id $where_clause");
$stmt->execute($params);
$total_evaluations = $stmt->fetchColumn();
$total_pages = ceil($total_evaluations / $limit);

// Fetch evaluations
$stmt = $pdo->prepare("
    SELECT e.*, s.full_name, s.class_id, s.admission_no, c.class_name
    FROM evaluations e
    JOIN students s ON e.student_id = s.id
    LEFT JOIN classes c ON e.class_id = c.id
    $where_clause
    ORDER BY e.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$limit, $offset]));
$evaluations = $stmt->fetchAll();

// Fetch students for dropdown
$stmt = $pdo->prepare("SELECT id, full_name, class_id FROM students WHERE school_id = ? ORDER BY class_id, full_name");
$stmt->execute([$current_school_id]);
$students = $stmt->fetchAll();

// Fetch classes for dropdown
$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name");
$stmt->execute([$current_school_id]);
$classes = $stmt->fetchAll();

// Calculate statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_evaluations,
        COUNT(DISTINCT student_id) as total_students,
        COUNT(DISTINCT class_id) as total_classes,
        SUM(CASE WHEN academic = 'excellent' OR non_academic = 'excellent' OR cognitive = 'excellent' OR psychomotor = 'excellent' OR affective = 'excellent' THEN 1 ELSE 0 END) as excellent_count,
        SUM(CASE WHEN academic = 'needs-improvement' OR non_academic = 'needs-improvement' OR cognitive = 'needs-improvement' OR psychomotor = 'needs-improvement' OR affective = 'needs-improvement' THEN 1 ELSE 0 END) as needs_improvement_count
    FROM evaluations 
    WHERE school_id = ?
");
$stmt->execute([$current_school_id]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluations CRUD - Principal Dashboard</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-evaluations-crud.css?v=1.0">
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
                        <a href="evaluations-crud.php" class="nav-link active">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Evaluations CRUD</span>
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
                    <h2>⭐ Evaluations Management</h2>
                    <p>Complete CRUD operations for student evaluations - No modals required</p>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-clipboard-check"></i>
                    <h3>Total Evaluations</h3>
                    <div class="count"><?php echo number_format($stats['total_evaluations'] ?? 0); ?></div>
                    <p class="stat-description">All evaluations</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <h3>Students Evaluated</h3>
                    <div class="count"><?php echo number_format($stats['total_students'] ?? 0); ?></div>
                    <p class="stat-description">Unique students</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-star"></i>
                    <h3>Excellent Ratings</h3>
                    <div class="count"><?php echo number_format($stats['excellent_count'] ?? 0); ?></div>
                    <p class="stat-description">Outstanding performance</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-chart-line"></i>
                    <h3>Needs Improvement</h3>
                    <div class="count"><?php echo number_format($stats['needs_improvement_count'] ?? 0); ?></div>
                    <p class="stat-description">Areas for development</p>
                </div>
            </div>

            <!-- Add Evaluation Section -->
            <section class="panel" id="add-evaluation-section">
                <div class="panel-header">
                    <h2><i class="fas fa-plus-circle"></i> Add New Evaluation</h2>
                    <button class="toggle-section-btn" onclick="toggleSection('add-evaluation-section')">
                        <i class="fas fa-chevron-up"></i>
                    </button>
                </div>
                <div class="panel-body">
                    <form method="post" id="add-evaluation-form">
                        <input type="hidden" name="add_evaluation" value="1">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="student_id">Student <span class="required">*</span></label>
                                <select name="student_id" id="student_id" required>
                                    <option value="">Select Student</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['id']; ?>">
                                            <?php echo htmlspecialchars($student['full_name']); ?> (Class: <?php echo $student['class_id']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="class_id">Class <span class="required">*</span></label>
                                <select name="class_id" id="class_id" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="term">Term <span class="required">*</span></label>
                                <select name="term" id="term" required>
                                    <option value="">Select Term</option>
                                    <option value="1">Term 1</option>
                                    <option value="2">Term 2</option>
                                    <option value="3">Term 3</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="year">Academic Year <span class="required">*</span></label>
                                <input type="number" name="year" id="year" value="<?php echo date('Y'); ?>" required>
                            </div>
                        </div>

                        <div class="rating-grid">
                            <div class="rating-card">
                                <h6><i class="fas fa-graduation-cap"></i> Academic Performance</h6>
                                <select name="academic" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="rating-card">
                                <h6><i class="fas fa-users"></i> Non-Academic Activities</h6>
                                <select name="non_academic" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="rating-card">
                                <h6><i class="fas fa-brain"></i> Cognitive Domain</h6>
                                <select name="cognitive" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="rating-card">
                                <h6><i class="fas fa-hand-paper"></i> Psychomotor Domain</h6>
                                <select name="psychomotor" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="rating-card">
                                <h6><i class="fas fa-heart"></i> Affective Domain</h6>
                                <select name="affective" required>
                                    <option value="">Select Rating</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="comments">Comments & Recommendations</label>
                            <textarea name="comments" id="comments" rows="4" placeholder="Enter additional comments, strengths, areas for improvement, and recommendations..."></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Evaluation
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-undo"></i> Reset Form
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Evaluations Management Section -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-database"></i> Evaluations Management</h2>
                    <div class="panel-actions">
                        <button class="btn btn-secondary" onclick="exportEvaluations('pdf')">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                        <button class="btn btn-secondary" onclick="exportEvaluations('excel')">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                    </div>
                </div>

                <!-- Search and Filter Section -->
                <div class="panel-body">
                    <form method="get" class="filter-form">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="search">Search Students</label>
                                <div class="search-input">
                                    <i class="fas fa-search"></i>
                                    <input type="text" id="search" name="search" placeholder="Search by name or admission number..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>
                            
                            <div class="filter-group">
                                <label for="term_filter">Filter by Term</label>
                                <select id="term_filter" name="term_filter">
                                    <option value="">All Terms</option>
                                    <option value="1" <?php echo $term_filter == '1' ? 'selected' : ''; ?>>Term 1</option>
                                    <option value="2" <?php echo $term_filter == '2' ? 'selected' : ''; ?>>Term 2</option>
                                    <option value="3" <?php echo $term_filter == '3' ? 'selected' : ''; ?>>Term 3</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="rating_filter">Filter by Rating</label>
                                <select id="rating_filter" name="rating_filter">
                                    <option value="">All Ratings</option>
                                    <option value="excellent" <?php echo $rating_filter == 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                    <option value="very-good" <?php echo $rating_filter == 'very-good' ? 'selected' : ''; ?>>Very Good</option>
                                    <option value="good" <?php echo $rating_filter == 'good' ? 'selected' : ''; ?>>Good</option>
                                    <option value="needs-improvement" <?php echo $rating_filter == 'needs-improvement' ? 'selected' : ''; ?>>Needs Improvement</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="class_filter">Filter by Class</label>
                                <select id="class_filter" name="class_filter">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label>&nbsp;</label>
                                <div class="filter-buttons">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Apply Filters
                                    </button>
                                    <a href="evaluations-crud.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Clear Filters
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- Bulk Actions -->
                    <div class="bulk-actions" id="bulk-actions" style="display: none;">
                        <div class="bulk-info">
                            <span id="selected-count">0</span> items selected
                        </div>
                        <div class="bulk-buttons">
                            <button class="btn btn-danger" onclick="bulkDelete()">
                                <i class="fas fa-trash"></i> Delete Selected
                            </button>
                            <button class="btn btn-secondary" onclick="clearSelection()">
                                <i class="fas fa-times"></i> Clear Selection
                            </button>
                        </div>
                    </div>

                    <!-- Evaluations Table -->
                    <div class="table-container">
                        <table class="table evaluations-table" id="evaluationsTable">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="select-all" onclick="toggleSelectAll()"></th>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Term/Year</th>
                                    <th>Academic</th>
                                    <th>Non-Academic</th>
                                    <th>Cognitive</th>
                                    <th>Psychomotor</th>
                                    <th>Affective</th>
                                    <th>Comments</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($evaluations)): ?>
                                    <tr>
                                        <td colspan="12" class="no-data">
                                            <i class="fas fa-clipboard-list"></i>
                                            <p>No evaluations found. Add your first evaluation above.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($evaluations as $eval): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="select-item" value="<?php echo $eval['id']; ?>">
                                            </td>
                                            <td>
                                                <div class="student-info">
                                                    <strong><?php echo htmlspecialchars($eval['full_name']); ?></strong>
                                                    <br><small class="text-muted">Roll: <?php echo $eval['admission_no']; ?></small>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($eval['class_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge badge-info">Term <?php echo $eval['term']; ?></span>
                                                <br><small><?php echo $eval['academic_year']; ?></small>
                                            </td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['academic']); ?>"><?php echo ucfirst($eval['academic']); ?></span></td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['non_academic']); ?>"><?php echo ucfirst($eval['non_academic']); ?></span></td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['cognitive']); ?>"><?php echo ucfirst($eval['cognitive']); ?></span></td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['psychomotor']); ?>"><?php echo ucfirst($eval['psychomotor']); ?></span></td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['affective']); ?>"><?php echo ucfirst($eval['affective']); ?></span></td>
                                            <td>
                                                <div class="comments-preview">
                                                    <?php echo !empty($eval['comments']) ? 
                                                        (strlen($eval['comments']) > 50 ? 
                                                            substr(htmlspecialchars($eval['comments']), 0, 50) . '...' : 
                                                            htmlspecialchars($eval['comments'])) : 
                                                        '<span class="no-comments">No comments</span>'; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <small><?php echo date('M j, Y', strtotime($eval['created_at'])); ?></small>
                                                <br><small class="text-muted"><?php echo date('H:i', strtotime($eval['created_at'])); ?></small>
                                            </td>
                                            <td class="actions">
                                                <button class="btn btn-secondary btn-sm" onclick="viewEvaluation(<?php echo $eval['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <button class="btn btn-warning btn-sm" onclick="editEvaluation(<?php echo $eval['id']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this evaluation?');">
                                                    <input type="hidden" name="delete_id" value="<?php echo $eval['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                                <button class="btn btn-success btn-sm" onclick="printEvaluation(<?php echo $eval['id']; ?>)">
                                                    <i class="fas fa-print"></i> Print
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $limit, $total_evaluations); ?> of <?php echo $total_evaluations; ?> evaluations
                            </div>
                            <div class="pagination-controls">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&term_filter=<?php echo $term_filter; ?>&rating_filter=<?php echo $rating_filter; ?>&class_filter=<?php echo $class_filter; ?>" class="btn btn-secondary">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&term_filter=<?php echo $term_filter; ?>&rating_filter=<?php echo $rating_filter; ?>&class_filter=<?php echo $class_filter; ?>" 
                                       class="btn <?php echo $i == $page ? 'btn-primary' : 'btn-secondary'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&term_filter=<?php echo $term_filter; ?>&rating_filter=<?php echo $rating_filter; ?>&class_filter=<?php echo $class_filter; ?>" class="btn btn-secondary">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- View/Edit Section -->
            <section class="panel" id="view-edit-section" style="display: none;">
                <div class="panel-header">
                    <h2 id="view-edit-title"><i class="fas fa-eye"></i> View Evaluation</h2>
                    <div class="panel-actions">
                        <button class="btn btn-secondary" onclick="closeViewEdit()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
                <div class="panel-body" id="view-edit-content">
                    <!-- Content loaded via JavaScript -->
                </div>
            </section>
        </main>
    </div>

    <!-- Scripts -->
    <script src="../assets/js/admin-evaluations-crud.js"></script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>