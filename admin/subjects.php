<?php

session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only principal/admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

// Get current school for data isolation
$current_school_id = require_school_auth();

$errors = [];
$success = '';
// Lists for assignment - filtered by current school
$teachers_query = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND school_id = ? ORDER BY full_name");
$teachers_query->execute([$current_school_id]);
$teachers = $teachers_query->fetchAll(PDO::FETCH_ASSOC);

$classes_query = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name");
$classes_query->execute([$current_school_id]);
$classes = $classes_query->fetchAll(PDO::FETCH_ASSOC);

// Handle actions: create, update, delete, assign, unassign
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') $errors[] = 'Subject name required.';
        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, subject_code, description, created_by, school_id, created_at) VALUES (:name, :code, :desc, :uid, :school_id, NOW())");
            $stmt->execute(['name'=>$name,'code'=>$code,'desc'=>$desc,'uid'=>$_SESSION['user_id'], 'school_id'=>$current_school_id]);
            $success = 'Subject created successfully.';
            header("Location: subjects.php");
            exit;
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($id <= 0) $errors[] = 'Invalid subject.';
        if ($name === '') $errors[] = 'Subject name required.';
        if (empty($errors)) {
            $stmt = $pdo->prepare("UPDATE subjects SET subject_name = :name, subject_code = :code, description = :desc, updated_at = NOW() WHERE id = :id AND school_id = :school_id");
            $stmt->execute(['name'=>$name,'code'=>$code,'desc'=>$desc,'id'=>$id, 'school_id'=>$current_school_id]);
            $success = 'Subject updated successfully.';
            header("Location: subjects.php");
            exit;
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            // Prevent deleting if dependent lesson_plans exist
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_plans WHERE subject_id = :id");
            $stmt->execute(['id' => $id]);
            $dependent = (int) $stmt->fetchColumn();

            if ($dependent > 0) {
                $errors[] = "Cannot delete subject: {$dependent} lesson plan(s) reference this subject. Reassign or delete those lesson plans first.";
            } else {
                // safe to remove assignments and subject
                $pdo->prepare("DELETE FROM subject_assignments WHERE subject_id = :id")->execute(['id' => $id]);
                $pdo->prepare("DELETE FROM subjects WHERE id = :id AND school_id = :school_id")->execute(['id' => $id, 'school_id' => $current_school_id]);
                $success = 'Subject deleted successfully.';
                header("Location: subjects.php");
                exit;
            }
        }
    } elseif ($action === 'assign') {
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $teacher_id = intval($_POST['teacher_id'] ?? 0);
        if ($subject_id <= 0 || $class_id <= 0 || $teacher_id <= 0) $errors[] = 'Invalid assignment data.';
        if (empty($errors)) {
            // prevent duplicate assignment
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_assignments WHERE subject_id = :sid AND class_id = :cid");
            $stmt->execute(['sid'=>$subject_id,'cid'=>$class_id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Subject already assigned to this class.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO subject_assignments (subject_id, class_id, teacher_id, assigned_at) VALUES (:sid,:cid,:tid,NOW())");
                $stmt->execute(['sid'=>$subject_id,'cid'=>$class_id,'tid'=>$teacher_id]);
                $success = 'Subject assigned successfully.';
                header("Location: subjects.php");
                exit;
            }
        }
    } elseif ($action === 'unassign') {
        $assign_id = intval($_POST['assign_id'] ?? 0);
        if ($assign_id > 0) {
            $pdo->prepare("DELETE FROM subject_assignments WHERE id = :id")->execute(['id'=>$assign_id]);
            $success = 'Subject unassigned successfully.';
            header("Location: subjects.php");
            exit;
        }
    }
}

// Fetch subjects and assignments - filtered by current school
$subjects_per_page = 10;
$subjects_page = max(1, intval($_GET['page'] ?? 1));
$subjects_offset = ($subjects_page - 1) * $subjects_per_page;

$subjects_total_stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE school_id = ?");
$subjects_total_stmt->execute([$current_school_id]);
$subjects_total = (int) $subjects_total_stmt->fetchColumn();
$subjects_total_pages = max(1, (int) ceil($subjects_total / $subjects_per_page));

