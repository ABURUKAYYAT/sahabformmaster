<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only principal/admin with school authentication
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();

$errors = [];
$success = null;

// Helpers
function flash($k, $v = null) {
    if ($v === null) {
        $v = $_SESSION['flash'][$k] ?? null;
        unset($_SESSION['flash'][$k]);
        return $v;
    }
    $_SESSION['flash'][$k] = $v;
}

$principal_name = $_SESSION['full_name'];

// CSRF token (simple)
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid. Please refresh and try again.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_class') {
            $name = trim($_POST['class_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if (strlen($name) < 2) $errors[] = "Class name must be at least 2 characters.";
            if (empty($errors)) {
                $stmt = $pdo->prepare("INSERT INTO classes (class_name, school_id, created_at) VALUES (:name, :school_id, NOW())");
                $stmt->execute(['name' => $name, 'school_id' => $current_school_id]);
                flash('success', 'Class created successfully!');
                header("Location: manage_class.php");
                exit;
            }
        }

        if ($action === 'update_class') {
            $id = intval($_POST['class_id'] ?? 0);
            $name = trim($_POST['class_name'] ?? '');
            if ($id <= 0) $errors[] = "Invalid class ID.";
            if (strlen($name) < 2) $errors[] = "Class name must be at least 2 characters.";
            if (empty($errors)) {
                // Verify class belongs to user's school
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                if ($stmt->fetchColumn() == 0) {
                    $errors[] = "Class not found or access denied.";
                } else {
                    $stmt = $pdo->prepare("UPDATE classes SET class_name = :name, updated_at = NOW() WHERE id = :id AND school_id = :school_id");
                    $stmt->execute(['name' => $name, 'id' => $id, 'school_id' => $current_school_id]);
                    flash('success', 'Class updated successfully!');
                    header("Location: manage_class.php");
                    exit;
                }
            }
        }

        if ($action === 'delete_class') {
            $id = intval($_POST['class_id'] ?? 0);
            if ($id <= 0) $errors[] = "Invalid class ID.";
            if (empty($errors)) {
                // Verify class belongs to user's school
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                if ($stmt->fetchColumn() == 0) {
                    $errors[] = "Class not found or access denied.";
                } else {
                    // check dependencies: students, subject_assignments, results
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = :id AND school_id = :school_id");
                    $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                    if ($stmt->fetchColumn() > 0) {
                        $errors[] = "Cannot delete class with students assigned. Reassign or remove students first.";
                    } else {
                        // safe to delete mappings then class
                        $pdo->prepare("DELETE FROM class_teachers WHERE class_id = :id")->execute(['id' => $id]);
                        $pdo->prepare("DELETE FROM classes WHERE id = :id AND school_id = :school_id")->execute(['id' => $id, 'school_id' => $current_school_id]);
                        flash('success', 'Class deleted successfully.');
                        header("Location: manage_class.php");
                        exit;
                    }
                }
            }
        }

        if ($action === 'assign_teacher') {
            $class_id = intval($_POST['class_id'] ?? 0);
            $teacher_id = intval($_POST['teacher_id'] ?? 0);
            if ($class_id <= 0 || $teacher_id <= 0) $errors[] = "Invalid class or teacher selection.";
            if (empty($errors)) {
                // Verify class belongs to user's school
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $class_id, 'school_id' => $current_school_id]);
                if ($stmt->fetchColumn() == 0) {
                    $errors[] = "Class not found or access denied.";
                } else {
                    // ensure teacher exists, belongs to school, and role = teacher
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :tid AND role = 'teacher' AND school_id = :school_id");
                    $stmt->execute(['tid' => $teacher_id, 'school_id' => $current_school_id]);
                    if ($stmt->fetchColumn() == 0) {
                        $errors[] = "Selected teacher not found or not authorized for this school.";
                    } else {
                        // Check if already assigned
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM class_teachers WHERE class_id = :cid AND teacher_id = :tid");
                        $stmt->execute(['cid' => $class_id, 'tid' => $teacher_id]);
                        if ($stmt->fetchColumn() > 0) {
                            $errors[] = "This teacher is already assigned to the selected class.";
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO class_teachers (class_id, teacher_id) VALUES (:cid, :tid)");
                            $stmt->execute(['cid' => $class_id, 'tid' => $teacher_id]);
                            flash('success', 'Teacher assigned to class successfully!');
                            header("Location: manage_class.php");
                            exit;
                        }
                    }
                }
            }
        }

        if ($action === 'unassign_teacher') {
            $class_id = intval($_POST['class_id'] ?? 0);
            $teacher_id = intval($_POST['teacher_id'] ?? 0);
            if ($class_id <= 0 || $teacher_id <= 0) $errors[] = "Invalid class or teacher.";
            if (empty($errors)) {
                // Verify class belongs to user's school
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $class_id, 'school_id' => $current_school_id]);
                if ($stmt->fetchColumn() == 0) {
                    $errors[] = "Class not found or access denied.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM class_teachers WHERE class_id = :cid AND teacher_id = :tid");
                    $stmt->execute(['cid' => $class_id, 'tid' => $teacher_id]);
                    flash('success', 'Teacher unassigned successfully.');
                    header("Location: manage_class.php");
                    exit;
                }
            }
        }
    }
}

