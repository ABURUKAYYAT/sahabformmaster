<?php
// filepath: c:\xampp\htdocs\sahabformmaster\admin\lesson-plans-detail.php
session_start();
require_once '../config/db.php';

// Only allow principal (admin) and teachers to access
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['principal', 'teacher'])) {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['full_name'] ?? 'User';
$is_principal = ($user_role === 'principal');

$errors = [];
$success = '';

// Get lesson plan ID from URL
$plan_id = intval($_GET['id'] ?? 0);
if ($plan_id <= 0) {
    header("Location: lesson-plans.php");
    exit;
}

// Fetch lesson plan details
$stmt = $pdo->prepare("SELECT lp.*, s.subject_name, c.class_name, u.full_name as teacher_name, 
                             u2.full_name as approved_by_name
                      FROM lesson_plans lp 
                      JOIN subjects s ON lp.subject_id = s.id 
                      JOIN classes c ON lp.class_id = c.id 
                      JOIN users u ON lp.teacher_id = u.id 
                      LEFT JOIN users u2 ON lp.approved_by = u2.id 
                      WHERE lp.id = :id");
$stmt->execute(['id' => $plan_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    header("Location: lesson-plans.php");
    exit;
}

// Check permissions: teacher can only view own plans, principal can view all
if ($user_role === 'teacher' && $plan['teacher_id'] != $user_id) {
    header("Location: lesson-plans.php");
    exit;
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_comment') {
        $comment = trim($_POST['comment'] ?? '');

        if ($comment === '') {
            $errors[] = 'Comment cannot be empty.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO lesson_plan_feedback (lesson_plan_id, user_id, comment) 
                                  VALUES (:lesson_plan_id, :user_id, :comment)");
            $stmt->execute([
                'lesson_plan_id' => $plan_id,
                'user_id' => $user_id,
                'comment' => $comment
            ]);
            $success = 'Comment added successfully.';
            // Refresh page to show new comment
            header("Location: lesson-plans-detail.php?id=" . $plan_id);
            exit;
        }
    }

    if ($action === 'delete_comment' && $is_principal) {
        $comment_id = intval($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM lesson_plan_feedback WHERE id = :id AND lesson_plan_id = :plan_id");
            $stmt->execute(['id' => $comment_id, 'plan_id' => $plan_id]);
            $success = 'Comment deleted.';
            header("Location: lesson-plans-detail.php?id=" . $plan_id);
            exit;
        }
    }
}

// Fetch feedback/comments
$stmt = $pdo->prepare("SELECT lpf.*, u.full_name, u.id as user_id 
                      FROM lesson_plan_feedback lpf 
                      JOIN users u ON lpf.user_id = u.id 
                      WHERE lpf.lesson_plan_id = :plan_id 
                      ORDER BY lpf.created_at DESC");
  $stmt->execute(['plan_id' => $plan_id]);
$feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status and approval badge colors
function getStatusBadge($status) {
    $classes = [
        'draft' => 'badge-secondary',
        'scheduled' => 'badge-primary',
        'completed' => 'badge-success',
        'on_hold' => 'badge-warning',
        'cancelled' => 'badge-danger'
    ];
    return $classes[$status] ?? 'badge-default';
}

function getApprovalBadge($status) {
    $classes = [
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'pending' => 'badge-warning'
    ];
    return $classes[$status] ?? 'badge-default';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Lesson Plan Details | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/lesson-plan-detail.css">
</head>
<body>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-right">
            <div class="school-logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                <h1 class="school-name">SahabFormMaster</h1>
            </div>
        </div>

        <div class="header-left">
            <div class="teacher-info">
                <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="teacher-role"><?php echo ucfirst($user_role); ?></span>
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Lessons Plans</span>
                        </a>
                    </li>
                    
                </ul>
            </nav>
        </aside>

    <main class="main-content">
        <div class="content-header">
            <div class="header-top">
                <a href="lesson-plans.php" class="btn-back">← Back to Lesson Plans</a>
                <h2><?php echo htmlspecialchars($plan['subject_name']); ?> - <?php echo htmlspecialchars($plan['topic']); ?></h2>
            </div>
            <p class="small-muted">Class: <?php echo htmlspecialchars($plan['class_name']); ?> | Teacher: <?php echo htmlspecialchars($plan['teacher_name']); ?></p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Plan Overview -->
        <section class="detail-section">
            <div class="detail-card">
                <div class="detail-header">
                    <h3>Lesson Plan Overview</h3>
                    <div class="badges">
                        <span class="badge <?php echo getStatusBadge($plan['status']); ?>">
                            Status: <?php echo ucfirst($plan['status']); ?>
                        </span>
                        <?php if ($is_principal): ?>
                            <span class="badge <?php echo getApprovalBadge($plan['approval_status']); ?>">
                                Approval: <?php echo ucfirst($plan['approval_status']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Subject</label>
                        <p><?php echo htmlspecialchars($plan['subject_name']); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>Class</label>
                        <p><?php echo htmlspecialchars($plan['class_name']); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>Topic/Unit</label>
                        <p><?php echo htmlspecialchars($plan['topic']); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>Teacher</label>
                        <p><?php echo htmlspecialchars($plan['teacher_name']); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>Duration</label>
                        <p><?php echo intval($plan['duration']); ?> minutes</p>
                    </div>
                    <div class="detail-item">
                        <label>Planned Date</label>
                        <p><?php echo htmlspecialchars($plan['date_planned']); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>Assessment Method</label>
                        <p><?php echo htmlspecialchars($plan['assessment_method']); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>Created</label>
                        <p><?php echo date('M d, Y h:i A', strtotime($plan['created_at'])); ?></p>
                    </div>

                    <?php if ($plan['approved_by'] && $plan['approved_by_name']): ?>
                    <div class="detail-item">
                        <label>Approved By</label>
                        <p><?php echo htmlspecialchars($plan['approved_by_name']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Learning Objectives -->
        <section class="detail-section">
            <div class="detail-card">
                <h3>Learning Objectives</h3>
                <div class="detail-content">
                    <?php echo nl2br(htmlspecialchars($plan['learning_objectives'])); ?>
                </div>
            </div>
        </section>

        <!-- Teaching Methods -->
        <?php if ($plan['teaching_methods']): ?>
        <section class="detail-section">
            <div class="detail-card">
                <h3>Teaching Methods</h3>
                <div class="detail-content">
                    <?php echo nl2br(htmlspecialchars($plan['teaching_methods'])); ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Resources -->
        <?php if ($plan['resources']): ?>
        <section class="detail-section">
            <div class="detail-card">
                <h3>Learning Resources/Materials</h3>
                <div class="detail-content">
                    <?php echo nl2br(htmlspecialchars($plan['resources'])); ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Lesson Content -->
        <?php if ($plan['lesson_content']): ?>
        <section class="detail-section">
            <div class="detail-card">
                <h3>Detailed Lesson Content</h3>
                <div class="detail-content">
                    <?php echo nl2br(htmlspecialchars($plan['lesson_content'])); ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Assessment -->
        <section class="detail-section">
            <div class="detail-card">
                <h3>Assessment</h3>
                <div class="detail-grid">
                    <div class="detail-item full-width">
                        <label>Assessment Method</label>
                        <p><?php echo htmlspecialchars($plan['assessment_method']); ?></p>
                    </div>
                    <?php if ($plan['assessment_tasks']): ?>
                    <div class="detail-item full-width">
                        <label>Assessment Tasks</label>
                        <div class="detail-content">
                            <?php echo nl2br(htmlspecialchars($plan['assessment_tasks'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Differentiation -->
        <?php if ($plan['differentiation']): ?>
        <section class="detail-section">
            <div class="detail-card">
                <h3>Differentiation Strategies</h3>
                <div class="detail-content">
                    <?php echo nl2br(htmlspecialchars($plan['differentiation'])); ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Homework -->
        <?php if ($plan['homework']): ?>
        <section class="detail-section">
            <div class="detail-card">
                <h3>Homework/Assignment</h3>
                <div class="detail-content">
                    <?php echo nl2br(htmlspecialchars($plan['homework'])); ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Principal's Remarks -->
        <?php if ($plan['principal_remarks']): ?>
        <section class="detail-section">
            <div class="detail-card principal-remarks">
                <h3>🔔 Principal's Remarks</h3>
                <div class="detail-content">
                    <?php echo nl2br(htmlspecialchars($plan['principal_remarks'])); ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Actions -->
        <section class="detail-section">
            <div class="detail-card">
                <div class="action-buttons">
                    <?php if ($user_role === 'teacher' && $plan['teacher_id'] == $user_id && $plan['status'] === 'draft'): ?>
                        <a href="lesson-plans.php?edit=<?php echo intval($plan['id']); ?>" class="btn-gold">Edit Lesson Plan</a>
                    <?php elseif ($is_principal): ?>
                        <a href="lesson-plans.php?edit=<?php echo intval($plan['id']); ?>" class="btn-gold">Edit Lesson Plan</a>
                    <?php endif; ?>

                    <a href="lesson-plans.php" class="btn-secondary">Back to List</a>
                </div>
            </div>
        </section>

        <!-- Feedback/Comments Section -->
        <section class="detail-section">
            <div class="detail-card">
                <h3>📝 Feedback & Comments</h3>

                <?php if ($plan['approval_status'] === 'rejected'): ?>
                    <div class="alert alert-warning">
                        <strong>⚠️ This lesson plan was rejected.</strong> Please review the feedback below and edit accordingly.
                    </div>
                <?php endif; ?>

                <!-- Add Comment Form -->
                <div class="comment-form">
                    <h4>Add Your Comment</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_comment">
                        <div class="form-group">
                            <textarea name="comment" class="form-control" rows="3" placeholder="Enter your feedback or comment..." required></textarea>
                        </div>
                        <button type="submit" class="btn-gold">Post Comment</button>
                    </form>
                </div>

                <!-- Comments List -->
                <div class="comments-list">
                    <?php if (count($feedback) === 0): ?>
                        <p class="small-muted">No comments yet.</p>
                    <?php else: ?>
                        <?php foreach ($feedback as $f): ?>
                            <div class="comment-item">
                                <div class="comment-header">
                                    <strong><?php echo htmlspecialchars($f['full_name']); ?></strong>
                                    <span class="comment-date"><?php echo date('M d, Y h:i A', strtotime($f['created_at'])); ?></span>
                                    <?php if ($is_principal): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?php echo intval($f['id']); ?>">
                                            <button type="submit" class="btn-delete-comment" onclick="return confirm('Delete this comment?');">✕</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <div class="comment-body">
                                    <?php echo nl2br(htmlspecialchars($f['comment'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Professional lesson planning and management system.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php">Dashboard</a>
                    <a href="lesson-plans.php">Lesson Plans</a>
                    <a href="manage_curriculum.php">Curriculum</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p>Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Version 1.0</p>
        </div>
    </div>
</footer>

<?php include '../includes/floating-button.php'; ?>
</body>
</html>
