<?php
// teacher/timebook.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow teachers
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// School authentication and context
$current_school_id = require_school_auth();
$user_id = $_SESSION['user_id'];

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

// Apply system timezone if set
$timezoneStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
$timezoneStmt->execute(['timezone']);
$appTimezone = $timezoneStmt->fetchColumn();
if (!$appTimezone) {
    $appTimezone = 'Africa/Lagos';
}
date_default_timezone_set($appTimezone);

// Check if teacher sign-in is enabled
$signinEnabledStmt = $pdo->prepare("SELECT setting_value FROM school_settings WHERE school_id = ? AND setting_key = ?");
$signinEnabledStmt->execute([$current_school_id, 'teacher_signin_enabled']);
$signin_enabled = $signinEnabledStmt->fetchColumn();
if ($signin_enabled === false) {
    $fallbackStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $fallbackStmt->execute(['teacher_signin_enabled']);
    $signin_enabled = $fallbackStmt->fetchColumn();
}
$signin_enabled = $signin_enabled === false ? true : ($signin_enabled === '1' || $signin_enabled === 1);

// Generate CSRF token
$csrf_token = generate_csrf_token();

// Handle sign in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $signin_enabled) {
    // Validate CSRF token
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = "Security validation failed. Please refresh the page.";
        Security::logSecurityEvent('csrf_violation', ['action' => 'timebook_signin', 'user_id' => $user_id]);
        header("Location: timebook.php");
        exit;
    }

    if (isset($_POST['sign_in'])) {
        $current_time = date('Y-m-d H:i:s');
        $notes = $_POST['notes'] ?? '';

        // Check if already signed in today
        $checkStmt = $pdo->prepare("SELECT id FROM time_records WHERE user_id = ? AND school_id = ? AND DATE(sign_in_time) = CURDATE()");
        $checkStmt->execute([$user_id, $current_school_id]);

        if ($checkStmt->rowCount() === 0) {
            $stmt = $pdo->prepare("INSERT INTO time_records (user_id, school_id, sign_in_time, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $current_school_id, $current_time, $notes]);
            $_SESSION['success'] = "Successfully signed in!";
        } else {
            $_SESSION['message'] = "You have already signed in today.";
        }

        header("Location: timebook.php");
        exit();
    }
}

// Get user info
$userStmt = $pdo->prepare("SELECT full_name, email, expected_arrival FROM users WHERE id = ? AND school_id = ?");
$userStmt->execute([$user_id, $current_school_id]);
$user = $userStmt->fetch();

// Get today's record
$todayStmt = $pdo->prepare("SELECT * FROM time_records WHERE user_id = ? AND school_id = ? AND DATE(sign_in_time) = CURDATE()");
$todayStmt->execute([$user_id, $current_school_id]);
$todayRecord = $todayStmt->fetch();

// Get this month's records
$monthStmt = $pdo->prepare("SELECT * FROM time_records WHERE user_id = ? AND school_id = ? AND MONTH(sign_in_time) = MONTH(CURDATE()) ORDER BY sign_in_time DESC");
$monthStmt->execute([$user_id, $current_school_id]);
$monthRecords = $monthStmt->fetchAll();

