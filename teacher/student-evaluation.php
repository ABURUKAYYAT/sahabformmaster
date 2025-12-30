<?php
session_start();
require_once '../config/db.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'];


// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_evaluation'])) {
        // Add new evaluation
        $student_id = $_POST['student_id'];
        $class_id = $_POST['class_id'];
        $term = $_POST['term'];
        $year = $_POST['year'];
        $academic = $_POST['academic'];
        $non_academic = $_POST['non_academic'];
        $cognitive = $_POST['cognitive'];
        $psychomotor = $_POST['psychomotor'];
        $affective = $_POST['affective'];
        $comments = $_POST['comments'];

        $stmt = $pdo->prepare("INSERT INTO evaluations (student_id, class_id, term, academic_year, academic, non_academic, cognitive, psychomotor, affective, comments, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$student_id, $class_id, $term, $year, $academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $teacher_id]);

        $_SESSION['success'] = "Evaluation added successfully!";
    }

    if (isset($_POST['update_evaluation'])) {
        // Update evaluation
        $evaluation_id = $_POST['evaluation_id'];
        $academic = $_POST['academic'];
        $non_academic = $_POST['non_academic'];
        $cognitive = $_POST['cognitive'];
        $psychomotor = $_POST['psychomotor'];
        $affective = $_POST['affective'];
        $comments = $_POST['comments'];

        $stmt = $pdo->prepare("UPDATE evaluations SET academic = ?, non_academic = ?, cognitive = ?, psychomotor = ?, affective = ?, comments = ?, updated_at = NOW() WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $evaluation_id, $teacher_id]);

        $_SESSION['success'] = "Evaluation updated successfully!";
    }

    if (isset($_POST['delete_evaluation'])) {
        // Delete evaluation
        $evaluation_id = $_POST['evaluation_id'];
        $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$evaluation_id, $teacher_id]);

        $_SESSION['success'] = "Evaluation deleted successfully!";
        header("Location: student-evaluation.php");
        exit();
    }
}

