<?php
// student_class_activities.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$current_school_id = get_current_school_id();

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
                SELECT class_id FROM students WHERE id = ? AND school_id = ?
            ) AND ca.status = 'published' AND ca.school_id = ?
        ";
        $check_stmt = $pdo->prepare($activity_check);
        $check_stmt->execute([$activity_id, $student_id, $current_school_id, $current_school_id]);
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
            (school_id, activity_id, student_id, submission_text, attachment_path, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ";
        $insert_stmt = $pdo->prepare($insert_sql);
        $insert_stmt->execute([
            $current_school_id,
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
                  JOIN classes c ON s.class_id = c.id AND c.school_id = ?
                  WHERE s.id = ? AND s.school_id = ?";
$student_stmt = $pdo->prepare($student_query);
$student_stmt->execute([$current_school_id, $student_id, $current_school_id]);
$student = $student_stmt->fetch();

if (!$student) {
    die('Student not found');
}

$student_class_id = $student['class_id'];
$student_name = $student['full_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Activities | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="stylesheet" href="../assets/css/student-class-activities.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">Student Portal</p>
                    </div>
                </div>
            </div>

            <!-- Student Info and Logout -->
            <div class="header-right">
                <div class="student-info">
                    <p class="student-label">Student</p>
                    <span class="student-name"><?php echo htmlspecialchars($student_name); ?></span>
                    <span class="admission-number"><?php echo htmlspecialchars($student['admission_no']); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <?php include '../includes/student_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
        <div class="main-container">
        <?php
        // Show messages
        if (isset($_SESSION['success'])) {
            echo '<div class="alert-modern alert-success-modern">
                    <i class="fas fa-check-circle"></i>
                    <span>' . $_SESSION['success'] . '</span>
                  </div>';
            unset($_SESSION['success']);
        }

        if (isset($_SESSION['error'])) {
            echo '<div class="alert-modern alert-error-modern">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>' . $_SESSION['error'] . '</span>
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

            <div class="modern-card">
                <div class="card-header-modern alt">
                    <h4 style="margin: 0;"><i class="fas fa-comments"></i> Feedback for: <?php echo htmlspecialchars($feedback['activity_title']); ?></h4>
                </div>
                <div class="card-body-modern">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Activity Title:</strong> <?php echo htmlspecialchars($feedback['activity_title']); ?></p>
                            <p><strong>Type:</strong> <?php echo ucfirst($feedback['activity_type']); ?></p>
                            <p><strong>Due Date:</strong>
                                <?php echo $feedback['due_date'] ? date('M d, Y H:i', strtotime($feedback['due_date'])) : 'Not set'; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Total Marks:</strong> <?php echo $feedback['total_marks']; ?></p>
                            <p><strong>Your Score:</strong>
                                <span class="badge badge-success" style="font-size: 1.2em;">
                                    <?php echo $feedback['marks_obtained']; ?>/<?php echo $feedback['total_marks']; ?>
                                </span>
                            </p>
                            <p><strong>Submitted On:</strong>
                                <?php echo date('M d, Y H:i', strtotime($feedback['submitted_at'])); ?>
                            </p>
                        </div>
                    </div>

                    <div class="card" style="margin-top: 2rem; background: #f8f9fa;">
                        <div class="card-header" style="background: #e9ecef;">
                            <h5 style="margin: 0;"><i class="fas fa-comment"></i> Teacher Feedback</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($feedback['feedback']): ?>
                                <div style="background: white; padding: 1rem; border-radius: 8px; border-left: 4px solid #28a745;">
                                    <?php echo nl2br(htmlspecialchars($feedback['feedback'])); ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info" style="margin: 0;">
                                    <i class="fas fa-info-circle"></i> No feedback provided by the teacher.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top: 2rem; text-align: center;">
                        <a href="student_class_activities.php?action=graded" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Back to Graded Activities
                        </a>
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
                JOIN subjects s ON ca.subject_id = s.id AND s.school_id = ?
                JOIN users u ON ca.teacher_id = u.id
                LEFT JOIN student_submissions ss ON ca.id = ss.activity_id AND ss.student_id = ?
                WHERE ca.id = ? AND ca.class_id = ? AND ca.school_id = ?
            ";
            $activity_stmt = $pdo->prepare($activity_query);
            $activity_stmt->execute([$current_school_id, $student_id, $activity_id, $student_class_id, $current_school_id]);
            $activity = $activity_stmt->fetch();

            if (!$activity):
            ?>
            <div class="alert-modern alert-error-modern">
                Activity not found or access denied.
                <a href="student_class_activities.php" class="btn btn-primary" style="margin-left: 1rem;">Back to Activities</a>
            </div>
            <?php else: ?>
            <div class="modern-card">
                <div class="card-header-modern">
                    <h4 style="margin: 0;"><i class="fas fa-eye"></i> <?php echo htmlspecialchars($activity['title']); ?></h4>
                    <small><?php echo htmlspecialchars($activity['subject_name']); ?> • <?php echo htmlspecialchars($activity['teacher_name']); ?></small>
                </div>
                <div class="card-body-modern">
                    <div class="row">
                        <div class="col-md-8">
                            <h6>Description</h6>
                            <p><?php echo nl2br(htmlspecialchars($activity['description'])); ?></p>

                            <h6 style="margin-top: 2rem;">Instructions</h6>
                            <p><?php echo nl2br(htmlspecialchars($activity['instructions'])); ?></p>

                            <?php if (!empty($activity['attachment_path'] ?? '')): ?>
                            <div style="margin-top: 2rem;">
                                <a href="<?php echo $activity['attachment_path']; ?>"
                                   class="btn btn-outline-primary" target="_blank">
                                    <i class="fas fa-download"></i> Download Activity Attachment
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <div class="modern-card">
                                <div class="card-body-modern">
                                    <h6>Activity Details</h6>
                                    <p><strong>Type:</strong> <?php echo ucfirst($activity['activity_type']); ?></p>
                                    <p><strong>Due Date:</strong>
                                        <?php if ($activity['due_date']): ?>
                                            <?php echo date('M d, Y H:i', strtotime($activity['due_date'])); ?>
                                            <?php if (strtotime($activity['due_date']) < time()): ?>
                                                <span class="badge badge-danger">Overdue</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            No due date
                                        <?php endif; ?>
                                    </p>
                                    <p><strong>Total Marks:</strong> <?php echo $activity['total_marks']; ?></p>
                                    <p><strong>Your Status:</strong>
                                        <span class="badge badge-<?php
                                            echo $activity['submission_status'] === 'graded' ? 'success' :
                                                 ($activity['submission_status'] === 'submitted' ? 'info' :
                                                 ($activity['submission_status'] === 'late' ? 'warning' : 'secondary'));
                                        ?>">
                                            <?php echo $activity['submission_status'] ? ucfirst($activity['submission_status']) : 'Not submitted'; ?>
                                        </span>
                                    </p>
                                    <?php if ($activity['marks_obtained']): ?>
                                        <p><strong>Your Score:</strong>
                                            <span class="badge badge-success" style="font-size: 1.1em;">
                                                <?php echo $activity['marks_obtained']; ?>/<?php echo $activity['total_marks']; ?>
                                            </span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submission Section -->
                    <?php if ($activity['submission_id']): ?>
                        <div class="modern-card">
                            <div class="card-header-modern alt">
                                <h5 style="margin: 0;"><i class="fas fa-file-alt"></i> Your Submission</h5>
                            </div>
                            <div class="card-body-modern">
                                <p><strong>Submission Text:</strong></p>
                                <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                                    <?php echo $activity['submission_text'] ? nl2br(htmlspecialchars($activity['submission_text'])) : '<em>No text submitted</em>'; ?>
                                </div>

                                <?php if ($activity['student_attachment']): ?>
                                    <p><strong>Attachment:</strong></p>
                                    <a href="<?php echo $activity['student_attachment']; ?>"
                                       class="btn btn-outline-secondary" target="_blank">
                                        <i class="fas fa-download"></i> Download Your Submission
                                    </a>
                                <?php endif; ?>

                                <?php if ($activity['feedback']): ?>
                                    <div class="modern-card">
                                        <div class="card-header-modern">
                                            <h5 style="margin: 0;"><i class="fas fa-comment"></i> Teacher Feedback</h5>
                                        </div>
                                        <div class="card-body-modern">
                                            <p><strong>Score:</strong>
                                                <span class="badge badge-success" style="font-size: 1.3em;">
                                                    <?php echo $activity['marks_obtained']; ?>/<?php echo $activity['total_marks']; ?>
                                                </span>
                                                <?php if ($activity['graded_at']): ?>
                                                    <span class="text-muted" style="margin-left: 1rem;">
                                                        Graded on: <?php echo date('M d, Y', strtotime($activity['graded_at'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </p>
                                            <p><strong>Feedback:</strong></p>
                                            <div style="background: white; padding: 1rem; border-radius: 8px; border-left: 4px solid #28a745;">
                                                <?php echo nl2br(htmlspecialchars($activity['feedback'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top: 2rem; text-align: center;">
                                    <a href="student_class_activities.php?action=dashboard" class="btn btn-primary">
                                        <i class="fas fa-arrow-left"></i> Back to Activities
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Submission Form -->
                        <div class="modern-card">
                            <div class="card-header-modern">
                                <h5 style="margin: 0;"><i class="fas fa-upload"></i> Submit Your Work</h5>
                            </div>
                            <div class="card-body-modern">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="activity_id" value="<?php echo $activity_id; ?>">

                                    <div class="form-group" style="margin-bottom: 1.5rem;">
                                        <label for="submission_text">Submission Text *</label>
                                        <textarea class="form-control" name="submission_text" rows="5"
                                                  placeholder="Type your answer or submission here..." required></textarea>
                                    </div>

                                    <div class="form-group" style="margin-bottom: 1.5rem;">
                                        <label for="attachment">Attachment (Optional)</label>
                                        <input type="file" class="form-control" name="attachment"
                                               accept=".pdf,.doc,.docx,.jpg,.png,.zip,.txt">
                                        <small class="text-muted">Max file size: 10MB. Allowed: PDF, Word, Images, ZIP, TXT</small>
                                    </div>

                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        Once submitted, you cannot edit your submission unless the teacher allows resubmission.
                                    </div>

                                    <div style="display: flex; justify-content: space-between; margin-top: 2rem;">
                                        <a href="student_class_activities.php?action=dashboard" class="btn btn-secondary">Cancel</a>
                                        <button type="submit" class="btn btn-success">Submit Work</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($action === 'submit' && $activity_id > 0): ?>
            <!-- Submit Work Page (Alternative to modal submission) -->
            <?php
            $submit_query = "
                SELECT ca.*, s.subject_name, u.full_name as teacher_name
                FROM class_activities ca
                JOIN subjects s ON ca.subject_id = s.id AND s.school_id = ?
                JOIN users u ON ca.teacher_id = u.id
                WHERE ca.id = ? AND ca.class_id = ? AND ca.status = 'published' AND ca.school_id = ?
            ";
            $submit_stmt = $pdo->prepare($submit_query);
            $submit_stmt->execute([$current_school_id, $activity_id, $student_class_id, $current_school_id]);
            $activity = $submit_stmt->fetch();

            if (!$activity):
            ?>
            <div class="alert-modern alert-error-modern">
                Activity not found or closed for submission.
                <a href="student_class_activities.php" class="btn btn-primary" style="margin-left: 1rem;">Back to Activities</a>
            </div>
            <?php else:
                // Check if already submitted
                $check_submission = "SELECT id FROM student_submissions WHERE activity_id = ? AND student_id = ?";
                $check_stmt = $pdo->prepare($check_submission);
                $check_stmt->execute([$activity_id, $student_id]);

                if ($check_stmt->rowCount() > 0):
            ?>
            <div class="alert-modern alert-success-modern">
                You have already submitted this activity.
                <a href="student_class_activities.php?action=view&id=<?php echo $activity_id; ?>" class="btn btn-primary" style="margin-left: 1rem;">
                    View Your Submission
                </a>
            </div>
            <?php else: ?>
            <div class="row">
                <div class="col-md-8">
                    <div class="modern-card">
                        <div class="card-header-modern">
                            <h4 style="margin: 0;"><i class="fas fa-upload"></i> Submit: <?php echo htmlspecialchars($activity['title']); ?></h4>
                        </div>
                        <div class="card-body-modern">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="activity_id" value="<?php echo $activity_id; ?>">

                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label for="submission_text">Submission Text *</label>
                                    <textarea class="form-control" name="submission_text" rows="8"
                                              placeholder="Type your answer or submission here..." required></textarea>
                                </div>

                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label for="attachment">Attachment (Optional)</label>
                                    <input type="file" class="form-control" name="attachment"
                                           accept=".pdf,.doc,.docx,.jpg,.png,.zip,.txt">
                                    <small class="text-muted">Max file size: 10MB. Allowed: PDF, Word, Images, ZIP, TXT</small>
                                </div>

                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Once submitted, you cannot edit your submission unless the teacher allows resubmission.
                                </div>

                                <div style="display: flex; justify-content: space-between; margin-top: 2rem;">
                                    <a href="student_class_activities.php?action=dashboard" class="btn btn-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-success">Submit Work</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="modern-card">
                        <div class="card-header-modern">
                            <h5 style="margin: 0;"><i class="fas fa-info-circle"></i> Activity Details</h5>
                        </div>
                        <div class="card-body-modern">
                            <p><strong>Subject:</strong> <?php echo htmlspecialchars($activity['subject_name']); ?></p>
                            <p><strong>Teacher:</strong> <?php echo htmlspecialchars($activity['teacher_name']); ?></p>
                            <p><strong>Type:</strong> <?php echo ucfirst($activity['activity_type']); ?></p>
                            <?php if ($activity['due_date']): ?>
                                <p><strong>Due Date:</strong> <?php echo date('M d, Y H:i', strtotime($activity['due_date'])); ?></p>
                            <?php endif; ?>
                            <p><strong>Total Marks:</strong> <?php echo $activity['total_marks']; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; endif; ?>

        <?php else: ?>
            <!-- Welcome Section -->
            <div class="modern-card hero-card">
                <div class="card-body-modern">
                    <h2 style="margin-bottom: 0.5rem;"><i class="fas fa-graduation-cap"></i> Welcome back, <?php echo htmlspecialchars($student_name); ?>!</h2>
                    <p style="margin: 0; opacity: 0.9;">Manage your class activities and assignments for <?php echo htmlspecialchars($student['class_name']); ?></p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="dashboard-cards" style="margin-bottom: 2rem;">
                <?php
                $pending_query = "
                    SELECT COUNT(DISTINCT ca.id) as count
                    FROM class_activities ca
                    LEFT JOIN student_submissions ss ON ca.id = ss.activity_id AND ss.student_id = ?
                    WHERE ca.class_id = ? AND ca.school_id = ?
                    AND ca.status = 'published'
                    AND (ss.id IS NULL OR ss.status IN ('pending', 'late'))
                    AND (ca.due_date IS NULL OR ca.due_date > NOW())
                ";
                $pending_stmt = $pdo->prepare($pending_query);
                $pending_stmt->execute([$student_id, $student_class_id, $current_school_id]);
                $pending_count = $pending_stmt->fetch()['count'];

                $due_week_query = "
                    SELECT COUNT(*) as count FROM class_activities
                    WHERE class_id = ? AND school_id = ?
                    AND status = 'published'
                    AND due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
                ";
                $due_week_stmt = $pdo->prepare($due_week_query);
                $due_week_stmt->execute([$student_class_id, $current_school_id]);
                $due_week_count = $due_week_stmt->fetch()['count'];

                $submitted_query = "
                    SELECT COUNT(*) as count FROM student_submissions
                    WHERE student_id = ? AND school_id = ? AND status IN ('submitted', 'graded')
                ";
                $submitted_stmt = $pdo->prepare($submitted_query);
                $submitted_stmt->execute([$student_id, $current_school_id]);
                $submitted_count = $submitted_stmt->fetch()['count'];

                $avg_query = "
                    SELECT AVG(marks_obtained) as avg_score
                    FROM student_submissions
                    WHERE student_id = ? AND school_id = ? AND status = 'graded' AND marks_obtained IS NOT NULL
                ";
                $avg_stmt = $pdo->prepare($avg_query);
                $avg_stmt->execute([$student_id, $current_school_id]);
                $avg_result = $avg_stmt->fetch();
                $avg_score = $avg_result['avg_score'] ? number_format($avg_result['avg_score'], 1) : '0';
                ?>
                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-clock"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Pending Activities</h3>
                        <p class="card-value"><?php echo $pending_count; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Active</span>
                        </div>
                    </div>
                </div>
                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-calendar-week"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Due This Week</h3>
                        <p class="card-value"><?php echo $due_week_count; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Upcoming</span>
                        </div>
                    </div>
                </div>
                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Submitted</h3>
                        <p class="card-value"><?php echo $submitted_count; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Complete</span>
                        </div>
                    </div>
                </div>
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Average Score</h3>
                        <p class="card-value"><?php echo $avg_score; ?>%</p>
                        <div class="card-footer">
                            <span class="card-badge">Grade</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Activity Tabs -->
            <div class="tabs-modern" style="margin-bottom: 2rem;">
                <a href="?action=dashboard" class="tab-modern <?php echo $action === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tasks"></i>
                    <span>Active</span>
                </a>
                <a href="?action=submitted" class="tab-modern <?php echo $action === 'submitted' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle"></i>
                    <span>Submitted</span>
                </a>
                <a href="?action=graded" class="tab-modern <?php echo $action === 'graded' ? 'active' : ''; ?>">
                    <i class="fas fa-star"></i>
                    <span>Graded</span>
                </a>
            </div>

            <!-- Activities Grid -->
            <?php
            // Build query based on tab
            $activities_query = "
                SELECT ca.*, s.subject_name, u.full_name as teacher_name,
                       ss.id as submission_id, ss.status as submission_status,
                       ss.marks_obtained, ss.feedback, ss.submitted_at,
                       ss.attachment_path as student_attachment,
                       ss.id as submission_db_id
                FROM class_activities ca
                JOIN subjects s ON ca.subject_id = s.id AND s.school_id = ?
                JOIN users u ON ca.teacher_id = u.id
                LEFT JOIN student_submissions ss ON ca.id = ss.activity_id AND ss.student_id = ?
                WHERE ca.class_id = ? AND ca.status = 'published' AND ca.school_id = ?
            ";

            if ($action === 'submitted') {
                $activities_query .= " AND ss.id IS NOT NULL AND ss.status IN ('submitted', 'graded', 'late')";
            } elseif ($action === 'graded') {
                $activities_query .= " AND ss.status = 'graded'";
            } else {
                $activities_query .= " AND (ss.id IS NULL OR ss.status IN ('pending', 'late'))";
            }

            $activities_query .= " ORDER BY ca.due_date ASC";

            $activities_stmt = $pdo->prepare($activities_query);
            $activities_stmt->execute([$current_school_id, $student_id, $student_class_id, $current_school_id]);
            $activities = $activities_stmt->fetchAll();

            if (count($activities) > 0):
                foreach ($activities as $activity):
                    $is_overdue = $activity['due_date'] && strtotime($activity['due_date']) < time();
                    $due_soon = $activity['due_date'] &&
                               strtotime($activity['due_date']) > time() &&
                               strtotime($activity['due_date']) < strtotime('+3 days');
            ?>
            <div class="modern-card activity-card">
                <div class="card-body-modern">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 style="margin-bottom: 0.5rem;">
                                <?php echo htmlspecialchars($activity['title']); ?>
                                <?php if ($is_overdue): ?>
                                    <span class="badge badge-danger">Overdue</span>
                                <?php elseif ($due_soon): ?>
                                    <span class="badge badge-warning">Due Soon</span>
                                <?php endif; ?>
                            </h5>
                            <p class="activity-meta" style="margin-bottom: 0.5rem;">
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($activity['subject_name']); ?> •
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($activity['teacher_name']); ?>
                            </p>
                            <p class="activity-meta" style="margin: 0;">
                                <?php echo htmlspecialchars(substr($activity['description'], 0, 100)); ?>
                                <?php echo strlen($activity['description']) > 100 ? '...' : ''; ?>
                            </p>
                        </div>
                        <div class="col-md-4 activity-actions text-md-right">
                            <?php if ($activity['due_date']): ?>
                                <p style="margin-bottom: 0.5rem;">
                                    <i class="fas fa-clock"></i> Due: <?php echo date('M d, Y H:i', strtotime($activity['due_date'])); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($activity['student_attachment'] ?? '')): ?>
                                <a href="<?php echo $activity['student_attachment']; ?>"
                                   class="btn btn-sm btn-outline-secondary" target="_blank">
                                    <i class="fas fa-download"></i> Your File
                                </a>
                            <?php endif; ?>

                            <?php if ($action === 'graded'): ?>
                                <a href="?action=feedback&feedback_id=<?php echo $activity['submission_db_id']; ?>"
                                   class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-eye"></i> View Feedback
                                </a>
                            <?php elseif ($activity['submission_id']): ?>
                                <span class="badge badge-success">Submitted</span>
                                <a href="?action=view&id=<?php echo $activity['id']; ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            <?php else: ?>
                                <a href="?action=submit&id=<?php echo $activity['id']; ?>"
                                   class="btn btn-sm btn-success">
                                    <i class="fas fa-upload"></i> Submit Work
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="modern-card">
                <div class="card-body-modern text-center" style="padding: 3rem;">
                    <div style="font-size: 3rem; color: #dee2e6; margin-bottom: 1rem;">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h4 style="color: #6c757d; margin-bottom: 0.5rem;">No Activities Found</h4>
                    <p style="color: #6c757d; margin: 0;">
                        <?php if ($action === 'dashboard'): ?>
                            No pending activities at the moment.
                        <?php elseif ($action === 'submitted'): ?>
                            No submitted activities yet.
                        <?php elseif ($action === 'graded'): ?>
                            No graded activities yet.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>
        </main>
    </div>

    

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        // Add active class on scroll for header
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Animate cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe dashboard cards
        document.querySelectorAll('.card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
