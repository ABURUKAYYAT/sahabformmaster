<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$uid = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;
$fallback_student_id = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
$evaluation_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ((!$uid && !$admission_no && $fallback_student_id <= 0) || $evaluation_id <= 0) {
    header('Location: my-evaluations.php');
    exit;
}

$current_school_id = get_current_school_id();

function print_term_label(string $raw): string
{
    $value = strtolower(trim($raw));
    $map = [
        '1' => 'Term 1', '1st' => 'Term 1', '1st term' => 'Term 1', 'first term' => 'Term 1',
        '2' => 'Term 2', '2nd' => 'Term 2', '2nd term' => 'Term 2', 'second term' => 'Term 2',
        '3' => 'Term 3', '3rd' => 'Term 3', '3rd term' => 'Term 3', 'third term' => 'Term 3',
    ];

    return $map[$value] ?? (trim($raw) !== '' ? ucfirst(trim($raw)) : 'Term N/A');
}

function print_rating_label(string $value): string
{
    $labels = [
        'excellent' => 'Excellent',
        'very-good' => 'Very Good',
        'good' => 'Good',
        'needs-improvement' => 'Needs Improvement',
    ];

    $key = strtolower(str_replace('_', '-', trim($value)));
    return $labels[$key] ?? ucfirst(str_replace('-', ' ', $key));
}

