<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit();
}
$current_school_id = require_school_auth();

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_evaluation'])) {
        // Add new evaluation
        $student_id = intval($_POST['student_id']);
        $term = $_POST['term'];
        $year = $_POST['year'];
        $academic = $_POST['academic'];
        $non_academic = $_POST['non_academic'];
        $cognitive = $_POST['cognitive'];
        $psychomotor = $_POST['psychomotor'];
        $affective = $_POST['affective'];
        $comments = $_POST['comments'];

        // Validate student ownership
        if (!validate_record_ownership('students', $student_id, $current_school_id)) {
            $_SESSION['error'] = "Access denied. Student not found in your school.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO evaluations (student_id, term, year, academic, non_academic, cognitive, psychomotor, affective, comments, evaluated_by, school_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$student_id, $term, $year, $academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $_SESSION['user_id'], $current_school_id]);

            $_SESSION['success'] = "Evaluation added successfully!";
        }
    }
    
    if (isset($_POST['update_evaluation'])) {
        // Update evaluation
        $evaluation_id = intval($_POST['evaluation_id']);
        $academic = $_POST['academic'];
        $non_academic = $_POST['non_academic'];
        $cognitive = $_POST['cognitive'];
        $psychomotor = $_POST['psychomotor'];
        $affective = $_POST['affective'];
        $comments = $_POST['comments'];

        // Validate evaluation ownership
        if (!validate_record_ownership('evaluations', $evaluation_id, $current_school_id)) {
            $_SESSION['error'] = "Access denied. Evaluation not found in your school.";
        } else {
            $stmt = $pdo->prepare("UPDATE evaluations SET academic = ?, non_academic = ?, cognitive = ?, psychomotor = ?, affective = ?, comments = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
            $stmt->execute([$academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $evaluation_id, $current_school_id]);

            $_SESSION['success'] = "Evaluation updated successfully!";
        }
    }
    
    if (isset($_GET['delete_id'])) {
        // Delete evaluation
        $evaluation_id = intval($_GET['delete_id']);

        // Validate evaluation ownership
        if (!validate_record_ownership('evaluations', $evaluation_id, $current_school_id)) {
            $_SESSION['error'] = "Access denied. Evaluation not found in your school.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id = ? AND school_id = ?");
            $stmt->execute([$evaluation_id, $current_school_id]);

            $_SESSION['success'] = "Evaluation deleted successfully!";
        }
        header("Location: students-evaluations.php");
        exit();
    }
    
    // if (isset($_POST['export_pdf'])) {
    //     // Export to PDF functionality
    //     require_once 'includes/pdf_generator.php';
    //     generateEvaluationPDF($_POST['student_id'], $_POST['term'], $_POST['year']);
    //     exit();
    // }
}

