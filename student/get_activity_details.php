<?php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Handle reminder setting via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_reminder') {
    $activity_id = intval($_POST['activity_id'] ?? 0);
    $student_id = isset($_SESSION['student_id']) ? $_SESSION['student_id'] : $_SESSION['user_id'];
    $reminder_time = $_POST['reminder_time'] ?? '1_hour';

    if (!$activity_id) {
        echo json_encode(['success' => false, 'message' => 'Activity ID is required']);
        exit;
    }

    try {
        if ($action === 'set_reminder') {
            // Check if reminder already exists
            $check_stmt = $pdo->prepare("SELECT id FROM student_reminders WHERE student_id = ? AND activity_id = ?");
            $check_stmt->execute([$student_id, $activity_id]);
            $existing_reminder = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_reminder) {
                // Update existing reminder
                $update_stmt = $pdo->prepare("UPDATE student_reminders SET reminder_time = ?, updated_at = NOW() WHERE id = ?");
                $update_stmt->execute([$reminder_time, $existing_reminder['id']]);
                echo json_encode(['success' => true, 'message' => 'Reminder updated successfully!', 'action' => 'updated']);
            } else {
                // Create new reminder
                $insert_stmt = $pdo->prepare("INSERT INTO student_reminders (student_id, activity_id, reminder_time) VALUES (?, ?, ?)");
                $insert_stmt->execute([$student_id, $activity_id, $reminder_time]);
                echo json_encode(['success' => true, 'message' => 'Reminder set successfully!', 'action' => 'created']);
            }
        } elseif ($action === 'remove_reminder') {
            // Remove reminder
            $delete_stmt = $pdo->prepare("UPDATE student_reminders SET is_active = 0, updated_at = NOW() WHERE student_id = ? AND activity_id = ? AND is_active = 1");
            $delete_stmt->execute([$student_id, $activity_id]);
            echo json_encode(['success' => true, 'message' => 'Reminder removed successfully!', 'action' => 'removed']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update reminder: ' . $e->getMessage()]);
    }
    exit;
}

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Activity ID is required']);
    exit;
}

$activity_id = intval($_GET['id']);
$student_id = isset($_SESSION['student_id']) ? $_SESSION['student_id'] : $_SESSION['user_id'];

