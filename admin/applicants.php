<?php
session_start();
require_once '../config/db.php';
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
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            background: linear-gradient(180deg, var(--primary-color) 0%, #1a2530 100%);
            color: white;
            min-height: 100vh;
            position: fixed;
            width: 260px;
            z-index: 1000;
        }
        
        .main-content {
            margin-left: 260px;
            padding: 20px;
        }
        
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .sidebar-text {
                display: none;
            }
        }
        
        .stat-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-under_review { background-color: #cce5ff; color: #004085; }
        .status-accepted { background-color: #d4edda; color: #155724; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        .status-waitlisted { background-color: #e2e3e5; color: #383d41; }
        .status-enrolled { background-color: #d1ecf1; color: #0c5460; }
        
        .action-dropdown .dropdown-menu {
            min-width: 200px;
        }
        
        .applicant-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -24px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--secondary-color);
        }
        
        .modal-xl-custom {
            max-width: 1200px;
        }
        
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .dataTables_wrapper {
            padding: 20px;
        }
        
        .action-btn-group .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column">
        <div class="p-3">
            <h4 class="text-center sidebar-text">Principal Dashboard</h4>
            <div class="text-center">
                <div class="applicant-avatar mx-auto">
                    <i class="fas fa-user-tie"></i>
                </div>
                <small class="sidebar-text"><?php echo $_SESSION['full_name']; ?></small>
            </div>
        </div>
        
        <nav class="flex-grow-1">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link text-white active" href="applicants.php">
                        <i class="fas fa-users me-2"></i>
                        <span class="sidebar-text">Applicants</span>
                        <span class="badge bg-danger float-end sidebar-text"><?php echo count($applicants); ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white" href="index.php">
                        <i class="fas fa-home me-2"></i>
                        <span class="sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white" href="students.php">
                        <i class="fas fa-user-graduate me-2"></i>
                        <span class="sidebar-text">Students</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white" href="export.php">
                        <i class="fas fa-file-export me-2"></i>
                        <span class="sidebar-text">Export Students</span>
                    </a>
                </li>
<!--                 
                <button class="btn btn-outline-secondary" onclick="exportToExcel()">
                    <i class="fas fa-file-export me-2"></i>Export
                </button> -->
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="h3 mb-0">
                    <i class="fas fa-users text-primary me-2"></i>Applicant Management
                </h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Applicants</li>
                    </ol>
                </nav>
            </div>
            <!-- <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addApplicantModal">
                    <i class="fas fa-plus me-2"></i>Add Applicant
                </button>
                <button class="btn btn-outline-secondary" onclick="exportToExcel()">
                    <i class="fas fa-file-export me-2"></i>Export
                </button>
            </div> -->
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <?php foreach ($stats as $stat): ?>
            <div class="col-md-2 col-sm-6 mb-3">
                <div class="stat-card h-100">
                    <div class="card-body text-center">
                        <h1 class="display-6 fw-bold mb-2"><?php echo $stat['count']; ?></h1>
                        <span class="status-badge status-<?php echo $stat['application_status']; ?> mb-2">
                            <?php echo ucfirst(str_replace('_', ' ', $stat['application_status'])); ?>
                        </span>
                        <div class="small text-muted"><?php echo $stat['percentage']; ?>%</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="col-md-2 col-sm-6 mb-3">
                <div class="stat-card h-100 bg-primary text-white">
                    <div class="card-body text-center">
                        <h1 class="display-6 fw-bold mb-2"><?php echo count($applicants); ?></h1>
                        <div class="mb-2">Total</div>
                        <div class="small">Applications</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                        <option value="accepted" <?php echo $status === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="waitlisted" <?php echo $status === 'waitlisted' ? 'selected' : ''; ?>>Waitlisted</option>
                        <option value="enrolled" <?php echo $status === 'enrolled' ? 'selected' : ''; ?>>Enrolled</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Class</label>
                    <select name="class_filter" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class; ?>" <?php echo (!empty($_GET['class_filter']) && $_GET['class_filter'] === $class) ? 'selected' : ''; ?>>
                            <?php echo $class; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $_GET['start_date'] ?? ''; ?>">
                </div> 
                
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $_GET['end_date'] ?? ''; ?>">
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search by name, email, or application number..." value="<?php echo $_GET['search'] ?? ''; ?>">
                </div>
                
                <div class="col-md-6 d-flex align-items-end">
                    <div class="d-grid gap-2 d-md-flex">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                        <a href="applicants.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo me-2"></i>Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <form method="POST" class="row align-items-center">
                            <div class="col-md-4 mb-2 mb-md-0">
                                <select name="bulk_status" class="form-select" required>
                                    <option value="">Bulk Action</option>
                                    <option value="under_review">Mark as Under Review</option>
                                    <option value="accepted">Mark as Accepted</option>
                                    <option value="rejected">Mark as Rejected</option>
                                    <option value="waitlisted">Mark as Waitlisted</option>
                                    <option value="enrolled">Mark as Enrolled</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-2 mb-md-0">
                                <input type="text" class="form-control" placeholder="Action notes..." name="bulk_notes">
                            </div>
                            <div class="col-md-4">
                                <div class="d-grid">
                                    <button type="submit" name="bulk_action" class="btn btn-warning">
                                        <i class="fas fa-cogs me-2"></i>Apply to Selected
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Applicants Table -->
        <div class="table-container">
            <table id="applicantsTable" class="table table-hover">
                <thead>
                    <tr>
                        <th width="30">
                            <input type="checkbox" id="selectAll">
                        </th>
                        <th>Application #</th>
                        <th>Name</th>
                        <th>Class</th>
                        <th>Contact</th>
                        <th>Guardian</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applicants as $applicant): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="applicant-checkbox" name="applicant_ids[]" value="<?php echo $applicant['id']; ?>">
                        </td>
                        <td>
                            <strong><?php echo $applicant['application_number']; ?></strong>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="applicant-avatar me-2">
                                    <?php echo strtoupper(substr($applicant['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="fw-bold"><?php echo $applicant['full_name']; ?></div>
                                    <small class="text-muted">DOB: <?php echo date('d/m/Y', strtotime($applicant['date_of_birth'])); ?></small>
                                </div>
                            </div>
                        </td>
                        <td><?php echo $applicant['class_applied']; ?></td>
                        <td>
                            <div><?php echo $applicant['email']; ?></div>
                            <small class="text-muted"><?php echo $applicant['phone']; ?></small>
                        </td>
                        <td>
                            <div><?php echo $applicant['guardian_name']; ?></div>
                            <small class="text-muted"><?php echo $applicant['guardian_phone']; ?></small>
                        </td>
                        <td>
                            <?php echo date('d/m/Y', strtotime($applicant['application_date'])); ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $applicant['application_status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $applicant['application_status'])); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-btn-group d-flex gap-1">
                                <button class="btn btn-sm btn-outline-primary view-applicant" 
                                        data-id="<?php echo $applicant['id']; ?>"
                                        data-bs-toggle="modal" 
                                        data-bs-target="#viewApplicantModal">
                                    <i class="fas fa-eye"></i>
                                </button>
                                
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
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
                                <button class="btn btn-sm btn-success" onclick="enrollApplicant(<?php echo $applicant['id']; ?>)">
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

        <!-- Chart Section -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Applications by Class</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="classChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Applications Timeline</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="timelineChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                <form method="POST" action="process-application.php?source=manual">
                    <div class="modal-body">
                        <!-- Form similar to application portal but for manual entry -->
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            This form allows you to manually enter applicant information. The applicant will receive an email with their application details.
                        </div>
                        <!-- Add form fields here -->
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
        $(document).ready(function() {
            // Initialize DataTable
            $('#applicantsTable').DataTable({
                pageLength: 25,
                responsive: true,
                order: [[6, 'desc']],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search..."
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
        
        // Refresh data every 30 seconds
        setInterval(function() {
            $('#applicantsTable').DataTable().ajax.reload(null, false);
        }, 30000);
    </script>
</body>
</html>