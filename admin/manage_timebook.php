<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}
$current_school_id = require_school_auth();

// CSRF token
$csrf_token = generate_csrf_token();

// Ensure system_settings table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Ensure school_settings table exists (per-school settings)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS school_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        school_id INT NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY school_setting_unique (school_id, setting_key)
    )");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Handle toggle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_signin'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['message'] = "Security validation failed. Please refresh the page.";
        header("Location: manage_timebook.php");
        exit();
    }
    $enabled = $_POST['enabled'] ? '1' : '0';
    $stmt = $pdo->prepare("INSERT INTO school_settings (school_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
    $stmt->execute([$current_school_id, 'teacher_signin_enabled', $enabled, $enabled]);
    $_SESSION['message'] = "Teacher sign-in " . ($enabled === '1' ? 'enabled' : 'disabled') . " successfully!";
    header("Location: manage_timebook.php");
    exit();
}

// Get current toggle state
$toggleStmt = $pdo->prepare("SELECT setting_value FROM school_settings WHERE school_id = ? AND setting_key = ?");
$toggleStmt->execute([$current_school_id, 'teacher_signin_enabled']);
$signin_enabled = $toggleStmt->fetchColumn();
if ($signin_enabled === false) {
    $fallbackStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $fallbackStmt->execute(['teacher_signin_enabled']);
    $signin_enabled = $fallbackStmt->fetchColumn();
}
$signin_enabled = $signin_enabled === false ? true : ($signin_enabled === '1' || $signin_enabled === 1);


// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['message'] = "Security validation failed. Please refresh the page.";
        header("Location: manage_timebook.php");
        exit();
    }
    $id = $_POST['id'];
    $status = $_POST['status'];
    $notes = $_POST['notes'] ?? '';
    
    $stmt = $pdo->prepare("UPDATE time_records SET status = ?, admin_notes = ?, reviewed_at = NOW() WHERE id = ? AND school_id = ?");
    $stmt->execute([$status, $notes, $id, $current_school_id]);
    
    $_SESSION['message'] = "Status updated successfully!";
    header("Location: manage_timebook.php");
    exit();
}

// Handle bulk status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['message'] = "Security validation failed. Please refresh the page.";
        header("Location: manage_timebook.php");
        exit();
    }
    $bulk_status = $_POST['bulk_status'] ?? '';
    $bulk_notes = $_POST['bulk_notes'] ?? '';
    $record_ids = $_POST['record_ids'] ?? [];

    if (empty($record_ids) || !in_array($bulk_status, ['pending', 'agreed', 'not_agreed'], true)) {
        $_SESSION['message'] = "Please select records and a valid status.";
        header("Location: manage_timebook.php");
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($record_ids), '?'));
    $params = array_merge([$bulk_status, $bulk_notes, $current_school_id], $record_ids);

    $bulkStmt = $pdo->prepare("UPDATE time_records SET status = ?, admin_notes = ?, reviewed_at = NOW() WHERE school_id = ? AND id IN ($placeholders)");
    $bulkStmt->execute($params);

    $_SESSION['message'] = "Bulk update completed for " . count($record_ids) . " record(s).";
    header("Location: manage_timebook.php");
    exit();
}

// Handle filter
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$where = "WHERE DATE(tr.sign_in_time) = ? AND tr.school_id = ? AND u.school_id = ?";
$params = [$date, $current_school_id, $current_school_id];

if (!empty($search)) {
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter !== 'all') {
    $where .= " AND tr.status = ?";
    $params[] = $filter;
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportQuery = "SELECT tr.sign_in_time, tr.status, tr.notes, tr.admin_notes, u.full_name, u.email, u.expected_arrival
                    FROM time_records tr
                    JOIN users u ON tr.user_id = u.id
                    $where
                    ORDER BY tr.sign_in_time DESC";
    $exportStmt = $pdo->prepare($exportQuery);
    $exportStmt->execute($params);
    $rows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="timebook_records_' . $date . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Sign In Time', 'Teacher Name', 'Email', 'Expected Arrival', 'Status', 'Teacher Notes', 'Admin Notes']);
    foreach ($rows as $row) {
        $signIn = $row['sign_in_time'] ? date('Y-m-d H:i:s', strtotime($row['sign_in_time'])) : '';
        $dateOnly = $row['sign_in_time'] ? date('Y-m-d', strtotime($row['sign_in_time'])) : '';
        fputcsv($output, [
            $dateOnly,
            $signIn,
            $row['full_name'] ?? '',
            $row['email'] ?? '',
            $row['expected_arrival'] ?? '',
            $row['status'] ?? '',
            $row['notes'] ?? '',
            $row['admin_notes'] ?? ''
        ]);
    }
    fclose($output);
    exit();
}

// Total count for pagination
$countQuery = "SELECT COUNT(*) FROM time_records tr JOIN users u ON tr.user_id = u.id $where";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$total_records = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_records / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Data query with pagination
$query = "SELECT tr.*, u.full_name, u.email, u.expected_arrival 
          FROM time_records tr 
          JOIN users u ON tr.user_id = u.id 
          $where
          ORDER BY tr.sign_in_time DESC
          LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Get statistics