// Get student details
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
        echo json_encode(['error' => 'Student not found']);
        exit;
    }

    // Get activity details with permission check
    $query = "
        SELECT sd.*, ac.category_name, ac.color, ac.icon, u.full_name as coordinator_name
        FROM school_diary sd
        LEFT JOIN activity_categories ac ON sd.category_id = ac.id
        LEFT JOIN users u ON sd.coordinator_id = u.id
        WHERE sd.id = ?
        AND sd.status != 'Cancelled'
        AND (sd.target_audience = 'All'
             OR sd.target_audience = 'Secondary Only'
             OR (sd.target_audience = 'Specific Classes' AND FIND_IN_SET(?, REPLACE(sd.target_classes, ', ', ','))))
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$activity_id, $student['class_name'] ?: '']);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        echo json_encode(['error' => 'Activity not found or you don\'t have permission to view it']);
        exit;
    }

    // Get attachments
    $attachments_stmt = $pdo->prepare("SELECT * FROM school_diary_attachments WHERE diary_id = ?");
    $attachments_stmt->execute([$activity_id]);
    $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format date and time
    $activity_date = date('F j, Y', strtotime($activity['activity_date']));
    $start_time = $activity['start_time'] ? date('h:i A', strtotime($activity['start_time'])) : 'Not specified';
    $end_time = $activity['end_time'] ? date('h:i A', strtotime($activity['end_time'])) : 'Not specified';
    $time_display = ($activity['start_time'] && $activity['end_time']) ? 
                   "$start_time to $end_time" : $start_time;
    
    // Determine status badge color
    $status_colors = [
        'Upcoming' => 'success',
        'Ongoing' => 'info',
        'Completed' => 'primary',
        'Cancelled' => 'danger'
    ];
    $status_color = $status_colors[$activity['status']] ?? 'secondary';
    
    // Check if activity has completion details
    $has_completion_details = $activity['status'] === 'Completed' && 
                             ($activity['participant_count'] || $activity['winners_list'] || 
                              $activity['achievements'] || $activity['feedback_summary']);

    // Generate HTML output
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>`r`n<meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Activity Details</title>
        <style>
            /* Activity Details Modal Styles */
            .modal-dialog {
                max-width: 800px;
                margin: 1rem auto;
            }
            
            .modal-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border-bottom: none;
                padding: 1.25rem 1.5rem;
                border-radius: 0.5rem 0.5rem 0 0;
            }
            
            .modal-header .btn-close {
                filter: invert(1);
                opacity: 0.8;
                background: rgba(255,255,255,0.2);
                padding: 0.5rem;
                border-radius: 0.25rem;
            }
            
            .modal-header .btn-close:hover {
                opacity: 1;
                background: rgba(255,255,255,0.3);
            }
            
            .modal-body {
                max-height: 70vh;
                overflow-y: auto;
                padding: 1.5rem;
                background: #f8f9fa;
            }
            
            /* Status Badges */
            .activity-badge {
                font-size: 0.85rem;
                padding: 0.5em 1.2em;
                border-radius: 20px;
                font-weight: 600;
                letter-spacing: 0.5px;
                box-shadow: 0 3px 6px rgba(0,0,0,0.15);
                text-transform: uppercase;
                border: 2px solid rgba(255,255,255,0.3);
            }
            
            .badge-success {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            }
            
            .badge-info {
                background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            }
            
            .badge-primary {
                background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            }
            
            .badge-danger {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            }
            
            /* Info Cards */
            .info-card {
                border: none;
                border-radius: 12px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                background: white;
                overflow: hidden;
                margin-bottom: 1.5rem;
            }
            
            .info-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            }
            
            .info-card .card-body {
                padding: 1.5rem;
            }
            
            /* Section Headers */
            .section-header {
                color: #4f46e5;
                font-weight: 600;
                margin-bottom: 1rem;
                padding-bottom: 0.75rem;
                border-bottom: 2px solid #eef2ff;
                display: flex;
                align-items: center;
                font-size: 1.1rem;
            }
            
            .section-header i {
                margin-right: 0.75rem;
                font-size: 1.2em;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            
            .main-title {
                color: white !important;
                font-weight: 700;
                margin: 0;
                font-size: 1.5rem;
                display: flex;
                align-items: center;
            }
            
            .main-title i {
                margin-right: 0.75rem;
                font-size: 1.4em;
            }
            
            /* Text Content */
            .text-content {
                line-height: 1.7;
                color: #4b5563;
                font-size: 0.95rem;
            }
            
            .content-box {
                background-color: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                padding: 1.25rem;
                margin-bottom: 1.25rem;
                box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            }
            
            /* Attachment Cards */
            .attachment-card {
                border: 2px solid #e5e7eb;
                border-radius: 12px;
                transition: all 0.3s ease;
                background: white;
                height: 100%;
                overflow: hidden;
            }
            
            .attachment-card:hover {
                border-color: #4f46e5;
                box-shadow: 0 8px 20px rgba(79, 70, 229, 0.15);
                transform: translateY(-3px);
            }
            
            .attachment-card .card-body {
                padding: 1rem;
            }
            
            .attachment-card img {
                border-radius: 8px;
                transition: transform 0.3s ease;
                height: 120px;
                object-fit: cover;
                width: 100%;
                margin-bottom: 0.75rem;
            }
            
            .attachment-card img:hover {
                transform: scale(1.08);
            }
            
            /* Button Styles */
            .modal-footer {
                background: white;
                border-top: 1px solid #e5e7eb;
                padding: 1.25rem 1.5rem;
                border-radius: 0 0 0.5rem 0.5rem;
            }
            
            .btn-outline-primary {
                border: 2px solid #4f46e5;
                color: #4f46e5;
                font-weight: 600;
                border-radius: 8px;
                padding: 0.5rem 1.25rem;
                transition: all 0.3s ease;
            }
            
            .btn-outline-primary:hover {
                background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
                color: white;
                border-color: transparent;
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(79, 70, 229, 0.25);
            }
            
            .btn-warning {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                border: none;
                color: white;
                font-weight: 600;
                border-radius: 8px;
                padding: 0.5rem 1.25rem;
                transition: all 0.3s ease;
            }
            
            .btn-warning:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(245, 158, 11, 0.25);
            }
            
            .btn-danger {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                border: none;
                color: white;
                font-weight: 600;
                border-radius: 8px;
                padding: 0.5rem 1.25rem;
                transition: all 0.3s ease;
            }
            
            .btn-danger:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(239, 68, 68, 0.25);
            }
            
            .btn-secondary {
                background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
                border: none;
                color: white;
                font-weight: 600;
                border-radius: 8px;
                padding: 0.5rem 1.25rem;
                transition: all 0.3s ease;
            }
            
            .btn-secondary:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(107, 114, 128, 0.25);
            }
            
            /* Icon Styling */
            .info-icon {
                color: #4f46e5;
                font-size: 1.1em;
                margin-right: 0.5rem;
                min-width: 24px;
                text-align: center;
            }
            
            /* Statistics Box */
            .stat-box {
                background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
                border: 2px solid #bae6fd;
                border-radius: 12px;
                padding: 1.25rem;
                text-align: center;
                min-width: 140px;
                box-shadow: 0 4px 12px rgba(0, 183, 255, 0.1);
            }
            
            .stat-box .stat-number {
                font-size: 2.5rem;
                font-weight: 800;
                color: #0369a1;
                line-height: 1;
                text-shadow: 0 2px 4px rgba(3, 105, 161, 0.2);
            }
            
            .stat-box .stat-label {
                font-size: 0.9rem;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin-top: 0.5rem;
                font-weight: 600;
            }
            
            /* Custom Scrollbar */
            .modal-body::-webkit-scrollbar {
                width: 10px;
            }
            
            .modal-body::-webkit-scrollbar-track {
                background: #f1f1f1;
                border-radius: 8px;
                margin: 4px;
            }
            
            .modal-body::-webkit-scrollbar-thumb {
                background: linear-gradient(180deg, #c7d2fe, #a5b4fc);
                border-radius: 8px;
                border: 2px solid #f1f1f1;
            }
            
            .modal-body::-webkit-scrollbar-thumb:hover {
                background: linear-gradient(180deg, #a5b4fc, #818cf8);
            }
            
            /* Responsive Adjustments */
            @media (max-width: 992px) {
                .modal-dialog {
                    max-width: 95%;
                    margin: 0.5rem;
                }
                
                .modal-body {
                    padding: 1.25rem;
                }
                
                .stat-box {
                    min-width: 120px;
                    padding: 1rem;
                }
                
                .stat-box .stat-number {
                    font-size: 2rem;
                }
                
                .activity-badge {
                    font-size: 0.8rem;
                    padding: 0.4em 1em;
                }
            }
            
            @media (max-width: 768px) {
                .modal-header {
                    padding: 1rem 1.25rem;
                }
                
                .main-title {
                    font-size: 1.25rem;
                }
                
                .modal-body {
                    padding: 1rem;
                }
                
                .content-box {
                    padding: 1rem;
                }
                
                .btn {
                    padding: 0.5rem 1rem;
                    font-size: 0.9rem;
                }
                
                .attachment-card img {
                    height: 100px;
                }
                
                .info-card .card-body {
                    padding: 1.25rem;
                }
                
                .section-header {
                    font-size: 1rem;
                }
            }
            
            @media (max-width: 576px) {
                .modal-header {
                    flex-direction: column;
                    align-items: flex-start;
                    padding: 0.75rem 1rem;
                }
                
                .main-title {
                    font-size: 1.1rem;
                    margin-bottom: 0.5rem;
                }
                
                .modal-header .btn-close {
                    position: absolute;
                    top: 0.75rem;
                    right: 0.75rem;
                }
                
                .modal-body {
                    max-height: 60vh;
                    padding: 0.75rem;
                }
                
                .btn {
                    width: 100%;
                    margin-bottom: 0.5rem;
                    padding: 0.625rem;
                }
                
                .modal-footer {
                    flex-direction: column;
                    padding: 1rem;
                }
                
                .attachment-card img {
                    height: 80px;
                }
                
                .stat-box {
                    min-width: 100px;
                    padding: 0.75rem;
                }
                
                .stat-box .stat-number {
                    font-size: 1.75rem;
                }
                
                .text-content {
                    font-size: 0.9rem;
                }
                
                .info-card {
                    margin-bottom: 1rem;
                }
            }
            
            @media (max-width: 400px) {
                .main-title {
                    font-size: 1rem;
                }
                
                .activity-badge {
                    font-size: 0.7rem;
                }
                
                .btn {
                    font-size: 0.85rem;
                }
            }
            
            /* Animation for Loading */
            @keyframes fadeInUp {
                from { 
                    opacity: 0; 
                    transform: translateY(20px); 
                }
                to { 
                    opacity: 1; 
                    transform: translateY(0); 
                }
            }
            
            .modal-content {
                animation: fadeInUp 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                border-radius: 0.75rem;
                overflow: hidden;
                box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            }
            
            /* Metadata Footer */
            .metadata-footer {
                background: #f8fafc;
                border-top: 1px solid #e2e8f0;
                padding: 1rem;
                font-size: 0.85rem;
                color: #64748b;
                border-radius: 8px;
                margin-top: 1.5rem;
            }
            
            /* File Type Icons */
            .file-icon {
                font-size: 2.5rem;
                display: block;
                margin-bottom: 0.75rem;
                opacity: 0.9;
            }
            
            .file-icon.image {
                color: #3b82f6;
            }
            
            .file-icon.video {
                color: #ef4444;
            }
            
            .file-icon.document {
                color: #10b981;
            }
            
            .file-icon.other {
                color: #8b5cf6;
            }
            
            /* Hover Effects */
            .hover-lift {
                transition: transform 0.3s ease;
            }
            
            .hover-lift:hover {
                transform: translateY(-4px);
            }
            
            /* Text Truncation */
            .text-truncate-2 {
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                line-height: 1.4;
            }
            
            /* Section Spacing */
            .section-spacing {
                margin-top: 1.75rem;
                margin-bottom: 1.75rem;
            }
            
            /* Completion Details Section */
            .completion-section {
                background: linear-gradient(135deg, #f0f9ff 0%, #f8fafc 100%);
                border-radius: 12px;
                padding: 1.75rem;
                border-left: 5px solid #3b82f6;
                margin-top: 2rem;
                box-shadow: 0 4px 12px rgba(0, 123, 255, 0.1);
            }
            
            /* Grid System for Mobile */
            .grid-mobile {
                display: grid;
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            @media (min-width: 576px) {
                .grid-mobile {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
            
            @media (min-width: 768px) {
                .grid-mobile {
                    grid-template-columns: repeat(3, 1fr);
                }
            }
            
            @media (min-width: 992px) {
                .grid-mobile {
                    grid-template-columns: repeat(4, 1fr);
                }
            }
            
            /* Print Styles */
            @media print {
                .modal-dialog {
                    max-width: 100%;
                    margin: 0;
                }
                
                .modal-body {
                    max-height: none;
                    overflow: visible;
                }
                
                .btn {
                    display: none;
                }
                
                .modal-footer {
                    display: none;
                }
            }
            
            /* Utility Classes */
            .mb-4 {
                margin-bottom: 2rem !important;
            }
            
            .mt-4 {
                margin-top: 2rem !important;
            }
            
            .pt-4 {
                padding-top: 2rem !important;
            }
            
            .pb-4 {
                padding-bottom: 2rem !important;
            }
            
            .fs-sm {
                font-size: 0.875rem !important;
            }
            
            .fw-semibold {
                font-weight: 600 !important;
            }
            
            .text-muted-light {
                color: #9ca3af !important;
            }
        </style>
    </head>
    <body>
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="main-title">
                    <i class="bi bi-journal-text"></i>
                    <?= htmlspecialchars($activity['activity_title']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body">
                <!-- Status and Basic Info -->
                <div class="info-card">
                    <div class="card-body">
                        <div class="row align-items-center mb-3">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
                                    <span class="activity-badge badge-<?= $status_color ?>">
                                        <?= $activity['status'] ?>
                                    </span>
                                    <span class="text-muted d-flex align-items-center">
                                        <i class="bi bi-tag me-2"></i>
                                        <?= htmlspecialchars($activity['activity_type']) ?>
                                    </span>
                                </div>
                                <div class="grid-mobile">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-calendar-date info-icon"></i>
                                        <div>
                                            <div class="fw-semibold fs-sm text-muted-light">Date</div>
                                            <div class="fw-bold"><?= $activity_date ?></div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($time_display !== 'Not specified'): ?>
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-clock info-icon"></i>
                                        <div>
                                            <div class="fw-semibold fs-sm text-muted-light">Time</div>
                                            <div class="fw-bold"><?= $time_display ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($activity['venue']): ?>
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-geo-alt info-icon"></i>
                                        <div>
                                            <div class="fw-semibold fs-sm text-muted-light">Venue</div>
                                            <div class="fw-bold"><?= htmlspecialchars($activity['venue']) ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($activity['participant_count']): ?>
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-people info-icon"></i>
                                        <div>
                                            <div class="fw-semibold fs-sm text-muted-light">Participants</div>
                                            <div class="fw-bold"><?= $activity['participant_count'] ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                <?php if ($activity['participant_count']): ?>
                                <div class="stat-box d-inline-block">
                                    <div class="stat-number"><?= $activity['participant_count'] ?></div>
                                    <div class="stat-label">Participants</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="row">
                            <!-- Left Column -->
                            <div class="col-lg-6">
                                <!-- Organizing Department -->
                                <?php if ($activity['organizing_dept']): ?>
                                <div class="mb-4">
                                    <h6 class="section-header">
                                        <i class="bi bi-building"></i>
                                        Organizing Department
                                    </h6>
                                    <div class="content-box">
                                        <p class="mb-0 fw-semibold"><?= htmlspecialchars($activity['organizing_dept']) ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Coordinator -->
                                <div class="mb-4">
                                    <h6 class="section-header">
                                        <i class="bi bi-person-badge"></i>
                                        Coordinator
                                    </h6>
                                    <div class="content-box">
                                        <p class="mb-0">
                                            <?= $activity['coordinator_name'] ? 
                                               '<span class="fw-semibold">' . htmlspecialchars($activity['coordinator_name']) . '</span>' : 
                                               '<span class="text-muted-light fst-italic">Not assigned</span>' ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <!-- Target Audience -->
                                <div class="mb-4">
                                    <h6 class="section-header">
                                        <i class="bi bi-people"></i>
                                        Target Audience
                                    </h6>
                                    <div class="content-box">
                                        <p class="mb-1 fw-semibold"><?= htmlspecialchars($activity['target_audience']) ?></p>
                                        <?php if ($activity['target_classes']): ?>
                                            <p class="mb-0 text-muted-light">
                                                <small>Classes: <?= htmlspecialchars($activity['target_classes']) ?></small>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Resources -->
                                <?php if ($activity['resources']): ?>
                                <div class="mb-4">
                                    <h6 class="section-header">
                                        <i class="bi bi-tools"></i>
                                        Resources Required
                                    </h6>
                                    <div class="content-box">
                                        <p class="text-content mb-0"><?= nl2br(htmlspecialchars($activity['resources'])) ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Right Column -->
                            <div class="col-lg-6">
                                <!-- Description -->
                                <div class="mb-4">
                                    <h6 class="section-header">
                                        <i class="bi bi-card-text"></i>
                                        Description
                                    </h6>
                                    <div class="content-box">
                                        <p class="text-content mb-0"><?= nl2br(htmlspecialchars($activity['description'])) ?></p>
                                    </div>
                                </div>
                                
                                <!-- Objectives -->
                                <?php if ($activity['objectives']): ?>
                                <div class="mb-4">
                                    <h6 class="section-header">
                                        <i class="bi bi-bullseye"></i>
                                        Objectives
                                    </h6>
                                    <div class="content-box">
                                        <p class="text-content mb-0"><?= nl2br(htmlspecialchars($activity['objectives'])) ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Attachments Section -->
                        <?php if (!empty($attachments)): ?>
                        <div class="mb-4 pt-4 border-top">
                            <h6 class="section-header">
                                <i class="bi bi-paperclip"></i>
                                Attachments
                            </h6>
                            <div class="row g-3 mt-3">
                                <?php foreach ($attachments as $attachment): ?>
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <div class="attachment-card hover-lift">
                                            <div class="card-body text-center">
                                                <?php if ($attachment['file_type'] == 'image'): ?>
                                                    <img src="<?= htmlspecialchars('../admin/' . $attachment['file_path']) ?>"
                                                         alt="<?= htmlspecialchars($attachment['file_name']) ?>"
                                                         class="img-fluid rounded mb-3"
                                                         onclick="openImageModal('<?= htmlspecialchars('../admin/' . $attachment['file_path']) ?>')">
                                                    <p class="small text-truncate-2 fw-semibold mb-2"><?= htmlspecialchars($attachment['file_name']) ?></p>
                                                    <a href="<?= htmlspecialchars('../admin/' . $attachment['file_path']) ?>"
                                                       download
                                                       class="btn btn-outline-primary btn-sm w-100">
                                                        <i class="bi bi-download me-1"></i> Download
                                                    </a>

                                                <?php elseif ($attachment['file_type'] == 'video'): ?>
                                                    <div class="mb-3">
                                                        <i class="bi bi-play-circle-fill text-primary file-icon video"></i>
                                                    </div>
                                                    <p class="small text-truncate-2 fw-semibold mb-2"><?= htmlspecialchars($attachment['file_name']) ?></p>
                                                    <a href="<?= htmlspecialchars('../admin/' . $attachment['file_path']) ?>"
                                                       download
                                                       class="btn btn-outline-primary btn-sm w-100">
                                                        <i class="bi bi-download me-1"></i> Download
                                                    </a>

                                                <?php else: ?>
                                                    <div class="mb-3">
                                                        <i class="bi bi-file-earmark-text file-icon document"></i>
                                                    </div>
                                                    <p class="small text-truncate-2 fw-semibold mb-2"><?= htmlspecialchars($attachment['file_name']) ?></p>
                                                    <a href="<?= htmlspecialchars('../admin/' . $attachment['file_path']) ?>"
                                                       download
                                                       class="btn btn-outline-primary btn-sm w-100">
                                                        <i class="bi bi-download me-1"></i> Download
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Completion Details (Only for Completed Activities) -->
                        <?php if ($has_completion_details): ?>
                        <div class="completion-section">
                            <h5 class="section-header mb-4">
                                <i class="bi bi-check-circle"></i>
                                Activity Completion Details
                            </h5>
                            
                            <div class="row align-items-start mb-4">
                                <?php if ($activity['participant_count']): ?>
                                <div class="col-md-3 mb-4 mb-md-0">
                                    <div class="stat-box">
                                        <div class="stat-number"><?= $activity['participant_count'] ?></div>
                                        <div class="stat-label">Participants</div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($activity['winners_list']): ?>
                                <div class="col-md-9">
                                    <h6 class="section-header">
                                        <i class="bi bi-trophy"></i>
                                        Winners List
                                    </h6>
                                    <div class="content-box">
                                        <p class="text-content mb-0"><?= nl2br(htmlspecialchars($activity['winners_list'])) ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($activity['achievements']): ?>
                            <div class="mb-4">
                                <h6 class="section-header">
                                    <i class="bi bi-star"></i>
                                    Achievements
                                </h6>
                                <div class="content-box">
                                    <p class="text-content mb-0"><?= nl2br(htmlspecialchars($activity['achievements'])) ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($activity['feedback_summary']): ?>
                            <div class="mb-4">
                                <h6 class="section-header">
                                    <i class="bi bi-chat-left-text"></i>
                                    Feedback Summary
                                </h6>
                                <div class="content-box">
                                    <p class="text-content mb-0"><?= nl2br(htmlspecialchars($activity['feedback_summary'])) ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Activity Metadata -->
                        <div class="metadata-footer">
                            <div class="row align-items-center">
                                <div class="col-md-6 mb-2 mb-md-0">
                                    <i class="bi bi-person-plus me-2"></i> 
                                    <span class="fw-semibold">Created by Principal</span>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <i class="bi bi-clock-history me-2"></i>
                                    <span class="fw-semibold">Last updated: <?= date('M j, Y \a\t h:i A', strtotime($activity['updated_at'] ?? $activity['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode([
        'title' => htmlspecialchars($activity['activity_title']),
        'html' => $html
    ]);

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}





