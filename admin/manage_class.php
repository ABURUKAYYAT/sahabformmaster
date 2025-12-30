<?php
session_start();
require_once '../config/db.php';

// Only principal/admin
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

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
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Classes — School Admin System</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root {
    --primary: #4f46e5;
    --primary-dark: #4338ca;
    --secondary: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --light: #f8fafc;
    --dark: #1e293b;
    --gray: #64748b;
    --gray-light: #e2e8f0;
    --border: #cbd5e1;
    --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --radius: 12px;
    --radius-sm: 8px;
  }

  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
    color: var(--dark);
    line-height: 1.6;
    min-height: 100vh;
  }

  .container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
  }

  header {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: 1.5rem 0;
    box-shadow: var(--shadow);
    position: sticky;
    top: 0;
    z-index: 100;
  }

  .header-content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .logo {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .logo i {
    font-size: 1.8rem;
  }

  .logo h1 {
    font-size: 1.5rem;
    font-weight: 700;
  }

  .user-info {
    font-size: 0.9rem;
    opacity: 0.9;
  }

  .alert {
    padding: 1rem;
    border-radius: var(--radius-sm);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: fadeIn 0.5s ease;
  }

  .alert-success {
    background-color: #d1fae5;
    color: #065f46;
    border-left: 4px solid var(--secondary);
  }

  .alert-error {
    background-color: #fee2e2;
    color: #991b1b;
    border-left: 4px solid var(--danger);
  }

  .alert i {
    font-size: 1.2rem;
  }

  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-top: 1rem;
  }

  @media (max-width: 1024px) {
    .dashboard-grid {
      grid-template-columns: 1fr;
    }
  }

  .card {
    background: white;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 1.75rem;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
  }

  .card:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
  }

  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--gray-light);
  }

  .card-title {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .card-title i {
    color: var(--primary);
  }

  .form-group {
    margin-bottom: 1.25rem;
  }

  .form-label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: var(--dark);
    font-size: 0.95rem;
  }

  .form-input, .form-textarea, .form-select {
    width: 100%;
    padding: 0.875rem 1rem;
    border: 2px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 1rem;
    transition: all 0.3s;
    background-color: white;
  }

  .form-input:focus, .form-textarea:focus, .form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
  }

  .form-textarea {
    min-height: 100px;
    resize: vertical;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 0.875rem 1.5rem;
    border: none;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
  }

  .btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
  }

  .btn-primary:hover {
    background: linear-gradient(135deg, var(--primary-dark), #3730a3);
    transform: translateY(-2px);
  }

  .btn-secondary {
    background: var(--gray-light);
    color: var(--dark);
  }

  .btn-secondary:hover {
    background: #d1d5db;
  }

  .btn-danger {
    background: var(--danger);
    color: white;
  }

  .btn-danger:hover {
    background: #dc2626;
  }

  .btn-small {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
  }

  .btn-icon {
    padding: 0.5rem;
    border-radius: 50%;
    width: 36px;
    height: 36px;
  }

  .action-buttons {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
  }

  .classes-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin-top: 1rem;
  }

  .classes-table th {
    background-color: #f1f5f9;
    padding: 1rem;
    text-align: left;
    font-weight: 700;
    color: var(--dark);
    border-bottom: 2px solid var(--border);
  }

  .classes-table td {
    padding: 1.25rem 1rem;
    border-bottom: 1px solid var(--gray-light);
    vertical-align: top;
  }

  .classes-table tr:hover {
    background-color: #f8fafc;
  }

  .class-name {
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--primary-dark);
    margin-bottom: 0.25rem;
  }

  .class-description {
    color: var(--gray);
    font-size: 0.9rem;
    margin-top: 0.25rem;
  }

  .teachers-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 0.5rem;
  }

  .teacher-tag {
    background: #e0e7ff;
    color: var(--primary-dark);
    padding: 0.5rem 0.875rem;
    border-radius: 50px;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
  }

  .teacher-tag .remove-btn {
    background: none;
    border: none;
    color: var(--danger);
    cursor: pointer;
    padding: 0;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
  }

  .teacher-tag .remove-btn:hover {
    background: rgba(239, 68, 68, 0.1);
  }

  .no-teachers {
    color: var(--gray);
    font-style: italic;
    font-size: 0.9rem;
  }

  .stats-card {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border-radius: var(--radius);
    padding: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
  }

  .stats-content h3 {
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
    opacity: 0.9;
  }

  .stats-number {
    font-size: 2.5rem;
    font-weight: 800;
    line-height: 1;
  }

  .stats-icon {
    font-size: 3rem;
    opacity: 0.2;
  }

  .empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--gray);
  }

  .empty-state i {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.3;
  }

  .empty-state h3 {
    font-size: 1.3rem;
    margin-bottom: 0.5rem;
  }

  .modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s ease;
  }

  .modal-content {
    background: white;
    border-radius: var(--radius);
    padding: 2rem;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    animation: slideUp 0.4s ease;
  }

  @keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--gray-light);
  }

  .modal-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .close-modal {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--gray);
    cursor: pointer;
    padding: 0.25rem;
    border-radius: 4px;
  }

  .close-modal:hover {
    background-color: var(--gray-light);
    color: var(--dark);
  }

  @media (max-width: 768px) {
    .container {
      padding: 15px;
    }
    
    .header-content {
      flex-direction: column;
      align-items: flex-start;
      gap: 10px;
    }
    
    .card {
      padding: 1.25rem;
    }
    
    .card-title {
      font-size: 1.2rem;
    }
    
    .classes-table {
      display: block;
      overflow-x: auto;
    }
    
    .classes-table th,
    .classes-table td {
      padding: 0.75rem;
    }
    
    .action-buttons {
      flex-direction: column;
      align-items: flex-start;
    }
    
    .btn {
      width: 100%;
      justify-content: center;
    }
  }

  @media (max-width: 480px) {
    .dashboard-grid {
      gap: 1rem;
    }
    
    .card {
      padding: 1rem;
    }
    
    .stats-card {
      flex-direction: column;
      text-align: center;
      gap: 1rem;
    }
    
    .teachers-list {
      flex-direction: column;
    }
  }
