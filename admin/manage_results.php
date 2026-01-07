
<?php
session_start();
require_once '../config/db.php';

// Only allow principal to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_id = $_SESSION['user_id'];
$principal_name = $_SESSION['full_name'] ?? 'Principal';

// Function to normalize term
function normalize_term($t) {
    $t = trim(strtolower((string)$t));
    $map = [
        '1' => '1st Term','first' => '1st Term','1st' => '1st Term','first term' => '1st Term','1st term' => '1st Term',
        '2' => '2nd Term','second' => '2nd Term','2nd' => '2nd Term','second term' => '2nd Term','2nd term' => '2nd Term',
        '3' => '3rd Term','third' => '3rd Term','3rd' => '3rd Term','third term' => '3rd Term','3rd term' => '3rd Term'
    ];
    return $map[$t] ?? (strlen($t) ? ucfirst($t) : '1st Term');
}

// Get filters
$term = normalize_term($_GET['term'] ?? '1st Term');
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$errors = [];
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_result') {
        $result_id = intval($_POST['result_id'] ?? 0);
        if ($result_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM results WHERE id = :id");
                $stmt->execute(['id' => $result_id]);
                $success = "Result deleted successfully.";
            } catch (PDOException $e) {
                $errors[] = "Error deleting result: " . $e->getMessage();
            }
        }
    }

    if ($action === 'update_result') {
        $result_id = intval($_POST['result_id'] ?? 0);
        $first_ca = floatval($_POST['first_ca'] ?? 0);
        $second_ca = floatval($_POST['second_ca'] ?? 0);
        $exam = floatval($_POST['exam'] ?? 0);
        $academic_session = trim($_POST['academic_session'] ?? '');

        if ($result_id > 0) {
            // Clamp scores
            $first_ca = max(0, min(100, $first_ca));
            $second_ca = max(0, min(100, $second_ca));
            $exam = max(0, min(100, $exam));
            $total_ca = $first_ca + $second_ca;

            try {
                $hasUpdatedAt = (bool) $pdo->query("SHOW COLUMNS FROM `results` LIKE 'updated_at'")->fetchColumn();
                if ($hasUpdatedAt) {
                    $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session, updated_at = NOW() WHERE id = :id");
                } else {
                    $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session WHERE id = :id");
                }
                $stmt->execute([
                    'first_ca' => $first_ca,
                    'second_ca' => $second_ca,
                    'exam' => $exam,
                    'total_ca' => $total_ca,
                    'academic_session' => $academic_session,
                    'id' => $result_id
                ]);
                $success = "Result updated successfully.";
            } catch (PDOException $e) {
                $errors[] = "Error updating result: " . $e->getMessage();
            }
        }
    }
}

