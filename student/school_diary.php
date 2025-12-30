<?php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) && !isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$student_id = isset($_SESSION['student_id']) ? $_SESSION['student_id'] : $_SESSION['user_id'];

// Get student details including class
try {
    $student_stmt = $pdo->prepare("
        SELECT s.*, c.class_name 
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id 
        WHERE s.id = ? OR s.user_id = ?
    ");
    $student_stmt->execute([$student_id, $student_id]);
    $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception("Student not found");
    }
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error loading student data: " . $e->getMessage();
    header("Location: index.php");
    exit;
}

// Get current date for filtering
$current_date = date('Y-m-d');

// Build query - students see activities for their class or all-school activities
$query = "SELECT sd.*, ac.category_name, ac.color, ac.icon, u.full_name as coordinator_name 
          FROM school_diary sd 
          LEFT JOIN activity_categories ac ON sd.category_id = ac.id
          LEFT JOIN users u ON sd.coordinator_id = u.id 
          WHERE (sd.target_audience = 'All' 
                 OR sd.target_audience = 'Secondary Only' 
                 OR (sd.target_audience = 'Specific Classes' AND FIND_IN_SET(?, REPLACE(sd.target_classes, ', ', ','))))
          AND sd.status != 'Cancelled'
          ORDER BY 
            CASE 
                WHEN sd.status = 'Ongoing' THEN 1
                WHEN sd.activity_date >= ? THEN 2
                ELSE 3
            END,
            sd.activity_date ASC,
            sd.start_time ASC";

