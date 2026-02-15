<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'principal', 'vice_principal'])) {
    echo '<div class="alert alert-danger">Access denied.</div>';
    exit;
}

$current_school_id = get_current_school_id();
$student_id = $_POST['student_id'] ?? 0;

if (!$student_id) {
    echo '<div class="alert alert-danger">Invalid student ID.</div>';
    exit;
}

// Get student details
$student_sql = "SELECT s.*, c.class_name 
                FROM students s 
                JOIN classes c ON s.class_id = c.id 
                WHERE s.id = :id AND s.school_id = :school_id";
$student_stmt = $pdo->prepare($student_sql);
$student_stmt->execute([':id' => $student_id, ':school_id' => $current_school_id]);
$student = $student_stmt->fetch();

if (!$student) {
    echo '<div class="alert alert-danger">Student not found.</div>';
    exit;
}

// Get attendance statistics (last 3 months)
$stats_sql = "SELECT 
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days
    FROM attendance 
    WHERE student_id = :id 
    AND date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([':id' => $student_id]);
$stats = $stats_stmt->fetch();

// Get monthly breakdown
$monthly_sql = "SELECT 
    DATE_FORMAT(date, '%Y-%m') as month,
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days
    FROM attendance 
    WHERE student_id = :id 
    AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(date, '%Y-%m')
    ORDER BY month DESC";
$monthly_stmt = $pdo->prepare($monthly_sql);
$monthly_stmt->execute([':id' => $student_id]);
$monthly_data = $monthly_stmt->fetchAll();

// Get recent attendance records
$recent_sql = "SELECT * FROM attendance 
               WHERE student_id = :id 
               ORDER BY date DESC LIMIT 20";
$recent_stmt = $pdo->prepare($recent_sql);
$recent_stmt->execute([':id' => $student_id]);
$recent_records = $recent_stmt->fetchAll();

// Calculate attendance rate
$attendance_rate = ($stats['total_days'] > 0) 
    ? round(($stats['present_days'] / $stats['total_days']) * 100, 1) 
    : 0;
?>

<div class="student-profile">
    <!-- Student Header -->
    <div class="d-flex align-items-center mb-4 p-3 bg-light rounded">
        <div class="student-avatar me-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
            <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
        </div>
        <div>
            <h5 class="mb-0"><?php echo htmlspecialchars($student['full_name']); ?></h5>
            <small class="text-muted">
                <?php echo htmlspecialchars($student['admission_no']); ?> | 
                <?php echo htmlspecialchars($student['class_name']); ?>
            </small>
        </div>
        <div class="ms-auto">
            <span class="badge <?php echo $attendance_rate >= 80 ? 'bg-success' : ($attendance_rate >= 60 ? 'bg-warning' : 'bg-danger'); ?>" 
                  style="font-size: 1rem; padding: 0.5rem 1rem;">
                <?php echo $attendance_rate; ?>% Rate
            </span>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-2">
            <div class="stat-card text-center">
                <h3 class="mb-0 text-success"><?php echo $stats['present_days'] ?? 0; ?></h3>
                <small class="text-muted">Present Days</small>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="stat-card text-center">
                <h3 class="mb-0 text-danger"><?php echo $stats['absent_days'] ?? 0; ?></h3>
                <small class="text-muted">Absent Days</small>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="stat-card text-center">
                <h3 class="mb-0 text-warning"><?php echo $stats['late_days'] ?? 0; ?></h3>
                <small class="text-muted">Late Arrivals</small>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="stat-card text-center">
                <h3 class="mb-0 text-primary"><?php echo $stats['leave_days'] ?? 0; ?></h3>
                <small class="text-muted">Leave Days</small>
            </div>
        </div>
    </div>
    
    <!-- Monthly Breakdown Chart -->
    <div class="mb-4">
        <h6 class="mb-3">Monthly Attendance Trend</h6>
        <div style="height: 200px;">
            <canvas id="studentTrendChart"></canvas>
        </div>
    </div>
    
    <!-- Recent Records -->
    <div class="mb-3">
        <h6 class="mb-3">Recent Attendance Records</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($recent_records)): ?>
                        <?php foreach ($recent_records as $record): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($record['date'])); ?></td>
                                <td>
                                    <span class="badge <?php 
                                        echo $record['status'] == 'present' ? 'bg-success' : 
                                            ($record['status'] == 'absent' ? 'bg-danger' : 
                                            ($record['status'] == 'late' ? 'bg-warning' : 'bg-primary')); 
                                    ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($record['notes'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted">No records found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Initialize student trend chart
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('studentTrendChart');
    if (ctx) {
        const months = <?php echo json_encode(array_reverse(array_column($monthly_data, 'month'))); ?>;
        const rates = <?php echo json_encode(array_map(function($m) use ($stats_stmt) {
            return ($m['total_days'] > 0) ? round(($m['present_days'] / $m['total_days']) * 100, 1) : 0;
        }, array_reverse($monthly_data))); ?>;
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Attendance Rate',
                    data: rates,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 0,
                        max: 100
                    }
                }
            }
        });
    }
});
</script>
