<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is principal with school authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: login.php');
    exit();
}

$current_school_id = require_school_auth();

$principal_name = $_SESSION['full_name'];

// Handle CRUD Operations
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

if ($action === 'create') {
    $stmt = $pdo->prepare("INSERT INTO visitors (full_name, contact_number, email, purpose, person_to_meet, department, expected_arrival, expected_duration, vehicle_number, id_proof_type, status, notes, school_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'expected', ?, ?)");
    if ($stmt->execute([
        $_POST['full_name'],
        $_POST['contact_number'],
        $_POST['email'],
        $_POST['purpose'],
        $_POST['person_to_meet'],
        $_POST['department'],
        $_POST['expected_arrival'],
        $_POST['expected_duration'],
        $_POST['vehicle_number'],
        $_POST['id_proof_type'],
        $_POST['notes'],
        $current_school_id
    ])) {
        $message = "Visitor added successfully!";
        $message_type = "success";
    }
} elseif ($action === 'update') {
    $stmt = $pdo->prepare("UPDATE visitors SET
                          full_name = ?,
                          contact_number = ?,
                          email = ?,
                          purpose = ?,
                          person_to_meet = ?,
                          department = ?,
                          expected_arrival = ?,
                          expected_duration = ?,
                          vehicle_number = ?,
                          id_proof_type = ?,
                          status = ?,
                          notes = ?
                          WHERE id = ? AND school_id = ?");
    if ($stmt->execute([
        $_POST['full_name'],
        $_POST['contact_number'],
        $_POST['email'],
        $_POST['purpose'],
        $_POST['person_to_meet'],
        $_POST['department'],
        $_POST['expected_arrival'],
        $_POST['expected_duration'],
        $_POST['vehicle_number'],
        $_POST['id_proof_type'],
        $_POST['status'],
        $_POST['notes'],
        $_POST['id'],
        $current_school_id
    ])) {
        $message = "Visitor updated successfully!";
        $message_type = "success";
    }
} elseif ($action === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM visitors WHERE id = ? AND school_id = ?");
    if ($stmt->execute([$_POST['id'], $current_school_id])) {
        $message = "Visitor deleted successfully!";
        $message_type = "success";
    }
} elseif ($action === 'checkin') {
    $stmt = $pdo->prepare("UPDATE visitors SET status = 'checked_in', actual_arrival = NOW() WHERE id = ? AND school_id = ?");
    if ($stmt->execute([$_POST['id'], $current_school_id])) {
        $message = "Visitor checked in!";
        $message_type = "success";
    }
} elseif ($action === 'checkout') {
    $stmt = $pdo->prepare("UPDATE visitors SET status = 'checked_out', check_out = NOW() WHERE id = ? AND school_id = ?");
    if ($stmt->execute([$_POST['id'], $current_school_id])) {
        $message = "Visitor checked out!";
        $message_type = "success";
    }
}

// Fetch all visitors - filtered by school
// Pagination
$per_page = 10;
$page = max(1, intval($_GET['page'] ?? 1));

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM visitors WHERE school_id = ?");
$count_stmt->execute([$current_school_id]);
$total_rows = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT * FROM visitors WHERE school_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $current_school_id, PDO::PARAM_INT);
$stmt->bindValue(2, $per_page, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pagination_params = $_GET;
unset($pagination_params['page']);
$start_item = $total_rows > 0 ? ($offset + 1) : 0;
$end_item = $total_rows > 0 ? min($offset + count($visitors), $total_rows) : 0;
$prev_page = max(1, $page - 1);
$next_page = min($total_pages, $page + 1);

// Fetch visitor for editing - filtered by school
$edit_visitor = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM visitors WHERE id = ? AND school_id = ?");
    $stmt->execute([$_GET['edit'], $current_school_id]);
    $edit_visitor = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get statistics - filtered by school
$stats_stmt = $pdo->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status = 'checked_in' THEN 1 ELSE 0 END) as currently_in,
    SUM(CASE WHEN status = 'expected' THEN 1 ELSE 0 END) as expected_today,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_total
    FROM visitors WHERE school_id = ?");
