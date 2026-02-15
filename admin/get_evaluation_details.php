<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth-check.php';

// Only principal/admin with school authentication
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$current_school_id = require_school_auth();

$evaluation_id = $_GET['id'] ?? null;

if (!$evaluation_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Evaluation ID required']);
    exit;
}

try {
    // Fetch evaluation details with related data
    $stmt = $pdo->prepare("
        SELECT e.*, s.full_name, s.class_id, s.admission_no, c.class_name, u.full_name as teacher_fname
        FROM evaluations e
        JOIN students s ON e.student_id = s.id
        LEFT JOIN classes c ON e.class_id = c.id
        LEFT JOIN users u ON e.teacher_id = u.id
        WHERE e.id = ? AND e.school_id = ?
    ");
    $stmt->execute([$evaluation_id, $current_school_id]);
    $evaluation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$evaluation) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Evaluation not found']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'evaluation' => $evaluation
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error fetching evaluation details']);
    exit;
}
?>