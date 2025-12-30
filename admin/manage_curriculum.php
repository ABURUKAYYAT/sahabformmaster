<?php
// admin/manage_curriculum.php
session_start();
require_once '../config/db.php';

// Only allow principal (admin) to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$admin_name = $_SESSION['full_name'] ?? 'Administrator';
$errors = [];
$success = '';

// Handle Create / Update via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $subject_name = trim($_POST['subject_name'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $topics = trim($_POST['topics'] ?? '');
    $duration = trim($_POST['duration'] ?? '');
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $term = trim($_POST['term'] ?? 'first');
    $week = intval($_POST['week'] ?? 0);
    $status = $_POST['status'] ?? 'active';

    // Validate subject_id
    if ($subject_id <= 0) {
        $errors[] = 'Subject is required.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE id = :id");
        $stmt->execute(['id' => $subject_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'Selected subject does not exist.';
        }
    }

    // Validate class_id if provided
    if ($class_id > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE id = :id");
        $stmt->execute(['id' => $class_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'Selected class does not exist.';
        }
    } else {
        $class_id = null;
    }

    // Validate teacher_id if provided
    if ($teacher_id > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'teacher'");
        $stmt->execute(['id' => $teacher_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'Selected teacher does not exist.';
        }
    } else {
        $teacher_id = null;
    }

    // Validate week
    if ($week < 0 || $week > 52) {
        $errors[] = 'Week must be between 0 and 52.';
    }

    if (empty($errors)) {
        if ($action === 'add') {
            if ($subject_name === '' || $grade_level === '') {
                $errors[] = 'Subject name and grade level are required.';
            } else {
                // Check for duplicate
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM curriculum WHERE subject_id = :subject_id AND grade_level = :grade_level AND term = :term AND week = :week");
                $stmt->execute([
                    'subject_id' => $subject_id,
                    'grade_level' => $grade_level,
                    'term' => $term,
                    'week' => $week
                ]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = 'This curriculum already exists for this subject, grade, term, and week.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO curriculum (subject_id, subject_name, grade_level, description, topics, duration, teacher_id, class_id, term, week, status) 
                                          VALUES (:subject_id, :subject_name, :grade_level, :description, :topics, :duration, :teacher_id, :class_id, :term, :week, :status)");
                    $stmt->execute([
                        'subject_id' => $subject_id,
                        'subject_name' => $subject_name,
                        'grade_level' => $grade_level,
                        'description' => $description,
                        'topics' => $topics,
                        'duration' => $duration,
                        'teacher_id' => $teacher_id,
                        'class_id' => $class_id,
                        'term' => $term,
                        'week' => $week,
                        'status' => $status
                    ]);
                    $success = 'Curriculum created successfully.';
                    header("Location: manage_curriculum.php");
                    exit;
                }
            }
        }

        if ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0 || $subject_name === '' || $grade_level === '') {
                $errors[] = 'Invalid input for update.';
            } else {
                // Check for duplicate excluding current record
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM curriculum WHERE subject_id = :subject_id AND grade_level = :grade_level AND term = :term AND week = :week AND id <> :id");
                $stmt->execute([
                    'subject_id' => $subject_id,
                    'grade_level' => $grade_level,
                    'term' => $term,
                    'week' => $week,
                    'id' => $id
                ]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = 'This subject, grade, term, and week combination is already used.';
                } else {
                    $stmt = $pdo->prepare("UPDATE curriculum SET subject_id = :subject_id, subject_name = :subject_name, grade_level = :grade_level, description = :description, 
                                          topics = :topics, duration = :duration, teacher_id = :teacher_id, class_id = :class_id, term = :term, week = :week, status = :status WHERE id = :id");
                    $stmt->execute([
                        'subject_id' => $subject_id,
                        'subject_name' => $subject_name,
                        'grade_level' => $grade_level,
                        'description' => $description,
                        'topics' => $topics,
                        'duration' => $duration,
                        'teacher_id' => $teacher_id,
                        'class_id' => $class_id,
                        'term' => $term,
                        'week' => $week,
                        'status' => $status,
                        'id' => $id
                    ]);
                    $success = 'Curriculum updated successfully.';
                    header("Location: manage_curriculum.php");
                    exit;
                }
            }
        }

        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid curriculum id.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM curriculum WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $success = 'Curriculum deleted successfully.';
                header("Location: manage_curriculum.php");
                exit;
            }
        }

        if ($action === 'bulk_delete') {
            $ids = $_POST['selected_ids'] ?? [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM curriculum WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $success = count($ids) . ' curriculum items deleted successfully.';
                header("Location: manage_curriculum.php");
                exit;
            }
        }
    }
}

