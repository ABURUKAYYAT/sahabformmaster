<?php
session_start();
require_once '../config/db.php';

// ...existing code...

// Student access using admission_no or user_id
$uid = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;
$errors = [];
$success = '';

if (!$uid && !$admission_no) {
    header("Location: ../index.php");
    exit;
}

// Resolve student record
$student = null;
if ($admission_no) {
    $stmt = $pdo->prepare("SELECT id, full_name, class_id FROM students WHERE admission_no = :admission_no LIMIT 1");
    $stmt->execute(['admission_no' => $admission_no]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$student && $uid) {
    $stmt = $pdo->prepare("SELECT id, full_name, class_id FROM students WHERE user_id = :uid OR id = :uid LIMIT 1");
    $stmt->execute(['uid' => $uid]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$student) {
    die("Student record not found.");
}

$student_id = (int)$student['id'];
$class_id = $student['class_id'];

// normalize term input to canonical values used in DB
function normalize_term($t) {
    $t = trim(strtolower((string)$t));
    $map = [
        '1' => '1st Term','first' => '1st Term','1st' => '1st Term','first term' => '1st Term','1st term' => '1st Term',
        '2' => '2nd Term','second' => '2nd Term','2nd' => '2nd Term','second term' => '2nd Term','2nd term' => '2nd Term',
        '3' => '3rd Term','third' => '3rd Term','3rd' => '3rd Term','third term' => '3rd Term','3rd term' => '3rd Term'
    ];
    return $map[$t] ?? (strlen($t) ? ucfirst($t) : '1st Term');
}

// Accept term from GET (filter) or default to 1st Term
$term = normalize_term($_GET['term'] ?? '1st Term');

// Handle complaint submission (unchanged)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'submit_complaint') {
        $result_id = intval($_POST['result_id'] ?? 0);
        $text = trim($_POST['complaint_text'] ?? '');
        if ($result_id <= 0 || $text === '') {
            $errors[] = "Please select a result and enter your complaint.";
        } else {
            // ensure result belongs to this student
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE id = :id AND student_id = :student_id");
            $stmt->execute(['id' => $result_id, 'student_id' => $student_id]);
            if ((int)$stmt->fetchColumn() === 0) {
                $errors[] = "Selected result not found.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO results_complaints (result_id, student_id, complaint_text, status, created_at) VALUES (:result_id, :student_id, :text, 'pending', NOW())");
                $stmt->execute(['result_id' => $result_id, 'student_id' => $student_id, 'text' => $text]);
                $success = "Complaint submitted.";
            }
        }
    }
}

