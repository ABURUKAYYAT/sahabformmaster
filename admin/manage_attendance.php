<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in as principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: login.php');
    exit();
}

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
        $sql = "INSERT INTO holidays (holiday_name, holiday_date, holiday_type, description) 
                VALUES (:holiday_name, :holiday_date, :holiday_type, :description)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':holiday_name' => $holiday_name,
            ':holiday_date' => $holiday_date,
            ':holiday_type' => $holiday_type,
            ':description' => $description
        ]);
        
        $_SESSION['success'] = "Holiday added successfully!";
        header("Location: manage_attendance.php");
        exit();
        
    } catch(PDOException $e) {
        $_SESSION['error'] = "Error adding holiday: " . $e->getMessage();
    }
}

// Fetch attendance summary
$summary_sql = "SELECT 
    (SELECT COUNT(DISTINCT date) FROM attendance WHERE date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as total_days,
    (SELECT COUNT(*) FROM attendance WHERE status = 'present' AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as present_count,
    (SELECT COUNT(*) FROM attendance WHERE status = 'absent' AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as absent_count,
    (SELECT COUNT(*) FROM attendance WHERE status = 'late' AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as late_count";
$summary_stmt = $pdo->query($summary_sql);
$summary = $summary_stmt->fetch();

// Fetch all classes
$classes_sql = "SELECT * FROM classes ORDER BY id";
$classes_result = $pdo->query($classes_sql);
$classes = $classes_result->fetchAll();

// Fetch attendance for selected date and class
if ($class_filter === 'all') {
    $attendance_sql = "SELECT s.id, s.full_name, s.admission_no, c.class_name, a.status 
                       FROM students s 
                       JOIN classes c ON s.class_id = c.id 
                       LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :selected_date 
                       ORDER BY c.class_name, s.full_name";
    $attendance_stmt = $pdo->prepare($attendance_sql);
    $attendance_stmt->execute([':selected_date' => $selected_date]);
} else {
    $attendance_sql = "SELECT s.id, s.full_name, s.admission_no, c.class_name, a.status 
                       FROM students s 
                       JOIN classes c ON s.class_id = c.id 
                       LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :selected_date 
                       WHERE s.class_id = :class_id 
                       ORDER BY s.full_name";
    $attendance_stmt = $pdo->prepare($attendance_sql);
    $attendance_stmt->execute([
        ':selected_date' => $selected_date,
        ':class_id' => $class_filter
    ]);
}
$attendance_records = $attendance_stmt->fetchAll();

// Fetch upcoming holidays
$holidays_sql = "SELECT * FROM holidays WHERE holiday_date >= CURDATE() ORDER BY holiday_date LIMIT 5";
$holidays_stmt = $pdo->query($holidays_sql);
$holidays = $holidays_stmt->fetchAll();

// Fetch class-wise statistics
$class_stats_sql = "SELECT 
    c.class_name,
    COUNT(DISTINCT s.id) as total_students,
    AVG(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100 as attendance_percentage
    FROM classes c
    LEFT JOIN students s ON c.id = s.class_id
    LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :current_date
    GROUP BY c.id
    ORDER BY c.id";
$class_stats_stmt = $pdo->prepare($class_stats_sql);
$class_stats_stmt->execute([':current_date' => $current_date]);
$class_stats = $class_stats_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attendance - Principal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css" rel="stylesheet">
    <style>
        .attendance-card {
            transition: transform 0.2s;
        }
        .attendance-card:hover {
            transform: translateY(-2px);
        }
        .status-present { color: #198754; }
        .status-absent { color: #dc3545; }
        .status-late { color: #fd7e14; }
        .status-leave { color: #0dcaf0; }
        .dashboard-stat {
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">Sahab Academy - Attendance Management</a>
            <div class="navbar-nav ms-auto">
                <a href="dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="logout.php" class="nav-link"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Dashboard Summary -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="dashboard-stat bg-success text-white">
                    <h5><i class="bi bi-calendar-check"></i> Total Days</h5>
                    <h2><?php echo $summary['total_days']; ?></h2>
                    <small>Last 30 days</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="dashboard-stat bg-info text-white">
                    <h5><i class="bi bi-person-check"></i> Present</h5>
                    <h2><?php echo $summary['present_count']; ?></h2>
                </div>
            </div>
            <div class="col-md-3">
                <div class="dashboard-stat bg-danger text-white">
                    <h5><i class="bi bi-person-x"></i> Absent</h5>
                    <h2><?php echo $summary['absent_count']; ?></h2>
                </div>
            </div>
            <div class="col-md-3">
                <div class="dashboard-stat bg-warning text-white">
                    <h5><i class="bi bi-clock-history"></i> Late</h5>
                    <h2><?php echo $summary['late_count']; ?></h2>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="row">
            <!-- Left Sidebar - Filters and Actions -->
            <div class="col-md-3">
                <!-- Date Selection -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6><i class="bi bi-calendar"></i> Select Date</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="mb-3">
                                <input type="text" class="form-control datepicker" name="date" 
                                       value="<?php echo $selected_date; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Select Class</label>
                                <select name="class_id" class="form-select">
                                    <option value="all">All Classes</option>
                                    <?php foreach($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" 
                                            <?php echo ($class_filter == $class['id']) ? 'selected' : ''; ?>>
                                            <?php echo $class['class_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> View Attendance
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Class Statistics -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6><i class="bi bi-bar-chart"></i> Today's Class Statistics</h6>
                    </div>
                    <div class="card-body">
                        <div class="list-group">
                            <?php foreach($class_stats as $stat): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?php echo $stat['class_name']; ?></span>
                                    <span class="badge bg-<?php echo ($stat['attendance_percentage'] > 80) ? 'success' : 'danger'; ?> rounded-pill">
                                        <?php echo round($stat['attendance_percentage'], 1); ?>%
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Holiday Management -->
                <div class="card">
                    <div class="card-header">
                        <h6><i class="bi bi-calendar-event"></i> Add Holiday</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <input type="text" class="form-control" name="holiday_name" 
                                       placeholder="Holiday Name" required>
                            </div>
                            <div class="mb-3">
                                <input type="text" class="form-control datepicker" name="holiday_date" 
                                       placeholder="Date" required>
                            </div>
                            <div class="mb-3">
                                <select name="holiday_type" class="form-select">
                                    <option value="national">National Holiday</option>
                                    <option value="religious">Religious Holiday</option>
                                    <option value="school">School Holiday</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <textarea class="form-control" name="description" 
                                          placeholder="Description" rows="2"></textarea>
                            </div>
                            <button type="submit" name="add_holiday" class="btn btn-warning w-100">
                                <i class="bi bi-plus-circle"></i> Add Holiday
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="col-md-9">
                <!-- Attendance Management Form -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>
                            <i class="bi bi-clipboard-check"></i> 
                            Attendance for <?php echo date('F j, Y', strtotime($selected_date)); ?>
                        </h5>
                        <div>
                            <button class="btn btn-sm btn-success" onclick="markAll('present')">
                                <i class="bi bi-check-circle"></i> Mark All Present
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="markAll('absent')">
                                <i class="bi bi-x-circle"></i> Mark All Absent
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if(isset($_SESSION['success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
                            <input type="hidden" name="class_id" value="<?php echo $class_filter; ?>">
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
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
                                                        'present' => 'success',
                                                        'absent' => 'danger',
                                                        'late' => 'warning',
                                                        'leave' => 'info'
                                                    ][$status];
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge_class; ?>">
                                                        <?php echo ucfirst($status); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <select name="attendance[<?php echo $student['id']; ?>]" 
                                                            class="form-select form-select-sm status-select">
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
                                    <i class="bi bi-save"></i> Save Attendance
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="printAttendance()">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Upcoming Holidays -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6><i class="bi bi-calendar-event"></i> Upcoming Holidays</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php if(count($holidays) > 0): ?>
                                <?php foreach($holidays as $holiday): ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="card border-info">
                                            <div class="card-body py-2">
                                                <h6 class="card-title mb-1"><?php echo htmlspecialchars($holiday['holiday_name']); ?></h6>
                                                <p class="card-text mb-1">
                                                    <small class="text-muted">
                                                        <i class="bi bi-calendar"></i> 
                                                        <?php echo date('F j, Y', strtotime($holiday['holiday_date'])); ?>
                                                    </small>
                                                </p>
                                                <?php if($holiday['description']): ?>
                                                    <p class="card-text"><small><?php echo htmlspecialchars($holiday['description']); ?></small></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <p class="text-muted text-center">No upcoming holidays</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.datepicker').datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true
            });
        });

        function markAll(status) {
            $('.status-select').val(status);
        }

        function printAttendance() {
            window.print();
        }

        // Auto-save notification
        let autoSaveTimer;
        $('.status-select').change(function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                // Show saving indicator
                const savingIndicator = document.createElement('div');
                savingIndicator.className = 'alert alert-info alert-dismissible fade show position-fixed top-0 end-0 m-3';
                savingIndicator.innerHTML = `
                    <i class="bi bi-info-circle"></i> Changes detected. Remember to save!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                document.body.appendChild(savingIndicator);
                
                setTimeout(() => {
                    savingIndicator.remove();
                }, 3000);
            }, 1000);
        });
    </script>
</body>
</html>