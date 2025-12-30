<?php
// student_class_activities.php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: ../student_login.php');
    exit();
}

$student_id = $_SESSION['student_id'];
$action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';
$activity_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'submit' && $activity_id > 0) {
        // Handle submission
        $submission_text = $_POST['submission_text'] ?? '';
        $attachment = $_FILES['attachment'] ?? null;
        
        // Check if activity exists and is still open
        $activity_check = "
            SELECT ca.* FROM class_activities ca 
            WHERE ca.id = ? AND ca.class_id = (
                SELECT class_id FROM students WHERE id = ?
            ) AND ca.status = 'published'
        ";
        $check_stmt = $pdo->prepare($activity_check);
        $check_stmt->execute([$activity_id, $student_id]);
        $activity = $check_stmt->fetch();
        
        if (!$activity) {
            $_SESSION['error'] = 'Activity not found or closed for submission.';
            header("Location: student_class_activities.php?action=view&id=$activity_id");
            exit();
        }
        
        // Check if already submitted
        $submission_check = "SELECT id FROM student_submissions WHERE activity_id = ? AND student_id = ?";
        $sub_check_stmt = $pdo->prepare($submission_check);
        $sub_check_stmt->execute([$activity_id, $student_id]);
        
        if ($sub_check_stmt->rowCount() > 0) {
            $_SESSION['error'] = 'You have already submitted this activity.';
            header("Location: student_class_activities.php?action=view&id=$activity_id");
            exit();
        }
        
        // Handle file upload
        $attachment_path = null;
        if ($attachment && $attachment['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip', 'txt'];
            $file_ext = strtolower(pathinfo($attachment['name'], PATHINFO_EXTENSION));
            
            if (in_array($file_ext, $allowed_types)) {
                $upload_dir = '../uploads/student_submissions/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_name = 'sub_' . $student_id . '_' . time() . '_' . basename($attachment['name']);
                $target_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($attachment['tmp_name'], $target_path)) {
                    $attachment_path = $target_path;
                }
            }
        }
        
        // Determine submission status
        $status = 'submitted';
        if ($activity['due_date'] && strtotime($activity['due_date']) < time()) {
            $status = 'late';
        }
        
        // Insert submission
        $insert_sql = "
            INSERT INTO student_submissions 
            (activity_id, student_id, submission_text, attachment_path, status, submitted_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ";
        $insert_stmt = $pdo->prepare($insert_sql);
        $insert_stmt->execute([
            $activity_id,
            $student_id,
            $submission_text,
            $attachment_path,
            $status
        ]);
        
        $_SESSION['success'] = 'Submission successful!';
        header("Location: student_class_activities.php?action=view&id=$activity_id");
        exit();
    }
}

// Get student info
$student_query = "SELECT s.*, c.class_name FROM students s 
                  JOIN classes c ON s.class_id = c.id 
                  WHERE s.id = ?";
$student_stmt = $pdo->prepare($student_query);
$student_stmt->execute([$student_id]);
$student = $student_stmt->fetch();

if (!$student) {
    die('Student not found');
}

