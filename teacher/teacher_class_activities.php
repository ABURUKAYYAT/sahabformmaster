<?php
// teacher_class_activities.php
session_start();
require_once '../config/db.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';
$activity_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create' || $action === 'edit') {
        // Handle activity creation/editing
        $activity_data = [
            'title' => $_POST['title'] ?? '',
            'activity_type' => $_POST['activity_type'] ?? '',
            'subject_id' => $_POST['subject_id'] ?? '',
            'class_id' => $_POST['class_id'] ?? '',
            'description' => $_POST['description'] ?? '',
            'instructions' => $_POST['instructions'] ?? '',
            'due_date' => $_POST['due_date'] ?: null,
            'total_marks' => $_POST['total_marks'] ?? 100,
            'status' => $_POST['status'] ?? 'draft',
            'teacher_id' => $teacher_id
        ];

        if ($action === 'create') {
            // Insert new activity
            $sql = "INSERT INTO class_activities (title, activity_type, subject_id, class_id, description, instructions, due_date, total_marks, status, teacher_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $activity_data['title'],
                $activity_data['activity_type'],
                $activity_data['subject_id'],
                $activity_data['class_id'],
                $activity_data['description'],
                $activity_data['instructions'],
                $activity_data['due_date'],
                $activity_data['total_marks'],
                $activity_data['status'],
                $activity_data['teacher_id']
            ]);
            
            header("Location: teacher_class_activities.php?action=activities&success=created");
            exit;
            
        } elseif ($action === 'edit' && $activity_id > 0) {
            // Update existing activity
            $sql = "UPDATE class_activities 
                    SET title = ?, activity_type = ?, subject_id = ?, class_id = ?, 
                        description = ?, instructions = ?, due_date = ?, total_marks = ?, 
                        status = ?, updated_at = NOW() 
                    WHERE id = ? AND teacher_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $activity_data['title'],
                $activity_data['activity_type'],
                $activity_data['subject_id'],
                $activity_data['class_id'],
                $activity_data['description'],
                $activity_data['instructions'],
                $activity_data['due_date'],
                $activity_data['total_marks'],
                $activity_data['status'],
                $activity_id,
                $teacher_id
            ]);
            
            header("Location: teacher_class_activities.php?action=activities&success=updated");
            exit;
        }
        
    } elseif ($action === 'grade' && isset($_POST['submission_id'])) {
        // Handle grading
        $submission_id = intval($_POST['submission_id']);
        $marks = floatval($_POST['marks']);
        $feedback = $_POST['feedback'] ?? '';
        
        // Get max marks from activity
        $check_sql = "SELECT ca.total_marks 
                      FROM student_submissions ss 
                      JOIN class_activities ca ON ss.activity_id = ca.id 
                      WHERE ss.id = ? AND ca.teacher_id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$submission_id, $teacher_id]);
        $result = $check_stmt->fetch();
        
        if ($result) {
            $max_marks = floatval($result['total_marks']);
            if ($marks > $max_marks) $marks = $max_marks;
            
            $update_sql = "UPDATE student_submissions 
                           SET marks_obtained = ?, feedback = ?, status = 'graded', graded_at = NOW() 
                           WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$marks, $feedback, $submission_id]);
            
            $activity_id = isset($_POST['activity_id']) ? intval($_POST['activity_id']) : 0;
            header("Location: teacher_class_activities.php?action=submissions&id=$activity_id&success=graded");
            exit;
        }
        
    } elseif ($action === 'delete' && $activity_id > 0) {
        // Handle deletion
        $delete_sql = "DELETE FROM class_activities WHERE id = ? AND teacher_id = ?";
        $delete_stmt = $pdo->prepare($delete_sql);
        $delete_stmt->execute([$activity_id, $teacher_id]);
        
        header("Location: teacher_class_activities.php?action=activities&success=deleted");
        exit;
    }
}

// Get teacher's assigned classes and subjects
$assigned_classes = [];
$assigned_subjects = [];

