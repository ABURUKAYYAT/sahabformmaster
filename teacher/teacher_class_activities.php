<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || strtolower((string) ($_SESSION['role'] ?? '')) !== 'teacher') {
    header('Location: ../index.php');
    exit;
}

require_once '../includes/functions.php';
$current_school_id = require_school_auth();

$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

$allowed_actions = ['dashboard', 'activities', 'submissions', 'reports', 'create', 'edit', 'grade', 'delete'];
$action = $_GET['action'] ?? 'dashboard';
if (!in_array($action, $allowed_actions, true)) {
    $action = 'dashboard';
}

$activity_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create' || $action === 'edit') {
        $activity_data = [
            'title' => trim($_POST['title'] ?? ''),
            'activity_type' => trim($_POST['activity_type'] ?? ''),
            'subject_id' => (int) ($_POST['subject_id'] ?? 0),
            'class_id' => (int) ($_POST['class_id'] ?? 0),
            'description' => trim($_POST['description'] ?? ''),
            'instructions' => trim($_POST['instructions'] ?? ''),
            'due_date' => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
            'total_marks' => (float) ($_POST['total_marks'] ?? 100),
            'status' => $_POST['status'] ?? 'draft',
            'teacher_id' => $teacher_id,
        ];

        if (
            $activity_data['title'] === '' ||
            $activity_data['activity_type'] === '' ||
            $activity_data['subject_id'] <= 0 ||
            $activity_data['class_id'] <= 0 ||
            $activity_data['instructions'] === ''
        ) {
            $errors[] = 'Activity type, title, subject, class, and instructions are required.';
        }

        if ($activity_data['total_marks'] < 0) {
            $errors[] = 'Total marks cannot be negative.';
        }

        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    $sql = 'INSERT INTO class_activities (school_id, title, activity_type, subject_id, class_id, description, instructions, due_date, total_marks, status, teacher_id, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $current_school_id,
                        $activity_data['title'],
                        $activity_data['activity_type'],
                        $activity_data['subject_id'],
                        $activity_data['class_id'],
                        $activity_data['description'],
                        $activity_data['instructions'],
                        $activity_data['due_date'],
                        $activity_data['total_marks'],
                        $activity_data['status'],
                        $activity_data['teacher_id'],
                    ]);

                    header('Location: teacher_class_activities.php?action=activities&success=created');
                    exit;
                }

                if ($action === 'edit' && $activity_id > 0) {
                    $sql = 'UPDATE class_activities
                            SET title = ?, activity_type = ?, subject_id = ?, class_id = ?,
                                description = ?, instructions = ?, due_date = ?, total_marks = ?,
                                status = ?, updated_at = NOW()
                            WHERE id = ? AND teacher_id = ? AND school_id = ?';
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
                        $teacher_id,
                        $current_school_id,
                    ]);

                    header('Location: teacher_class_activities.php?action=activities&success=updated');
                    exit;
                }
            } catch (Exception $exception) {
                $errors[] = 'Unable to save the activity: ' . $exception->getMessage();
            }
        }
    } elseif ($action === 'grade' && isset($_POST['submission_id'])) {
        $submission_id = (int) $_POST['submission_id'];
        $marks = max(0, (float) ($_POST['marks'] ?? 0));
        $feedback = trim($_POST['feedback'] ?? '');

        $check_sql = 'SELECT ca.total_marks
                      FROM student_submissions ss
                      JOIN class_activities ca ON ss.activity_id = ca.id
                      WHERE ss.id = ? AND ca.teacher_id = ? AND ca.school_id = ?';
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$submission_id, $teacher_id, $current_school_id]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $max_marks = (float) $result['total_marks'];
            if ($marks > $max_marks) {
                $marks = $max_marks;
            }

            $update_sql = "UPDATE student_submissions
                           SET marks_obtained = ?, feedback = ?, status = 'graded', graded_at = NOW()
                           WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$marks, $feedback, $submission_id]);

            $redirect_activity_id = isset($_POST['activity_id']) ? (int) $_POST['activity_id'] : 0;
            header('Location: teacher_class_activities.php?action=submissions&id=' . $redirect_activity_id . '&success=graded');
            exit;
        }

        $errors[] = 'Submission not found or no longer available for grading.';
    }
}

