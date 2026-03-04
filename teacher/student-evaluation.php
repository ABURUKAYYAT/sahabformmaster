<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth-check.php';

$current_school_id = require_school_auth();

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: ../index.php');
    exit;
}

$teacher_id = (int)($_SESSION['user_id'] ?? 0);
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

$rating_options = [
    'excellent' => 'Excellent',
    'very-good' => 'Very Good',
    'good' => 'Good',
    'needs-improvement' => 'Needs Improvement',
];

$term_options = [
    '1' => 'Term 1',
    '2' => 'Term 2',
    '3' => 'Term 3',
];

$build_redirect_url = static function (array $params = []): string {
    $query = http_build_query($params);
    return 'student-evaluation.php' . ($query !== '' ? '?' . $query : '');
};

$build_pagination_window = static function (int $current_page, int $total_pages): array {
    if ($total_pages <= 1) {
        return [1];
    }

    $pages = [1];
    $start = max(2, $current_page - 1);
    $end = min($total_pages - 1, $current_page + 1);

    if ($start > 2) {
        $pages[] = '...';
    }

    for ($page_number = $start; $page_number <= $end; $page_number += 1) {
        $pages[] = $page_number;
    }

    if ($end < $total_pages - 1) {
        $pages[] = '...';
    }

    if ($total_pages > 1) {
        $pages[] = $total_pages;
    }

    return $pages;
};

$students_query = "
    SELECT DISTINCT
        s.id,
        s.full_name,
        s.class_id,
        s.admission_no,
        c.class_name
    FROM students s
    JOIN classes c
        ON c.id = s.class_id
       AND c.school_id = s.school_id
    WHERE s.school_id = ?
      AND (
            EXISTS (
                SELECT 1
                FROM subject_assignments sa
                WHERE sa.class_id = s.class_id
                  AND sa.teacher_id = ?
            )
         OR EXISTS (
                SELECT 1
                FROM class_teachers ct
                WHERE ct.class_id = s.class_id
                  AND ct.teacher_id = ?
            )
      )
    ORDER BY c.class_name, s.full_name
";