// Fetch student's results for the selected term (use prepared stmt)
try {
    $stmt = $pdo->prepare("SELECT r.*, sub.subject_name 
        FROM results r
        JOIN subjects sub ON r.subject_id = sub.id
        WHERE r.student_id = :student_id AND LOWER(TRIM(r.term)) = LOWER(TRIM(:term))
        ORDER BY sub.subject_name");
    $stmt->execute(['student_id' => $student_id, 'term' => $term]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Database error while fetching results.";
    error_log("student/results.php DB error: " . $e->getMessage());
    $results = [];
}

// Fetch existing complaints by student
$stmt = $pdo->prepare("SELECT rc.*, sub.subject_name, r.term FROM results_complaints rc JOIN results r ON rc.result_id = r.id JOIN subjects sub ON r.subject_id = sub.id WHERE rc.student_id = :student_id ORDER BY rc.created_at DESC");
$stmt->execute(['student_id' => $student_id]);
$complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get class name
$stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = :id");
$stmt->execute(['id' => $class_id]);
$class_name = $stmt->fetchColumn() ?: 'N/A';

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Results | SahabFormMaster</title>
<link rel="stylesheet" href="../assets/css/dashboard.css">
<style>
    /* Custom styles for student results page */
    .student-results-container {
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .student-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 25px;
        border-radius: 10px;
        margin-bottom: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    
    .student-info {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .student-details h1 {
        margin: 0 0 10px 0;
        font-size: 28px;
    }
    
    .student-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        font-size: 16px;
    }
    
    .meta-item {
        background: rgba(255,255,255,0.2);
        padding: 8px 15px;
        border-radius: 20px;
        backdrop-filter: blur(10px);
    }
    
    .term-selector {
        background: white;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .results-section, .complaints-section {
        background: white;
        padding: 25px;
        border-radius: 10px;
        margin-bottom: 30px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .section-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f0f0f0;
    }
    
    .section-title h3 {
        margin: 0;
        color: #333;
        font-size: 22px;
    }
    
    .results-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .subject-card {
        border: 1px solid #e0e0e0;
        border-radius: 10px;
        padding: 20px;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .subject-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .subject-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .subject-name {
        font-weight: bold;
        font-size: 18px;
        color: #333;
    }
    
    .subject-grade {
        background: #4CAF50;
        color: white;
        padding: 5px 10px;
        border-radius: 15px;
        font-weight: bold;
    }
    
    .scores-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        text-align: center;
        margin-bottom: 15px;
    }
    
    .score-item {
        padding: 10px;
        background: #f8f9fa;
        border-radius: 8px;
    }
    
    .score-label {
        font-size: 12px;
        color: #666;
        margin-bottom: 5px;
    }
    
    .score-value {
        font-size: 20px;
        font-weight: bold;
        color: #333;
    }
    
    .total-score {
        text-align: center;
        padding: 15px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 8px;
        margin-bottom: 15px;
    }
    
    .total-value {
        font-size: 32px;
        font-weight: bold;
    }
    
    .remark {
        text-align: center;
        font-style: italic;
        color: #666;
        margin-bottom: 15px;
    }
    
    .complaint-btn {
        width: 100%;
        padding: 10px;
        background: #FF9800;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: bold;
        transition: background 0.3s ease;
    }
    
    .complaint-btn:hover {
        background: #F57C00;
    }
    
    .complaint-form {
        background: #f9f9f9;
        padding: 15px;
        border-radius: 8px;
        margin-top: 15px;
        display: none;
    }
    
    .complaint-form.active {
        display: block;
    }
    
    .complaint-textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
        margin-bottom: 10px;
        resize: vertical;
        min-height: 100px;
    }
    
    .complaints-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    
    .complaints-table th, .complaints-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #e0e0e0;
    }
    
    .complaints-table th {
        background-color: #f8f9fa;
        font-weight: 600;
    }
    
    .status-badge {
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 12px;
        font-weight: bold;
    }
    
    .status-pending { background: #FFF3CD; color: #856404; }
    .status-resolved { background: #D4EDDA; color: #155724; }
    .status-rejected { background: #F8D7DA; color: #721C24; }
    
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #666;
    }
    
    .empty-icon {
        font-size: 48px;
        margin-bottom: 20px;
        opacity: 0.3;
    }
    
    .download-btn {
        background: #4CAF50;
        color: white;
        padding: 12px 25px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: bold;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
    }
    
    .download-btn:hover {
        background: #45a049;
    }
    
    .btn-dashboard {
        background: #2196F3;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        margin-bottom: 20px;
    }
    
    .btn-dashboard:hover {
        background: #0b7dda;
    }
    
    /* Responsive styles */
    @media (max-width: 768px) {
        .student-results-container {
            padding: 15px;
        }
        
        .student-header {
            padding: 20px;
        }
        
        .student-info {
            flex-direction: column;
            text-align: center;
            gap: 15px;
        }
        
        .student-details h1 {
            font-size: 24px;
        }
        
        .student-meta {
            justify-content: center;
        }
        
        .results-grid {
            grid-template-columns: 1fr;
        }
        
        .subject-card {
            padding: 15px;
        }
        
        .section-title {
            flex-direction: column;
            gap: 10px;
            text-align: center;
        }
        
        .complaints-table {
            display: block;
            overflow-x: auto;
        }
        
        .total-value {
            font-size: 28px;
        }
    }
    
    @media (max-width: 480px) {
        .student-header {
            padding: 15px;
        }
        
        .student-details h1 {
            font-size: 20px;
        }
        
        .meta-item {
            font-size: 14px;
            padding: 6px 12px;
        }
        
        .section-title h3 {
            font-size: 18px;
        }
        
        .scores-grid {
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        
        .score-value {
            font-size: 18px;
        }
        
        .subject-name {
            font-size: 16px;
        }
    }
</style>
</head>
<body>
    <?php if (isset($_SESSION['user_id'])): ?>
        <a href="./dashboard.php" class="btn-dashboard">← Back to Dashboard</a>
    <?php endif; ?>
    
    <div class="student-results-container">
        <!-- Student Header -->
        <div class="student-header">
            <div class="student-info">
                <div class="student-details">
                    <h1>My Academic Results</h1>
                    <div class="student-meta">
                        <span class="meta-item">Student: <?php echo htmlspecialchars($student['full_name']); ?></span>
                        <span class="meta-item">Class: <?php echo htmlspecialchars($class_name); ?></span>
                        <span class="meta-item">Term: <?php echo htmlspecialchars($term); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Term Selector -->
        <div class="term-selector">
            <form method="GET" class="term-form">
                <label for="term"><strong>Select Term:</strong></label>
                <select id="term" name="term" onchange="this.form.submit()" class="form-control" style="max-width: 200px; display: inline-block; margin-left: 10px;">
                    <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                    <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                    <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                </select>
                <noscript><button type="submit" class="btn-small" style="margin-left: 10px;">Apply</button></noscript>
            </form>
        </div>
        
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <!-- Results Section -->
        <div class="results-section">
            <div class="section-title">
                <h3>Academic Performance</h3>
                <?php if (!empty($results)): ?>
                    <form method="POST" action="../teacher/generate-result-pdf.php" style="display:inline;">
                        <input type="hidden" name="student_id" value="<?php echo intval($student_id); ?>">
                        <input type="hidden" name="class_id" value="<?php echo intval($class_id); ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                        <button type="submit" class="download-btn">📄 Download PDF</button>
                    </form>
                <?php endif; ?>
            </div>
            
            <?php if (empty($results)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📊</div>
                    <h3>No Results Available</h3>
                    <p>No results found for <?php echo htmlspecialchars($term); ?> term.</p>
                </div>
            <?php else: ?>
                <div class="results-grid">
                    <?php foreach ($results as $r): 
                        $first_ca = floatval($r['first_ca'] ?? 0);
                        $second_ca = floatval($r['second_ca'] ?? 0);
                        $ca_total = $first_ca + $second_ca;
                        $exam = floatval($r['exam'] ?? 0);
                        $grand_total = $ca_total + $exam;
                        
                        if ($grand_total >= 90) { $grade = ['A','Excellent']; }
                        elseif ($grand_total >= 80) { $grade = ['B','Very Good']; }
                        elseif ($grand_total >= 70) { $grade = ['C','Good']; }
                        elseif ($grand_total >= 60) { $grade = ['D','Fair']; }
                        elseif ($grand_total >= 50) { $grade = ['E','Pass']; }
                        else { $grade = ['F','Fail']; }
                    ?>
                    <div class="subject-card">
                        <div class="subject-header">
                            <div class="subject-name"><?php echo htmlspecialchars($r['subject_name']); ?></div>
                            <div class="subject-grade grade-<?php echo strtolower($grade[0]); ?>"><?php echo $grade[0]; ?></div>
                        </div>
                        
                        <div class="scores-grid">
                            <div class="score-item">
                                <div class="score-label">First CA</div>
                                <div class="score-value"><?php echo number_format($first_ca, 1); ?></div>
                            </div>
                            <div class="score-item">
                                <div class="score-label">Second CA</div>
                                <div class="score-value"><?php echo number_format($second_ca, 1); ?></div>
                            </div>
                            <div class="score-item">
                                <div class="score-label">Exam</div>
                                <div class="score-value"><?php echo number_format($exam, 1); ?></div>
                            </div>
                        </div>
                        
                        <div class="total-score">
                            <div class="score-label">Total Score</div>
                            <div class="total-value"><?php echo number_format($grand_total, 1); ?></div>
                        </div>
                        
                        <div class="remark">
                            <?php echo $grade[1]; ?>
                        </div>
                        
                        <button type="button" class="complaint-btn" onclick="toggleComplaintForm(<?php echo intval($r['id']); ?>)">
                            Submit Complaint
                        </button>
                        
                        <div id="complaint-<?php echo intval($r['id']); ?>" class="complaint-form">
                            <form method="POST" action="myresults.php">
                                <input type="hidden" name="action" value="submit_complaint">
                                <input type="hidden" name="result_id" value="<?php echo intval($r['id']); ?>">
                                <textarea name="complaint_text" rows="3" placeholder="Enter your complaint regarding this result..." 
                                          class="complaint-textarea" required></textarea>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn-gold" type="submit">Submit Complaint</button>
                                    <button type="button" class="btn-small" onclick="toggleComplaintForm(<?php echo intval($r['id']); ?>)">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Complaints Section -->
        <div class="complaints-section">
            <div class="section-title">
                <h3>My Complaints</h3>
            </div>
            
            <?php if (empty($complaints)): ?>
                <div class="empty-state">
                    <div class="empty-icon">💬</div>
                    <h3>No Complaints</h3>
                    <p>You haven't submitted any complaints yet.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="complaints-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Subject</th>
                                <th>Term</th>
                                <th>Complaint</th>
                                <th>Status</th>
                                <th>Response</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($complaints as $i => $c): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($c['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($c['term']); ?></td>
                                    <td><?php echo htmlspecialchars($c['complaint_text']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $c['status']; ?>">
                                            <?php echo htmlspecialchars(ucfirst($c['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['teacher_response'] ?? 'Awaiting response...'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleComplaintForm(resultId) {
            const form = document.getElementById('complaint-' + resultId);
            form.classList.toggle('active');
            
            // Scroll to form if opening
            if (form.classList.contains('active')) {
                form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }
        
        // Initialize with first term selected
        document.addEventListener('DOMContentLoaded', function() {
            const termSelect = document.getElementById('term');
            if (termSelect) {
                termSelect.style.backgroundColor = '#f8f9fa';
                termSelect.style.border = '2px solid #667eea';
                termSelect.style.padding = '8px';
            }
        });
    </script>
</body>
</html>
[file content end]