// Fetch all classes
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all students
$students = $pdo->query("SELECT * FROM students ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Build query for results
$query = "
    SELECT r.*,
           s.full_name AS student_name,
           s.admission_no,
           s.class_id,
           c.class_name,
           sub.subject_name
    FROM results r
    JOIN students s ON r.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    JOIN subjects sub ON r.subject_id = sub.id
    WHERE r.term = :term
";

$params = ['term' => $term];

if ($class_id > 0) {
    $query .= " AND s.class_id = :class_id";
    $params['class_id'] = $class_id;
}

if ($student_id > 0) {
    $query .= " AND s.id = :student_id";
    $params['student_id'] = $student_id;
}

$query .= " ORDER BY c.class_name, s.full_name, sub.subject_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_students = count(array_unique(array_column($results, 'student_id')));
$total_subjects = count(array_unique(array_column($results, 'subject_id')));
$total_results = count($results);

// Calculate class averages
$class_stats = [];
foreach ($results as $result) {
    $class_id = $result['class_id'];
    $ca_total = $result['first_ca'] + $result['second_ca'];
    $grand_total = $ca_total + $result['exam'];

    if (!isset($class_stats[$class_id])) {
        $class_stats[$class_id] = [
            'class_name' => $result['class_name'],
            'total_scores' => 0,
            'count' => 0,
            'students' => []
        ];
    }

    $class_stats[$class_id]['total_scores'] += $grand_total;
    $class_stats[$class_id]['count']++;

    if (!in_array($result['student_id'], $class_stats[$class_id]['students'])) {
        $class_stats[$class_id]['students'][] = $result['student_id'];
    }
}

// Function to calculate grade
function calculateGrade($grand_total) {
    if ($grand_total >= 90) return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($grand_total >= 80) return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($grand_total >= 70) return ['grade' => 'C', 'remark' => 'Good'];
    if ($grand_total >= 60) return ['grade' => 'D', 'remark' => 'Fair'];
    if ($grand_total >= 50) return ['grade' => 'E', 'remark' => 'Pass'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Results | Principal Dashboard</title>
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
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
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
                        <a href="manage_results.php" class="nav-link active">
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
                    <h2>📊 Manage Results</h2>
                    <p>View and manage all student results across classes and terms</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($total_results); ?></span>
                        <span class="quick-stat-label">Total Results</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($total_students); ?></span>
                        <span class="quick-stat-label">Students</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($total_subjects); ?></span>
                        <span class="quick-stat-label">Subjects</span>
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
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📊</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Results</h3>
                        <p class="card-value"><?php echo number_format($total_results); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Academic Records</span>
                            <a href="#results-table" class="card-link">View All →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">👥</div>
                    </div>
                    <div class="card-content">
                        <h3>Students</h3>
                        <p class="card-value"><?php echo number_format($total_students); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">With Results</span>
                            <a href="students.php" class="card-link">Manage →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📚</div>
                    </div>
                    <div class="card-content">
                        <h3>Subjects</h3>
                        <p class="card-value"><?php echo number_format($total_subjects); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Covered</span>
                            <a href="subjects.php" class="card-link">View →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">🎓</div>
                    </div>
                    <div class="card-content">
                        <h3>Classes</h3>
                        <p class="card-value"><?php echo number_format(count($classes)); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Active</span>
                            <a href="manage_class.php" class="card-link">Manage →</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="stats-section">
                <div class="section-header">
                    <h3>🔍 Filter Results</h3>
                    <span class="section-badge">Refine Search</span>
                </div>
                <form method="GET" class="filter-form" style="padding: 2rem;">
                    <div class="filter-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                        <div class="form-group">
                            <label for="term" style="display: block; font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem;">Academic Term</label>
                            <select id="term" name="term" style="width: 100%; padding: 0.75rem; border: 2px solid var(--gray-300); border-radius: 0.5rem; font-size: 0.95rem; transition: border-color 0.3s ease;">
                                <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                                <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                                <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="class_id" style="display: block; font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem;">Class (Optional)</label>
                            <select id="class_id" name="class_id" style="width: 100%; padding: 0.75rem; border: 2px solid var(--gray-300); border-radius: 0.5rem; font-size: 0.95rem; transition: border-color 0.3s ease;">
                                <option value="0">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo intval($class['id']); ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="student_id" style="display: block; font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem;">Student (Optional)</label>
                            <select id="student_id" name="student_id" style="width: 100%; padding: 0.75rem; border: 2px solid var(--gray-300); border-radius: 0.5rem; font-size: 0.95rem; transition: border-color 0.3s ease;">
                                <option value="0">All Students</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo intval($student['id']); ?>" <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_no'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <button type="submit" style="background: var(--primary-color); color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 0.5rem; font-weight: 600; cursor: pointer; transition: background 0.3s ease;">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="manage_results.php" style="background: var(--gray-500); color: white; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 0.5rem; font-weight: 600; transition: background 0.3s ease;">
                            <i class="fas fa-undo"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Class Statistics -->
            <?php if (!empty($class_stats)): ?>
                <div class="stats-section">
                    <div class="section-header">
                        <h3>📈 Class Performance Overview</h3>
                        <span class="section-badge">Analytics</span>
                    </div>
                    <div class="stats-grid">
                        <?php foreach ($class_stats as $class_id => $stats):
                            $average = $stats['count'] > 0 ? $stats['total_scores'] / $stats['count'] : 0;
                            $student_count = count($stats['students']);
                        ?>
                            <div class="stat-box">
                                <div class="stat-icon">🎓</div>
                                <div class="stat-info">
                                    <span class="stat-value"><?php echo number_format($average, 1); ?></span>
                                    <span class="stat-label">Avg Score - <?php echo htmlspecialchars($stats['class_name']); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-top: 1rem; font-size: 0.85rem; color: var(--gray-600);">
                                    <span><?php echo $student_count; ?> Students</span>
                                    <span><?php echo $stats['count']; ?> Results</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Results Table -->
            <div class="activity-section" id="results-table">
                <div class="section-header">
                    <h3>📋 All Results</h3>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span class="section-badge"><?php echo count($results); ?> Results</span>
                        <span style="color: var(--gray-500); font-size: 0.9rem;">Term: <?php echo htmlspecialchars($term); ?></span>
                    </div>
                </div>

                <?php if (empty($results)): ?>
                    <div style="text-align: center; padding: 4rem 2rem; color: var(--gray-500);">
                        <div style="font-size: 4rem; margin-bottom: 1rem;">📊</div>
                        <h3 style="color: var(--gray-700); margin-bottom: 0.5rem;">No Results Found</h3>
                        <p>No results match your current filters. Try adjusting your search criteria.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Class</th>
                                    <th>Student</th>
                                    <th>Admission No</th>
                                    <th>Subject</th>
                                    <th>1st CA</th>
                                    <th>2nd CA</th>
                                    <th>CA Total</th>
                                    <th>Exam</th>
                                    <th>Total</th>
                                    <th>Grade</th>
                                    <th>Remark</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $index => $result):
                                    $ca_total = $result['first_ca'] + $result['second_ca'];
                                    $grand_total = $ca_total + $result['exam'];
                                    $grade_data = calculateGrade($grand_total);
                                ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($result['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($result['admission_no']); ?></td>
                                        <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                        <td><?php echo number_format($result['first_ca'], 1); ?></td>
                                        <td><?php echo number_format($result['second_ca'], 1); ?></td>
                                        <td><strong><?php echo number_format($ca_total, 1); ?></strong></td>
                                        <td><?php echo number_format($result['exam'], 1); ?></td>
                                        <td><strong><?php echo number_format($grand_total, 1); ?></strong></td>
                                        <td>
                                            <span class="badge badge-<?php
                                                echo match($grade_data['grade']) {
                                                    'A' => 'success',
                                                    'B' => 'info',
                                                    'C' => 'warning',
                                                    'D' => 'secondary',
                                                    'E' => 'primary',
                                                    'F' => 'danger',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo $grade_data['grade']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $grade_data['remark']; ?></td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <button type="button" onclick="openEditModal(<?php echo $result['id']; ?>, <?php echo number_format($result['first_ca'], 1); ?>, <?php echo number_format($result['second_ca'], 1); ?>, <?php echo number_format($result['exam'], 1); ?>, '<?php echo addslashes($result['academic_session']); ?>')" style="background: var(--warning-color); color: white; border: none; padding: 0.5rem 0.75rem; border-radius: 0.375rem; font-size: 0.85rem; cursor: pointer; transition: background 0.3s ease;">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="delete_result">
                                                    <input type="hidden" name="result_id" value="<?php echo intval($result['id']); ?>">
                                                    <button type="submit" onclick="return confirm('Are you sure you want to delete this result?')" style="background: var(--error-color); color: white; border: none; padding: 0.5rem 0.75rem; border-radius: 0.375rem; font-size: 0.85rem; cursor: pointer; transition: background 0.3s ease;">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                                <form method="POST" action="../teacher/generate-result-pdf.php" style="display: inline;">
                                                    <input type="hidden" name="student_id" value="<?php echo intval($result['student_id']); ?>">
                                                    <input type="hidden" name="class_id" value="<?php echo intval($result['class_id']); ?>">
                                                    <input type="hidden" name="term" value="<?php echo htmlspecialchars($result['term']); ?>">
                                                    <button type="submit" style="background: var(--info-color); color: white; border: none; padding: 0.5rem 0.75rem; border-radius: 0.375rem; font-size: 0.85rem; cursor: pointer; transition: background 0.3s ease;">
                                                        <i class="fas fa-file-pdf"></i> PDF
                                                    </button>
                                                </form>
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

    <!-- Edit Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Result</h2>
                <button class="close-btn" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editForm">
                    <input type="hidden" name="action" value="update_result">
                    <input type="hidden" name="result_id" id="edit_result_id">

                    <div class="form-group" style="margin-bottom: 1.5rem;">
                        <label for="edit_academic_session" style="display: block; font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem;">Academic Session</label>
                        <input type="text" id="edit_academic_session" name="academic_session" style="width: 100%; padding: 0.75rem; border: 2px solid var(--gray-300); border-radius: 0.5rem; font-size: 0.95rem;" required>
                    </div>

                    <div class="score-inputs" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="form-group">
                            <label for="edit_first_ca" style="display: block; font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem;">First CA</label>
                            <input type="number" id="edit_first_ca" name="first_ca" style="width: 100%; padding: 0.75rem; border: 2px solid var(--gray-300); border-radius: 0.5rem; font-size: 0.95rem;" min="0" max="100" step="0.1" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_second_ca" style="display: block; font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem;">Second CA</label>
                            <input type="number" id="edit_second_ca" name="second_ca" style="width: 100%; padding: 0.75rem; border: 2px solid var(--gray-300); border-radius: 0.5rem; font-size: 0.95rem;" min="0" max="100" step="0.1" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_exam" style="display: block; font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem;">Exam</label>
                            <input type="number" id="edit_exam" name="exam" style="width: 100%; padding: 0.75rem; border: 2px solid var(--gray-300); border-radius: 0.5rem; font-size: 0.95rem;" min="0" max="100" step="0.1" required>
                        </div>
                    </div>

                    <div id="totalDisplay" style="background: var(--gray-100); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-weight: 600; color: var(--primary-color);">
                        Total Score: <span id="calculatedTotal">0.0</span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeEditModal()" style="background: var(--gray-500); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; cursor: pointer;">Cancel</button>
                <button type="submit" form="editForm" style="background: var(--primary-color); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; cursor: pointer;">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>About SahabFormMaster</h4>
                    <p>A comprehensive school management system designed for academic excellence and efficient administration.</p>
                </div>
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="manage-school.php">School Settings</a></li>
                        <li><a href="manage_user.php">User Management</a></li>
                        <li><a href="#">Support & Help</a></li>
                        <li><a href="#">Documentation</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact Information</h4>
                    <p>📧 admin@sahabformmaster.com</p>
                    <p>📱 +234 808 683 5607</p>
                    <p>🌐 www.sahabformmaster.com</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 SahabFormMaster. All rights reserved.</p>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <span>•</span>
                    <a href="#">Terms of Service</a>
                    <span>•</span>
                    <span>Version 2.0</span>
                </div>
            </div>
        </div>
    </footer>

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

        // Modal functions
        function openEditModal(resultId, firstCa, secondCa, exam, academicSession) {
            document.getElementById('edit_result_id').value = resultId;
            document.getElementById('edit_first_ca').value = firstCa;
            document.getElementById('edit_second_ca').value = secondCa;
            document.getElementById('edit_exam').value = exam;
            document.getElementById('edit_academic_session').value = academicSession;
            document.getElementById('editModal').style.display = 'flex';
            updateTotal();
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        // Calculate total automatically
        function updateTotal() {
            const firstCa = parseFloat(document.getElementById('edit_first_ca').value) || 0;
            const secondCa = parseFloat(document.getElementById('edit_second_ca').value) || 0;
            const exam = parseFloat(document.getElementById('edit_exam').value) || 0;
            const total = firstCa + secondCa + exam;
            document.getElementById('calculatedTotal').textContent = total.toFixed(1);
        }

        document.getElementById('edit_first_ca').addEventListener('input', updateTotal);
        document.getElementById('edit_second_ca').addEventListener('input', updateTotal);
        document.getElementById('edit_exam').addEventListener('input', updateTotal);

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
[file content end]