</style>
</head>
<body>
<header>
  <div class="header-content">
    <div class="logo">
      <i class="fas fa-chalkboard-teacher"></i>
      <h1>School Admin System</h1>
    </div>
    <div class="user-info">
      <a href="index.php" style="color: white;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
  </div>
</header>

<div class="container">
  <?php if (!empty($success)): ?>
    <div class="alert alert-success">
      <i class="fas fa-check-circle"></i>
      <div><?php echo htmlspecialchars($success); ?></div>
    </div>
  <?php endif; ?>
  
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <div>
        <?php foreach ($errors as $e): ?>
          <div><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="stats-card">
    <div class="stats-content">
      <h3>Total Classes</h3>
      <div class="stats-number"><?php echo count($classes); ?></div>
    </div>
    <div class="stats-icon">
      <i class="fas fa-school"></i>
    </div>
  </div>

  <div class="dashboard-grid">
    <!-- Left Column: Forms -->
    <div>
      <!-- Create Class Card -->
      <div class="card">
        <div class="card-header">
          <h2 class="card-title"><i class="fas fa-plus-circle"></i> Create New Class</h2>
        </div>
        <form method="POST" id="createForm">
          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
          <input type="hidden" name="action" value="create_class">
          
          <div class="form-group">
            <label class="form-label" for="class_name">Class Name *</label>
            <input type="text" class="form-input" name="class_name" id="class_name" required placeholder="e.g., Grade 10 Science">
          </div>
          
          <!-- <div class="form-group">
            <label class="form-label" for="description">Description</label>
            <textarea class="form-textarea" name="description" id="description" rows="3" placeholder="Optional description for this class"></textarea>
          </div>
           -->
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Create Class
          </button>
        </form>
      </div>

      <!-- Assign Teacher Card -->
      <div class="card" style="margin-top: 2rem;">
        <div class="card-header">
          <h2 class="card-title"><i class="fas fa-user-plus"></i> Assign Teacher to Class</h2>
        </div>
        <form method="POST" id="assignForm">
          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
          <input type="hidden" name="action" value="assign_teacher">
          
          <div class="form-group">
            <label class="form-label" for="assign_class_id">Select Class *</label>
            <select class="form-select" name="class_id" id="assign_class_id" required>
              <option value="">-- Choose a class --</option>
              <?php foreach ($classes as $c): ?>
                <option value="<?php echo intval($c['id']); ?>">
                  <?php echo htmlspecialchars($c['class_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label class="form-label" for="assign_teacher_id">Select Teacher *</label>
            <select class="form-select" name="teacher_id" id="assign_teacher_id" required>
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
          
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-link"></i> Assign Teacher
          </button>
        </form>
      </div>
    </div>

    <!-- Right Column: Classes List -->
    <div>
      <div class="card">
        <div class="card-header">
          <h2 class="card-title"><i class="fas fa-list-alt"></i> Class List</h2>
          <div class="action-buttons">
            <button class="btn btn-secondary btn-small" onclick="refreshPage()">
              <i class="fas fa-sync-alt"></i> Refresh
            </button>
          </div>
        </div>
        
        <?php if (empty($classes)): ?>
          <div class="empty-state">
            <i class="fas fa-chalkboard"></i>
            <h3>No Classes Found</h3>
            <p>Create your first class using the form on the left.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="classes-table">
              <thead>
                <tr>
                  <th>Class Details</th>
                  <th>Assigned Teachers</th>
                  <th style="width: 180px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($classes as $c): ?>
                  <tr>
                    <td>
                      <div class="class-name"><?php echo htmlspecialchars($c['class_name']); ?></div>
                      <?php if (!empty($c['description'])): ?>
                        <div class="class-description"><?php echo htmlspecialchars($c['description']); ?></div>
                      <?php endif; ?>
                      <div class="small" style="color: var(--gray); font-size: 0.85rem; margin-top: 0.5rem;">
                        ID: <?php echo $c['id']; ?> | Created: <?php echo date('M j, Y', strtotime($c['created_at'])); ?>
                      </div>
                    </td>
                    <td>
                      <div class="teachers-list">
                        <?php if (!empty($assignments[$c['id']])): ?>
                          <?php foreach ($assignments[$c['id']] as $a): ?>
                            <div class="teacher-tag">
                              <i class="fas fa-user-graduate"></i>
                              <?php echo htmlspecialchars($a['full_name']); ?>
                              <form method="POST" style="display: inline;" onsubmit="return confirm('Unassign this teacher from the class?')">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="unassign_teacher">
                                <input type="hidden" name="class_id" value="<?php echo intval($c['id']); ?>">
                                <input type="hidden" name="teacher_id" value="<?php echo intval($a['teacher_id']); ?>">
                                <button type="submit" class="remove-btn" title="Unassign teacher">
                                  <i class="fas fa-times"></i>
                                </button>
                              </form>
                            </div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <div class="no-teachers">No teachers assigned yet</div>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div class="action-buttons">
                        <button class="btn btn-secondary btn-small" onclick="openEditModal(<?php echo intval($c['id']); ?>, '<?php echo addslashes(htmlspecialchars($c['class_name'])); ?>', '<?php echo addslashes(htmlspecialchars($c['description'] ?? '')); ?>')">
                          <i class="fas fa-edit"></i> Edit
                        </button>
                        
                        <form method="POST" onsubmit="return confirmDeleteClass()" style="display: inline;">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                          <input type="hidden" name="action" value="delete_class">
                          <input type="hidden" name="class_id" value="<?php echo intval($c['id']); ?>">
                          <button type="submit" class="btn btn-danger btn-small">
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

<!-- Edit Class Modal -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title"><i class="fas fa-edit"></i> Edit Class</h2>
      <button class="close-modal" onclick="closeEditModal()">&times;</button>
    </div>
    <form method="POST" id="editForm">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
      <input type="hidden" name="action" value="update_class">
      <input type="hidden" name="class_id" id="edit_class_id">
      
      <div class="form-group">
        <label class="form-label" for="edit_class_name">Class Name *</label>
        <input type="text" class="form-input" name="class_name" id="edit_class_name" required>
      </div>
      
      <!-- <div class="form-group">
        <label class="form-label" for="edit_description">Description</label>
        <textarea class="form-textarea" name="description" id="edit_description" rows="3"></textarea>
      </div>
       -->
      <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-save"></i> Save Changes
        </button>
        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
          <i class="fas fa-times"></i> Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(id, name, desc) {
  document.getElementById('edit_class_id').value = id;
  document.getElementById('edit_class_name').value = name;
  // document.getElementById('edit_description').value = desc;
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
</body>
</html>