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
<link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body>
<main class="main-content">
    <h2>Subjects - Teacher</h2>
    <?php if($errors): ?><div class="alert alert-error"><?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?></div><?php endif; ?>
    <?php if($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section>
        <h3>Create Subject (optional)</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <input name="name" placeholder="Subject name" required>
            <input name="code" placeholder="Code (optional)">
            <input name="description" placeholder="Description">
            <button class="btn-gold">Create</button>
        </form>
    </section>

    <section>
        <h3>All Subjects</h3>
        <table class="results-table">
            <thead><tr><th>#</th><th>Name</th><th>Code</th><th>Owned</th><th>Assigned to Me</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach($all_subjects as $s): ?>
                <tr>
                    <td><?php echo intval($s['id']); ?></td>
                    <td><?php echo htmlspecialchars($s['subject_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['subject_code']); ?></td>
                    <td><?php echo ($s['created_by'] == $uid) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo in_array($s['id'], $assigned_subject_ids) ? 'Yes' : 'No'; ?></td>
                    <td>
                        <?php if($s['created_by'] == $uid): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                            <input name="name" value="<?php echo htmlspecialchars($s['subject_name']); ?>">
                            <input name="code" value="<?php echo htmlspecialchars($s['subject_code']); ?>">
                            <button class="btn-small">Save</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                            <button class="btn-delete">Delete</button>
                        </form>
                        <?php else: ?>
                            <span class="small-muted">No actions</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section>
        <h3>My Assignments</h3>
        <?php if(empty($assignments)): ?><p class="small-muted">No subjects assigned.</p><?php else: ?>
            <ul>
                <?php foreach($assignments as $a): ?>
                    <li><?php echo htmlspecialchars($a['class_name'] . ' — Subject ID: ' . $a['subject_id']); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
</body>
</html>