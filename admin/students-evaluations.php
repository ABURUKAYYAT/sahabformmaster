<?php
session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_name = $_SESSION['full_name'];


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
        $stmt->execute([$student_id, $class_id, $term, $year, $academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $_SESSION['user_id']]);
        
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
        
        $stmt = $pdo->prepare("UPDATE evaluations SET academic = ?, non_academic = ?, cognitive = ?, psychomotor = ?, affective = ?, comments = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $evaluation_id]);
        
        $_SESSION['success'] = "Evaluation updated successfully!";
    }
    
    if (isset($_GET['delete_id'])) {
        // Delete evaluation
        $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id = ?");
        $stmt->execute([$_GET['delete_id']]);
        
        $_SESSION['success'] = "Evaluation deleted successfully!";
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

// Fetch evaluations
$stmt = $pdo->query("
    SELECT e.*, s.full_name, s.class_id, s.admission_no 
    FROM evaluations e 
    JOIN students s ON e.student_id = s.id 
    ORDER BY e.created_at DESC
");
$evaluations = $stmt->fetchAll();

// Fetch students for dropdown
$students = $pdo->query("SELECT id, full_name, class_id FROM students ORDER BY class_id, full_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Evaluations - Principal Dashboard</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Modal Fix for Dashboard Layout */
        .modal-backdrop {
            z-index: 1060 !important;
        }

        .modal {
            z-index: 1070 !important;
        }

        .modal-dialog {
            margin-top: 10vh;
        }

        /* Ensure modals appear above dashboard elements */
        .dashboard-header,
        .sidebar,
        .mobile-menu-toggle {
            z-index: 1050 !important;
        }

        /* Fix modal close button positioning */
        .modal-header .btn-close {
            margin: 0;
        }

        /* Ensure modal content is properly positioned */
        @media (max-width: 768px) {
            .modal-dialog {
                margin: 5vh auto;
                max-width: 95vw;
            }

            .modal-content {
                border-radius: 10px;
            }
        }
    </style>
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>

            <!-- Principal Info and Logout -->
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon">🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📰</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link">
                            <span class="nav-icon">📔</span>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students Registration</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students-evaluations.php" class="nav-link active">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Students Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_class.php" class="nav-link">
                            <span class="nav-icon">🎓</span>
                            <span class="nav-text">Manage Classes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="content_coverage.php" class="nav-link">
                            <span class="nav-icon">✅</span>
                            <span class="nav-text">Content Coverage</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage-school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <span class="nav-icon">📖</span>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">👤</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🚶</span>
                            <span class="nav-text">Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_timebook.php" class="nav-link">
                            <span class="nav-icon">⏰</span>
                            <span class="nav-text">Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_attendance.php" class="nav-link">
                            <span class="nav-icon">📋</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments_dashboard.php" class="nav-link">
                            <span class="nav-icon">💰</span>
                            <span class="nav-text">School Fees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="sessions.php" class="nav-link">
                            <span class="nav-icon">📅</span>
                            <span class="nav-text">School Sessions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_calendar.php" class="nav-link">
                            <span class="nav-icon">🗓️</span>
                            <span class="nav-text">School Calendar</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="applicants.php" class="nav-link">
                            <span class="nav-icon">📄</span>
                            <span class="nav-text">Applicants</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Student Evaluations Management</h2>
                    <p>Monitor and manage student performance evaluations</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format(count($evaluations)); ?></span>
                        <span class="quick-stat-label">Total Evaluations</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value">
                            <?php echo array_reduce($evaluations, function($carry, $item) {
                                return $carry + (($item['academic'] === 'excellent' ||
                                                $item['non_academic'] === 'excellent') ? 1 : 0);
                            }, 0); ?>
                        </span>
                        <span class="quick-stat-label">Excellent Ratings</span>
                    </div>
                </div>
            </div>

            <!-- Success Message -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert" style="border-radius: 10px; border: none; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                    <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <!-- Evaluations Management Section -->
            <div class="dashboard-cards">
                <div class="card" style="grid-column: span 2;">
                    <div class="card-header" style="background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; border-radius: 15px 15px 0 0; padding: 1.5rem;">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Student Evaluations</h5>
                            <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addEvaluationModal" style="border-radius: 8px;">
                                <i class="fas fa-plus me-2"></i>Add New Evaluation
                            </button>
                        </div>
                    </div>

                    <div class="card-body" style="padding: 2rem;">
                        <!-- Evaluations Table -->
                        <div class="table-responsive">
                            <table class="table table-hover students-table" id="evaluationsTable">
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
                                                <div class="d-flex flex-column">
                                                    <strong><?php echo htmlspecialchars($eval['full_name']); ?></strong>
                                                    <small class="text-muted">Roll: <?php echo $eval['admission_no']; ?></small>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($eval['class_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge badge-info">Term <?php echo $eval['term']; ?></span>
                                                <br><small><?php echo $eval['academic_year'] ?? $eval['year']; ?></small>
                                            </td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['academic']); ?>"><?php echo ucfirst($eval['academic']); ?></span></td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['non_academic']); ?>"><?php echo ucfirst($eval['non_academic']); ?></span></td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['cognitive']); ?>"><?php echo ucfirst($eval['cognitive']); ?></span></td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['psychomotor']); ?>"><?php echo ucfirst($eval['psychomotor']); ?></span></td>
                                            <td><span class="badge badge-<?php echo str_replace('-', '', $eval['affective']); ?>"><?php echo ucfirst($eval['affective']); ?></span></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $eval['id']; ?>" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $eval['id']; ?>" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?delete_id=<?php echo $eval['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this evaluation?')" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-outline-success" onclick="printEvaluation(<?php echo $eval['id']; ?>)" title="Print">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- View Modal -->
                                        <div class="modal fade" id="viewModal<?php echo $eval['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header" style="background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white;">
                                                        <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Evaluation Details</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <h6 class="text-primary mb-3"><i class="fas fa-user me-2"></i>Student Information</h6>
                                                                <p><strong>Name:</strong> <?php echo htmlspecialchars($eval['full_name']); ?></p>
                                                                <p><strong>Class:</strong> <?php echo htmlspecialchars($eval['class_name'] ?? 'N/A'); ?></p>
                                                                <p><strong>Term:</strong> <?php echo $eval['term']; ?> | <strong>Year:</strong> <?php echo $eval['academic_year'] ?? $eval['year']; ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <h6 class="text-primary mb-3"><i class="fas fa-star me-2"></i>Ratings</h6>
                                                                <p><strong>Academic:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['academic']); ?>"><?php echo ucfirst($eval['academic']); ?></span></p>
                                                                <p><strong>Non-Academic:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['non_academic']); ?>"><?php echo ucfirst($eval['non_academic']); ?></span></p>
                                                                <p><strong>Cognitive:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['cognitive']); ?>"><?php echo ucfirst($eval['cognitive']); ?></span></p>
                                                                <p><strong>Psychomotor:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['psychomotor']); ?>"><?php echo ucfirst($eval['psychomotor']); ?></span></p>
                                                                <p><strong>Affective:</strong> <span class="badge badge-<?php echo str_replace('-', '', $eval['affective']); ?>"><?php echo ucfirst($eval['affective']); ?></span></p>
                                                            </div>
                                                        </div>
                                                        <?php if (!empty($eval['comments'])): ?>
                                                        <hr>
                                                        <h6 class="text-primary mb-3"><i class="fas fa-comment me-2"></i>Comments</h6>
                                                        <p><?php echo nl2br(htmlspecialchars($eval['comments'])); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="student_id" value="<?php echo $eval['student_id']; ?>">
                                                            <input type="hidden" name="term" value="<?php echo $eval['term']; ?>">
                                                            <input type="hidden" name="year" value="<?php echo $eval['academic_year'] ?? $eval['year']; ?>">
                                                            <button type="submit" name="export_pdf" class="btn btn-success">
                                                                <i class="fas fa-file-pdf me-2"></i>Export PDF
                                                            </button>
                                                        </form>
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Edit Modal -->
                                        <div class="modal fade" id="editModal<?php echo $eval['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <div class="modal-header" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white;">
                                                            <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Evaluation</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="evaluation_id" value="<?php echo $eval['id']; ?>">
                                                            <input type="hidden" name="update_evaluation" value="1">

                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-bold">Academic Performance</label>
                                                                    <select class="form-select" name="academic" required>
                                                                        <option value="excellent" <?php echo ($eval['academic'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                        <option value="very-good" <?php echo ($eval['academic'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                        <option value="good" <?php echo ($eval['academic'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                        <option value="needs-improvement" <?php echo ($eval['academic'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-bold">Non-Academic Activities</label>
                                                                    <select class="form-select" name="non_academic" required>
                                                                        <option value="excellent" <?php echo ($eval['non_academic'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                        <option value="very-good" <?php echo ($eval['non_academic'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                        <option value="good" <?php echo ($eval['non_academic'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                        <option value="needs-improvement" <?php echo ($eval['non_academic'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                            </div>

                                                            <div class="row mb-3">
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold">Cognitive Domain</label>
                                                                    <select class="form-select" name="cognitive" required>
                                                                        <option value="excellent" <?php echo ($eval['cognitive'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                        <option value="very-good" <?php echo ($eval['cognitive'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                        <option value="good" <?php echo ($eval['cognitive'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                        <option value="needs-improvement" <?php echo ($eval['cognitive'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold">Psychomotor Domain</label>
                                                                    <select class="form-select" name="psychomotor" required>
                                                                        <option value="excellent" <?php echo ($eval['psychomotor'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                        <option value="very-good" <?php echo ($eval['psychomotor'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                        <option value="good" <?php echo ($eval['psychomotor'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                        <option value="needs-improvement" <?php echo ($eval['psychomotor'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold">Affective Domain</label>
                                                                    <select class="form-select" name="affective" required>
                                                                        <option value="excellent" <?php echo ($eval['affective'] == 'excellent' ? 'selected' : ''); ?>>Excellent</option>
                                                                        <option value="very-good" <?php echo ($eval['affective'] == 'very-good' ? 'selected' : ''); ?>>Very Good</option>
                                                                        <option value="good" <?php echo ($eval['affective'] == 'good' ? 'selected' : ''); ?>>Good</option>
                                                                        <option value="needs-improvement" <?php echo ($eval['affective'] == 'needs-improvement' ? 'selected' : ''); ?>>Needs Improvement</option>
                                                                    </select>
                                                                </div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Comments & Recommendations</label>
                                                                <textarea class="form-control" name="comments" rows="4" placeholder="Enter additional comments, strengths, and areas for improvement..."><?php echo htmlspecialchars($eval['comments']); ?></textarea>
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
        </main>
    </div>

    <!-- Add Evaluation Modal -->
    <div class="modal fade" id="addEvaluationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header" style="background: linear-gradient(135deg, #10b981, #059669); color: white;">
                        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Evaluation</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="add_evaluation" value="1">

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Student</label>
                                <select class="form-select" name="student_id" required>
                                    <option value="">Select Student</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['id']; ?>">
                                            <?php echo htmlspecialchars($student['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Class</label>
                                <select class="form-select" name="class_id" required>
                                    <option value="">Select Class</option>
                                    <?php
                                    $classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();
                                    foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Year</label>
                                <input type="number" class="form-control" name="year" value="<?php echo date('Y'); ?>" required>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Term</label>
                                <select class="form-select" name="term" required>
                                    <option value="1">Term 1</option>
                                    <option value="2">Term 2</option>
                                    <option value="3">Term 3</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Academic Performance</label>
                                <select class="form-select" name="academic" required>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Non-Academic Activities</label>
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
                                <label class="form-label fw-bold">Cognitive Domain</label>
                                <select class="form-select" name="cognitive" required>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Psychomotor Domain</label>
                                <select class="form-select" name="psychomotor" required>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Affective Domain</label>
                                <select class="form-select" name="affective" required>
                                    <option value="excellent">Excellent</option>
                                    <option value="very-good">Very Good</option>
                                    <option value="good">Good</option>
                                    <option value="needs-improvement">Needs Improvement</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Comments & Recommendations</label>
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

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>About SahabFormMaster</h4>
                    <p>A comprehensive school management system designed for academic excellence and efficient administration.</p>
                </div>
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="manage-school.php">School Settings</a></li>
                        <li><a href="manage_user.php">User Management</a></li>
                        <li><a href="#">Support & Help</a></li>
                        <li><a href="#">Documentation</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact Information</h4>
                    <p>📧 admin@sahabformmaster.com</p>
                    <p>📱 +234 808 683 5607</p>
                    <p>🌐 www.sahabformmaster.com</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 SahabFormMaster. All rights reserved.</p>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <span>•</span>
                    <a href="#">Terms of Service</a>
                    <span>•</span>
                    <span>Version 2.0</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        // Print function
        function printEvaluation(evaluationId) {
            window.open(`print-evaluation.php?id=${evaluationId}`, '_blank');
        }

        // DataTable initialization
        $(document).ready(function() {
            $('#evaluationsTable').DataTable({
                "pageLength": 10,
                "responsive": true,
                "language": {
                    "search": "Search evaluations:",
                    "lengthMenu": "Show _MENU_ evaluations per page",
                    "info": "Showing _START_ to _END_ of _TOTAL_ evaluations"
                }
            });
        });

        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Add active class on scroll
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