// If student has class, search for it in target_classes
$class_param = $student['class_name'] ?: '';
$stmt = $pdo->prepare($query);
$stmt->execute([$class_param, $current_date]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get today's activities
$today_query = "SELECT COUNT(*) as count FROM school_diary 
                WHERE activity_date = ? 
                AND status != 'Cancelled'";
$today_stmt = $pdo->prepare($today_query);
$today_stmt->execute([$current_date]);
$today_count = $today_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get upcoming activities (next 7 days)
$next_week = date('Y-m-d', strtotime('+7 days'));
$upcoming_query = "SELECT COUNT(*) as count FROM school_diary 
                   WHERE activity_date BETWEEN ? AND ? 
                   AND status != 'Cancelled' 
                   AND status = 'Upcoming'";
$upcoming_stmt = $pdo->prepare($upcoming_query);
$upcoming_stmt->execute([$current_date, $next_week]);
$upcoming_count = $upcoming_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get attachments and reminder status for each activity
foreach ($activities as &$activity) {
    $attachment_stmt = $pdo->prepare("
        SELECT file_path, file_type
        FROM school_diary_attachments
        WHERE diary_id = ?
        ORDER BY FIELD(file_type, 'image', 'video', 'document')
        LIMIT 3
    ");
    $attachment_stmt->execute([$activity['id']]);
    $activity['attachments'] = $attachment_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if student has a reminder set for this activity
    $reminder_stmt = $pdo->prepare("
        SELECT id, reminder_time FROM student_reminders
        WHERE student_id = ? AND activity_id = ? AND is_active = 1
    ");
    $reminder_stmt->execute([$student_id, $activity['id']]);
    $reminder = $reminder_stmt->fetch(PDO::FETCH_ASSOC);
    $activity['has_reminder'] = $reminder ? true : false;
    $activity['reminder_time'] = $reminder ? $reminder['reminder_time'] : '1_hour';
}
unset($activity); // Break reference
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Events Calendar - Sahab Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4cc9f0;
            --warning-color: #f72585;
            --info-color: #7209b7;
            --light-bg: #f8f9fa;
            --dark-bg: #212529;
            --card-shadow: 0 4px 6px rgba(0,0,0,0.1);
            --card-hover-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            padding-bottom: 3rem;
        }
        
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .dashboard-btn {
            background: var(--warning-color);
            border: none;
            transition: all 0.3s ease;
        }
        
        .dashboard-btn:hover {
            background: #e11571;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(247, 37, 133, 0.3);
        }
        
        .hero-section {
            background: linear-gradient(rgba(67, 97, 238, 0.9), rgba(58, 12, 163, 0.9)),
                        url('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?ixlib=rb-1.2.1&auto=format&fit=crop&w=1950&q=80');
            background-size: cover;
            background-position: center;
            border-radius: 15px;
            padding: 2.5rem 1.5rem;
            margin-bottom: 2rem;
            color: white;
            box-shadow: var(--card-shadow);
        }
        
        .stat-card {
            border-radius: 12px;
            border: none;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-hover-shadow);
        }
        
        .activity-card {
            border-radius: 12px;
            border: none;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }
        
        .activity-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-hover-shadow);
        }
        
        .category-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 2;
        }
        
        .activity-image-container {
            height: 180px;
            overflow: hidden;
            position: relative;
        }
        
        .activity-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .activity-card:hover .activity-image {
            transform: scale(1.05);
        }
        
        .date-badge {
            background: rgba(255,255,255,0.95);
            color: var(--dark-bg);
            padding: 8px 15px;
            border-radius: 25px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-upcoming { background: #d4edda; color: #155724; }
        .status-ongoing { background: #fff3cd; color: #856404; }
        .status-completed { background: #e2e3e5; color: #383d41; }
        
        .countdown-box {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
        }
        
        .countdown-number {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
        }
        
        .filter-buttons .btn {
            border-radius: 25px;
            padding: 8px 20px;
            margin: 0 5px 10px;
        }
        
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
            background: white;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1.5rem;
        }
        
        .modal-custom .modal-content {
            border-radius: 15px;
            border: none;
        }
        
        .modal-custom .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px 15px 0 0;
        }
        
        @media (max-width: 768px) {
            .hero-section {
                padding: 1.5rem 1rem;
            }
            
            .countdown-number {
                font-size: 1.8rem;
            }
            
            .activity-image-container {
                height: 150px;
            }
        }
        
        @media (max-width: 576px) {
            .filter-buttons .btn {
                width: 100%;
                margin: 5px 0;
            }
            
            .stat-card .card-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-calendar-check-fill me-2" style="font-size: 1.5rem;"></i>
                <span class="fw-bold">Sahab Events Calendar</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a href="dashboard.php" class="btn dashboard-btn text-white">
                            <i class="fas fa-tachometer-alt me-2"></i>Back to Dashboard
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="display-5 fw-bold mb-3">School Events & Activities</h1>
                    <p class="lead mb-4">Stay updated with all school events, competitions, and activities. Never miss an important date!</p>
                    <div class="d-flex align-items-center">
                        <div class="date-badge me-3">
                            <i class="bi bi-calendar3 me-2"></i>
                            <?= date('F j, Y') ?>
                        </div>
                        <?php if ($student && isset($student['class_name'])): ?>
                            <div class="badge bg-light text-dark">
                                <i class="bi bi-person-badge me-1"></i>
                                <?= htmlspecialchars($student['class_name']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4 mt-4 mt-lg-0">
                    <div class="countdown-box">
                        <h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>Next Event In</h5>
                        <div class="row text-center">
                            <div class="col-3">
                                <div class="countdown-number" id="countdown-days">00</div>
                                <small>Days</small>
                            </div>
                            <div class="col-3">
                                <div class="countdown-number" id="countdown-hours">00</div>
                                <small>Hours</small>
                            </div>
                            <div class="col-3">
                                <div class="countdown-number" id="countdown-minutes">00</div>
                                <small>Mins</small>
                            </div>
                            <div class="col-3">
                                <div class="countdown-number" id="countdown-seconds">00</div>
                                <small>Secs</small>
                            </div>
                        </div>
                        <div class="mt-3" id="next-event-title">Loading next event...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card stat-card border-start border-primary border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Total Events</h6>
                                <h3 class="mb-0"><?= count($activities) ?></h3>
                            </div>
                            <div class="bg-primary rounded-circle p-3">
                                <i class="bi bi-calendar-event text-white" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-3">
                <div class="card stat-card border-start border-success border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Today's Events</h6>
                                <h3 class="mb-0"><?= $today_count ?></h3>
                            </div>
                            <div class="bg-success rounded-circle p-3">
                                <i class="bi bi-calendar-check text-white" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-3">
                <div class="card stat-card border-start border-warning border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Upcoming (7 days)</h6>
                                <h3 class="mb-0"><?= $upcoming_count ?></h3>
                            </div>
                            <div class="bg-warning rounded-circle p-3">
                                <i class="bi bi-calendar-plus text-white" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Buttons -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="bi bi-funnel me-2"></i>Filter Events</h6>
                        <div class="filter-buttons text-center">
                            <button class="btn btn-outline-primary active" data-filter="all">All Events</button>
                            <button class="btn btn-outline-success" data-filter="upcoming">Upcoming</button>
                            <button class="btn btn-outline-warning" data-filter="ongoing">Ongoing</button>
                            <button class="btn btn-outline-info" data-filter="today">Today</button>
                            <button class="btn btn-outline-secondary" data-filter="completed">Past Events</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activities Grid -->
        <div class="row" id="activities-container">
            <?php if (empty($activities)): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-calendar-x"></i>
                        </div>
                        <h4 class="mb-3">No Events Scheduled</h4>
                        <p class="text-muted mb-4">There are no upcoming events scheduled at the moment.</p>
                        <button class="btn btn-primary">
                            <i class="bi bi-calendar-plus me-2"></i>Check Back Later
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($activities as $activity): ?>
                    <?php
                    // Determine status and styling
                    $activity_date = strtotime($activity['activity_date']);
                    $today = strtotime(date('Y-m-d'));
                    $status_class = '';
                    
                    if ($activity['status'] == 'Ongoing') {
                        $status_class = 'status-ongoing';
                        $status_text = 'Ongoing';
                    } elseif ($activity_date == $today && $activity['status'] == 'Upcoming') {
                        $status_class = 'status-ongoing';
                        $status_text = 'Today';
                    } elseif ($activity_date > $today) {
                        $status_class = 'status-upcoming';
                        $status_text = 'Upcoming';
                    } else {
                        $status_class = 'status-completed';
                        $status_text = 'Completed';
                    }
                    
                    // Get category color
                    $category_color = $activity['color'] ?: '#007bff';
                    $category_icon = $activity['icon'] ?: 'bi-calendar-event';
                    
                    // Format date
                    $formatted_date = date('M j, Y', $activity_date);
                    $formatted_time = $activity['start_time'] ? date('h:i A', strtotime($activity['start_time'])) : 'All Day';
                    
                    // Get first image for preview
                    $preview_image = '';
                    foreach ($activity['attachments'] as $attachment) {
                        if ($attachment['file_type'] == 'image') {
                            $preview_image = '../admin/' . $attachment['file_path'];
                            break;
                        }
                    }
                    ?>
                    
                    <div class="col-lg-4 col-md-6 mb-4 activity-item" 
                         data-status="<?= strtolower($status_text) ?>" 
                         data-date="<?= $activity['activity_date'] ?>">
                        <div class="card activity-card">
                            <!-- Category Badge -->
                            <div class="category-badge" style="background: <?= $category_color ?>; color: white;">
                                <i class="bi <?= $category_icon ?> me-1"></i>
                                <?= htmlspecialchars($activity['category_name'] ?: $activity['activity_type']) ?>
                            </div>
                            
                            <!-- Activity Image -->
                            <div class="activity-image-container">
                                <?php if ($preview_image): ?>
                                    <img src="<?= htmlspecialchars($preview_image) ?>" 
                                         class="activity-image" 
                                         alt="<?= htmlspecialchars($activity['activity_title']) ?>"
                                         onerror="this.src='https://images.unsplash.com/photo-1498243691581-b145c3f54a5a?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80'">
                                <?php else: ?>
                                    <div class="activity-image d-flex align-items-center justify-content-center bg-light">
                                        <i class="bi <?= $category_icon ?>" style="font-size: 3rem; color: <?= $category_color ?>;"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-body">
                                <!-- Status Badge -->
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="status-badge <?= $status_class ?>">
                                        <?= $status_text ?>
                                    </span>
                                    <?php if (count($activity['attachments']) > 0): ?>
                                        <span class="badge bg-light text-dark">
                                            <i class="bi bi-paperclip me-1"></i>
                                            <?= count($activity['attachments']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Title -->
                                <h5 class="card-title mb-2">
                                    <?= htmlspecialchars($activity['activity_title']) ?>
                                </h5>
                                
                                <!-- Date & Time -->
                                <p class="text-muted mb-3">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <strong><?= $formatted_date ?></strong>
                                    <?php if ($activity['start_time']): ?>
                                        <br>
                                        <i class="bi bi-clock me-1"></i>
                                        <?= $formatted_time ?>
                                        <?php if ($activity['end_time']): ?>
                                            - <?= date('h:i A', strtotime($activity['end_time'])) ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                                
                                <!-- Venue -->
                                <?php if ($activity['venue']): ?>
                                    <p class="mb-3">
                                        <i class="bi bi-geo-alt me-1"></i>
                                        <small><?= htmlspecialchars($activity['venue']) ?></small>
                                    </p>
                                <?php endif; ?>
                                
                                <!-- Description Preview -->
                                <p class="card-text mb-3">
                                    <?php
                                    $description = strip_tags($activity['description'] ?: 'No description available');
                                    echo strlen($description) > 100 ? 
                                        substr($description, 0, 100) . '...' : 
                                        $description;
                                    ?>
                                </p>
                                
                                <!-- Action Buttons -->

                                <div class="d-flex justify-content-between">
                                    <button class="btn btn-sm btn-outline-primary view-details-btn"
                                            data-id="<?= $activity['id'] ?>">
                                        <i class="bi bi-eye me-1"></i> View Details
                                    </button>
                                    <?php if ($activity_date >= $today): ?>
                                        <button class="btn btn-sm reminder-btn <?= $activity['has_reminder'] ? 'btn-success' : 'btn-outline-success' ?>"
                                                data-id="<?= $activity['id'] ?>"
                                                data-has-reminder="<?= $activity['has_reminder'] ? '1' : '0' ?>"
                                                data-reminder-time="<?= htmlspecialchars($activity['reminder_time']) ?>">
                                            <i class="bi bi-bell<?= $activity['has_reminder'] ? '-fill' : '' ?> me-1"></i>
                                            <?= $activity['has_reminder'] ? 'Reminder Set' : 'Remind Me' ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Activity Details Modal -->
    <div class="modal fade modal-custom" id="activityModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Close
                    </button>
                    <button type="button" class="btn btn-primary" id="setReminderBtn">
                        <i class="bi bi-bell me-1"></i> Set Reminder
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Filter activities
            $('[data-filter]').click(function() {
                const filter = $(this).data('filter');
                $('[data-filter]').removeClass('active');
                $(this).addClass('active');
                
                $('.activity-item').hide();
                
                if (filter === 'all') {
                    $('.activity-item').show();
                } else if (filter === 'today') {
                    const today = new Date().toISOString().split('T')[0];
                    $(`.activity-item[data-date="${today}"]`).show();
                } else {
                    $(`.activity-item[data-status="${filter}"]`).show();
                }
                
                // If no items visible, show message
                if ($('.activity-item:visible').length === 0) {
                    $('#activities-container').html(`
                        <div class="col-12">
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="bi bi-funnel"></i>
                                </div>
                                <h4 class="mb-3">No Events Found</h4>
                                <p class="text-muted mb-4">No events match your filter criteria.</p>
                                <button class="btn btn-primary" onclick="resetFilter()">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Show All Events
                                </button>
                            </div>
                        </div>
                    `);
                }
            });
            
            // Countdown timer for next event
            function updateCountdown() {
                const upcomingEvents = <?= json_encode(array_filter($activities, function($a) {
                    return strtotime($a['activity_date']) >= strtotime(date('Y-m-d')) && $a['status'] == 'Upcoming';
                })) ?>;
                
                if (upcomingEvents.length > 0) {
                    const nextEvent = upcomingEvents[0];
                    const eventDate = new Date(nextEvent.activity_date + ' ' + (nextEvent.start_time || '00:00:00'));
                    const now = new Date();
                    
                    if (eventDate > now) {
                        const diff = eventDate - now;
                        
                        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                        
                        $('#countdown-days').text(days.toString().padStart(2, '0'));
                        $('#countdown-hours').text(hours.toString().padStart(2, '0'));
                        $('#countdown-minutes').text(minutes.toString().padStart(2, '0'));
                        $('#countdown-seconds').text(seconds.toString().padStart(2, '0'));
                        $('#next-event-title').text(nextEvent.activity_title);
                    } else {
                        $('#next-event-title').text('No upcoming events');
                        $('#countdown-days, #countdown-hours, #countdown-minutes, #countdown-seconds').text('00');
                    }
                } else {
                    $('#next-event-title').text('No upcoming events');
                    $('#countdown-days, #countdown-hours, #countdown-minutes, #countdown-seconds').text('00');
                }
            }
            
            // Update countdown every second
            setInterval(updateCountdown, 1000);
            updateCountdown();
            
            // View activity details
            $('.view-details-btn').click(function() {
                const activityId = $(this).data('id');

                // Show loading
                $('#modalTitle').text('Loading...');
                $('#modalBody').html(`
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3">Loading activity details...</p>
                    </div>
                `);

                // Fetch activity details
                $.ajax({
                    url: 'get_activity_details.php',
                    method: 'GET',
                    data: { id: activityId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.error) {
                            $('#modalTitle').text('Error');
                            $('#modalBody').html(`
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    ${response.error}
                                </div>
                            `);
                        } else {
                            $('#modalTitle').text(response.title || 'Activity Details');
                            $('#modalBody').html(response.html || '<p>Details not available</p>');
                            $('#setReminderBtn').data('id', activityId);

                            // Update modal reminder button based on current reminder status
                            const modalReminderBtn = $('#setReminderBtn');
                            const cardReminderBtn = $(`.reminder-btn[data-id="${activityId}"]`);
                            const hasReminder = cardReminderBtn.data('has-reminder') == '1';

                            if (hasReminder) {
                                modalReminderBtn.removeClass('btn-primary').addClass('btn-warning');
                                modalReminderBtn.html('<i class="bi bi-bell-slash me-1"></i> Remove Reminder');
                            } else {
                                modalReminderBtn.removeClass('btn-warning').addClass('btn-primary');
                                modalReminderBtn.html('<i class="bi bi-bell me-1"></i> Set Reminder');
                            }
                        }
                        new bootstrap.Modal(document.getElementById('activityModal')).show();
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', xhr.responseText, status, error);
                        $('#modalTitle').text('Error');
                        $('#modalBody').html(`
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                Failed to load activity details. Please try again.
                                <br><small class="text-muted mt-2">Error: ${error}</small>
                            </div>
                        `);
                        new bootstrap.Modal(document.getElementById('activityModal')).show();
                    }
                });
            });

            // Handle reminder button clicks (both in cards and modal)
            $(document).on('click', '.reminder-btn, #setReminderBtn', function() {
                const activityId = $(this).data('id');
                const cardReminderBtn = $(`.reminder-btn[data-id="${activityId}"]`);
                const modalReminderBtn = $('#setReminderBtn');
                const currentHasReminder = cardReminderBtn.data('has-reminder') == '1';

                if (!activityId) return;

                // Show loading state
                const originalHtml = $(this).html();
                $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i> Processing...');

                // Determine action and reminder time
                let action = 'set_reminder';
                let reminderTime = '1_hour';

                if (currentHasReminder) {
                    // If already has reminder, we're removing it
                    action = 'remove_reminder';
                } else {
                    // Show reminder options modal
                    showReminderOptionsModal(activityId, $(this), originalHtml);
                    return;
                }

                // Make AJAX call to set/remove reminder
                $.ajax({
                    url: 'get_activity_details.php',
                    method: 'POST',
                    data: {
                        action: action,
                        activity_id: activityId,
                        reminder_time: reminderTime
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Update button states
                            if (currentHasReminder) {
                                // Removed reminder
                                cardReminderBtn.removeClass('btn-success').addClass('btn-outline-success');
                                cardReminderBtn.html('<i class="bi bi-bell me-1"></i> Remind Me');
                                cardReminderBtn.data('has-reminder', '0');
                                modalReminderBtn.removeClass('btn-warning').addClass('btn-primary');
                                modalReminderBtn.html('<i class="bi bi-bell me-1"></i> Set Reminder');
                            } else {
                                // Set reminder
                                cardReminderBtn.removeClass('btn-outline-success').addClass('btn-success');
                                cardReminderBtn.html('<i class="bi bi-bell-fill me-1"></i> Reminder Set');
                                cardReminderBtn.data('has-reminder', '1');
                                cardReminderBtn.data('reminder-time', reminderTime);
                                modalReminderBtn.removeClass('btn-primary').addClass('btn-warning');
                                modalReminderBtn.html('<i class="bi bi-bell-slash me-1"></i> Remove Reminder');
                            }

                            // Show success message
                            showToast('success', response.message);
                        } else {
                            showToast('error', response.message || 'Failed to update reminder');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Reminder AJAX Error:', xhr.responseText);
                        showToast('error', 'Failed to update reminder. Please try again.');
                    },
                    complete: function() {
                        // Reset button state
                        $('.reminder-btn, #setReminderBtn').prop('disabled', false);
                        $('.reminder-btn[data-id="' + activityId + '"]').html(originalHtml);
                    }
                });
            });

            // Reset filter function
            window.resetFilter = function() {
                $('[data-filter="all"]').click();
            };

            // Image modal function
            window.openImageModal = function(imageSrc) {
                // Create modal HTML
                const modalHtml = `
                    <div class="modal fade" id="imageModal" tabindex="-1">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Image Preview</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-center p-0">
                                    <img src="${imageSrc}" class="img-fluid" style="max-height: 70vh;" alt="Activity Image">
                                </div>
                                <div class="modal-footer">
                                    <a href="${imageSrc}" download class="btn btn-primary">
                                        <i class="bi bi-download me-2"></i>Download Image
                                    </a>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                        <i class="bi bi-x-circle me-2"></i>Close
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                // Remove existing modal if any
                $('#imageModal').remove();

                // Add modal to body
                $('body').append(modalHtml);

                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('imageModal'));
                modal.show();

                // Clean up modal when hidden
                $('#imageModal').on('hidden.bs.modal', function() {
                    $(this).remove();
                });
            };

            // Reminder options modal function
            window.showReminderOptionsModal = function(activityId, buttonElement, originalHtml) {
                const modalHtml = `
                    <div class="modal fade" id="reminderOptionsModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header" style="background: linear-gradient(135deg, #10b981, #059669); color: white;">
                                    <h5 class="modal-title">
                                        <i class="bi bi-bell me-2"></i>Set Reminder
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="mb-3">When would you like to be reminded about this activity?</p>
                                    <div class="reminder-options">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="reminderTime" id="reminder_15min" value="15_min">
                                            <label class="form-check-label" for="reminder_15min">
                                                <i class="bi bi-clock me-2"></i>15 minutes before
                                            </label>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="reminderTime" id="reminder_30min" value="30_min">
                                            <label class="form-check-label" for="reminder_30min">
                                                <i class="bi bi-clock me-2"></i>30 minutes before
                                            </label>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="reminderTime" id="reminder_1hour" value="1_hour" checked>
                                            <label class="form-check-label" for="reminder_1hour">
                                                <i class="bi bi-clock me-2"></i>1 hour before
                                            </label>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="reminderTime" id="reminder_2hours" value="2_hours">
                                            <label class="form-check-label" for="reminder_2hours">
                                                <i class="bi bi-clock me-2"></i>2 hours before
                                            </label>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="reminderTime" id="reminder_1day" value="1_day">
                                            <label class="form-check-label" for="reminder_1day">
                                                <i class="bi bi-calendar me-2"></i>1 day before
                                            </label>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="reminderTime" id="reminder_2days" value="2_days">
                                            <label class="form-check-label" for="reminder_2days">
                                                <i class="bi bi-calendar me-2"></i>2 days before
                                            </label>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="reminderTime" id="reminder_1week" value="1_week">
                                            <label class="form-check-label" for="reminder_1week">
                                                <i class="bi bi-calendar-week me-2"></i>1 week before
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                        <i class="bi bi-x-circle me-1"></i>Cancel
                                    </button>
                                    <button type="button" class="btn btn-success" id="confirmReminderBtn">
                                        <i class="bi bi-bell me-1"></i>Set Reminder
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                // Remove existing modal if any
                $('#reminderOptionsModal').remove();

                // Add modal to body
                $('body').append(modalHtml);

                // Set up modal events
                const modal = new bootstrap.Modal(document.getElementById('reminderOptionsModal'));

                // Handle confirm button click
                $('#confirmReminderBtn').click(function() {
                    const selectedTime = $('input[name="reminderTime"]:checked').val();

                    // Close reminder options modal
                    modal.hide();

                    // Proceed with setting reminder
                    setReminder(activityId, selectedTime, buttonElement, originalHtml);
                });

                // Show modal
                modal.show();

                // Clean up modal when hidden
                $('#reminderOptionsModal').on('hidden.bs.modal', function() {
                    $(this).remove();
                });
            };

            // Set reminder function
            function setReminder(activityId, reminderTime, buttonElement, originalHtml) {
                // Show loading state
                buttonElement.prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i> Setting...');

                // Make AJAX call to set reminder
                $.ajax({
                    url: 'get_activity_details.php',
                    method: 'POST',
                    data: {
                        action: 'set_reminder',
                        activity_id: activityId,
                        reminder_time: reminderTime
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Update button states
                            const cardReminderBtn = $(`.reminder-btn[data-id="${activityId}"]`);
                            const modalReminderBtn = $('#setReminderBtn');

                            cardReminderBtn.removeClass('btn-outline-success').addClass('btn-success');
                            cardReminderBtn.html('<i class="bi bi-bell-fill me-1"></i> Reminder Set');
                            cardReminderBtn.data('has-reminder', '1');
                            cardReminderBtn.data('reminder-time', reminderTime);

                            if (modalReminderBtn.length) {
                                modalReminderBtn.removeClass('btn-primary').addClass('btn-warning');
                                modalReminderBtn.html('<i class="bi bi-bell-slash me-1"></i> Remove Reminder');
                            }

                            // Show success message
                            showToast('success', response.message);
                        } else {
                            showToast('error', response.message || 'Failed to set reminder');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Set Reminder AJAX Error:', xhr.responseText);
                        showToast('error', 'Failed to set reminder. Please try again.');
                    },
                    complete: function() {
                        // Reset button state
                        buttonElement.prop('disabled', false).html(originalHtml);
                    }
                });
            }

            // Toast notification function
            window.showToast = function(type, message) {
                // Remove existing toasts
                $('.toast-notification').remove();

                // Create toast HTML
                const toastClass = type === 'success' ? 'bg-success' : 'bg-danger';
                const iconClass = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle';
                const toastHtml = `
                    <div class="toast-notification position-fixed top-0 end-0 p-3" style="z-index: 9999;">
                        <div class="toast show align-items-center text-white ${toastClass} border-0" role="alert">
                            <div class="d-flex">
                                <div class="toast-body">
                                    <i class="bi ${iconClass} me-2"></i>${message}
                                </div>
                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                            </div>
                        </div>
                    </div>
                `;

                // Add toast to body
                $('body').append(toastHtml);

                // Auto remove after 3 seconds
                setTimeout(function() {
                    $('.toast-notification').fadeOut(function() {
                        $(this).remove();
                    });
                }, 3000);
            };
        });
    </script>
</body>
</html>
