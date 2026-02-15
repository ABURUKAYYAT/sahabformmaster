<?php
// admin/manage_curriculum.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow principal (admin) to access this page with school authentication
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();

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
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE id = :id AND school_id = :school_id");
        $stmt->execute(['id' => $subject_id, 'school_id' => $current_school_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'Selected subject does not exist or is not available for your school.';
        }
    }

    // Validate class_id if provided
    if ($class_id > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE id = :id AND school_id = :school_id");
        $stmt->execute(['id' => $class_id, 'school_id' => $current_school_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'Selected class does not exist or is not available for your school.';
        }
    } else {
        $class_id = null;
    }

    // Validate teacher_id if provided
    if ($teacher_id > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'teacher' AND school_id = :school_id");
        $stmt->execute(['id' => $teacher_id, 'school_id' => $current_school_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'Selected teacher does not exist or is not available for your school.';
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
                    
                        $school_id = $current_school_id;
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
                $stmt = $pdo->prepare("DELETE FROM curriculum WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                $success = 'Curriculum deleted successfully.';
                header("Location: manage_curriculum.php");
                exit;
            }
        }

        if ($action === 'bulk_delete') {
            $ids = $_POST['selected_ids'] ?? [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM curriculum WHERE id IN ($placeholders) AND school_id = ?");
                $params = array_merge($ids, [$current_school_id]);
                $stmt->execute($params);
                $success = count($ids) . ' curriculum items deleted successfully.';
                header("Location: manage_curriculum.php");
                exit;
            }
        }
    }
}

// Fetch subjects for dropdown - filtered by current school
$subjects_query = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name ASC");
$subjects_query->execute([$current_school_id]);
$subjects = $subjects_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch teachers for dropdown - filtered by current school
$teachers_query = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND school_id = ? ORDER BY full_name ASC");
$teachers_query->execute([$current_school_id]);
$teachers = $teachers_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes for dropdown - filtered by current school
$classes_query = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$classes_query->execute([$current_school_id]);
$classes = $classes_query->fetchAll(PDO::FETCH_ASSOC);

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
        $stmt = $pdo->prepare("SELECT * FROM curriculum WHERE id = :id AND school_id = :school_id");
        $stmt->execute(['id' => $edit_id, 'school_id' => $current_school_id]);
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Curriculum | Principal Dashboard</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>

            <!-- Principal Info and Logout -->
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($admin_name); ?></span>
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
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link active">
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
                <div class="welcome-section">
                    <h2>📚 Manage Curriculum</h2>
                    <p>Create, update and manage school curriculum across all grades and terms</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format(count($curriculums)); ?></span>
                        <span class="quick-stat-label">Total Items</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo count(array_unique(array_column($curriculums, 'subject_name'))); ?></span>
                        <span class="quick-stat-label">Subjects</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo count(array_unique(array_column($curriculums, 'grade_level'))); ?></span>
                        <span class="quick-stat-label">Grades</span>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($errors): ?>
                <div class="alert alert-error" style="background: rgba(239, 68, 68, 0.1); color: #dc2626; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border-left: 4px solid #dc2626;">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" style="background: rgba(16, 185, 129, 0.1); color: #059669; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border-left: 4px solid #059669;">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="dashboard-cards">
                <?php
                $total_curriculum = count($curriculums);
                $active_curriculum = array_filter($curriculums, fn($c) => $c['status'] === 'active');
                $inactive_curriculum = array_filter($curriculums, fn($c) => $c['status'] === 'inactive');
                $unique_subjects = count(array_unique(array_column($curriculums, 'subject_name')));
                ?>
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📚</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Curriculum</h3>
                        <p class="card-value"><?php echo number_format($total_curriculum); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">All Items</span>
                            <a href="#curriculum-table" class="card-link">View All →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">✅</div>
                    </div>
                    <div class="card-content">
                        <h3>Active</h3>
                        <p class="card-value"><?php echo number_format(count($active_curriculum)); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Published</span>
                            <a href="?filter_status=active" class="card-link">Filter →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">⏸️</div>
                    </div>
                    <div class="card-content">
                        <h3>Inactive</h3>
                        <p class="card-value"><?php echo number_format(count($inactive_curriculum)); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Draft</span>
                            <a href="?filter_status=inactive" class="card-link">Filter →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📖</div>
                    </div>
                    <div class="card-content">
                        <h3>Subjects</h3>
                        <p class="card-value"><?php echo number_format($unique_subjects); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Unique</span>
                            <a href="subjects.php" class="card-link">Manage →</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create / Edit form -->
            <div class="activity-section">
                <div class="section-header">
                    <h3><?php echo $edit_curriculum ? '✏️ Edit Curriculum' : '➕ Create New Curriculum'; ?></h3>
                    <span class="section-badge"><?php echo $edit_curriculum ? 'Update' : 'Add New'; ?></span>
                </div>

                <form method="POST" class="filters-form">
                    <input type="hidden" name="action" value="<?php echo $edit_curriculum ? 'edit' : 'add'; ?>">
                    <?php if ($edit_curriculum): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_curriculum['id']); ?>">
                    <?php endif; ?>

                    <div class="stats-grid">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-book"></i> Subject *</label>
                            <select name="subject_id" class="form-control" required>
                                <option value="">Select Subject</option>
                                <?php $selected_subject = $edit_curriculum['subject_id'] ?? 0; ?>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo intval($s['id']); ?>" <?php echo intval($s['id']) === $selected_subject ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-heading"></i> Subject Name *</label>
                            <input type="text" name="subject_name" class="form-control"
                                   value="<?php echo htmlspecialchars($edit_curriculum['subject_name'] ?? ''); ?>"
                                   placeholder="e.g. Mathematics, English, Science" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-graduation-cap"></i> Grade Level *</label>
                            <select name="grade_level" class="form-control" required>
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

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-calendar-alt"></i> Term</label>
                            <select name="term" class="form-control">
                                <?php $selected_term = $edit_curriculum['term'] ?? 'first'; ?>
                                <option value="first" <?php echo $selected_term === 'first' ? 'selected' : ''; ?>>First Term</option>
                                <option value="second" <?php echo $selected_term === 'second' ? 'selected' : ''; ?>>Second Term</option>
                                <option value="third" <?php echo $selected_term === 'third' ? 'selected' : ''; ?>>Third Term</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-calendar-week"></i> Week</label>
                            <input type="number" name="week" class="form-control" min="0" max="52"
                                   value="<?php echo htmlspecialchars($edit_curriculum['week'] ?? 0); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-clock"></i> Duration</label>
                            <input type="text" name="duration" class="form-control"
                                   value="<?php echo htmlspecialchars($edit_curriculum['duration'] ?? ''); ?>"
                                   placeholder="e.g. 12 weeks">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-chalkboard-teacher"></i> Assign Teacher</label>
                            <select name="teacher_id" class="form-control">
                                <option value="">Select Teacher (Optional)</option>
                                <?php $selected_teacher = $edit_curriculum['teacher_id'] ?? 0; ?>
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?php echo intval($t['id']); ?>" <?php echo intval($t['id']) === $selected_teacher ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($t['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-users"></i> Class (Optional)</label>
                            <select name="class_id" class="form-control">
                                <option value="">Select Class (Optional)</option>
                                <?php $selected_class = $edit_curriculum['class_id'] ?? 0; ?>
                                <?php foreach ($classes as $cl): ?>
                                    <option value="<?php echo intval($cl['id']); ?>" <?php echo intval($cl['id']) === $selected_class ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cl['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-circle"></i> Status</label>
                            <select name="status" class="form-control">
                                <?php $selected_status = $edit_curriculum['status'] ?? 'active'; ?>
                                <option value="active" <?php echo $selected_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $selected_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 2rem;">
                        <label class="form-label"><i class="fas fa-align-left"></i> Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Enter curriculum description..."><?php echo htmlspecialchars($edit_curriculum['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-list-ol"></i> Topics/Modules (one per line)</label>
                        <textarea name="topics" class="form-control" rows="5"
                                  placeholder="Topic 1&#10;Topic 2&#10;Topic 3..."><?php echo htmlspecialchars($edit_curriculum['topics'] ?? ''); ?></textarea>
                        <small style="color: var(--gray-500); font-size: 0.875rem; margin-top: 0.5rem; display: block;">Enter each topic on a new line</small>
                    </div>

                    <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 2rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $edit_curriculum ? 'Update Curriculum' : 'Create Curriculum'; ?>
                        </button>
                        <?php if ($edit_curriculum): ?>
                            <a href="manage_curriculum.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Search and Filter -->
            <div class="activity-section">
                <div class="section-header">
                    <h3>🔍 Search & Filter Curriculum</h3>
                    <span class="section-badge">Refine Results</span>
                </div>

                <form method="GET" class="filters-form">
                    <div class="stats-grid">
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search by subject or grade...">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Grade Level</label>
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
                            <label class="form-label">Term</label>
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
                            <label class="form-label">Class</label>
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
                            <label class="form-label">Status</label>
                            <select name="filter_status" class="form-control">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="manage_curriculum.php" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <div class="activity-section">
                <div class="section-header">
                    <h3>⚡ Bulk Actions</h3>
                    <span class="section-badge">Manage Multiple</span>
                </div>

                <form method="POST" id="bulkForm" onsubmit="return confirm('Are you sure you want to delete selected curriculum items?');" class="filters-form">
                    <input type="hidden" name="action" value="bulk_delete">
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                        <label for="selectAll" style="font-weight: 600; color: var(--gray-700);">Select All</label>
                        <button type="button" class="btn btn-danger btn-small" onclick="deleteSelected()">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                        <button type="button" class="btn btn-success btn-small" onclick="exportToExcel()">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                    </div>
                </form>
            </div>

            <!-- Curriculum Table -->
            <div class="activity-section" id="curriculum-table">
                <div class="section-header">
                    <h3>📋 All Curriculum Items</h3>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span class="section-badge"><?php echo count($curriculums); ?> Items</span>
                    </div>
                </div>

                <?php if (empty($curriculums)): ?>
                    <div style="text-align: center; padding: 4rem 2rem; color: var(--gray-500);">
                        <div style="font-size: 4rem; margin-bottom: 1rem;">📚</div>
                        <h3 style="color: var(--gray-700); margin-bottom: 0.5rem;">No Curriculum Found</h3>
                        <p>No curriculum items match your current filters. Try adjusting your search criteria or create a new curriculum item.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th>
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
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($curriculums as $index => $c): ?>
                                    <tr>
                                        <td>
                                            <!-- <input type="checkbox" name="selected_ids[]" value="<?php echo intval($c['id']); ?>" class="select-checkbox"> -->
                                        </td>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($c['subject_name']); ?></strong>
                                            <?php if ($c['description']): ?>
                                                <br><small style="color: var(--gray-600);"><?php echo substr(htmlspecialchars($c['description']), 0, 50); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-primary"><?php echo htmlspecialchars($c['grade_level']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-warning"><?php echo ucfirst($c['term'] ?? 'N/A'); ?> Term</span>
                                        </td>
                                        <td>
                                            <?php if ($c['week'] > 0): ?>
                                                <span class="badge">Week <?php echo intval($c['week']); ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--gray-500);">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color: var(--gray-600);">
                                            <?php echo htmlspecialchars($c['teacher_name'] ?? 'Unassigned'); ?>
                                        </td>
                                        <td style="color: var(--gray-600);">
                                            <?php echo htmlspecialchars($c['class_name'] ?? '-'); ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php
                                            $topicCount = !empty($c['topics']) ? count(array_filter(explode("\n", $c['topics']))) : 0;
                                            ?>
                                            <span class="badge badge-success"><?php echo $topicCount; ?> topics</span>
                                        </td>
                                        <td style="color: var(--gray-600);"><?php echo htmlspecialchars($c['duration'] ?: '-'); ?></td>
                                        <td>
                                            <span class="badge <?php echo $c['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo ucfirst($c['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a class="btn btn-primary btn-small" href="manage_curriculum.php?edit=<?php echo intval($c['id']); ?>">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo intval($c['id']); ?>">
                                                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Delete this curriculum item?')">
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
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Topics Modal -->
    <div class="modal" id="topicsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-list-ol"></i> Curriculum Topics</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div id="topicsList" class="modal-body">
                <!-- Topics will be loaded here -->
            </div>
            <div class="modal-footer">
                <button onclick="closeModal()" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

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
                        let html = '<div style="padding: 1rem;">';
                        data.topics.forEach((topic, index) => {
                            html += `<div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; padding: 0.5rem; background: var(--gray-50); border-radius: 0.375rem;">
                                        <i class="fas fa-chevron-right" style="color: var(--primary-color);"></i>
                                        <span style="font-weight: 500;">${topic}</span>
                                    </div>`;
                        });
                        html += '</div>';
                        topicsList.innerHTML = html;
                    } else {
                        topicsList.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--gray-500);"><i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 1rem;"></i><p>No topics available.</p></div>';
                    }
                    document.getElementById('topicsModal').style.display = 'flex';
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('topicsList').innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--error-color);"><i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 1rem;"></i><p>Error loading topics.</p></div>';
                });
        }

        function closeModal() {
            document.getElementById('topicsModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('topicsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Export functions (placeholder)
        function exportToExcel() {
            alert('Excel export feature would be implemented here');
            // Implement Excel export using SheetJS or server-side generation
        }

        // Add active class on scroll
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
