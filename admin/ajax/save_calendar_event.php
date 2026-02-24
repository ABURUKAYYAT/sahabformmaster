<?php
/**
 * AJAX endpoint to save (add/update) calendar events
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    // Try regular POST
    $input = $_POST;
}

$id = isset($input['id']) ? (int)$input['id'] : null;
$title = isset($input['title']) ? trim($input['title']) : '';
$event_date = isset($input['event_date']) ? $input['event_date'] : '';
$event_type = isset($input['event_type']) ? $input['event_type'] : 'academic';
$description = isset($input['description']) ? $input['description'] : null;
$location = isset($input['location']) ? $input['location'] : null;
$event_time = isset($input['event_time']) ? $input['event_time'] : null;
$is_all_day = !empty($input['is_all_day']) ? 1 : 0;
$color = isset($input['color']) ? $input['color'] : null;

// Normalize school_id to avoid FK violations
if (empty($school_id) || !is_numeric($school_id)) {
    $school_id = null;
} else {
    $school_id = (int)$school_id;
    $checkSchool = $pdo->prepare("SELECT COUNT(*) FROM schools WHERE id = ?");
    $checkSchool->execute([$school_id]);
    if ($checkSchool->fetchColumn() == 0) {
        $school_id = null;
    }
}

// Normalize event time when all-day
if ($event_time === '') {
    $event_time = null;
}
if ($is_all_day === 1) {
    $event_time = null;
}

// Validation
if (empty($title)) {
    echo json_encode(['success' => false, 'message' => 'Event title is required']);
    exit;
}

if (empty($event_date)) {
    echo json_encode(['success' => false, 'message' => 'Event date is required']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

// Validate event type
$validTypes = ['academic', 'holiday', 'exam', 'sports', 'ceremony', 'meeting', 'other'];
if (!in_array($event_type, $validTypes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid event type']);
    exit;
}

try {
    if ($id) {
        // Update existing event
        $stmt = $pdo->prepare("
            UPDATE calendar_events
            SET title = ?, event_date = ?, event_type = ?, description = ?, 
                location = ?, event_time = ?, is_all_day = ?, color = ?,
                updated_at = NOW()
            WHERE id = ? AND (school_id = ? OR school_id IS NULL)
        ");
        $stmt->execute([$title, $event_date, $event_type, $description, $location, 
                        $event_time, $is_all_day, $color, $id, $school_id]);
        
        if ($stmt->rowCount() === 0 && !$pdo->lastInsertId()) {
            // Check if event exists
            $checkStmt = $pdo->prepare("SELECT id FROM calendar_events WHERE id = ?");
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Event not found']);
                exit;
            }
        }
        
        $message = 'Event updated successfully';
    } else {
        // Insert new event
        $stmt = $pdo->prepare("
            INSERT INTO calendar_events 
            (school_id, title, event_date, event_type, description, location, event_time, is_all_day, color, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$school_id, $title, $event_date, $event_type, $description, 
                        $location, $event_time, $is_all_day, $color, $user_id]);
        
        $id = $pdo->lastInsertId();
        $message = 'Event added successfully';
    }
    
    // Log the action
    error_log("Calendar event {$id} saved by user {$user_id}");
    
    echo json_encode([
        'success' => true, 
        'message' => $message,
        'event_id' => $id
    ]);
    
} catch (PDOException $e) {
    error_log("Save calendar event error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save event']);
}
