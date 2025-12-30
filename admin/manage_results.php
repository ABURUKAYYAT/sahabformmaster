
<?php
session_start();
require_once '../config/db.php';

// Only allow principal to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_id = $_SESSION['user_id'];

// Function to normalize term
function normalize_term($t) {
    $t = trim(strtolower((string)$t));
    $map = [
        '1' => '1st Term','first' => '1st Term','1st' => '1st Term','first term' => '1st Term','1st term' => '1st Term',
        '2' => '2nd Term','second' => '2nd Term','2nd' => '2nd Term','second term' => '2nd Term','2nd term' => '2nd Term',
        '3' => '3rd Term','third' => '3rd Term','3rd' => '3rd Term','third term' => '3rd Term','3rd term' => '3rd Term'
    ];
    return $map[$t] ?? (strlen($t) ? ucfirst($t) : '1st Term');
}

// Get filters
$term = normalize_term($_GET['term'] ?? '1st Term');
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$errors = [];
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete_result') {
        $result_id = intval($_POST['result_id'] ?? 0);
        if ($result_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM results WHERE id = :id");
                $stmt->execute(['id' => $result_id]);
                $success = "Result deleted successfully.";
            } catch (PDOException $e) {
                $errors[] = "Error deleting result: " . $e->getMessage();
            }
        }
    }
    
    if ($action === 'update_result') {
        $result_id = intval($_POST['result_id'] ?? 0);
        $first_ca = floatval($_POST['first_ca'] ?? 0);
        $second_ca = floatval($_POST['second_ca'] ?? 0);
        $exam = floatval($_POST['exam'] ?? 0);
        $academic_session = trim($_POST['academic_session'] ?? '');
        
        if ($result_id > 0) {
            // Clamp scores
            $first_ca = max(0, min(100, $first_ca));
            $second_ca = max(0, min(100, $second_ca));
            $exam = max(0, min(100, $exam));
            $total_ca = $first_ca + $second_ca;
            
            try {
                $hasUpdatedAt = (bool) $pdo->query("SHOW COLUMNS FROM `results` LIKE 'updated_at'")->fetchColumn();
                if ($hasUpdatedAt) {
                    $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session, updated_at = NOW() WHERE id = :id");
                } else {
                    $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session WHERE id = :id");
                }
                $stmt->execute([
                    'first_ca' => $first_ca,
                    'second_ca' => $second_ca,
                    'exam' => $exam,
                    'total_ca' => $total_ca,
                    'academic_session' => $academic_session,
                    'id' => $result_id
                ]);
                $success = "Result updated successfully.";
            } catch (PDOException $e) {
                $errors[] = "Error updating result: " . $e->getMessage();
            }
        }
    }
}