$subjects_query = $pdo->prepare("SELECT s.*,
    (SELECT COUNT(*) FROM lesson_plans WHERE subject_id = s.id) as lesson_count,
    (SELECT COUNT(*) FROM subject_assignments WHERE subject_id = s.id) as assignment_count,
    u.full_name as creator_name
    FROM subjects s
    LEFT JOIN users u ON s.created_by = u.id
    WHERE s.school_id = ?
    ORDER BY s.subject_name ASC
    LIMIT ? OFFSET ?");
$subjects_query->bindValue(1, $current_school_id, PDO::PARAM_INT);
$subjects_query->bindValue(2, $subjects_per_page, PDO::PARAM_INT);
$subjects_query->bindValue(3, $subjects_offset, PDO::PARAM_INT);
$subjects_query->execute();
$subjects = $subjects_query->fetchAll(PDO::FETCH_ASSOC);

$assignments_query = $pdo->prepare("SELECT sa.id AS assign_id, sa.subject_id, sa.class_id, sa.teacher_id,
    c.class_name, u.full_name as teacher_name, s.subject_name, sa.assigned_at
    FROM subject_assignments sa
    JOIN classes c ON sa.class_id = c.id
    JOIN users u ON sa.teacher_id = u.id
    JOIN subjects s ON sa.subject_id = s.id
    WHERE c.school_id = ? AND u.school_id = ? AND s.school_id = ?
    ORDER BY c.class_name, s.subject_name");
$assignments_query->execute([$current_school_id, $current_school_id, $current_school_id]);
$assignments = $assignments_query->fetchAll(PDO::FETCH_ASSOC);

// Get statistics - filtered by current school
$stats_query = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM subjects WHERE school_id = ?) as total_subjects,
    (SELECT COUNT(*) FROM subject_assignments sa
     JOIN classes c ON sa.class_id = c.id
     JOIN subjects s ON sa.subject_id = s.id
     WHERE c.school_id = ? AND s.school_id = ?) as total_assignments,
    (SELECT COUNT(DISTINCT sa.teacher_id) FROM subject_assignments sa
     JOIN classes c ON sa.class_id = c.id
     JOIN subjects s ON sa.subject_id = s.id
     WHERE c.school_id = ? AND s.school_id = ?) as teachers_with_assignments");
$stats_query->execute([$current_school_id, $current_school_id, $current_school_id, $current_school_id, $current_school_id]);
$stats = $stats_query->fetch(PDO::FETCH_ASSOC);

$principal_name = $_SESSION['full_name'] ?? 'Principal';

// Get school settings for header
$school_name = 'SahabFormMaster'; // Default, could be fetched from DB
$school_tagline = 'Principal Portal'; // Default
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Management | <?php echo htmlspecialchars($school_name); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color: #38bdf8;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .page-title {
            font-family: 'Poppins', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
        }

        .card-icon-wrapper {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: grid;
            place-items: center;
            color: white;
            margin-bottom: 0.75rem;
        }

        .card-value {
            font-size: 2rem;
            font-weight: 800;
            margin: 0.25rem 0 0.5rem 0;
            color: var(--gray-900);
        }

        .section-container {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            margin-bottom: 2rem;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
        }

        .results-table th,
        .results-table td {
            padding: 0.85rem 0.9rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.92rem;
        }

        .results-table thead {
            background: var(--gray-50);
        }

        .table-container {
            overflow: auto;
        }

        .subject-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary-color);
            font-weight: 600;
            font-size: 0.8rem;
        }

        .pagination {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            justify-content: flex-end;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 0.75rem;
            border-radius: 8px;
            border: 1px solid var(--gray-200);
            text-decoration: none;
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.9rem;
            background: white;
        }

        .pagination a:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .pagination .active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars($school_name); ?></h1>
                        <p class="school-tagline"><?php echo htmlspecialchars($school_tagline); ?></p>
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
                    <i class="fas fa-sign-out-alt logout-icon"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <?php include '../includes/admin_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-book-open"></i>
                    Subject Management
                </h1>
                <p style="color: #6c757d; margin-top: 5px;">Manage subjects, assignments, and teaching allocations</p>
            </div>
            <button class="btn btn-primary" onclick="openModal('createSubjectModal')">
                <i class="fas fa-plus"></i> Add New Subject
            </button>
        </div>

        <?php if($errors): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div>
                <?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div><?php echo htmlspecialchars($success); ?></div>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="dashboard-cards">
            <div class="card">
                <div class="card-icon-wrapper">
                    <div class="card-icon">📚</div>
                </div>
                <div class="card-content">
                    <h3>Total Subjects</h3>
                    <p class="card-value"><?php echo number_format($stats['total_subjects']); ?></p>
                    <div class="card-footer">
                        <span class="card-badge">Active Subjects</span>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-icon-wrapper">
                    <div class="card-icon">🔗</div>
                </div>
                <div class="card-content">
                    <h3>Active Assignments</h3>
                    <p class="card-value"><?php echo number_format($stats['total_assignments']); ?></p>
                    <div class="card-footer">
                        <span class="card-badge">Subject-Class Links</span>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-icon-wrapper">
                    <div class="card-icon">👨‍🏫</div>
                </div>
                <div class="card-content">
                    <h3>Teachers Assigned</h3>
                    <p class="card-value"><?php echo number_format($stats['teachers_with_assignments']); ?></p>
                    <div class="card-footer">
                        <span class="card-badge">Teaching Staff</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Subject Modal -->
        <div id="createSubjectModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">
                        <i class="fas fa-plus-circle"></i> Create New Subject
                    </h2>
                    <button class="modal-close" onclick="closeModal('createSubjectModal')">&times;</button>
                </div>
                <form method="POST" id="createSubjectForm">
                    <input type="hidden" name="action" value="create">
                    <div class="form-group">
                        <label for="subjectName"><i class="fas fa-font"></i> Subject Name *</label>
                        <input type="text" id="subjectName" name="name" class="form-control" placeholder="Enter subject name" required>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="subjectCode"><i class="fas fa-code"></i> Subject Code</label>
                            <input type="text" id="subjectCode" name="code" class="form-control" placeholder="e.g., MATH101">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="subjectDesc"><i class="fas fa-align-left"></i> Description</label>
                        <textarea id="subjectDesc" name="description" class="form-control" rows="3" placeholder="Enter subject description"></textarea>
                    </div>
                    <div class="form-group" style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('createSubjectModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Subject
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- All Subjects Section -->
        <section class="section-container">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-list-alt"></i> All Subjects
                </h2>
                <div class="search-container">
                    <input type="text" id="subjectSearch" class="search-input" placeholder="Search subjects...">
                    <button class="search-btn">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>

            <?php if(empty($subjects)): ?>
            <div class="no-data">
                <i class="fas fa-book"></i>
                <h3>No Subjects Found</h3>
                <p>Get started by creating your first subject</p>
                <button class="btn btn-primary" onclick="openModal('createSubjectModal')">
                    <i class="fas fa-plus"></i> Create Subject
                </button>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Subject Name</th>
                            <th>Code</th>
                            <th>Description</th>
                            <th>Statistics</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="subjectTableBody">
                        <?php foreach($subjects as $s): ?>
                        <tr>
                            <td><strong>#<?php echo intval($s['id']); ?></strong></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($s['subject_name']); ?></div>
                            </td>
                            <td>
                                <?php if($s['subject_code']): ?>
                                <span class="subject-badge">
                                    <i class="fas fa-hashtag"></i>
                                    <?php echo htmlspecialchars($s['subject_code']); ?>
                                </span>
                                <?php else: ?>
                                <span style="color: #6c757d;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars(substr($s['description'] ?? 'No description', 0, 50)); ?>
                                <?php if(strlen($s['description'] ?? '') > 50): ?>...<?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 15px;">
                                    <span class="subject-badge" title="Lesson Plans">
                                        <i class="fas fa-file-alt"></i> <?php echo $s['lesson_count']; ?>
                                    </span>
                                    <span class="subject-badge" title="Assignments">
                                        <i class="fas fa-link"></i> <?php echo $s['assignment_count']; ?>
                                    </span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($s['creator_name'] ?? 'System'); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-small btn-edit" onclick="openEditModal(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars($s['subject_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($s['subject_code'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($s['description'] ?? '', ENT_QUOTES); ?>')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this subject? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                                        <button type="submit" class="btn-small btn-delete">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($subjects_total_pages > 1): ?>
            <div class="pagination" aria-label="Subjects pagination">
                <?php
                $prev_page = max(1, $subjects_page - 1);
                $next_page = min($subjects_total_pages, $subjects_page + 1);
                ?>
                <a href="?page=<?php echo $prev_page; ?>" class="<?php echo $subjects_page <= 1 ? 'disabled' : ''; ?>">Prev</a>
                <?php for ($p = 1; $p <= $subjects_total_pages; $p++): ?>
                    <?php if ($p == $subjects_page): ?>
                        <span class="active"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <a href="?page=<?php echo $next_page; ?>" class="<?php echo $subjects_page >= $subjects_total_pages ? 'disabled' : ''; ?>">Next</a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- Edit Subject Modal -->
        <div id="editSubjectModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">
                        <i class="fas fa-edit"></i> Edit Subject
                    </h2>
                    <button class="modal-close" onclick="closeModal('editSubjectModal')">&times;</button>
                </div>
                <form method="POST" id="editSubjectForm">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="editSubjectId">
                    <div class="form-group">
                        <label for="editSubjectName">Subject Name *</label>
                        <input type="text" id="editSubjectName" name="name" class="form-control" required>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="editSubjectCode">Subject Code</label>
                            <input type="text" id="editSubjectCode" name="code" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="editSubjectDesc">Description</label>
                        <textarea id="editSubjectDesc" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group" style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('editSubjectModal')">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Subject
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assign Subject Section -->
        <section class="section-container">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-link"></i> Subject Assignments
                </h2>
                <button class="btn btn-primary" onclick="openModal('assignSubjectModal')">
                    <i class="fas fa-link"></i> New Assignment
                </button>
            </div>

            <!-- Assign Subject Modal -->
            <div id="assignSubjectModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">
                            <i class="fas fa-link"></i> Assign Subject
                        </h2>
                        <button class="modal-close" onclick="closeModal('assignSubjectModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="assign">
                        <div class="form-group">
                            <label for="assignSubject">Subject *</label>
                            <select id="assignSubject" name="subject_id" class="form-control" required>
                                <option value="">Select Subject</option>
                                <?php foreach($subjects as $s): ?>
                                <option value="<?php echo intval($s['id']); ?>">
                                    <?php echo htmlspecialchars($s['subject_name']); ?>
                                    <?php if($s['subject_code']): ?> (<?php echo htmlspecialchars($s['subject_code']); ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="assignClass">Class *</label>
                                <select id="assignClass" name="class_id" class="form-control" required>
                                    <option value="">Select Class</option>
                                    <?php foreach($classes as $c): ?>
                                    <option value="<?php echo intval($c['id']); ?>">
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="assignTeacher">Teacher *</label>
                                <select id="assignTeacher" name="teacher_id" class="form-control" required>
                                    <option value="">Select Teacher</option>
                                    <?php foreach($teachers as $t): ?>
                                    <option value="<?php echo intval($t['id']); ?>">
                                        <?php echo htmlspecialchars($t['full_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" style="display: flex; gap: 10px; justify-content: flex-end;">
                            <button type="button" class="btn btn-outline" onclick="closeModal('assignSubjectModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-link"></i> Assign Subject
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if(empty($assignments)): ?>
            <div class="no-data">
                <i class="fas fa-link"></i>
                <h3>No Assignments Found</h3>
                <p>Assign subjects to classes and teachers to get started</p>
                <button class="btn btn-primary" onclick="openModal('assignSubjectModal')">
                    <i class="fas fa-link"></i> Create Assignment
                </button>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Teacher</th>
                            <th>Assigned On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($assignments as $a): ?>
                        <tr>
                            <td><strong>#<?php echo intval($a['assign_id']); ?></strong></td>
                            <td>
                                <span class="subject-badge">
                                    <i class="fas fa-users"></i>
                                    <?php echo htmlspecialchars($a['class_name']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($a['subject_name']); ?></div>
                            </td>
                            <td>
                                <span class="subject-badge">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <?php echo htmlspecialchars($a['teacher_name']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo date('M d, Y', strtotime($a['assigned_at'])); ?>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to unassign this subject?');">
                                    <input type="hidden" name="action" value="unassign">
                                    <input type="hidden" name="assign_id" value="<?php echo intval($a['assign_id']); ?>">
                                    <button type="submit" class="btn-small btn-delete">
                                        <i class="fas fa-unlink"></i> Unassign
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        </main>
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
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Edit modal function
        function openEditModal(id, name, code, description) {
            document.getElementById('editSubjectId').value = id;
            document.getElementById('editSubjectName').value = name;
            document.getElementById('editSubjectCode').value = code;
            document.getElementById('editSubjectDesc').value = description;
            openModal('editSubjectModal');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // Search functionality
        document.getElementById('subjectSearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#subjectTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + N for new subject
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openModal('createSubjectModal');
            }
            
            // Escape to close modal
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (modal.style.display === 'flex') {
                        closeModal(modal.id);
                    }
                });
            }
        });

        // Form validation
        document.getElementById('createSubjectForm')?.addEventListener('submit', function(e) {
            const name = document.getElementById('subjectName').value.trim();
            if (!name) {
                e.preventDefault();
                alert('Subject name is required');
                document.getElementById('subjectName').focus();
            }
        });

        // Auto-focus on modal inputs
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.addEventListener('shown', function() {
                const input = modal.querySelector('input, select, textarea');
                if (input) input.focus();
            });
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
