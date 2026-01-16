<?php
require_once '../auth_check.php';
require_once '../../config/db.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit;
}

$user_id = (int)$_GET['id'];

try {
    $stmt = $pdo->prepare("
        SELECT u.*,
               s.school_name,
               0 as recent_activity
        FROM users u
        LEFT JOIN schools s ON u.school_id = s.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Don't include password in response
    unset($user['password']);

    echo json_encode([
        'success' => true,
        'user' => $user
    ]);

} catch (Exception $e) {
    error_log("Error fetching user details: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
