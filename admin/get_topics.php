<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow principal (admin)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$current_school_id = require_school_auth();

$curriculum_id = intval($_GET['id'] ?? 0);
if ($curriculum_id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid curriculum id']);
    exit;
}

$stmt = $pdo->prepare("SELECT topics FROM curriculum WHERE id = :id AND school_id = :school_id");
$stmt->execute(['id' => $curriculum_id, 'school_id' => $current_school_id]);
$topics_string = $stmt->fetchColumn();

$topics = [];
if (!empty($topics_string)) {
    $topics = array_values(array_filter(array_map('trim', explode("\n", $topics_string))));
}

header('Content-Type: application/json');
echo json_encode(['topics' => $topics]);
