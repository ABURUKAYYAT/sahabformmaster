<?php
session_start();
require_once '../config/db.php';

if (isset($_POST['date']) && isset($_POST['student_id'])) {
    $date = $_POST['date'];
    $student_id = $_POST['student_id'];
    
    $sql = "SELECT a.*, u.full_name as recorded_by, 
                   s.full_name as student_name, c.class_name
            FROM attendance a
            LEFT JOIN users u ON a.recorded_by = u.id
            JOIN students s ON a.student_id = s.id
            JOIN classes c ON s.class_id = c.id
            WHERE a.student_id = :student_id AND a.date = :date";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':student_id' => $student_id,
        ':date' => $date
    ]);
    $attendance = $stmt->fetch();
    
    if ($attendance) {
        $status_badge = [
            'present' => ['class' => 'success', 'icon' => 'check-circle'],
            'absent' => ['class' => 'danger', 'icon' => 'x-circle'],
            'late' => ['class' => 'warning', 'icon' => 'clock'],
            'leave' => ['class' => 'info', 'icon' => 'envelope']
        ][$attendance['status']];
        
        echo '<div class="text-center mb-3">';
        echo '<h4>' . date('l, F j, Y', strtotime($date)) . '</h4>';
        echo '</div>';
        
        echo '<div class="row">';
        echo '<div class="col-md-6">';
        echo '<p><strong>Student:</strong> ' . htmlspecialchars($attendance['student_name']) . '</p>';
        echo '<p><strong>Class:</strong> ' . htmlspecialchars($attendance['class_name']) . '</p>';
        echo '</div>';
        echo '<div class="col-md-6 text-end">';
        echo '<span class="badge bg-' . $status_badge['class'] . ' p-2">';
        echo '<i class="bi bi-' . $status_badge['icon'] . '"></i> ';
        echo ucfirst($attendance['status']);
        echo '</span>';
        echo '</div>';
        echo '</div>';
        
        if ($attendance['notes']) {
            echo '<div class="alert alert-info mt-3">';
            echo '<strong>Remarks:</strong> ' . htmlspecialchars($attendance['notes']);
            echo '</div>';
        }
        
        echo '<div class="mt-3 text-muted">';
        echo '<small>';
        echo '<strong>Recorded by:</strong> ' . htmlspecialchars($attendance['recorded_by'] ?? 'System');
        if ($attendance['recorded_at']) {
            echo ' at ' . date('h:i A', strtotime($attendance['recorded_at']));
        }
        echo '</small>';
        echo '</div>';
    } else {
        // Check if it's a holiday
        $holiday_sql = "SELECT * FROM holidays WHERE holiday_date = :date";
        $holiday_stmt = $pdo->prepare($holiday_sql);
        $holiday_stmt->execute([':date' => $date]);
        $holiday = $holiday_stmt->fetch();
        
        if ($holiday) {
            echo '<div class="text-center">';
            echo '<i class="bi bi-calendar-event text-warning" style="font-size: 3rem;"></i>';
            echo '<h4 class="mt-3">' . htmlspecialchars($holiday['holiday_name']) . '</h4>';
            echo '<p>' . htmlspecialchars($holiday['description']) . '</p>';
            echo '</div>';
        } else {
            echo '<p class="text-muted text-center">No attendance recorded for this date.</p>';
        }
    }
}
?>