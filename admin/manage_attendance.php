<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in as principal with school authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: login.php');
    exit();
}

$current_school_id = require_school_auth();

$principal_id = $_SESSION['user_id'];
$current_date = date('Y-m-d');
$selected_date = isset($_GET['date']) ? $_GET['date'] : $current_date;
$class_filter = isset($_GET['class_id']) ? $_GET['class_id'] : 'all';

// Handle bulk update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    $update_date = $_POST['attendance_date'];
    $class_id = $_POST['class_id'];

    try {
        $pdo->beginTransaction();

        foreach ($_POST['attendance'] as $student_id => $status) {
            $sql = "INSERT INTO attendance (student_id, date, status, recorded_by)
                    VALUES (:student_id, :date, :status, :recorded_by)
                    ON DUPLICATE KEY UPDATE status = :status_update, recorded_by = :recorded_by_update";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':student_id' => $student_id,
                ':date' => $update_date,
                ':status' => $status,
                ':recorded_by' => $principal_id,
                ':status_update' => $status,
                ':recorded_by_update' => $principal_id
            ]);
        }

        $pdo->commit();
        $_SESSION['success'] = "Attendance updated successfully!";
        header("Location: manage_attendance.php?date=$update_date&class_id=$class_id");
        exit();

    } catch(PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error updating attendance: " . $e->getMessage();
    }
}

// Handle holiday management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_holiday'])) {
    $holiday_name = $_POST['holiday_name'];
    $holiday_date = $_POST['holiday_date'];
    $holiday_type = $_POST['holiday_type'];
    $description = $_POST['description'];

    try {
        $sql = "INSERT INTO holidays (holiday_name, holiday_date, holiday_type, description, school_id)
                VALUES (:holiday_name, :holiday_date, :holiday_type, :description, :school_id)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':holiday_name' => $holiday_name,
            ':holiday_date' => $holiday_date,
            ':holiday_type' => $holiday_type,
            ':description' => $description,
            ':school_id' => $current_school_id
        ]);

        $_SESSION['success'] = "Holiday added successfully!";
        header("Location: manage_attendance.php");
        exit();

    } catch(PDOException $e) {
        $_SESSION['error'] = "Error adding holiday: " . $e->getMessage();
    }
}

// Fetch attendance summary - filtered by school
$summary_sql = "SELECT
    (SELECT COUNT(DISTINCT date) FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND s.school_id = ?) as total_days,
    (SELECT COUNT(*) FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.status = 'present' AND a.date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND s.school_id = ?) as present_count,
    (SELECT COUNT(*) FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.status = 'absent' AND a.date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND s.school_id = ?) as absent_count,
    (SELECT COUNT(*) FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.status = 'late' AND a.date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND s.school_id = ?) as late_count";
$summary_stmt = $pdo->prepare($summary_sql);
$summary_stmt->execute([$current_school_id, $current_school_id, $current_school_id, $current_school_id]);
$summary = $summary_stmt->fetch();

// Fetch classes - filtered by school
$classes_sql = "SELECT * FROM classes WHERE school_id = ? ORDER BY id";
$classes_stmt = $pdo->prepare($classes_sql);
$classes_stmt->execute([$current_school_id]);
$classes = $classes_stmt->fetchAll();

// Fetch attendance for selected date and class - filtered by school
if ($class_filter === 'all') {
    $attendance_sql = "SELECT s.id, s.full_name, s.admission_no, c.class_name, a.status
                       FROM students s
                       JOIN classes c ON s.class_id = c.id
                       LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :selected_date
                       WHERE s.school_id = :school_id AND c.school_id = :school_id
                       ORDER BY c.class_name, s.full_name";
    $attendance_stmt = $pdo->prepare($attendance_sql);
    $attendance_stmt->execute([':selected_date' => $selected_date, ':school_id' => $current_school_id]);
} else {
    $attendance_sql = "SELECT s.id, s.full_name, s.admission_no, c.class_name, a.status
                       FROM students s
                       JOIN classes c ON s.class_id = c.id
                       LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :selected_date
                       WHERE s.class_id = :class_id AND s.school_id = :school_id AND c.school_id = :school_id
                       ORDER BY s.full_name";
    $attendance_stmt = $pdo->prepare($attendance_sql);
    $attendance_stmt->execute([
        ':selected_date' => $selected_date,
        ':class_id' => $class_filter,
        ':school_id' => $current_school_id
    ]);
}
$attendance_records = $attendance_stmt->fetchAll();

