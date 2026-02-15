<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$uid = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;
if (!$uid && !$admission_no) {
    header("Location: ../index.php");
    exit;
}

$current_school_id = get_current_school_id();

// Resolve student record
$student = null;
if ($admission_no) {
    $stmt = $pdo->prepare("SELECT id, full_name, class_id, phone, address FROM students WHERE admission_no=:ad AND school_id=:school_id LIMIT 1");
    $stmt->execute(['ad'=>$admission_no, 'school_id'=>$current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$student && $uid) {
    $stmt = $pdo->prepare("SELECT id, full_name, class_id, phone, address FROM students WHERE (user_id=:uid OR id=:uid) AND school_id=:school_id LIMIT 1");
    $stmt->execute(['uid'=>$uid, 'school_id'=>$current_school_id]);
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
        $stmt = $pdo->prepare("UPDATE students SET phone=:phone,address=:address,updated_at=NOW() WHERE id=:id AND school_id=:school_id");
        $stmt->execute(['phone'=>$phone,'address'=>$address,'id'=>$student['id'], 'school_id'=>$current_school_id]);
        $success = 'Contact updated.';
    } elseif ($action === 'submit_complaint') {
        $result_id = intval($_POST['result_id'] ?? 0);
        $text = trim($_POST['complaint_text'] ?? '');
        if ($result_id<=0 || $text==='') $errors[]='Please select result and enter complaint.';
        else {
            // ensure result belongs to student
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE id=:rid AND student_id=:sid AND school_id=:school_id");
            $stmt->execute(['rid'=>$result_id,'sid'=>$student['id'], 'school_id'=>$current_school_id]);
            if ($stmt->fetchColumn()==0) $errors[]='Result not found.';
            else {
                $pdo->prepare("INSERT INTO results_complaints (result_id, student_id, complaint_text, status, created_at, school_id) VALUES (:rid,:sid,:text,'pending',NOW(), :school_id)")
                    ->execute(['rid'=>$result_id,'sid'=>$student['id'],'text'=>$text, 'school_id'=>$current_school_id]);
                $success = 'Complaint submitted.';
            }
        }
    }
}

// Fetch results
$term = $_GET['term'] ?? null;
$sql = "SELECT r.*, s.subject_name FROM results r JOIN subjects s ON r.subject_id = s.id WHERE r.student_id = :sid AND r.school_id = :school_id";
$params = ['sid'=>$student['id'], 'school_id'=>$current_school_id];
if ($term) { $sql .= " AND r.term = :term"; $params['term']=$term; }
$sql .= " ORDER BY s.subject_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch complaints
$stmt = $pdo->prepare("SELECT rc.*, sub.subject_name, r.term FROM results_complaints rc JOIN results r ON rc.result_id=r.id AND r.school_id=:school_id JOIN subjects sub ON r.subject_id=sub.id WHERE rc.student_id=:sid AND rc.school_id=:school_id ORDER BY rc.created_at DESC");
$stmt->execute(['sid'=>$student['id'], 'school_id'=>$current_school_id]);
$complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal — My Profile</title>
    <link rel="stylesheet" href="../assets/css/student-students.css">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <header class="topbar">
        <h1><i class="fas fa-user-graduate"></i> Student Portal</h1>
        <div class="user">
            <i class="fas fa-user-circle"></i>
            <?php echo htmlspecialchars($student['full_name']); ?>
        </div>
    </header>

    <main class="container">
        <?php if($errors): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Profile Section -->
        <section class="panel">
            <h2><i class="fas fa-id-card"></i> My Profile</h2>
            <div class="content">
                <div class="profile-card">
                    <div class="profile-info">
                        <h3><?php echo htmlspecialchars($student['full_name']); ?></h3>
                        <p><?php
                        $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
                        $stmt->execute([intval($student['class_id']), $current_school_id]);
                        echo htmlspecialchars($stmt->fetchColumn() ?: 'N/A');
                        ?></p>
                        <span class="class-badge">
                            <i class="fas fa-graduation-cap"></i>
                            Student ID: <?php echo htmlspecialchars($student['id']); ?>
                        </span>
                    </div>
                </div>

                <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: var(--gray-700);">
                    <i class="fas fa-address-book"></i> Contact Information
                </h3>
                <form method="POST" class="form-inline">
                    <input type="hidden" name="action" value="update_contact">
                    <input name="phone" placeholder="Phone Number" value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>" required>
                    <input name="address" placeholder="Address" value="<?php echo htmlspecialchars($student['address'] ?? ''); ?>" required>
                    <button class="btn success" type="submit">
                        <i class="fas fa-save"></i> Update Contact Info
                    </button>
                </form>
            </div>
        </section>

        <!-- Results Section -->
        <section class="panel">
            <h2><i class="fas fa-chart-bar"></i> My Results</h2>
            <div class="content">
                <?php if(empty($results)): ?>
                    <div style="text-align: center; padding: 3rem; color: var(--gray-500);">
                        <i class="fas fa-chart-line fa-3x" style="margin-bottom: 1rem; opacity: 0.5;"></i>
                        <p>No results available yet.</p>
                        <p class="small">Your academic results will appear here once they are published.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-hashtag"></i> #</th>
                                    <th><i class="fas fa-book"></i> Subject</th>
                                    <th><i class="fas fa-calculator"></i> 1st CA</th>
                                    <th><i class="fas fa-calculator"></i> 2nd CA</th>
                                    <th><i class="fas fa-file-alt"></i> Exam</th>
                                    <th><i class="fas fa-plus-circle"></i> Total</th>
                                    <th><i class="fas fa-graduation-cap"></i> Grade</th>
                                    <th><i class="fas fa-cogs"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach($results as $i=>$r):
                                $ca = $r['first_ca']+$r['second_ca'];
                                $total = $ca + $r['exam'];
                                $grade = $total>=90?'A':($total>=80?'B':($total>=70?'C':($total>=60?'D':($total>=50?'E':'F'))));
                                $gradeClass = 'grade-' . strtolower($grade);
                            ?>
                                <tr>
                                    <td><?php echo $i+1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($r['subject_name']); ?></strong>
                                        <div class="small">Term: <?php echo htmlspecialchars($r['term']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($r['first_ca']); ?>/20</td>
                                    <td><?php echo htmlspecialchars($r['second_ca']); ?>/20</td>
                                    <td><?php echo htmlspecialchars($r['exam']); ?>/60</td>
                                    <td><strong><?php echo $total; ?>/100</strong></td>
                                    <td><span class="<?php echo $gradeClass; ?>"><?php echo $grade; ?></span></td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                            <button onclick="toggleComplaint(<?php echo intval($r['id']); ?>)" class="btn small">
                                                <i class="fas fa-exclamation-triangle"></i> Complain
                                            </button>
                                            <form method="POST" action="../teacher/generate-result-pdf.php" style="display:inline;">
                                                <input type="hidden" name="student_id" value="<?php echo intval($student['id']); ?>">
                                                <input type="hidden" name="class_id" value="<?php echo intval($student['class_id']); ?>">
                                                <input type="hidden" name="term" value="<?php echo htmlspecialchars($r['term']); ?>">
                                                <button class="btn small success" type="submit">
                                                    <i class="fas fa-download"></i> PDF
                                                </button>
                                            </form>
                                        </div>
                                        <div id="complaint-<?php echo intval($r['id']); ?>" class="complaint-box hidden">
                                            <h4 style="margin-bottom: 1rem; color: var(--gray-700);">
                                                <i class="fas fa-comment-dots"></i> Submit Complaint
                                            </h4>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="submit_complaint">
                                                <input type="hidden" name="result_id" value="<?php echo intval($r['id']); ?>">
                                                <textarea name="complaint_text" rows="4" placeholder="Please explain your complaint about this result..." required></textarea>
                                                <button class="btn" type="submit">
                                                    <i class="fas fa-paper-plane"></i> Submit Complaint
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
        </section>

        <!-- Complaints Section -->
        <section class="panel">
            <h2><i class="fas fa-comments"></i> My Complaints</h2>
            <div class="content">
                <?php if(empty($complaints)): ?>
                    <div style="text-align: center; padding: 3rem; color: var(--gray-500);">
                        <i class="fas fa-comment-slash fa-3x" style="margin-bottom: 1rem; opacity: 0.5;"></i>
                        <p>No complaints submitted yet.</p>
                        <p class="small">You can submit complaints about your results using the "Complain" button above.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-hashtag"></i> #</th>
                                    <th><i class="fas fa-book"></i> Subject</th>
                                    <th><i class="fas fa-comment-dots"></i> Complaint</th>
                                    <th><i class="fas fa-info-circle"></i> Status</th>
                                    <th><i class="fas fa-reply"></i> Teacher Response</th>
                                    <th><i class="fas fa-calendar"></i> Date</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach($complaints as $i=>$c):
                                $statusClass = $c['status'] === 'pending' ? 'text-warning' :
                                             ($c['status'] === 'resolved' ? 'text-success' : 'text-info');
                                $statusIcon = $c['status'] === 'pending' ? 'fas fa-clock' :
                                            ($c['status'] === 'resolved' ? 'fas fa-check-circle' : 'fas fa-spinner');
                            ?>
                                <tr>
                                    <td><?php echo $i+1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($c['subject_name']); ?></strong>
                                        <div class="small">Term: <?php echo htmlspecialchars($c['term']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($c['complaint_text'], 0, 50)) . (strlen($c['complaint_text']) > 50 ? '...' : ''); ?></td>
                                    <td>
                                        <span class="<?php echo $statusClass; ?>">
                                            <i class="<?php echo $statusIcon; ?>"></i>
                                            <?php echo htmlspecialchars(ucfirst($c['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if(!empty($c['teacher_response'])): ?>
                                            <?php echo nl2br(htmlspecialchars(substr($c['teacher_response'], 0, 100))) . (strlen($c['teacher_response']) > 100 ? '...' : ''); ?>
                                        <?php else: ?>
                                            <span class="small text-muted">No response yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($c['created_at'])); ?>
                                        <div class="small text-muted"><?php echo date('H:i', strtotime($c['created_at'])); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
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