$stats_stmt->execute([$current_school_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitors Management | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="stylesheet" href="../assets/css/visitors.css">
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
                        <a href="visitors.php" class="nav-link active">
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
                    <h2>👥 Visitors Management</h2>
                    <p>Manage visitor registrations, check-ins, and campus access</p>
                </div>
            </div>

        <!-- Statistics Cards -->
        <div class="stats-container" style="margin-bottom: 1.5rem;">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3>Total Visitors</h3>
                <div class="count"><?php echo $stats['total'] ?? 0; ?></div>
                <p class="stat-description">All records</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-door-open"></i>
                <h3>Currently On Campus</h3>
                <div class="count"><?php echo $stats['currently_in'] ?? 0; ?></div>
                <p class="stat-description">Checked in</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-calendar-check"></i>
                <h3>Expected Today</h3>
                <div class="count"><?php echo $stats['expected_today'] ?? 0; ?></div>
                <p class="stat-description">Upcoming</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-calendar-day"></i>
                <h3>Today's Total</h3>
                <div class="count"><?php echo $stats['today_total'] ?? 0; ?></div>
                <p class="stat-description">Today</p>
            </div>
        </div>

        <!-- Message Alert -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-check-circle"></i>
            <span><?php echo $message; ?></span>
            <button class="alert-close" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <div class="content-grid" style="display:flex; flex-direction:column; gap: 1.5rem;">
            <!-- Form Section -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-user-plus"></i> <?php echo $edit_visitor ? 'Edit Visitor' : 'Register New Visitor'; ?></h2>
                    <button type="button" class="btn small secondary" id="toggleVisitorForm">
                        <i class="fas fa-eye-slash"></i> Hide Form
                    </button>
                </div>
                <div class="panel-body" id="visitorFormBody">
                <form id="visitorForm" method="POST" class="filters-form">
                    <input type="hidden" name="action" value="<?php echo $edit_visitor ? 'update' : 'create'; ?>">
                    <?php if ($edit_visitor): ?>
                    <input type="hidden" name="id" value="<?php echo $edit_visitor['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="stats-grid">
                        <div class="form-group">
                            <label for="full_name"><i class="fas fa-user"></i> Full Name *</label>
                            <input type="text" id="full_name" name="full_name" 
                                   value="<?php echo $edit_visitor['full_name'] ?? ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_number"><i class="fas fa-phone"></i> Contact Number *</label>
                            <input type="tel" id="contact_number" name="contact_number" 
                                   value="<?php echo $edit_visitor['contact_number'] ?? ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo $edit_visitor['email'] ?? ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="purpose"><i class="fas fa-bullseye"></i> Purpose *</label>
                            <select id="purpose" name="purpose" required>
                                <option value="">Select Purpose</option>
                                <option value="parent_meeting" <?php echo ($edit_visitor['purpose'] ?? '') == 'parent_meeting' ? 'selected' : ''; ?>>Parent Meeting</option>
                                <option value="admission_inquiry" <?php echo ($edit_visitor['purpose'] ?? '') == 'admission_inquiry' ? 'selected' : ''; ?>>Admission Inquiry</option>
                                <option value="vendor" <?php echo ($edit_visitor['purpose'] ?? '') == 'vendor' ? 'selected' : ''; ?>>Vendor/Supplier</option>
                                <option value="interview" <?php echo ($edit_visitor['purpose'] ?? '') == 'interview' ? 'selected' : ''; ?>>Job Interview</option>
                                <option value="official_business" <?php echo ($edit_visitor['purpose'] ?? '') == 'official_business' ? 'selected' : ''; ?>>Official Business</option>
                                <option value="guest_lecture" <?php echo ($edit_visitor['purpose'] ?? '') == 'guest_lecture' ? 'selected' : ''; ?>>Guest Lecture</option>
                                <option value="other" <?php echo ($edit_visitor['purpose'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="person_to_meet"><i class="fas fa-handshake"></i> Person to Meet *</label>
                            <input type="text" id="person_to_meet" name="person_to_meet" 
                                   value="<?php echo $edit_visitor['person_to_meet'] ?? ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="department"><i class="fas fa-building"></i> Department</label>
                            <select id="department" name="department">
                                <option value="">Select Department</option>
                                <option value="principal_office" <?php echo ($edit_visitor['department'] ?? '') == 'principal_office' ? 'selected' : ''; ?>>Principal Office</option>
                                <option value="administration" <?php echo ($edit_visitor['department'] ?? '') == 'administration' ? 'selected' : ''; ?>>Administration</option>
                                <option value="accounts" <?php echo ($edit_visitor['department'] ?? '') == 'accounts' ? 'selected' : ''; ?>>Accounts</option>
                                <option value="academic" <?php echo ($edit_visitor['department'] ?? '') == 'academic' ? 'selected' : ''; ?>>Academic</option>
                                <option value="library" <?php echo ($edit_visitor['department'] ?? '') == 'library' ? 'selected' : ''; ?>>Library</option>
                                <option value="laboratory" <?php echo ($edit_visitor['department'] ?? '') == 'laboratory' ? 'selected' : ''; ?>>Laboratory</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="expected_arrival"><i class="fas fa-clock"></i> Expected Arrival *</label>
                            <input type="datetime-local" id="expected_arrival" name="expected_arrival" 
                                   value="<?php echo isset($edit_visitor['expected_arrival']) ? date('Y-m-d\TH:i', strtotime($edit_visitor['expected_arrival'])) : date('Y-m-d\TH:i'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="expected_duration"><i class="fas fa-hourglass-half"></i> Duration (hours)</label>
                            <input type="number" id="expected_duration" name="expected_duration" 
                                   value="<?php echo $edit_visitor['expected_duration'] ?? '1'; ?>" min="0.5" max="8" step="0.5">
                        </div>
                        
                        <div class="form-group">
                            <label for="vehicle_number"><i class="fas fa-car"></i> Vehicle Number</label>
                            <input type="text" id="vehicle_number" name="vehicle_number" 
                                   value="<?php echo $edit_visitor['vehicle_number'] ?? ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="id_proof_type"><i class="fas fa-id-card"></i> ID Proof Type</label>
                            <select id="id_proof_type" name="id_proof_type">
                                <option value="">Select ID Type</option>
                                <option value="aadhar" <?php echo ($edit_visitor['id_proof_type'] ?? '') == 'aadhar' ? 'selected' : ''; ?>>Aadhar Card</option>
                                <option value="pan" <?php echo ($edit_visitor['id_proof_type'] ?? '') == 'pan' ? 'selected' : ''; ?>>PAN Card</option>
                                <option value="driving" <?php echo ($edit_visitor['id_proof_type'] ?? '') == 'driving' ? 'selected' : ''; ?>>Driving License</option>
                                <option value="voter" <?php echo ($edit_visitor['id_proof_type'] ?? '') == 'voter' ? 'selected' : ''; ?>>Voter ID</option>
                                <option value="passport" <?php echo ($edit_visitor['id_proof_type'] ?? '') == 'passport' ? 'selected' : ''; ?>>Passport</option>
                            </select>
                        </div>
                        
                        <?php if ($edit_visitor): ?>
                        <div class="form-group">
                            <label for="status"><i class="fas fa-info-circle"></i> Status</label>
                            <select id="status" name="status">
                                <option value="expected" <?php echo ($edit_visitor['status'] ?? '') == 'expected' ? 'selected' : ''; ?>>Expected</option>
                                <option value="checked_in" <?php echo ($edit_visitor['status'] ?? '') == 'checked_in' ? 'selected' : ''; ?>>Checked In</option>
                                <option value="checked_out" <?php echo ($edit_visitor['status'] ?? '') == 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                                <option value="cancelled" <?php echo ($edit_visitor['status'] ?? '') == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="notes"><i class="fas fa-sticky-note"></i> Additional Notes</label>
                            <textarea id="notes" name="notes" rows="3"><?php echo $edit_visitor['notes'] ?? ''; ?></textarea>
                        </div>
                    </div>
                    
                    <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-top:1.5rem;">
                        <button type="submit" class="btn primary">
                            <i class="fas fa-save"></i> <?php echo $edit_visitor ? 'Update Visitor' : 'Register Visitor'; ?>
                        </button>
                        <?php if ($edit_visitor): ?>
                        <a href="visitors.php" class="btn secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
                </div>
            </section>

            <!-- Visitors List -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-list"></i> Recent Visitors</h2>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search visitors...">
                    </div>
                </div>
                <div class="panel-body">
                
                <div class="table-wrap">
                    <table class="table visitors-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Purpose</th>
                                <th>To Meet</th>
                                <th>Status</th>
                                <th>Arrival</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visitors as $visitor): ?>
                            <tr data-id="<?php echo $visitor['id']; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($visitor['full_name']); ?></strong>
                                    <div class="contact-info">
                                        <small><i class="fas fa-phone"></i> <?php echo $visitor['contact_number']; ?></small>
                                    </div>
                                </td>
                                <td>
                                    <span class="purpose-badge purpose-<?php echo $visitor['purpose']; ?>">
                                        <?php 
                                        $purpose_labels = [
                                            'parent_meeting' => 'Parent Meeting',
                                            'admission_inquiry' => 'Admission',
                                            'vendor' => 'Vendor',
                                            'interview' => 'Interview',
                                            'official_business' => 'Official',
                                            'guest_lecture' => 'Guest Lecture',
                                            'other' => 'Other'
                                        ];
                                        echo $purpose_labels[$visitor['purpose']] ?? $visitor['purpose'];
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($visitor['person_to_meet']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $visitor['status']; ?>">
                                        <?php 
                                        $status_labels = [
                                            'expected' => 'Expected',
                                            'checked_in' => 'On Campus',
                                            'checked_out' => 'Checked Out',
                                            'cancelled' => 'Cancelled'
                                        ];
                                        echo $status_labels[$visitor['status']] ?? $visitor['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    if ($visitor['actual_arrival']) {
                                        echo date('M j, g:i A', strtotime($visitor['actual_arrival']));
                                    } else {
                                        echo date('M j, g:i A', strtotime($visitor['expected_arrival']));
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit=<?php echo $visitor['id']; ?>" class="btn-action btn-edit" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <?php if ($visitor['status'] == 'expected'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="checkin">
                                            <input type="hidden" name="id" value="<?php echo $visitor['id']; ?>">
                                            <button type="submit" class="btn-action btn-checkin" title="Check In">
                                                <i class="fas fa-sign-in-alt"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($visitor['status'] == 'checked_in'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="checkout">
                                            <input type="hidden" name="id" value="<?php echo $visitor['id']; ?>">
                                            <button type="submit" class="btn-action btn-checkout" title="Check Out">
                                                <i class="fas fa-sign-out-alt"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete this visitor?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $visitor['id']; ?>">
                                            <button type="submit" class="btn-action btn-delete" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($visitors)): ?>
                            <tr>
                                <td colspan="6" class="no-data" style="text-align:center; color:#64748b;">
                                    <i class="fas fa-users-slash"></i>
                                    <p>No visitors found</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:1rem; margin-top:1.5rem;">
                        <div style="color:#64748b; font-weight:600;">
                            Showing <?php echo $start_item; ?>-<?php echo $end_item; ?> of <?php echo $total_rows; ?>
                        </div>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                            <a class="btn secondary" style="<?php echo $page <= 1 ? 'pointer-events:none; opacity:0.6;' : ''; ?>"
                               href="<?php echo 'visitors.php?' . http_build_query(array_merge($pagination_params, ['page' => 1])); ?>">
                                First
                            </a>
                            <a class="btn secondary" style="<?php echo $page <= 1 ? 'pointer-events:none; opacity:0.6;' : ''; ?>"
                               href="<?php echo 'visitors.php?' . http_build_query(array_merge($pagination_params, ['page' => $prev_page])); ?>">
                                Prev
                            </a>
                            <span class="btn primary" style="pointer-events:none;">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </span>
                            <a class="btn secondary" style="<?php echo $page >= $total_pages ? 'pointer-events:none; opacity:0.6;' : ''; ?>"
                               href="<?php echo 'visitors.php?' . http_build_query(array_merge($pagination_params, ['page' => $next_page])); ?>">
                                Next
                            </a>
                            <a class="btn secondary" style="<?php echo $page >= $total_pages ? 'pointer-events:none; opacity:0.6;' : ''; ?>"
                               href="<?php echo 'visitors.php?' . http_build_query(array_merge($pagination_params, ['page' => $total_pages])); ?>">
                                Last
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
                
                <!-- Export Options -->
                <!-- <div class="export-section">
                    <h3><i class="fas fa-download"></i> Export Data</h3>
                    <div class="export-buttons">
                        <a href="export_visitors.php?format=pdf" class="btn-export" target="_blank">
                            <i class="fas fa-file-pdf"></i> PDF Report
                        </a>
                        <a href="export_visitors.php?format=csv" class="btn-export">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </a>
                        <button class="btn-export" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                    </div>
                </div> -->
            </section>
        </div>
        </main>
    </div>

    

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');
        const toggleVisitorForm = document.getElementById('toggleVisitorForm');
        const visitorFormBody = document.getElementById('visitorFormBody');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        if (toggleVisitorForm && visitorFormBody) {
            const updateToggleLabel = () => {
                const isHidden = visitorFormBody.style.display === 'none';
                toggleVisitorForm.innerHTML = isHidden
                    ? '<i class="fas fa-eye"></i> Show Form'
                    : '<i class="fas fa-eye-slash"></i> Hide Form';
            };
            updateToggleLabel();
            toggleVisitorForm.addEventListener('click', () => {
                visitorFormBody.style.display = visitorFormBody.style.display === 'none' ? 'block' : 'none';
                updateToggleLabel();
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
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

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const rows = document.querySelectorAll('.visitors-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchValue) ? '' : 'none';
            });
        });

        // Status color coding
        document.addEventListener('DOMContentLoaded', function() {
            const statusBadges = document.querySelectorAll('.status-badge');
            statusBadges.forEach(badge => {
                const status = badge.className.match(/status-(\w+)/)[1];
                switch(status) {
                    case 'expected':
                        badge.style.backgroundColor = 'var(--transparent-gold)';
                        badge.style.color = 'var(--dark-gold)';
                        break;
                    case 'checked_in':
                        badge.style.backgroundColor = '#d4edda';
                        badge.style.color = '#155724';
                        break;
                    case 'checked_out':
                        badge.style.backgroundColor = '#cce5ff';
                        badge.style.color = '#004085';
                        break;
                    case 'cancelled':
                        badge.style.backgroundColor = '#f8d7da';
                        badge.style.color = '#721c24';
                        break;
                }
            });
        });

        // Auto-hide alert after 5 seconds
        setTimeout(() => {
            const alert = document.querySelector('.alert');
            if (alert) {
                alert.style.display = 'none';
            }
        }, 5000);
    </script>
    <?php include '../includes/floating-button.php'; ?>
</body>
</html>



