<?php
// get_calendar_activities.php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode([]);
    exit();
}

$student_id = $_SESSION['student_id'];

// Get student's class
$student_query = "SELECT class_id FROM students WHERE id = ?";
$student_stmt = $pdo->prepare($student_query);
$student_stmt->execute([$student_id]);
$student = $student_stmt->fetch();

if (!$student) {
    echo json_encode([]);
    exit();
}

// Get activities with due dates
$query = "
    SELECT ca.id as activity_id, ca.title, ca.activity_type as type, ca.due_date,
           s.subject_name 
    FROM class_activities ca 
    JOIN subjects s ON ca.subject_id = s.id 
    WHERE ca.class_id = ? 
    AND ca.status = 'published'
    AND ca.due_date IS NOT NULL
    ORDER BY ca.due_date ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$student['class_id']]);
$activities = $stmt->fetchAll();

$events = [];
foreach ($activities as $row) {
    $events[] = [
        'title' => $row['title'] . ' (' . $row['subject_name'] . ')',
        'start' => $row['due_date'],
        'end' => $row['due_date'],
        'color' => getColorByType($row['type']),
        'activity_id' => $row['activity_id'],
        'type' => $row['type']
    ];
}

echo json_encode($events);

function getColorByType($type) {
    $colors = [
        'classwork' => '#198754',
        'assignment' => '#0dcaf0',
        'quiz' => '#ffc107',
        'project' => '#6f42c1',
        'homework' => '#fd7e14'
    ];
    return $colors[$type] ?? '#0d6efd';
}
