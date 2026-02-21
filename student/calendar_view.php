<?php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) && !isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$student_id = isset($_SESSION['student_id']) ? $_SESSION['student_id'] : $_SESSION['user_id'];

// Get student details for filtering
$student_stmt = $pdo->prepare("
    SELECT s.*, c.class_name 
    FROM students s 
    LEFT JOIN classes c ON s.class_id = c.id 
    WHERE s.id = ? OR s.user_id = ?
");
$student_stmt->execute([$student_id, $student_id]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

// Get current month and year
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Validate month and year
if ($month < 1 || $month > 12) $month = date('n');
if ($year < 2000 || $year > 2100) $year = date('Y');

// Get events for the month
$first_day = date('Y-m-01', strtotime("$year-$month-01"));
$last_day = date('Y-m-t', strtotime("$year-$month-01"));

$events_query = "
    SELECT sd.*, ac.category_name, ac.color 
    FROM school_diary sd 
    LEFT JOIN activity_categories ac ON sd.category_id = ac.id 
    WHERE (sd.target_audience = 'All' 
           OR sd.target_audience = 'Secondary Only' 
           OR (sd.target_audience = 'Specific Classes' AND FIND_IN_SET(?, REPLACE(sd.target_classes, ', ', ','))))
    AND sd.activity_date BETWEEN ? AND ?
    AND sd.status != 'Cancelled'
    ORDER BY sd.activity_date, sd.start_time";

$events_stmt = $pdo->prepare($events_query);
$events_stmt->execute([$student['class_name'] ?: '', $first_day, $last_day]);
$events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group events by date
$events_by_date = [];
foreach ($events as $event) {
    $date = $event['activity_date'];
    if (!isset($events_by_date[$date])) {
        $events_by_date[$date] = [];
    }
    $events_by_date[$date][] = $event;
}

// Calculate calendar
$first_day_of_month = date('w', strtotime($first_day)); // 0 = Sunday, 1 = Monday, etc.
$days_in_month = date('t', strtotime($first_day));
$today = date('Y-m-d');

// Previous and next month navigation
$prev_month = $month == 1 ? 12 : $month - 1;
$prev_year = $month == 1 ? $year - 1 : $year;
$next_month = $month == 12 ? 1 : $month + 1;
$next_year = $month == 12 ? $year + 1 : $year;
?>

<!DOCTYPE html>
<html lang="en">
<head>`r`n<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar View - Sahab Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #6366f1;
            --secondary-color: #8b5cf6;
        }
        
        body {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .calendar-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
        }
        
        .calendar-nav {
            background: white;
            padding: 1rem;
            border-radius: 0 0 15px 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #dee2e6;
        }
        
        .calendar-day-header {
            background: #f8f9fa;
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            color: #495057;
        }
        
        .calendar-day {
            background: white;
            min-height: 120px;
            padding: 10px;
            position: relative;
        }
        
        .calendar-day.today {
            background: #e7f5ff;
            border: 2px solid var(--primary-color);
        }
        
        .calendar-day.other-month {
            background: #f8f9fa;
            color: #adb5bd;
        }
        
        .day-number {
            font-weight: bold;
            margin-bottom: 5px;
            color: #495057;
        }
        
        .event-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 4px;
        }
        
        .event-list {
            margin-top: 5px;
        }
        
        .event-item {
            font-size: 0.75rem;
            padding: 3px 6px;
            margin-bottom: 3px;
            border-radius: 4px;
            cursor: pointer;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .event-item:hover {
            opacity: 0.9;
        }
        
        .month-selector .btn {
            border-radius: 25px;
            padding: 8px 20px;
        }
        
        .view-toggle .btn {
            border-radius: 25px;
        }
        
        @media (max-width: 768px) {
            .calendar-day {
                min-height: 80px;
                padding: 5px;
                font-size: 0.875rem;
            }
            
            .event-item {
                font-size: 0.7rem;
                padding: 2px 4px;
            }
        }
        
        @media (max-width: 576px) {
            .calendar-grid {
                grid-template-columns: repeat(1, 1fr);
            }
            
            .calendar-day-header {
                display: none;
            }
            
            .calendar-day {
                min-height: auto;
                padding: 1rem;
                border-bottom: 1px solid #dee2e6;
            }
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-calendar-week me-2"></i>Event Calendar</h1>
            <div>
                <a href="school_diary.php" class="btn btn-primary">
                    <i class="bi bi-grid me-1"></i> Grid View
                </a>
                <a href="dashboard.php" class="btn btn-outline-secondary ms-2">
                    <i class="bi bi-house-door me-1"></i> Dashboard
                </a>
            </div>
        </div>
        
        <!-- Calendar Header -->
        <div class="calendar-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2 class="mb-0">
                        <?= date('F Y', strtotime("$year-$month-01")) ?>
                    </h2>
                    <p class="mb-0">View all school events in calendar format</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="badge bg-light text-dark p-2">
                        <i class="bi bi-person-badge me-1"></i>
                        <?= htmlspecialchars($student['class_name']) ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Calendar Navigation -->
        <div class="calendar-nav">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <a href="?month=<?= $prev_month ?>&year=<?= $prev_year ?>" class="btn btn-outline-primary">
                        <i class="bi bi-chevron-left me-1"></i> Previous
                    </a>
                </div>
                <div class="col-md-4 text-center month-selector">
                    <div class="btn-group">
                        <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-outline-secondary">
                            Today
                        </a>
                        <a href="?month=<?= $next_month ?>&year=<?= $next_year ?>" class="btn btn-outline-primary">
                            Next <i class="bi bi-chevron-right ms-1"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-4 text-end view-toggle">
                    <select class="form-select" style="max-width: 200px; display: inline-block;" onchange="window.location.href='?month=' + this.value.split('-')[1] + '&year=' + this.value.split('-')[0]">
                        <?php for ($y = $year - 2; $y <= $year + 2; $y++): ?>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $y . '-' . $m ?>" <?= $y == $year && $m == $month ? 'selected' : '' ?>>
                                    <?= date('F Y', strtotime("$y-$m-01")) ?>
                                </option>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Calendar Grid -->
        <div class="calendar-grid">
            <!-- Day Headers -->
            <div class="calendar-day-header">Sunday</div>
            <div class="calendar-day-header">Monday</div>
            <div class="calendar-day-header">Tuesday</div>
            <div class="calendar-day-header">Wednesday</div>
            <div class="calendar-day-header">Thursday</div>
            <div class="calendar-day-header">Friday</div>
            <div class="calendar-day-header">Saturday</div>
            
            <!-- Empty days for first week -->
            <?php for ($i = 0; $i < $first_day_of_month; $i++): ?>
                <div class="calendar-day other-month"></div>
            <?php endfor; ?>
            
            <!-- Days of the month -->
            <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                <?php
                $date = date('Y-m-d', strtotime("$year-$month-$day"));
                $is_today = $date == $today;
                $day_events = $events_by_date[$date] ?? [];
                ?>
                <div class="calendar-day <?= $is_today ? 'today' : '' ?>">
                    <div class="day-number"><?= $day ?></div>
                    <div class="event-list">
                        <?php foreach ($day_events as $event): ?>
                            <div class="event-item" 
                                 style="background: <?= $event['color'] ?>; color: white;"
                                 onclick="window.location.href='event_details.php?id=<?= $event['id'] ?>'"
                                 title="<?= htmlspecialchars($event['activity_title']) ?>">
                                <span class="event-indicator" style="background: white;"></span>
                                <?= htmlspecialchars($event['activity_title']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endfor; ?>
            
            <!-- Empty days for last week -->
            <?php
            $total_cells = 42; // 6 weeks * 7 days
            $used_cells = $first_day_of_month + $days_in_month;
            $empty_cells = $total_cells - $used_cells;
            ?>
            <?php for ($i = 0; $i < $empty_cells; $i++): ?>
                <div class="calendar-day other-month"></div>
            <?php endfor; ?>
        </div>
        
        <!-- Legend -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="bi bi-info-circle me-2"></i>Legend</h6>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="d-flex align-items-center">
                                <div class="event-indicator" style="background: #28a745;"></div>
                                <span class="ms-2">Academics</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="event-indicator" style="background: #dc3545;"></div>
                                <span class="ms-2">Sports</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="event-indicator" style="background: #ffc107;"></div>
                                <span class="ms-2">Cultural</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="event-indicator" style="background: #17a2b8;"></div>
                                <span class="ms-2">Competitions</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="event-indicator" style="background: #20c997;"></div>
                                <span class="ms-2">Workshops</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Upcoming Events Summary -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="bi bi-calendar2-event me-2"></i>Upcoming Events This Month</h6>
                        <?php if (empty($events)): ?>
                            <p class="text-muted mb-0">No events scheduled for this month.</p>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($events as $event): ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3" style="min-width: 80px;">
                                                <div class="text-center">
                                                    <div class="bg-light rounded p-1">
                                                        <div class="fw-bold"><?= date('d', strtotime($event['activity_date'])) ?></div>
                                                        <div class="small"><?= date('M', strtotime($event['activity_date'])) ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div style="flex: 1;">
                                                <a href="event_details.php?id=<?= $event['id'] ?>" class="text-decoration-none">
                                                    <h6 class="mb-1"><?= htmlspecialchars($event['activity_title']) ?></h6>
                                                </a>
                                                <div class="small text-muted">
                                                    <?php if ($event['start_time']): ?>
                                                        <i class="bi bi-clock me-1"></i>
                                                        <?= date('h:i A', strtotime($event['start_time'])) ?>
                                                    <?php endif; ?>
                                                    <?php if ($event['venue']): ?>
                                                        <i class="bi bi-geo-alt ms-2 me-1"></i>
                                                        <?= htmlspecialchars($event['venue']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="badge" style="background: <?= $event['color'] ?>;">
                                                    <?= htmlspecialchars($event['category_name']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile responsiveness
        function handleResize() {
            const calendarGrid = document.querySelector('.calendar-grid');
            if (window.innerWidth < 576) {
                calendarGrid.style.gridTemplateColumns = 'repeat(1, 1fr)';
            } else {
                calendarGrid.style.gridTemplateColumns = 'repeat(7, 1fr)';
            }
        }
        
        window.addEventListener('resize', handleResize);
        handleResize(); // Initial call
        
        // Print calendar
        function printCalendar() {
            window.print();
        }
        
        // Export calendar
        function exportCalendar() {
            alert('Calendar export feature would be implemented here. Would export to PDF or CSV.');
        }
    </script>
</body>
</html>




