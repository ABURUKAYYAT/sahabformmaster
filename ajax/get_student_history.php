<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$allowed_roles = ['teacher', 'principal'];
if (!isset($_SESSION['user_id']) || !in_array(strtolower((string) ($_SESSION['role'] ?? '')), $allowed_roles, true)) {
    http_response_code(403);
    echo '<div class="history-empty"><p class="font-semibold text-ink-900">Access denied</p><p class="mt-2 text-sm">You are not authorized to view attendance history.</p></div>';
    exit;
}

$current_school_id = require_school_auth();
$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$current_role = strtolower((string) ($_SESSION['role'] ?? 'teacher'));
$student_id = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;

if ($student_id <= 0) {
    echo '<div class="history-empty"><p class="font-semibold text-ink-900">Student not found</p><p class="mt-2 text-sm">A valid student was not supplied.</p></div>';
    exit;
}

$student_stmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.admission_no, c.class_name
    FROM students s
    JOIN classes c ON c.id = s.class_id AND c.school_id = :school_id
    WHERE s.id = :student_id
      AND s.school_id = :school_id
    LIMIT 1
");
$student_stmt->execute([
    ':student_id' => $student_id,
    ':school_id' => $current_school_id,
]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo '<div class="history-empty"><p class="font-semibold text-ink-900">Student unavailable</p><p class="mt-2 text-sm">This learner could not be found in the current school.</p></div>';
    exit;
}

if ($current_role !== 'principal') {
    $access_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM class_teachers ct
        JOIN students s ON s.class_id = ct.class_id
        WHERE ct.teacher_id = :teacher_id
          AND s.id = :student_id
          AND s.school_id = :school_id
    ");
    $access_stmt->execute([
        ':teacher_id' => $teacher_id,
        ':student_id' => $student_id,
        ':school_id' => $current_school_id,
    ]);

    if ((int) $access_stmt->fetchColumn() === 0) {
        echo '<div class="history-empty"><p class="font-semibold text-ink-900">History unavailable</p><p class="mt-2 text-sm">You can only view attendance history for learners in your assigned classes.</p></div>';
        exit;
    }
}

$records_stmt = $pdo->prepare("
    SELECT a.date, a.status, a.notes, a.recorded_at, u.full_name AS recorded_by
    FROM attendance a
    LEFT JOIN users u ON u.id = a.recorded_by
    WHERE a.student_id = :student_id
      AND a.school_id = :school_id
    ORDER BY a.date DESC, a.recorded_at DESC
    LIMIT 20
");
$records_stmt->execute([
    ':student_id' => $student_id,
    ':school_id' => $current_school_id,
]);
$records = $records_stmt->fetchAll(PDO::FETCH_ASSOC);

$status_meta = [
    'present' => ['label' => 'Present', 'icon' => 'fa-check', 'class' => 'bg-emerald-100 text-emerald-700'],
    'absent' => ['label' => 'Absent', 'icon' => 'fa-xmark', 'class' => 'bg-rose-100 text-rose-700'],
    'late' => ['label' => 'Late', 'icon' => 'fa-clock', 'class' => 'bg-amber-100 text-amber-700'],
    'leave' => ['label' => 'Leave', 'icon' => 'fa-envelope', 'class' => 'bg-fuchsia-100 text-fuchsia-700'],
];
?>
<div class="space-y-5">
    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Student Overview</p>
                <h3 class="mt-2 text-xl font-semibold text-ink-900"><?php echo htmlspecialchars($student['full_name']); ?></h3>
                <p class="mt-1 text-sm text-slate-500"><?php echo htmlspecialchars($student['class_name']); ?></p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?php echo htmlspecialchars($student['admission_no'] ?: 'No admission number'); ?></span>
        </div>
    </div>

    <?php if (empty($records)): ?>
        <div class="history-empty">
            <p class="font-semibold text-ink-900">No attendance records yet</p>
            <p class="mt-2 text-sm">Once attendance is submitted for this learner, the most recent entries will appear here.</p>
        </div>
    <?php else: ?>
        <div class="history-list">
            <?php foreach ($records as $record): ?>
                <?php $status = $record['status'] ?? 'absent'; ?>
                <?php $meta = $status_meta[$status] ?? $status_meta['absent']; ?>
                <article class="history-row">
                    <div class="history-meta">
                        <div>
                            <p class="text-sm font-semibold text-ink-900"><?php echo date('l, j M Y', strtotime($record['date'])); ?></p>
                            <p class="mt-1 text-xs text-slate-500">Recorded <?php echo htmlspecialchars($record['recorded_at'] ? date('d M Y, h:i A', strtotime($record['recorded_at'])) : 'without timestamp'); ?></p>
                        </div>
                        <span class="status-pill <?php echo htmlspecialchars($meta['class']); ?>">
                            <i class="fas <?php echo htmlspecialchars($meta['icon']); ?>"></i>
                            <span><?php echo htmlspecialchars($meta['label']); ?></span>
                        </span>
                    </div>
                    <div class="grid gap-3 md:grid-cols-[1fr_220px]">
                        <div class="rounded-2xl border border-ink-900/5 bg-white p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Remarks</p>
                            <p class="mt-2 text-sm text-slate-600"><?php echo htmlspecialchars($record['notes'] !== '' && $record['notes'] !== null ? $record['notes'] : 'No remarks recorded for this entry.'); ?></p>
                        </div>
                        <div class="rounded-2xl border border-ink-900/5 bg-white p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Recorded By</p>
                            <p class="mt-2 text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($record['recorded_by'] ?? 'System'); ?></p>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