// Fetch evaluations created by this teacher
$stmt = $pdo->prepare("
    SELECT e.*, s.full_name, s.class_id, s.admission_no, c.class_name
    FROM evaluations e
    JOIN students s ON e.student_id = s.id
    JOIN classes c ON e.class_id = c.id
    WHERE e.teacher_id = ?
    ORDER BY e.created_at DESC
");
$stmt->execute([$teacher_id]);
$evaluations = $stmt->fetchAll();

// Fetch students that this teacher can evaluate (students in classes they teach)
$students_query = "
    SELECT DISTINCT s.id, s.full_name, s.class_id, s.admission_no, c.class_name
    FROM students s
    JOIN classes c ON s.class_id = c.id
    WHERE EXISTS (
        SELECT 1 FROM subject_assignments sa WHERE sa.class_id = s.class_id AND sa.teacher_id = ?
    ) OR EXISTS (
        SELECT 1 FROM class_teachers ct WHERE ct.class_id = s.class_id AND ct.teacher_id = ?
    )
    ORDER BY c.class_name, s.full_name
";
$stmt = $pdo->prepare($students_query);
$stmt->execute([$teacher_id, $teacher_id]);
$students = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Evaluations - Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/teacher-dashboard.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --light-bg: #f8f9fa;
        }

        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s;
            margin-bottom: 20px;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1.2rem;
        }

        .rating-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .excellent { background-color: #d4edda; color: #155724; }
        .very-good { background-color: #cce5ff; color: #004085; }
        .good { background-color: #fff3cd; color: #856404; }
        .needs-improvement { background-color: #f8d7da; color: #721c24; }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #2980b9, var(--secondary-color));
            transform: scale(1.05);
        }

        .modal-content {
            border-radius: 15px;
            border: none;
        }

        .table th {
            background-color: var(--primary-color);
            color: white;
            border: none;
        }

        .table td {
            vertical-align: middle;
        }

        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.9rem;
            }

            .card-header h5 {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>

    <div class="container-fluid mt-4">
        <!-- Back to Dashboard Button -->
        <div class="row mb-3">
            <div class="col-12">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-12">
                <!-- Success Message -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $_SESSION['success']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Student Evaluations Management</h5>
                        <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addEvaluationModal">
                            <i class="fas fa-plus me-2"></i>Add New Evaluation
                        </button>
                    </div>

                    <div class="card-body">
                        <!-- Evaluation Summary -->
                        <div class="row mb-4">
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="card bg-primary text-white">
                                    <div class="card-body text-center">
                                        <h6>Total Evaluations</h6>
                                        <h3><?= count($evaluations); ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="card bg-success text-white">
                                    <div class="card-body text-center">
                                        <h6>Excellent Ratings</h6>
                                        <h3>
                                            <?= array_reduce($evaluations, function($carry, $item) {
                                                return $carry + (($item['academic'] === 'excellent' ||
                                                                $item['non_academic'] === 'excellent') ? 1 : 0);
                                            }, 0); ?>
                                        </h3>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Evaluations Table -->
                        <div class="table-responsive">
                            <table class="table table-hover" id="evaluationsTable">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Term/Year</th>
                                        <th>Academic</th>
                                        <th>Non-Academic</th>
                                        <th>Cognitive</th>
                                        <th>Psychomotor</th>
                                        <th>Affective</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($evaluations as $eval): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($eval['full_name']); ?></strong>
                                                <br><small class="text-muted">Roll: <?= $eval['admission_no']; ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($eval['class_name']); ?></td>
                                            <td>
                                                <span class="badge bg-info">Term <?= $eval['term']; ?></span>
                                                <br><small><?= $eval['academic_year']; ?></small>
                                            </td>
                                            <td><span class="rating-badge <?= $eval['academic']; ?>"><?= ucfirst($eval['academic']); ?></span></td>
                                            <td><span class="rating-badge <?= $eval['non_academic']; ?>"><?= ucfirst($eval['non_academic']); ?></span></td>
                                            <td><span class="rating-badge <?= $eval['cognitive']; ?>"><?= ucfirst($eval['cognitive']); ?></span></td>
                                            <td><span class="rating-badge <?= $eval['psychomotor']; ?>"><?= ucfirst($eval['psychomotor']); ?></span></td>
                                            <td><span class="rating-badge <?= $eval['affective']; ?>"><?= ucfirst($eval['affective']); ?></span></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewEvaluation(<?= $eval['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-warning" onclick="editEvaluation(<?= $eval['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this evaluation?')">
                                                        <input type="hidden" name="delete_evaluation" value="1">
                                                        <input type="hidden" name="evaluation_id" value="<?= $eval['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-trash"></i>
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
                </div>
            </div>
        </div>
    </div>

    <!-- Add Evaluation Modal -->
    <div class="modal fade" id="addEvaluationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Evaluation</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="add_evaluation" value="1">

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Student</label>
                                <select class="form-select" name="student_id" required>
                                    <option value="">Select Student</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?= $student['id']; ?>">
                                            <?= htmlspecialchars($student['full_name']); ?> - <?= htmlspecialchars($student['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Term</label>
                                <select class="form-select" name="term" required>
                                    <option value="1">Term 1</option>
                                    <option value="2">Term 2</option>
                                    <option value="3">Term 3</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Year</label>
                                <input type="number" class="form-control" name="year" value="<?= date('Y'); ?>" required>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Class</label>
                                <select class="form-select" name="class_id" required>
                                    <option value="">Select Class</option>
                                    <?php
                                    $classes = array_unique(array_column($students, 'class_id'));
                                    foreach ($classes as $class_id) {
                                        $class_name = '';
                                        foreach ($students as $s) {
                                            if ($s['class_id'] == $class_id) {
                                                $class_name = $s['class_name'];
                                                break;
                                            }
                                        }
                                        echo "<option value=\"$class_id\">" . htmlspecialchars($class_name) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <hr>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Academic Performance</label>
                                <select class="form-select" name="academic" required>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Non-Academic Activities</label>
                                <select class="form-select" name="non_academic" required>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Cognitive Domain</label>
                                <select class="form-select" name="cognitive" required>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Psychomotor Domain</label>
                                <select class="form-select" name="psychomotor" required>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Affective Domain</label>
                                <select class="form-select" name="affective" required>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Comments & Recommendations</label>
                            <textarea class="form-control" name="comments" rows="4" placeholder="Enter additional comments, strengths, and areas for improvement..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Evaluation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Evaluation Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Evaluation Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewModalBody">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Evaluation Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" id="editForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Evaluation</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="editModalBody">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Evaluation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Evaluation data for JavaScript
        const evaluations = <?= json_encode($evaluations); ?>;

        // Auto-fill class when student is selected
        document.querySelector('select[name="student_id"]').addEventListener('change', function() {
            const studentId = this.value;
            const students = <?= json_encode($students); ?>;
            const selectedStudent = students.find(s => s.id == studentId);
            if (selectedStudent) {
                document.querySelector('select[name="class_id"]').value = selectedStudent.class_id;
            }
        });

        // View evaluation function
        function viewEvaluation(evaluationId) {
            const evaluation = evaluations.find(e => e.id == evaluationId);
            if (!evaluation) return;

            const viewModalBody = document.getElementById('viewModalBody');
            viewModalBody.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Student Information</h6>
                        <p><strong>Name:</strong> ${evaluation.full_name}</p>
                        <p><strong>Class:</strong> ${evaluation.class_name}</p>
                        <p><strong>Term:</strong> ${evaluation.term} | <strong>Year:</strong> ${evaluation.academic_year}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Ratings</h6>
                        <p><strong>Academic:</strong> <span class="rating-badge ${evaluation.academic}">${evaluation.academic.charAt(0).toUpperCase() + evaluation.academic.slice(1)}</span></p>
                        <p><strong>Non-Academic:</strong> <span class="rating-badge ${evaluation.non_academic}">${evaluation.non_academic.charAt(0).toUpperCase() + evaluation.non_academic.slice(1)}</span></p>
                        <p><strong>Cognitive:</strong> <span class="rating-badge ${evaluation.cognitive}">${evaluation.cognitive.charAt(0).toUpperCase() + evaluation.cognitive.slice(1)}</span></p>
                        <p><strong>Psychomotor:</strong> <span class="rating-badge ${evaluation.psychomotor}">${evaluation.psychomotor.charAt(0).toUpperCase() + evaluation.psychomotor.slice(1)}</span></p>
                        <p><strong>Affective:</strong> <span class="rating-badge ${evaluation.affective}">${evaluation.affective.charAt(0).toUpperCase() + evaluation.affective.slice(1)}</span></p>
                    </div>
                </div>
                <hr>
                <h6>Comments</h6>
                <p>${evaluation.comments ? evaluation.comments.replace(/\n/g, '<br>') : 'No comments'}</p>
            `;

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('viewModal'));
            modal.show();
        }

        // Edit evaluation function
        function editEvaluation(evaluationId) {
            const evaluation = evaluations.find(e => e.id == evaluationId);
            if (!evaluation) return;

            const editModalBody = document.getElementById('editModalBody');
            const editForm = document.getElementById('editForm');

            editModalBody.innerHTML = `
                <input type="hidden" name="evaluation_id" value="${evaluation.id}">
                <input type="hidden" name="update_evaluation" value="1">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Academic Performance</label>
                        <select class="form-select" name="academic" required>
                            <option value="excellent" ${evaluation.academic === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.academic === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.academic === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.academic === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Non-Academic</label>
                        <select class="form-select" name="non_academic" required>
                            <option value="excellent" ${evaluation.non_academic === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.non_academic === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.non_academic === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.non_academic === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Cognitive Domain</label>
                        <select class="form-select" name="cognitive" required>
                            <option value="excellent" ${evaluation.cognitive === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.cognitive === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.cognitive === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.cognitive === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Psychomotor Domain</label>
                        <select class="form-select" name="psychomotor" required>
                            <option value="excellent" ${evaluation.psychomotor === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.psychomotor === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.psychomotor === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.psychomotor === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Affective Domain</label>
                        <select class="form-select" name="affective" required>
                            <option value="excellent" ${evaluation.affective === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.affective === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.affective === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.affective === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Comments & Recommendations</label>
                    <textarea class="form-control" name="comments" rows="4">${evaluation.comments || ''}</textarea>
                </div>
            `;

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
        }
    </script>
</body>
</html>
