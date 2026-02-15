<?php
session_start();

// Include database configuration
require_once '../config/db.php';

// Log logout activity
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO access_logs (user_id, action, resource_type, status, ip_address, user_agent, message) VALUES (?, 'logout', 'system', 'success', ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'Super admin logged out'
        ]);
    } catch (Exception $e) {
        error_log("Failed to log logout: " . $e->getMessage());
    }
}

// Clear all session data
session_unset();
session_destroy();

// Redirect to login page
header('Location: login.php?logout=1');
exit;
?>