if ($action === 'delete') {
    if ($activity_id > 0) {
        try {
            $delete_sql = 'DELETE FROM class_activities WHERE id = ? AND teacher_id = ? AND school_id = ?';
            $delete_stmt = $pdo->prepare($delete_sql);
            $delete_stmt->execute([$activity_id, $teacher_id, $current_school_id]);
            header('Location: teacher_class_activities.php?action=activities&success=deleted');
            exit;
        } catch (Exception $exception) {
            $errors[] = 'Unable to delete the activity: ' . $exception->getMessage();
        }
    } else {
        $errors[] = 'Invalid activity selected for deletion.';
    }

    $action = 'activities';
}

$class_query = 'SELECT DISTINCT c.id, c.class_name
                FROM class_teachers ct
                JOIN classes c ON ct.class_id = c.id
                WHERE ct.teacher_id = ? AND c.school_id = ?
                ORDER BY c.class_name';
$class_stmt = $pdo->prepare($class_query);
$class_stmt->execute([$teacher_id, $current_school_id]);
$assigned_classes = $class_stmt->fetchAll(PDO::FETCH_ASSOC);

$subject_query = 'SELECT DISTINCT s.id, s.subject_name
                  FROM subject_assignments sa
                  JOIN subjects s ON sa.subject_id = s.id
                  WHERE sa.teacher_id = ? AND s.school_id = ?
                  ORDER BY s.subject_name';
$subject_stmt = $pdo->prepare($subject_query);
$subject_stmt->execute([$teacher_id, $current_school_id]);
$assigned_subjects = $subject_stmt->fetchAll(PDO::FETCH_ASSOC);

$activity_type_labels = [
    'classwork' => 'Classwork',
    'assignment' => 'Assignment',
    'quiz' => 'Quiz',
    'project' => 'Project',
    'homework' => 'Homework',
];

$get_activity_meta = static function (?string $activity_type) use ($activity_type_labels): array {
    $type = strtolower((string) $activity_type);
    $meta = [
        'classwork' => [
            'label' => $activity_type_labels['classwork'],
            'icon' => 'fa-clipboard-list',
            'pill' => 'bg-sky-50 text-sky-700 border border-sky-100',
            'icon_wrap' => 'bg-sky-600/10 text-sky-700',
        ],
        'assignment' => [
            'label' => $activity_type_labels['assignment'],
            'icon' => 'fa-list-check',
            'pill' => 'bg-indigo-50 text-indigo-700 border border-indigo-100',
            'icon_wrap' => 'bg-indigo-600/10 text-indigo-700',
        ],
        'quiz' => [
            'label' => $activity_type_labels['quiz'],
            'icon' => 'fa-brain',
            'pill' => 'bg-amber-50 text-amber-700 border border-amber-100',
            'icon_wrap' => 'bg-amber-500/10 text-amber-700',
        ],
        'project' => [
            'label' => $activity_type_labels['project'],
            'icon' => 'fa-diagram-project',
            'pill' => 'bg-violet-50 text-violet-700 border border-violet-100',
            'icon_wrap' => 'bg-violet-600/10 text-violet-700',
        ],
        'homework' => [
            'label' => $activity_type_labels['homework'],
            'icon' => 'fa-house-laptop',
            'pill' => 'bg-emerald-50 text-emerald-700 border border-emerald-100',
            'icon_wrap' => 'bg-emerald-600/10 text-emerald-700',
        ],
    ];

    return $meta[$type] ?? [
        'label' => ucfirst(str_replace('_', ' ', $type ?: 'Activity')),
        'icon' => 'fa-tasks',
        'pill' => 'bg-slate-100 text-slate-700 border border-slate-200',
        'icon_wrap' => 'bg-slate-200 text-slate-700',
    ];
};

$get_status_meta = static function (?string $status): array {
    $status_key = strtolower((string) $status);
    $meta = [
        'draft' => ['label' => 'Draft', 'icon' => 'fa-pen-to-square', 'pill' => 'bg-slate-100 text-slate-700 border border-slate-200'],
        'published' => ['label' => 'Published', 'icon' => 'fa-eye', 'pill' => 'bg-emerald-100 text-emerald-700 border border-emerald-200'],
        'closed' => ['label' => 'Closed', 'icon' => 'fa-lock', 'pill' => 'bg-rose-100 text-rose-700 border border-rose-200'],
        'submitted' => ['label' => 'Submitted', 'icon' => 'fa-paper-plane', 'pill' => 'bg-sky-100 text-sky-700 border border-sky-200'],
        'graded' => ['label' => 'Graded', 'icon' => 'fa-check-circle', 'pill' => 'bg-teal-100 text-teal-700 border border-teal-200'],
        'late' => ['label' => 'Late', 'icon' => 'fa-clock', 'pill' => 'bg-amber-100 text-amber-700 border border-amber-200'],
        'pending' => ['label' => 'Pending', 'icon' => 'fa-hourglass-half', 'pill' => 'bg-orange-100 text-orange-700 border border-orange-200'],
    ];

    return $meta[$status_key] ?? ['label' => ucfirst($status_key ?: 'Unknown'), 'icon' => 'fa-circle-info', 'pill' => 'bg-slate-100 text-slate-700 border border-slate-200'];
};