// Fetch subjects for dropdown
$stmt = $pdo->query("SELECT id, subject_name FROM subjects ORDER BY subject_name ASC");
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch teachers for dropdown
$stmt = $pdo->query("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name ASC");
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes for dropdown
$stmt = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search and filter
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['filter_status'] ?? '';
$filter_grade = $_GET['filter_grade'] ?? '';
$filter_term = $_GET['filter_term'] ?? '';
$filter_class = $_GET['filter_class'] ?? '';

$query = "SELECT c.*, cl.class_name, u.full_name as teacher_name 
          FROM curriculum c 
          LEFT JOIN classes cl ON c.class_id = cl.id 
          LEFT JOIN users u ON c.teacher_id = u.id 
          WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (c.subject_name LIKE :search OR c.grade_level LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($filter_status !== '') {
    $query .= " AND c.status = :status";
    $params['status'] = $filter_status;
}

if ($filter_grade !== '') {
    $query .= " AND c.grade_level = :grade";
    $params['grade'] = $filter_grade;
}

if ($filter_term !== '') {
    $query .= " AND c.term = :term";
    $params['term'] = $filter_term;
}

if ($filter_class !== '') {
    $query .= " AND c.class_id = :class_id";
    $params['class_id'] = $filter_class;
}

$query .= " ORDER BY c.grade_level, c.term, c.week, c.subject_name";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$curriculums = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch curriculum data
$edit_curriculum = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM curriculum WHERE id = :id");
        $stmt->execute(['id' => $edit_id]);
        $edit_curriculum = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// Get unique terms and weeks for filtering
$stmt = $pdo->query("SELECT DISTINCT term FROM curriculum WHERE term IS NOT NULL ORDER BY 
    CASE term 
        WHEN 'first' THEN 1 
        WHEN 'second' THEN 2 
        WHEN 'third' THEN 3 
        ELSE 4 
    END");
$terms = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Manage Curriculum | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a6fa5;
            --secondary-color: #166088;
            --accent-color: #4fc3a1;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --border-radius: 8px;
            --box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .curriculum-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .curriculum-container {
                grid-template-columns: 1fr;
            }
        }

        .curriculum-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--box-shadow);
            transition: transform 0.3s ease;
        }

        .curriculum-card:hover {
            transform: translateY(-5px);
        }

        .curriculum-card h3 {
            margin-top: 0;
            color: var(--secondary-color);
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .curriculum-form .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-gold {
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            color: #000;
        }

        .btn-gold:hover {
            background: linear-gradient(135deg, #ffed4e, #ffd700);
            transform: translateY(-2px);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-color);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .search-filter {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .table-wrapper {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: var(--primary-color);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid #eee;
        }

        .table tr:hover {
            background: #f8f9fa;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .badge-primary {
            background: #e3f2fd;
            color: var(--primary-color);
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-small {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 4px;
        }

        .curriculum-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0.5rem 0;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.875rem;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .topic-list {
            max-height: 200px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 1rem;
            border-radius: var(--border-radius);
            border: 1px solid #dee2e6;
        }

        .topic-item {
            padding: 0.5rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .topic-item:last-child {
            border-bottom: none;
        }

        .topic-item i {
            color: var(--accent-color);
        }

        .bulk-actions {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .select-all {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .export-buttons {
            display: flex;
            gap: 0.5rem;
        }
    </style>
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
                <span class="teacher-name"><?php echo htmlspecialchars($admin_name); ?></span>
                <span class="teacher-role">(Administrator)</span>
            </div>
            <a href="../index.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <ul class="nav-list">
                <li class="nav-item">
                    <a href="index.php" class="nav-link">
                        <span class="nav-icon">📊</span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <div class="content-header">
            <h2><i class="fas fa-book-open"></i> Manage Curriculum</h2>
            <p class="small-muted">Create, update and manage school curriculum</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Curriculum Statistics -->
        <div class="curriculum-stats">
            <?php
            $total_curriculum = count($curriculums);
            $active_curriculum = array_filter($curriculums, fn($c) => $c['status'] === 'active');
            $inactive_curriculum = array_filter($curriculums, fn($c) => $c['status'] === 'inactive');
            $unique_subjects = count(array_unique(array_column($curriculums, 'subject_name')));
            ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_curriculum; ?></div>
                <div class="stat-label">Total Curriculum</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($active_curriculum); ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($inactive_curriculum); ?></div>
                <div class="stat-label">Inactive</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $unique_subjects; ?></div>
                <div class="stat-label">Subjects</div>
            </div>
        </div>

        <!-- Create / Edit form -->
        <section class="curriculum-section">
            <div class="curriculum-card">
                <h3><i class="fas fa-plus-circle"></i> <?php echo $edit_curriculum ? 'Edit Curriculum' : 'Create New Curriculum'; ?></h3>

                <form method="POST" class="curriculum-form">
                    <input type="hidden" name="action" value="<?php echo $edit_curriculum ? 'edit' : 'add'; ?>">
                    <?php if ($edit_curriculum): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_curriculum['id']); ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="subject_id"><i class="fas fa-book"></i> Subject *</label>
                                <select id="subject_id" name="subject_id" class="form-control" required>
                                    <option value="">Select Subject</option>
                                    <?php $selected_subject = $edit_curriculum['subject_id'] ?? 0; ?>
                                    <?php foreach ($subjects as $s): ?>
                                        <option value="<?php echo intval($s['id']); ?>" <?php echo intval($s['id']) === $selected_subject ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($s['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="subject_name"><i class="fas fa-heading"></i> Subject Name *</label>
                                <input type="text" id="subject_name" name="subject_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_curriculum['subject_name'] ?? ''); ?>" 
                                       placeholder="e.g. Mathematics, English, Science" required>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="grade_level"><i class="fas fa-graduation-cap"></i> Grade Level *</label>
                                <select id="grade_level" name="grade_level" class="form-control" required>
                                    <option value="">Select Grade Level</option>
                                    <?php $selected_grade = $edit_curriculum['grade_level'] ?? ''; ?>
                                    <option value="JSS1" <?php echo $selected_grade === 'JSS1' ? 'selected' : ''; ?>>JSS 1</option>
                                    <option value="JSS2" <?php echo $selected_grade === 'JSS2' ? 'selected' : ''; ?>>JSS 2</option>
                                    <option value="JSS3" <?php echo $selected_grade === 'JSS3' ? 'selected' : ''; ?>>JSS 3</option>
                                    <option value="SS1" <?php echo $selected_grade === 'SS1' ? 'selected' : ''; ?>>SS 1</option>
                                    <option value="SS2" <?php echo $selected_grade === 'SS2' ? 'selected' : ''; ?>>SS 2</option>
                                    <option value="SS3" <?php echo $selected_grade === 'SS3' ? 'selected' : ''; ?>>SS 3</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="term"><i class="fas fa-calendar-alt"></i> Term</label>
                                <select id="term" name="term" class="form-control">
                                    <?php $selected_term = $edit_curriculum['term'] ?? 'first'; ?>
                                    <option value="first" <?php echo $selected_term === 'first' ? 'selected' : ''; ?>>First Term</option>
                                    <option value="second" <?php echo $selected_term === 'second' ? 'selected' : ''; ?>>Second Term</option>
                                    <option value="third" <?php echo $selected_term === 'third' ? 'selected' : ''; ?>>Third Term</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="week"><i class="fas fa-calendar-week"></i> Week</label>
                                <input type="number" id="week" name="week" class="form-control" min="0" max="52"
                                       value="<?php echo htmlspecialchars($edit_curriculum['week'] ?? 0); ?>">
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="duration"><i class="fas fa-clock"></i> Duration</label>
                                <input type="text" id="duration" name="duration" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_curriculum['duration'] ?? ''); ?>" 
                                       placeholder="e.g. 12 weeks">
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="teacher_id"><i class="fas fa-chalkboard-teacher"></i> Assign Teacher</label>
                                <select id="teacher_id" name="teacher_id" class="form-control">
                                    <option value="">Select Teacher (Optional)</option>
                                    <?php $selected_teacher = $edit_curriculum['teacher_id'] ?? 0; ?>
                                    <?php foreach ($teachers as $t): ?>
                                        <option value="<?php echo intval($t['id']); ?>" <?php echo intval($t['id']) === $selected_teacher ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($t['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="class_id"><i class="fas fa-users"></i> Class (Optional)</label>
                                <select id="class_id" name="class_id" class="form-control">
                                    <option value="">Select Class (Optional)</option>
                                    <?php $selected_class = $edit_curriculum['class_id'] ?? 0; ?>
                                    <?php foreach ($classes as $cl): ?>
                                        <option value="<?php echo intval($cl['id']); ?>" <?php echo intval($cl['id']) === $selected_class ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cl['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="status"><i class="fas fa-circle"></i> Status</label>
                                <select id="status" name="status" class="form-control">
                                    <?php $selected_status = $edit_curriculum['status'] ?? 'active'; ?>
                                    <option value="active" <?php echo $selected_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $selected_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description"><i class="fas fa-align-left"></i> Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3" 
                                  placeholder="Enter curriculum description..."><?php echo htmlspecialchars($edit_curriculum['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="topics"><i class="fas fa-list-ol"></i> Topics/Modules (one per line)</label>
                        <textarea id="topics" name="topics" class="form-control" rows="5" 
                                  placeholder="Topic 1&#10;Topic 2&#10;Topic 3..."><?php echo htmlspecialchars($edit_curriculum['topics'] ?? ''); ?></textarea>
                        <small class="small-muted">Enter each topic on a new line</small>
                    </div>

                    <div class="form-actions">
                        <?php if ($edit_curriculum): ?>
                            <button type="submit" class="btn btn-gold">
                                <i class="fas fa-save"></i> Update Curriculum
                            </button>
                            <a href="manage_curriculum.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php else: ?>
                            <button type="submit" class="btn btn-gold">
                                <i class="fas fa-plus"></i> Create Curriculum
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- Search and Filter -->
        <section class="curriculum-section">
            <div class="search-filter">
                <form method="GET" class="search-form">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by subject or grade...">
                    </div>

                    <div class="form-group">
                        <label>Grade Level</label>
                        <select name="filter_grade" class="form-control">
                            <option value="">All Grades</option>
                            <option value="JSS1" <?php echo $filter_grade === 'JSS1' ? 'selected' : ''; ?>>JSS 1</option>
                            <option value="JSS2" <?php echo $filter_grade === 'JSS2' ? 'selected' : ''; ?>>JSS 2</option>
                            <option value="JSS3" <?php echo $filter_grade === 'JSS3' ? 'selected' : ''; ?>>JSS 3</option>
                            <option value="SS1" <?php echo $filter_grade === 'SS1' ? 'selected' : ''; ?>>SS 1</option>
                            <option value="SS2" <?php echo $filter_grade === 'SS2' ? 'selected' : ''; ?>>SS 2</option>
                            <option value="SS3" <?php echo $filter_grade === 'SS3' ? 'selected' : ''; ?>>SS 3</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Term</label>
                        <select name="filter_term" class="form-control">
                            <option value="">All Terms</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo htmlspecialchars($term); ?>" <?php echo $filter_term === $term ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($term) . ' Term'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Class</label>
                        <select name="filter_class" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?php echo intval($cl['id']); ?>" <?php echo intval($filter_class) === intval($cl['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cl['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="filter_status" class="form-control">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <a href="manage_curriculum.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </section>

        <!-- Bulk Actions -->
        <form method="POST" id="bulkForm" onsubmit="return confirm('Are you sure you want to delete selected curriculum items?');">
            <input type="hidden" name="action" value="bulk_delete">
            <div class="bulk-actions">
                <div class="select-all">
                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                    <label for="selectAll">Select All</label>
                    <button type="button" class="btn btn-danger btn-small" onclick="deleteSelected()">
                        <i class="fas fa-trash"></i> Delete Selected
                    </button>
                </div>
                <div class="export-buttons">
                    <button type="button" class="btn btn-primary btn-small" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <button type="button" class="btn btn-success btn-small" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </div>
            </div>

            <!-- Curriculum Table -->
            <section class="curriculum-section">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="selectAllHeader" onchange="toggleSelectAll(this)">
                                </th>
                                <th>#</th>
                                <th>Subject</th>
                                <th>Grade</th>
                                <th>Term</th>
                                <th>Week</th>
                                <th>Teacher</th>
                                <th>Class</th>
                                <th>Topics</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th style="width: 160px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($curriculums) === 0): ?>
                                <tr>
                                    <td colspan="12" class="text-center small-muted">
                                        <i class="fas fa-info-circle"></i> No curriculum found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($curriculums as $c): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="selected_ids[]" value="<?php echo intval($c['id']); ?>" class="select-checkbox">
                                        </td>
                                        <td><?php echo intval($c['id']); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($c['subject_name']); ?></strong>
                                            <?php if ($c['description']): ?>
                                                <br><small class="small-muted"><?php echo substr(htmlspecialchars($c['description']), 0, 50); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-primary"><?php echo htmlspecialchars($c['grade_level']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-warning"><?php echo ucfirst($c['term'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($c['week'] > 0): ?>
                                                <span class="badge">Week <?php echo intval($c['week']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small-muted">
                                            <?php echo htmlspecialchars($c['teacher_name'] ?? 'Unassigned'); ?>
                                        </td>
                                        <td class="small-muted">
                                            <?php echo htmlspecialchars($c['class_name'] ?? '-'); ?>
                                        </td>
                                        <td class="text-center">
                                            <?php
                                            $topicCount = !empty($c['topics']) ? count(array_filter(explode("\n", $c['topics']))) : 0;
                                            ?>
                                            <span class="badge badge-success"><?php echo $topicCount; ?> topics</span>
                                        </td>
                                        <td class="small-muted"><?php echo htmlspecialchars($c['duration'] ?: '-'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo htmlspecialchars($c['status']); ?>">
                                                <?php echo ucfirst($c['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a class="btn btn-primary btn-small" href="manage_curriculum.php?edit=<?php echo intval($c['id']); ?>">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this curriculum?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo intval($c['id']); ?>">
                                                    <button type="submit" class="btn btn-danger btn-small">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-secondary btn-small" onclick="viewTopics(<?php echo intval($c['id']); ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </form>
    </main>
</div>

<!-- Topics Modal -->
<div id="topicsModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
    <div class="modal-content" style="background:white; margin:5% auto; padding:2rem; border-radius:var(--border-radius); width:80%; max-width:600px;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <h3><i class="fas fa-list-ol"></i> Topics</h3>
            <button onclick="closeModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div id="topicsList" class="topic-list"></div>
        <div class="modal-footer" style="margin-top:1.5rem; text-align:right;">
            <button onclick="closeModal()" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Comprehensive curriculum management system for schools.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php">Dashboard</a>
                    <a href="manage_curriculum.php">Curriculum</a>
                    <a href="students.php">Students</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p>Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Version 2.0</p>
        </div>
    </div>
</footer>

<script>
// Bulk selection
function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
}

function deleteSelected() {
    const selected = document.querySelectorAll('.select-checkbox:checked');
    if (selected.length === 0) {
        alert('Please select at least one curriculum item to delete.');
        return;
    }
    if (confirm(`Are you sure you want to delete ${selected.length} selected item(s)?`)) {
        document.getElementById('bulkForm').submit();
    }
}

// View topics modal
function viewTopics(curriculumId) {
    fetch(`get_topics.php?id=${curriculumId}`)
        .then(response => response.json())
        .then(data => {
            const topicsList = document.getElementById('topicsList');
            if (data.topics && data.topics.length > 0) {
                let html = '';
                data.topics.forEach((topic, index) => {
                    html += `<div class="topic-item">
                                <i class="fas fa-chevron-right"></i>
                                <span>${topic}</span>
                            </div>`;
                });
                topicsList.innerHTML = html;
            } else {
                topicsList.innerHTML = '<p class="text-center small-muted">No topics available.</p>';
            }
            document.getElementById('topicsModal').style.display = 'block';
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('topicsList').innerHTML = '<p class="text-center text-danger">Error loading topics.</p>';
        });
}

function closeModal() {
    document.getElementById('topicsModal').style.display = 'none';
}

// Export functions (placeholder)
function exportToPDF() {
    alert('PDF export feature would be implemented here');
    // Implement PDF export using jsPDF or server-side generation
}

function exportToExcel() {
    alert('Excel export feature would be implemented here');
    // Implement Excel export using SheetJS or server-side generation
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('topicsModal');
    if (event.target === modal) {
        closeModal();
    }
}
</script>

</body>
</html>