// Fetch upcoming holidays - filtered by school (assuming holidays table has school_id)
$holidays_sql = "SELECT * FROM holidays WHERE holiday_date >= CURDATE() AND school_id = ? ORDER BY holiday_date LIMIT 5";
$holidays_stmt = $pdo->prepare($holidays_sql);
$holidays_stmt->execute([$current_school_id]);
$holidays = $holidays_stmt->fetchAll();

// Fetch class-wise statistics - filtered by school
$class_stats_sql = "SELECT
    c.class_name,
    COUNT(DISTINCT s.id) as total_students,
    AVG(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100 as attendance_percentage
    FROM classes c
    LEFT JOIN students s ON c.id = s.class_id AND s.school_id = ?
    LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :current_date
    WHERE c.school_id = ?
    GROUP BY c.id
    ORDER BY c.id";
$class_stats_stmt = $pdo->prepare($class_stats_sql);
$class_stats_stmt->execute([$current_school_id, $current_school_id, ':current_date' => $current_date]);
$class_stats = $class_stats_stmt->fetchAll();

$principal_name = $_SESSION['full_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attendance - Principal | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css" rel="stylesheet">
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
                    <h2>📋 Attendance Management</h2>
                    <p>Monitor and manage student attendance records</p>
                </div>
            </div>

            <!-- Dashboard Summary Cards -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📅</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Days</h3>
                        <p class="card-value"><?php echo $summary['total_days'] ?? 0; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Last 30 days</span>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">✅</div>
                    </div>
                    <div class="card-content">
                        <h3>Present</h3>
                        <p class="card-value"><?php echo $summary['present_count'] ?? 0; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Total records</span>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">❌</div>
                    </div>
                    <div class="card-content">
                        <h3>Absent</h3>
                        <p class="card-value"><?php echo $summary['absent_count'] ?? 0; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Total records</span>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">⏰</div>
                    </div>
                    <div class="card-content">
                        <h3>Late</h3>
                        <p class="card-value"><?php echo $summary['late_count'] ?? 0; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Total records</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Management Section -->
            <div class="section-container">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-calendar-check"></i>
                        Attendance for <?php echo date('F j, Y', strtotime($selected_date)); ?>
                    </h3>
                </div>

                <!-- Filters and Actions -->
                <div class="stats-grid" style="margin-bottom: 2rem;">
                    <div class="form-group">
                        <label class="form-label">Select Date</label>
                        <input type="text" class="form-control datepicker" name="date" value="<?php echo $selected_date; ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Select Class</label>
                        <select name="class_id" class="form-control" onchange="changeFilters()">
                            <option value="all">All Classes</option>
                            <?php foreach($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo ($class_filter == $class['id']) ? 'selected' : ''; ?>>
                                    <?php echo $class['class_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; align-items: end; gap: 0.5rem;">
                        <button class="btn btn-success" onclick="markAll('present')">
                            <i class="fas fa-check"></i> Mark All Present
                        </button>
                        <button class="btn btn-danger" onclick="markAll('absent')">
                            <i class="fas fa-times"></i> Mark All Absent
                        </button>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if(isset($_SESSION['success'])): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <?php safe_echo($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <?php if(isset($_SESSION['error'])): ?>
                    <div class="alert alert-error" role="alert">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php safe_echo($_SESSION['error']); unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <!-- Attendance Form -->
                <form method="POST" action="">
                    <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $class_filter; ?>">

                    <div class="table-responsive">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Admission No</th>
                                    <th>Student Name</th>
                                    <th>Class</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach($attendance_records as $student): ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                                        <td>
                                            <?php
                                            $status = $student['status'] ?: 'absent';
                                            $badge_class = [
                                                'present' => 'badge-success',
                                                'absent' => 'badge-danger',
                                                'late' => 'badge-warning',
                                                'leave' => 'badge-info'
                                            ][$status];
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <select name="attendance[<?php echo $student['id']; ?>]" class="form-control form-control-sm status-select">
                                                <option value="present" <?php echo ($status == 'present') ? 'selected' : ''; ?>>Present</option>
                                                <option value="absent" <?php echo ($status == 'absent') ? 'selected' : ''; ?>>Absent</option>
                                                <option value="late" <?php echo ($status == 'late') ? 'selected' : ''; ?>>Late</option>
                                                <option value="leave" <?php echo ($status == 'leave') ? 'selected' : ''; ?>>Leave</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                        <button type="submit" name="bulk_update" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Attendance
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="printAttendance()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </form>
            </div>

            <!-- Additional Sections -->
            <div class="stats-section">
                <div class="section-header">
                    <h3>📊 Today's Class Statistics</h3>
                </div>
                <div class="stats-grid">
                    <?php foreach($class_stats as $stat): ?>
                        <div class="stat-box">
                            <div class="stat-icon">📚</div>
                            <div class="stat-info">
                                <span class="stat-value"><?php echo round($stat['attendance_percentage'], 1); ?>%</span>
                                <span class="stat-label"><?php echo $stat['class_name']; ?></span>
                            </div>
                            <div class="stat-progress">
                                <div class="progress-bar" style="width: <?php echo round($stat['attendance_percentage'], 1); ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Holiday Management -->
            <div class="section-container">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-calendar-plus"></i>
                        Add Holiday
                    </h3>
                </div>

                <form method="POST" action="" class="stats-grid" style="margin-top: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Holiday Name</label>
                        <input type="text" class="form-control" name="holiday_name" placeholder="Holiday Name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="text" class="form-control datepicker" name="holiday_date" placeholder="Date" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="holiday_type" class="form-control">
                            <option value="national">National Holiday</option>
                            <option value="religious">Religious Holiday</option>
                            <option value="school">School Holiday</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" placeholder="Description" rows="2"></textarea>
                    </div>
                    <div class="form-group" style="display: flex; align-items: end;">
                        <button type="submit" name="add_holiday" class="btn btn-warning">
                            <i class="fas fa-plus"></i> Add Holiday
                        </button>
                    </div>
                </form>
            </div>

            <!-- Upcoming Holidays -->
            <div class="activity-section">
                <div class="section-header">
                    <h3>📅 Upcoming Holidays</h3>
                </div>
                <div class="activity-list">
                    <?php if(count($holidays) > 0): ?>
                        <?php foreach($holidays as $holiday): ?>
                            <div class="activity-item">
                                <div class="activity-icon activity-icon-info">📅</div>
                                <div class="activity-content">
                                    <span class="activity-text">
                                        <strong><?php echo htmlspecialchars($holiday['holiday_name']); ?></strong> -
                                        <?php echo htmlspecialchars($holiday['description'] ?: 'No description'); ?>
                                    </span>
                                    <span class="activity-date"><?php echo date('M j, Y', strtotime($holiday['holiday_date'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="activity-item">
                            <div class="activity-icon activity-icon-info">📅</div>
                            <div class="activity-content">
                                <span class="activity-text">No upcoming holidays</span>
                                <span class="activity-date"><?php echo date('M j, Y'); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>
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

        // Initialize Datepicker
        $(document).ready(function() {
            $('.datepicker').datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true
            });
        });

        // Filter change handler
        function changeFilters() {
            const classId = document.querySelector('select[name="class_id"]').value;
            const date = document.querySelector('.datepicker').value;
            window.location.href = `manage_attendance.php?date=${date}&class_id=${classId}`;
        }

        // Mark all students function
        function markAll(status) {
            const selects = document.querySelectorAll('.status-select');
            selects.forEach(select => select.value = status);
        }

        // Print functionality
        function printAttendance() {
            window.print();
        }

        // Auto-save notification
        let autoSaveTimer;
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('status-select')) {
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(() => {
                    const notification = document.createElement('div');
                    notification.className = 'alert alert-success';
                    notification.innerHTML = '<i class="fas fa-info-circle"></i> Changes detected. Remember to save!';
                    notification.style.cssText = 'position: fixed; top: 100px; right: 20px; z-index: 9999; max-width: 300px;';

                    document.body.appendChild(notification);
                    setTimeout(() => notification.remove(), 3000);
                }, 1000);
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
