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
            $success = 'Subject created.';
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
            $success = 'Subject updated.';
            header("Location: subjects.php");
            exit;
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            // remove assignments first
            $pdo->prepare("DELETE FROM subject_assignments WHERE subject_id = :id")->execute(['id'=>$id]);
            $pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute(['id'=>$id]);
            $success = 'Subject deleted.';
            header("Location: subjects.php");
            exit;
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
                $success = 'Assigned successfully.';
                header("Location: subjects.php");
                exit;
            }
        }
    } elseif ($action === 'unassign') {
        $assign_id = intval($_POST['assign_id'] ?? 0);
        if ($assign_id > 0) {
            $pdo->prepare("DELETE FROM subject_assignments WHERE id = :id")->execute(['id'=>$assign_id]);
            $success = 'Unassigned.';
            header("Location: subjects.php");
            exit;
        }
    }
}

// Fetch subjects and assignments
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$assignments = $pdo->query("SELECT sa.id AS assign_id, sa.subject_id, sa.class_id, sa.teacher_id, c.class_name, u.full_name as teacher_name, s.subject_name
    FROM subject_assignments sa
    JOIN classes c ON sa.class_id = c.id
    JOIN users u ON sa.teacher_id = u.id
    JOIN subjects s ON sa.subject_id = s.id
    ORDER BY c.class_name, s.subject_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Manage Subjects - Admin</title>
<link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body>
<main class="main-content">
    <h2>Subjects - Admin</h2>

    <?php if($errors): ?><div class="alert alert-error"><?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?></div><?php endif; ?>
    <?php if($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section>
        <h3>Create Subject</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <input name="name" placeholder="Subject name" required>
            <input name="code" placeholder="Code (optional)">
            <input name="description" placeholder="Short description">
            <button class="btn-gold">Create</button>
        </form>
    </section>

    <section>
        <h3>All Subjects</h3>
        <table class="results-table">
            <thead><tr><th>#</th><th>Name</th><th>Code</th><th>Created By</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach($subjects as $s): ?>
                <tr>
                    <td><?php echo intval($s['id']); ?></td>
                    <td><?php echo htmlspecialchars($s['subject_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['subject_code']); ?></td>
                    <td><?php
                        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :id");
                        $stmt->execute(['id'=>$s['created_by']]);
                        echo htmlspecialchars($stmt->fetchColumn() ?: 'System');
                    ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                            <input name="name" value="<?php echo htmlspecialchars($s['subject_name']); ?>" required>
                            <input name="code" value="<?php echo htmlspecialchars($s['subject_code']); ?>">
                            <input name="description" value="<?php echo htmlspecialchars($s['description']); ?>">
                            <button class="btn-small">Save</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                            <button class="btn-delete">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section>
        <h3>Assignments</h3>
        <form method="POST">
            <input type="hidden" name="action" value="assign">
            <select name="subject_id" required>
                <option value="">Subject</option>
                <?php foreach($subjects as $s): ?><option value="<?php echo intval($s['id']); ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option><?php endforeach; ?>
            </select>
            <select name="class_id" required>
                <option value="">Class</option>
                <?php foreach($classes as $c): ?><option value="<?php echo intval($c['id']); ?>"><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
            </select>
            <select name="teacher_id" required>
                <option value="">Teacher</option>
                <?php foreach($teachers as $t): ?><option value="<?php echo intval($t['id']); ?>"><?php echo htmlspecialchars($t['full_name']); ?></option><?php endforeach; ?>
            </select>
            <button class="btn-gold">Assign</button>
        </form>

        <table class="results-table" style="margin-top:12px;">
            <thead><tr><th>#</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach($assignments as $a): ?>
                <tr>
                    <td><?php echo intval($a['assign_id']); ?></td>
                    <td><?php echo htmlspecialchars($a['class_name']); ?></td>
                    <td><?php echo htmlspecialchars($a['subject_name']); ?></td>
                    <td><?php echo htmlspecialchars($a['teacher_name']); ?></td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Unassign?');">
                            <input type="hidden" name="action" value="unassign">
                            <input type="hidden" name="assign_id" value="<?php echo intval($a['assign_id']); ?>">
                            <button class="btn-delete">Unassign</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>