$format_datetime = static function (?string $value, string $format = 'M d, Y h:i A'): string {
    if (empty($value)) {
        return 'Not set';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : 'Not set';
};

$format_number = static function ($value): string {
    if ($value === null || $value === '') {
        return '0';
    }

    $number = (float) $value;
    if (fmod($number, 1.0) === 0.0) {
        return number_format($number, 0);
    }

    return number_format($number, 2);
};

$is_overdue = static function (?string $value): bool {
    if (empty($value)) {
        return false;
    }

    $timestamp = strtotime($value);
    return $timestamp !== false && $timestamp < time();
};

$success_messages = [
    'created' => 'Activity created successfully.',
    'updated' => 'Activity updated successfully.',
    'deleted' => 'Activity deleted successfully.',
    'graded' => 'Submission graded successfully.',
];
$success_message = $success_messages[$_GET['success'] ?? ''] ?? '';

$hero_stmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_activities,
        SUM(CASE WHEN status = "published" THEN 1 ELSE 0 END) AS published_activities,
        SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) AS draft_activities,
        SUM(CASE WHEN due_date IS NOT NULL AND due_date > NOW() AND due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS upcoming_due
     FROM class_activities
     WHERE teacher_id = ? AND school_id = ?'
);
$hero_stmt->execute([$teacher_id, $current_school_id]);
$hero_row = $hero_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$pending_review_stmt = $pdo->prepare(
    "SELECT COUNT(*) AS pending_review
     FROM student_submissions ss
     INNER JOIN class_activities ca ON ss.activity_id = ca.id
     WHERE ca.teacher_id = ? AND ca.school_id = ? AND ss.status IN ('submitted', 'late')"
);
$pending_review_stmt->execute([$teacher_id, $current_school_id]);
$pending_review = (int) ($pending_review_stmt->fetchColumn() ?: 0);

$graded_total_stmt = $pdo->prepare(
    "SELECT COUNT(*) AS graded_count
     FROM student_submissions ss
     INNER JOIN class_activities ca ON ss.activity_id = ca.id
     WHERE ca.teacher_id = ? AND ca.school_id = ? AND ss.status = 'graded'"
);
$graded_total_stmt->execute([$teacher_id, $current_school_id]);
$graded_total = (int) ($graded_total_stmt->fetchColumn() ?: 0);

$hero_totals = [
    'total_activities' => (int) ($hero_row['total_activities'] ?? 0),
    'published_activities' => (int) ($hero_row['published_activities'] ?? 0),
    'draft_activities' => (int) ($hero_row['draft_activities'] ?? 0),
    'upcoming_due' => (int) ($hero_row['upcoming_due'] ?? 0),
    'pending_review' => $pending_review,
    'graded_total' => $graded_total,
];

$dashboard_recent_activities = [];
$dashboard_recent_submissions = [];
$activity_record = null;
$selected_activity = null;
$selected_activity_submissions = [];
$grade_submission = null;
$activities = [];
$all_submissions = [];
$reports = [];
$report_summary = [
    'activity_count' => 0,
    'average_score' => '0',
    'average_submission_rate' => 0,
];
$report_type_chart = ['labels' => [], 'values' => []];
$report_status_chart = ['labels' => [], 'values' => []];

