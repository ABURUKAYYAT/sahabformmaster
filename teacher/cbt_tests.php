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
$action = $_GET['action'] ?? 'list';
$test_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$error = '';

if (!empty($_SESSION['cbt_message'])) {
    $message = (string)$_SESSION['cbt_message'];
    unset($_SESSION['cbt_message']);
}
if (!empty($_SESSION['cbt_error'])) {
    $error = (string)$_SESSION['cbt_error'];
    unset($_SESSION['cbt_error']);
}

// Fetch classes and subjects assigned to teacher
$class_stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name
    FROM class_teachers ct
    JOIN classes c ON ct.class_id = c.id
    WHERE ct.teacher_id = ? AND c.school_id = ?
");
$class_stmt->execute([$teacher_id, $current_school_id]);
$classes = $class_stmt->fetchAll();
$class_ids = array_map(static function ($row) {
    return (int)$row['id'];
}, $classes);

$subject_stmt = $pdo->prepare("
    SELECT DISTINCT s.id, s.subject_name
    FROM subject_assignments sa
    JOIN subjects s ON sa.subject_id = s.id
    WHERE sa.teacher_id = ? AND s.school_id = ?
");
$subject_stmt->execute([$teacher_id, $current_school_id]);
$subjects = $subject_stmt->fetchAll();
$subject_ids = array_map(static function ($row) {
    return (int)$row['id'];
}, $subjects);

// Create or update test
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_test'])) {
    $title = trim($_POST['title'] ?? '');
    $class_id = intval($_POST['class_id'] ?? 0);
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $duration = intval($_POST['duration_minutes'] ?? 30);
    $starts_at = cbt_to_mysql_datetime_or_null($_POST['starts_at'] ?? '');
    $ends_at = cbt_to_mysql_datetime_or_null($_POST['ends_at'] ?? '');
    $status = trim((string)($_POST['status'] ?? 'draft'));
    $allowed_statuses = ['draft', 'published', 'closed'];
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'draft';
    }
    $duration = max(5, min(300, $duration));

    if ($title === '') {
        $_SESSION['cbt_error'] = 'Test title is required.';
    } elseif (!in_array($class_id, $class_ids, true)) {
        $_SESSION['cbt_error'] = 'Selected class is not assigned to you.';
    } elseif (!in_array($subject_id, $subject_ids, true)) {
        $_SESSION['cbt_error'] = 'Selected subject is not assigned to you.';
    } elseif ($starts_at !== null && $ends_at !== null && strtotime($ends_at) <= strtotime($starts_at)) {
        $_SESSION['cbt_error'] = 'End time must be after start time.';
    } else {
        if ($test_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE cbt_tests
                SET title = ?, class_id = ?, subject_id = ?, duration_minutes = ?, starts_at = ?, ends_at = ?, status = ?, updated_at = NOW()
                WHERE id = ? AND teacher_id = ? AND school_id = ?
            ");
            $stmt->execute([$title, $class_id, $subject_id, $duration, $starts_at, $ends_at, $status, $test_id, $teacher_id, $current_school_id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cbt_tests (school_id, teacher_id, class_id, subject_id, title, duration_minutes, starts_at, ends_at, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$current_school_id, $teacher_id, $class_id, $subject_id, $title, $duration, $starts_at, $ends_at, $status]);
            $test_id = (int)$pdo->lastInsertId();
        }
        $_SESSION['cbt_message'] = 'CBT test saved successfully.';
    }

    header("Location: cbt_tests.php?action=edit&id=" . (int)$test_id);
    exit;
}

