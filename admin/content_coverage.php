<?php
// admin/content_coverage.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow principals to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

// Get current school for data isolation
$current_school_id = require_school_auth();

$principal_name = $_SESSION['full_name'] ?? 'Administrator';
$admin_id = $_SESSION['user_id'];
$errors = [];
$success = '';

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_coverage' || $action === 'reject_coverage') {
        $coverage_id = intval($_POST['coverage_id'] ?? 0);
        $comments = trim($_POST['principal_comments'] ?? '');

        if ($coverage_id <= 0) {
            $errors[] = 'Invalid coverage entry.';
        } else {
            $status = ($action === 'approve_coverage') ? 'approved' : 'rejected';

            try {
                $stmt = $pdo->prepare("UPDATE content_coverage SET
                    status = ?, principal_id = ?, approved_at = NOW(),
                    principal_comments = ? WHERE id = ?");
                $stmt->execute([$status, $admin_id, $comments ?: null, $coverage_id]);

                $action_text = ($status === 'approved') ? 'approved' : 'rejected';
                $success = "Content coverage entry has been {$action_text} successfully.";

            } catch (Exception $e) {
                $errors[] = 'Failed to update coverage: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'bulk_approve') {
        $coverage_ids = $_POST['selected_ids'] ?? [];
        if (!empty($coverage_ids)) {
            try {
                $placeholders = str_repeat('?,', count($coverage_ids) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE content_coverage SET
                    status = 'approved', principal_id = ?, approved_at = NOW()
                    WHERE id IN ($placeholders) AND status = 'pending'");
                $stmt->execute(array_merge([$admin_id], $coverage_ids));

                $success = count($coverage_ids) . ' coverage entries approved successfully.';
            } catch (Exception $e) {
                $errors[] = 'Failed to bulk approve: ' . $e->getMessage();
            }
        }
    }
}

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['filter_status'] ?? '';
$filter_teacher = $_GET['filter_teacher'] ?? '';
$filter_subject = $_GET['filter_subject'] ?? '';
$filter_class = $_GET['filter_class'] ?? '';
$filter_term = $_GET['filter_term'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';

// Build query
$query = "SELECT cc.*,
           s.subject_name, cl.class_name,
           t.full_name as teacher_name,
           p.full_name as principal_name
          FROM content_coverage cc
          JOIN subjects s ON cc.subject_id = s.id AND s.school_id = ?
          JOIN classes cl ON cc.class_id = cl.id AND cl.school_id = ?
          JOIN users t ON cc.teacher_id = t.id AND t.school_id = ?
          LEFT JOIN users p ON cc.principal_id = p.id
          WHERE 1=1";

$params = [];

if ($search !== '') {
    $query .= " AND (cc.topics_covered LIKE ? OR s.subject_name LIKE ? OR cl.class_name LIKE ? OR t.full_name LIKE ?)";
    $search_param = '%' . $search . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($filter_status !== '') {
    $query .= " AND cc.status = ?";
    $params[] = $filter_status;
}

if ($filter_teacher !== '') {
    $query .= " AND cc.teacher_id = ?";
    $params[] = $filter_teacher;
}

if ($filter_subject !== '') {
    $query .= " AND cc.subject_id = ?";
    $params[] = $filter_subject;
}

if ($filter_class !== '') {
    $query .= " AND cc.class_id = ?";
    $params[] = $filter_class;
}

if ($filter_term !== '') {
    $query .= " AND cc.term = ?";
    $params[] = $filter_term;
}

if ($filter_date_from !== '') {
    $query .= " AND cc.date_covered >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to !== '') {
    $query .= " AND cc.date_covered <= ?";
    $params[] = $filter_date_to;
}

$query .= " ORDER BY cc.date_covered DESC, cc.submitted_at DESC";

try {
    // Add school_id parameters at the beginning for the JOINs
    $school_params = [$current_school_id, $current_school_id, $current_school_id];
    $stmt = $pdo->prepare($query);
    $stmt->execute(array_merge($school_params, $params));
    $coverage_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $coverage_entries = [];
    $errors[] = 'Failed to load coverage entries: ' . $e->getMessage();
}

// Get filter options - school filtered
$teachers_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND school_id = ? ORDER BY full_name");
$teachers_stmt->execute([$current_school_id]);
$teachers = $teachers_stmt->fetchAll();

$subjects_stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name");
$subjects_stmt->execute([$current_school_id]);
$subjects = $subjects_stmt->fetchAll();

$classes_stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name");
$classes_stmt->execute([$current_school_id]);
$classes = $classes_stmt->fetchAll();

// Statistics
$stats = [
    'total' => count($coverage_entries),
    'pending' => count(array_filter($coverage_entries, fn($c) => $c['status'] === 'pending')),
    'approved' => count(array_filter($coverage_entries, fn($c) => $c['status'] === 'approved')),
    'rejected' => count(array_filter($coverage_entries, fn($c) => $c['status'] === 'rejected'))
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Coverage Review | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
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
                        <a href="content_coverage.php" class="nav-link active">
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
            <!-- Content Header -->
            <div class="content-header">
                <div class="welcome-section">
                    <h2><i class="fas fa-clipboard-check"></i> Content Coverage Review</h2>
                    <p>Review and approve teacher content coverage submissions</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📋</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Submissions</h3>
                        <p class="card-value"><?php echo number_format($stats['total']); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">All Records</span>
                            <a href="#coverage-table" class="card-link">View All →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">⏳</div>
                    </div>
                    <div class="card-content">
                        <h3>Pending Review</h3>
                        <p class="card-value"><?php echo number_format($stats['pending']); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Requires Action</span>
                            <a href="?filter_status=pending" class="card-link">Review →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">✅</div>
                    </div>
                    <div class="card-content">
                        <h3>Approved</h3>
                        <p class="card-value"><?php echo number_format($stats['approved']); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Completed</span>
                            <a href="?filter_status=approved" class="card-link">View →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">❌</div>
                    </div>
                    <div class="card-content">
                        <h3>Rejected</h3>
                        <p class="card-value"><?php echo number_format($stats['rejected']); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Needs Revision</span>
                            <a href="?filter_status=rejected" class="card-link">Review →</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($errors): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Filters Section -->
            <div class="charts-section">
                <div class="section-header">
                    <h3>🔍 Filter & Search</h3>
                    <span class="section-badge">Advanced</span>
                </div>
                <form method="GET" class="filters-form">
                    <div class="stats-grid">
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search topics, teacher, subject...">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="filter_status" class="form-control">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Teacher</label>
                            <select name="filter_teacher" class="form-control">
                                <option value="">All Teachers</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>" <?php echo $filter_teacher == $teacher['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($teacher['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Subject</label>
                            <select name="filter_subject" class="form-control">
                                <option value="">All Subjects</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo $filter_subject == $subject['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Class</label>
                            <select name="filter_class" class="form-control">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Term</label>
                            <select name="filter_term" class="form-control">
                                <option value="">All Terms</option>
                                <option value="1st Term" <?php echo $filter_term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                                <option value="2nd Term" <?php echo $filter_term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                                <option value="3rd Term" <?php echo $filter_term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                        </div>
                    </div>

                    <div class="modal-footer" style="border-top: none; padding-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i>
                            <span>Apply Filters</span>
                        </button>
                        <a href="content_coverage.php" class="btn" style="background: var(--gray-200); color: var(--gray-700);">
                            <i class="fas fa-redo"></i>
                            <span>Reset</span>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <?php if ($stats['pending'] > 0): ?>
                <div class="activity-section">
                    <div class="section-header">
                        <h3>⚡ Bulk Actions</h3>
                        <span class="section-badge">Quick Approve</span>
                    </div>
                    <form method="POST" id="bulkForm" onsubmit="return confirm('Are you sure you want to approve selected pending entries?');">
                        <input type="hidden" name="action" value="bulk_approve">
                        <div class="activity-list" style="padding: 1.5rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                                <div>
                                    <strong><?php echo $stats['pending']; ?> entries pending review</strong>
                                    <p style="margin: 0.5rem 0 0 0; color: var(--gray-600); font-size: 0.9rem;">Select multiple entries for bulk approval</p>
                                </div>
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <button type="button" class="btn btn-success" onclick="selectAllPending()">
                                        <i class="fas fa-check-square"></i>
                                        Select All Pending
                                    </button>
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-check"></i>
                                        Bulk Approve Selected
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Coverage Table -->
            <div class="activity-section" id="coverage-table">
                <div class="section-header">
                    <h3>📋 Coverage Entries</h3>
                    <span class="section-badge"><?php echo count($coverage_entries); ?> Results</span>
                </div>

                <?php if (empty($coverage_entries)): ?>
                    <div class="activity-list">
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list empty-icon"></i>
                            <h3 class="empty-title">No Coverage Entries Found</h3>
                            <p>No content coverage entries match your current filters.</p>
                            <a href="content_coverage.php" class="btn btn-primary">Clear Filters</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                    </th>
                                    <th>Date</th>
                                    <th>Teacher</th>
                                    <th>Subject</th>
                                    <th>Class</th>
                                    <th>Term</th>
                                    <th>Topics Covered</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coverage_entries as $entry): ?>
                                    <tr>
                                        <td>
                                            <?php if ($entry['status'] === 'pending'): ?>
                                                <input type="checkbox" name="selected_ids[]" value="<?php echo $entry['id']; ?>" form="bulkForm" class="select-checkbox">
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($entry['date_covered'])); ?></td>
                                        <td><?php echo htmlspecialchars($entry['teacher_name']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['term']); ?></td>
                                        <td>
                                            <div style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($entry['topics_covered']); ?>">
                                                <?php echo htmlspecialchars(substr($entry['topics_covered'], 0, 80)); ?>
                                                <?php if (strlen($entry['topics_covered']) > 80): ?>...<?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $entry['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $entry['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <button class="btn btn-primary" onclick="viewDetails(<?php echo $entry['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                    View
                                                </button>
                                                <?php if ($entry['status'] === 'pending'): ?>
                                                    <button class="btn btn-success" onclick="approveEntry(<?php echo $entry['id']; ?>)">
                                                        <i class="fas fa-check"></i>
                                                        Approve
                                                    </button>
                                                    <button class="btn btn-danger" onclick="rejectEntry(<?php echo $entry['id']; ?>)">
                                                        <i class="fas fa-times"></i>
                                                        Reject
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    

    <!-- Details Modal -->
    <div class="modal" id="detailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Coverage Details</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button onclick="closeModal()" class="btn" style="background: var(--gray-200); color: var(--gray-700);">Close</button>
            </div>
        </div>
    </div>

    <!-- Approval Modal -->
    <div class="modal" id="approvalModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="approvalTitle">Approve Coverage</h2>
                <button class="close-btn" onclick="closeApprovalModal()">&times;</button>
            </div>
            <form method="POST" id="approvalForm">
                <div class="modal-body">
                    <input type="hidden" name="coverage_id" id="approvalCoverageId">
                    <input type="hidden" name="action" id="approvalAction">

                    <div class="form-group">
                        <label class="form-label">Comments (Optional)</label>
                        <textarea name="principal_comments" class="form-control" rows="3" placeholder="Add any comments or feedback..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeApprovalModal()" class="btn" style="background: var(--gray-200); color: var(--gray-700);">Cancel</button>
                    <button type="submit" class="btn" id="approvalSubmitBtn">Submit</button>
                </div>
            </form>
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
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        // View details modal
        function viewDetails(coverageId) {
            fetch(`ajax/get_coverage_details.php?id=${coverageId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalContent').innerHTML = data.html;
                        document.getElementById('detailsModal').style.display = 'block';
                    } else {
                        alert('Failed to load coverage details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading details');
                });
        }

        // Approve entry
        function approveEntry(coverageId) {
            document.getElementById('approvalCoverageId').value = coverageId;
            document.getElementById('approvalAction').value = 'approve_coverage';
            document.getElementById('approvalTitle').textContent = 'Approve Coverage';
            document.getElementById('approvalSubmitBtn').textContent = 'Approve';
            document.getElementById('approvalSubmitBtn').className = 'btn btn-success';
            document.getElementById('approvalModal').style.display = 'block';
        }

        // Reject entry
        function rejectEntry(coverageId) {
            document.getElementById('approvalCoverageId').value = coverageId;
            document.getElementById('approvalAction').value = 'reject_coverage';
            document.getElementById('approvalTitle').textContent = 'Reject Coverage';
            document.getElementById('approvalSubmitBtn').textContent = 'Reject';
            document.getElementById('approvalSubmitBtn').className = 'btn btn-danger';
            document.getElementById('approvalModal').style.display = 'block';
        }

        // Close modals
        function closeModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        function closeApprovalModal() {
            document.getElementById('approvalModal').style.display = 'none';
        }

        // Bulk selection
        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.select-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
        }

        function selectAllPending() {
            const checkboxes = document.querySelectorAll('.select-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const detailsModal = document.getElementById('detailsModal');
            const approvalModal = document.getElementById('approvalModal');
            if (event.target === detailsModal) {
                closeModal();
            }
            if (event.target === approvalModal) {
                closeApprovalModal();
            }
        }

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



