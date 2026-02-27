<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth-check.php';

// Only allow principal (admin) and teachers
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['principal', 'teacher'])) {
    header("Location: ../index.php");
    exit;
}


$current_school_id = require_school_auth();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['full_name'] ?? 'User';
$is_principal = ($user_role === 'principal');

// Get current school for data isolation
$current_school_id = require_school_auth();

$errors = [];
$success = '';

// Handle Create / Update via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $topic = trim($_POST['topic'] ?? '');
    $duration = intval($_POST['duration'] ?? 0);
    $learning_objectives = trim($_POST['learning_objectives'] ?? '');
    $teaching_methods = trim($_POST['teaching_methods'] ?? '');
    $resources = trim($_POST['resources'] ?? '');
    $lesson_content = trim($_POST['lesson_content'] ?? '');
    $assessment_method = trim($_POST['assessment_method'] ?? '');
    $assessment_tasks = trim($_POST['assessment_tasks'] ?? '');
    $differentiation = trim($_POST['differentiation'] ?? '');
    $homework = trim($_POST['homework'] ?? '');
    $date_planned = trim($_POST['date_planned'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    $principal_remarks = $is_principal ? trim($_POST['principal_remarks'] ?? '') : '';

    // Validate inputs
    if ($subject_id <= 0) $errors[] = 'Subject is required.';
    if ($class_id <= 0) $errors[] = 'Class is required.';
    if ($topic === '') $errors[] = 'Topic is required.';
    if ($duration <= 0) $errors[] = 'Duration must be a valid number.';
    if ($learning_objectives === '') $errors[] = 'Learning objectives are required.';
    if ($assessment_method === '') $errors[] = 'Assessment method is required.';
    if ($date_planned === '' || !strtotime($date_planned)) $errors[] = 'Valid planned date is required.';

    // For teachers: set teacher_id to current user
    if ($user_role === 'teacher') {
        $teacher_id = $user_id;
    }

    // Validate teacher_id
    if ($teacher_id <= 0) {
        $errors[] = 'Teacher is required.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'teacher' AND school_id = :school_id");
        $stmt->execute(['id' => $teacher_id, 'school_id' => $current_school_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'Selected teacher does not exist or is not a teacher.';
        }
    }

    if (empty($errors)) {
        if ($action === 'add') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_plans 
                                  WHERE teacher_id = :teacher_id AND class_id = :class_id 
                                  AND topic = :topic AND DATE(date_planned) = :date_planned");
            $stmt->execute([
                'teacher_id' => $teacher_id,
                'class_id' => $class_id,
                'topic' => $topic,
                'date_planned' => $date_planned
            ]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'A lesson plan for this topic already exists for this class on this date.';
            } else {
                $approval_status = $is_principal ? 'approved' : 'pending';
                $approved_by = $is_principal ? $user_id : null;
                $approved_at = $is_principal ? date('Y-m-d H:i:s') : null;

                $stmt = $pdo->prepare("INSERT INTO lesson_plans
                                      (school_id, subject_id, class_id, teacher_id, topic, duration, learning_objectives,
                                       teaching_methods, resources, lesson_content, assessment_method, assessment_tasks,
                                       differentiation, homework, date_planned, status, approval_status, approved_by, approved_at, principal_remarks)
                                      VALUES (:school_id, :subject_id, :class_id, :teacher_id, :topic, :duration, :learning_objectives,
                                              :teaching_methods, :resources, :lesson_content, :assessment_method, :assessment_tasks,
                                              :differentiation, :homework, :date_planned, :status, :approval_status, :approved_by, :approved_at, :principal_remarks)");
                $stmt->execute([
                    'school_id' => $current_school_id,
                    'subject_id' => $subject_id,
                    'class_id' => $class_id,
                    'teacher_id' => $teacher_id,
                    'topic' => $topic,
                    'duration' => $duration,
                    'learning_objectives' => $learning_objectives,
                    'teaching_methods' => $teaching_methods,
                    'resources' => $resources,
                    'lesson_content' => $lesson_content,
                    'assessment_method' => $assessment_method,
                    'assessment_tasks' => $assessment_tasks,
                    'differentiation' => $differentiation,
                    'homework' => $homework,
                    'date_planned' => $date_planned,
                    'status' => $status,
                    'approval_status' => $approval_status,
                    'approved_by' => $approved_by,
                    'approved_at' => $approved_at,
                    'principal_remarks' => $principal_remarks
                ]);
                $success = 'Lesson plan created successfully!';
                header("Location: lesson-plans.php");
                exit;
            }
        }

        if ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                $plan = $stmt->fetch();

                if (!$plan) {
                    $errors[] = 'Lesson plan not found.';
                } elseif ($user_role === 'teacher' && ($plan['teacher_id'] != $user_id || $plan['status'] !== 'draft')) {
                    $errors[] = 'You can only edit your own draft lesson plans.';
                } else {
                    $stmt = $pdo->prepare("UPDATE lesson_plans SET 
                                          subject_id = :subject_id, class_id = :class_id, teacher_id = :teacher_id,
                                          topic = :topic, duration = :duration, learning_objectives = :learning_objectives,
                                          teaching_methods = :teaching_methods, resources = :resources, lesson_content = :lesson_content,
                                          assessment_method = :assessment_method, assessment_tasks = :assessment_tasks,
                                          differentiation = :differentiation, homework = :homework, date_planned = :date_planned,
                                          status = :status, principal_remarks = :principal_remarks WHERE id = :id AND school_id = :school_id");
                    $stmt->execute([
                        'subject_id' => $subject_id,
                        'class_id' => $class_id,
                        'teacher_id' => $teacher_id,
                        'topic' => $topic,
                        'duration' => $duration,
                        'learning_objectives' => $learning_objectives,
                        'teaching_methods' => $teaching_methods,
                        'resources' => $resources,
                        'lesson_content' => $lesson_content,
                        'assessment_method' => $assessment_method,
                        'assessment_tasks' => $assessment_tasks,
                        'differentiation' => $differentiation,
                        'homework' => $homework,
                        'date_planned' => $date_planned,
                        'status' => $status,
                        'principal_remarks' => $principal_remarks,
                        'id' => $id,
                        'school_id' => $current_school_id
                    ]);
                    $success = 'Lesson plan updated successfully!';
                    header("Location: lesson-plans.php");
                    exit;
                }
            }
        }

        if ($action === 'approve' && $is_principal) {
            $id = intval($_POST['id'] ?? 0);
            $approval_status = $_POST['approval_status'] ?? 'approved';
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("UPDATE lesson_plans SET approval_status = :approval_status, approved_by = :approved_by, approved_at = NOW(), status = :status WHERE id = :id AND school_id = :school_id");
                $new_status = ($approval_status === 'approved') ? 'scheduled' : 'on_hold';
                $stmt->execute([
                    'approval_status' => $approval_status,
                    'approved_by' => $user_id,
                    'status' => $new_status,
                    'id' => $id,
                    'school_id' => $current_school_id
                ]);
                $success = 'Lesson plan ' . $approval_status . ' successfully!';
                header("Location: lesson-plans.php");
                exit;
            }
        }

        if ($action === 'complete' && $is_principal) {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("UPDATE lesson_plans SET status = :status WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['status' => 'completed', 'id' => $id, 'school_id' => $current_school_id]);
                $success = 'Lesson plan marked as completed!';
                header("Location: lesson-plans.php");
                exit;
            }
        }

        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                $plan = $stmt->fetch();

                if (!$plan) {
                    $errors[] = 'Lesson plan not found.';
                } elseif ($user_role === 'teacher' && $plan['teacher_id'] != $user_id) {
                    $errors[] = 'You can only delete your own lesson plans.';
                } else {
                    $pdo->prepare("DELETE FROM lesson_plan_feedback WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plan_attachments WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plans WHERE id = :id AND school_id = :school_id")
                        ->execute(['id' => $id, 'school_id' => $current_school_id]);
                    $success = 'Lesson plan deleted successfully!';
                    header("Location: lesson-plans.php");
                    exit;
                }
            }
        }
    }
}

