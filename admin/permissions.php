<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/permissions_helpers.php';

// Check if user is logged in and is a principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: ../index.php');
    exit();
}

$current_school_id = require_school_auth();
ensure_permissions_schema($pdo);

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

$principal_name = $_SESSION['full_name'];

// Handle approval/rejection/cancellation/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $permission_id = (int)($_POST['permission_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));
    $rejection_reason = trim((string)($_POST['rejection_reason'] ?? ''));

    if ($permission_id <= 0) {
        $error = "Invalid permission request selected.";
    } else {
        try {
            $targetStmt = $pdo->prepare("SELECT * FROM permissions WHERE id = ? AND school_id = ? LIMIT 1");
            $targetStmt->execute([$permission_id, $current_school_id]);
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                $error = "Permission request not found for your school.";
            } elseif ($action === 'approve') {
                if ($target['status'] !== 'pending') {
                    $error = "Only pending requests can be approved.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE permissions
                        SET status = 'approved',
                            rejection_reason = NULL,
                            approved_by = ?,
                            approved_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([(string)$user_id, $permission_id, $current_school_id]);
                    $message = "Request approved successfully!";
                }
            } elseif ($action === 'reject') {
                if ($target['status'] !== 'pending') {
                    $error = "Only pending requests can be rejected.";
                } elseif ($rejection_reason === '') {
                    $error = "Please provide a reason for rejection.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE permissions
                        SET status = 'rejected',
                            rejection_reason = ?,
                            approved_by = ?,
                            approved_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([$rejection_reason, (string)$user_id, $permission_id, $current_school_id]);
                    $message = "Request rejected successfully!";
                }
            } elseif ($action === 'cancel') {
                if ($target['status'] !== 'approved') {
                    $error = "Only approved requests can be cancelled.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE permissions
                        SET status = 'cancelled',
                            approved_by = ?,
                            approved_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([(string)$user_id, $permission_id, $current_school_id]);
                    $message = "Request cancelled successfully!";
                }
            } elseif ($action === 'edit') {
                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $request_type = trim((string)($_POST['request_type'] ?? ''));
                $start_date = to_mysql_datetime_or_null($_POST['start_date'] ?? '');
                $end_date = to_mysql_datetime_or_null($_POST['end_date'] ?? '');
                $priority = trim((string)($_POST['priority'] ?? 'medium'));

                if ($title === '' || $request_type === '' || $start_date === null) {
                    $error = "Please fill in all required fields with valid values.";
                } else {
                    $allowedPriorities = ['low', 'medium', 'high', 'urgent'];
                    if (!in_array($priority, $allowedPriorities, true)) {
                        $priority = 'medium';
                    }

                    $stmt = $pdo->prepare("
                        UPDATE permissions
                        SET title = ?,
                            description = ?,
                            request_type = ?,
                            start_date = ?,
                            end_date = ?,
                            priority = ?,
                            updated_at = NOW()
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([
                        $title,
                        $description !== '' ? $description : null,
                        $request_type,
                        $start_date,
                        $end_date,
                        $priority,
                        $permission_id,
                        $current_school_id
                    ]);
                    $message = "Request updated successfully!";
                }
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM permissions WHERE id = ? AND school_id = ?");
                $stmt->execute([$permission_id, $current_school_id]);
                $message = "Request deleted successfully!";
            } else {
                $error = "Unsupported action.";
            }
        } catch (PDOException $e) {
            $error = "Error processing request: " . $e->getMessage();
        }
    }
}

// Fetch all permission requests with filters
$filter_status = $_GET['status'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$filter_date = $_GET['date'] ?? '';

$sql = "
    SELECT p.*, u.full_name as staff_name, 
           u.designation as staff_designation,
           ap.full_name as approved_by_name
    FROM permissions p
    JOIN users u ON p.staff_id = u.id
    LEFT JOIN users ap ON p.approved_by = ap.id
    WHERE p.school_id = ?
";

$params = [$current_school_id];

if ($filter_status !== 'all') {
    $sql .= " AND p.status = ?";
    $params[] = $filter_status;
}

if ($filter_type !== 'all') {
    $sql .= " AND p.request_type = ?";
    $params[] = $filter_type;
}

if ($filter_date) {
    $sql .= " AND DATE(p.start_date) = ?";
    $params[] = $filter_date;
}

$sql .= " ORDER BY 
    CASE p.priority 
        WHEN 'urgent' THEN 1
        WHEN 'high' THEN 2
        WHEN 'medium' THEN 3
        WHEN 'low' THEN 4
    END,
    p.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching requests: " . $e->getMessage();
    $requests = [];
}