// Quick status update (publish/draft/close)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_test_status'])) {
    $target_test_id = (int)($_POST['target_test_id'] ?? 0);
    $target_status = trim((string)($_POST['target_status'] ?? 'draft'));
    $redirect_mode = trim((string)($_POST['redirect_mode'] ?? 'list'));
    $allowed_statuses = ['draft', 'published', 'closed'];

    if (!in_array($target_status, $allowed_statuses, true)) {
        $_SESSION['cbt_error'] = 'Invalid CBT status selected.';
    } elseif ($target_test_id <= 0) {
        $_SESSION['cbt_error'] = 'Invalid test selected.';
    } else {
        $own_stmt = $pdo->prepare("
            SELECT t.id,
                   t.status,
                   (SELECT COUNT(*) FROM cbt_questions q WHERE q.test_id = t.id) AS question_count
            FROM cbt_tests t
            WHERE t.id = ? AND t.teacher_id = ? AND t.school_id = ?
            LIMIT 1
        ");
        $own_stmt->execute([$target_test_id, $teacher_id, $current_school_id]);
        $target_test = $own_stmt->fetch();

        if (!$target_test) {
            $_SESSION['cbt_error'] = 'Test not found or access denied.';
        } elseif ($target_status === 'published' && (int)$target_test['question_count'] <= 0) {
            $_SESSION['cbt_error'] = 'Add at least one question before publishing.';
        } else {
            $upd = $pdo->prepare("
                UPDATE cbt_tests
                SET status = ?, updated_at = NOW()
                WHERE id = ? AND teacher_id = ? AND school_id = ?
            ");
            $upd->execute([$target_status, $target_test_id, $teacher_id, $current_school_id]);
            $_SESSION['cbt_message'] = 'Test status updated to ' . ucfirst($target_status) . '.';
        }
    }

    if ($redirect_mode === 'edit') {
        header("Location: cbt_tests.php?action=edit&id=" . (int)$target_test_id);
    } else {
        header("Location: cbt_tests.php");
    }
    exit;
}

// Add question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question']) && $test_id > 0) {
    $question_text = trim($_POST['question_text'] ?? '');
    $option_a = trim($_POST['option_a'] ?? '');
    $option_b = trim($_POST['option_b'] ?? '');
    $option_c = trim($_POST['option_c'] ?? '');
    $option_d = trim($_POST['option_d'] ?? '');
    $correct_option = $_POST['correct_option'] ?? 'A';

    $allowed_options = ['A', 'B', 'C', 'D'];
    if (!in_array($correct_option, $allowed_options, true)) {
        $correct_option = 'A';
    }

    $owner_stmt = $pdo->prepare("SELECT id FROM cbt_tests WHERE id = ? AND teacher_id = ? AND school_id = ?");
    $owner_stmt->execute([$test_id, $teacher_id, $current_school_id]);
    $owns_test = (bool)$owner_stmt->fetchColumn();

    if (!$owns_test) {
        $_SESSION['cbt_error'] = 'You are not allowed to modify this test.';
    } elseif ($question_text === '' || $option_a === '' || $option_b === '' || $option_c === '' || $option_d === '') {
        $_SESSION['cbt_error'] = 'Question text and all options are required.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO cbt_questions (test_id, question_text, option_a, option_b, option_c, option_d, correct_option)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$test_id, $question_text, $option_a, $option_b, $option_c, $option_d, $correct_option]);
        $_SESSION['cbt_message'] = 'Question added successfully.';
    }

    header("Location: cbt_tests.php?action=edit&id=" . (int)$test_id);
    exit;
}