// Load data for display
try {
    // Load classes for current school only
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE school_id = ? ORDER BY class_name ASC");
    $stmt->execute([$current_school_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load teachers from current school only
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE role = 'teacher' AND school_id = ? ORDER BY full_name ASC");
    $stmt->execute([$current_school_id]);
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load assignments mapping with school filtering
    $assignments = [];
    $stmt = $pdo->prepare("
        SELECT ct.class_id, u.id AS teacher_id, u.full_name, u.email
        FROM class_teachers ct
        JOIN users u ON ct.teacher_id = u.id
        JOIN classes c ON ct.class_id = c.id
        WHERE u.role = 'teacher' AND u.school_id = ? AND c.school_id = ?
    ");
    $stmt->execute([$current_school_id, $current_school_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assignments[$row['class_id']][] = $row;
    }
} catch (PDOException $e) {
    $errors[] = "Database error: " . $e->getMessage();
    $classes = [];
    $teachers = [];
    $assignments = [];
}

$success = flash('success');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Classes | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
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
                        <a href="manage_class.php" class="nav-link active">
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
                    <h2>🎓 Class Management</h2>
                    <p>Create, edit, and assign teachers to classes</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-graduation-cap"></i>
                    <h3>Total Classes</h3>
                    <div class="count"><?php echo count($classes); ?></div>
                    <p class="stat-description">Active classes in the system</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <h3>Available Teachers</h3>
                    <div class="count"><?php echo count($teachers); ?></div>
                    <p class="stat-description">Teachers for assignment</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-link"></i>
                    <h3>Assignments</h3>
                    <div class="count">
                        <?php
                        $total_assignments = 0;
                        foreach ($assignments as $class_assignments) {
                            $total_assignments += count($class_assignments);
                        }
                        echo $total_assignments;
                        ?>
                    </div>
                    <p class="stat-description">Teacher-class connections</p>
                </div>
            </div>

            <!-- Messages -->
            <?php if($errors): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php foreach($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
                </div>
            <?php endif; ?>

            <?php if($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Class Management Forms -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-plus-circle"></i> Create New Class</h2>
                </div>

                <form method="POST" class="form-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="create_class">

                    <input name="class_name" placeholder="Class Name *" required>

                    <button type="submit" class="btn primary">
                        <i class="fas fa-plus"></i> Create Class
                    </button>
                </form>
            </section>

            <!-- Assign Teacher Section -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-user-plus"></i> Assign Teacher to Class</h2>
                </div>

                <form method="POST" class="form-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="assign_teacher">

                    <select name="class_id" required>
                        <option value="">Select Class *</option>
                        <?php foreach($classes as $c): ?>
                            <option value="<?php echo intval($c['id']); ?>">
                                <?php echo htmlspecialchars($c['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="teacher_id" required>
                        <option value="">Select Teacher *</option>
                        <?php foreach($teachers as $t): ?>
                            <option value="<?php echo intval($t['id']); ?>">
                                <?php echo htmlspecialchars($t['full_name']); ?>
                                <?php if (!empty($t['email'])): ?>
                                    (<?php echo htmlspecialchars($t['email']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn success">
                        <i class="fas fa-link"></i> Assign Teacher
                    </button>
                </form>
            </section>

            <!-- Classes List -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-list"></i> Class List</h2>
                    <button class="btn small secondary" onclick="refreshPage()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Class Name</th>
                                <th>Created</th>
                                <th>Assigned Teachers</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($classes)): ?>
                                <tr>
                                    <td colspan="4" class="text-center">
                                        <i class="fas fa-graduation-cap"></i> No classes found. Create your first class above.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($classes as $c): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($c['class_name']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($c['created_at'])); ?></td>
                                        <td>
                                            <?php if(!empty($assignments[$c['id']])): ?>
                                                <?php foreach($assignments[$c['id']] as $a): ?>
                                                    <span class="badge badge-info">
                                                        <?php echo htmlspecialchars($a['full_name']); ?>
                                                        <form method="POST" class="inline-form" onsubmit="return confirm('Unassign this teacher?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                            <input type="hidden" name="action" value="unassign_teacher">
                                                            <input type="hidden" name="class_id" value="<?php echo intval($c['id']); ?>">
                                                            <input type="hidden" name="teacher_id" value="<?php echo intval($a['teacher_id']); ?>">
                                                            <button type="submit" class="btn small danger" title="Unassign">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </form>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="badge badge-warning">No teachers assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions">
                                            <button class="btn small warning" onclick="openEditModal(<?php echo intval($c['id']); ?>, '<?php echo addslashes(htmlspecialchars($c['class_name'])); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>

                                            <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this class?\n\nThis action cannot be undone and will remove all teacher assignments.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="action" value="delete_class">
                                                <input type="hidden" name="class_id" value="<?php echo intval($c['id']); ?>">
                                                <button type="submit" class="btn small danger">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
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

    <!-- Edit Class Modal -->
    <div id="editModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Class</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="update_class">
                    <input type="hidden" name="class_id" id="edit_class_id">

                    <div class="form-group">
                        <label for="edit_class_name">Class Name *</label>
                        <input type="text" name="class_name" id="edit_class_name" class="form-control" required placeholder="Enter class name">
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn secondary" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openEditModal(id, name) {
            document.getElementById('edit_class_id').value = id;
            document.getElementById('edit_class_name').value = name;
            document.getElementById('editModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('editForm').reset();
        }

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

        // Confirmation functions
        function confirmDeleteClass() {
            return confirm("Are you sure you want to delete this class?\n\nThis action cannot be undone and will remove all teacher assignments for this class.");
        }

        function refreshPage() {
            window.location.reload();
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (this.id === 'editModal') closeEditModal();
                }
            });
        });

        // Form submission with loading state
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.classList.add('loading');
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    submitBtn.disabled = true;
                }
            });
        });

        // Add smooth scroll to top when page loads
        window.addEventListener('load', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Add ripple effect to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                ripple.style.position = 'absolute';
                ripple.style.borderRadius = '50%';
                ripple.style.background = 'rgba(255, 255, 255, 0.3)';
                ripple.style.transform = 'scale(0)';
                ripple.style.animation = 'ripple 0.6s linear';
                ripple.style.left = (e.offsetX - 10) + 'px';
                ripple.style.top = (e.offsetY - 10) + 'px';
                ripple.style.width = '20px';
                ripple.style.height = '20px';

                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);

                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add CSS animation for ripple
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Add intersection observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Initially hide elements for animation
        document.querySelectorAll('.panel, .stat-card, .alert').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'all 0.6s ease';
            observer.observe(el);
        });

        // Keyboard navigation for modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