$student_class_id = $student['class_id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Activities - Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .activity-card { transition: transform 0.2s; border-left: 4px solid #0d6efd; }
        .activity-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .activity-classwork { border-left-color: #198754 !important; }
        .activity-assignment { border-left-color: #0dcaf0 !important; }
        .activity-quiz { border-left-color: #ffc107 !important; }
        .activity-project { border-left-color: #6f42c1 !important; }
        .activity-homework { border-left-color: #fd7e14 !important; }
        .stats-card { border-radius: 10px; }
        .due-soon { background-color: #fff3cd !important; }
        .overdue { background-color: #f8d7da !important; }
        .submitted { background-color: #d1e7dd !important; }
        .graded { background-color: #cfe2ff !important; }
        .badge-due { font-size: 0.7em; }
        .form-page { max-width: 900px; margin: 0 auto; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">My Class Activities</a>
            <div class="navbar-nav ms-auto">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="student_class_activities.php?action=dashboard" class="nav-link">Activities</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php
        // Show messages
        if (isset($_SESSION['success'])) {
            echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                    ' . $_SESSION['success'] . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                  </div>';
            unset($_SESSION['success']);
        }
        
        if (isset($_SESSION['error'])) {
            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                    ' . $_SESSION['error'] . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                  </div>';
            unset($_SESSION['error']);
        }
        ?>
        
        <?php if ($action === 'feedback'): ?>
            <!-- Feedback Page (replaces modal) -->
            <?php
            $feedback_id = isset($_GET['feedback_id']) ? intval($_GET['feedback_id']) : 0;
            
            $feedback_query = "
                SELECT ss.*, ca.title as activity_title, ca.total_marks,
                       ca.activity_type, ca.due_date
                FROM student_submissions ss 
                JOIN class_activities ca ON ss.activity_id = ca.id 
                WHERE ss.id = ? AND ss.student_id = ?
            ";
            $feedback_stmt = $pdo->prepare($feedback_query);
            $feedback_stmt->execute([$feedback_id, $student_id]);
            $feedback = $feedback_stmt->fetch();
            
            if (!$feedback) {
                echo '<div class="alert alert-danger">Feedback not found.</div>';
                echo '<a href="student_class_activities.php?action=graded" class="btn btn-primary">Back to Graded Activities</a>';
                exit();
            }
            ?>
            
            <div class="form-page">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Feedback for: <?= htmlspecialchars($feedback['activity_title']) ?></h2>
                    <a href="student_class_activities.php?action=graded" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Graded Activities
                    </a>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">Activity Details</h4>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <p><strong>Activity Title:</strong> <?= htmlspecialchars($feedback['activity_title']) ?></p>
                                <p><strong>Type:</strong> <?= ucfirst($feedback['activity_type']) ?></p>
                                <p><strong>Due Date:</strong> 
                                    <?= $feedback['due_date'] ? date('M d, Y H:i', strtotime($feedback['due_date'])) : 'Not set' ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Total Marks:</strong> <?= $feedback['total_marks'] ?></p>
                                <p><strong>Your Score:</strong> 
                                    <span class="badge bg-success fs-5">
                                        <?= $feedback['marks_obtained'] ?>/<?= $feedback['total_marks'] ?>
                                    </span>
                                </p>
                                <p><strong>Submitted On:</strong> 
                                    <?= date('M d, Y H:i', strtotime($feedback['submitted_at'])) ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="card bg-light">
                            <div class="card-header">
                                <h5 class="mb-0">Teacher Feedback</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($feedback['feedback']): ?>
                                    <div class="p-3 bg-white rounded">
                                        <?= nl2br(htmlspecialchars($feedback['feedback'])) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        No feedback provided by the teacher.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <a href="student_class_activities.php?action=graded" class="btn btn-primary">Back to Graded Activities</a>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'view' && $activity_id > 0): ?>
            <!-- View Activity Details Page -->
            <?php
            $activity_query = "
                SELECT ca.*, s.subject_name, u.full_name as teacher_name,
                       ss.id as submission_id, ss.submission_text, ss.attachment_path as student_attachment,
                       ss.marks_obtained, ss.feedback, ss.status as submission_status,
                       ss.graded_at
                FROM class_activities ca 
                JOIN subjects s ON ca.subject_id = s.id 
                JOIN users u ON ca.teacher_id = u.id 
                LEFT JOIN student_submissions ss ON ca.id = ss.activity_id AND ss.student_id = ?
                WHERE ca.id = ? AND ca.class_id = ?
            ";
            $activity_stmt = $pdo->prepare($activity_query);
            $activity_stmt->execute([$student_id, $activity_id, $student_class_id]);
            $activity = $activity_stmt->fetch();
            
            if (!$activity):
            ?>
            <div class="alert alert-danger">
                Activity not found or access denied.
                <a href="student_class_activities.php" class="btn btn-sm btn-primary ms-3">Back to Activities</a>
            </div>
            <?php else: ?>
            <div class="form-page">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><?= htmlspecialchars($activity['title']) ?></h2>
                    <a href="student_class_activities.php?action=dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Activities
                    </a>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">Activity Details</h4>
                        <small class="text-muted">
                            <?= $activity['subject_name'] ?> • <?= $activity['teacher_name'] ?>
                        </small>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <h6>Description</h6>
                                <p><?= nl2br(htmlspecialchars($activity['description'])) ?></p>
                                
                                <h6 class="mt-4">Instructions</h6>
                                <p><?= nl2br(htmlspecialchars($activity['instructions'])) ?></p>
                                
                                <?php if ($activity['attachment_path']): ?>
                                <div class="mt-3">
                                    <a href="<?= $activity['attachment_path'] ?>" 
                                       class="btn btn-outline-primary" target="_blank">
                                        <i class="bi bi-download"></i> Download Activity Attachment
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6>Activity Details</h6>
                                        <p><strong>Type:</strong> <?= ucfirst($activity['activity_type']) ?></p>
                                        <p><strong>Due Date:</strong> 
                                            <?php if ($activity['due_date']): ?>
                                                <?= date('M d, Y H:i', strtotime($activity['due_date'])) ?>
                                                <?php if (strtotime($activity['due_date']) < time()): ?>
                                                    <span class="badge bg-danger">Overdue</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                No due date
                                            <?php endif; ?>
                                        </p>
                                        <p><strong>Total Marks:</strong> <?= $activity['total_marks'] ?></p>
                                        <p><strong>Your Status:</strong> 
                                            <span class="badge bg-<?= 
                                                $activity['submission_status'] === 'graded' ? 'success' : 
                                                ($activity['submission_status'] === 'submitted' ? 'info' : 
                                                ($activity['submission_status'] === 'late' ? 'warning' : 'secondary')) 
                                            ?>">
                                                <?= $activity['submission_status'] ? ucfirst($activity['submission_status']) : 'Not submitted' ?>
                                            </span>
                                        </p>
                                        <?php if ($activity['marks_obtained']): ?>
                                            <p><strong>Your Score:</strong> 
                                                <span class="badge bg-success fs-6">
                                                    <?= $activity['marks_obtained'] ?>/<?= $activity['total_marks'] ?>
                                                </span>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Submission Section -->
                        <?php if ($activity['submission_id']): ?>
                            <div class="card mt-4">
                                <div class="card-header bg-info text-white">
                                    <h5 class="mb-0">Your Submission</h5>
                                </div>
                                <div class="card-body">
                                    <p><strong>Submission Text:</strong></p>
                                    <div class="p-3 bg-light rounded mb-3">
                                        <?= $activity['submission_text'] ? nl2br(htmlspecialchars($activity['submission_text'])) : '<em>No text submitted</em>' ?>
                                    </div>
                                    
                                    <?php if ($activity['student_attachment']): ?>
                                        <p><strong>Attachment:</strong></p>
                                        <a href="<?= $activity['student_attachment'] ?>" 
                                           class="btn btn-outline-secondary mb-3" target="_blank">
                                            <i class="bi bi-download"></i> Download Your Submission
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($activity['feedback']): ?>
                                        <div class="card mt-4">
                                            <div class="card-header bg-success text-white">
                                                <h5 class="mb-0">Teacher Feedback</h5>
                                            </div>
                                            <div class="card-body">
                                                <p><strong>Score:</strong> 
                                                    <span class="badge bg-success fs-5">
                                                        <?= $activity['marks_obtained'] ?>/<?= $activity['total_marks'] ?>
                                                    </span>
                                                    <?php if ($activity['graded_at']): ?>
                                                        <span class="text-muted ms-2">
                                                            Graded on: <?= date('M d, Y', strtotime($activity['graded_at'])) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </p>
                                                <p><strong>Feedback:</strong></p>
                                                <div class="p-3 bg-white rounded">
                                                    <?= nl2br(htmlspecialchars($activity['feedback'])) ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-4">
                                        <a href="student_class_activities.php?action=dashboard" class="btn btn-primary">
                                            Back to Activities
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Submission Form -->
                            <div class="card mt-4">
                                <div class="card-header">
                                    <h5 class="mb-0">Submit Your Work</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="activity_id" value="<?= $activity_id ?>">
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Submission Text *</label>
                                            <textarea class="form-control" name="submission_text" rows="5" 
                                                      placeholder="Type your answer or submission here..." required></textarea>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Attachment (Optional)</label>
                                            <input type="file" class="form-control" name="attachment" 
                                                   accept=".pdf,.doc,.docx,.jpg,.png,.zip,.txt">
                                            <small class="text-muted">Max file size: 10MB. Allowed: PDF, Word, Images, ZIP, TXT</small>
                                        </div>
                                        
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            Once submitted, you cannot edit your submission unless the teacher allows resubmission.
                                        </div>
                                        
                                        <div class="d-flex justify-content-between">
                                            <a href="student_class_activities.php?action=dashboard" class="btn btn-secondary">Cancel</a>
                                            <button type="submit" class="btn btn-success">Submit Work</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($action === 'submit' && $activity_id > 0): ?>
            <!-- Submit Work Page (Alternative to modal submission) -->
            <?php
            $submit_query = "
                SELECT ca.*, s.subject_name, u.full_name as teacher_name
                FROM class_activities ca 
                JOIN subjects s ON ca.subject_id = s.id 
                JOIN users u ON ca.teacher_id = u.id 
                WHERE ca.id = ? AND ca.class_id = ? AND ca.status = 'published'
            ";
            $submit_stmt = $pdo->prepare($submit_query);
            $submit_stmt->execute([$activity_id, $student_class_id]);
            $activity = $submit_stmt->fetch();
            
            if (!$activity):
            ?>
            <div class="alert alert-danger">
                Activity not found or closed for submission.
                <a href="student_class_activities.php" class="btn btn-sm btn-primary ms-3">Back to Activities</a>
            </div>
            <?php else: 
                // Check if already submitted
                $check_submission = "SELECT id FROM student_submissions WHERE activity_id = ? AND student_id = ?";
                $check_stmt = $pdo->prepare($check_submission);
                $check_stmt->execute([$activity_id, $student_id]);
                
                if ($check_stmt->rowCount() > 0):
            ?>
            <div class="alert alert-info">
                You have already submitted this activity.
                <a href="student_class_activities.php?action=view&id=<?= $activity_id ?>" class="btn btn-sm btn-primary ms-3">
                    View Your Submission
                </a>
            </div>
            <?php else: ?>
            <div class="form-page">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Submit: <?= htmlspecialchars($activity['title']) ?></h2>
                    <a href="student_class_activities.php?action=dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Activities
                    </a>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Activity Details</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Subject:</strong> <?= $activity['subject_name'] ?></p>
                        <p><strong>Teacher:</strong> <?= $activity['teacher_name'] ?></p>
                        <p><strong>Type:</strong> <?= ucfirst($activity['activity_type']) ?></p>
                        <?php if ($activity['due_date']): ?>
                            <p><strong>Due Date:</strong> <?= date('M d, Y H:i', strtotime($activity['due_date'])) ?></p>
                        <?php endif; ?>
                        <p><strong>Total Marks:</strong> <?= $activity['total_marks'] ?></p>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Submission Form</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="activity_id" value="<?= $activity_id ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Submission Text *</label>
                                <textarea class="form-control" name="submission_text" rows="8" 
                                          placeholder="Type your answer or submission here..." required></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Attachment (Optional)</label>
                                <input type="file" class="form-control" name="attachment" 
                                       accept=".pdf,.doc,.docx,.jpg,.png,.zip,.txt">
                                <small class="text-muted">Max file size: 10MB. Allowed: PDF, Word, Images, ZIP, TXT</small>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                Once submitted, you cannot edit your submission unless the teacher allows resubmission.
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="student_class_activities.php?action=dashboard" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-success">Submit Work</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; endif; ?>

        <?php else: ?>
            <!-- Main Dashboard with Tabs -->
            <!-- Welcome Header -->
            <div class="row mb-4">
                <div class="col">
                    <h2>Welcome, <?= htmlspecialchars($student['full_name']) ?></h2>
                    <p class="text-muted">Class: <?= $student['class_name'] ?></p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card stats-card bg-warning text-dark">
                        <div class="card-body">
                            <h6 class="card-title">Pending Activities</h6>
                            <?php
                            $pending_query = "
                                SELECT COUNT(DISTINCT ca.id) as count 
                                FROM class_activities ca 
                                LEFT JOIN student_submissions ss ON ca.id = ss.activity_id AND ss.student_id = ?
                                WHERE ca.class_id = ? 
                                AND ca.status = 'published'
                                AND (ss.id IS NULL OR ss.status IN ('pending', 'late'))
                                AND (ca.due_date IS NULL OR ca.due_date > NOW())
                            ";
                            $pending_stmt = $pdo->prepare($pending_query);
                            $pending_stmt->execute([$student_id, $student_class_id]);
                            $pending_count = $pending_stmt->fetch()['count'];
                            ?>
                            <h2 class="mb-0"><?= $pending_count ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card stats-card bg-info text-white">
                        <div class="card-body">
                            <h6 class="card-title">Due This Week</h6>
                            <?php
                            $due_week_query = "
                                SELECT COUNT(*) as count FROM class_activities 
                                WHERE class_id = ? 
                                AND status = 'published'
                                AND due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
                            ";
                            $due_week_stmt = $pdo->prepare($due_week_query);
                            $due_week_stmt->execute([$student_class_id]);
                            $due_week_count = $due_week_stmt->fetch()['count'];
                            ?>
                            <h2 class="mb-0"><?= $due_week_count ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card stats-card bg-success text-white">
                        <div class="card-body">
                            <h6 class="card-title">Submitted</h6>
                            <?php
                            $submitted_query = "
                                SELECT COUNT(*) as count FROM student_submissions 
                                WHERE student_id = ? AND status IN ('submitted', 'graded')
                            ";
                            $submitted_stmt = $pdo->prepare($submitted_query);
                            $submitted_stmt->execute([$student_id]);
                            $submitted_count = $submitted_stmt->fetch()['count'];
                            ?>
                            <h2 class="mb-0"><?= $submitted_count ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card stats-card bg-primary text-white">
                        <div class="card-body">
                            <h6 class="card-title">Average Score</h6>
                            <?php
                            $avg_query = "
                                SELECT AVG(marks_obtained) as avg_score 
                                FROM student_submissions 
                                WHERE student_id = ? AND status = 'graded' AND marks_obtained IS NOT NULL
                            ";
                            $avg_stmt = $pdo->prepare($avg_query);
                            $avg_stmt->execute([$student_id]);
                            $avg_result = $avg_stmt->fetch();
                            $avg_score = $avg_result['avg_score'] ? number_format($avg_result['avg_score'], 1) : '-';
                            ?>
                            <h2 class="mb-0"><?= $avg_score ?></h2>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs mb-4" id="studentTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $action === 'dashboard' ? 'active' : '' ?>" 
                            onclick="window.location.href='?action=dashboard'">Active</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $action === 'submitted' ? 'active' : '' ?>" 
                            onclick="window.location.href='?action=submitted'">Submitted</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $action === 'graded' ? 'active' : '' ?>" 
                            onclick="window.location.href='?action=graded'">Graded</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $action === 'calendar' ? 'active' : '' ?>" 
                            onclick="window.location.href='?action=calendar'">Calendar</button>
                </li>
            </ul>

            <!-- Activities List -->
            <div class="row">
                <?php
                // Build query based on tab
                $activities_query = "
                    SELECT ca.*, s.subject_name, u.full_name as teacher_name,
                           ss.id as submission_id, ss.status as submission_status,
                           ss.marks_obtained, ss.feedback, ss.submitted_at,
                           ss.id as submission_db_id
                    FROM class_activities ca 
                    JOIN subjects s ON ca.subject_id = s.id 
                    JOIN users u ON ca.teacher_id = u.id 
                    LEFT JOIN student_submissions ss ON ca.id = ss.activity_id AND ss.student_id = ?
                    WHERE ca.class_id = ? AND ca.status = 'published'
                ";
                
                if ($action === 'submitted') {
                    $activities_query .= " AND ss.id IS NOT NULL AND ss.status IN ('submitted', 'graded')";
                } elseif ($action === 'graded') {
                    $activities_query .= " AND ss.status = 'graded'";
                } elseif ($action === 'calendar') {
                    // For calendar view, we'll show a calendar
                } else {
                    $activities_query .= " AND (ss.id IS NULL OR ss.status IN ('pending', 'late'))";
                }
                
                if ($action !== 'calendar') {
                    $activities_query .= " ORDER BY ca.due_date ASC";
                    
                    $activities_stmt = $pdo->prepare($activities_query);
                    $activities_stmt->execute([$student_id, $student_class_id]);
                    $activities = $activities_stmt->fetchAll();
                    
                    if (count($activities) > 0):
                        foreach ($activities as $activity):
                            $is_overdue = $activity['due_date'] && strtotime($activity['due_date']) < time();
                            $due_soon = $activity['due_date'] && 
                                       strtotime($activity['due_date']) > time() && 
                                       strtotime($activity['due_date']) < strtotime('+3 days');
                            $card_class = '';
                            
                            if ($action === 'graded') {
                                $card_class = 'graded';
                            } elseif ($activity['submission_status'] === 'submitted') {
                                $card_class = 'submitted';
                            } elseif ($is_overdue) {
                                $card_class = 'overdue';
                            } elseif ($due_soon) {
                                $card_class = 'due-soon';
                            }
                ?>
                <div class="col-md-6 mb-4">
                    <div class="card activity-card activity-<?= $activity['activity_type'] ?> <?= $card_class ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h5 class="card-title mb-1"><?= htmlspecialchars($activity['title']) ?></h5>
                                    <span class="badge bg-secondary"><?= ucfirst($activity['activity_type']) ?></span>
                                    <?php if ($is_overdue): ?>
                                        <span class="badge bg-danger badge-due">Overdue</span>
                                    <?php elseif ($due_soon): ?>
                                        <span class="badge bg-warning badge-due">Due Soon</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($activity['submission_status'] === 'graded' && $activity['marks_obtained']): ?>
                                    <div class="text-end">
                                        <span class="badge bg-success fs-6">
                                            <?= $activity['marks_obtained'] ?>/<?= $activity['total_marks'] ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <p class="card-text text-muted">
                                <small>
                                    <i class="bi bi-book"></i> <?= $activity['subject_name'] ?><br>
                                    <i class="bi bi-person"></i> <?= $activity['teacher_name'] ?>
                                </small>
                            </p>
                            
                            <p class="card-text">
                                <?= nl2br(htmlspecialchars(substr($activity['description'], 0, 100))) ?>
                                <?= strlen($activity['description']) > 100 ? '...' : '' ?>
                            </p>
                            
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <div>
                                    <?php if ($activity['due_date']): ?>
                                        <small class="text-muted">
                                            <i class="bi bi-clock"></i> Due: <?= date('M d, Y H:i', strtotime($activity['due_date'])) ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if ($action === 'graded'): ?>
                                        <a href="?action=feedback&feedback_id=<?= $activity['submission_db_id'] ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            View Feedback
                                        </a>
                                    <?php elseif ($activity['submission_id']): ?>
                                        <span class="badge bg-info">Submitted</span>
                                        <a href="?action=view&id=<?= $activity['id'] ?>" 
                                           class="btn btn-sm btn-outline-secondary ms-2">View</a>
                                    <?php else: ?>
                                        <a href="?action=submit&id=<?= $activity['id'] ?>" 
                                           class="btn btn-sm btn-primary">Submit Work</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div class="col-12">
                    <div class="alert alert-info">
                        <?php if ($action === 'dashboard'): ?>
                            No pending activities at the moment.
                        <?php elseif ($action === 'submitted'): ?>
                            No submitted activities yet.
                        <?php elseif ($action === 'graded'): ?>
                            No graded activities yet.
                        <?php elseif ($action === 'calendar'): ?>
                            <!-- Calendar view would go here -->
                            Calendar view is currently not available.
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; } ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>