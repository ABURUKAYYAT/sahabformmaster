<?php
/**
 * AJAX endpoint to get calendar events
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

$school_id = $_SESSION['school_id'] ?? null;

// Get month and year from request
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

try {
    ensure_calendar_events_table($pdo);

    // Get events for the specified month
    $stmt = $pdo->prepare("
        SELECT id, title, event_date, event_type, description, location, event_time, is_all_day, color
        FROM calendar_events
        WHERE MONTH(event_date) = ? AND YEAR(event_date) = ?
        AND (school_id = ? OR school_id IS NULL)
        ORDER BY event_date ASC, event_time ASC
    ");
    $stmt->execute([$month, $year, $school_id]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format events for the calendar
    $formattedEvents = [];
    foreach ($events as $event) {
        $formattedEvents[] = [
            'id' => $event['id'],
            'title' => $event['title'],
            'start' => $event['event_date'] . ($event['event_time'] && !$event['is_all_day'] ? 'T' . $event['event_time'] : ''),
            'allDay' => (bool)$event['is_all_day'],
            'type' => $event['event_type'],
            'description' => $event['description'],
            'location' => $event['location'],
            'color' => getEventColor($event['event_type'], $event['color'])
        ];
    }
    
    echo json_encode(['success' => true, 'events' => $formattedEvents]);
    
} catch (PDOException $e) {
    error_log("Get calendar events error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch events']);
}

function getEventColor($type, $customColor = null) {
    if ($customColor) {
        return $customColor;
    }
    
    $colors = [
        'academic' => '#4f46e5',    // Indigo
        'holiday' => '#ef4444',       // Red
        'exam' => '#f59e0b',          // Amber
        'sports' => '#10b981',        // Emerald
        'ceremony' => '#3b82f6',      // Blue
        'meeting' => '#8b5cf6',       // Purple
        'other' => '#6b7280'          // Gray
    ];
    
    return $colors[$type] ?? $colors['other'];
}
