<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
// require_once 'includes/auth-check.php';

// Check if user is principal
if ($_SESSION['role'] !== 'principal') {
    header('Location: unauthorized.php');
    exit();
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle applicant status update
    if (isset($_POST['update_status'])) {
        $stmt = $pdo->prepare("UPDATE applicants SET application_status = ?, status_changed_by = ?, status_changed_at = NOW(), status_notes = ? WHERE id = ?");
        $stmt->execute([$_POST['status'], $_SESSION['user_id'], $_POST['notes'], $_POST['applicant_id']]);
        
        // Log the action
        // logAction($_SESSION['user_id'], 'applicant_status', "Updated applicant #{$_POST['applicant_id']} to {$_POST['status']}");
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action'])) {
        $applicantIds = $_POST['applicant_ids'] ?? [];
        if (!empty($applicantIds)) {
            $placeholders = str_repeat('?,', count($applicantIds) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE applicants SET application_status = ? WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$_POST['bulk_status']], $applicantIds));
        }
    }
}

// Fetch applicants with filters
$whereClauses = [];
$params = [];

// Status filter
$status = $_GET['status'] ?? '';
if ($status && $status !== 'all') {
    $whereClauses[] = "application_status = ?";
    $params[] = $status;
}

// Date range filter
if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $whereClauses[] = "application_date BETWEEN ? AND ?";
    $params[] = $_GET['start_date'];
    $params[] = $_GET['end_date'] . ' 23:59:59';
}

// Class filter
if (!empty($_GET['class_filter'])) {
    $whereClauses[] = "class_applied = ?";
    $params[] = $_GET['class_filter'];
}

// Search
if (!empty($_GET['search'])) {
    $searchTerm = "%{$_GET['search']}%";
    $whereClauses[] = "(full_name LIKE ? OR email LIKE ? OR application_number LIKE ? OR phone LIKE ?)";
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

// Build query
$whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
$sql = "SELECT * FROM applicants $whereSQL ORDER BY application_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$statsStmt = $pdo->query("
    SELECT 
        application_status,
        COUNT(*) as count,
        ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM applicants)), 1) as percentage
    FROM applicants 
    GROUP BY application_status
    ORDER BY FIELD(application_status, 'pending', 'under_review', 'accepted', 'rejected', 'waitlisted', 'enrolled')
");
$stats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

