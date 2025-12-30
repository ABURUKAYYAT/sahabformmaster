<?php
session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}


// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = $_POST['id'];
    $status = $_POST['status'];
    $notes = $_POST['notes'] ?? '';
    
    $stmt = $pdo->prepare("UPDATE time_records SET status = ?, admin_notes = ?, reviewed_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $notes, $id]);
    
    $_SESSION['message'] = "Status updated successfully!";
    header("Location: manage_timebook.php");
    exit();
}

// Handle filter
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');

// Build query
$query = "SELECT tr.*, u.full_name, u.email, u.expected_arrival 
          FROM time_records tr 
          JOIN users u ON tr.user_id = u.id 
          WHERE DATE(tr.sign_in_time) = ?";
$params = [$date];

if (!empty($search)) {
    $query .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter !== 'all') {
    $query .= " AND tr.status = ?";
    $params[] = $filter;
}

$query .= " ORDER BY tr.sign_in_time DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Get statistics
$statsQuery = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'agreed' THEN 1 ELSE 0 END) as agreed,
    SUM(CASE WHEN status = 'not_agreed' THEN 1 ELSE 0 END) as not_agreed,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM time_records WHERE DATE(sign_in_time) = ?";
$statsStmt = $pdo->prepare($statsQuery);
$statsStmt->execute([$date]);
$stats = $statsStmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Timebook - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --success-color: #06d6a0;
            --warning-color: #ffd166;
            --danger-color: #ef476f;
            --light-color: #f8f9fa;
            --dark-color: #212529;
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            background: linear-gradient(180deg, var(--primary-color), #3a56d4);
            min-height: 100vh;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .sidebar-brand {
            padding: 20px;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 5px 10px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .content-wrapper {
            padding: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .time-record-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary-color);
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .status-agreed {
            background-color: rgba(6, 214, 160, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }
        
        .status-not_agreed {
            background-color: rgba(239, 71, 111, 0.1);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }
        
        .status-pending {
            background-color: rgba(255, 209, 102, 0.1);
            color: #e6b400;
            border: 1px solid #e6b400;
        }
        
        .time-display {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            
            .content-wrapper {
                padding: 15px;
            }
        }
        
        .filter-tabs .nav-link {
            color: var(--dark-color);
            border: 1px solid #dee2e6;
            margin: 0 5px;
        }
        
        .filter-tabs .nav-link.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .modal-custom {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .btn-custom {
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            transition: all 0.3s;
        }
        
        .btn-custom-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-custom-primary:hover {
            background: #3a56d4;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-2 col-md-3 sidebar d-none d-md-block">
            
                <nav class="nav flex-column mt-4">
                    <a href="index.php" class="nav-link">
                        <i class="fas fa-home me-2"></i>Dashboard
                    </a>
                    <a href="manage_timebook.php" class="nav-link active">
                        <i class="fas fa-calendar-check me-2"></i>Timebook
                    </a>
                  
                    <a href="attendance_reports.php" class="nav-link">
                        <i class="fas fa-chart-bar me-2"></i>Reports
                    </a>
               
                </nav>
            </div>
            
            <!-- Mobile Header -->
            <div class="d-md-none bg-white shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clock text-primary me-2"></i>TimeTrack</h5>
                    <button class="btn btn-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
            
            <!-- Mobile Menu -->
            <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileMenu">
                <div class="offcanvas-header bg-primary text-white">
                    <h5 class="offcanvas-title">TimeTrack Admin</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
                </div>
                <div class="offcanvas-body">
                    <nav class="nav flex-column">
                        <a href="dashboard.php" class="nav-link">
                            <i class="fas fa-home me-2"></i>Dashboard
                        </a>
                        <a href="manage_timebook.php" class="nav-link active">
                            <i class="fas fa-calendar-check me-2"></i>Timebook
                        </a>
                        <a href="manage_teachers.php" class="nav-link">
                            <i class="fas fa-users me-2"></i>Teachers
                        </a>
                        <a href="reports.php" class="nav-link">
                            <i class="fas fa-chart-bar me-2"></i>Reports
                        </a>
                        <a href="settings.php" class="nav-link">
                            <i class="fas fa-cog me-2"></i>Settings
                        </a>
                        <a href="logout.php" class="nav-link mt-3">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </nav>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-10 col-md-9 ms-sm-auto">
                <div class="content-wrapper">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="h4 fw-bold text-dark">Manage Timebook</h2>
                            <p class="text-muted">Review and manage teacher attendance records</p>
                        </div>
                        <div class="text-muted">
                            <?php echo date('F j, Y'); ?>
                        </div>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Today</h6>
                                        <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                                    </div>
                                    <div class="stat-icon" style="background: rgba(67, 97, 238, 0.1); color: var(--primary-color);">
                                        <i class="fas fa-list-check"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Agreed</h6>
                                        <h3 class="mb-0 text-success"><?php echo $stats['agreed']; ?></h3>
                                    </div>
                                    <div class="stat-icon" style="background: rgba(6, 214, 160, 0.1); color: var(--success-color);">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Not Agreed</h6>
                                        <h3 class="mb-0 text-danger"><?php echo $stats['not_agreed']; ?></h3>
                                    </div>
                                    <div class="stat-icon" style="background: rgba(239, 71, 111, 0.1); color: var(--danger-color);">
                                        <i class="fas fa-times-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Pending</h6>
                                        <h3 class="mb-0 text-warning"><?php echo $stats['pending']; ?></h3>
                                    </div>
                                    <div class="stat-icon" style="background: rgba(255, 209, 102, 0.1); color: #e6b400;">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Select Date</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                        <input type="text" class="form-control datepicker" name="date" value="<?php echo $date; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Search Teacher</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input type="text" class="form-control" name="search" placeholder="Name or email..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-custom-primary me-2">
                                        <i class="fas fa-filter me-1"></i> Apply Filters
                                    </button>
                                    <a href="manage_timebook.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-redo me-1"></i> Reset
                                    </a>
                                </div>
                            </form>
                            
                            <!-- Filter Tabs -->
                            <div class="mt-4">
                                <ul class="nav nav-pills filter-tabs">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="?date=<?php echo $date; ?>&filter=all">All</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $filter === 'pending' ? 'active' : ''; ?>" href="?date=<?php echo $date; ?>&filter=pending">Pending</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $filter === 'agreed' ? 'active' : ''; ?>" href="?date=<?php echo $date; ?>&filter=agreed">Agreed</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $filter === 'not_agreed' ? 'active' : ''; ?>" href="?date=<?php echo $date; ?>&filter=not_agreed">Not Agreed</a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Records List -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title mb-4">Today's Records</h5>
                            
                            <?php if (empty($records)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                    <h5>No records found</h5>
                                    <p class="text-muted">No attendance records for the selected date.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($records as $record): ?>
                                    <div class="time-record-card">
                                        <div class="row align-items-center">
                                            <div class="col-lg-3 col-md-4 mb-3 mb-md-0">
                                                <div class="d-flex align-items-center">
                                                    <div class="bg-light rounded-circle p-3 me-3">
                                                        <i class="fas fa-user text-primary"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($record['full_name']); ?></h6>
                                                        <small class="text-muted"><?php echo htmlspecialchars($record['email']); ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-lg-2 col-md-3 mb-3 mb-md-0">
                                                <div>
                                                    <small class="text-muted d-block">Sign In Time</small>
                                                    <span class="time-display"><?php echo date('H:i:s', strtotime($record['sign_in_time'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="col-lg-2 col-md-3 mb-3 mb-md-0">
                                                <div>
                                                    <small class="text-muted d-block">Expected Time</small>
                                                    <span class="time-display"><?php echo $record['expected_arrival']; ?></span>
                                                </div>
                                            </div>
                                            <div class="col-lg-2 col-md-2">
                                                <span class="status-badge status-<?php echo $record['status']; ?>">
                                                    <?php 
                                                    $statusLabels = [
                                                        'pending' => 'Pending Review',
                                                        'agreed' => 'Agreed',
                                                        'not_agreed' => 'Not Agreed'
                                                    ];
                                                    echo $statusLabels[$record['status']]; 
                                                    ?>
                                                </span>
                                            </div>
                                            <div class="col-lg-3 col-md-12 text-md-end">
                                                <button class="btn btn-sm btn-outline-primary me-2" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#reviewModal"
                                                        data-id="<?php echo $record['id']; ?>"
                                                        data-status="<?php echo $record['status']; ?>"
                                                        data-notes="<?php echo htmlspecialchars($record['admin_notes'] ?? ''); ?>"
                                                        data-name="<?php echo htmlspecialchars($record['full_name']); ?>">
                                                    <i class="fas fa-edit me-1"></i> Review
                                                </button>
                                                <a href="teacher_report.php?id=<?php echo $record['user_id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-chart-line me-1"></i> Report
                                                </a>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($record['admin_notes'])): ?>
                                            <div class="mt-3 pt-3 border-top">
                                                <small class="text-muted">Admin Notes:</small>
                                                <p class="mb-0"><?php echo htmlspecialchars($record['admin_notes']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Review Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-custom">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Review Attendance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="recordId">
                        <div class="mb-3">
                            <label class="form-label">Teacher</label>
                            <input type="text" class="form-control" id="teacherName" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="recordStatus" required>
                                <option value="pending">Pending Review</option>
                                <option value="agreed">Agreed</option>
                                <option value="not_agreed">Not Agreed</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" name="notes" id="recordNotes" rows="3" placeholder="Add notes for the teacher..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_status" class="btn btn-custom-primary">
                            <i class="fas fa-save me-1"></i> Save Changes
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
            
            // Show toast message if exists
            <?php if (isset($_SESSION['message'])): ?>
                showToast("<?php echo $_SESSION['message']; ?>", "success");
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>
        });
        
        function showToast(message, type) {
            var toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 1055;';
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            document.body.appendChild(toast);
            
            var bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }
    </script>
</body>
</html>