<?php

session_start();
require_once '../config/db.php';

// Only principal/admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$errors = [];
$success = '';
// Lists for assignment
$teachers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

// Handle actions: create, update, delete, assign, unassign
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') $errors[] = 'Subject name required.';
        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, subject_code, description, created_by, created_at) VALUES (:name, :code, :desc, :uid, NOW())");
            $stmt->execute(['name'=>$name,'code'=>$code,'desc'=>$desc,'uid'=>$_SESSION['user_id']]);
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
            $stmt = $pdo->prepare("UPDATE subjects SET subject_name = :name, subject_code = :code, description = :desc, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['name'=>$name,'code'=>$code,'desc'=>$desc,'id'=>$id]);
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
                $pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute(['id' => $id]);
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

// Fetch subjects and assignments
$subjects = $pdo->query("SELECT s.*, 
    (SELECT COUNT(*) FROM lesson_plans WHERE subject_id = s.id) as lesson_count,
    (SELECT COUNT(*) FROM subject_assignments WHERE subject_id = s.id) as assignment_count,
    u.full_name as creator_name
    FROM subjects s 
    LEFT JOIN users u ON s.created_by = u.id
    ORDER BY s.subject_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$assignments = $pdo->query("SELECT sa.id AS assign_id, sa.subject_id, sa.class_id, sa.teacher_id, 
    c.class_name, u.full_name as teacher_name, s.subject_name, sa.assigned_at
    FROM subject_assignments sa
    JOIN classes c ON sa.class_id = c.id
    JOIN users u ON sa.teacher_id = u.id
    JOIN subjects s ON sa.subject_id = s.id
    ORDER BY c.class_name, s.subject_name")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $pdo->query("SELECT 
    (SELECT COUNT(*) FROM subjects) as total_subjects,
    (SELECT COUNT(*) FROM subject_assignments) as total_assignments,
    (SELECT COUNT(DISTINCT teacher_id) FROM subject_assignments) as teachers_with_assignments
    ")->fetch(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects - Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/subjects.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a6fa5;
            --secondary-color: #166088;
            --accent-color: #ff6b6b;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            --transition: all 0.3s ease;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title {
            color: var(--secondary-color);
            font-size: 2.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-title i {
            color: var(--primary-color);
            font-size: 2rem;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: var(--transition);
            border-left: 5px solid var(--primary-color);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.subjects { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-icon.assignments { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-icon.teachers { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }

        .stat-info h3 {
            font-size: 2.5rem;
            margin: 0;
            color: var(--dark-color);
            font-weight: 700;
        }

        .stat-info p {
            margin: 5px 0 0;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .section-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-title {
            font-size: 1.5rem;
            color: var(--secondary-color);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
            outline: none;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .alert-error {
            background: #fee;
            border-left: 5px solid var(--danger-color);
            color: #721c24;
        }

        .alert-success {
            background: #e8f7ef;
            border-left: 5px solid var(--success-color);
            color: #155724;
        }

        .alert i {
            font-size: 1.2rem;
        }

        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .results-table thead {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }

        .results-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .results-table tbody tr {
            border-bottom: 1px solid #e0e0e0;
            transition: var(--transition);
        }

        .results-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .results-table td {
            padding: 15px;
            vertical-align: middle;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-edit {
            background: #17a2b8;
            color: white;
        }

        .btn-delete {
            background: var(--danger-color);
            color: white;
        }

        .btn-view {
            background: #6c757d;
            color: white;
        }

        .btn-small:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .subject-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e9ecef;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin: 2px;
        }

        .subject-badge i {
            font-size: 0.8rem;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.5rem;
            color: var(--secondary-color);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }

        .search-container {
            position: relative;
            margin-bottom: 20px;
        }

        .search-input {
            padding: 12px 45px 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            width: 100%;
            font-size: 1rem;
        }

        .search-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
        }

        .filter-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            background: white;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .page-title {
                font-size: 1.8rem;
            }

            .section-container {
                padding: 20px;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
                width: 100%;
            }

            .action-buttons .btn-small {
                width: 100%;
                justify-content: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body> 
    <main class="main-content">
        <button class="btn btn-primary">
               <a href='index.php' style="text-decoration: none; color: white"> </i> Dashboard </a>
            </button>
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
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon subjects">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_subjects']; ?></h3>
                    <p>Total Subjects</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon assignments">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_assignments']; ?></h3>
                    <p>Active Assignments</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon teachers">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['teachers_with_assignments']; ?></h3>
                    <p>Teachers Assigned</p>
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

    <script>
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
</body>
</html>