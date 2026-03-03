<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'teacher') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$current_school_id = require_school_auth();
$teacher_id = intval($_SESSION['user_id']);
$student_id = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$term = trim($_GET['term'] ?? '');

if (!$student_id || !$class_id || $term === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM subject_assignments sa
        JOIN classes c ON sa.class_id = c.id
        WHERE sa.teacher_id = :teacher_id
          AND sa.class_id = :class_id
          AND c.school_id = :school_id
    ");
    $stmt->execute([
        'teacher_id' => $teacher_id,
        'class_id' => $class_id,
        'school_id' => $current_school_id
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT r.subject_id, r.first_ca, r.second_ca, r.exam, r.academic_session
        FROM results r
        JOIN students st ON r.student_id = st.id
        JOIN subjects sub ON r.subject_id = sub.id
        WHERE r.student_id = :student_id
          AND r.term = :term
          AND r.school_id = :school_id
          AND st.class_id = :class_id
          AND st.school_id = :school_id_student
          AND sub.school_id = :school_id_subject
        ORDER BY sub.subject_name ASC
    ");
    $stmt->execute([
        'student_id' => $student_id,
        'term' => $term,
        'school_id' => $current_school_id,
        'class_id' => $class_id,
        'school_id_student' => $current_school_id,
        'school_id_subject' => $current_school_id
    ]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $academic_session = !empty($results) ? $results[0]['academic_session'] : null;

    echo json_encode([
        'success' => true,
        'results' => $results,
        'academic_session' => $academic_session
    ]);
} catch (PDOException $e) {
    error_log('teacher/ajax_get_student_results.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
