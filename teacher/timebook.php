<?php
session_start();
require_once '../config/db.php';

// Only allow teachers
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle sign in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['sign_in'])) {
        $current_time = date('Y-m-d H:i:s');
        $notes = $_POST['notes'] ?? '';
        
        // Check if already signed in today
        $checkStmt = $pdo->prepare("SELECT id FROM time_records WHERE user_id = ? AND DATE(sign_in_time) = CURDATE()");
        $checkStmt->execute([$user_id]);
        
        if ($checkStmt->rowCount() === 0) {
            $stmt = $pdo->prepare("INSERT INTO time_records (user_id, sign_in_time, notes) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $current_time, $notes]);
            $_SESSION['message'] = "Successfully signed in!";
        } else {
            $_SESSION['message'] = "You have already signed in today.";
        }
        
        header("Location: timebook.php");
        exit();
    }
}

// Get user info
$userStmt = $pdo->prepare("SELECT full_name, email, expected_arrival FROM users WHERE id = ?");
$userStmt->execute([$user_id]);
$user = $userStmt->fetch();

// Get today's record
$todayStmt = $pdo->prepare("SELECT * FROM time_records WHERE user_id = ? AND DATE(sign_in_time) = CURDATE()");
$todayStmt->execute([$user_id]);
$todayRecord = $todayStmt->fetch();

// Get this month's records
$monthStmt = $pdo->prepare("SELECT * FROM time_records WHERE user_id = ? AND MONTH(sign_in_time) = MONTH(CURDATE()) ORDER BY sign_in_time DESC");
$monthStmt->execute([$user_id]);
$monthRecords = $monthStmt->fetchAll();

// Calculate statistics
$statsStmt = $pdo->prepare("SELECT 
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'agreed' THEN 1 ELSE 0 END) as agreed_days,
    SUM(CASE WHEN status = 'not_agreed' THEN 1 ELSE 0 END) as not_agreed_days,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_days
    FROM time_records 
    WHERE user_id = ? AND MONTH(sign_in_time) = MONTH(CURDATE())");
