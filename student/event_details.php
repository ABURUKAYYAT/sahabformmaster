<?php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) && !isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$current_school_id = get_current_school_id();

// Check if event ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: school_diary.php");
    exit;
}

$event_id = intval($_GET['id']);
$student_id = isset($_SESSION['student_id']) ? $_SESSION['student_id'] : $_SESSION['user_id'];

// Get event details
try {
    // Get student details for filtering
    $student_stmt = $pdo->prepare("
        SELECT s.*, c.class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id AND c.school_id = ?
        WHERE s.id = ? OR s.user_id = ? AND s.school_id = ?
    ");
    $student_stmt->execute([$current_school_id, $student_id, $student_id, $current_school_id]);
    $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get event details
    $event_stmt = $pdo->prepare("
        SELECT sd.*,
               ac.category_name, ac.color, ac.icon,
               u.full_name as coordinator_name, u.email as coordinator_email, u.phone as coordinator_phone,
               DATE_FORMAT(sd.activity_date, '%W, %M %e, %Y') as formatted_date,
               DATE_FORMAT(sd.activity_date, '%Y-%m-%d') as iso_date,
               TIME_FORMAT(sd.start_time, '%h:%i %p') as formatted_start,
               TIME_FORMAT(sd.end_time, '%h:%i %p') as formatted_end,
               TIMEDIFF(sd.end_time, sd.start_time) as duration
        FROM school_diary sd
        LEFT JOIN activity_categories ac ON sd.category_id = ac.id
        LEFT JOIN users u ON sd.coordinator_id = u.id
        WHERE sd.id = ? AND sd.school_id = ?
        AND (sd.target_audience = 'All'
             OR sd.target_audience = 'Secondary Only'
             OR (sd.target_audience = 'Specific Classes' AND FIND_IN_SET(?, REPLACE(sd.target_classes, ', ', ','))))
        AND sd.status != 'Cancelled'
    ");

    $event_stmt->execute([$event_id, $current_school_id, $student['class_name'] ?: '']);
    $event = $event_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception("Event not found or you don't have access to view this event");
    }
    
    // Get all attachments
    $attachments_stmt = $pdo->prepare("
        SELECT * FROM school_diary_attachments
        WHERE diary_id = ? AND school_id = ?
        ORDER BY FIELD(file_type, 'image', 'video', 'document')
    ");
    $attachments_stmt->execute([$event_id, $current_school_id]);
    $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get activity participants count
    $participants_stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM activity_participants
        WHERE activity_id = ? AND school_id = ?
    ");
    $participants_stmt->execute([$event_id, $current_school_id]);
    $participant_count = $participants_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Get student registration status if applicable
    $registration_stmt = $pdo->prepare("
        SELECT status FROM activity_registrations
        WHERE activity_id = ? AND (student_id = ? OR user_id = ?) AND school_id = ?
    ");
    $registration_stmt->execute([$event_id, $student_id, $student_id, $current_school_id]);
    $registration = $registration_stmt->fetch(PDO::FETCH_ASSOC);

    // Get similar events (same category)
    $similar_events_stmt = $pdo->prepare("
        SELECT sd.id, sd.activity_title, sd.activity_date, sd.start_time, sd.venue
        FROM school_diary sd
        WHERE sd.category_id = ?
        AND sd.id != ?
        AND sd.status != 'Cancelled'
        AND sd.activity_date >= CURDATE()
        AND sd.school_id = ?
        ORDER BY sd.activity_date ASC
        LIMIT 3
    ");
    $similar_events_stmt->execute([$event['category_id'], $event_id, $current_school_id]);
    $similar_events = $similar_events_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Determine event status
    $event_date = strtotime($event['activity_date']);
    $today = strtotime(date('Y-m-d'));
    
    if ($event['status'] == 'Ongoing') {
        $status_class = 'bg-warning';
        $status_text = 'Ongoing';
        $status_icon = 'bi-clock-history';
    } elseif ($event_date == $today && $event['status'] == 'Upcoming') {
        $status_class = 'bg-info';
        $status_text = 'Today';
        $status_icon = 'bi-calendar-check';
    } elseif ($event_date > $today) {
        $status_class = 'bg-success';
        $status_text = 'Upcoming';
        $status_icon = 'bi-calendar-plus';
    } else {
        $status_class = 'bg-secondary';
        $status_text = 'Completed';
        $status_icon = 'bi-calendar-check';
    }
    
    // Format date/time for calendar
    $start_datetime = $event['iso_date'] . 'T' . ($event['start_time'] ?: '09:00:00');
    $end_datetime = $event['iso_date'] . 'T' . ($event['end_time'] ?: '17:00:00');
    
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header("Location: school_diary.php");
    exit;
}

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    try {
        $pdo->beginTransaction();
        
        // Check if already registered
        $check_stmt = $pdo->prepare("
            SELECT id FROM activity_registrations
            WHERE activity_id = ? AND (student_id = ? OR user_id = ?) AND school_id = ?
        ");
        $check_stmt->execute([$event_id, $student_id, $student_id, $current_school_id]);
        
        if ($check_stmt->fetch()) {
            $_SESSION['warning'] = "You are already registered for this event!";
        } else {
            // Insert registration
            $insert_stmt = $pdo->prepare("
                INSERT INTO activity_registrations
                (activity_id, student_id, full_name, email, phone, class, registration_type, status, registration_date, school_id)
                VALUES (?, ?, ?, ?, ?, ?, 'student', 'pending', NOW(), ?)
            ");

            $insert_stmt->execute([
                $event_id,
                $student_id,
                $student['full_name'],
                $student['guardian_email'] ?: '',
                $student['phone'] ?: $student['guardian_phone'],
                $student['class_name'],
                $current_school_id
            ]);
            
            $pdo->commit();
            $_SESSION['success'] = "Successfully registered for the event! Your registration is pending approval.";
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Registration failed: " . $e->getMessage();
    }
    
    header("Location: event_details.php?id=" . $event_id);
    exit;
}

// Handle reminder setting
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_reminder'])) {
    $reminder_type = $_POST['reminder_type'] ?? 'email';
    $reminder_time = $_POST['reminder_time'] ?? '1_day_before';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_reminders
            (activity_id, reminder_type, reminder_time, sent_to, status, created_at, school_id)
            VALUES (?, ?, ?, ?, 'pending', NOW(), ?)
        ");

        $stmt->execute([
            $event_id,
            $reminder_type,
            $reminder_time,
            $student_id,
            $current_school_id
        ]);
        
        $_SESSION['success'] = "Reminder set successfully! You will be notified before the event.";
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Failed to set reminder: " . $e->getMessage();
    }
    
    header("Location: event_details.php?id=" . $event_id);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($event['activity_title']) ?> - Sahab Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lightgallery@2.7.0/css/lightgallery-bundle.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4cc9f0;
            --warning-color: #f72585;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .event-header {
            background: linear-gradient(rgba(67, 97, 238, 0.9), rgba(58, 12, 163, 0.9)),
                        url('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?ixlib=rb-1.2.1&auto=format&fit=crop&w=1950&q=80');
            background-size: cover;
            background-position: center;
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }
        
        .event-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .event-sidebar {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
        }
        
        .info-card {
            border-left: 4px solid var(--primary-color);
            padding: 1rem;
            background: white;
            border-radius: 8px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .attachment-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .attachment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .gallery-item {
            height: 200px;
            overflow: hidden;
            border-radius: 10px;
            cursor: pointer;
        }
        
        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .gallery-item:hover img {
            transform: scale(1.05);
        }
        
        .action-buttons .btn {
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 500;
        }
        
        .registration-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
        }
        
        .similar-event-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .similar-event-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-3px);
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0;
        }
        
        .back-btn {
            background: var(--warning-color);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: #e11571;
            color: white;
            transform: translateY(-2px);
        }
        
        .status-badge {
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .event-header {
                padding: 2rem 0;
            }
            
            .action-buttons .btn {
                width: 100%;
                margin-bottom: 10px;
            }
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .event-header {
                background: white !important;
                color: black !important;
                padding: 1rem 0 !important;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="school_diary.php">
                <i class="bi bi-calendar-check-fill me-2" style="font-size: 1.5rem;"></i>
                <span class="fw-bold">Sahab Events</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a href="school_diary.php" class="btn back-btn">
                            <i class="bi bi-arrow-left me-2"></i>Back to Events
                        </a>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="dashboard.php" class="btn btn-outline-light">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Event Header -->
    <div class="event-header">
        <div class="container">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb text-white">
                    <li class="breadcrumb-item"><a href="school_diary.php" class="text-white text-decoration-none">Events</a></li>
                    <li class="breadcrumb-item active text-white" aria-current="page">Event Details</li>
                </ol>
            </nav>
            
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <span class="status-badge <?= $status_class ?> mb-3">
                        <i class="bi <?= $status_icon ?> me-2"></i><?= $status_text ?>
                    </span>
                    <h1 class="display-5 fw-bold mb-3"><?= htmlspecialchars($event['activity_title']) ?></h1>
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-calendar3 me-2"></i>
                            <strong><?= $event['formatted_date'] ?></strong>
                        </div>
                        <?php if ($event['start_time']): ?>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-clock me-2"></i>
                                <strong><?= $event['formatted_start'] ?></strong>
                                <?php if ($event['end_time']): ?>
                                    <span class="mx-1">to</span>
                                    <strong><?= $event['formatted_end'] ?></strong>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($event['venue']): ?>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-geo-alt me-2"></i>
                                <strong><?= htmlspecialchars($event['venue']) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end mt-4 mt-lg-0">
                    <div class="action-buttons d-flex flex-column flex-lg-row gap-2 justify-content-lg-end">
                        <button onclick="window.print()" class="btn btn-light">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <button onclick="addToCalendar()" class="btn btn-light">
                            <i class="bi bi-calendar-plus me-1"></i> Add to Calendar
                        </button>
                        <button onclick="shareEvent()" class="btn btn-light">
                            <i class="bi bi-share me-1"></i> Share
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Flash Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['warning'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle me-2"></i>
                <?= $_SESSION['warning'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['warning']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8 mb-4">
                <div class="card event-card mb-4">
                    <div class="card-body">
                        <!-- Category Badge -->
                        <div class="d-flex align-items-center mb-4">
                            <span class="badge me-3" style="background: <?= $event['color'] ?>; color: white; padding: 8px 16px;">
                                <i class="bi <?= $event['icon'] ?> me-1"></i>
                                <?= htmlspecialchars($event['category_name']) ?>
                            </span>
                            <span class="text-muted">
                                <i class="bi bi-people me-1"></i>
                                <?= $participant_count ?> participants
                            </span>
                        </div>
                        
                        <!-- Event Description -->
                        <div class="mb-5">
                            <h4 class="mb-3">Event Description</h4>
                            <div class="bg-light p-4 rounded">
                                <?= nl2br(htmlspecialchars($event['description'] ?: 'No description available')) ?>
                            </div>
                        </div>
                        
                        <!-- Objectives -->
                        <?php if ($event['objectives']): ?>
                            <div class="mb-5">
                                <h4 class="mb-3"><i class="bi bi-bullseye me-2"></i>Objectives</h4>
                                <div class="bg-light p-4 rounded">
                                    <?= nl2br(htmlspecialchars($event['objectives'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Gallery -->
                        <?php 
                        $images = array_filter($attachments, function($att) {
                            return $att['file_type'] == 'image';
                        });
                        ?>
                        
                        <?php if (!empty($images)): ?>
                            <div class="mb-5">
                                <h4 class="mb-3"><i class="bi bi-images me-2"></i>Event Photos</h4>
                                <div class="row g-3" id="lightgallery">
                                    <?php foreach ($images as $image): ?>
                                        <div class="col-md-4 col-6">
                                            <div class="gallery-item" 
                                                 data-src="../admin/<?= htmlspecialchars($image['file_path']) ?>"
                                                 data-sub-html="<h4><?= htmlspecialchars($event['activity_title']) ?></h4>">
                                                <img src="../admin/<?= htmlspecialchars($image['file_path']) ?>" 
                                                     alt="Event Image" 
                                                     onerror="this.src='https://images.unsplash.com/photo-1498243691581-b145c3f54a5a?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80'">
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Attachments -->
                        <?php 
                        $other_attachments = array_filter($attachments, function($att) {
                            return $att['file_type'] != 'image';
                        });
                        ?>
                        
                        <?php if (!empty($other_attachments)): ?>
                            <div class="mb-5">
                                <h4 class="mb-3"><i class="bi bi-paperclip me-2"></i>Documents & Files</h4>
                                <div class="row g-3">
                                    <?php foreach ($other_attachments as $attachment): ?>
                                        <div class="col-md-6">
                                            <div class="attachment-card">
                                                <div class="d-flex align-items-center">
                                                    <?php if ($attachment['file_type'] == 'video'): ?>
                                                        <i class="bi bi-file-earmark-play-fill text-danger me-3" style="font-size: 2rem;"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-file-earmark-text-fill text-primary me-3" style="font-size: 2rem;"></i>
                                                    <?php endif; ?>
                                                    <div style="flex: 1; min-width: 0;">
                                                        <h6 class="mb-1 text-truncate"><?= htmlspecialchars($attachment['file_name']) ?></h6>
                                                        <small class="text-muted">
                                                            <?= strtoupper(pathinfo($attachment['file_path'], PATHINFO_EXTENSION)) ?> File
                                                        </small>
                                                    </div>
                                                    <a href="../admin/<?= htmlspecialchars($attachment['file_path']) ?>" 
                                                       download 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Additional Information -->
                        <div class="row">
                            <?php if ($event['organizing_dept']): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="info-card">
                                        <h6><i class="bi bi-building me-2"></i>Organizing Department</h6>
                                        <p class="mb-0"><?= htmlspecialchars($event['organizing_dept']) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($event['target_audience']): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="info-card">
                                        <h6><i class="bi bi-people me-2"></i>Target Audience</h6>
                                        <p class="mb-0"><?= htmlspecialchars($event['target_audience']) ?></p>
                                        <?php if ($event['target_classes']): ?>
                                            <small class="text-muted">Classes: <?= htmlspecialchars($event['target_classes']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($event['resources']): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="info-card">
                                        <h6><i class="bi bi-tools me-2"></i>Required Resources</h6>
                                        <p class="mb-0"><?= nl2br(htmlspecialchars($event['resources'])) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($event['winners_list']): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="info-card">
                                        <h6><i class="bi bi-trophy me-2"></i>Winners</h6>
                                        <p class="mb-0"><?= nl2br(htmlspecialchars($event['winners_list'])) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($event['achievements']): ?>
                                <div class="col-md-12 mb-3">
                                    <div class="info-card">
                                        <h6><i class="bi bi-award me-2"></i>Achievements</h6>
                                        <p class="mb-0"><?= nl2br(htmlspecialchars($event['achievements'])) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($event['feedback_summary']): ?>
                                <div class="col-md-12 mb-3">
                                    <div class="info-card">
                                        <h6><i class="bi bi-chat-left-text me-2"></i>Feedback Summary</h6>
                                        <p class="mb-0"><?= nl2br(htmlspecialchars($event['feedback_summary'])) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Registration Form (for upcoming events) -->
                <?php if ($event_date > $today && $event['status'] == 'Upcoming' && $event['target_audience'] != 'Teachers' && $event['target_audience'] != 'Staff'): ?>
                    <div class="registration-card mb-4">
                        <h4 class="mb-3"><i class="bi bi-person-plus me-2"></i>Register for this Event</h4>
                        <p class="mb-4">Secure your spot for this event by registering below.</p>
                        
                        <?php if ($registration): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                You are already registered for this event. Status: 
                                <span class="badge bg-<?= $registration['status'] == 'confirmed' ? 'success' : 'warning' ?>">
                                    <?= ucfirst($registration['status']) ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="">
                                <input type="hidden" name="register" value="1">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($student['full_name']) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Class</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($student['class_name']) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" value="<?= htmlspecialchars($student['guardian_email'] ?: '') ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Phone</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($student['phone'] ?: $student['guardian_phone']) ?>" readonly>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-light w-100">
                                            <i class="bi bi-check-circle me-2"></i>Register Now
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Event Details Sidebar -->
                <div class="event-sidebar mb-4">
                    <h5 class="mb-4"><i class="bi bi-info-circle me-2"></i>Event Details</h5>
                    
                    <div class="mb-4">
                        <h6><i class="bi bi-calendar3 me-2"></i>Date & Time</h6>
                        <p class="mb-1"><strong><?= $event['formatted_date'] ?></strong></p>
                        <?php if ($event['start_time']): ?>
                            <p class="mb-0">
                                <i class="bi bi-clock me-1"></i>
                                <?= $event['formatted_start'] ?>
                                <?php if ($event['end_time']): ?>
                                    - <?= $event['formatted_end'] ?>
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <p class="mb-0"><i class="bi bi-clock me-1"></i> All Day</p>
                        <?php endif; ?>
                        
                        <?php if ($event['duration']): ?>
                            <small class="text-muted">
                                <i class="bi bi-hourglass me-1"></i>
                                Duration: <?= $event['duration'] ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($event['venue']): ?>
                        <div class="mb-4">
                            <h6><i class="bi bi-geo-alt me-2"></i>Venue</h6>
                            <p class="mb-0"><?= htmlspecialchars($event['venue']) ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($event['coordinator_name']): ?>
                        <div class="mb-4">
                            <h6><i class="bi bi-person me-2"></i>Event Coordinator</h6>
                            <p class="mb-1"><strong><?= htmlspecialchars($event['coordinator_name']) ?></strong></p>
                            <?php if ($event['coordinator_email']): ?>
                                <p class="mb-1">
                                    <i class="bi bi-envelope me-1"></i>
                                    <a href="mailto:<?= htmlspecialchars($event['coordinator_email']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($event['coordinator_email']) ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                            <?php if ($event['coordinator_phone']): ?>
                                <p class="mb-0">
                                    <i class="bi bi-telephone me-1"></i>
                                    <a href="tel:<?= htmlspecialchars($event['coordinator_phone']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($event['coordinator_phone']) ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Set Reminder (for upcoming events) -->
                    <?php if ($event_date > $today): ?>
                        <div class="mb-4">
                            <h6><i class="bi bi-bell me-2"></i>Set Reminder</h6>
                            <form method="POST" action="" class="mb-3">
                                <input type="hidden" name="set_reminder" value="1">
                                <div class="mb-3">
                                    <label class="form-label small">Remind me via:</label>
                                    <select name="reminder_type" class="form-select form-select-sm">
                                        <option value="notification">Notification</option>
                                        <option value="email">Email</option>
                                        <option value="sms">SMS</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small">Remind me:</label>
                                    <select name="reminder_time" class="form-select form-select-sm">
                                        <option value="1_day_before">1 Day Before</option>
                                        <option value="2_days_before">2 Days Before</option>
                                        <option value="1_week_before">1 Week Before</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100">
                                    <i class="bi bi-bell me-1"></i> Set Reminder
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Share Event -->
                    <div class="mb-4">
                        <h6><i class="bi bi-share me-2"></i>Share Event</h6>
                        <div class="d-flex gap-2">
                            <button onclick="shareEvent('facebook')" class="btn btn-sm btn-outline-primary flex-fill">
                                <i class="bi bi-facebook"></i>
                            </button>
                            <button onclick="shareEvent('twitter')" class="btn btn-sm btn-outline-info flex-fill">
                                <i class="bi bi-twitter"></i>
                            </button>
                            <button onclick="shareEvent('whatsapp')" class="btn btn-sm btn-outline-success flex-fill">
                                <i class="bi bi-whatsapp"></i>
                            </button>
                            <button onclick="copyEventLink()" class="btn btn-sm btn-outline-secondary flex-fill">
                                <i class="bi bi-link"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Map (if venue exists) -->
                    <?php if ($event['venue']): ?>
                        <div class="mb-4">
                            <h6><i class="bi bi-map me-2"></i>Location</h6>
                            <div class="ratio ratio-16x9">
                                <iframe 
                                    src="https://maps.google.com/maps?q=<?= urlencode($event['venue'] . ', Sahab Academy') ?>&t=&z=15&ie=UTF8&iwloc=&output=embed" 
                                    style="border:0; border-radius: 8px;" 
                                    allowfullscreen>
                                </iframe>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Similar Events -->
                <?php if (!empty($similar_events)): ?>
                    <div class="event-sidebar">
                        <h5 class="mb-4"><i class="bi bi-calendar2-event me-2"></i>Similar Events</h5>
                        <?php foreach ($similar_events as $similar): ?>
                            <div class="similar-event-card">
                                <h6 class="mb-2"><?= htmlspecialchars($similar['activity_title']) ?></h6>
                                <p class="mb-1 text-muted small">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?= date('M j, Y', strtotime($similar['activity_date'])) ?>
                                </p>
                                <?php if ($similar['start_time']): ?>
                                    <p class="mb-1 text-muted small">
                                        <i class="bi bi-clock me-1"></i>
                                        <?= date('h:i A', strtotime($similar['start_time'])) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($similar['venue']): ?>
                                    <p class="mb-2 text-muted small">
                                        <i class="bi bi-geo-alt me-1"></i>
                                        <?= htmlspecialchars($similar['venue']) ?>
                                    </p>
                                <?php endif; ?>
                                <a href="event_details.php?id=<?= $similar['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    View Details
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lightgallery@2.7.0/lightgallery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lightgallery@2.7.0/plugins/thumbnail/lg-thumbnail.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lightgallery@2.7.0/plugins/zoom/lg-zoom.min.js"></script>
    <script>
        // Initialize light gallery
        if (document.getElementById('lightgallery')) {
            lightGallery(document.getElementById('lightgallery'), {
                selector: '.gallery-item',
                download: false,
                counter: true,
                getCaptionFromTitleOrAlt: false
            });
        }
        
        // Add to calendar function
        function addToCalendar() {
            const eventTitle = "<?= addslashes($event['activity_title']) ?>";
            const eventDate = "<?= $event['iso_date'] ?>";
            const eventTime = "<?= $event['start_time'] ?>";
            
            // Create ICS file content
            const startDate = new Date(eventDate + 'T' + (eventTime || '09:00:00'));
            const endDate = new Date(startDate.getTime() + (2 * 60 * 60 * 1000)); // 2 hours default
            
            const icsContent = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'CALSCALE:GREGORIAN',
                'BEGIN:VEVENT',
                'SUMMARY:' + eventTitle,
                'DTSTART:' + startDate.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z',
                'DTEND:' + endDate.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z',
                'DESCRIPTION:' + "<?= addslashes($event['description'] ?: '') ?>",
                'LOCATION:' + "<?= addslashes($event['venue'] ?: 'Sahab Academy') ?>",
                'UID:' + "<?= $event_id ?>" + '@sahabacademy.com',
                'DTSTAMP:' + new Date().toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z',
                'STATUS:CONFIRMED',
                'END:VEVENT',
                'END:VCALENDAR'
            ].join('\r\n');
            
            // Create download link
            const blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            
            const link = document.createElement('a');
            link.href = url;
            link.download = eventTitle.replace(/\s+/g, '_') + '.ics';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            
            alert('Event added to your calendar! You can import the .ics file to Google Calendar, Outlook, or Apple Calendar.');
        }
        
        // Share event function
        function shareEvent(platform) {
            const eventTitle = "<?= addslashes($event['activity_title']) ?>";
            const eventUrl = window.location.href;
            const eventDate = "<?= $event['formatted_date'] ?>";
            
            let shareUrl = '';
            
            switch (platform) {
                case 'facebook':
                    shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(eventUrl);
                    break;
                case 'twitter':
                    shareUrl = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(eventTitle + ' - ' + eventDate) + '&url=' + encodeURIComponent(eventUrl);
                    break;
                case 'whatsapp':
                    shareUrl = 'https://wa.me/?text=' + encodeURIComponent(eventTitle + ' - ' + eventDate + ' ' + eventUrl);
                    break;
                default:
                    if (navigator.share) {
                        navigator.share({
                            title: eventTitle,
                            text: 'Check out this school event: ' + eventTitle + ' on ' + eventDate,
                            url: eventUrl
                        });
                        return;
                    } else {
                        shareUrl = eventUrl;
                    }
            }
            
            if (shareUrl) {
                window.open(shareUrl, '_blank', 'width=600,height=400');
            }
        }
        
        // Copy event link to clipboard
        function copyEventLink() {
            const eventUrl = window.location.href;
            
            navigator.clipboard.writeText(eventUrl).then(() => {
                alert('Event link copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy: ', err);
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = eventUrl;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('Event link copied to clipboard!');
            });
        }
        
        // Print event details
        function printEvent() {
            window.print();
        }
        
        // Initialize tooltips
        $(function () {
            $('[data-bs-toggle="tooltip"]').tooltip();
        });
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
