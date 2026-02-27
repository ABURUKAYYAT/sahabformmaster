<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/cbt_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();
ensure_cbt_schema($pdo);
$teacher_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT a.*, t.title, s.full_name, c.class_name, subj.subject_name
    FROM cbt_attempts a
    JOIN cbt_tests t ON a.test_id = t.id
    JOIN students s ON a.student_id = s.id
    JOIN classes c ON t.class_id = c.id
    JOIN subjects subj ON t.subject_id = subj.id
    WHERE t.teacher_id = ? AND t.school_id = ?
      AND a.status = 'submitted'
    ORDER BY a.submitted_at DESC
");
$stmt->execute([$teacher_id, $current_school_id]);
$attempts = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT Results - Teacher</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/cbt-schoolfeed-theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include '../includes/mobile_navigation.php'; ?>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-left">
            <div class="school-logo-container">
                <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                <div class="school-info">
                    <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                    <p class="school-tagline">CBT Results</p>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="teacher-info">
                <p class="teacher-label">Teacher</p>
                <span class="teacher-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
            </div>
            <a href="logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <?php include '../includes/teacher_sidebar.php'; ?>
    <main class="main-content">
        <div class="main-container">
        <div class="content-header">
            <div class="welcome-section">
                <h2>CBT Results</h2>
                <p>Review student scores and submissions.</p>
            </div>
            <div class="header-actions">
                <a href="cbt_tests.php" class="btn-modern-outline">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Tests</span>
                </a>
            </div>
        </div>
        <div id="cbt-offline-status" style="display:none;"></div>

        <div class="modern-card">
            <div class="card-body-modern">
                <table class="table-modern">
                    <thead>
                    <tr>
                        <th>Student</th>
                        <th>Test</th>
                        <th>Class</th>
                        <th>Subject</th>
                        <th>Score</th>
                        <th>Percent</th>
                        <th>Submitted</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($attempts)): ?>
                        <tr>
                            <td colspan="7">No submitted CBT attempts yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attempts as $a): ?>
                            <?php $percent = ((int)$a['total_questions'] > 0) ? round(((int)$a['score'] / (int)$a['total_questions']) * 100, 1) : 0; ?>
                            <tr>
                                <td><?php echo htmlspecialchars($a['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['title']); ?></td>
                                <td><?php echo htmlspecialchars($a['class_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['subject_name']); ?></td>
                                <td><?php echo intval($a['score']); ?> / <?php echo intval($a['total_questions']); ?></td>
                                <td><?php echo $percent; ?>%</td>
                                <td><?php echo $a['submitted_at'] ? date('M d, Y H:i', strtotime($a['submitted_at'])) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
    </main>
</div>

<?php include '../includes/floating-button.php'; ?>
<script src="../assets/js/cbt-offline-sync.js"></script>
<script>
    CBTOfflineSync.init({
        queueKey: 'cbt_teacher_offline_queue_v1',
        formSelector: 'form[data-offline-sync="true"]',
        statusElementId: 'cbt-offline-status',
        statusPrefix: 'Teacher CBT Sync:',
        swPath: '../cbt-sw.js'
    });
</script>
</body>
</html>