$statsStmt->execute([$user_id]);
$stats = $statsStmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Timebook - Teacher Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --success-color: #06d6a0;
            --warning-color: #ffd166;
            --danger-color: #ef476f;
            --light-color: #f8f9fa;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fb 0%, #e3e8ff 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        
        .navbar-custom {
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            padding: 15px 0;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-color), #3a56d4);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            margin-right: 20px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            height: 100%;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
        }
        
        .sign-in-card {
            background: linear-gradient(135deg, var(--primary-color), #3a56d4);
            color: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.3);
        }
        
        .time-display-large {
            font-family: 'Courier New', monospace;
            font-size: 3.5rem;
            font-weight: 700;
            text-align: center;
            margin: 20px 0;
        }
        
        .status-indicator {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.9rem;
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
        
        .attendance-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .table th {
            background: #f8f9fa;
            border: none;
            padding: 15px;
            font-weight: 600;
            color: #495057;
        }
        
        .table td {
            padding: 15px;
            vertical-align: middle;
            border-color: #f1f3f5;
        }
        
        .btn-signin {
            background: white;
            color: var(--primary-color);
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s;
        }
        
        .btn-signin:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,255,255,0.2);
        }
        
        .btn-signin:disabled {
            background: #e9ecef;
            color: #6c757d;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        @media (max-width: 768px) {
            .time-display-large {
                font-size: 2.5rem;
            }
            
            .profile-avatar {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .dashboard-card {
                margin-bottom: 20px;
            }
        }
        
        .modal-custom {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .note-input {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 12px;
            transition: all 0.3s;
        }
        
        .note-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            outline: none;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="#">
                <i class="fas fa-clock me-2"></i>Teacher Timebook
            </a>
            <div class="d-flex align-items-center">
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="bg-primary rounded-circle p-2 me-2">
                            <i class="fas fa-user text-white"></i>
                        </div>
                        <span class="d-none d-md-inline"><?php echo htmlspecialchars($user['full_name']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="reports.php"><i class="fas fa-chart-line me-2"></i>My Reports</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container py-4">
        <!-- User Profile -->
        <div class="user-profile">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
            </div>
            <div class="flex-grow-1">
                <h4 class="mb-1"><?php echo htmlspecialchars($user['full_name']); ?></h4>
                <p class="text-muted mb-2"><?php echo htmlspecialchars($user['email']); ?></p>
                <div class="d-flex align-items-center">
                    <span class="badge bg-light text-dark me-3">
                        <i class="fas fa-clock me-1"></i> Expected: <?php echo $user['expected_arrival']; ?>
                    </span>
                    <span class="badge bg-light text-dark">
                        <i class="fas fa-calendar me-1"></i> <?php echo date('F j, Y'); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Today's Status -->
        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="sign-in-card">
                    <div class="text-center mb-4">
                        <h3 class="mb-2">Today's Attendance</h3>
                        <p class="opacity-75">Sign in when you arrive at school</p>
                    </div>
                    
                    <div class="time-display-large" id="currentTime">
                        <?php echo date('H:i:s'); ?>
                    </div>
                    
                    <div class="text-center mb-4">
                        <?php if ($todayRecord): ?>
                            <div class="alert alert-light" role="alert">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                You signed in at <strong><?php echo date('H:i:s', strtotime($todayRecord['sign_in_time'])); ?></strong>
                                <?php if ($todayRecord['status'] !== 'pending'): ?>
                                    <br>
                                    <span class="status-indicator status-<?php echo $todayRecord['status']; ?> mt-2 d-inline-block">
                                        Status: <?php 
                                        $statusLabels = [
                                            'agreed' => 'Agreed ✓',
                                            'not_agreed' => 'Not Agreed ✗'
                                        ];
                                        echo $statusLabels[$todayRecord['status']]; 
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <br>
                                    <span class="status-indicator status-pending mt-2 d-inline-block">
                                        Awaiting Review
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($todayRecord['admin_notes'])): ?>
                                <div class="alert alert-light mt-3">
                                    <small class="text-muted">Admin Notes:</small>
                                    <p class="mb-0"><?php echo htmlspecialchars($todayRecord['admin_notes']); ?></p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST" id="signInForm">
                                <div class="mb-4">
                                    <textarea class="form-control note-input" name="notes" rows="2" 
                                              placeholder="Add any notes for today (optional)"></textarea>
                                </div>
                                <button type="submit" name="sign_in" class="btn btn-signin btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i> Sign In Now
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 mt-4 mt-lg-0">
                <div class="dashboard-card">
                    <h5 class="mb-4">This Month</h5>
                    <div class="row text-center">
                        <div class="col-6 mb-4">
                            <div class="stat-number text-primary"><?php echo $stats['total_days'] ?? 0; ?></div>
                            <div class="stat-label">Days Worked</div>
                        </div>
                        <div class="col-6 mb-4">
                            <div class="stat-number text-success"><?php echo $stats['agreed_days'] ?? 0; ?></div>
                            <div class="stat-label">Agreed Days</div>
                        </div>
                        <div class="col-6">
                            <div class="stat-number text-warning"><?php echo $stats['pending_days'] ?? 0; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="col-6">
                            <div class="stat-number text-danger"><?php echo $stats['not_agreed_days'] ?? 0; ?></div>
                            <div class="stat-label">Not Agreed</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Records -->
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-4">Recent Attendance Records</h5>
                
                <?php if (empty($monthRecords)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <h5>No records found</h5>
                        <p class="text-muted">Your attendance records will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Sign In Time</th>
                                    <th>Expected Time</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthRecords as $record): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($record['sign_in_time'])); ?></td>
                                        <td>
                                            <span class="fw-bold"><?php echo date('H:i:s', strtotime($record['sign_in_time'])); ?></span>
                                        </td>
                                        <td><?php echo $user['expected_arrival']; ?></td>
                                        <td>
                                            <span class="status-indicator status-<?php echo $record['status']; ?>">
                                                <?php 
                                                $statusLabels = [
                                                    'pending' => 'Pending',
                                                    'agreed' => 'Agreed',
                                                    'not_agreed' => 'Not Agreed'
                                                ];
                                                echo $statusLabels[$record['status']]; 
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($record['admin_notes'])): ?>
                                                <button class="btn btn-sm btn-outline-secondary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#notesModal"
                                                        data-notes="<?php echo htmlspecialchars($record['admin_notes']); ?>">
                                                    <i class="fas fa-sticky-note me-1"></i> View Notes
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Notes Modal -->
    <div class="modal fade" id="notesModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-custom">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Admin Notes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="modalNotesContent"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update current time every second
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour12: false });
            document.getElementById('currentTime').textContent = timeString;
        }
        
        // Initialize time and update every second
        updateTime();
        setInterval(updateTime, 1000);
        
        // Handle notes modal
        const notesModal = document.getElementById('notesModal');
        notesModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const notes = button.getAttribute('data-notes');
            const modalBody = notesModal.querySelector('#modalNotesContent');
            modalBody.textContent = notes;
        });
        
        // Auto-resize textarea
        const textarea = document.querySelector('textarea[name="notes"]');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }
        
        // Show toast message if exists
        <?php if (isset($_SESSION['message'])): ?>
            showToast("<?php echo $_SESSION['message']; ?>", "success");
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>
        
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 1055;';
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-check-circle me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            document.body.appendChild(toast);
            
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }
    </script>
</body>
</html>