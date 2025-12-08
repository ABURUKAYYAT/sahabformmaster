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

    $subject_id = intval($_POST['subject_id'] ?? 0); // <-- ADDED
    $subject_name = trim($_POST['subject_name'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $topics = trim($_POST['topics'] ?? '');
    $duration = trim($_POST['duration'] ?? '');
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $status = $_POST['status'] ?? 'active';

    // Validate subject_id - REQUIRED (added)
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
            $errors[] = 'Selected class does not exist. Please choose a valid class.';
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

    if (empty($errors)) {
        if ($action === 'add') {
            if ($subject_name === '' || $grade_level === '') {
                $errors[] = 'Subject name and grade level are required.';
            } else {
                // Check if curriculum already exists for this subject and grade
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM curriculum WHERE subject_id = :subject_id AND grade_level = :grade_level");
                $stmt->execute(['subject_id' => $subject_id, 'grade_level' => $grade_level]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = 'This curriculum already exists for this subject and grade.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO curriculum (subject_id, subject_name, grade_level, description, topics, duration, teacher_id, class_id, status) 
                                          VALUES (:subject_id, :subject_name, :grade_level, :description, :topics, :duration, :teacher_id, :class_id, :status)");
                    $stmt->execute([
                        'subject_id' => $subject_id,
                        'subject_name' => $subject_name,
                        'grade_level' => $grade_level,
                        'description' => $description,
                        'topics' => $topics,
                        'duration' => $duration,
                        'teacher_id' => $teacher_id,
                        'class_id' => $class_id,
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
                // Check if another curriculum has this subject/grade combination
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM curriculum WHERE subject_id = :subject_id AND grade_level = :grade_level AND id <> :id");
                $stmt->execute(['subject_id' => $subject_id, 'grade_level' => $grade_level, 'id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = 'This subject and grade combination is already used.';
                } else {
                    $stmt = $pdo->prepare("UPDATE curriculum SET subject_id = :subject_id, subject_name = :subject_name, grade_level = :grade_level, description = :description, 
                                          topics = :topics, duration = :duration, teacher_id = :teacher_id, class_id = :class_id, status = :status WHERE id = :id");
                    $stmt->execute([
                        'subject_id' => $subject_id,
                        'subject_name' => $subject_name,
                        'grade_level' => $grade_level,
                        'description' => $description,
                        'topics' => $topics,
                        'duration' => $duration,
                        'teacher_id' => $teacher_id,
                        'class_id' => $class_id,
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
    }
}

// Fetch subjects for dropdown (ADDED)
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

$query = "SELECT id, subject_id, subject_name, grade_level, description, topics, duration, teacher_id, class_id, status, created_at FROM curriculum WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (subject_name LIKE :search OR grade_level LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($filter_status !== '') {
    $query .= " AND status = :status";
    $params['status'] = $filter_status;
}

$query .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$curriculums = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch curriculum data
$edit_curriculum = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT id, subject_id, subject_name, grade_level, description, topics, duration, teacher_id, class_id, status FROM curriculum WHERE id = :id");
        $stmt->execute(['id' => $edit_id]);
        $edit_curriculum = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// Get teacher name by ID
function getTeacherName($teacher_id, $teachers) {
    foreach ($teachers as $t) {
        if ($t['id'] == $teacher_id) {
            return $t['full_name'];
        }
    }
    return 'Unassigned';
}

// Get subject name by ID (ADDED)
function getSubjectName($subject_id, $subjects) {
    foreach ($subjects as $s) {
        if ($s['id'] == $subject_id) {
            return $s['name'];
        }
    }
    return 'Unknown';
}

// Count topics
function countTopics($topics_string) {
    if (empty($topics_string)) return 0;
    return count(array_filter(array_map('trim', explode("\n", $topics_string))));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Manage Curriculum | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/manage_curriculum.css">
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
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <ul class="nav-list">
                <li class="nav-item">
                    <a href="index.php" class="nav-link active">
                        <span class="nav-icon">📊</span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                   <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                <li class="nav-item">
                    <a href="students.php" class="nav-link">
                        <span class="nav-icon">👥</span>
                        <span class="nav-text">Manage Students</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="results.php" class="nav-link">
                        <span class="nav-icon">📈</span>
                        <span class="nav-text">Manage Results</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="lesson-plans.php" class="nav-link">
                        <span class="nav-icon">📝</span>
                        <span class="nav-text">Manage Lesson Plans</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_curriculum.php" class="nav-link">
                        <span class="nav-icon">📚</span>
                        <span class="nav-text">Manage Curriculum</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="school.php" class="nav-link">
                        <span class="nav-icon">🏫</span>
                        <span class="nav-text">Manage School</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_user.php" class="nav-link">
                        <span class="nav-icon">🔐</span>
                        <span class="nav-text">Manage Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="users.php" class="nav-link">
                        <span class="nav-icon">🔐</span>
                        <span class="nav-text">Manage Visitors</span>
                    </a>
                </li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <div class="content-header">
            <h2>Manage Curriculum</h2>
            <p class="small-muted">Create, update and manage school curriculum</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Create / Edit form -->
        <section class="curriculum-section">
            <div class="curriculum-card">
                <h3><?php echo $edit_curriculum ? 'Edit Curriculum' : 'Create New Curriculum'; ?></h3>

                <form method="POST" class="curriculum-form">
                    <input type="hidden" name="action" value="<?php echo $edit_curriculum ? 'edit' : 'add'; ?>">
                    <?php if ($edit_curriculum): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_curriculum['id']); ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="subject_id">Subject *</label>
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
                                <label for="subject_name">Subject Name *</label>
                                <input type="text" id="subject_name" name="subject_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_curriculum['subject_name'] ?? ''); ?>" 
                                       placeholder="e.g. Mathematics, English, Science" required>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="grade_level">Grade Level *</label>
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
                                <label for="teacher_id">Assign Teacher</label>
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
                                <label for="class_id">Class (Optional)</label>
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
                                <label for="duration">Duration</label>
                                <input type="text" id="duration" name="duration" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_curriculum['duration'] ?? ''); ?>" 
                                       placeholder="e.g. 12 weeks">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3" 
                                  placeholder="Enter curriculum description..."><?php echo htmlspecialchars($edit_curriculum['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="topics">Topics/Modules (one per line)</label>
                        <textarea id="topics" name="topics" class="form-control" rows="5" 
                                  placeholder="Topic 1&#10;Topic 2&#10;Topic 3..."><?php echo htmlspecialchars($edit_curriculum['topics'] ?? ''); ?></textarea>
                        <small class="small-muted">Enter each topic on a new line</small>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <?php $selected_status = $edit_curriculum['status'] ?? 'active'; ?>
                            <option value="active" <?php echo $selected_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $selected_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <?php if ($edit_curriculum): ?>
                            <button type="submit" class="btn-gold">Update Curriculum</button>
                            <a href="manage_curriculum.php" class="btn-secondary">Cancel</a>
                        <?php else: ?>
                            <button type="submit" class="btn-gold">Create Curriculum</button>
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
                        <input type="text" name="search" class="form-control" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by subject or grade...">
                    </div>

                    <div class="form-group">
                        <select name="filter_status" class="form-control">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-search">Search</button>
                    <a href="manage_curriculum.php" class="btn-reset">Reset</a>
                </form>
            </div>
        </section>

        <!-- Curriculum Table -->
        <section class="curriculum-section">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Subject</th>
                            <th>Grade</th>
                            <th>Teacher</th>
                            <th>Topics</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th style="width:160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($curriculums) === 0): ?>
                            <tr><td colspan="8" class="text-center small-muted">No curriculum found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($curriculums as $c): ?>
                                <tr>
                                    <td><?php echo intval($c['id']); ?></td>
                                    <td><?php echo htmlspecialchars($c['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($c['grade_level']); ?></td>
                                    <td class="small-muted"><?php echo getTeacherName($c['teacher_id'], $teachers); ?></td>
                                    <td class="text-center"><span class="badge"><?php echo countTopics($c['topics']); ?></span></td>
                                    <td class="small-muted"><?php echo htmlspecialchars($c['duration'] ?: '-'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($c['status']); ?>">
                                            <?php echo ucfirst($c['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="manage-actions">
                                            <a class="btn-small btn-edit" href="manage_curriculum.php?edit=<?php echo intval($c['id']); ?>">Edit</a>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this curriculum?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo intval($c['id']); ?>">
                                                <button type="submit" class="btn-small btn-delete">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
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
            <p class="footer-version">Version 1.0</p>
        </div>
    </div>
</footer>

</body>
</html>