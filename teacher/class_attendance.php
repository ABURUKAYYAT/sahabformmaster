<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'principal'])) {
    header('Location: login.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$current_date = date('Y-m-d');
$selected_date = isset($_GET['date']) ? $_GET['date'] : $current_date;

// Get teacher's assigned classes
$assigned_classes_sql = "SELECT c.id, c.class_name 
                        FROM class_teachers ct 
                        JOIN classes c ON ct.class_id = c.id 
                        WHERE ct.teacher_id = :teacher_id";
$assigned_stmt = $pdo->prepare($assigned_classes_sql);
$assigned_stmt->execute([':teacher_id' => $teacher_id]);
$assigned_classes = $assigned_stmt->fetchAll();

$assigned_class_ids = array_column($assigned_classes, 'id');
$assigned_classes_map = array_column($assigned_classes, 'class_name', 'id');

if (empty($assigned_class_ids)) {
    die("No classes assigned to you.");
}

$selected_class = isset($_GET['class_id']) && in_array($_GET['class_id'], $assigned_class_ids) 
    ? $_GET['class_id'] 
    : $assigned_class_ids[0];

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_date = $_POST['attendance_date'];
    $class_id = $_POST['class_id'];
    
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST['attendance'] as $student_id => $status) {
            $remarks = $_POST['remarks'][$student_id] ?? '';
            
            $sql = "INSERT INTO attendance (student_id, date, status, recorded_by, notes) 
                    VALUES (:student_id, :date, :status, :recorded_by, :notes)
                    ON DUPLICATE KEY UPDATE status = :status_update, recorded_by = :recorded_by_update, notes = :notes_update";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':student_id' => $student_id,
                ':date' => $attendance_date,
                ':status' => $status,
                ':recorded_by' => $teacher_id,
                ':notes' => $remarks,
                ':status_update' => $status,
                ':recorded_by_update' => $teacher_id,
                ':notes_update' => $remarks
            ]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Attendance submitted successfully!";
        header("Location: class_attendance.php?date=$attendance_date&class_id=$class_id");
        exit();
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error submitting attendance: " . $e->getMessage();
    }
}

// Fetch students for selected class
$students_sql = "SELECT s.id, s.full_name, s.admission_no, a.status, a.notes 
                FROM students s 
                LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :selected_date 
                WHERE s.class_id = :class_id 
                ORDER BY s.full_name";
$students_stmt = $pdo->prepare($students_sql);
$students_stmt->execute([
    ':selected_date' => $selected_date,
    ':class_id' => $selected_class
]);
$students = $students_stmt->fetchAll();

// Fetch class attendance statistics
$stats_sql = "SELECT 
    COUNT(DISTINCT s.id) as total_students,
    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count
    FROM students s 
    LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :selected_date 
    WHERE s.class_id = :class_id";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([
    ':selected_date' => $selected_date,
    ':class_id' => $selected_class
]);
$stats = $stats_stmt->fetch();

