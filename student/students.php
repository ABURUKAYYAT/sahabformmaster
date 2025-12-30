<?php
session_start();
require_once '../config/db.php';

$uid = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;
if (!$uid && !$admission_no) {
    header("Location: ../index.php");
    exit;
}

// Resolve student record
$student = null;
if ($admission_no) {
    $stmt = $pdo->prepare("SELECT id, full_name, class_id, phone, address FROM students WHERE admission_no=:ad LIMIT 1");
    $stmt->execute(['ad'=>$admission_no]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$student && $uid) {
    $stmt = $pdo->prepare("SELECT id, full_name, class_id, phone, address FROM students WHERE user_id=:uid OR id=:uid LIMIT 1");
    $stmt->execute(['uid'=>$uid]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$student) { echo "Student not found"; exit; }

$errors = []; $success = '';
// update contact or submit complaint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_contact') {
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $stmt = $pdo->prepare("UPDATE students SET phone=:phone,address=:address,updated_at=NOW() WHERE id=:id");
        $stmt->execute(['phone'=>$phone,'address'=>$address,'id'=>$student['id']]);
        $success = 'Contact updated.';
    } elseif ($action === 'submit_complaint') {
        $result_id = intval($_POST['result_id'] ?? 0);
        $text = trim($_POST['complaint_text'] ?? '');
        if ($result_id<=0 || $text==='') $errors[]='Please select result and enter complaint.';
        else {
            // ensure result belongs to student
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE id=:rid AND student_id=:sid");
            $stmt->execute(['rid'=>$result_id,'sid'=>$student['id']]);
            if ($stmt->fetchColumn()==0) $errors[]='Result not found.';
            else {
                $pdo->prepare("INSERT INTO results_complaints (result_id, student_id, complaint_text, status, created_at) VALUES (:rid,:sid,:text,'pending',NOW())")
                    ->execute(['rid'=>$result_id,'sid'=>$student['id'],'text'=>$text]);
                $success = 'Complaint submitted.';
            }
        }
    }
}

// Fetch results
$term = $_GET['term'] ?? null;
$sql = "SELECT r.*, s.subject_name FROM results r JOIN subjects s ON r.subject_id = s.id WHERE r.student_id = :sid";
$params = ['sid'=>$student['id']];
if ($term) { $sql .= " AND r.term = :term"; $params['term']=$term; }
$sql .= " ORDER BY s.subject_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch complaints
$stmt = $pdo->prepare("SELECT rc.*, sub.subject_name, r.term FROM results_complaints rc JOIN results r ON rc.result_id=r.id JOIN subjects sub ON r.subject_id=sub.id WHERE rc.student_id=:sid ORDER BY rc.created_at DESC");
$stmt->execute(['sid'=>$student['id']]);
$complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Student — My Profile</title>
<link rel="stylesheet" href="../assets/css/student-students.css">
</head>
<body>
<header class="topbar">
    <h1>Student Portal</h1>
    <div class="user"><?php echo htmlspecialchars($student['full_name']); ?></div>
</header>
<main class="container">
    <?php if($errors): ?><div class="alert alert-error"><?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?></div><?php endif; ?>
    <?php if($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <section class="panel">
        <h2>Profile</h2>
        <p>Class: <?php echo htmlspecialchars($pdo->query("SELECT class_name FROM classes WHERE id=".intval($student['class_id']))->fetchColumn() ?: 'N/A'); ?></p>
        <form method="POST" class="form-inline">
            <input type="hidden" name="action" value="update_contact">
            <input name="phone" placeholder="Phone" value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>">
            <input name="address" placeholder="Address" value="<?php echo htmlspecialchars($student['address'] ?? ''); ?>">
            <button class="btn">Save</button>
        </form>
    </section>

    <section class="panel">
        <h2>My Results</h2>
        <?php if(empty($results)): ?>
            <p class="small">No results available.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>Subject</th><th>1st CA</th><th>2nd CA</th><th>Exam</th><th>Total</th><th>Grade</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach($results as $i=>$r): 
                        $ca = $r['first_ca']+$r['second_ca'];
                        $total = $ca + $r['exam'];
                        $grade = $total>=90?'A':($total>=80?'B':($total>=70?'C':($total>=60?'D':($total>=50?'E':'F'))));
                    ?>
                        <tr>
                            <td><?php echo $i+1; ?></td>
                            <td><?php echo htmlspecialchars($r['subject_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['first_ca']); ?></td>
                            <td><?php echo htmlspecialchars($r['second_ca']); ?></td>
                            <td><?php echo htmlspecialchars($r['exam']); ?></td>
                            <td><?php echo $total; ?></td>
                            <td><?php echo $grade; ?></td>
                            <td>
                                <button onclick="toggleComplaint(<?php echo intval($r['id']); ?>)" class="btn small">Complain</button>
                                <form method="POST" action="../teacher/generate-result-pdf.php" style="display:inline;">
                                    <input type="hidden" name="student_id" value="<?php echo intval($student['id']); ?>">
                                    <input type="hidden" name="class_id" value="<?php echo intval($student['class_id']); ?>">
                                    <input type="hidden" name="term" value="<?php echo htmlspecialchars($r['term']); ?>">
                                    <button class="btn small">PDF</button>
                                </form>
                                <div id="complaint-<?php echo intval($r['id']); ?>" class="complaint-box hidden">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="submit_complaint">
                                        <input type="hidden" name="result_id" value="<?php echo intval($r['id']); ?>">
                                        <textarea name="complaint_text" rows="3" placeholder="Explain your complaint" required></textarea>
                                        <button class="btn">Submit</button>
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

    <section class="panel">
        <h2>My Complaints</h2>
        <?php if(empty($complaints)): ?><p class="small">No complaints.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>#</th><th>Subject</th><th>Complaint</th><th>Status</th><th>Response</th></tr></thead>
                <tbody>
                <?php foreach($complaints as $i=>$c): ?>
                    <tr>
                        <td><?php echo $i+1; ?></td>
                        <td><?php echo htmlspecialchars($c['subject_name']); ?></td>
                        <td><?php echo htmlspecialchars($c['complaint_text']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($c['status'])); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($c['teacher_response'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>

<script>
function toggleComplaint(id){
    var el = document.getElementById('complaint-'+id);
    if(!el) return;
    el.classList.toggle('hidden');
}
</script>
</body>
</html>