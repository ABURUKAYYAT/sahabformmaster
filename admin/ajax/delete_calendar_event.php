<?php
/**
 * AJAX endpoint to delete calendar events
 */

session_start();
require_once '../../config/db.php';

header('Content-Type: application/json');

function ensure_calendar_events_table(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            school_id INT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            event_date DATE NOT NULL,
            event_type ENUM('academic', 'holiday', 'exam', 'sports', 'ceremony', 'meeting', 'other') NOT NULL DEFAULT 'academic',
            description TEXT NULL,
            location VARCHAR(255) NULL,
            event_time TIME NULL,
            is_all_day TINYINT(1) NOT NULL DEFAULT 0,
            color VARCHAR(20) NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_calendar_school_date (school_id, event_date),
            INDEX idx_calendar_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    ensure_calendar_events_table($pdo);
} catch (Exception $e) {
    error_log("Calendar table init error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to initialize calendar storage']);
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;
$user_id = $_SESSION['user_id'];

// Get event ID from request
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    $input = $_POST;
}

$id = isset($input['id']) ? (int)$input['id'] : null;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit;
}

try {
    // Verify the event exists and belongs to the school
    $stmt = $pdo->prepare("
        SELECT id FROM calendar_events 
        WHERE id = ? AND (school_id = ? OR school_id IS NULL)
    ");
    $stmt->execute([$id, $school_id]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Event not found']);
        exit;
    }
    
    // Delete the event
    $deleteStmt = $pdo->prepare("DELETE FROM calendar_events WHERE id = ?");
    $deleteStmt->execute([$id]);
    
    if ($deleteStmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Failed to delete event']);
        exit;
    }
    
    // Log the action
    error_log("Calendar event {$id} deleted by user {$user_id}");
    
    echo json_encode([
        'success' => true, 
        'message' => 'Event deleted successfully'
    ]);
    
} catch (PDOException $e) {
    error_log("Delete calendar event error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to delete event']);
}