$students_stmt = $pdo->prepare($students_query);
$students_stmt->execute([$current_school_id, $teacher_id, $teacher_id]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

$students_by_id = [];
$class_options = [];
foreach ($students as $student) {
    $student_id = (int)$student['id'];
    $class_id = (int)$student['class_id'];
    $students_by_id[$student_id] = $student;
    $class_options[$class_id] = $student['class_name'];
}

asort($class_options, SORT_NATURAL | SORT_FLAG_CASE);

$redirect_query = $_GET;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect_url = $build_redirect_url($redirect_query);
    $post_errors = [];

    if (isset($_POST['add_evaluation'])) {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $term = trim((string)($_POST['term'] ?? ''));
        $year = trim((string)($_POST['year'] ?? ''));
        $comments = trim((string)($_POST['comments'] ?? ''));

        $academic = trim((string)($_POST['academic'] ?? ''));
        $non_academic = trim((string)($_POST['non_academic'] ?? ''));
        $cognitive = trim((string)($_POST['cognitive'] ?? ''));
        $psychomotor = trim((string)($_POST['psychomotor'] ?? ''));
        $affective = trim((string)($_POST['affective'] ?? ''));

        if (!isset($students_by_id[$student_id])) {
            $post_errors[] = 'Select a student from one of your assigned classes.';
        }

        if (!isset($term_options[$term])) {
            $post_errors[] = 'Select a valid term.';
        }

        if (!preg_match('/^\d{4}$/', $year)) {
            $post_errors[] = 'Enter a valid four-digit academic year.';
        }

        foreach ([$academic, $non_academic, $cognitive, $psychomotor, $affective] as $rating_value) {
            if (!isset($rating_options[$rating_value])) {
                $post_errors[] = 'All rating fields must use the available evaluation options.';
                break;
            }
        }

        if (empty($post_errors)) {
            $resolved_class_id = (int)$students_by_id[$student_id]['class_id'];

            $insert_stmt = $pdo->prepare("
                INSERT INTO evaluations (
                    school_id,
                    student_id,
                    class_id,
                    term,
                    academic_year,
                    academic,
                    non_academic,
                    cognitive,
                    psychomotor,
                    affective,
                    comments,
                    teacher_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insert_stmt->execute([
                $current_school_id,
                $student_id,
                $resolved_class_id,
                $term,
                $year,
                $academic,
                $non_academic,
                $cognitive,
                $psychomotor,
                $affective,
                $comments,
                $teacher_id,
            ]);

            $evaluation_id = (int)$pdo->lastInsertId();
            log_teacher_action(
                'create_evaluation',
                'evaluation',
                $evaluation_id,
                'Created evaluation for student ID ' . $student_id . ' in term ' . $term . ', ' . $year
            );

            $_SESSION['success'] = 'Evaluation saved successfully.';
        } else {
            $_SESSION['error'] = implode(' ', $post_errors);
        }

        header('Location: ' . $redirect_url);
        exit;
    }

    if (isset($_POST['update_evaluation'])) {
        $evaluation_id = (int)($_POST['evaluation_id'] ?? 0);
        $comments = trim((string)($_POST['comments'] ?? ''));

        $academic = trim((string)($_POST['academic'] ?? ''));
        $non_academic = trim((string)($_POST['non_academic'] ?? ''));
        $cognitive = trim((string)($_POST['cognitive'] ?? ''));
        $psychomotor = trim((string)($_POST['psychomotor'] ?? ''));
        $affective = trim((string)($_POST['affective'] ?? ''));

        foreach ([$academic, $non_academic, $cognitive, $psychomotor, $affective] as $rating_value) {
            if (!isset($rating_options[$rating_value])) {
                $post_errors[] = 'All rating fields must use the available evaluation options.';
                break;
            }
        }

        $evaluation_check_stmt = $pdo->prepare("
            SELECT e.id
            FROM evaluations e
            JOIN students s
                ON s.id = e.student_id
            WHERE e.id = ?
              AND e.teacher_id = ?
              AND s.school_id = ?
              AND (e.school_id = ? OR e.school_id IS NULL)
            LIMIT 1
        ");
        $evaluation_check_stmt->execute([$evaluation_id, $teacher_id, $current_school_id, $current_school_id]);
        $evaluation_exists = $evaluation_check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$evaluation_exists) {
            $post_errors[] = 'That evaluation record could not be found in your workspace.';
        }

        if (empty($post_errors)) {
            $update_stmt = $pdo->prepare("
                UPDATE evaluations
                SET school_id = ?,
                    academic = ?,
                    non_academic = ?,
                    cognitive = ?,
                    psychomotor = ?,
                    affective = ?,
                    comments = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND teacher_id = ?
            ");

            $update_stmt->execute([
                $current_school_id,
                $academic,
                $non_academic,
                $cognitive,
                $psychomotor,
                $affective,
                $comments,
                $evaluation_id,
                $teacher_id,
            ]);

            log_teacher_action(
                'update_evaluation',
                'evaluation',
                $evaluation_id,
                'Updated student evaluation ratings and comments'
            );

            $_SESSION['success'] = 'Evaluation updated successfully.';
        } else {
            $_SESSION['error'] = implode(' ', $post_errors);
        }

        header('Location: ' . $redirect_url);
        exit;
    }

    if (isset($_POST['delete_evaluation'])) {
        $evaluation_id = (int)($_POST['evaluation_id'] ?? 0);

        $delete_stmt = $pdo->prepare("
            DELETE e
            FROM evaluations e
            JOIN students s
                ON s.id = e.student_id
            WHERE e.id = ?
              AND e.teacher_id = ?
              AND s.school_id = ?
              AND (e.school_id = ? OR e.school_id IS NULL)
        ");
        $delete_stmt->execute([$evaluation_id, $teacher_id, $current_school_id, $current_school_id]);

        if ($delete_stmt->rowCount() > 0) {
            log_teacher_action(
                'delete_evaluation',
                'evaluation',
                $evaluation_id,
                'Deleted evaluation record'
            );
            $_SESSION['success'] = 'Evaluation deleted successfully.';
        } else {
            $_SESSION['error'] = 'That evaluation record could not be deleted.';
        }

        header('Location: ' . $redirect_url);
        exit;
    }
}

$flash_success = $_SESSION['success'] ?? '';
$flash_error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$search = trim((string)($_GET['search'] ?? ''));
$class_filter = trim((string)($_GET['class_filter'] ?? ''));
$term_filter = trim((string)($_GET['term_filter'] ?? ''));
$year_filter = trim((string)($_GET['year_filter'] ?? ''));
$rating_filter = trim((string)($_GET['rating_filter'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

$base_sql = "
    FROM evaluations e
    JOIN students s
        ON s.id = e.student_id
    JOIN classes c
        ON c.id = e.class_id
    WHERE e.teacher_id = ?
      AND s.school_id = ?
      AND c.school_id = ?
      AND (e.school_id = ? OR e.school_id IS NULL)
";

$base_params = [$teacher_id, $current_school_id, $current_school_id, $current_school_id];
$filter_sql = '';
$filter_params = [];

if ($search !== '') {
    $filter_sql .= " AND (s.full_name LIKE ? OR s.admission_no LIKE ? OR e.comments LIKE ?)";
    $search_like = '%' . $search . '%';
    $filter_params[] = $search_like;
    $filter_params[] = $search_like;
    $filter_params[] = $search_like;
}

if ($class_filter !== '' && ctype_digit($class_filter)) {
    $filter_sql .= " AND e.class_id = ?";
    $filter_params[] = (int)$class_filter;
}

if (isset($term_options[$term_filter])) {
    $filter_sql .= " AND e.term = ?";
    $filter_params[] = $term_filter;
}

if ($year_filter !== '' && preg_match('/^\d{4}$/', $year_filter)) {
    $filter_sql .= " AND e.academic_year = ?";
    $filter_params[] = $year_filter;
}

if (isset($rating_options[$rating_filter])) {
    $filter_sql .= "
        AND (
            e.academic = ?
            OR e.non_academic = ?
            OR e.cognitive = ?
            OR e.psychomotor = ?
            OR e.affective = ?
        )
    ";
    $filter_params = array_merge($filter_params, array_fill(0, 5, $rating_filter));
}

$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_evaluations,
        COUNT(DISTINCT e.student_id) AS total_students,
        COUNT(DISTINCT e.class_id) AS total_classes,
        SUM(
            CASE
                WHEN e.academic = 'excellent'
                  OR e.non_academic = 'excellent'
                  OR e.cognitive = 'excellent'
                  OR e.psychomotor = 'excellent'
                  OR e.affective = 'excellent'
                THEN 1 ELSE 0
            END
        ) AS excellent_count,
        SUM(
            CASE
                WHEN e.academic = 'needs-improvement'
                  OR e.non_academic = 'needs-improvement'
                  OR e.cognitive = 'needs-improvement'
                  OR e.psychomotor = 'needs-improvement'
                  OR e.affective = 'needs-improvement'
                THEN 1 ELSE 0
            END
        ) AS needs_improvement_count
    " . $base_sql
);
$stats_stmt->execute($base_params);
$overall_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$count_stmt = $pdo->prepare("SELECT COUNT(*) " . $base_sql . $filter_sql);
$count_stmt->execute(array_merge($base_params, $filter_params));
$total_filtered_evaluations = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_filtered_evaluations / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$evaluations_stmt = $pdo->prepare("
    SELECT
        e.*,
        s.full_name,
        s.admission_no,
        c.class_name
    " . $base_sql . $filter_sql . "
    ORDER BY COALESCE(e.updated_at, e.created_at) DESC, e.id DESC
    LIMIT " . (int)$per_page . " OFFSET " . (int)$offset
);
$evaluations_stmt->execute(array_merge($base_params, $filter_params));
$evaluations = $evaluations_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_total = count($evaluations);
$active_filter_count = 0;
foreach ([$search, $class_filter, $term_filter, $year_filter, $rating_filter] as $filter_value) {
    if ($filter_value !== '' && $filter_value !== '0') {
        $active_filter_count += 1;
    }
}

$recent_update = '';
if (!empty($evaluations)) {
    $latest_timestamp = $evaluations[0]['updated_at'] ?: $evaluations[0]['created_at'];
    if (!empty($latest_timestamp)) {
        $recent_update = date('d M Y, h:i A', strtotime($latest_timestamp));
    }
}

$current_query_without_page = $_GET;
unset($current_query_without_page['page']);

$pagination_items = $build_pagination_window($page, $total_pages);
$students_payload = [];
foreach ($students as $student) {
    $students_payload[] = [
        'id' => (int)$student['id'],
        'class_id' => (int)$student['class_id'],
        'class_name' => $student['class_name'],
        'full_name' => $student['full_name'],
    ];
}

include '../includes/teacher_student_evaluation_page.php';
