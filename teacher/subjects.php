<?php
session_start();
require_once '../config/db.php';

// Only teachers
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
$uid = $_SESSION['user_id'];
$errors = [];
$success = '';

// Handle create/update/delete for teachers (teacher-created subjects)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        if ($name === '') $errors[] = 'Subject name required.';
        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, subject_code, description, created_by, created_at) VALUES (:name,:code,:desc,:uid,NOW())");
            $stmt->execute(['name'=>$name,'code'=>$code,'desc'=>trim($_POST['description'] ?? ''),'uid'=>$uid]);
            $success = 'Subject created.';
            header("Location: subjects.php");
            exit;
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0) $errors[] = 'Invalid id.';
        if ($name === '') $errors[] = 'Name required.';
        if (empty($errors)) {
            // ensure teacher is owner or allow updating any? keep simple: owner only
            $stmt = $pdo->prepare("SELECT created_by FROM subjects WHERE id = :id");
            $stmt->execute(['id'=>$id]);
            if ($stmt->fetchColumn() != $uid) { $errors[] = 'Access denied.'; }
            else {
                $pdo->prepare("UPDATE subjects SET subject_name = :name, subject_code = :code, description = :desc, updated_at = NOW() WHERE id = :id")
                    ->execute(['name'=>$name,'code'=>trim($_POST['code'] ?? ''),'desc'=>trim($_POST['description'] ?? ''),'id'=>$id]);
                $success = 'Updated.';
                header("Location: subjects.php");
                exit;
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT created_by FROM subjects WHERE id = :id");
            $stmt->execute(['id'=>$id]);
            if ($stmt->fetchColumn() != $uid) { $errors[] = 'Access denied.'; }
            else {
                $pdo->prepare("DELETE FROM subject_assignments WHERE subject_id = :id")->execute(['id'=>$id]);
                $pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute(['id'=>$id]);
                $success = 'Deleted.';
                header("Location: subjects.php");
                exit;
            }
        }
    }
}

// Fetch subjects: show all subjects and mark which are assigned to teacher's classes
$all_subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name")->fetchAll(PDO::FETCH_ASSOC);
$my_assignments = $pdo->prepare("SELECT sa.id, sa.subject_id, sa.class_id, c.class_name FROM subject_assignments sa JOIN classes c ON sa.class_id = c.id WHERE sa.teacher_id = :uid ORDER BY c.class_name");
$my_assignments->execute(['uid'=>$uid]);
$assignments = $my_assignments->fetchAll(PDO::FETCH_ASSOC);
$assigned_subject_ids = array_column($assignments, 'subject_id');
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Subjects - Teacher</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/subjects.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="dashboard-container">
    <main class="main-content">
        <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

        <div class="subjects-container">
            <div class="content-header">
                <h1><i class="fas fa-book"></i> Subject Management</h1>
                <p>Manage your subjects and assignments</p>
            </div>

            <?php if($errors): ?>
                <div class="alert alert-error">
                    <?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?>
                </div>
            <?php endif; ?>

            <?php if($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Create Subject Section -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-plus-circle"></i> Create New Subject</h3>
                    <p>Optional: Create your own subjects if needed</p>
                </div>
                <form method="POST" class="create-form">
                    <input type="hidden" name="action" value="create">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="subject_name"><i class="fas fa-tag"></i> Subject Name *</label>
                            <input type="text" id="subject_name" name="name" placeholder="Enter subject name" required>
                        </div>
                        <div class="form-group">
                            <label for="subject_code"><i class="fas fa-code"></i> Subject Code</label>
                            <input type="text" id="subject_code" name="code" placeholder="Optional code">
                        </div>
                        <div class="form-group full-width">
                            <label for="subject_desc"><i class="fas fa-file-alt"></i> Description</label>
                            <textarea id="subject_desc" name="description" placeholder="Subject description" rows="3"></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary"><i class="fas fa-plus"></i> Create Subject</button>
                </form>
            </div>

            <!-- All Subjects Section -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-list"></i> All Subjects</h3>
                    <p>View and manage all available subjects</p>
                </div>
                <div class="table-responsive">
                    <table class="subjects-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-hashtag"></i> ID</th>
                                <th><i class="fas fa-book"></i> Subject Name</th>
                                <th><i class="fas fa-code"></i> Code</th>
                                <th><i class="fas fa-user"></i> Owned</th>
                                <th><i class="fas fa-check-circle"></i> Assigned</th>
                                <th><i class="fas fa-cogs"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($all_subjects as $s): ?>
                            <tr>
                                <td><?php echo intval($s['id']); ?></td>
                                <td><?php echo htmlspecialchars($s['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['subject_code'] ?: '-'); ?></td>
                                <td>
                                    <span class="badge <?php echo ($s['created_by'] == $uid) ? 'badge-owned' : 'badge-not-owned'; ?>">
                                        <?php echo ($s['created_by'] == $uid) ? '<i class="fas fa-check"></i> Yes' : '<i class="fas fa-times"></i> No'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo in_array($s['id'], $assigned_subject_ids) ? 'badge-assigned' : 'badge-not-assigned'; ?>">
                                        <?php echo in_array($s['id'], $assigned_subject_ids) ? '<i class="fas fa-check"></i> Yes' : '<i class="fas fa-times"></i> No'; ?>
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <div class="action-buttons">
                                        <button class="btn-edit" onclick="editSubject(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars($s['subject_name']); ?>', '<?php echo htmlspecialchars($s['subject_code']); ?>', '<?php echo htmlspecialchars($s['description'] ?? ''); ?>')" <?php echo ($s['created_by'] != $uid) ? 'title="You can only edit subjects you created"' : ''; ?>>
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this subject?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                                            <button type="submit" class="btn-delete" <?php echo ($s['created_by'] != $uid) ? 'title="You can only delete subjects you created" disabled' : ''; ?>>
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
            </div>

            <!-- My Assignments Section -->
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="fas fa-user-check"></i> My Subject Assignments</h3>
                    <p>Subjects assigned to your classes</p>
                </div>
                <?php if(empty($assignments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h4>No Assignments</h4>
                        <p>You haven't been assigned to any subjects yet.</p>
                    </div>
                <?php else: ?>
                    <div class="assignments-grid">
                        <?php foreach($assignments as $a): ?>
                            <div class="assignment-card">
                                <div class="assignment-icon">
                                    <i class="fas fa-graduation-cap"></i>
                                </div>
                                <div class="assignment-details">
                                    <h4><?php echo htmlspecialchars($a['class_name']); ?></h4>
                                    <p>Subject ID: <?php echo htmlspecialchars($a['subject_id']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Edit Modal (Hidden by default) -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-edit"></i> Edit Subject</h3>
                    <span class="close-modal" onclick="closeModal()">&times;</span>
                </div>
                <form method="POST" class="edit-form">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_name"><i class="fas fa-tag"></i> Subject Name *</label>
                            <input type="text" id="edit_name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_code"><i class="fas fa-code"></i> Subject Code</label>
                            <input type="text" id="edit_code" name="code">
                        </div>
                        <div class="form-group full-width">
                            <label for="edit_desc"><i class="fas fa-file-alt"></i> Description</label>
                            <textarea id="edit_desc" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="btn-primary">Update Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<script>
function editSubject(id, name, code, description) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_code').value = code;
    document.getElementById('edit_desc').value = description || '';
    document.getElementById('editModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>
</body>
</html>