// Calculate statistics
$statsStmt = $pdo->prepare("SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'agreed' THEN 1 ELSE 0 END) as agreed_days,
    SUM(CASE WHEN status = 'not_agreed' THEN 1 ELSE 0 END) as not_agreed_days,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_days
    FROM time_records
    WHERE user_id = ? AND school_id = ? AND MONTH(sign_in_time) = MONTH(CURDATE())");
$statsStmt->execute([$user_id, $current_school_id]);
$stats = $statsStmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Timebook - SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f5f7fb;
        }

        .dashboard-container .main-content {
            width: 100%;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .timebook-hero {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            border-radius: 16px;
            padding: 1.5rem 2rem;
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2);
        }

        .timebook-hero h2 {
            margin: 0 0 0.35rem 0;
        }

        .timebook-hero p {
            margin: 0;
            opacity: 0.9;
        }

        .timebook-panel {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
            padding: 1.5rem;
        }

        .time-display {
            font-size: 2.5rem;
            font-weight: 700;
            letter-spacing: 1px;
            color: #1e3a8a;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .status-pending {
            background: #fef3c7;
            color: #b45309;
        }

        .status-agreed {
            background: #dcfce7;
            color: #15803d;
        }

        .status-not-agreed {
            background: #fee2e2;
            color: #b91c1c;
        }

        .alert {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-success {
            background: #dcfce7;
            color: #15803d;
        }

        .alert-info {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .alert-error {
            background: #fee2e2;
            color: #b91c1c;
        }

        .alert-warning {
            background: #fef3c7;
            color: #b45309;
        }

        .text-muted {
            color: #64748b;
        }

        .signin-button {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0.85rem 1.5rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .signin-button:disabled {
            opacity: 0.7;
        }

        .table-wrap {
            margin-top: 1rem;
        }

        .notes-button {
            border: 1px solid #cbd5f5;
            background: #fff;
            color: #1d4ed8;
            border-radius: 8px;
            padding: 0.4rem 0.75rem;
            font-weight: 600;
        }

        .notes-button:hover {
            background: #eff6ff;
        }

        @media (max-width: 768px) {
            .timebook-hero {
                padding: 1.25rem 1.5rem;
            }

            .time-display {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Navigation Component -->
    <?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Teacher Timebook</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($user['full_name'] ?? 'Teacher'); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include '../includes/teacher_sidebar.php'; ?>
        <main class="main-content">
            <div class="main-container">

                        <div class="content-header">
                    <div class="welcome-section">
                        <h2>Teacher Timebook</h2>
                        <p>Track your daily attendance and monitor your time records efficiently.</p>
                    </div>
                    <div class="header-stats">
                        <div class="quick-stat">
                            <span class="quick-stat-value"><?php echo date('M j, Y'); ?></span>
                            <span class="quick-stat-label">Today</span>
                        </div>
                        <div class="quick-stat">
                            <span class="quick-stat-value"><?php echo $todayRecord ? date('H:i', strtotime($todayRecord['sign_in_time'])) : '--:--'; ?></span>
                            <span class="quick-stat-label">Sign-in Time</span>
                        </div>
                    </div>
                </div>

                <div class="stats-section">
                    <div class="section-header">
                        <h3>Monthly Overview</h3>
                        <span class="section-badge">This Month</span>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-box">
                            <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                            <div class="stat-info">
                                <span class="stat-value"><?php echo $stats['total_days'] ?? 0; ?></span>
                                <span class="stat-label">Days Worked</span>
                            </div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="stat-info">
                                <span class="stat-value"><?php echo $stats['agreed_days'] ?? 0; ?></span>
                                <span class="stat-label">Agreed Days</span>
                            </div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-icon"><i class="fas fa-clock"></i></div>
                            <div class="stat-info">
                                <span class="stat-value"><?php echo $stats['pending_days'] ?? 0; ?></span>
                                <span class="stat-label">Pending Review</span>
                            </div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                            <div class="stat-info">
                                <span class="stat-value"><?php echo $stats['not_agreed_days'] ?? 0; ?></span>
                                <span class="stat-label">Not Agreed</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sign-in Section -->
                <div class="activity-section">
                    <div class="section-header">
                        <h3>Today's Attendance</h3>
                        <span class="section-badge">Live</span>
                    </div>
                    <div class="timebook-panel">
                        <div class="time-display" id="currentTime">
                            <?php echo date('H:i:s'); ?>
                        </div>
                        <p class="text-muted">Sign in when you arrive at school.</p>

                        <?php if ($todayRecord): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-check-circle"></i>
                                <div>
                                    <strong>You signed in at <?php echo date('H:i:s', strtotime($todayRecord['sign_in_time'])); ?></strong>
                                    <?php
                                    $todayStatus = $todayRecord['status'] ?? 'pending';
                                    if ($todayStatus === '') {
                                        $todayStatus = 'pending';
                                    }
                                    ?>
                                    <?php if ($todayStatus !== 'pending'): ?>
                                        <div style="margin-top: 0.5rem;">
                                            <span class="status-pill status-<?php echo $todayStatus; ?>">
                                                Status: <?php
                                                $statusLabels = [
                                                    'agreed' => 'Agreed',
                                                    'not_agreed' => 'Not Agreed'
                                                ];
                                                echo $statusLabels[$todayStatus] ?? 'Pending';
                                                ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <div style="margin-top: 0.5rem;">
                                            <span class="status-pill status-pending">Awaiting Review</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($todayRecord['admin_notes'])): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-sticky-note"></i>
                                    <div>
                                        <strong>Admin Notes:</strong>
                                        <p style="margin: 0;"><?php echo htmlspecialchars($todayRecord['admin_notes']); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php elseif (!$signin_enabled): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>Sign-in Temporarily Disabled</strong><br>
                                    Teacher sign-in has been disabled by the administrator. Please contact the admin for assistance.
                                </div>
                            </div>
                        <?php else: ?>
                            <form method="POST" id="signInForm" style="margin-top: 1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="sign_in" value="1">
                                <div class="form-group">
                                    <label class="form-label">Notes (optional)</label>
                                    <textarea class="form-control" name="notes" rows="3" placeholder="Add any notes for today (optional)"></textarea>
                                </div>
                                <button type="submit" class="signin-button">
                                    <i class="fas fa-sign-in-alt"></i>
                                    <span>Sign In Now</span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

<!-- Alerts -->
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php safe_echo($_SESSION['success']); unset($_SESSION['success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['message'])): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <span><?php safe_echo($_SESSION['message']); unset($_SESSION['message']); ?></span>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php safe_echo($_SESSION['error']); unset($_SESSION['error']); ?></span>
            </div>
        <?php endif; ?>

                        <!-- Attendance Records Table -->
                <div class="activity-section">
                    <div class="section-header">
                        <h3>Recent Attendance Records</h3>
                        <span class="section-badge"><?php echo count($monthRecords); ?> Records</span>
                    </div>

                    <div class="timebook-panel">
                        <?php if (empty($monthRecords)): ?>
                            <div style="text-align: center; padding: 2rem; color: #64748b;">
                                <i class="fas fa-clipboard-list" style="font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.5;"></i>
                                <h4 style="margin-bottom: 0.35rem;">No records found</h4>
                                <p style="margin: 0;">Your attendance records will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Sign In Time</th>
                                            <th>Expected Time</th>
                                            <th>Status</th>
                                            <th>Admin Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthRecords as $record): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y', strtotime($record['sign_in_time'])); ?></td>
                                                <td><strong><?php echo date('H:i:s', strtotime($record['sign_in_time'])); ?></strong></td>
                                                <td><?php echo $user['expected_arrival']; ?></td>
                                                <td>
                                                    <?php
                                                    $recordStatus = $record['status'] ?? 'pending';
                                                    if ($recordStatus === '') {
                                                        $recordStatus = 'pending';
                                                    }
                                                    $statusLabels = [
                                                        'pending' => 'Pending',
                                                        'agreed' => 'Agreed',
                                                        'not_agreed' => 'Not Agreed'
                                                    ];
                                                    ?>
                                                    <span class="status-pill status-<?php echo $recordStatus; ?>">
                                                        <?php echo $statusLabels[$recordStatus] ?? 'Pending'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($record['admin_notes'])): ?>
                                                        <button class="notes-button"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#notesModal"
                                                                data-notes="<?php echo htmlspecialchars($record['admin_notes']); ?>">
                                                            <i class="fas fa-sticky-note"></i> View Notes
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">-</span>
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
        </main>
    </div>

<!-- Notes Modal -->
    <div class="modal fade" id="notesModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
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
        // Update current time every second (sync with server timezone)
        const serverStartTime = new Date("<?php echo date('Y-m-d\\TH:i:sP'); ?>");
        let serverTime = new Date(serverStartTime.getTime());

        function updateTime() {
            const timeString = serverTime.toLocaleTimeString('en-US', { hour12: false });
            const timeEl = document.getElementById('currentTime');
            if (timeEl) {
                timeEl.textContent = timeString;
            }
            serverTime = new Date(serverTime.getTime() + 1000);
        }

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

        // Show success message if exists
        <?php if (isset($_SESSION['success'])): ?>
            showToast("<?php echo $_SESSION['success']; ?>", "success");
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        // Show info message if exists
        <?php if (isset($_SESSION['message'])): ?>
            showToast("<?php echo $_SESSION['message']; ?>", "info");
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 1055; min-width: 300px;';
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            document.body.appendChild(toast);

            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();

            setTimeout(() => {
                toast.remove();
            }, 4000);
        }

        // Enhanced form validation
        const signInForm = document.getElementById('signInForm');
        if (signInForm) {
            signInForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('.signin-button');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Signing In...</span>';
                    submitBtn.disabled = true;
                }
            });
        }

    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