// Import questions from question bank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_questions']) && $test_id > 0) {
    $owner_stmt = $pdo->prepare("SELECT id FROM cbt_tests WHERE id = ? AND teacher_id = ? AND school_id = ?");
    $owner_stmt->execute([$test_id, $teacher_id, $current_school_id]);
    if (!$owner_stmt->fetchColumn()) {
        $_SESSION['cbt_error'] = 'You are not allowed to import questions for this test.';
        header("Location: cbt_tests.php?action=edit&id=" . (int)$test_id);
        exit;
    }

    $question_ids = $_POST['question_ids'] ?? [];
    $imported_count = 0;
    if (!empty($question_ids)) {
        foreach ($question_ids as $qid) {
            $qid = intval($qid);
            $qstmt = $pdo->prepare("
                SELECT qb.id, qb.question_text
                FROM questions_bank qb
                WHERE qb.id = ? AND qb.school_id = ? AND qb.question_type = 'mcq'
            ");
            $qstmt->execute([$qid, $current_school_id]);
            $qrow = $qstmt->fetch();
            if (!$qrow) continue;

            // Avoid duplicate question text in the same test
            $dup = $pdo->prepare("SELECT COUNT(*) FROM cbt_questions WHERE test_id = ? AND question_text = ?");
            $dup->execute([$test_id, $qrow['question_text']]);
            if ($dup->fetchColumn() > 0) continue;

            $opt = $pdo->prepare("
                SELECT option_letter, option_text, is_correct
                FROM question_options
                WHERE question_id = ? AND school_id = ?
                ORDER BY option_letter ASC
            ");
            $opt->execute([$qid, $current_school_id]);
            $options = $opt->fetchAll();

            $option_map = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
            $correct = 'A';
            foreach ($options as $o) {
                $letter = strtoupper($o['option_letter']);
                if (isset($option_map[$letter])) {
                    $option_map[$letter] = $o['option_text'];
                    if ((int)$o['is_correct'] === 1) {
                        $correct = $letter;
                    }
                }
            }

            // Require A-D options for CBT
            if ($option_map['A'] === '' || $option_map['B'] === '' || $option_map['C'] === '' || $option_map['D'] === '') {
                continue;
            }

            $ins = $pdo->prepare("
                INSERT INTO cbt_questions (test_id, question_text, option_a, option_b, option_c, option_d, correct_option)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $test_id,
                $qrow['question_text'],
                $option_map['A'],
                $option_map['B'],
                $option_map['C'],
                $option_map['D'],
                $correct
            ]);
            $imported_count++;
        }
    }
    $_SESSION['cbt_message'] = $imported_count > 0
        ? "Imported {$imported_count} question(s) from bank."
        : 'No questions were imported. Check selection/duplicates/options.';
    header("Location: cbt_tests.php?action=edit&id=" . (int)$test_id);
    exit;
}

