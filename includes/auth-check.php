<?php
// Universal logging function for all user types
function log_user_action($action, $resource_type = null, $resource_id = null, $details = null) {
    global $pdo;

    if (!isset($_SESSION['user_id'])) return;

    $user_role = $_SESSION['role'] ?? 'unknown';
    $school_id = $_SESSION['school_id'] ?? null;

    try {
        $stmt = $pdo->prepare("INSERT INTO access_logs (user_id, school_id, action, resource_type, resource_id, status, ip_address, user_agent, message) VALUES (?, ?, ?, ?, ?, 'success', ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $school_id,
            $action,
            $resource_type,
            $resource_id,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $details
        ]);
    } catch (Exception $e) {
        error_log("Failed to log user action: " . $e->getMessage());
    }
}

// Role-specific logging functions
function log_admin_action($action, $resource_type = null, $resource_id = null, $details = null) {
    log_user_action($action, $resource_type, $resource_id, $details);
}

function log_teacher_action($action, $resource_type = null, $resource_id = null, $details = null) {
    log_user_action($action, $resource_type, $resource_id, $details);
}

function log_student_action($action, $resource_type = null, $resource_id = null, $details = null) {
    log_user_action($action, $resource_type, $resource_id, $details);
}
?>
