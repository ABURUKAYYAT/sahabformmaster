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
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$status_labels = [
    'draft' => 'Draft',
    'published' => 'Published',
    'closed' => 'Closed',
];
$status_badge_classes = [
    'draft' => 'is-draft',
    'published' => 'is-published',
    'closed' => 'is-closed',
];
$format_datetime = static function (?string $value, string $format = 'd M Y, h:i A'): string {
    if (empty($value)) {
        return 'Not scheduled';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'Not scheduled';
    }

    return date($format, $timestamp);
};
$get_status_badge_class = static function (?string $status) use ($status_badge_classes): string {
    $status_key = strtolower((string)$status);
    return $status_badge_classes[$status_key] ?? 'is-draft';
};

$is_editor = in_array($action, ['create', 'edit'], true);
$total_tests = count($tests);
$published_tests = 0;
$draft_tests = 0;
$closed_tests = 0;
$scheduled_tests = 0;
$total_test_questions = 0;
$total_submissions = 0;
$current_test_submission_count = 0;

foreach ($tests as $listed_test) {
    $status_key = strtolower((string)($listed_test['status'] ?? 'draft'));
    if ($status_key === 'published') {
        $published_tests += 1;
    } elseif ($status_key === 'closed') {
        $closed_tests += 1;
    } else {
        $draft_tests += 1;
    }

    if (!empty($listed_test['starts_at']) || !empty($listed_test['ends_at'])) {
        $scheduled_tests += 1;
    }

    $total_test_questions += (int)($listed_test['question_count'] ?? 0);
    $total_submissions += (int)($listed_test['submitted_count'] ?? 0);

    if ((int)($listed_test['id'] ?? 0) === $test_id) {
        $current_test_submission_count = (int)($listed_test['submitted_count'] ?? 0);
    }
}

$current_question_count = count($questions);
$bank_question_count = count($bank_questions);
$page_title = $is_editor
    ? ($action === 'create' ? 'Create CBT Test' : 'Build CBT Test')
    : 'CBT Test Workspace';
$page_summary = $is_editor
    ? 'Configure timing, question flow, and publication status using the same structured workspace as the question bank.'
    : 'Create, schedule, publish, and monitor computer-based tests from a single teacher assessment workspace.';
?>
<?php include '../includes/teacher_cbt_tests_page.php'; ?>