// Fetch monthly attendance summary
$monthly_sql = "SELECT 
    DATE_FORMAT(date, '%Y-%m') as month,
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
    AVG(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100 as attendance_rate
    FROM attendance 
    WHERE student_id IN (SELECT id FROM students WHERE class_id = :class_id)
    AND date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    GROUP BY DATE_FORMAT(date, '%Y-%m')
    ORDER BY month DESC";
$monthly_stmt = $pdo->prepare($monthly_sql);
$monthly_stmt->execute([':class_id' => $selected_class]);
$monthly_data = $monthly_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Attendance - Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css" rel="stylesheet">
    <style>
        .quick-action-btn {
            width: 100%;
            margin-bottom: 5px;
        }
        .attendance-badge {
            cursor: pointer;
        }
        .student-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .status-indicator {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .status-present { background-color: #28a745; }
        .status-absent { background-color: #dc3545; }
        .status-late { background-color: #ffc107; }
        .status-leave { background-color: #17a2b8; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-success">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="bi bi-people-fill"></i> Class Attendance
            </a>
            <div class="navbar-nav ms-auto">
                <span class="nav-link">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                <a href="dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="logout.php" class="nav-link"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Left Sidebar - Quick Actions -->
            <div class="col-md-3">
                <!-- Date and Class Selection -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6><i class="bi bi-funnel"></i> Filters</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Date</label>
                                <input type="text" class="form-control datepicker" name="date" 
                                       value="<?php echo $selected_date; ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Class</label>
                                <select name="class_id" class="form-select" onchange="this.form.submit()">
                                    <?php foreach($assigned_classes_map as $id => $name): ?>
                                        <option value="<?php echo $id; ?>" 
                                            <?php echo ($selected_class == $id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-search"></i> Load
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6><i class="bi bi-lightning"></i> Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-outline-success quick-action-btn" onclick="markAllStatus('present')">
                            <i class="bi bi-check-circle"></i> Mark All Present
                        </button>
                        <button class="btn btn-outline-danger quick-action-btn" onclick="markAllStatus('absent')">
                            <i class="bi bi-x-circle"></i> Mark All Absent
                        </button>
                        <button class="btn btn-outline-warning quick-action-btn" onclick="markAllStatus('late')">
                            <i class="bi bi-clock"></i> Mark All Late
                        </button>
                        <hr>
                        <button class="btn btn-primary quick-action-btn" onclick="submitAttendance()">
                            <i class="bi bi-save"></i> Save Attendance
                        </button>
                        <button class="btn btn-info quick-action-btn" onclick="printAttendance()">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </div>
                </div>

                <!-- Today's Statistics -->
                <div class="card">
                    <div class="card-header">
                        <h6><i class="bi bi-bar-chart"></i> Today's Stats</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Total Students:</span>
                            <strong><?php echo $stats['total_students']; ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span><span class="status-indicator status-present"></span> Present:</span>
                            <strong class="text-success"><?php echo $stats['present_count']; ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span><span class="status-indicator status-absent"></span> Absent:</span>
                            <strong class="text-danger"><?php echo $stats['absent_count']; ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span><span class="status-indicator status-late"></span> Late:</span>
                            <strong class="text-warning"><?php echo $stats['late_count']; ?></strong>
                        </div>
                        <?php 
                        $attendance_rate = ($stats['total_students'] > 0) 
                            ? round(($stats['present_count'] / $stats['total_students']) * 100, 1) 
                            : 0;
                        ?>
                        <div class="progress mt-3" style="height: 20px;">
                            <div class="progress-bar bg-success" role="progressbar" 
                                 style="width: <?php echo $attendance_rate; ?>%" 
                                 aria-valuenow="<?php echo $attendance_rate; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <?php echo $attendance_rate; ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9">
                <!-- Attendance Form -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>
                            <i class="bi bi-clipboard-data"></i> 
                            <?php echo htmlspecialchars($assigned_classes_map[$selected_class]); ?> - 
                            <?php echo date('F j, Y', strtotime($selected_date)); ?>
                        </h5>
                        <div>
                            <span class="badge bg-<?php echo ($attendance_rate >= 80) ? 'success' : 'danger'; ?>">
                                Attendance: <?php echo $attendance_rate; ?>%
                            </span>
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

                        <form method="POST" action="" id="attendanceForm">
                            <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
                            <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="15%">Admission No</th>
                                            <th width="25%">Student Name</th>
                                            <th width="15%">Status</th>
                                            <th width="25%">Remarks</th>
                                            <th width="15%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; ?>
                                        <?php foreach($students as $student): ?>
                                            <?php 
                                            $current_status = $student['status'] ?: 'absent';
                                            $status_color = [
                                                'present' => 'success',
                                                'absent' => 'danger',
                                                'late' => 'warning',
                                                'leave' => 'info'
                                            ][$current_status];
                                            ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
                                                <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button" class="btn btn-outline-success status-btn <?php echo ($current_status == 'present') ? 'active' : ''; ?>" 
                                                                data-student="<?php echo $student['id']; ?>" 
                                                                data-status="present">
                                                            <i class="bi bi-check"></i> P
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger status-btn <?php echo ($current_status == 'absent') ? 'active' : ''; ?>" 
                                                                data-student="<?php echo $student['id']; ?>" 
                                                                data-status="absent">
                                                            <i class="bi bi-x"></i> A
                                                        </button>
                                                        <button type="button" class="btn btn-outline-warning status-btn <?php echo ($current_status == 'late') ? 'active' : ''; ?>" 
                                                                data-student="<?php echo $student['id']; ?>" 
                                                                data-status="late">
                                                            <i class="bi bi-clock"></i> L
                                                        </button>
                                                        <button type="button" class="btn btn-outline-info status-btn <?php echo ($current_status == 'leave') ? 'active' : ''; ?>" 
                                                                data-student="<?php echo $student['id']; ?>" 
                                                                data-status="leave">
                                                            <i class="bi bi-envelope"></i> LV
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="attendance[<?php echo $student['id']; ?>]" 
                                                           id="status_<?php echo $student['id']; ?>" 
                                                           value="<?php echo $current_status; ?>">
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm" 
                                                           name="remarks[<?php echo $student['id']; ?>]" 
                                                           value="<?php echo htmlspecialchars($student['notes'] ?? ''); ?>" 
                                                           placeholder="Optional remarks">
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                            onclick="showStudentHistory(<?php echo $student['id']; ?>)">
                                                        <i class="bi bi-clock-history"></i> History
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                                <button type="submit" name="submit_attendance" class="btn btn-primary btn-lg">
                                    <i class="bi bi-save"></i> Submit Attendance
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Monthly Summary -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6><i class="bi bi-calendar-month"></i> Monthly Attendance Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Total Days</th>
                                        <th>Present Days</th>
                                        <th>Attendance Rate</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($monthly_data as $month): ?>
                                        <tr>
                                            <td><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                                            <td><?php echo $month['total_days']; ?></td>
                                            <td><?php echo $month['present_days']; ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1" style="height: 10px;">
                                                        <div class="progress-bar bg-<?php echo ($month['attendance_rate'] >= 75) ? 'success' : 'danger'; ?>" 
                                                             style="width: <?php echo $month['attendance_rate']; ?>%"></div>
                                                    </div>
                                                    <span class="ms-2"><?php echo round($month['attendance_rate'], 1); ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo ($month['attendance_rate'] >= 75) ? 'success' : 'danger'; ?>">
                                                    <?php echo ($month['attendance_rate'] >= 75) ? 'Good' : 'Needs Improvement'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Student History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Attendance History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="historyContent">
                    Loading...
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

            // Status button click handler
            $('.status-btn').click(function() {
                const studentId = $(this).data('student');
                const status = $(this).data('status');
                
                // Update hidden input
                $('#status_' + studentId).val(status);
                
                // Update button active state
                $(this).closest('.btn-group').find('.status-btn').removeClass('active');
                $(this).addClass('active');
            });
        });

        function markAllStatus(status) {
            $('.status-btn[data-status="' + status + '"]').click();
        }

        function submitAttendance() {
            if (confirm('Are you sure you want to submit attendance for today?')) {
                $('#attendanceForm').submit();
            }
        }

        function printAttendance() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Attendance Report - <?php echo htmlspecialchars($assigned_classes_map[$selected_class]); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .header { text-align: center; margin-bottom: 20px; }
                        .footer { margin-top: 30px; font-size: 12px; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>Sahab Academy</h2>
                        <h3>Attendance Report</h3>
                        <p>Class: <?php echo htmlspecialchars($assigned_classes_map[$selected_class]); ?></p>
                        <p>Date: <?php echo date('F j, Y', strtotime($selected_date)); ?></p>
                    </div>
                    ${document.querySelector('.table-responsive').outerHTML}
                    <div class="footer">
                        <p>Generated on: ${new Date().toLocaleString()}</p>
                        <p>Teacher: <?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        function showStudentHistory(studentId) {
            $('#historyContent').html('<div class="text-center"><div class="spinner-border"></div></div>');
            
            $.ajax({
                url: 'ajax/get_student_history.php',
                method: 'POST',
                data: { student_id: studentId },
                success: function(response) {
                    $('#historyContent').html(response);
                    $('#historyModal').modal('show');
                }
            });
        }
    </script>
</body>
</html>