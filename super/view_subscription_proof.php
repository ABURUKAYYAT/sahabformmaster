<?php
require_once 'auth_check.php';
require_once '../config/db.php';

$request_id = (int)($_GET['request_id'] ?? 0);
if ($request_id <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}

$stmt = $pdo->prepare("
    SELECT p.proof_file_path
    FROM school_subscription_requests r
    JOIN school_subscription_payment_proofs p ON p.request_id = r.id
    WHERE r.id = ?
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT 1
");
$stmt->execute([$request_id]);
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