// Get assigned classes
$class_query = "SELECT DISTINCT c.id, c.class_name 
                FROM class_teachers ct 
                JOIN classes c ON ct.class_id = c.id 
                WHERE ct.teacher_id = ?";
$class_stmt = $pdo->prepare($class_query);
$class_stmt->execute([$teacher_id]);
$assigned_classes = $class_stmt->fetchAll();

// Get assigned subjects
$subject_query = "SELECT DISTINCT s.id, s.subject_name 
                  FROM subject_assignments sa 
                  JOIN subjects s ON sa.subject_id = s.id 
                  WHERE sa.teacher_id = ?";
$subject_stmt = $pdo->prepare($subject_query);
$subject_stmt->execute([$teacher_id]);
$assigned_subjects = $subject_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Activities - Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .activity-card {
            transition: transform 0.2s;
            border-left: 4px solid #0d6efd;
        }
        .activity-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .activity-classwork { border-left-color: #198754 !important; }
        .activity-assignment { border-left-color: #0dcaf0 !important; }
        .activity-quiz { border-left-color: #ffc107 !important; }
        .activity-project { border-left-color: #6f42c1 !important; }
        .activity-homework { border-left-color: #fd7e14 !important; }
        .badge-type { font-size: 0.8em; }
        .stats-card { border-radius: 10px; }
        .submission-item { border-bottom: 1px solid #eee; padding: 10px 0; }
        .submission-item:last-child { border-bottom: none; }
        .late-submission { background-color: #fff3cd; }
        .graded-submission { background-color: #d1e7dd; }
        .form-page { max-width: 900px; margin: 0 auto; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">Class Activities</a>
            <div class="navbar-nav ms-auto">
                <a href="teacher_dashboard.php" class="nav-link">Dashboard</a>
                <a href="teacher_class_activities.php?action=dashboard" class="nav-link">Activities</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php
        // Show success messages
        if (isset($_GET['success'])) {
            $messages = [
                'created' => 'Activity created successfully!',
                'updated' => 'Activity updated successfully!',
                'deleted' => 'Activity deleted successfully!',
                'graded' => 'Submission graded successfully!'
            ];
            
            if (isset($messages[$_GET['success']])) {
                echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                        ' . $messages[$_GET['success']] . '
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                      </div>';
            }
        }
        ?>

        <?php if (in_array($action, ['create', 'edit']) && $action !== 'grade'): ?>
            <!-- Create/Edit Activity Form -->
            <?php
            $activity = null;
            if ($action === 'edit' && $activity_id > 0) {
                $activity_query = "SELECT * FROM class_activities WHERE id = ? AND teacher_id = ?";
                $activity_stmt = $pdo->prepare($activity_query);
                $activity_stmt->execute([$activity_id, $teacher_id]);
                $activity = $activity_stmt->fetch();
                
                if (!$activity) {
                    echo '<div class="alert alert-danger">Activity not found or access denied.</div>';
                    exit;
                }
            }
            ?>
            
            <div class="form-page">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><?= $action === 'create' ? 'Create New Activity' : 'Edit Activity' ?></h2>
                    <a href="teacher_class_activities.php?action=activities" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Activities
                    </a>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Activity Type *</label>
                                    <select class="form-select" name="activity_type" required>
                                        <option value="">Select type</option>
                                        <option value="classwork" <?= ($activity['activity_type'] ?? '') === 'classwork' ? 'selected' : '' ?>>Classwork</option>
                                        <option value="assignment" <?= ($activity['activity_type'] ?? '') === 'assignment' ? 'selected' : '' ?>>Assignment</option>
                                        <option value="quiz" <?= ($activity['activity_type'] ?? '') === 'quiz' ? 'selected' : '' ?>>Quiz</option>
                                        <option value="project" <?= ($activity['activity_type'] ?? '') === 'project' ? 'selected' : '' ?>>Project</option>
                                        <option value="homework" <?= ($activity['activity_type'] ?? '') === 'homework' ? 'selected' : '' ?>>Homework</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Title *</label>
                                    <input type="text" class="form-control" name="title" value="<?= htmlspecialchars($activity['title'] ?? '') ?>" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Subject *</label>
                                    <select class="form-select" name="subject_id" required>
                                        <option value="">Select subject</option>
                                        <?php foreach ($assigned_subjects as $subject): ?>
                                            <option value="<?= $subject['id'] ?>" <?= ($activity['subject_id'] ?? '') == $subject['id'] ? 'selected' : '' ?>>
                                                <?= $subject['subject_name'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Class *</label>
                                    <select class="form-select" name="class_id" required>
                                        <option value="">Select class</option>
                                        <?php foreach ($assigned_classes as $class): ?>
                                            <option value="<?= $class['id'] ?>" <?= ($activity['class_id'] ?? '') == $class['id'] ? 'selected' : '' ?>>
                                                <?= $class['class_name'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($activity['description'] ?? '') ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Instructions *</label>
                                <textarea class="form-control" name="instructions" rows="3" required><?= htmlspecialchars($activity['instructions'] ?? '') ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Due Date & Time</label>
                                    <?php
                                    $due_date = $activity['due_date'] ?? '';
                                    if ($due_date) {
                                        $due_date = date('Y-m-d\TH:i', strtotime($due_date));
                                    }
                                    ?>
                                    <input type="datetime-local" class="form-control" name="due_date" value="<?= $due_date ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Total Marks</label>
                                    <input type="number" class="form-control" name="total_marks" min="0" step="0.01" value="<?= $activity['total_marks'] ?? 100 ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="draft" <?= ($activity['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                                    <option value="published" <?= ($activity['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                                </select>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="teacher_class_activities.php?action=activities" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">
                                    <?= $action === 'create' ? 'Create Activity' : 'Update Activity' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'grade' && $activity_id > 0): ?>
            <!-- Grade Submission Page -->
            <?php
            $submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
            
            $submission_query = "
                SELECT ss.*, st.full_name, st.admission_no, ca.title as activity_title, 
                       ca.total_marks, ca.instructions, ca.activity_type
                FROM student_submissions ss 
                JOIN students st ON ss.student_id = st.id 
                JOIN class_activities ca ON ss.activity_id = ca.id 
                WHERE ss.id = ? AND ca.teacher_id = ?
            ";
            $submission_stmt = $pdo->prepare($submission_query);
            $submission_stmt->execute([$submission_id, $teacher_id]);
            $submission = $submission_stmt->fetch();
            
            if (!$submission) {
                echo '<div class="alert alert-danger">Submission not found or access denied.</div>';
                echo '<a href="teacher_class_activities.php?action=submissions&id=' . $activity_id . '" class="btn btn-primary">Back to Submissions</a>';
                exit;
            }
            ?>
            
            <div class="form-page">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Grade Submission</h2>
                    <a href="teacher_class_activities.php?action=submissions&id=<?= $activity_id ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Submissions
                    </a>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5>Activity: <?= htmlspecialchars($submission['activity_title']) ?></h5>
                        <small class="text-muted">Type: <?= ucfirst($submission['activity_type']) ?></small>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Student:</strong> <?= htmlspecialchars($submission['full_name']) ?></p>
                                <p><strong>Admission No:</strong> <?= $submission['admission_no'] ?></p>
                                <p><strong>Submitted:</strong> <?= date('M d, Y H:i', strtotime($submission['submitted_at'])) ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Max Marks:</strong> <?= $submission['total_marks'] ?></p>
                                <p><strong>Current Marks:</strong> <?= $submission['marks_obtained'] ? $submission['marks_obtained'] . '/' . $submission['total_marks'] : 'Not graded' ?></p>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><strong>Activity Instructions:</strong></label>
                            <div class="border p-3 bg-light">
                                <?= nl2br(htmlspecialchars($submission['instructions'])) ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><strong>Student's Submission:</strong></label>
                            <div class="border p-3">
                                <?= $submission['submission_text'] ? nl2br(htmlspecialchars($submission['submission_text'])) : '<em>No text submitted</em>' ?>
                            </div>
                        </div>
                        
                        <?php if ($submission['attachment_path']): ?>
                        <div class="mb-3">
                            <label class="form-label"><strong>Attachment:</strong></label>
                            <br>
                            <a href="<?= $submission['attachment_path'] ?>" class="btn btn-outline-primary" target="_blank">
                                <i class="bi bi-download"></i> Download Attachment
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5>Grading</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="submission_id" value="<?= $submission_id ?>">
                            <input type="hidden" name="activity_id" value="<?= $activity_id ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Marks (Max: <?= $submission['total_marks'] ?>) *</label>
                                <input type="number" class="form-control" name="marks" 
                                       value="<?= $submission['marks_obtained'] ?>" 
                                       min="0" max="<?= $submission['total_marks'] ?>" step="0.01" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Feedback</label>
                                <textarea class="form-control" name="feedback" rows="4" 
                                          placeholder="Provide constructive feedback..."><?= htmlspecialchars($submission['feedback'] ?? '') ?></textarea>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="teacher_class_activities.php?action=submissions&id=<?= $activity_id ?>" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-success">Save Grade</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Main Dashboard/Activities/Submissions/Reports -->
            <!-- Header with Create Button -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Class Activities Management</h2>
                <a href="?action=create" class="btn btn-success">
                    <i class="bi bi-plus-circle"></i> Create New Activity
                </a>
            </div>

            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs mb-4" id="activitiesTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $action === 'dashboard' ? 'active' : '' ?>" 
                            onclick="window.location.href='?action=dashboard'">Dashboard</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $action === 'activities' ? 'active' : '' ?>" 
                            onclick="window.location.href='?action=activities'">My Activities</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $action === 'submissions' ? 'active' : '' ?>" 
                            onclick="window.location.href='?action=submissions'">Submissions</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $action === 'reports' ? 'active' : '' ?>" 
                            onclick="window.location.href='?action=reports'">Reports</button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content">
                <?php if ($action === 'dashboard'): ?>
                    <!-- Dashboard Content -->
                    <div class="row">
                        <!-- Statistics Cards -->
                        <div class="col-md-3 mb-3">
                            <div class="card stats-card bg-primary text-white">
                                <div class="card-body">
                                    <h6 class="card-title">Total Activities</h6>
                                    <?php
                                    $total_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM class_activities WHERE teacher_id = ?");
                                    $total_stmt->execute([$teacher_id]);
                                    $total_activities = $total_stmt->fetch()['total'];
                                    ?>
                                    <h2 class="mb-0"><?= $total_activities ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card stats-card bg-warning text-dark">
                                <div class="card-body">
                                    <h6 class="card-title">Pending Submissions</h6>
                                    <?php
                                    $pending_stmt = $pdo->prepare("
                                        SELECT COUNT(DISTINCT a.id) as pending 
                                        FROM class_activities a 
                                        LEFT JOIN student_submissions s ON a.id = s.activity_id 
                                        WHERE a.teacher_id = ? 
                                        AND a.status = 'published' 
                                        AND (s.status IS NULL OR s.status = 'pending')
                                    ");
                                    $pending_stmt->execute([$teacher_id]);
                                    $pending = $pending_stmt->fetch()['pending'];
                                    ?>
                                    <h2 class="mb-0"><?= $pending ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card stats-card bg-success text-white">
                                <div class="card-body">
                                    <h6 class="card-title">Graded</h6>
                                    <?php
                                    $graded_stmt = $pdo->prepare("
                                        SELECT COUNT(*) as graded FROM student_submissions s 
                                        JOIN class_activities a ON s.activity_id = a.id 
                                        WHERE a.teacher_id = ? AND s.status = 'graded'
                                    ");
                                    $graded_stmt->execute([$teacher_id]);
                                    $graded = $graded_stmt->fetch()['graded'];
                                    ?>
                                    <h2 class="mb-0"><?= $graded ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card stats-card bg-info text-white">
                                <div class="card-body">
                                    <h6 class="card-title">Upcoming Due</h6>
                                    <?php
                                    $upcoming_stmt = $pdo->prepare("
                                        SELECT COUNT(*) as upcoming FROM class_activities 
                                        WHERE teacher_id = ? 
                                        AND due_date > NOW() 
                                        AND due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                                    ");
                                    $upcoming_stmt->execute([$teacher_id]);
                                    $upcoming = $upcoming_stmt->fetch()['upcoming'];
                                    ?>
                                    <h2 class="mb-0"><?= $upcoming ?></h2>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Activities -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Recent Activities</h5>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $recent_query = "
                                        SELECT ca.*, s.subject_name, c.class_name 
                                        FROM class_activities ca 
                                        JOIN subjects s ON ca.subject_id = s.id 
                                        JOIN classes c ON ca.class_id = c.id 
                                        WHERE ca.teacher_id = ? 
                                        ORDER BY ca.created_at DESC LIMIT 5
                                    ";
                                    $recent_stmt = $pdo->prepare($recent_query);
                                    $recent_stmt->execute([$teacher_id]);
                                    $recent_activities = $recent_stmt->fetchAll();
                                    
                                    if (count($recent_activities) > 0):
                                        foreach ($recent_activities as $activity):
                                    ?>
                                    <div class="activity-card card mb-3 activity-<?= $activity['activity_type'] ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="card-title mb-1"><?= htmlspecialchars($activity['title']) ?></h6>
                                                    <span class="badge bg-primary badge-type"><?= ucfirst($activity['activity_type']) ?></span>
                                                    <span class="text-muted"><?= $activity['subject_name'] ?> • <?= $activity['class_name'] ?></span>
                                                </div>
                                                <div class="text-end">
                                                    <small class="text-muted">Created: <?= date('M d, Y', strtotime($activity['created_at'])) ?></small><br>
                                                    <?php if ($activity['due_date']): ?>
                                                        <small class="<?= strtotime($activity['due_date']) < time() ? 'text-danger' : 'text-muted' ?>">
                                                            Due: <?= date('M d, Y H:i', strtotime($activity['due_date'])) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <a href="?action=submissions&id=<?= $activity['id'] ?>" class="btn btn-sm btn-outline-primary">View Submissions</a>
                                                <a href="?action=edit&id=<?= $activity['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; else: ?>
                                    <p class="text-muted">No activities created yet.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($action === 'activities'): ?>
                    <!-- My Activities Content -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">My Activities</h5>
                            <div class="d-flex gap-2">
                                <select class="form-select form-select-sm w-auto" id="filterType">
                                    <option value="">All Types</option>
                                    <option value="classwork">Classwork</option>
                                    <option value="assignment">Assignment</option>
                                    <option value="quiz">Quiz</option>
                                    <option value="project">Project</option>
                                    <option value="homework">Homework</option>
                                </select>
                                <select class="form-select form-select-sm w-auto" id="filterStatus">
                                    <option value="">All Status</option>
                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="activitiesTable">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Type</th>
                                            <th>Subject</th>
                                            <th>Class</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                            <th>Submissions</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $activities_query = "
                                            SELECT ca.*, s.subject_name, c.class_name,
                                            (SELECT COUNT(*) FROM student_submissions ss WHERE ss.activity_id = ca.id) as total_submissions,
                                            (SELECT COUNT(*) FROM student_submissions ss WHERE ss.activity_id = ca.id AND ss.status = 'graded') as graded_submissions
                                            FROM class_activities ca 
                                            JOIN subjects s ON ca.subject_id = s.id 
                                            JOIN classes c ON ca.class_id = c.id 
                                            WHERE ca.teacher_id = ? 
                                            ORDER BY ca.created_at DESC
                                        ";
                                        $activities_stmt = $pdo->prepare($activities_query);
                                        $activities_stmt->execute([$teacher_id]);
                                        $activities = $activities_stmt->fetchAll();
                                        
                                        foreach ($activities as $activity):
                                            $submission_rate = $activity['total_submissions'] > 0 ? 
                                                round(($activity['graded_submissions'] / $activity['total_submissions']) * 100, 0) : 0;
                                        ?>
                                        <tr data-type="<?= $activity['activity_type'] ?>" data-status="<?= $activity['status'] ?>">
                                            <td><?= htmlspecialchars($activity['title']) ?></td>
                                            <td><span class="badge bg-secondary"><?= ucfirst($activity['activity_type']) ?></span></td>
                                            <td><?= $activity['subject_name'] ?></td>
                                            <td><?= $activity['class_name'] ?></td>
                                            <td>
                                                <?php if ($activity['due_date']): ?>
                                                    <?= date('M d, Y', strtotime($activity['due_date'])) ?>
                                                    <?php if (strtotime($activity['due_date']) < time()): ?>
                                                        <span class="badge bg-danger">Overdue</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No due date</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $activity['status'] === 'published' ? 'success' : ($activity['status'] === 'closed' ? 'secondary' : 'warning') ?>">
                                                    <?= ucfirst($activity['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar" role="progressbar" 
                                                         style="width: <?= $submission_rate ?>%"
                                                         aria-valuenow="<?= $submission_rate ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                        <?= $submission_rate ?>%
                                                    </div>
                                                </div>
                                                <small class="text-muted"><?= $activity['graded_submissions'] ?>/<?= $activity['total_submissions'] ?> graded</small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="?action=submissions&id=<?= $activity['id'] ?>" 
                                                       class="btn btn-outline-primary" 
                                                       title="View Submissions">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="?action=edit&id=<?= $activity['id'] ?>" 
                                                       class="btn btn-outline-secondary"
                                                       title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <button onclick="deleteActivity(<?= $activity['id'] ?>)" 
                                                            class="btn btn-outline-danger"
                                                            title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <?php elseif ($action === 'submissions'): ?>
                    <!-- Submissions Management -->
                    <?php if ($activity_id > 0): ?>
                        <!-- Single Activity Submissions -->
                        <?php
                        $activity_query = "
                            SELECT ca.*, s.subject_name, c.class_name 
                            FROM class_activities ca 
                            JOIN subjects s ON ca.subject_id = s.id 
                            JOIN classes c ON ca.class_id = c.id 
                            WHERE ca.id = ? AND ca.teacher_id = ?
                        ";
                        $activity_stmt = $pdo->prepare($activity_query);
                        $activity_stmt->execute([$activity_id, $teacher_id]);
                        $activity = $activity_stmt->fetch();
                        
                        if (!$activity) {
                            echo '<div class="alert alert-danger">Activity not found or access denied.</div>';
                        } else {
                        ?>
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-0">Submissions for: <?= htmlspecialchars($activity['title']) ?></h5>
                                    <small class="text-muted"><?= $activity['subject_name'] ?> • <?= $activity['class_name'] ?></small>
                                </div>
                                <a href="?action=submissions" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-arrow-left"></i> All Submissions
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6>Activity Details</h6>
                                                <p><strong>Type:</strong> <?= ucfirst($activity['activity_type']) ?></p>
                                                <p><strong>Due Date:</strong> <?= $activity['due_date'] ? date('M d, Y H:i', strtotime($activity['due_date'])) : 'Not set' ?></p>
                                                <p><strong>Total Marks:</strong> <?= $activity['total_marks'] ?></p>
                                                <p><strong>Status:</strong> <span class="badge bg-<?= $activity['status'] === 'published' ? 'success' : 'warning' ?>"><?= ucfirst($activity['status']) ?></span></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6>Instructions</h6>
                                                <p><?= nl2br(htmlspecialchars($activity['instructions'])) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Submitted On</th>
                                                <th>Status</th>
                                                <th>Marks</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $submissions_query = "
                                                SELECT ss.*, st.full_name, st.admission_no 
                                                FROM student_submissions ss 
                                                JOIN students st ON ss.student_id = st.id 
                                                WHERE ss.activity_id = ? 
                                                ORDER BY ss.submitted_at DESC
                                            ";
                                            $submissions_stmt = $pdo->prepare($submissions_query);
                                            $submissions_stmt->execute([$activity_id]);
                                            $submissions = $submissions_stmt->fetchAll();
                                            
                                            foreach ($submissions as $submission):
                                            ?>
                                            <tr class="<?= $submission['status'] === 'late' ? 'late-submission' : ($submission['status'] === 'graded' ? 'graded-submission' : '') ?>">
                                                <td>
                                                    <strong><?= htmlspecialchars($submission['full_name']) ?></strong><br>
                                                    <small class="text-muted"><?= $submission['admission_no'] ?></small>
                                                </td>
                                                <td>
                                                    <?= $submission['submitted_at'] ? date('M d, Y H:i', strtotime($submission['submitted_at'])) : 'Not submitted' ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $submission['status'] === 'graded' ? 'success' : 
                                                        ($submission['status'] === 'late' ? 'warning' : 
                                                        ($submission['status'] === 'submitted' ? 'info' : 'secondary')) 
                                                    ?>">
                                                        <?= ucfirst($submission['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($submission['marks_obtained'] !== null): ?>
                                                        <strong><?= $submission['marks_obtained'] ?></strong>/<?= $activity['total_marks'] ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not graded</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="?action=grade&id=<?= $activity_id ?>&submission_id=<?= $submission['id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-check-circle"></i> Grade
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    <?php else: ?>
                        <!-- All Submissions -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">All Submissions</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Activity</th>
                                                <th>Student</th>
                                                <th>Class</th>
                                                <th>Submitted</th>
                                                <th>Status</th>
                                                <th>Marks</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $all_submissions_query = "
                                                SELECT ss.*, ca.title as activity_title, ca.activity_type, ca.total_marks, ca.id as activity_id,
                                                       st.full_name as student_name, st.admission_no,
                                                       c.class_name
                                                FROM student_submissions ss 
                                                JOIN class_activities ca ON ss.activity_id = ca.id 
                                                JOIN students st ON ss.student_id = st.id 
                                                JOIN classes c ON st.class_id = c.id 
                                                WHERE ca.teacher_id = ? 
                                                ORDER BY ss.updated_at DESC
                                                LIMIT 50
                                            ";
                                            $all_submissions_stmt = $pdo->prepare($all_submissions_query);
                                            $all_submissions_stmt->execute([$teacher_id]);
                                            $all_submissions = $all_submissions_stmt->fetchAll();
                                            
                                            foreach ($all_submissions as $submission):
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($submission['activity_title']) ?></strong><br>
                                                    <small class="text-muted"><?= ucfirst($submission['activity_type']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($submission['student_name']) ?></td>
                                                <td><?= $submission['class_name'] ?></td>
                                                <td><?= date('M d, Y', strtotime($submission['submitted_at'])) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $submission['status'] === 'graded' ? 'success' : 
                                                        ($submission['status'] === 'late' ? 'warning' : 
                                                        ($submission['status'] === 'submitted' ? 'info' : 'secondary')) 
                                                    ?>">
                                                        <?= ucfirst($submission['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($submission['marks_obtained'] !== null): ?>
                                                        <?= $submission['marks_obtained'] ?>/<?= $submission['total_marks'] ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="?action=submissions&id=<?= $submission['activity_id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        View
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php elseif ($action === 'reports'): ?>
                    <!-- Reports Section -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5>Performance by Activity Type</h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="typePerformanceChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5>Submission Statistics</h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="submissionStatsChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5>Detailed Activity Report</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Activity</th>
                                            <th>Type</th>
                                            <th>Class</th>
                                            <th>Total Students</th>
                                            <th>Submitted</th>
                                            <th>Graded</th>
                                            <th>Avg Score</th>
                                            <th>Submission Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $report_query = "
                                            SELECT ca.id, ca.title, ca.activity_type, c.class_name,
                                                   (SELECT COUNT(*) FROM students st WHERE st.class_id = ca.class_id) as total_students,
                                                   COUNT(ss.id) as submitted_count,
                                                   SUM(CASE WHEN ss.status = 'graded' THEN 1 ELSE 0 END) as graded_count,
                                                   AVG(ss.marks_obtained) as avg_score
                                            FROM class_activities ca 
                                            JOIN classes c ON ca.class_id = c.id 
                                            LEFT JOIN student_submissions ss ON ca.id = ss.activity_id 
                                            WHERE ca.teacher_id = ? 
                                            GROUP BY ca.id 
                                            ORDER BY ca.created_at DESC
                                        ";
                                        $report_stmt = $pdo->prepare($report_query);
                                        $report_stmt->execute([$teacher_id]);
                                        $reports = $report_stmt->fetchAll();
                                        
                                        foreach ($reports as $report):
                                            $submission_rate = $report['total_students'] > 0 ? 
                                                round(($report['submitted_count'] / $report['total_students']) * 100, 0) : 0;
                                            $avg_score = $report['avg_score'] ? number_format($report['avg_score'], 1) : '-';
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($report['title']) ?></td>
                                            <td><?= ucfirst($report['activity_type']) ?></td>
                                            <td><?= $report['class_name'] ?></td>
                                            <td><?= $report['total_students'] ?></td>
                                            <td><?= $report['submitted_count'] ?></td>
                                            <td><?= $report['graded_count'] ?></td>
                                            <td><?= $avg_score ?></td>
                                            <td>
                                                <div class="progress">
                                                    <div class="progress-bar" role="progressbar" 
                                                         style="width: <?= $submission_rate ?>%"
                                                         aria-valuenow="<?= $submission_rate ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                        <?= $submission_rate ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Filter activities table
        document.addEventListener('DOMContentLoaded', function() {
            const filterType = document.getElementById('filterType');
            const filterStatus = document.getElementById('filterStatus');
            const activitiesTable = document.getElementById('activitiesTable');
            
            if (filterType && filterStatus && activitiesTable) {
                function filterTable() {
                    const typeValue = filterType.value;
                    const statusValue = filterStatus.value;
                    const rows = activitiesTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    
                    for (let row of rows) {
                        const rowType = row.getAttribute('data-type');
                        const rowStatus = row.getAttribute('data-status');
                        
                        const typeMatch = !typeValue || rowType === typeValue;
                        const statusMatch = !statusValue || rowStatus === statusValue;
                        
                        row.style.display = (typeMatch && statusMatch) ? '' : 'none';
                    }
                }
                
                filterType.addEventListener('change', filterTable);
                filterStatus.addEventListener('change', filterTable);
            }

            // Charts for reports
            <?php if ($action === 'reports'): ?>
            // Type Performance Chart
            const typeCtx = document.getElementById('typePerformanceChart').getContext('2d');
            new Chart(typeCtx, {
                type: 'bar',
                data: {
                    labels: ['Classwork', 'Assignment', 'Quiz', 'Project', 'Homework'],
                    datasets: [{
                        label: 'Average Score',
                        data: [85, 78, 92, 88, 80],
                        backgroundColor: ['#198754', '#0dcaf0', '#ffc107', '#6f42c1', '#fd7e14']
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // Submission Stats Chart
            const statsCtx = document.getElementById('submissionStatsChart').getContext('2d');
            new Chart(statsCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Submitted', 'Graded', 'Pending', 'Late'],
                    datasets: [{
                        data: [65, 45, 20, 5],
                        backgroundColor: ['#198754', '#0dcaf0', '#ffc107', '#dc3545']
                    }]
                }
            });
            <?php endif; ?>
        });

        function deleteActivity(id) {
            if (confirm('Are you sure you want to delete this activity? This will also delete all submissions.')) {
                window.location.href = 'teacher_class_activities.php?action=delete&id=' + id;
            }
        }
    </script>
</body>
</html>