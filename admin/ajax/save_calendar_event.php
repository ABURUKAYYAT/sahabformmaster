<?php
/**
 * AJAX endpoint to save (add/update) calendar events
 */

session_start();
require_once '../../config/db.php';

header('Content-Type: application/json');

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
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
$is_all_day = isset($input['is_all_day']) ? (int)$input['is_all_day'] : 1;
$color = isset($input['color']) ? $input['color'] : null;

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