$statsQuery = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'agreed' THEN 1 ELSE 0 END) as agreed,
    SUM(CASE WHEN status = 'not_agreed' THEN 1 ELSE 0 END) as not_agreed,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM time_records WHERE DATE(sign_in_time) = ? AND school_id = ?";
$statsStmt = $pdo->prepare($statsQuery);
$statsStmt->execute([$date, $current_school_id]);
$stats = $statsStmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teacher Timebook | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .bulk-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            margin-bottom: 1rem;
        }
        .bulk-actions .form-control {
            min-width: 180px;
        }
        .bulk-actions textarea.form-control {
            min-width: 260px;
        }
        .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        .pagination a,
        .pagination span {
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            text-decoration: none;
            background: #f1f5f9;
            color: #1f2937;
            font-weight: 600;
        }
        .pagination .active {
            background: #2563eb;
            color: #fff;
        }
        .text-muted {
            color: #6b7280;
        }
        .select-all {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 600;
            color: #1f2937;
        }
        .record-checkbox {
            transform: scale(1.1);
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
                    <span class="principal-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Principal'); ?></span>
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
                        <a href="manage_timebook.php" class="nav-link active">
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
                    <h2>??? Manage Teacher Timebook</h2>
                    <p>Review and approve daily teacher sign-ins.</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>Total Records</h3>
                    <div class="count"><?php echo number_format($stats['total'] ?? 0); ?></div>
                    <p class="stat-description">Today</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-check-circle"></i>
                    <h3>Approved</h3>
                    <div class="count"><?php echo number_format($stats['agreed'] ?? 0); ?></div>
                    <p class="stat-description">Accepted records</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-clock"></i>
                    <h3>Pending</h3>
                    <div class="count"><?php echo number_format($stats['pending'] ?? 0); ?></div>
                    <p class="stat-description">Awaiting review</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-times-circle"></i>
                    <h3>Rejected</h3>
                    <div class="count"><?php echo number_format($stats['not_agreed'] ?? 0); ?></div>
                    <p class="stat-description">Not approved</p>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['message'])): ?>
                <?php $msg = $_SESSION['message']; unset($_SESSION['message']); ?>
                <div class="alert <?php echo stripos($msg, 'failed') !== false ? 'alert-error' : 'alert-success'; ?>">
                    <i class="fas fa-info-circle"></i>
                    <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endif; ?>

            <!-- System Controls -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-toggle-on"></i> System Controls</h2>
                </div>
                <div class="form-inline" style="align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <strong>Teacher Sign-in:</strong>
                        <span class="badge badge-info" style="margin-left: 0.5rem;"><?php echo $signin_enabled ? 'Enabled' : 'Disabled'; ?></span>
                    </div>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="toggle_signin" value="1">
                        <input type="hidden" name="enabled" value="<?php echo $signin_enabled ? '0' : '1'; ?>">
                        <button type="submit" class="btn <?php echo $signin_enabled ? 'danger' : 'success'; ?>">
                            <i class="fas fa-<?php echo $signin_enabled ? 'times' : 'check'; ?>"></i>
                            <?php echo $signin_enabled ? 'Disable' : 'Enable'; ?>
                        </button>
                    </form>
                </div>
            </section>

            <!-- Filters -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-filter"></i> Search & Filter</h2>
                </div>
                <form method="GET" class="form-inline" style="flex-wrap: wrap; gap: 0.75rem;">
                    <input type="text" class="form-control datepicker" name="date" value="<?php echo htmlspecialchars($date); ?>" placeholder="YYYY-MM-DD">
                    <input type="text" class="form-control" name="search" placeholder="Name or email..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="manage_timebook.php" class="btn secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                    <a href="?<?php
                        $exportParams = $_GET;
                        $exportParams['export'] = 'csv';
                        echo htmlspecialchars(http_build_query($exportParams));
                    ?>" class="btn success">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                </form>
                <div style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <a class="badge <?php echo $filter === 'all' ? 'badge-primary' : 'badge-secondary'; ?>" href="?date=<?php echo $date; ?>&filter=all">All Records</a>
                    <a class="badge <?php echo $filter === 'pending' ? 'badge-primary' : 'badge-secondary'; ?>" href="?date=<?php echo $date; ?>&filter=pending">Pending Review</a>
                    <a class="badge <?php echo $filter === 'agreed' ? 'badge-primary' : 'badge-secondary'; ?>" href="?date=<?php echo $date; ?>&filter=agreed">Approved</a>
                    <a class="badge <?php echo $filter === 'not_agreed' ? 'badge-primary' : 'badge-secondary'; ?>" href="?date=<?php echo $date; ?>&filter=not_agreed">Not Approved</a>
                </div>
            </section>

            <!-- Time Records -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-list"></i> Time Records</h2>
                </div>

                <?php if (empty($records)): ?>
                    <div style="text-align: center; padding: 2rem; color: #666;">
                        <i class="fas fa-clipboard-list" style="font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.5;"></i>
                        <h3 style="margin-bottom: 0.35rem;">No records found</h3>
                        <p style="margin: 0;">No attendance records for the selected date.</p>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="bulk_update" value="1">
                        <div class="bulk-actions">
                            <label class="select-all">
                                <input type="checkbox" id="selectAllRecords">
                                Select All
                            </label>
                            <select name="bulk_status" class="form-control" required>
                                <option value="">Set Status...</option>
                                <option value="agreed">Approve</option>
                                <option value="not_agreed">Reject</option>
                                <option value="pending">Mark Pending</option>
                            </select>
                            <textarea name="bulk_notes" class="form-control" rows="1" placeholder="Admin notes (optional)"></textarea>
                            <button type="submit" class="btn primary">
                                <i class="fas fa-check"></i> Apply to Selected
                            </button>
                        </div>

                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Teacher</th>
                                        <th>Sign In</th>
                                        <th>Expected</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $record): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="record_ids[]" value="<?php echo $record['id']; ?>" class="record-checkbox">
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($record['full_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($record['email']); ?></small>
                                            </td>
                                            <td><?php echo date('H:i:s', strtotime($record['sign_in_time'])); ?></td>
                                            <td><?php echo $record['expected_arrival'] ?: '-'; ?></td>
                                            <td>
                                                <span class="badge badge-<?php
                                                    echo $record['status'] === 'agreed' ? 'approved' :
                                                         ($record['status'] === 'not_agreed' ? 'rejected' : 'pending');
                                                ?>">
                                                    <?php
                                                    $statusLabels = [
                                                        'pending' => 'Pending Review',
                                                        'agreed' => 'Approved',
                                                        'not_agreed' => 'Not Approved'
                                                    ];
                                                    echo $statusLabels[$record['status']];
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($record['admin_notes'])): ?>
                                                    <button class="btn small secondary" data-bs-toggle="modal" data-bs-target="#reviewModal" data-id="<?php echo $record['id']; ?>" data-status="<?php echo $record['status']; ?>" data-notes="<?php echo htmlspecialchars($record['admin_notes'] ?? ''); ?>" data-name="<?php echo htmlspecialchars($record['full_name']); ?>">
                                                        <i class="fas fa-sticky-note"></i> View Notes
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="actions">
                                                <button class="btn small warning"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#reviewModal"
                                                        data-id="<?php echo $record['id']; ?>"
                                                        data-status="<?php echo $record['status']; ?>"
                                                        data-notes="<?php echo htmlspecialchars($record['admin_notes'] ?? ''); ?>"
                                                        data-name="<?php echo htmlspecialchars($record['full_name']); ?>">
                                                    <i class="fas fa-edit"></i> Review
                                                </button>
                                                <a href="teacher_report.php?id=<?php echo $record['user_id']; ?>" class="btn small secondary">
                                                    <i class="fas fa-chart-line"></i> Report
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php
                            $queryParams = $_GET;
                            unset($queryParams['page'], $queryParams['export']);
                            for ($p = 1; $p <= $total_pages; $p++):
                                $queryParams['page'] = $p;
                                $url = '?' . http_build_query($queryParams);
                            ?>
                                <?php if ($p === $page): ?>
                                    <span class="active"><?php echo $p; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo htmlspecialchars($url); ?>"><?php echo $p; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>

<!-- Review Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Review Attendance Record
                    </h2>
                    <button type="button" class="close-btn" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="modal-body">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="id" id="recordId">

                        <div class="form-group">
                            <label class="form-label">👨‍🏫 Teacher Name</label>
                            <input type="text" class="form-control" id="teacherName" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">📊 Review Status</label>
                            <select class="form-control" name="status" id="recordStatus" required>
                                <option value="pending">⏳ Pending Review</option>
                                <option value="agreed">✅ Approved</option>
                                <option value="not_agreed">❌ Not Approved</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">📝 Admin Notes (Optional)</label>
                            <textarea class="form-control" name="notes" id="recordNotes" rows="4"
                                      placeholder="Add any notes or comments for this attendance record..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize datepicker
            $('.datepicker').datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true
            });

            // Select all records
            $('#selectAllRecords').on('change', function() {
                $('.record-checkbox').prop('checked', this.checked);
            });
            
            // Handle modal data
            $('#reviewModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);
                var id = button.data('id');
                var status = button.data('status');
                var notes = button.data('notes');
                var name = button.data('name');
                
                var modal = $(this);
                modal.find('#recordId').val(id);
                modal.find('#teacherName').val(name);
                modal.find('#recordStatus').val(status);
                modal.find('#recordNotes').val(notes);
            });
        });

        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        if (mobileMenuToggle && sidebar && sidebarClose) {
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
        }
    </script><?php include '../includes/floating-button.php'; ?></body>
</html>