// Fetch analytics data for charts
$monthly_submissions = [];
$status_distribution = [];
$approval_rates = [];
$teacher_performance = [];

// Monthly submissions for last 6 months
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM lesson_plans WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND school_id = ?");
    $stmt->execute([$month, $current_school_id]);
    $monthly_submissions[] = $stmt->fetch()['count'];
}

// Status distribution
$statuses = ['draft', 'submitted', 'scheduled', 'completed'];
foreach ($statuses as $status) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM lesson_plans WHERE status = ? AND school_id = ?");
    $stmt->execute([$status, $current_school_id]);
    $status_distribution[] = $stmt->fetch()['count'];
}

// Approval rates
$approval_statuses = ['approved', 'rejected', 'pending'];
foreach ($approval_statuses as $status) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM lesson_plans WHERE approval_status = ? AND school_id = ?");
    $stmt->execute([$status, $current_school_id]);
    $approval_rates[] = $stmt->fetch()['count'];
}

// Top teachers by lesson plans
$teacherStmt = $pdo->prepare("
    SELECT u.full_name, COUNT(lp.id) as plan_count 
    FROM users u 
    LEFT JOIN lesson_plans lp ON u.id = lp.teacher_id AND lp.school_id = ? 
    WHERE u.role = 'teacher' AND u.school_id = ? 
    GROUP BY u.id, u.full_name 
    ORDER BY plan_count DESC 
    LIMIT 5
");
$teacherStmt->execute([$current_school_id, $current_school_id]);
$teacher_performance = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch data for dropdowns
$stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name ASC");
$stmt->execute([$current_school_id]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$stmt->execute([$current_school_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND school_id = ? ORDER BY full_name ASC");
$stmt->execute([$current_school_id]);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search and filter
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['filter_status'] ?? '';
$filter_approval = $_GET['approval_status'] ?? '';
$filter_teacher = $_GET['filter_teacher'] ?? '';
$filter_class = $_GET['filter_class'] ?? '';

$query = "SELECT lp.*, s.subject_name as subject_name, c.class_name, u.full_name as teacher_name
          FROM lesson_plans lp
          JOIN subjects s ON lp.subject_id = s.id
          JOIN classes c ON lp.class_id = c.id
          JOIN users u ON lp.teacher_id = u.id
          WHERE lp.school_id = ?";
$params = [$current_school_id];

// Teachers see only their own plans
if ($user_role === 'teacher') {
    $query .= " AND lp.teacher_id = ?";
    $params[] = $user_id;
}

if ($search !== '') {
    $query .= " AND (lp.topic LIKE :search OR s.subject_name LIKE :search OR u.full_name LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($filter_status !== '') {
    $query .= " AND lp.status = :status";
    $params['status'] = $filter_status;
}

if ($filter_approval !== '') {
    $query .= " AND lp.approval_status = :approval_status";
    $params['approval_status'] = $filter_approval;
}

if ($filter_teacher !== '' && $is_principal) {
    $query .= " AND lp.teacher_id = :teacher_id_filter";
    $params['teacher_id_filter'] = intval($filter_teacher);
}

if ($filter_class !== '') {
    $query .= " AND lp.class_id = :class_id_filter";
    $params['class_id_filter'] = intval($filter_class);
}

$query .= " ORDER BY lp.date_planned DESC, lp.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$lesson_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch lesson plan data
$edit_plan = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM lesson_plans WHERE id = :id AND school_id = :school_id");
        $stmt->execute(['id' => $edit_id, 'school_id' => $current_school_id]);
        $edit_plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($edit_plan && $user_role === 'teacher' && ($edit_plan['teacher_id'] != $user_id || $edit_plan['status'] !== 'draft')) {
            $edit_plan = null;
        }
    }
}

function getApprovalBadge($status) {
    $classes = [
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'pending' => 'badge-warning'
    ];
    return $classes[$status] ?? 'badge-default';
}

function getStatusBadge($status) {
    $classes = [
        'draft' => 'badge-secondary',
        'submitted' => 'badge-warning',
        'scheduled' => 'badge-primary',
        'completed' => 'badge-success',
        'on_hold' => 'badge-warning',
        'cancelled' => 'badge-danger'
    ];
    return $classes[$status] ?? 'badge-default';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lesson Plans Management | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: #f3f6fb;
            color: #0f172a;
        }
        .dashboard-container .main-content {
            width: 100%;
        }
        .content-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            padding: 1.1rem 1.25rem;
            margin-bottom: 1rem;
        }
        .content-header h2 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
        }
        .small-muted {
            color: #64748b;
        }
        .content-header .small-muted {
            margin: 0.35rem 0 0;
            font-size: 0.92rem;
        }
        .lesson-section,
        .search-filter,
        .quick-actions,
        .analytics-section {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
            padding: 1.2rem;
            margin-bottom: 1rem;
        }
        .lesson-card {
            padding: 0;
        }
        .lesson-card h3 {
            margin: 0 0 1rem;
            color: #0f172a;
            font-size: 1.1rem;
            font-weight: 700;
        }
        .section-title {
            margin: 0 0 1rem;
            color: #0f172a;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .section-topbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 0.9rem;
        }
        .section-topbar h3 {
            margin: 0;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 14px;
        }
        .form-group label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            color: #334155;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 0.92rem;
            background: #ffffff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        textarea.form-control {
            resize: vertical;
            min-height: 92px;
            line-height: 1.45;
        }
        .admin-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin: 0 0 1rem;
        }
        .stat-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.04);
            padding: 0.95rem 1rem;
        }
        .stat-card i {
            color: #2563eb;
            font-size: 0.95rem;
            margin-bottom: 6px;
        }
        .stat-number {
            font-size: 1.35rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }
        .stat-label {
            color: #64748b;
            font-size: 0.83rem;
            font-weight: 600;
        }
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 11px 12px;
            text-decoration: none;
            color: #1f2937;
            font-weight: 600;
            transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }
        .quick-action-btn:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
            transform: translateY(-1px);
        }
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 12px;
        }
        .chart-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            padding: 0.9rem;
            min-height: 280px;
        }
        .chart-card h4 {
            margin: 0 0 0.7rem;
            color: #334155;
            font-size: 0.96rem;
            font-weight: 700;
        }
        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 10px;
            align-items: end;
        }
        .form-actions {
            margin-top: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .btn-gold, .btn-search, .btn-primary-std {
            background: #2563eb;
            color: #ffffff;
            border: 1px solid #2563eb;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .btn-gold:hover, .btn-search:hover, .btn-primary-std:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }
        .btn-secondary, .btn-reset, .btn-secondary-std {
            background: #e2e8f0;
            color: #0f172a;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 700;
            text-decoration: none;
        }
        .btn-outline-std {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            border-radius: 10px;
            padding: 9px 12px;
            font-weight: 700;
            text-decoration: none;
        }
        .btn-small {
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 0.82rem;
        }
        .table-wrapper {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow-x: auto;
            background: #ffffff;
        }
        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead th {
            background: #f8fafc;
            color: #334155;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-weight: 700;
            padding: 11px 10px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .table tbody td {
            padding: 11px 10px;
            border-bottom: 1px solid #eef2f7;
            color: #0f172a;
            vertical-align: top;
            font-size: 0.9rem;
        }
        .table tbody tr:hover {
            background: #f9fbff;
        }
        .manage-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .badge-secondary { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
        .badge-warning { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .badge-primary { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
        .badge-success { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .badge-danger { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .badge-default { background: #f1f5f9; color: #334155; border-color: #e2e8f0; }
        .lesson-form-collapsible {
            display: none;
            margin-top: 0.8rem;
            padding-top: 0.8rem;
            border-top: 1px solid #e2e8f0;
        }
        .lesson-form-collapsible.active {
            display: block;
        }
        .toggle-form-btn {
            margin-left: auto;
        }
        @media (max-width: 992px) {
            .content-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        @media (max-width: 768px) {
            .content-header,
            .lesson-section,
            .search-filter,
            .quick-actions,
            .analytics-section {
                padding: 0.9rem;
            }
            .admin-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .search-form {
                grid-template-columns: 1fr;
            }
            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }
        @media (max-width: 520px) {
            .admin-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
                        <p class="school-tagline">Admin Portal</p>
                    </div>
                </div>
            </div>

            <!-- User Info and Logout -->
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label"><?php echo ucfirst($user_role); ?></p>
                    <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon">🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📰</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link">
                            <span class="nav-icon">📔</span>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students Registration</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students-evaluations.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Students Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_class.php" class="nav-link">
                            <span class="nav-icon">🎓</span>
                            <span class="nav-text">Manage Classes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link active">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage-school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                                                            <li class="nav-item">
                        <a href="support.php" class="nav-link">
                            <span class="nav-icon">🛟</span>
                            <span class="nav-text">Support</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subscription.php" class="nav-link">
                            <span class="nav-icon">💳</span>
                            <span class="nav-text">Subscription</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <span class="nav-icon">📖</span>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">👤</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🚶</span>
                            <span class="nav-text">Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_timebook.php" class="nav-link">
                            <span class="nav-icon">⏰</span>
                            <span class="nav-text">Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_attendance.php" class="nav-link">
                            <span class="nav-icon">📋</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments_dashboard.php" class="nav-link">
                            <span class="nav-icon">💰</span>
                            <span class="nav-text">School Fees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="sessions.php" class="nav-link">
                            <span class="nav-icon">📅</span>
                            <span class="nav-text">School Sessions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_calendar.php" class="nav-link">
                            <span class="nav-icon">🗓️</span>
                            <span class="nav-text">School Calendar</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="applicants.php" class="nav-link">
                            <span class="nav-icon">📄</span>
                            <span class="nav-text">Applicants</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
        <div class="content-header">
            <div>
                <h2><i class="fas fa-clipboard-list"></i> Lesson Plans Management</h2>
                <p class="small-muted"><?php echo $is_principal ? 'Review, approve, and manage all lesson plans' : 'Create and manage your lesson plans'; ?></p>
            </div>
            <a href="index.php" class="btn btn-primary-std">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </div>

        <!-- Quick Stats -->
        <?php 
        $pending_approval = 0;
        $draft_count = 0;
        $scheduled_count = 0;
        $completed_count = 0;
        $total_count = count($lesson_plans);
        
        foreach ($lesson_plans as $lp) {
            if ($lp['approval_status'] === 'pending') $pending_approval++;
            if ($lp['status'] === 'draft') $draft_count++;
            if ($lp['status'] === 'scheduled') $scheduled_count++;
            if ($lp['status'] === 'completed') $completed_count++;
        }
        ?>
        
        <div class="admin-stats">
            <div class="stat-card">
                <i class="fas fa-file-alt"></i>
                <div class="stat-number"><?php echo $total_count; ?></div>
                <div class="stat-label">Total Plans</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-clock"></i>
                <div class="stat-number"><?php echo $pending_approval; ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-calendar-check"></i>
                <div class="stat-number"><?php echo $scheduled_count; ?></div>
                <div class="stat-label">Scheduled</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <div class="stat-number"><?php echo $completed_count; ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <!-- Quick Actions for Principal -->
        <?php if ($is_principal && $pending_approval > 0): ?>
        <div class="quick-actions">
            <a href="lesson-plans.php?filter_status=submitted&approval_status=pending" class="quick-action-btn">
                <i class="fas fa-hourglass-half"></i>
                <span>Review Pending (<?php echo $pending_approval; ?>)</span>
            </a>
            <a href="lesson-plans.php?filter_status=scheduled" class="quick-action-btn">
                <i class="fas fa-calendar-alt"></i>
                <span>View Scheduled</span>
            </a>
            <a href="lesson-plans.php" class="quick-action-btn">
                <i class="fas fa-sync-alt"></i>
                <span>Refresh View</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- Analytics Section -->
        <?php if ($is_principal): ?>
        <section class="lesson-section">
            <div class="lesson-card">
                <h3><i class="fas fa-chart-bar"></i> Lesson Plans Analytics</h3>
                <div class="charts-grid">
                    <div class="chart-card">
                        <h4><i class="fas fa-calendar-alt"></i> Monthly Submissions</h4>
                        <canvas id="monthlyChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h4><i class="fas fa-chart-pie"></i> Status Distribution</h4>
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h4><i class="fas fa-thumbs-up"></i> Approval Rates</h4>
                        <canvas id="approvalChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h4><i class="fas fa-users"></i> Top Teachers</h4>
                        <canvas id="teacherChart"></canvas>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>

        <!-- Create / Edit Form -->
        <section class="lesson-section">
            <div class="lesson-card">
                <div class="section-topbar">
                    <h3>
                        <i class="fas <?php echo $edit_plan ? 'fa-edit' : 'fa-plus-circle'; ?>"></i>
                        <?php echo $edit_plan ? 'Edit Lesson Plan' : 'Create New Lesson Plan'; ?>
                    </h3>
                    <button type="button" class="btn btn-outline-std toggle-form-btn" id="toggleLessonForm">
                        <i class="fas fa-eye"></i> Show Form
                    </button>
                </div>

                <div id="lessonFormBody" class="lesson-form-collapsible">
                <form method="POST" class="lesson-form" action="">
                    <input type="hidden" name="action" value="<?php echo $edit_plan ? 'edit' : 'add'; ?>">
                    <?php if ($edit_plan): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_plan['id']); ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="subject_id"><i class="fas fa-book"></i> Subject *</label>
                            <select id="subject_id" name="subject_id" class="form-control" required>
                                <option value="">Select Subject</option>
                                <?php $sel_subject = $edit_plan['subject_id'] ?? 0; ?>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo intval($s['id']); ?>" <?php echo intval($s['id']) === $sel_subject ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="class_id"><i class="fas fa-users"></i> Class *</label>
                            <select id="class_id" name="class_id" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php $sel_class = $edit_plan['class_id'] ?? 0; ?>
                                <?php foreach ($classes as $cl): ?>
                                    <option value="<?php echo intval($cl['id']); ?>" <?php echo intval($cl['id']) === $sel_class ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cl['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                       <div class="form-group">
                           <label for="teacher_id"><i class="fas fa-chalkboard-teacher"></i> Teacher *</label>
                           <select id="teacher_id" name="teacher_id" class="form-control" required <?php echo $user_role === 'teacher' ? 'disabled' : ''; ?>>
                               <option value="">Select Teacher</option>
                               <?php $sel_teacher = $edit_plan['teacher_id'] ?? ($user_role === 'teacher' ? $user_id : 0); ?>
                               <?php foreach ($teachers as $t): ?>
                                   <option value="<?php echo intval($t['id']); ?>" <?php echo intval($t['id']) === intval($sel_teacher) ? 'selected' : ''; ?>>
                                       <?php echo htmlspecialchars($t['full_name']); ?>
                                   </option>
                               <?php endforeach; ?>
                           </select>
                           <?php if ($user_role === 'teacher'): ?>
                               <input type="hidden" name="teacher_id" value="<?php echo intval($user_id); ?>">
                           <?php endif; ?>
                       </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="topic"><i class="fas fa-tag"></i> Topic/Unit *</label>
                            <input type="text" id="topic" name="topic" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_plan['topic'] ?? ''); ?>" 
                                   placeholder="e.g. Fractions, The Human Body" required>
                        </div>

                        <div class="form-group">
                            <label for="duration"><i class="fas fa-clock"></i> Duration (minutes) *</label>
                            <input type="number" id="duration" name="duration" class="form-control" 
                                   value="<?php echo intval($edit_plan['duration'] ?? 0); ?>" 
                                   min="1" placeholder="45" required>
                        </div>

                        <div class="form-group">
                            <label for="date_planned"><i class="fas fa-calendar-alt"></i> Planned Date *</label>
                            <input type="date" id="date_planned" name="date_planned" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_plan['date_planned'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="assessment_method"><i class="fas fa-clipboard-check"></i> Assessment Method *</label>
                            <select id="assessment_method" name="assessment_method" class="form-control" required>
                                <option value="">Select Assessment Method</option>
                                <?php $sel_assess = $edit_plan['assessment_method'] ?? ''; ?>
                                <option value="Quiz" <?php echo $sel_assess === 'Quiz' ? 'selected' : ''; ?>>Quiz</option>
                                <option value="Assignment" <?php echo $sel_assess === 'Assignment' ? 'selected' : ''; ?>>Assignment</option>
                                <option value="Practical" <?php echo $sel_assess === 'Practical' ? 'selected' : ''; ?>>Practical</option>
                                <option value="Observation" <?php echo $sel_assess === 'Observation' ? 'selected' : ''; ?>>Observation</option>
                                <option value="Project" <?php echo $sel_assess === 'Project' ? 'selected' : ''; ?>>Project</option>
                                <option value="Presentation" <?php echo $sel_assess === 'Presentation' ? 'selected' : ''; ?>>Presentation</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="status"><i class="fas fa-info-circle"></i> Status</label>
                            <select id="status" name="status" class="form-control">
                                <?php $sel_status = $edit_plan['status'] ?? 'draft'; ?>
                                <option value="draft" <?php echo $sel_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="scheduled" <?php echo $sel_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="completed" <?php echo $sel_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="learning_objectives"><i class="fas fa-bullseye"></i> Learning Objectives *</label>
                        <textarea id="learning_objectives" name="learning_objectives" class="form-control" rows="3" 
                                  placeholder="What will students be able to do after this lesson?" required><?php echo htmlspecialchars($edit_plan['learning_objectives'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="teaching_methods"><i class="fas fa-chalkboard-teacher"></i> Teaching Methods</label>
                        <textarea id="teaching_methods" name="teaching_methods" class="form-control" rows="2" 
                                  placeholder="e.g. Lecture, Discussion, Practical, Group Work, Role Play"><?php echo htmlspecialchars($edit_plan['teaching_methods'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="resources"><i class="fas fa-tools"></i> Learning Resources/Materials</label>
                        <textarea id="resources" name="resources" class="form-control" rows="2" 
                                  placeholder="Textbooks, charts, videos, lab equipment, etc."><?php echo htmlspecialchars($edit_plan['resources'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="lesson_content"><i class="fas fa-file-alt"></i> Detailed Lesson Content</label>
                        <textarea id="lesson_content" name="lesson_content" class="form-control" rows="5" 
                                  placeholder="Lesson outline: Introduction, Main content, Activities, Conclusion"><?php echo htmlspecialchars($edit_plan['lesson_content'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="assessment_tasks"><i class="fas fa-tasks"></i> Assessment Tasks</label>
                        <textarea id="assessment_tasks" name="assessment_tasks" class="form-control" rows="3" 
                                  placeholder="Specific questions, tasks or criteria for assessment"><?php echo htmlspecialchars($edit_plan['assessment_tasks'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="differentiation"><i class="fas fa-users-cog"></i> Differentiation Strategies</label>
                        <textarea id="differentiation" name="differentiation" class="form-control" rows="3" 
                                  placeholder="Support for struggling learners, extension for advanced learners"><?php echo htmlspecialchars($edit_plan['differentiation'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="homework"><i class="fas fa-home"></i> Homework/Assignment</label>
                        <textarea id="homework" name="homework" class="form-control" rows="2" 
                                  placeholder="Homework tasks and deadline"><?php echo htmlspecialchars($edit_plan['homework'] ?? ''); ?></textarea>
                    </div>

                    <?php if ($is_principal && $edit_plan): ?>
                    <div class="form-group">
                        <label for="principal_remarks"><i class="fas fa-comment-dots"></i> Principal's Remarks/Feedback</label>
                        <textarea id="principal_remarks" name="principal_remarks" class="form-control" rows="2" 
                                  placeholder="Your feedback or notes"><?php echo htmlspecialchars($edit_plan['principal_remarks'] ?? ''); ?></textarea>
                    </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <?php if ($edit_plan): ?>
                            <button type="submit" class="btn btn-primary-std">
                                <i class="fas fa-save"></i> Update Lesson Plan
                            </button>
                            <a href="lesson-plans.php" class="btn btn-secondary-std">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php else: ?>
                            <button type="submit" class="btn btn-primary-std">
                                <i class="fas fa-plus-circle"></i> Create Lesson Plan
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            </div>
        </section>

        <!-- Search and Filter -->
        <section class="search-filter">
            <h3 class="section-title">
                <i class="fas fa-search"></i> Search & Filter
            </h3>
            <form method="GET" class="search-form">
                <div class="form-group">
                    <input type="text" name="search" class="form-control" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Search topic, subject, or teacher...">
                </div>

                <div class="form-group">
                    <select name="filter_status" class="form-control">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="submitted" <?php echo $filter_status === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                        <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="on_hold" <?php echo $filter_status === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="approval_status" class="form-control">
                        <option value="">All Approvals</option>
                        <option value="pending" <?php echo $filter_approval === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filter_approval === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filter_approval === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>

                <?php if ($is_principal): ?>
                <div class="form-group">
                    <select name="filter_teacher" class="form-control">
                        <option value="">All Teachers</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?php echo intval($t['id']); ?>" <?php echo $filter_teacher == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <select name="filter_class" class="form-control">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?php echo intval($cl['id']); ?>" <?php echo $filter_class == $cl['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cl['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary-std">
                    <i class="fas fa-search"></i> Search
                </button>
                <a href="lesson-plans.php" class="btn btn-secondary-std">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </form>
        </section>

        <!-- Lesson Plans Table -->
        <section class="lesson-section">
            <div class="lesson-card">
                <h3><i class="fas fa-list-ul"></i> Lesson Plans Overview</h3>
                
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Topic</th>
                                <th>Class</th>
                                <th>Teacher</th>
                                <th>Date</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <?php if ($is_principal): ?>
                                    <th>Approval</th>
                                <?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lesson_plans) === 0): ?>
                                <tr>
                                    <td colspan="<?php echo $is_principal ? 10 : 9; ?>" class="text-center small-muted" style="padding: 3rem;">
                                        <i class="fas fa-inbox" style="font-size: 3rem; color: #dee2e6; margin-bottom: 1rem;"></i>
                                        <p>No lesson plans found matching your criteria</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lesson_plans as $lp): ?>
                                    <tr>
                                        <td><strong>#<?php echo intval($lp['id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($lp['subject_name']); ?></td>
                                        <td>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($lp['topic']); ?></div>
                                            <?php if ($is_principal && $lp['principal_remarks']): ?>
                                                <small style="color: #666; font-size: 0.85rem;">
                                                    <i class="fas fa-comment"></i> Has remarks
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($lp['class_name']); ?></td>
                                        <td class="small-muted"><?php echo htmlspecialchars($lp['teacher_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($lp['date_planned'])); ?></td>
                                        <td><?php echo intval($lp['duration']); ?> min</td>
                                        <td>
                                            <span class="badge <?php echo getStatusBadge($lp['status']); ?>">
                                                <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                                <?php echo ucfirst($lp['status']); ?>
                                            </span>
                                        </td>
                                        <?php if ($is_principal): ?>
                                            <td>
                                                <span class="badge <?php echo getApprovalBadge($lp['approval_status']); ?>">
                                                    <?php if ($lp['approval_status'] === 'approved'): ?>
                                                        <i class="fas fa-check"></i>
                                                    <?php elseif ($lp['approval_status'] === 'rejected'): ?>
                                                        <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-clock"></i>
                                                    <?php endif; ?>
                                                    <?php echo ucfirst($lp['approval_status']); ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="manage-actions">
                                                <a class="btn btn-outline-std btn-small" href="lesson-plans-detail.php?id=<?php echo intval($lp['id']); ?>" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>

                                                <?php if ($user_role === 'teacher' && $lp['teacher_id'] == $user_id && $lp['status'] === 'draft'): ?>
                                                    <a class="btn btn-secondary-std btn-small" href="lesson-plans.php?edit=<?php echo intval($lp['id']); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                <?php elseif ($is_principal): ?>
                                                    <a class="btn btn-secondary-std btn-small" href="lesson-plans.php?edit=<?php echo intval($lp['id']); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ($is_principal && $lp['approval_status'] === 'pending'): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                        <input type="hidden" name="approval_status" value="approved">
                                                        <button type="submit" class="btn btn-primary-std btn-small" title="Approve">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>

                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                        <input type="hidden" name="approval_status" value="rejected">
                                                        <button type="submit" class="btn btn-secondary-std btn-small" title="Reject" onclick="return confirm('Are you sure you want to reject this lesson plan?');">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($is_principal && $lp['status'] === 'scheduled'): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="complete">
                                                        <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                        <button type="submit" class="btn btn-primary-std btn-small" title="Mark Completed" onclick="return confirm('Mark this lesson plan as completed?');">
                                                            <i class="fas fa-check-double"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if (($user_role === 'teacher' && $lp['teacher_id'] == $user_id && $lp['status'] === 'draft') || $is_principal): ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this lesson plan?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                        <button type="submit" class="btn btn-outline-std btn-small">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>



<script>
    // Form validation
    document.addEventListener('DOMContentLoaded', function() {
        // Add required field indicators
        const requiredFields = document.querySelectorAll('[required]');
        requiredFields.forEach(field => {
            const label = field.closest('.form-group')?.querySelector('label');
            if (label) {
                const star = document.createElement('span');
                star.textContent = ' *';
                star.style.color = '#f72585';
                label.appendChild(star);
            }
        });
        
        
        // Toggle lesson form
        const toggleBtn = document.getElementById('toggleLessonForm');
        const formBody = document.getElementById('lessonFormBody');
        if (toggleBtn && formBody) {
            const isEditing = Boolean(document.querySelector('input[name="id"]'));
            const show = isEditing;
            formBody.classList.toggle('active', show);
            toggleBtn.innerHTML = show
                ? '<i class="fas fa-eye-slash"></i> Hide Form'
                : '<i class="fas fa-eye"></i> Show Form';
            toggleBtn.addEventListener('click', () => {
                const isOpen = formBody.classList.toggle('active');
                toggleBtn.innerHTML = isOpen
                    ? '<i class="fas fa-eye-slash"></i> Hide Form'
                    : '<i class="fas fa-eye"></i> Show Form';
            });
        }

// Search form auto-submit on filter change
        const filterSelects = document.querySelectorAll('.search-form select');
        filterSelects.forEach(select => {
            select.addEventListener('change', function() {
                if (this.value) {
                    this.closest('form').submit();
                }
            });
        });
        
        // Highlight rows with pending approval
        const pendingRows = document.querySelectorAll('tr');
        pendingRows.forEach(row => {
            const approvalBadge = row.querySelector('.badge-warning');
            if (approvalBadge && approvalBadge.textContent.includes('pending')) {
                row.style.backgroundColor = 'rgba(255, 193, 7, 0.1)';
                row.style.borderLeft = '3px solid #ffc107';
            }
        });

        // Initialize Charts
        <?php if ($is_principal): ?>
        const monthlyData = <?php echo json_encode($monthly_submissions); ?>;
        const statusData = <?php echo json_encode($status_distribution); ?>;
        const approvalData = <?php echo json_encode($approval_rates); ?>;
        const teacherLabels = <?php echo json_encode(array_column($teacher_performance, 'full_name')); ?>;
        const teacherData = <?php echo json_encode(array_column($teacher_performance, 'plan_count')); ?>;

        // Monthly Submissions Chart
        new Chart(document.getElementById('monthlyChart'), {
            type: 'line',
            data: {
                labels: [
                    '<?php echo date('M Y', strtotime('-5 months')); ?>',
                    '<?php echo date('M Y', strtotime('-4 months')); ?>',
                    '<?php echo date('M Y', strtotime('-3 months')); ?>',
                    '<?php echo date('M Y', strtotime('-2 months')); ?>',
                    '<?php echo date('M Y', strtotime('-1 month')); ?>',
                    '<?php echo date('M Y'); ?>'
                ],
                datasets: [{
                    label: 'Lesson Plans Submitted',
                    data: monthlyData,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // Status Distribution Chart
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Draft', 'Submitted', 'Scheduled', 'Completed'],
                datasets: [{
                    data: statusData,
                    backgroundColor: ['#6c757d', '#ffc107', '#17a2b8', '#28a745'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // Approval Rates Chart
        new Chart(document.getElementById('approvalChart'), {
            type: 'pie',
            data: {
                labels: ['Approved', 'Rejected', 'Pending'],
                datasets: [{
                    data: approvalData,
                    backgroundColor: ['#28a745', '#dc3545', '#ffc107'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // Top Teachers Chart
        new Chart(document.getElementById('teacherChart'), {
            type: 'bar',
            data: {
                labels: teacherLabels,
                datasets: [{
                    label: 'Lesson Plans Created',
                    data: teacherData,
                    backgroundColor: '#4361ee',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
        <?php endif; ?>
    });
</script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>