if ($action === 'dashboard') {
    $recent_stmt = $pdo->prepare(
        'SELECT ca.*, s.subject_name, c.class_name
         FROM class_activities ca
         JOIN subjects s ON ca.subject_id = s.id
         JOIN classes c ON ca.class_id = c.id
         WHERE ca.teacher_id = ? AND ca.school_id = ?
         ORDER BY ca.created_at DESC
         LIMIT 5'
    );
    $recent_stmt->execute([$teacher_id, $current_school_id]);
    $dashboard_recent_activities = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

    $recent_submissions_stmt = $pdo->prepare(
        'SELECT ss.*, ca.title AS activity_title, ca.activity_type, ca.total_marks,
                st.full_name AS student_name, st.admission_no, c.class_name
         FROM student_submissions ss
         JOIN class_activities ca ON ss.activity_id = ca.id
         JOIN students st ON ss.student_id = st.id
         LEFT JOIN classes c ON st.class_id = c.id
         WHERE ca.teacher_id = ? AND ca.school_id = ? AND st.school_id = ?
         ORDER BY ss.submitted_at DESC
         LIMIT 6'
    );
    $recent_submissions_stmt->execute([$teacher_id, $current_school_id, $current_school_id]);
    $dashboard_recent_submissions = $recent_submissions_stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($action === 'activities') {
    $activities_stmt = $pdo->prepare(
        'SELECT ca.*, s.subject_name, c.class_name,
                (SELECT COUNT(*) FROM student_submissions ss WHERE ss.activity_id = ca.id) AS total_submissions,
                (SELECT COUNT(*) FROM student_submissions ss WHERE ss.activity_id = ca.id AND ss.status = "graded") AS graded_submissions
         FROM class_activities ca
         JOIN subjects s ON ca.subject_id = s.id
         JOIN classes c ON ca.class_id = c.id
         WHERE ca.teacher_id = ? AND ca.school_id = ?
         ORDER BY ca.created_at DESC'
    );
    $activities_stmt->execute([$teacher_id, $current_school_id]);
    $activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($action === 'create' || $action === 'edit') {
    if ($action === 'edit' && $activity_id > 0) {
        $activity_stmt = $pdo->prepare('SELECT * FROM class_activities WHERE id = ? AND teacher_id = ? AND school_id = ?');
        $activity_stmt->execute([$activity_id, $teacher_id, $current_school_id]);
        $activity_record = $activity_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$activity_record) {
            $errors[] = 'Activity not found or access denied.';
        }
    }
} elseif ($action === 'grade') {
    $submission_id = isset($_GET['submission_id']) ? (int) $_GET['submission_id'] : 0;

    if ($activity_id > 0 && $submission_id > 0) {
        $submission_stmt = $pdo->prepare(
            'SELECT ss.*, st.full_name, st.admission_no, ca.title AS activity_title,
                    ca.total_marks, ca.instructions, ca.activity_type, ca.status AS activity_status
             FROM student_submissions ss
             JOIN students st ON ss.student_id = st.id
             JOIN class_activities ca ON ss.activity_id = ca.id
             WHERE ss.id = ? AND ca.id = ? AND ca.teacher_id = ? AND ca.school_id = ? AND st.school_id = ?'
        );
        $submission_stmt->execute([$submission_id, $activity_id, $teacher_id, $current_school_id, $current_school_id]);
        $grade_submission = $submission_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$grade_submission) {
        $errors[] = 'Submission not found or access denied.';
    }
} elseif ($action === 'submissions') {
    if ($activity_id > 0) {
        $selected_activity_stmt = $pdo->prepare(
            'SELECT ca.*, s.subject_name, c.class_name
             FROM class_activities ca
             JOIN subjects s ON ca.subject_id = s.id
             JOIN classes c ON ca.class_id = c.id
             WHERE ca.id = ? AND ca.teacher_id = ? AND ca.school_id = ?'
        );
        $selected_activity_stmt->execute([$activity_id, $teacher_id, $current_school_id]);
        $selected_activity = $selected_activity_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($selected_activity) {
            $selected_activity_submissions_stmt = $pdo->prepare(
                'SELECT ss.*, st.full_name, st.admission_no
                 FROM student_submissions ss
                 JOIN students st ON ss.student_id = st.id
                 WHERE ss.activity_id = ? AND st.school_id = ?
                 ORDER BY ss.submitted_at DESC'
            );
            $selected_activity_submissions_stmt->execute([$activity_id, $current_school_id]);
            $selected_activity_submissions = $selected_activity_submissions_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $errors[] = 'Activity not found or access denied.';
        }
    } else {
        $all_submissions_stmt = $pdo->prepare(
            'SELECT ss.*, ca.title AS activity_title, ca.activity_type, ca.total_marks, ca.id AS activity_id,
                    st.full_name AS student_name, st.admission_no, c.class_name
             FROM student_submissions ss
             JOIN class_activities ca ON ss.activity_id = ca.id
             JOIN students st ON ss.student_id = st.id
             JOIN classes c ON st.class_id = c.id
             WHERE ca.teacher_id = ? AND ca.school_id = ? AND st.school_id = ?
             ORDER BY ss.submitted_at DESC
             LIMIT 50'
        );
        $all_submissions_stmt->execute([$teacher_id, $current_school_id, $current_school_id]);
        $all_submissions = $all_submissions_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($action === 'reports') {
    $report_stmt = $pdo->prepare(
        'SELECT ca.id, ca.title, ca.activity_type, s.subject_name, c.class_name,
                (SELECT COUNT(*) FROM students st WHERE st.class_id = ca.class_id AND st.school_id = ca.school_id) AS total_students,
                COUNT(ss.id) AS submitted_count,
                COALESCE(SUM(CASE WHEN ss.status = "graded" THEN 1 ELSE 0 END), 0) AS graded_count,
                AVG(CASE WHEN ss.status = "graded" THEN ss.marks_obtained END) AS avg_score
         FROM class_activities ca
         JOIN classes c ON ca.class_id = c.id
         JOIN subjects s ON ca.subject_id = s.id
         LEFT JOIN student_submissions ss ON ca.id = ss.activity_id
         WHERE ca.teacher_id = ? AND ca.school_id = ?
         GROUP BY ca.id, ca.title, ca.activity_type, s.subject_name, c.class_name
         ORDER BY ca.created_at DESC'
    );
    $report_stmt->execute([$teacher_id, $current_school_id]);
    $reports = $report_stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_submission_rate = 0.0;
    $total_avg_score = 0.0;
    $avg_score_count = 0;
    foreach ($reports as &$report) {
        $total_students = (int) ($report['total_students'] ?? 0);
        $submitted_count = (int) ($report['submitted_count'] ?? 0);
        $report['submission_rate'] = $total_students > 0 ? (int) round(($submitted_count / $total_students) * 100) : 0;
        $total_submission_rate += $report['submission_rate'];

        if ($report['avg_score'] !== null) {
            $total_avg_score += (float) $report['avg_score'];
            $avg_score_count += 1;
        }
    }
    unset($report);

    $report_summary = [
        'activity_count' => count($reports),
        'average_score' => $avg_score_count > 0 ? $format_number($total_avg_score / $avg_score_count) : '0',
        'average_submission_rate' => count($reports) > 0 ? (int) round($total_submission_rate / count($reports)) : 0,
    ];

    $ordered_types = array_keys($activity_type_labels);
    $type_totals = array_fill_keys($ordered_types, ['sum' => 0.0, 'count' => 0]);
    foreach ($reports as $report) {
        $type_key = strtolower((string) ($report['activity_type'] ?? ''));
        if (!array_key_exists($type_key, $type_totals)) {
            continue;
        }
        if ($report['avg_score'] !== null) {
            $type_totals[$type_key]['sum'] += (float) $report['avg_score'];
            $type_totals[$type_key]['count'] += 1;
        }
    }

    foreach ($ordered_types as $type_key) {
        $report_type_chart['labels'][] = $activity_type_labels[$type_key];
        $report_type_chart['values'][] = $type_totals[$type_key]['count'] > 0
            ? round($type_totals[$type_key]['sum'] / $type_totals[$type_key]['count'], 1)
            : 0;
    }

    $status_counts = [
        'graded' => 0,
        'submitted' => 0,
        'late' => 0,
        'pending' => 0,
    ];
    $status_chart_stmt = $pdo->prepare(
        'SELECT ss.status, COUNT(*) AS total
         FROM student_submissions ss
         JOIN class_activities ca ON ss.activity_id = ca.id
         WHERE ca.teacher_id = ? AND ca.school_id = ?
         GROUP BY ss.status'
    );
    $status_chart_stmt->execute([$teacher_id, $current_school_id]);
    foreach ($status_chart_stmt->fetchAll(PDO::FETCH_ASSOC) as $status_row) {
        $status_key = strtolower((string) ($status_row['status'] ?? ''));
        if (array_key_exists($status_key, $status_counts)) {
            $status_counts[$status_key] = (int) $status_row['total'];
        }
    }

    foreach ($status_counts as $status_key => $count) {
        $status_meta = $get_status_meta($status_key);
        $report_status_chart['labels'][] = $status_meta['label'];
        $report_status_chart['values'][] = $count;
    }
}

include '../includes/teacher_class_activities_page.php';