// Fetch evaluations - school-filtered
$stmt = $pdo->prepare("
    SELECT e.*, s.first_name, s.last_name, s.class, s.roll_number
    FROM evaluations e
    JOIN students s ON e.student_id = s.id
    WHERE s.school_id = ?
    ORDER BY e.created_at DESC
");
$stmt->execute([$current_school_id]);
$evaluations = $stmt->fetchAll();

// Fetch students for dropdown - school-filtered
$stmt = $pdo->prepare("SELECT id, first_name, last_name, class FROM students WHERE school_id = ? ORDER BY class, first_name");
$stmt->execute([$current_school_id]);
$students = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Evaluations - Principal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
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
        
        .select2-container--bootstrap5 .select2-selection {
            border-radius: 8px;
            padding: 8px;
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
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-lg-12">
                <!-- Error Message -->
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= $_SESSION['error']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

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
                                                <strong><?= htmlspecialchars($eval['first_name'] . ' ' . $eval['last_name']); ?></strong>
                                                <br><small class="text-muted">Roll: <?= $eval['roll_number']; ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($eval['class']); ?></td>
                                            <td>
                                                <span class="badge bg-info">Term <?= $eval['term']; ?></span>
                                                <br><small><?= $eval['year']; ?></small>
                                            </td>
                                            <td><span class="rating-badge <?= $eval['academic']; ?>"><?= ucfirst($eval['academic']); ?></span></td>
                                            <td><span class="rating-badge <?= $eval['non_academic']; ?>"><?= ucfirst($eval['non_academic']); ?></span></td>
                                            <td><span class="rating-badge <?= $eval['cognitive']; ?>"><?= ucfirst($eval['cognitive']); ?></span></td>
                                            <td><span class="rating-badge <?= $eval['psychomotor']; ?>"><?= ucfirst($eval['psychomotor']); ?></span></td>
                                            <td><span class="rating-badge <?= $eval['affective']; ?>"><?= ucfirst($eval['affective']); ?></span></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal<?= $eval['id']; ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $eval['id']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?delete_id=<?= $eval['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-outline-success" onclick="printEvaluation(<?= $eval['id']; ?>)">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- View Modal -->
                                        <div class="modal fade" id="viewModal<?= $eval['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Evaluation Details</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <h6>Student Information</h6>
                                                                <p><strong>Name:</strong> <?= $eval['first_name'] . ' ' . $eval['last_name']; ?></p>
                                                                <p><strong>Class:</strong> <?= $eval['class']; ?></p>
                                                                <p><strong>Term:</strong> <?= $eval['term']; ?> | <strong>Year:</strong> <?= $eval['year']; ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <h6>Ratings</h6>
                                                                <p><strong>Academic:</strong> <span class="rating-badge <?= $eval['academic']; ?>"><?= ucfirst($eval['academic']); ?></span></p>
                                                                <p><strong>Non-Academic:</strong> <span class="rating-badge <?= $eval['non_academic']; ?>"><?= ucfirst($eval['non_academic']); ?></span></p>
                                                                <p><strong>Cognitive:</strong> <span class="rating-badge <?= $eval['cognitive']; ?>"><?= ucfirst($eval['cognitive']); ?></span></p>
                                                                <p><strong>Psychomotor:</strong> <span class="rating-badge <?= $eval['psychomotor']; ?>"><?= ucfirst($eval['psychomotor']); ?></span></p>
                                                                <p><strong>Affective:</strong> <span class="rating-badge <?= $eval['affective']; ?>"><?= ucfirst($eval['affective']); ?></span></p>
                                                            </div>
                                                        </div>
                                                        <hr>
                                                        <h6>Comments</h6>
                                                        <p><?= nl2br(htmlspecialchars($eval['comments'])); ?></p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="student_id" value="<?= $eval['student_id']; ?>">
                                                            <input type="hidden" name="term" value="<?= $eval['term']; ?>">
                                                            <input type="hidden" name="year" value="<?= $eval['year']; ?>">
                                                            <button type="submit" name="export_pdf" class="btn btn-success">
                                                                <i class="fas fa-file-pdf me-2"></i>Export PDF
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Edit Modal -->
                                        <div class="modal fade" id="editModal<?= $eval['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Evaluation</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="evaluation_id" value="<?= $eval['id']; ?>">
                                                            <input type="hidden" name="update_evaluation" value="1">
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Academic Performance</label>
                                                                    <select class="form-select" name="academic" required>
                                                                        <option value="excellent" <?= $eval['academic'] == 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                                                        <option value="very-good" <?= $eval['academic'] == 'very-good' ? 'selected' : ''; ?>>Very Good</option>
                                                                        <option value="good" <?= $eval['academic'] == 'good' ? 'selected' : ''; ?>>Good</option>
                                                                        <option value="needs-improvement" <?= $eval['academic'] == 'needs-improvement' ? 'selected' : ''; ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Non-Academic</label>
                                                                    <select class="form-select" name="non_academic" required>
                                                                        <option value="excellent" <?= $eval['non_academic'] == 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                                                        <option value="very-good" <?= $eval['non_academic'] == 'very-good' ? 'selected' : ''; ?>>Very Good</option>
                                                                        <option value="good" <?= $eval['non_academic'] == 'good' ? 'selected' : ''; ?>>Good</option>
                                                                        <option value="needs-improvement" <?= $eval['non_academic'] == 'needs-improvement' ? 'selected' : ''; ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-4">
                                                                    <label class="form-label">Cognitive Domain</label>
                                                                    <select class="form-select" name="cognitive" required>
                                                                        <option value="excellent" <?= $eval['cognitive'] == 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                                                        <option value="very-good" <?= $eval['cognitive'] == 'very-good' ? 'selected' : ''; ?>>Very Good</option>
                                                                        <option value="good" <?= $eval['cognitive'] == 'good' ? 'selected' : ''; ?>>Good</option>
                                                                        <option value="needs-improvement" <?= $eval['cognitive'] == 'needs-improvement' ? 'selected' : ''; ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label">Psychomotor Domain</label>
                                                                    <select class="form-select" name="psychomotor" required>
                                                                        <option value="excellent" <?= $eval['psychomotor'] == 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                                                        <option value="very-good" <?= $eval['psychomotor'] == 'very-good' ? 'selected' : ''; ?>>Very Good</option>
                                                                        <option value="good" <?= $eval['psychomotor'] == 'good' ? 'selected' : ''; ?>>Good</option>
                                                                        <option value="needs-improvement" <?= $eval['psychomotor'] == 'needs-improvement' ? 'selected' : ''; ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label">Affective Domain</label>
                                                                    <select class="form-select" name="affective" required>
                                                                        <option value="excellent" <?= $eval['affective'] == 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                                                        <option value="very-good" <?= $eval['affective'] == 'very-good' ? 'selected' : ''; ?>>Very Good</option>
                                                                        <option value="good" <?= $eval['affective'] == 'good' ? 'selected' : ''; ?>>Good</option>
                                                                        <option value="needs-improvement" <?= $eval['affective'] == 'needs-improvement' ? 'selected' : ''; ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Comments & Recommendations</label>
                                                                <textarea class="form-control" name="comments" rows="4"><?= htmlspecialchars($eval['comments']); ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Update Evaluation</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
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
                                            <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' - ' . $student['class']); ?>
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
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function printEvaluation(evaluationId) {
            window.open(`print-evaluation.php?id=${evaluationId}`, '_blank');
        }
        
        // DataTable initialization
        $(document).ready(function() {
            $('#evaluationsTable').DataTable({
                "pageLength": 10,
                "responsive": true
            });
        });
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