// Statistics
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN priority = 'urgent' AND status = 'pending' THEN 1 ELSE 0 END) as urgent_pending
        FROM permissions
        WHERE school_id = ? AND status != 'cancelled'
    ");
    $stats_stmt->execute([$current_school_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'urgent_pending' => 0];
}

$request_types = [];
try {
    $typeStmt = $pdo->prepare("
        SELECT DISTINCT request_type
        FROM permissions
        WHERE school_id = ? AND request_type IS NOT NULL AND request_type != ''
        ORDER BY request_type ASC
    ");
    $typeStmt->execute([$current_school_id]);
    $request_types = $typeStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $request_types = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Permissions - Sahab Form Master</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        <?php include '../includes/admin_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Manage Permissions 👥</h2>
                    <p>Review and manage staff permission requests</p>
                </div>
            </div>
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-section">
            <div class="section-header">
                <h3>📊 Permission Statistics</h3>
                <span class="section-badge">Overview</span>
            </div>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $stats['total']; ?></span>
                        <span class="stat-label">Total Requests</span>
                    </div>
                    <div class="stat-progress">
                        <div class="progress-bar" style="width: <?php echo $stats['total'] > 0 ? min(100, ($stats['total'] / 50) * 100) : 0; ?>%;"></div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $stats['pending']; ?></span>
                        <span class="stat-label">Pending</span>
                    </div>
                    <div class="stat-progress">
                        <div class="progress-bar progress-warning" style="width: <?php echo $stats['total'] > 0 ? ($stats['pending'] / $stats['total']) * 100 : 0; ?>%;"></div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $stats['approved']; ?></span>
                        <span class="stat-label">Approved</span>
                    </div>
                    <div class="stat-progress">
                        <div class="progress-bar" style="width: <?php echo $stats['total'] > 0 ? ($stats['approved'] / $stats['total']) * 100 : 0; ?>%;"></div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $stats['rejected']; ?></span>
                        <span class="stat-label">Rejected</span>
                    </div>
                    <div class="stat-progress">
                        <div class="progress-bar progress-warning" style="width: <?php echo $stats['total'] > 0 ? ($stats['rejected'] / $stats['total']) * 100 : 0; ?>%;"></div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $stats['urgent_pending']; ?></span>
                        <span class="stat-label">Urgent Pending</span>
                    </div>
                    <div class="stat-progress">
                        <div class="progress-bar progress-warning" style="width: <?php echo $stats['pending'] > 0 ? ($stats['urgent_pending'] / $stats['pending']) * 100 : 0; ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="stats-section">
            <div class="section-header">
                <h3>🔍 Filter Requests</h3>
                <span class="section-badge">Search</span>
            </div>
            <form method="GET">
                <div class="stats-grid">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-control" name="status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Request Type</label>
                        <select class="form-control" name="type">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <?php foreach ($request_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_type === $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" name="date" value="<?php echo $filter_date; ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Requests Table -->
        <div class="table-responsive">
            <table class="students-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Staff</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Date & Time</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4">
                                <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                                <p class="text-muted">No permission requests found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td>#<?php echo str_pad($request['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($request['staff_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo $request['staff_designation']; ?></small>
                                </td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?></td>
                                <td><?php echo htmlspecialchars($request['title']); ?></td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($request['start_date'])); ?>
                                    <br><small><?php echo date('h:i A', strtotime($request['start_date'])); ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $request['priority'] === 'urgent' ? 'danger' : ($request['priority'] === 'high' ? 'warning' : ($request['priority'] === 'medium' ? 'info' : 'secondary')); ?>">
                                        <?php echo ucfirst($request['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $request['status'] === 'approved' ? 'success' : ($request['status'] === 'rejected' ? 'danger' : ($request['status'] === 'pending' ? 'warning' : 'secondary')); ?>">
                                        <?php echo ucfirst($request['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d', strtotime($request['created_at'])); ?>
                                    <br><small><?php echo date('h:i A', strtotime($request['created_at'])); ?></small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-small btn-primary view-details me-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#detailsModal"
                                                data-request='<?php echo htmlspecialchars(json_encode($request), ENT_QUOTES, 'UTF-8'); ?>'>
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <button class="btn btn-small btn-success edit-btn me-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editModal"
                                                data-request='<?php echo htmlspecialchars(json_encode($request), ENT_QUOTES, 'UTF-8'); ?>'>
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <button class="btn btn-small btn-danger delete-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteModal"
                                                data-request='<?php echo htmlspecialchars(json_encode($request), ENT_QUOTES, 'UTF-8'); ?>'>
                                            <i class="fas fa-trash"></i>
                                        </button>

                                        <?php if ($request['status'] === 'pending'): ?>
                                            <div class="action-buttons mt-2">
                                                <button class="btn btn-small btn-success approve-btn d-block mb-1"
                                                        data-id="<?php echo $request['id']; ?>">
                                                    <i class="fas fa-check me-1"></i>Approve
                                                </button>

                                                <button class="btn btn-small btn-danger reject-btn d-block"
                                                        data-id="<?php echo $request['id']; ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#rejectModal">
                                                    <i class="fas fa-times me-1"></i>Reject
                                                </button>
                                            </div>
                                        <?php elseif ($request['status'] === 'approved'): ?>
                                            <div class="action-buttons mt-2">
                                                <button class="btn btn-small btn-warning cancel-btn d-block"
                                                        data-id="<?php echo $request['id']; ?>">
                                                    <i class="fas fa-ban me-1"></i>Cancel
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </main>
    </div>

    

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Permission Request Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="detailsContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Permission Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" id="rejectPermissionId" name="permission_id">
                        <input type="hidden" name="action" value="reject">
                        
                        <div class="mb-3">
                            <label class="form-label">Reason for Rejection *</label>
                            <textarea class="form-control" name="rejection_reason" 
                                      rows="4" placeholder="Please provide a reason for rejection..." 
                                      required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Approval Form (hidden) -->
    <form id="approveForm" method="POST" style="display: none;">
        <input type="hidden" id="approvePermissionId" name="permission_id">
        <input type="hidden" name="action" value="approve">
    </form>

    <!-- Cancel Form (hidden) -->
    <form id="cancelForm" method="POST" style="display: none;">
        <input type="hidden" id="cancelPermissionId" name="permission_id">
        <input type="hidden" name="action" value="cancel">
    </form>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Permission Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" id="editPermissionId" name="permission_id">
                        <input type="hidden" name="action" value="edit">

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Title *</label>
                                    <input type="text" class="form-control" id="editTitle" name="title" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Request Type *</label>
                            <select class="form-control" id="editRequestType" name="request_type" required>
                                        <?php if (!empty($request_types)): ?>
                                            <?php foreach ($request_types as $type): ?>
                                                <option value="<?php echo htmlspecialchars($type); ?>">
                                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type))); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="leave">Leave</option>
                                            <option value="training">Training</option>
                                            <option value="other">Other</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Start Date & Time *</label>
                                    <input type="datetime-local" class="form-control" id="editStartDate" name="start_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">End Date & Time</label>
                                    <input type="datetime-local" class="form-control" id="editEndDate" name="end_date">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Priority</label>
                            <select class="form-select" id="editPriority" name="priority">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="editDescription" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Delete Permission Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" id="deletePermissionId" name="permission_id">
                        <input type="hidden" name="action" value="delete">

                        <div class="text-center mb-4">
                            <div class="mb-3" style="font-size: 4rem; color: #dc3545;">
                                <i class="fas fa-trash-alt"></i>
                            </div>
                            <h5>Are you sure?</h5>
                            <p class="mb-0">You are about to delete this permission request:</p>
                            <h6 class="mt-2 text-danger" id="deleteRequestTitle"></h6>
                            <p class="text-muted mt-3"><i class="fas fa-info-circle me-1"></i> This action cannot be undone.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toDateTimeLocalValue(rawValue) {
            if (!rawValue) return '';
            const normalized = String(rawValue).trim().replace(' ', 'T');
            if (normalized.length >= 16) {
                return normalized.slice(0, 16);
            }
            if (normalized.length === 10) {
                return normalized + 'T00:00';
            }
            return '';
        }

        function ensureRequestTypeOption(selectElement, requestType) {
            if (!selectElement || !requestType) return;
            const exists = Array.from(selectElement.options).some(opt => opt.value === requestType);
            if (!exists) {
                const dynamicOption = document.createElement('option');
                dynamicOption.value = requestType;
                dynamicOption.textContent = requestType.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                selectElement.appendChild(dynamicOption);
            }
        }

        function resolveAttachmentPath(path) {
            const raw = String(path || '').trim();
            if (!raw) return '';
            if (raw.startsWith('http://') || raw.startsWith('https://') || raw.startsWith('/') || raw.startsWith('../')) {
                return raw;
            }
            return `../teacher/${raw.replace(/^\.?\//, '')}`;
        }

        // View details
        document.querySelectorAll('.view-details').forEach(button => {
            button.addEventListener('click', function() {
                const request = JSON.parse(this.getAttribute('data-request'));
                const attachmentPath = resolveAttachmentPath(request.attachment_path);
                const content = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Request Information</h6>
                            <p><strong>Staff:</strong> ${request.staff_name}</p>
                            <p><strong>Designation:</strong> ${request.staff_designation || 'N/A'}</p>
                            <p><strong>Type:</strong> ${(request.request_type || '').replace(/_/g, ' ')}</p>
                            <p><strong>Priority:</strong> <span class="priority-badge priority-${request.priority}">${request.priority}</span></p>
                            <p><strong>Status:</strong> <span class="status-badge status-${request.status}">${request.status}</span></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Timing Details</h6>
                            <p><strong>Start:</strong> ${new Date(request.start_date).toLocaleString()}</p>
                            ${request.end_date ? `<p><strong>End:</strong> ${new Date(request.end_date).toLocaleString()}</p>` : ''}
                            ${request.duration_hours ? `<p><strong>Duration:</strong> ${request.duration_hours} hours</p>` : ''}
                            <p><strong>Submitted:</strong> ${new Date(request.created_at).toLocaleString()}</p>
                            ${request.approved_by_name ? `<p><strong>Approved By:</strong> ${request.approved_by_name}</p>` : ''}
                            ${request.approved_at ? `<p><strong>Approved At:</strong> ${new Date(request.approved_at).toLocaleString()}</p>` : ''}
                        </div>
                    </div>

                    <hr>

                    <div class="row mt-3">
                        <div class="col-12">
                            <h6>Description</h6>
                            <div class="border rounded p-3 bg-light">
                                ${request.description || 'No description provided.'}
                            </div>
                        </div>
                    </div>

                    ${request.rejection_reason ? `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6>Rejection Reason</h6>
                            <div class="border rounded p-3 bg-light">
                                ${request.rejection_reason}
                            </div>
                        </div>
                    </div>` : ''}

                    ${attachmentPath ? `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6>Attachment</h6>
                            <a href="${attachmentPath}" target="_blank" class="btn btn-outline-primary">
                                <i class="fas fa-file me-1"></i>View Attachment
                            </a>
                        </div>
                    </div>` : ''}
                `;
                document.getElementById('detailsContent').innerHTML = content;
            });
        });

        // Approve request
        document.querySelectorAll('.approve-btn').forEach(button => {
            button.addEventListener('click', function() {
                if (confirm('Are you sure you want to approve this request?')) {
                    const permissionId = this.getAttribute('data-id');
                    document.getElementById('approvePermissionId').value = permissionId;
                    document.getElementById('approveForm').submit();
                }
            });
        });

        // Reject request
        document.querySelectorAll('.reject-btn').forEach(button => {
            button.addEventListener('click', function() {
                const permissionId = this.getAttribute('data-id');
                document.getElementById('rejectPermissionId').value = permissionId;
            });
        });

        // Cancel request
        document.querySelectorAll('.cancel-btn').forEach(button => {
            button.addEventListener('click', function() {
                if (confirm('Are you sure you want to cancel this approved request?')) {
                    const permissionId = this.getAttribute('data-id');
                    document.getElementById('cancelPermissionId').value = permissionId;
                    document.getElementById('cancelForm').submit();
                }
            });
        });

        // Edit request
        document.querySelectorAll('.edit-btn').forEach(button => {
            button.addEventListener('click', function() {
                const request = JSON.parse(this.getAttribute('data-request'));
                document.getElementById('editPermissionId').value = request.id;
                document.getElementById('editTitle').value = request.title;
                ensureRequestTypeOption(document.getElementById('editRequestType'), request.request_type);
                document.getElementById('editRequestType').value = request.request_type;
                document.getElementById('editStartDate').value = toDateTimeLocalValue(request.start_date);
                document.getElementById('editEndDate').value = toDateTimeLocalValue(request.end_date);
                document.getElementById('editPriority').value = request.priority;
                document.getElementById('editDescription').value = request.description || '';
            });
        });

        // Delete request
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.addEventListener('click', function() {
                const request = JSON.parse(this.getAttribute('data-request'));
                document.getElementById('deletePermissionId').value = request.id;
                document.getElementById('deleteRequestTitle').textContent = request.title;
            });
        });

        // Auto-refresh for urgent requests
        setInterval(() => {
            const urgentBadges = document.querySelectorAll('.priority-urgent');
            urgentBadges.forEach(badge => {
                badge.style.animation = 'none';
                setTimeout(() => {
                    badge.style.animation = 'pulse 2s infinite';
                }, 10);
            });
        }, 2000);
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
