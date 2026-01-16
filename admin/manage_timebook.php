<?php
session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

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

// Handle toggle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_signin'])) {
    $enabled = $_POST['enabled'] ? '1' : '0';
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
    $stmt->execute(['teacher_signin_enabled', $enabled, $enabled]);
    $_SESSION['message'] = "Teacher sign-in " . ($enabled === '1' ? 'enabled' : 'disabled') . " successfully!";
    header("Location: manage_timebook.php");
    exit();
}

// Get current toggle state
$toggleStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
$toggleStmt->execute(['teacher_signin_enabled']);
$signin_enabled = $toggleStmt->fetchColumn();
$signin_enabled = $signin_enabled !== false ? (bool)$signin_enabled : true; // Default to true if not set


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
    <title>Manage Timebook - SahabFormMaster</title>
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
        <?php include '../includes/admin_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>⏰ Manage Teacher Timebook</h2>
                    <p>Review and manage teacher attendance records</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($stats['total'] ?? 0); ?></span>
                        <span class="quick-stat-label">Total Today</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($stats['agreed'] ?? 0); ?></span>
                        <span class="quick-stat-label">Approved</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($stats['pending'] ?? 0); ?></span>
                        <span class="quick-stat-label">Pending</span>
                    </div>
                </div>
            </div>

            <!-- Controls Section -->
            <div class="stats-section">
                <div class="section-header">
                    <h3>⚙️ System Controls</h3>
                    <span class="section-badge">Settings</span>
                </div>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-icon">
                            <i class="fas fa-toggle-on"></i>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value">Teacher Sign-in</span>
                            <span class="stat-label"><?php echo $signin_enabled ? 'Enabled' : 'Disabled'; ?></span>
                        </div>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="toggle_signin" value="1">
                            <input type="hidden" name="enabled" value="<?php echo $signin_enabled ? '0' : '1'; ?>">
                            <button type="submit" class="btn btn-sm <?php echo $signin_enabled ? 'btn-danger' : 'btn-success'; ?> ms-2">
                                <i class="fas fa-<?php echo $signin_enabled ? 'times' : 'check'; ?> me-1"></i>
                                <?php echo $signin_enabled ? 'Disable' : 'Enable'; ?>
                            </button>
                        </form>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo date('F j, Y'); ?></span>
                            <span class="stat-label">Current Date</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📊</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Records</h3>
                        <p class="card-value"><?php echo number_format($stats['total'] ?? 0); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Today's Activity</span>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">✅</div>
                    </div>
                    <div class="card-content">
                        <h3>Approved</h3>
                        <p class="card-value"><?php echo number_format($stats['agreed'] ?? 0); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Accepted Records</span>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-5">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">⏳</div>
                    </div>
                    <div class="card-content">
                        <h3>Pending Review</h3>
                        <p class="card-value"><?php echo number_format($stats['pending'] ?? 0); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Awaiting Action</span>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-6">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">❌</div>
                    </div>
                    <div class="card-content">
                        <h3>Not Approved</h3>
                        <p class="card-value"><?php echo number_format($stats['not_agreed'] ?? 0); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Rejected Records</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="activity-section">
                <div class="section-header">
                    <h3>🔍 Search & Filter</h3>
                    <span class="section-badge">Records</span>
                </div>
                <div class="filters-form">
                    <form method="GET" class="stats-grid">
                        <div class="form-group">
                            <label class="form-label">📅 Select Date</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                <input type="text" class="form-control datepicker" name="date" value="<?php echo $date; ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">👨‍🏫 Search Teacher</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" name="search" placeholder="Name or email..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">🎯 Quick Actions</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i> Apply Filters
                                </button>
                                <a href="manage_timebook.php" class="btn btn-secondary">
                                    <i class="fas fa-redo me-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>

                    <!-- Filter Tabs -->
                    <div class="mt-4">
                        <div class="d-flex flex-wrap gap-2">
                            <a class="badge <?php echo $filter === 'all' ? 'badge-primary' : 'badge-secondary'; ?> p-2 text-decoration-none" href="?date=<?php echo $date; ?>&filter=all">
                                <i class="fas fa-list me-1"></i> All Records
                            </a>
                            <a class="badge <?php echo $filter === 'pending' ? 'badge-primary' : 'badge-secondary'; ?> p-2 text-decoration-none" href="?date=<?php echo $date; ?>&filter=pending">
                                <i class="fas fa-clock me-1"></i> Pending Review
                            </a>
                            <a class="badge <?php echo $filter === 'agreed' ? 'badge-primary' : 'badge-secondary'; ?> p-2 text-decoration-none" href="?date=<?php echo $date; ?>&filter=agreed">
                                <i class="fas fa-check me-1"></i> Approved
                            </a>
                            <a class="badge <?php echo $filter === 'not_agreed' ? 'badge-primary' : 'badge-secondary'; ?> p-2 text-decoration-none" href="?date=<?php echo $date; ?>&filter=not_agreed">
                                <i class="fas fa-times me-1"></i> Not Approved
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Time Records Section -->
            <div class="activity-section">
                <div class="section-header">
                    <h3>📋 Time Records</h3>
                    <span class="section-badge"><?php echo count($records); ?> Records</span>
                </div>

                <?php if (empty($records)): ?>
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-info">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">No attendance records found for the selected date</span>
                            <span class="activity-date"><?php echo date('M j, Y', strtotime($date)); ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php
                                echo $record['status'] === 'agreed' ? 'activity-icon-success' :
                                     ($record['status'] === 'not_agreed' ? 'activity-icon-warning' : 'activity-icon-info');
                            ?>">
                                <i class="fas fa-<?php
                                    echo $record['status'] === 'agreed' ? 'check-circle' :
                                         ($record['status'] === 'not_agreed' ? 'times-circle' : 'clock');
                                ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <span class="activity-text">
                                            <strong><?php echo htmlspecialchars($record['full_name']); ?></strong>
                                            <small class="text-muted ms-2"><?php echo htmlspecialchars($record['email']); ?></small>
                                        </span>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="fas fa-sign-in-alt me-1"></i>
                                                Sign-in: <strong><?php echo date('H:i:s', strtotime($record['sign_in_time'])); ?></strong>
                                                <?php if ($record['expected_arrival']): ?>
                                                    | Expected: <strong><?php echo $record['expected_arrival']; ?></strong>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <?php if (!empty($record['admin_notes'])): ?>
                                            <div class="mt-2">
                                                <small class="text-muted">
                                                    <i class="fas fa-sticky-note me-1"></i>
                                                    <em><?php echo htmlspecialchars($record['admin_notes']); ?></em>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex flex-column align-items-end gap-2">
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
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-sm btn-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#reviewModal"
                                                    data-id="<?php echo $record['id']; ?>"
                                                    data-status="<?php echo $record['status']; ?>"
                                                    data-notes="<?php echo htmlspecialchars($record['admin_notes'] ?? ''); ?>"
                                                    data-name="<?php echo htmlspecialchars($record['full_name']); ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="teacher_report.php?id=<?php echo $record['user_id']; ?>"
                                               class="btn btn-sm btn-secondary">
                                                <i class="fas fa-chart-line"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
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
    </script><?php include '../includes/floating-button.php'; ?></body>
</html>
