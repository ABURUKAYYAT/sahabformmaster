<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    http_response_code(403);
    exit('Access denied.');
}

$school_id = require_school_auth();
$request_id = (int)($_GET['request_id'] ?? 0);
if ($request_id <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}

$stmt = $pdo->prepare("
    SELECT p.proof_file_path
    FROM school_subscription_requests r
    JOIN school_subscription_payment_proofs p ON p.request_id = r.id
    WHERE r.id = ? AND r.school_id = ?
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT 1
");
$stmt->execute([$request_id, $school_id]);
$path = $stmt->fetchColumn();
if (!$path) {
    http_response_code(404);
    exit('Proof not found.');
}

$full = realpath(__DIR__ . '/../' . ltrim($path, '/\\'));
$base = realpath(__DIR__ . '/../uploads/subscriptions');
if (!$full || !$base || strpos($full, $base) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit('File unavailable.');
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mime_map = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'pdf' => 'application/pdf'
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($full) . '"');
header('Content-Length: ' . filesize($full));
readfile($full);
exit;
?>
