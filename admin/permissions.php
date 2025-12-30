<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is a principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $permission_id = $_POST['permission_id'] ?? '';
    $action = $_POST['action'] ?? '';
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    
    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("
                UPDATE permissions 
                SET status = 'approved', 
                    approved_by = ?, 
                    approved_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $permission_id]);
            $message = "Request approved successfully!";
            
        } elseif ($action === 'reject') {
            if (empty($rejection_reason)) {
                $error = "Please provide a reason for rejection.";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE permissions 
                    SET status = 'rejected', 
                        rejection_reason = ?,
                        approved_by = ?,
                        approved_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$rejection_reason, $user_id, $permission_id]);
                $message = "Request rejected successfully!";
            }
        }
    } catch (PDOException $e) {
        $error = "Error processing request: " . $e->getMessage();
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
    WHERE 1=1
";

$params = [];

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
        WHERE status != 'cancelled'
    ");
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'urgent_pending' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Permissions - Sahab Form Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, #34495e 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .stat-total { background-color: #e3f2fd; color: #1976d2; }
        .stat-pending { background-color: #fff3e0; color: #f57c00; }
        .stat-approved { background-color: #e8f5e9; color: #388e3c; }
        .stat-rejected { background-color: #ffebee; color: #d32f2f; }
        .stat-urgent { background-color: #fce4ec; color: #c2185b; }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .table-responsive {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-approved { background-color: #d4edda; color: #155724; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        
        .priority-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
        }
        
        .priority-low { background-color: #d1ecf1; color: #0c5460; }
        .priority-medium { background-color: #fff3cd; color: #856404; }
        .priority-high { background-color: #f8d7da; color: #721c24; }
        .priority-urgent { 
            background-color: #f5c6cb; 
            color: #721c24; 
            font-weight: bold;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .action-btn {
            padding: 5px 15px;
            border: none;
            border-radius: 5px;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        
        .btn-approve {
            background-color: var(--success-color);
            color: white;
        }
        
        .btn-approve:hover {
            background-color: #219a52;
        }
        
        .btn-reject {
            background-color: var(--danger-color);
            color: white;
        }
        
        .btn-reject:hover {
            background-color: #c0392b;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #34495e 100%);
            color: white;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }
        
        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 15px;
            }
            
            .table th, .table td {
                font-size: 0.85rem;
                padding: 0.5rem;
            }
            
            .action-btn {
                padding: 3px 10px;
                font-size: 0.75rem;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-school me-2"></i>Sahab Form Master
            </a>
            <!-- <div class="d-flex align-items-center">
                <span class="text-light me-3"><?php echo $_SESSION['full_name'] ?? 'Principal'; ?></span>
                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" 
                            data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="principal_dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                        <li><a class="dropdown-item" href="principal_permissions.php">
                            <i class="fas fa-clipboard-check me-2"></i>Permissions</a></li>
                        <li><a class="dropdown-item" href="principal_profile.php">
                            <i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div> -->
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon stat-total me-3">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div>
                            <h4 class="mb-0"><?php echo $stats['total']; ?></h4>
                            <small class="text-muted">Total Requests</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon stat-pending me-3">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <h4 class="mb-0"><?php echo $stats['pending']; ?></h4>
                            <small class="text-muted">Pending</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon stat-approved me-3">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <h4 class="mb-0"><?php echo $stats['approved']; ?></h4>
                            <small class="text-muted">Approved</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon stat-rejected me-3">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div>
                            <h4 class="mb-0"><?php echo $stats['rejected']; ?></h4>
                            <small class="text-muted">Rejected</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon stat-urgent me-3">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div>
                            <h4 class="mb-0"><?php echo $stats['urgent_pending']; ?></h4>
                            <small class="text-muted">Urgent Pending</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Request Type</label>
                    <select class="form-select" name="type">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="leave" <?php echo $filter_type === 'leave' ? 'selected' : ''; ?>>Leave</option>
                        <option value="early_departure" <?php echo $filter_type === 'early_departure' ? 'selected' : ''; ?>>Early Departure</option>
                        <option value="late_arrival" <?php echo $filter_type === 'late_arrival' ? 'selected' : ''; ?>>Late Arrival</option>
                        <option value="training" <?php echo $filter_type === 'training' ? 'selected' : ''; ?>>Training</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="date" value="<?php echo $filter_date; ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i>Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Requests Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
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
                                    <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                                        <?php echo ucfirst($request['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $request['status']; ?>">
                                        <?php echo ucfirst($request['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d', strtotime($request['created_at'])); ?>
                                    <br><small><?php echo date('h:i A', strtotime($request['created_at'])); ?></small>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info mb-1 view-details" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#detailsModal"
                                            data-request='<?php echo htmlspecialchars(json_encode($request), ENT_QUOTES, 'UTF-8'); ?>'>
                                        <i class="fas fa-eye me-1"></i>View
                                    </button>
                                    
                                    <?php if ($request['status'] === 'pending'): ?>
                                        <button class="btn btn-sm btn-approve mb-1 approve-btn"
                                                data-id="<?php echo $request['id']; ?>">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </button>
                                        
                                        <button class="btn btn-sm btn-reject mb-1 reject-btn"
                                                data-id="<?php echo $request['id']; ?>"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#rejectModal">
                                            <i class="fas fa-times me-1"></i>Reject
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // View details
        document.querySelectorAll('.view-details').forEach(button => {
            button.addEventListener('click', function() {
                const request = JSON.parse(this.getAttribute('data-request'));
                const content = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Request Information</h6>
                            <p><strong>Staff:</strong> ${request.staff_name}</p>
                            <p><strong>Designation:</strong> ${request.staff_designation || 'N/A'}</p>
                            <p><strong>Type:</strong> ${request.request_type.replace('_', ' ')}</p>
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
                    
                    ${request.attachment_path ? `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6>Attachment</h6>
                            <a href="${request.attachment_path}" target="_blank" class="btn btn-outline-primary">
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
</body>
</html>