// Classes for filter
$classesStmt = $pdo->query("SELECT DISTINCT class_applied FROM applicants ORDER BY class_applied");
$classes = $classesStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Applicants - Principal Dashboard</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Bootstrap CSS (for modals and DataTables base styles) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #dbeafe;
            --accent-blue: #38bdf8;
        }

        .content-header {
            background: #ffffff;
            border: 1px solid rgba(37, 99, 235, 0.12);
            box-shadow: 0 12px 30px rgba(30, 58, 138, 0.08);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border: none;
        }

        .btn-secondary {
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            color: #1e3a8a;
        }

        .students-table thead th {
            background: #f1f5ff;
            color: #1e3a8a;
        }

        .badge {
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge-pending { background: #e0f2fe; color: #075985; }
        .badge-under_review { background: #fff7ed; color: #9a3412; }
        .badge-accepted { background: #ecfdf5; color: #065f46; }
        .badge-rejected { background: #fef2f2; color: #991b1b; }
        .badge-waitlisted { background: #eef2ff; color: #3730a3; }
        .badge-enrolled { background: #eff6ff; color: #1d4ed8; }

        .card {
            border: 1px solid rgba(37, 99, 235, 0.08);
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.08);
        }

        .chart-card {
            border: 1px solid rgba(37, 99, 235, 0.1);
            box-shadow: 0 12px 26px rgba(30, 58, 138, 0.08);
        }

        .modal-content {
            border: 1px solid rgba(37, 99, 235, 0.12);
        }

        .dataTables_filter input {
            border-radius: 8px;
            border: 1px solid #cbd5f5;
        }

        .dropdown-menu {
            border: 1px solid rgba(37, 99, 235, 0.12);
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
                    <span class="principal-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <?php include '../includes/admin_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>📄 Applicant Management</h2>
                    <p>Manage and review school applications efficiently</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo count($applicants); ?></span>
                        <span class="quick-stat-label">Total Applications</span>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="dashboard-cards">
                <?php foreach ($stats as $stat): ?>
                <div class="card card-gradient-<?php
                    $status = $stat['application_status'];
                    echo match($status) {
                        'pending' => '5',
                        'under_review' => '3',
                        'accepted' => '4',
                        'rejected' => '2',
                        'waitlisted' => '6',
                        'enrolled' => '1',
                        default => '1'
                    };
                ?>">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">
                            <?php
                            echo match($status) {
                                'pending' => '⏳',
                                'under_review' => '🔍',
                                'accepted' => '✅',
                                'rejected' => '❌',
                                'waitlisted' => '📋',
                                'enrolled' => '🎓',
                                default => '📄'
                            };
                            ?>
                        </div>
                    </div>
                    <div class="card-content">
                        <h3><?php echo ucfirst(str_replace('_', ' ', $stat['application_status'])); ?></h3>
                        <p class="card-value"><?php echo $stat['count']; ?></p>
                        <div class="card-footer">
                            <span class="card-badge"><?php echo $stat['percentage']; ?>%</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📊</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Applications</h3>
                        <p class="card-value"><?php echo count($applicants); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">All Time</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="section-container">
                <div class="section-header">
                    <h3>🔍 Filter Applications</h3>
                    <span class="section-badge">Search & Filter</span>
                </div>
                <form method="GET" class="filters-form">
                    <div class="stats-grid">
                        <div class="form-group">
                            <label class="form-label">Application Status</label>
                            <select name="status" class="form-control">
                                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                <option value="accepted" <?php echo $status === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="waitlisted" <?php echo $status === 'waitlisted' ? 'selected' : ''; ?>>Waitlisted</option>
                                <option value="enrolled" <?php echo $status === 'enrolled' ? 'selected' : ''; ?>>Enrolled</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Class Applied</label>
                            <select name="class_filter" class="form-control">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class; ?>" <?php echo (!empty($_GET['class_filter']) && $_GET['class_filter'] === $class) ? 'selected' : ''; ?>>
                                    <?php echo $class; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">From Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo $_GET['start_date'] ?? ''; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">To Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $_GET['end_date'] ?? ''; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Search Applications</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-search"></i></span>
                                <input type="text" name="search" class="form-control" placeholder="Name, email, or application number..." value="<?php echo $_GET['search'] ?? ''; ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">&nbsp;</label>
                            <div style="display: flex; gap: 1rem; align-items: flex-end;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-2"></i>Apply Filters
                                </button>
                                <a href="applicants.php" class="btn btn-secondary">
                                    <i class="fas fa-redo me-2"></i>Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions Section -->
            <div class="section-container">
                <div class="section-header">
                    <h3>⚡ Bulk Actions</h3>
                    <span class="section-badge">Batch Operations</span>
                </div>
                <form method="POST" class="filters-form">
                    <div class="stats-grid">
                        <div class="form-group">
                            <label class="form-label">Bulk Status Change</label>
                            <select name="bulk_status" class="form-control" required>
                                <option value="">Select Action</option>
                                <option value="under_review">Mark as Under Review</option>
                                <option value="accepted">Mark as Accepted</option>
                                <option value="rejected">Mark as Rejected</option>
                                <option value="waitlisted">Mark as Waitlisted</option>
                                <option value="enrolled">Mark as Enrolled</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Action Notes</label>
                            <input type="text" class="form-control" placeholder="Add notes for this batch action..." name="bulk_notes">
                        </div>

                        <div class="form-group">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" name="bulk_action" class="btn btn-warning">
                                <i class="fas fa-cogs me-2"></i>Apply to Selected
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Applicants Table Section -->
            <div class="section-container">
                <div class="section-header">
                    <h3>📋 Applications List</h3>
                    <span class="section-badge"><?php echo count($applicants); ?> Records</span>
                </div>
                <div class="table-responsive">
                    <table id="applicantsTable" class="students-table">
                        <thead>
                            <tr>
                                <th width="40">
                                    <input type="checkbox" id="selectAll" style="margin: 0;">
                                </th>
                                <th>Application #</th>
                                <th>Applicant</th>
                                <th>Class</th>
                                <th>Contact</th>
                                <th>Guardian</th>
                                <th>Application Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($applicants as $applicant): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="applicant-checkbox" name="applicant_ids[]" value="<?php echo $applicant['id']; ?>" style="margin: 0;">
                                </td>
                                <td>
                                    <strong><?php echo $applicant['application_number']; ?></strong>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <div style="width: 35px; height: 35px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.9rem;">
                                            <?php echo strtoupper(substr($applicant['full_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; color: var(--gray-900);"><?php echo $applicant['full_name']; ?></div>
                                            <div style="font-size: 0.8rem; color: var(--gray-500);">DOB: <?php echo date('d/m/Y', strtotime($applicant['date_of_birth'])); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo $applicant['class_applied']; ?></td>
                                <td>
                                    <div style="font-weight: 500; color: var(--gray-900);"><?php echo $applicant['email']; ?></div>
                                    <div style="font-size: 0.8rem; color: var(--gray-500);"><?php echo $applicant['phone']; ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 500; color: var(--gray-900);"><?php echo $applicant['guardian_name']; ?></div>
                                    <div style="font-size: 0.8rem; color: var(--gray-500);"><?php echo $applicant['guardian_phone']; ?></div>
                                </td>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($applicant['application_date'])); ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $applicant['application_status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $applicant['application_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-small btn-primary view-applicant"
                                                data-id="<?php echo $applicant['id']; ?>"
                                                data-bs-toggle="modal"
                                                data-bs-target="#viewApplicantModal">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <div class="dropdown">
                                            <button class="btn btn-small btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-cog"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#statusModal"
                                                       data-applicant-id="<?php echo $applicant['id']; ?>"
                                                       data-current-status="<?php echo $applicant['application_status']; ?>">
                                                        <i class="fas fa-edit me-2"></i>Change Status
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="mailto:<?php echo $applicant['email']; ?>">
                                                        <i class="fas fa-envelope me-2"></i>Send Email
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="tel:<?php echo $applicant['phone']; ?>">
                                                        <i class="fas fa-phone me-2"></i>Call
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?php echo $applicant['id']; ?>)">
                                                        <i class="fas fa-trash me-2"></i>Delete
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>

                                        <?php if ($applicant['application_status'] === 'accepted'): ?>
                                        <button class="btn btn-small btn-success" onclick="enrollApplicant(<?php echo $applicant['id']; ?>)">
                                            <i class="fas fa-user-plus"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Analytics Section -->
            <div class="charts-section">
                <div class="section-header">
                    <h3>📊 Application Analytics</h3>
                    <span class="section-badge">Visual Insights</span>
                </div>
                <div class="charts-grid">
                    <div class="chart-card">
                        <h4>📈 Applications by Class</h4>
                        <canvas id="classChart" width="300" height="200"></canvas>
                    </div>
                    <div class="chart-card">
                        <h4>📅 Application Timeline</h4>
                        <canvas id="timelineChart" width="300" height="200"></canvas>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
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

    <!-- View Applicant Modal -->
    <div class="modal fade modal-xl-custom" id="viewApplicantModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Applicant Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="applicantDetails">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- Status Change Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Change Applicant Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="applicant_id" id="statusApplicantId">
                        
                        <div class="mb-3">
                            <label class="form-label">Current Status</label>
                            <div id="currentStatusDisplay" class="fw-bold"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">New Status</label>
                            <select name="status" class="form-select" required>
                                <option value="">Select Status</option>
                                <option value="pending">Pending</option>
                                <option value="under_review">Under Review</option>
                                <option value="accepted">Accepted</option>
                                <option value="rejected">Rejected</option>
                                <option value="waitlisted">Waitlisted</option>
                                <option value="enrolled">Enrolled</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes/Comments</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Add notes about this status change..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notify Applicant</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="notify_applicant" value="1" checked>
                                <label class="form-check-label">
                                    Send email notification about status change
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Applicant Modal -->
    <div class="modal fade modal-xl-custom" id="addApplicantModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Applicant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="process-application.php?source=manual" enctype="multipart/form-data">
                    <div class="modal-body">
                        <!-- Form similar to application portal but for manual entry -->
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            This form allows you to manually enter applicant information. The applicant will receive an email with their application details.
                        </div>
                        <input type="hidden" name="submission_source" value="manual">
                        <input type="hidden" name="terms_accepted" value="1">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name</label>
                                <input type="text" class="form-control" name="full_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Gender</label>
                                <select class="form-select" name="gender" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Class Applying For</label>
                                <select class="form-select" name="class_applied" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" name="address" rows="2" required></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Previous School</label>
                                <input type="text" class="form-control" name="previous_school">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Class Completed</label>
                                <input type="text" class="form-control" name="last_class_completed">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Academic Qualifications</label>
                                <textarea class="form-control" name="academic_qualifications" rows="2"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Extracurricular Activities</label>
                                <textarea class="form-control" name="extracurricular_activities" rows="2"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reason for Application</label>
                                <textarea class="form-control" name="reason_for_application" rows="2" required></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Guardian Name</label>
                                <input type="text" class="form-control" name="guardian_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Relationship</label>
                                <select class="form-select" name="guardian_relationship" required>
                                    <option value="">Select Relationship</option>
                                    <option value="Father">Father</option>
                                    <option value="Mother">Mother</option>
                                    <option value="Brother">Brother</option>
                                    <option value="Sister">Sister</option>
                                    <option value="Uncle">Uncle</option>
                                    <option value="Aunt">Aunt</option>
                                    <option value="Grandfather">Grandfather</option>
                                    <option value="Grandmother">Grandmother</option>
                                    <option value="Guardian">Guardian</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Guardian Phone</label>
                                <input type="tel" class="form-control" name="guardian_phone" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Guardian Email</label>
                                <input type="email" class="form-control" name="guardian_email">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Guardian Occupation</label>
                                <input type="text" class="form-control" name="guardian_occupation">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">How did you hear about us?</label>
                                <select class="form-select" name="how_heard_about_us">
                                    <option value="">Select Option</option>
                                    <option value="Friend/Family">Friend/Family</option>
                                    <option value="Social Media">Social Media</option>
                                    <option value="Website">Website</option>
                                    <option value="Newspaper">Newspaper</option>
                                    <option value="Radio/TV">Radio/TV</option>
                                    <option value="School Fair">School Fair</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Birth Certificate</label>
                                <input type="file" class="form-control" name="birth_certificate" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Passport Photo</label>
                                <input type="file" class="form-control" name="passport_photo" accept=".jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Previous School Certificate</label>
                                <input type="file" class="form-control" name="school_certificate" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Other Documents</label>
                                <input type="file" class="form-control" name="other_documents[]" accept=".jpg,.jpeg,.png,.pdf" multiple>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Applicant</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>
    
    <script>
        let applicantsTable;

        $(document).ready(function() {
            // Initialize DataTable for client-side processing
            applicantsTable = $('#applicantsTable').DataTable({
                pageLength: 25,
                responsive: true,
                order: [[6, 'desc']],
                processing: false,
                serverSide: false,
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search..."
                },
                columnDefs: [
                    { orderable: false, targets: [0, 8] }, // Disable sorting on checkbox and actions columns
                    { searchable: false, targets: [0, 8] }  // Disable searching on checkbox and actions columns
                ],
                initComplete: function() {
                    // Add search input styling
                    $('.dataTables_filter input').addClass('form-control');
                }
            });
            
            // Select all checkboxes
            $('#selectAll').click(function() {
                $('.applicant-checkbox').prop('checked', this.checked);
            });
            
            // Status modal
            $('#statusModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);
                var applicantId = button.data('applicant-id');
                var currentStatus = button.data('current-status');
                
                var modal = $(this);
                modal.find('#statusApplicantId').val(applicantId);
                modal.find('#currentStatusDisplay').html(
                    '<span class="status-badge status-' + currentStatus + '">' + 
                    currentStatus.replace('_', ' ') + '</span>'
                );
            });
            
            // View applicant details
            $('.view-applicant').click(function() {
                var applicantId = $(this).data('id');
                
                $.ajax({
                    url: 'get-applicant-details.php',
                    type: 'GET',
                    data: { id: applicantId },
                    success: function(response) {
                        $('#applicantDetails').html(response);
                    }
                });
            });
            
            // Initialize charts
            initializeCharts();
        });
        
        function initializeCharts() {
            // Class distribution chart
            var classCtx = document.getElementById('classChart').getContext('2d');
            var classChart = new Chart(classCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($classes); ?>,
                    datasets: [{
                        label: 'Applications',
                        data: [/* Data will be populated via AJAX */],
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
            
            // Timeline chart
            var timelineCtx = document.getElementById('timelineChart').getContext('2d');
            var timelineChart = new Chart(timelineCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'Applications',
                        data: [/* Monthly data */],
                        borderColor: 'rgb(75, 192, 192)',
                        tension: 0.1,
                        fill: true,
                        backgroundColor: 'rgba(75, 192, 192, 0.1)'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
        
        function confirmDelete(applicantId) {
            if (confirm('Are you sure you want to delete this applicant? This action cannot be undone.')) {
                window.location.href = 'delete-applicant.php?id=' + applicantId;
            }
        }
        
        function enrollApplicant(applicantId) {
            if (confirm('Enroll this applicant as a student? This will create a student record.')) {
                window.location.href = 'enroll-applicant.php?id=' + applicantId;
            }
        }
        
        function exportToExcel() {
            // Implementation for Excel export
            window.location.href = 'export-applicants.php?' + window.location.search.substring(1);
        }
        
        // Refresh data every 30 seconds if ajax is configured
        setInterval(function() {
            if (applicantsTable && applicantsTable.ajax && applicantsTable.ajax.url()) {
                applicantsTable.ajax.reload(null, false);
            }
        }, 30000);
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