// Fetch all classes
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all students
$students = $pdo->query("SELECT * FROM students ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Build query for results
$query = "
    SELECT r.*, 
           s.full_name AS student_name, 
           s.admission_no,
           s.class_id,
           c.class_name,
           sub.subject_name
    FROM results r
    JOIN students s ON r.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    JOIN subjects sub ON r.subject_id = sub.id
    WHERE r.term = :term
";

$params = ['term' => $term];

if ($class_id > 0) {
    $query .= " AND s.class_id = :class_id";
    $params['class_id'] = $class_id;
}

if ($student_id > 0) {
    $query .= " AND s.id = :student_id";
    $params['student_id'] = $student_id;
}

$query .= " ORDER BY c.class_name, s.full_name, sub.subject_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_students = count(array_unique(array_column($results, 'student_id')));
$total_subjects = count(array_unique(array_column($results, 'subject_id')));
$total_results = count($results);

// Calculate class averages
$class_stats = [];
foreach ($results as $result) {
    $class_id = $result['class_id'];
    $ca_total = $result['first_ca'] + $result['second_ca'];
    $grand_total = $ca_total + $result['exam'];
    
    if (!isset($class_stats[$class_id])) {
        $class_stats[$class_id] = [
            'class_name' => $result['class_name'],
            'total_scores' => 0,
            'count' => 0,
            'students' => []
        ];
    }
    
    $class_stats[$class_id]['total_scores'] += $grand_total;
    $class_stats[$class_id]['count']++;
    
    if (!in_array($result['student_id'], $class_stats[$class_id]['students'])) {
        $class_stats[$class_id]['students'][] = $result['student_id'];
    }
}

// Function to calculate grade
function calculateGrade($grand_total) {
    if ($grand_total >= 90) return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($grand_total >= 80) return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($grand_total >= 70) return ['grade' => 'C', 'remark' => 'Good'];
    if ($grand_total >= 60) return ['grade' => 'D', 'remark' => 'Fair'];
    if ($grand_total >= 50) return ['grade' => 'E', 'remark' => 'Pass'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Results | Principal Dashboard</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .principal-results-container {
            padding: 20px;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .results-table-container {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .results-table th, .results-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .results-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        
        .results-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .grade-A { color: #4CAF50; font-weight: bold; }
        .grade-B { color: #8BC34A; font-weight: bold; }
        .grade-C { color: #FFC107; font-weight: bold; }
        .grade-D { color: #FF9800; font-weight: bold; }
        .grade-E { color: #F44336; font-weight: bold; }
        .grade-F { color: #D32F2F; font-weight: bold; }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-edit {
            background: #FF9800;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .btn-delete {
            background: #f44336;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .btn-pdf {
            background: #2196F3;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .edit-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .edit-modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        
        .score-inputs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        
        .class-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .class-stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 768px) {
            .principal-results-container {
                padding: 15px;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .class-stats {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 3px;
            }
            
            .results-table {
                font-size: 14px;
            }
            
            .results-table th, .results-table td {
                padding: 8px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .score-inputs {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    
    <div class="dashboard-container">
        
        <main class="main-content">
            <div class="principal-results-container">
                <div class="content-header">
                    <h1>Manage Results - Principal Dashboard</h1>
                    <p class="small-muted">View and manage all student results across classes</p>
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
                
                <!-- Statistics Cards -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $total_results; ?></div>
                        <div class="stat-label">Total Results</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $total_students; ?></div>
                        <div class="stat-label">Students with Results</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $total_subjects; ?></div>
                        <div class="stat-label">Subjects Covered</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($classes); ?></div>
                        <div class="stat-label">Classes</div>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <h3>Filter Results</h3>
                    <form method="GET" class="filter-form">
                        <div class="filter-grid">
                            <div>
                                <label for="term">Term</label>
                                <select id="term" name="term" class="form-control">
                                    <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                                    <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                                    <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="class_id">Class (Optional)</label>
                                <select id="class_id" name="class_id" class="form-control">
                                    <option value="0">All Classes</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo intval($class['id']); ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="student_id">Student (Optional)</label>
                                <select id="student_id" name="student_id" class="form-control">
                                    <option value="0">All Students</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo intval($student['id']); ?>" <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_no'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" class="btn-gold">Apply Filters</button>
                            <a href="manage_results.php" class="btn-small">Reset Filters</a>
                        </div>
                    </form>
                </div>
                
                <!-- Class Statistics -->
                <?php if (!empty($class_stats)): ?>
                    <div class="results-table-container">
                        <h3>Class Performance Overview</h3>
                        <div class="class-stats">
                            <?php foreach ($class_stats as $class_id => $stats): 
                                $average = $stats['count'] > 0 ? $stats['total_scores'] / $stats['count'] : 0;
                                $student_count = count($stats['students']);
                            ?>
                                <div class="class-stat-card">
                                    <h4><?php echo htmlspecialchars($stats['class_name']); ?></h4>
                                    <div style="display: flex; justify-content: space-between; margin-top: 15px;">
                                        <div>
                                            <div class="stat-value"><?php echo number_format($average, 1); ?></div>
                                            <div class="stat-label">Average Score</div>
                                        </div>
                                        <div>
                                            <div class="stat-value"><?php echo $student_count; ?></div>
                                            <div class="stat-label">Students</div>
                                        </div>
                                        <div>
                                            <div class="stat-value"><?php echo $stats['count']; ?></div>
                                            <div class="stat-label">Results</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Results Table -->
                <div class="results-table-container">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3>All Results</h3>
                        <div class="small-muted">
                            Showing <?php echo count($results); ?> results for <?php echo htmlspecialchars($term); ?>
                        </div>
                    </div>
                    
                    <?php if (empty($results)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">📊</div>
                            <h3>No Results Found</h3>
                            <p>No results match your current filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="results-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Class</th>
                                        <th>Student</th>
                                        <th>Admission No</th>
                                        <th>Subject</th>
                                        <th>1st CA</th>
                                        <th>2nd CA</th>
                                        <th>CA Total</th>
                                        <th>Exam</th>
                                        <th>Total</th>
                                        <th>Grade</th>
                                        <th>Remark</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $index => $result): 
                                        $ca_total = $result['first_ca'] + $result['second_ca'];
                                        $grand_total = $ca_total + $result['exam'];
                                        $grade_data = calculateGrade($grand_total);
                                    ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($result['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($result['admission_no']); ?></td>
                                            <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                            <td><?php echo number_format($result['first_ca'], 1); ?></td>
                                            <td><?php echo number_format($result['second_ca'], 1); ?></td>
                                            <td><?php echo number_format($ca_total, 1); ?></td>
                                            <td><?php echo number_format($result['exam'], 1); ?></td>
                                            <td><strong><?php echo number_format($grand_total, 1); ?></strong></td>
                                            <td>
                                                <span class="grade-<?php echo $grade_data['grade']; ?>">
                                                    <?php echo $grade_data['grade']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $grade_data['remark']; ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button" class="btn-edit" onclick="openEditModal(<?php echo $result['id']; ?>, <?php echo number_format($result['first_ca'], 1); ?>, <?php echo number_format($result['second_ca'], 1); ?>, <?php echo number_format($result['exam'], 1); ?>, '<?php echo addslashes($result['academic_session']); ?>')">
                                                        Edit
                                                    </button>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="delete_result">
                                                        <input type="hidden" name="result_id" value="<?php echo intval($result['id']); ?>">
                                                        <button type="submit" class="btn-delete" onclick="return confirm('Delete this result?')">Delete</button>
                                                    </form>
                                                    <form method="POST" action="../teacher/generate-result-pdf.php" style="display:inline;">
                                                        <input type="hidden" name="student_id" value="<?php echo intval($result['student_id']); ?>">
                                                        <input type="hidden" name="class_id" value="<?php echo intval($result['class_id']); ?>">
                                                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($result['term']); ?>">
                                                        <button type="submit" class="btn-pdf">PDF</button>
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
        </main>
    </div>
    
    <!-- Edit Modal -->
    <div id="editModal" class="edit-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Result</h3>
                <button type="button" class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="update_result">
                <input type="hidden" name="result_id" id="edit_result_id">
                
                <div>
                    <label for="edit_academic_session">Academic Session</label>
                    <input type="text" id="edit_academic_session" name="academic_session" class="form-control" required>
                </div>
                
                <div class="score-inputs">
                    <div>
                        <label for="edit_first_ca">First CA</label>
                        <input type="number" id="edit_first_ca" name="first_ca" class="form-control" min="0" max="100" step="0.1" required>
                    </div>
                    
                    <div>
                        <label for="edit_second_ca">Second CA</label>
                        <input type="number" id="edit_second_ca" name="second_ca" class="form-control" min="0" max="100" step="0.1" required>
                    </div>
                    
                    <div>
                        <label for="edit_exam">Exam</label>
                        <input type="number" id="edit_exam" name="exam" class="form-control" min="0" max="100" step="0.1" required>
                    </div>
                </div>
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" class="btn-gold">Save Changes</button>
                    <button type="button" class="btn-small" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openEditModal(resultId, firstCa, secondCa, exam, academicSession) {
            document.getElementById('edit_result_id').value = resultId;
            document.getElementById('edit_first_ca').value = firstCa;
            document.getElementById('edit_second_ca').value = secondCa;
            document.getElementById('edit_exam').value = exam;
            document.getElementById('edit_academic_session').value = academicSession;
            document.getElementById('editModal').classList.add('active');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
        
        // Calculate total automatically
        document.getElementById('edit_first_ca').addEventListener('input', updateTotal);
        document.getElementById('edit_second_ca').addEventListener('input', updateTotal);
        document.getElementById('edit_exam').addEventListener('input', updateTotal);
        
        function updateTotal() {
            const firstCa = parseFloat(document.getElementById('edit_first_ca').value) || 0;
            const secondCa = parseFloat(document.getElementById('edit_second_ca').value) || 0;
            const exam = parseFloat(document.getElementById('edit_exam').value) || 0;
            const total = firstCa + secondCa + exam;
            
            // Optionally display total somewhere
            // document.getElementById('totalDisplay').textContent = total.toFixed(1);
        }
    </script>
</body>
</html>
[file content end]