// Delete question
if (isset($_GET['delete_q']) && $test_id > 0) {
    $qid = intval($_GET['delete_q']);
    $stmt = $pdo->prepare("
        DELETE q FROM cbt_questions q
        JOIN cbt_tests t ON q.test_id = t.id
        WHERE q.id = ? AND t.teacher_id = ? AND t.school_id = ?
    ");
    $stmt->execute([$qid, $teacher_id, $current_school_id]);
    $_SESSION['cbt_message'] = 'Question deleted.';
    header("Location: cbt_tests.php?action=edit&id=" . (int)$test_id);
    exit;
}

// Fetch test for edit
$test = null;
$questions = [];
if ($test_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cbt_tests WHERE id = ? AND teacher_id = ? AND school_id = ?");
    $stmt->execute([$test_id, $teacher_id, $current_school_id]);
    $test = $stmt->fetch();

    $qstmt = $pdo->prepare("SELECT * FROM cbt_questions WHERE test_id = ? ORDER BY question_order ASC, id ASC");
    $qstmt->execute([$test_id]);
    $questions = $qstmt->fetchAll();

    if (!$test && $action === 'edit') {
        $action = 'list';
        $error = 'Test not found or you do not have access to it.';
    }
}

// Bank questions for import (only on edit)
$bank_questions = [];
if ($action === 'edit' && $test_id > 0 && $test) {
    $bq = $pdo->prepare("
        SELECT qb.id, qb.question_text, qb.difficulty_level
        FROM questions_bank qb
        WHERE qb.school_id = ? AND qb.question_type = 'mcq'
          AND qb.subject_id = ? AND qb.class_id = ?
        ORDER BY qb.created_at DESC
        LIMIT 50
    ");
    $bq->execute([$current_school_id, $test['subject_id'], $test['class_id']]);
    $bank_questions = $bq->fetchAll();
}
// List tests
$tests_stmt = $pdo->prepare("
    SELECT
        t.*,
        c.class_name,
        s.subject_name,
        (SELECT COUNT(*) FROM cbt_questions q WHERE q.test_id = t.id) AS question_count,
        (SELECT COUNT(*) FROM cbt_attempts a WHERE a.test_id = t.id AND a.status = 'submitted') AS submitted_count
    FROM cbt_tests t
    JOIN classes c ON t.class_id = c.id
    JOIN subjects s ON t.subject_id = s.id
    WHERE t.teacher_id = ? AND t.school_id = ?
    ORDER BY t.created_at DESC
");
$tests_stmt->execute([$teacher_id, $current_school_id]);
$tests = $tests_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT Tests - Teacher</title>
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
                    <p class="school-tagline">CBT Tests</p>
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
                <h2>CBT Tests</h2>
                <p>Create tests, add questions, and review results.</p>
            </div>
            <div class="header-actions">
                <a href="cbt_tests.php?action=create" class="btn-modern-primary">
                    <i class="fas fa-plus-circle"></i>
                    <span>New Test</span>
                </a>
                <a href="cbt_results.php" class="btn-modern-outline">
                    <i class="fas fa-chart-bar"></i>
                    <span>Results</span>
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin-bottom: 1rem;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 1rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <div id="cbt-offline-status" style="display:none;"></div>

        <?php if ($action === 'create' || $action === 'edit'): ?>
            <div class="form-page-modern">
                <div class="form-card-modern">
                    <div class="form-header-modern">
                        <div class="form-title-modern">
                            <i class="fas fa-file-alt"></i>
                            <?php echo $action === 'create' ? 'Create Test' : 'Edit Test'; ?>
                        </div>
                    </div>
                    <div class="form-body-modern">
                        <form method="POST">
                            <input type="hidden" name="save_test" value="1">
                            <div class="form-row-modern">
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Title</label>
                                    <input type="text" class="form-input-modern" name="title" value="<?php echo htmlspecialchars($test['title'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Class</label>
                                    <select class="form-input-modern" name="class_id" required>
                                        <option value="">Select</option>
                                        <?php foreach ($classes as $c): ?>
                                            <option value="<?php echo $c['id']; ?>" <?php echo ($test['class_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($c['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row-modern">
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Subject</label>
                                    <select class="form-input-modern" name="subject_id" required>
                                        <option value="">Select</option>
                                        <?php foreach ($subjects as $s): ?>
                                            <option value="<?php echo $s['id']; ?>" <?php echo ($test['subject_id'] ?? '') == $s['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($s['subject_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Duration (minutes)</label>
                                    <input type="number" class="form-input-modern" name="duration_minutes" min="5" value="<?php echo htmlspecialchars($test['duration_minutes'] ?? 30); ?>">
                                </div>
                            </div>

                            <div class="form-row-modern">
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Starts At</label>
                                    <input type="datetime-local" class="form-input-modern" name="starts_at" value="<?php echo !empty($test['starts_at']) ? date('Y-m-d\TH:i', strtotime($test['starts_at'])) : ''; ?>">
                                </div>
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Ends At</label>
                                    <input type="datetime-local" class="form-input-modern" name="ends_at" value="<?php echo !empty($test['ends_at']) ? date('Y-m-d\TH:i', strtotime($test['ends_at'])) : ''; ?>">
                                </div>
                            </div>

                            <div class="form-row-modern">
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Status</label>
                                    <select class="form-input-modern" name="status">
                                        <option value="draft" <?php echo ($test['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="published" <?php echo ($test['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
                                        <option value="closed" <?php echo ($test['status'] ?? '') === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                    <small class="text-muted">Only tests with status <strong>Published</strong> are shown to students.</small>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a href="cbt_tests.php" class="btn-modern-outline">
                                    <i class="fas fa-arrow-left"></i>
                                    <span>Back</span>
                                </a>
                                <button type="submit" class="btn-modern-primary">
                                    <i class="fas fa-save"></i>
                                    <span>Save Test</span>
                                </button>
                            </div>
                        </form>

                        <?php if ($action === 'edit' && !empty($test['id'])): ?>
                            <div class="d-flex justify-content-end align-items-center mt-3" style="gap: 0.75rem;">
                                <?php if (($test['status'] ?? '') !== 'published'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="set_test_status" value="1">
                                        <input type="hidden" name="target_test_id" value="<?php echo (int)$test['id']; ?>">
                                        <input type="hidden" name="target_status" value="published">
                                        <input type="hidden" name="redirect_mode" value="edit">
                                        <button type="submit" class="btn-modern-primary">
                                            <i class="fas fa-bullhorn"></i>
                                            <span>Publish Now</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="set_test_status" value="1">
                                        <input type="hidden" name="target_test_id" value="<?php echo (int)$test['id']; ?>">
                                        <input type="hidden" name="target_status" value="draft">
                                        <input type="hidden" name="redirect_mode" value="edit">
                                        <button type="submit" class="btn-modern-outline">
                                            <i class="fas fa-eye-slash"></i>
                                            <span>Move to Draft</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($action === 'edit' && $test_id > 0): ?>
                    <div class="form-card-modern">
                        <div class="form-header-modern">
                            <div class="form-title-modern">
                                <i class="fas fa-question-circle"></i>
                                Add Question
                            </div>
                        </div>
                        <div class="form-body-modern">
                            <form method="POST">
                                <input type="hidden" name="add_question" value="1">
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Question</label>
                                    <textarea class="form-input-modern" name="question_text" rows="3" required></textarea>
                                </div>
                                <div class="form-row-modern">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Option A</label>
                                        <input type="text" class="form-input-modern" name="option_a" required>
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Option B</label>
                                        <input type="text" class="form-input-modern" name="option_b" required>
                                    </div>
                                </div>
                                <div class="form-row-modern">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Option C</label>
                                        <input type="text" class="form-input-modern" name="option_c" required>
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">Option D</label>
                                        <input type="text" class="form-input-modern" name="option_d" required>
                                    </div>
                                </div>
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Correct Option</label>
                                    <select class="form-input-modern" name="correct_option">
                                        <option value="A">A</option>
                                        <option value="B">B</option>
                                        <option value="C">C</option>
                                        <option value="D">D</option>
                                    </select>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mt-4">
                                    <div></div>
                                    <button type="submit" class="btn-modern-primary">
                                        <i class="fas fa-plus"></i>
                                        <span>Add Question</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="form-card-modern">
                        <div class="form-header-modern">
                            <div class="form-title-modern">
                                <i class="fas fa-database"></i>
                                Import From Question Bank
                            </div>
                        </div>
                        <div class="form-body-modern">
                            <?php if (count($bank_questions) === 0): ?>
                                <p>No matching questions in bank for this class/subject.</p>
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="import_questions" value="1">
                                    <div style="max-height: 260px; overflow: auto; border: 1px solid #e5e7eb; padding: 1rem; border-radius: 8px;">
                                        <?php foreach ($bank_questions as $bq): ?>
                                            <label style="display:block; margin-bottom: 0.75rem;">
                                                <input type="checkbox" name="question_ids[]" value="<?php echo $bq['id']; ?>">
                                                <strong><?php echo htmlspecialchars($bq['question_text']); ?></strong>
                                                <small style="color:#6b7280;">(<?php echo htmlspecialchars($bq['difficulty_level']); ?>)</small>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-4">
                                        <div></div>
                                        <button type="submit" class="btn-modern-primary">
                                            <i class="fas fa-download"></i>
                                            <span>Import Selected</span>
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="modern-card">
                        <div class="card-header-modern">
                            <h4>Questions</h4>
                        </div>
                        <div class="card-body-modern">
                            <?php if (count($questions) === 0): ?>
                                <p>No questions yet.</p>
                            <?php else: ?>
                                <ol>
                                    <?php foreach ($questions as $q): ?>
                                        <li style="margin-bottom: 1rem;">
                                            <strong><?php echo htmlspecialchars($q['question_text']); ?></strong>
                                            <div>A. <?php echo htmlspecialchars($q['option_a']); ?></div>
                                            <div>B. <?php echo htmlspecialchars($q['option_b']); ?></div>
                                            <div>C. <?php echo htmlspecialchars($q['option_c']); ?></div>
                                            <div>D. <?php echo htmlspecialchars($q['option_d']); ?></div>
                                            <div>Correct: <?php echo htmlspecialchars($q['correct_option']); ?></div>
                                            <a href="cbt_tests.php?action=edit&id=<?php echo $test_id; ?>&delete_q=<?php echo $q['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete question?')">Delete</a>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="modern-card">
                <div class="card-body-modern">
                    <table class="table-modern">
                        <thead>
                        <tr>
                            <th>Title</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Schedule</th>
                            <th>Duration</th>
                            <th>Questions</th>
                            <th>Submissions</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tests as $t): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($t['title']); ?></td>
                                <td><?php echo htmlspecialchars($t['class_name']); ?></td>
                                <td><?php echo htmlspecialchars($t['subject_name']); ?></td>
                                <td>
                                    <?php if (!empty($t['starts_at'])): ?>
                                        <?php echo date('M d, Y H:i', strtotime($t['starts_at'])); ?>
                                        <?php if (!empty($t['ends_at'])): ?>
                                            <br><small>to <?php echo date('M d, Y H:i', strtotime($t['ends_at'])); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No schedule</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo intval($t['duration_minutes']); ?> mins</td>
                                <td><?php echo (int)$t['question_count']; ?></td>
                                <td><?php echo (int)$t['submitted_count']; ?></td>
                                <td><?php echo htmlspecialchars($t['status']); ?></td>
                                <td>
                                    <a href="cbt_tests.php?action=edit&id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <?php if (($t['status'] ?? '') !== 'published'): ?>
                                        <form method="POST" style="display:inline-block; margin-left: 0.35rem;">
                                            <input type="hidden" name="set_test_status" value="1">
                                            <input type="hidden" name="target_test_id" value="<?php echo (int)$t['id']; ?>">
                                            <input type="hidden" name="target_status" value="published">
                                            <input type="hidden" name="redirect_mode" value="list">
                                            <button type="submit" class="btn btn-sm btn-success" <?php echo ((int)$t['question_count'] <= 0) ? 'disabled title="Add questions before publishing"' : ''; ?>>
                                                Publish
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline-block; margin-left: 0.35rem;">
                                            <input type="hidden" name="set_test_status" value="1">
                                            <input type="hidden" name="target_test_id" value="<?php echo (int)$t['id']; ?>">
                                            <input type="hidden" name="target_status" value="draft">
                                            <input type="hidden" name="redirect_mode" value="list">
                                            <button type="submit" class="btn btn-sm btn-warning">Move to Draft</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </main>
</div>

<?php include '../includes/floating-button.php'; ?>
<script src="../assets/js/cbt-offline-sync.js"></script>
<script>
    CBTOfflineSync.init({
        queueKey: 'cbt_teacher_offline_queue_v1',
        formSelector: 'form[method="POST"], form[method="post"]',
        statusElementId: 'cbt-offline-status',
        statusPrefix: 'Teacher CBT Sync:',
        swPath: '../cbt-sw.js'
    });
</script>
</body>
</html>
