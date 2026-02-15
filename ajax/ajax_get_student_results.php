<?php
session_start();
require_once '../config/db.php';

// Only allow authenticated users
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$student_id = $_GET['student_id'] ?? null;
$term = $_GET['term'] ?? null;
$class_id = $_GET['class_id'] ?? null;

// Validate inputs
if (!$student_id || !$term || !$class_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    // Fetch results for the student, term, and ensure student belongs to the class
    $stmt = $pdo->prepare("
        SELECT r.*, s.subject_name
        FROM results r
        JOIN subjects s ON r.subject_id = s.id
        JOIN students st ON r.student_id = st.id
        WHERE r.student_id = :student_id
        AND r.term = :term
        AND st.class_id = :class_id
        ORDER BY s.subject_name
    ");
    $stmt->execute([
        'student_id' => $student_id,
        'term' => $term,
        'class_id' => $class_id
    ]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get academic session from one of the results (assuming all have the same session)
    $academic_session = !empty($results) ? $results[0]['academic_session'] : null;

    echo json_encode([
        'success' => true,
        'results' => $results,
        'academic_session' => $academic_session
    ]);

} catch (PDOException $e) {
    error_log('Database error in ajax_get_student_results.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
