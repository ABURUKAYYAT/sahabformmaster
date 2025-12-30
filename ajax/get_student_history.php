<?php
session_start();
require_once '../config/db.php';

if (isset($_POST['student_id'])) {
    $student_id = $_POST['student_id'];
    
    $sql = "SELECT s.full_name, s.admission_no, c.class_name, 
                   a.date, a.status, a.notes, a.recorded_at,
                   u.full_name as recorded_by
            FROM students s
            JOIN classes c ON s.class_id = c.id
            LEFT JOIN attendance a ON s.id = a.student_id
            LEFT JOIN users u ON a.recorded_by = u.id
            WHERE s.id = :student_id
            ORDER BY a.date DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':student_id' => $student_id]);
    $records = $stmt->fetchAll();
    
    if (count($records) > 0) {
        echo '<table class="table table-sm">';
        echo '<thead><tr><th>Date</th><th>Status</th><th>Remarks</th><th>Recorded By</th></tr></thead>';
        echo '<tbody>';
        foreach($records as $row) {
            $badge_class = [
                'present' => 'success',
                'absent' => 'danger',
                'late' => 'warning',
                'leave' => 'info'
            ][$row['status']];
            
            echo '<tr>';
            echo '<td>' . date('M j, Y', strtotime($row['date'])) . '</td>';
            echo '<td><span class="badge bg-' . $badge_class . '">' . ucfirst($row['status']) . '</span></td>';
            echo '<td>' . htmlspecialchars($row['notes'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['recorded_by'] ?? 'System') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="text-muted">No attendance records found.</p>';
    }
}
?>