$student = null;
if ($admission_no) {
    $stmt = $pdo->prepare('SELECT id, full_name, admission_no FROM students WHERE admission_no = :admission_no AND school_id = :school_id LIMIT 1');
    $stmt->execute(['admission_no' => $admission_no, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$student && $uid) {
    $stmt = $pdo->prepare('SELECT id, full_name, admission_no FROM students WHERE (user_id = :uid OR id = :uid) AND school_id = :school_id LIMIT 1');
    $stmt->execute(['uid' => $uid, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$student && $fallback_student_id > 0) {
    $stmt = $pdo->prepare('SELECT id, full_name, admission_no FROM students WHERE id = :id AND school_id = :school_id LIMIT 1');
    $stmt->execute(['id' => $fallback_student_id, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$student) {
    header('Location: my-evaluations.php');
    exit;
}

$student_id = (int) ($student['id'] ?? 0);

$stmt = $pdo->prepare('SELECT e.*, COALESCE(c.class_name, "N/A") AS class_name, COALESCE(u.full_name, "Teacher") AS teacher_name FROM evaluations e LEFT JOIN classes c ON e.class_id = c.id AND c.school_id = :class_school_id LEFT JOIN users u ON e.teacher_id = u.id WHERE e.id = :evaluation_id AND e.student_id = :student_id AND (e.school_id = :school_id OR e.school_id IS NULL) LIMIT 1');
$stmt->execute([
    'evaluation_id' => $evaluation_id,
    'student_id' => $student_id,
    'school_id' => $current_school_id,
    'class_school_id' => $current_school_id,
]);
$evaluation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$evaluation) {
    header('Location: my-evaluations.php');
    exit;
}

$term_label = print_term_label((string) ($evaluation['term'] ?? ''));
$year_label = trim((string) ($evaluation['academic_year'] ?? ''));
$updated_label = $evaluation['updated_at'] ? date('F d, Y', strtotime((string) $evaluation['updated_at'])) : date('F d, Y', strtotime((string) ($evaluation['created_at'] ?? 'now')));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Print | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; font-family: 'Manrope', sans-serif; background: #f1f5f9; color: #0f172a; }
        .print-wrap { max-width: 920px; margin: 2rem auto; padding: 0 1rem; }
        .print-shell { border: 1px solid rgba(15, 31, 45, 0.12); border-radius: 1.5rem; background: #fff; box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12); overflow: hidden; }
        .print-head { padding: 1.5rem; background: linear-gradient(135deg, #0f766e 0%, #0f172a 100%); color: #fff; }
        .print-head h1 { margin: 0.45rem 0 0; font-family: 'Fraunces', serif; font-size: 2rem; }
        .print-head p { margin: 0.25rem 0 0; color: rgba(255, 255, 255, 0.85); }
        .meta-grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 1rem; }
        .meta-card { border: 1px solid rgba(255, 255, 255, 0.24); border-radius: 1rem; padding: 0.75rem; background: rgba(255, 255, 255, 0.08); }
        .meta-card small { text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.64rem; color: rgba(255, 255, 255, 0.75); }
        .meta-card strong { display: block; margin-top: 0.2rem; font-size: 0.88rem; }
        .print-body { padding: 1.35rem; }
        .info-grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .info-item { border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 0.95rem; background: #f8fafc; padding: 0.75rem 0.85rem; }
        .info-item small { display: block; font-size: 0.68rem; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; }
        .info-item strong { display: block; margin-top: 0.22rem; font-size: 0.9rem; color: #0f172a; }
        .ratings-grid { display: grid; gap: 0.7rem; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 1rem; }
        .rating-item { border: 1px solid rgba(15, 23, 42, 0.1); border-radius: 0.95rem; background: #fff; padding: 0.75rem 0.85rem; display: flex; align-items: center; justify-content: space-between; gap: 0.7rem; }
        .rating-item span { font-size: 0.8rem; font-weight: 700; color: #334155; }
        .pill { border-radius: 9999px; border: 1px solid transparent; font-size: 0.72rem; font-weight: 700; padding: 0.32rem 0.68rem; }
        .pill.excellent { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
        .pill.very-good { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
        .pill.good { background: #fffbeb; border-color: #fde68a; color: #b45309; }
        .pill.needs-improvement { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
        .comment-box { margin-top: 1rem; border: 1px solid rgba(15, 23, 42, 0.1); border-radius: 0.95rem; background: #f8fafc; padding: 0.9rem; }
        .comment-box p { margin: 0.35rem 0 0; font-size: 0.9rem; line-height: 1.5; color: #334155; white-space: pre-wrap; }
        .actions { margin: 1rem auto 0; max-width: 920px; padding: 0 1rem; display: flex; gap: 0.55rem; justify-content: flex-end; }
        .btn { border: 1px solid rgba(15, 23, 42, 0.15); border-radius: 9999px; background: #fff; color: #0f172a; padding: 0.48rem 0.95rem; font-size: 0.8rem; font-weight: 700; cursor: pointer; text-decoration: none; }
        .btn.primary { background: #0f766e; border-color: #0f766e; color: #fff; }
        @media (max-width: 760px) { .meta-grid { grid-template-columns: 1fr; } .info-grid, .ratings-grid { grid-template-columns: 1fr; } .print-head h1 { font-size: 1.7rem; } }
        @media print {
            body { background: #fff; }
            .actions { display: none !important; }
            .print-wrap { margin: 0; max-width: none; padding: 0; }
            .print-shell { border: 0; border-radius: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
<div class="actions no-print">
    <a href="my-evaluations.php" class="btn">Back</a>
    <button type="button" class="btn primary" onclick="window.print();">Print</button>
</div>

<div class="print-wrap">
    <article class="print-shell">
        <header class="print-head">
            <p><?php echo htmlspecialchars(get_school_display_name()); ?></p>
            <h1>Student Evaluation Report</h1>
            <p>Prepared for <?php echo htmlspecialchars((string) ($student['full_name'] ?? 'Student')); ?></p>
            <div class="meta-grid">
                <div class="meta-card">
                    <small>Term</small>
                    <strong><?php echo htmlspecialchars($term_label); ?></strong>
                </div>
                <div class="meta-card">
                    <small>Academic Year</small>
                    <strong><?php echo htmlspecialchars($year_label !== '' ? $year_label : 'Year not set'); ?></strong>
                </div>
                <div class="meta-card">
                    <small>Last Updated</small>
                    <strong><?php echo htmlspecialchars($updated_label); ?></strong>
                </div>
            </div>
        </header>

        <div class="print-body">
            <section class="info-grid">
                <div class="info-item"><small>Student Name</small><strong><?php echo htmlspecialchars((string) ($student['full_name'] ?? 'Student')); ?></strong></div>
                <div class="info-item"><small>Admission Number</small><strong><?php echo htmlspecialchars((string) ($student['admission_no'] ?? 'N/A')); ?></strong></div>
                <div class="info-item"><small>Class</small><strong><?php echo htmlspecialchars((string) ($evaluation['class_name'] ?? 'N/A')); ?></strong></div>
                <div class="info-item"><small>Teacher</small><strong><?php echo htmlspecialchars((string) ($evaluation['teacher_name'] ?? 'Teacher')); ?></strong></div>
            </section>

            <section class="ratings-grid">
                <?php
                $ratings = [
                    'Academic' => (string) ($evaluation['academic'] ?? 'good'),
                    'Non-Academic' => (string) ($evaluation['non_academic'] ?? 'good'),
                    'Cognitive' => (string) ($evaluation['cognitive'] ?? 'good'),
                    'Psychomotor' => (string) ($evaluation['psychomotor'] ?? 'good'),
                    'Affective' => (string) ($evaluation['affective'] ?? 'good'),
                ];
                ?>
                <?php foreach ($ratings as $label => $value): ?>
                    <?php $key = strtolower(str_replace('_', '-', trim($value))); ?>
                    <div class="rating-item">
                        <span><?php echo htmlspecialchars($label); ?></span>
                        <span class="pill <?php echo htmlspecialchars(in_array($key, ['excellent', 'very-good', 'good', 'needs-improvement'], true) ? $key : 'good'); ?>">
                            <?php echo htmlspecialchars(print_rating_label($value)); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="comment-box">
                <small>Teacher Comments</small>
                <p><?php echo htmlspecialchars(trim((string) ($evaluation['comments'] ?? '')) !== '' ? (string) $evaluation['comments'] : 'No comments were provided for this evaluation.'); ?></p>
            </section>
        </div>
    </article>
</div>
</body>
</html>
