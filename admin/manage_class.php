<?php
session_start();
require_once '../config/db.php';

// Only principal/admin
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_name = $_SESSION['full_name'];

// Helpers
function flash($k, $v = null) {
    if ($v === null) {
        $v = $_SESSION['flash'][$k] ?? null;
        unset($_SESSION['flash'][$k]);
        return $v;
    }
    $_SESSION['flash'][$k] = $v;
}

$errors = [];
$success = null;

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
                $stmt = $pdo->prepare("INSERT INTO classes (class_name, created_at) VALUES (:name,  NOW())");
                $stmt->execute(['name' => $name]);
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
                $stmt = $pdo->prepare("UPDATE classes SET class_name = :name,  updated_at = NOW() WHERE id = :id");
                $stmt->execute(['name' => $name, 'id' => $id]);
                flash('success', 'Class updated successfully!');
                header("Location: manage_class.php");
                exit;
            }
        }

        if ($action === 'delete_class') {
            $id = intval($_POST['class_id'] ?? 0);
            if ($id <= 0) $errors[] = "Invalid class ID.";
            if (empty($errors)) {
                // check dependencies: students, subject_assignments, results
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = :id");
                $stmt->execute(['id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = "Cannot delete class with students assigned. Reassign or remove students first.";
                } else {
                    // safe to delete mappings then class
                    $pdo->prepare("DELETE FROM class_teachers WHERE class_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM classes WHERE id = :id")->execute(['id' => $id]);
                    flash('success', 'Class deleted successfully.');
                    header("Location: manage_class.php");
                    exit;
                }
            }
        }

        if ($action === 'assign_teacher') {
            $class_id = intval($_POST['class_id'] ?? 0);
            $teacher_id = intval($_POST['teacher_id'] ?? 0);
            if ($class_id <= 0 || $teacher_id <= 0) $errors[] = "Invalid class or teacher selection.";
            if (empty($errors)) {
                // ensure teacher exists and role = teacher
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :tid AND role = 'teacher'");
                $stmt->execute(['tid' => $teacher_id]);
                if ($stmt->fetchColumn() == 0) {
                    $errors[] = "Selected teacher not found or not a valid teacher account.";
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

        if ($action === 'unassign_teacher') {
            $class_id = intval($_POST['class_id'] ?? 0);
            $teacher_id = intval($_POST['teacher_id'] ?? 0);
            if ($class_id <= 0 || $teacher_id <= 0) $errors[] = "Invalid class or teacher.";
            if (empty($errors)) {
                $stmt = $pdo->prepare("DELETE FROM class_teachers WHERE class_id = :cid AND teacher_id = :tid");
                $stmt->execute(['cid' => $class_id, 'tid' => $teacher_id]);
                flash('success', 'Teacher unassigned successfully.');
                header("Location: manage_class.php");
                exit;
            }
        }
    }
}

// Load data for display
try {
    $classes = $pdo->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $teachers = $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'teacher' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Load assignments mapping with proper error handling
    $assignments = [];
    $stmt = $pdo->query("SELECT ct.class_id, u.id AS teacher_id, u.full_name, u.email FROM class_teachers ct JOIN users u ON ct.teacher_id = u.id WHERE u.role = 'teacher'");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignments[$row['class_id']][] = $row;
        }
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
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css">
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
        <?php include '../includes/admin_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2><i class="fas fa-chalkboard-teacher"></i> Manage Classes</h2>
                    <p>Create, edit, and assign teachers to classes</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo count($classes); ?></span>
                        <span class="quick-stat-label">Classes</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo count($teachers); ?></span>
                        <span class="quick-stat-label">Teachers</span>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (!empty($success)): ?>
                <div class="alert alert-success" style="padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 12px; background-color: #d1fae5; color: #065f46; border-left: 4px solid #10b981;">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" style="padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 12px; background-color: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444;">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <?php foreach ($errors as $e): ?>
                            <div><?php echo htmlspecialchars($e); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">🎓</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Classes</h3>
                        <p class="card-value"><?php echo count($classes); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Active Classes</span>
                            <a href="#class-list" class="card-link">View All →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">👨‍🏫</div>
                    </div>
                    <div class="card-content">
                        <h3>Available Teachers</h3>
                        <p class="card-value"><?php echo count($teachers); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">For Assignment</span>
                            <a href="manage_user.php" class="card-link">Manage →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📊</div>
                    </div>
                    <div class="card-content">
                        <h3>Assignments</h3>
                        <p class="card-value">
                            <?php
                            $total_assignments = 0;
                            foreach ($assignments as $class_assignments) {
                                $total_assignments += count($class_assignments);
                            }
                            echo $total_assignments;
                            ?>
                        </p>
                        <div class="card-footer">
                            <span class="card-badge">Teacher-Class Links</span>
                            <a href="#assignments" class="card-link">Review →</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Forms and Class List Grid -->
            <div class="stats-section" style="padding: 30px;">
                <div class="section-header">
                    <h3>📝 Class Management</h3>
                    <span class="section-badge">Forms & List</span>
                </div>
                <div class="dashboard-grid" style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; margin-top: 1rem;">
                    <!-- Left Column: Forms -->
                    <div>
                        <!-- Create Class Card -->
                        <div class="card" style="margin-bottom: 2rem;">
                            <div class="card-header">
                                <h2 class="card-title" style="font-size: 1.4rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                                    <i class="fas fa-plus-circle" style="color: #FFD700;"></i> Create New Class
                                </h2>
                            </div>
                            <form method="POST" id="createForm" style="padding: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="create_class">

                                <div class="form-group" style="margin-bottom: 1.25rem;">
                                    <label class="form-label" for="class_name" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e293b; font-size: 0.95rem;">Class Name *</label>
                                    <input type="text" class="form-input" name="class_name" id="class_name" required placeholder="e.g., Grade 10 Science" style="width: 100%; padding: 0.875rem 1rem; border: 2px solid #cbd5e1; border-radius: 8px; font-size: 1rem; transition: all 0.3s; background-color: white;">
                                </div>

                                <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0.875rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; font-size: 1rem; cursor: pointer; transition: all 0.3s; background: linear-gradient(135deg, #FFD700, #B8860B); color: white;">
                                    <i class="fas fa-save"></i> Create Class
                                </button>
                            </form>
                        </div>

                        <!-- Assign Teacher Card -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title" style="font-size: 1.4rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                                    <i class="fas fa-user-plus" style="color: #FFD700;"></i> Assign Teacher to Class
                                </h2>
                            </div>
                            <form method="POST" id="assignForm" style="padding: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="assign_teacher">

                                <div class="form-group" style="margin-bottom: 1.25rem;">
                                    <label class="form-label" for="assign_class_id" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e293b; font-size: 0.95rem;">Select Class *</label>
                                    <select class="form-select" name="class_id" id="assign_class_id" required style="width: 100%; padding: 0.875rem 1rem; border: 2px solid #cbd5e1; border-radius: 8px; font-size: 1rem; transition: all 0.3s; background-color: white;">
                                        <option value="">-- Choose a class --</option>
                                        <?php foreach ($classes as $c): ?>
                                            <option value="<?php echo intval($c['id']); ?>">
                                                <?php echo htmlspecialchars($c['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group" style="margin-bottom: 1.25rem;">
                                    <label class="form-label" for="assign_teacher_id" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e293b; font-size: 0.95rem;">Select Teacher *</label>
                                    <select class="form-select" name="teacher_id" id="assign_teacher_id" required style="width: 100%; padding: 0.875rem 1rem; border: 2px solid #cbd5e1; border-radius: 8px; font-size: 1rem; transition: all 0.3s; background-color: white;">
                                        <option value="">-- Choose a teacher --</option>
                                        <?php foreach ($teachers as $t): ?>
                                            <option value="<?php echo intval($t['id']); ?>">
                                                <?php echo htmlspecialchars($t['full_name']); ?>
                                                <?php if (!empty($t['email'])): ?>
                                                    (<?php echo htmlspecialchars($t['email']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0.875rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; font-size: 1rem; cursor: pointer; transition: all 0.3s; background: linear-gradient(135deg, #FFD700, #B8860B); color: white;">
                                    <i class="fas fa-link"></i> Assign Teacher
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Right Column: Classes List -->
                    <div id="class-list">
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title" style="font-size: 1.4rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                                    <i class="fas fa-list-alt" style="color: #FFD700;"></i> Class List
                                </h2>
                                <div class="action-buttons" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                                    <button class="btn btn-secondary btn-small" onclick="refreshPage()" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.3s; background: #e2e8f0; color: #1e293b;">
                                        <i class="fas fa-sync-alt"></i> Refresh
                                    </button>
                                </div>
                            </div>

                            <?php if (empty($classes)): ?>
                                <div class="empty-state" style="text-align: center; padding: 3rem 1rem; color: #64748b;">
                                    <i class="fas fa-chalkboard" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                                    <h3 style="font-size: 1.3rem; margin-bottom: 0.5rem;">No Classes Found</h3>
                                    <p>Create your first class using the form on the left.</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x: auto;">
                                    <table class="classes-table" style="width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 1rem;">
                                        <thead>
                                            <tr>
                                                <th style="background-color: #f1f5f9; padding: 1rem; text-align: left; font-weight: 700; color: #1e293b; border-bottom: 2px solid #cbd5e1;">Class Details</th>
                                                <th style="background-color: #f1f5f9; padding: 1rem; text-align: left; font-weight: 700; color: #1e293b; border-bottom: 2px solid #cbd5e1;">Assigned Teachers</th>
                                                <th style="background-color: #f1f5f9; padding: 1rem; text-align: left; font-weight: 700; color: #1e293b; border-bottom: 2px solid #cbd5e1; width: 180px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($classes as $c): ?>
                                                <tr style="transition: background-color 0.2s;">
                                                    <td style="padding: 1.25rem 1rem; border-bottom: 1px solid #e2e8f0; vertical-align: top;">
                                                        <div class="class-name" style="font-weight: 700; font-size: 1.1rem; color: #FFD700; margin-bottom: 0.25rem;"><?php echo htmlspecialchars($c['class_name']); ?></div>
                                                        <div class="small" style="color: #64748b; font-size: 0.85rem; margin-top: 0.5rem;">
                                                            ID: <?php echo $c['id']; ?> | Created: <?php echo date('M j, Y', strtotime($c['created_at'])); ?>
                                                        </div>
                                                    </td>
                                                    <td style="padding: 1.25rem 1rem; border-bottom: 1px solid #e2e8f0; vertical-align: top;">
                                                        <div class="teachers-list" style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.5rem;">
                                                            <?php if (!empty($assignments[$c['id']])): ?>
                                                                <?php foreach ($assignments[$c['id']] as $a): ?>
                                                                    <div class="teacher-tag" style="background: #e0e7ff; color: #4338ca; padding: 0.5rem 0.875rem; border-radius: 50px; font-size: 0.875rem; display: inline-flex; align-items: center; gap: 6px; font-weight: 500;">
                                                                        <i class="fas fa-user-graduate"></i>
                                                                        <?php echo htmlspecialchars($a['full_name']); ?>
                                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Unassign this teacher from the class?')">
                                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                                            <input type="hidden" name="action" value="unassign_teacher">
                                                                            <input type="hidden" name="class_id" value="<?php echo intval($c['id']); ?>">
                                                                            <input type="hidden" name="teacher_id" value="<?php echo intval($a['teacher_id']); ?>">
                                                                            <button type="submit" class="remove-btn" title="Unassign teacher" style="background: none; border: none; color: #ef4444; cursor: pointer; padding: 0; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: background 0.2s;">
                                                                                <i class="fas fa-times"></i>
                                                                            </button>
                                                                        </form>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <div class="no-teachers" style="color: #64748b; font-style: italic; font-size: 0.9rem;">No teachers assigned yet</div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td style="padding: 1.25rem 1rem; border-bottom: 1px solid #e2e8f0; vertical-align: top;">
                                                        <div class="action-buttons" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                                                            <button class="btn btn-secondary btn-small" onclick="openEditModal(<?php echo intval($c['id']); ?>, '<?php echo addslashes(htmlspecialchars($c['class_name'])); ?>', '<?php echo addslashes(htmlspecialchars($c['description'] ?? '')); ?>')" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.3s; background: #e2e8f0; color: #1e293b;">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>

                                                            <form method="POST" onsubmit="return confirmDeleteClass()" style="display: inline;">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                                <input type="hidden" name="action" value="delete_class">
                                                                <input type="hidden" name="class_id" value="<?php echo intval($c['id']); ?>">
                                                                <button type="submit" class="btn btn-danger btn-small" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.3s; background: #ef4444; color: white;">
                                                                    <i class="fas fa-trash-alt"></i> Delete
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
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Edit Class Modal -->
    <div class="modal" id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="modal-content" style="background: white; border-radius: 12px; padding: 2rem; width: 90%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
            <div class="modal-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #e2e8f0;">
                <h2 class="modal-title" style="font-size: 1.5rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-edit" style="color: #FFD700;"></i> Edit Class
                </h2>
                <button class="close-modal" onclick="closeEditModal()" style="background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer; padding: 0.25rem; border-radius: 4px;">&times;</button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="update_class">
                <input type="hidden" name="class_id" id="edit_class_id">

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label" for="edit_class_name" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1e293b; font-size: 0.95rem;">Class Name *</label>
                    <input type="text" class="form-input" name="class_name" id="edit_class_name" required style="width: 100%; padding: 0.875rem 1rem; border: 2px solid #cbd5e1; border-radius: 8px; font-size: 1rem; transition: all 0.3s; background-color: white;">
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0.875rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; font-size: 1rem; cursor: pointer; transition: all 0.3s; background: linear-gradient(135deg, #FFD700, #B8860B); color: white;">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0.875rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; font-size: 1rem; cursor: pointer; transition: all 0.3s; background: #e2e8f0; color: #1e293b;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
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
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        // Edit Modal Functions
        function openEditModal(id, name, desc) {
            document.getElementById('edit_class_id').value = id;
            document.getElementById('edit_class_name').value = name;
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function confirmDeleteClass() {
            return confirm("Are you sure you want to delete this class?\n\nThis action cannot be undone and will remove all teacher assignments for this class.");
        }

        function refreshPage() {
            window.location.reload();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target === modal) {
                closeEditModal();
            }
        }

        // Auto-hide success messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentNode) alert.parentNode.removeChild(alert);
                }, 500);
            });
        }, 5000);

        // Form validation
        document.getElementById('createForm')?.addEventListener('submit', function(e) {
            const className = document.getElementById('class_name').value.trim();
            if (className.length < 2) {
                e.preventDefault();
                alert('Class name must be at least 2 characters long.');
            }
        });

        document.getElementById('assignForm')?.addEventListener('submit', function(e) {
            const classId = document.getElementById('assign_class_id').value;
            const teacherId = document.getElementById('assign_teacher_id').value;

            if (!classId || !teacherId) {
                e.preventDefault();
                alert('Please select both a class and a teacher.');
            }
        });
    </script>

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

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
