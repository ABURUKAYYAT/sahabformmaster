<?php
// teacher_class_activities.php
session_start();
require_once '../config/db.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
require_once '../includes/functions.php';
$current_school_id = require_school_auth();

$teacher_id = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';
$activity_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create' || $action === 'edit') {
        // Handle activity creation/editing
        $activity_data = [
            'title' => $_POST['title'] ?? '',
            'activity_type' => $_POST['activity_type'] ?? '',
            'subject_id' => $_POST['subject_id'] ?? '',
            'class_id' => $_POST['class_id'] ?? '',
            'description' => $_POST['description'] ?? '',
            'instructions' => $_POST['instructions'] ?? '',
            'due_date' => $_POST['due_date'] ?: null,
            'total_marks' => $_POST['total_marks'] ?? 100,
            'status' => $_POST['status'] ?? 'draft',
            'teacher_id' => $teacher_id
        ];

        if ($action === 'create') {
            // Insert new activity
            $sql = "INSERT INTO class_activities (school_id, title, activity_type, subject_id, class_id, description, instructions, due_date, total_marks, status, teacher_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $current_school_id,
                $activity_data['title'],
                $activity_data['activity_type'],
                $activity_data['subject_id'],
                $activity_data['class_id'],
                $activity_data['description'],
                $activity_data['instructions'],
                $activity_data['due_date'],
                $activity_data['total_marks'],
                $activity_data['status'],
                $activity_data['teacher_id']
            ]);
            
            header("Location: teacher_class_activities.php?action=activities&success=created");
            exit;
            
        } elseif ($action === 'edit' && $activity_id > 0) {
            // Update existing activity
            $sql = "UPDATE class_activities
                    SET title = ?, activity_type = ?, subject_id = ?, class_id = ?,
                        description = ?, instructions = ?, due_date = ?, total_marks = ?,
                        status = ?, updated_at = NOW()
                    WHERE id = ? AND teacher_id = ? AND school_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $activity_data['title'],
                $activity_data['activity_type'],
                $activity_data['subject_id'],
                $activity_data['class_id'],
                $activity_data['description'],
                $activity_data['instructions'],
                $activity_data['due_date'],
                $activity_data['total_marks'],
                $activity_data['status'],
                $activity_id,
                $teacher_id,
                $current_school_id
            ]);
            
            header("Location: teacher_class_activities.php?action=activities&success=updated");
            exit;
        }
        
    } elseif ($action === 'grade' && isset($_POST['submission_id'])) {
        // Handle grading
        $submission_id = intval($_POST['submission_id']);
        $marks = floatval($_POST['marks']);
        $feedback = $_POST['feedback'] ?? '';
        
        // Get max marks from activity
        $check_sql = "SELECT ca.total_marks
                      FROM student_submissions ss
                      JOIN class_activities ca ON ss.activity_id = ca.id
                      WHERE ss.id = ? AND ca.teacher_id = ? AND ca.school_id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$submission_id, $teacher_id, $current_school_id]);
        $result = $check_stmt->fetch();
        
        if ($result) {
            $max_marks = floatval($result['total_marks']);
            if ($marks > $max_marks) $marks = $max_marks;
            
            $update_sql = "UPDATE student_submissions 
                           SET marks_obtained = ?, feedback = ?, status = 'graded', graded_at = NOW() 
                           WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$marks, $feedback, $submission_id]);
            
            $activity_id = isset($_POST['activity_id']) ? intval($_POST['activity_id']) : 0;
            header("Location: teacher_class_activities.php?action=submissions&id=$activity_id&success=graded");
            exit;
        }
        
    } elseif ($action === 'delete' && $activity_id > 0) {
        // Handle deletion
        $delete_sql = "DELETE FROM class_activities WHERE id = ? AND teacher_id = ? AND school_id = ?";
        $delete_stmt = $pdo->prepare($delete_sql);
        $delete_stmt->execute([$activity_id, $teacher_id, $current_school_id]);
        
        header("Location: teacher_class_activities.php?action=activities&success=deleted");
        exit;
    }
}

// Get teacher's assigned classes and subjects
$assigned_classes = [];
$assigned_subjects = [];

// Get assigned classes - school-filtered
$class_query = "SELECT DISTINCT c.id, c.class_name
                FROM class_teachers ct
                JOIN classes c ON ct.class_id = c.id
                WHERE ct.teacher_id = ? AND c.school_id = ?";
$class_stmt = $pdo->prepare($class_query);
$class_stmt->execute([$teacher_id, $current_school_id]);
$assigned_classes = $class_stmt->fetchAll();

// Get assigned subjects - school-filtered
$subject_query = "SELECT DISTINCT s.id, s.subject_name
                  FROM subject_assignments sa
                  JOIN subjects s ON sa.subject_id = s.id
                  WHERE sa.teacher_id = ? AND s.school_id = ?";
$subject_stmt = $pdo->prepare($subject_query);
$subject_stmt->execute([$teacher_id, $current_school_id]);
$assigned_subjects = $subject_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Activities - Teacher</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f7fb; }

        .dashboard-container .main-content { width: 100%; }

        .main-container { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }

        .content-header,
        .form-page-modern,
        .dashboard-cards,
        .activity-list-modern,
        .filter-card-modern,
        .modal-content-modern,
        .alert-modern {
            background: #ffffff;
            border: 1px solid #cfe1ff;
            border-radius: 12px;
            box-shadow: none;
        }

        .content-header { padding: 1.25rem 1.5rem; margin-bottom: 1rem; }

        .form-header-modern,
        .filter-header-modern,
        .table-header-modern {
            background: #1d4ed8;
            color: #fff;
            border-radius: 12px 12px 0 0;
        }

        .btn-primary,
        .btn-modern-primary,
        .primary-action-modern {
            background: #1d4ed8;
            border: 1px solid #1d4ed8;
            color: #fff;
        }

        .btn-secondary,
        .btn-modern-secondary {
            background: #fff;
            border: 1px solid #1d4ed8;
            color: #1d4ed8;
        }

        .status-badge-modern {
            border-radius: 999px;
            font-weight: 600;
            padding: 0.25rem 0.6rem;
            font-size: 0.75rem;
        }

        .status-active-modern { background: #dcfce7; color: #166534; }
        .status-inactive-modern { background: #fee2e2; color: #991b1b; }

        .activity-card-modern {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: none;
        }

        .activity-actions-modern .btn-action-modern {
            border-radius: 8px;
        }

        .modal-modern {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 1050;
        }

        .modal-content-modern {
            position: absolute;
            inset: 50% auto auto 50%;
            transform: translate(-50%, -50%);
            max-width: 820px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            padding: 1.5rem;
        }

        @media (max-width: 768px) {
            .main-container { padding: 1rem; }
        }
    
        .activity-list-modern { padding: 0; margin-bottom: 1.25rem; }
        .activity-body-modern { padding: 1rem 1.25rem; }
        .activity-header-modern { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 0.75rem; }
        .activity-header-modern h4 { font-size: 1rem; font-weight: 700; margin: 0; color: #0f172a; }
        .activity-header-modern p { margin: 0; color: #64748b; font-size: 0.85rem; }
        .activity-meta-modern { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
        .activity-actions-modern { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .btn-modern-outline { border: 1px solid #1d4ed8; color: #1d4ed8; background: #fff; padding: 0.4rem 0.7rem; border-radius: 8px; }
        .btn-modern-success { background: #0ea5e9; color: #fff; border: 1px solid #0ea5e9; padding: 0.4rem 0.7rem; border-radius: 8px; }

    
        .tabs-modern { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
        .tab-modern { border: 1px solid #cfe1ff; background: #fff; color: #1d4ed8; padding: 0.5rem 0.9rem; border-radius: 999px; font-weight: 600; cursor: pointer; }
        .tab-modern.active { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }

        .form-card-modern, .filter-card-modern, .table-container-modern { border: 1px solid #cfe1ff; border-radius: 12px; background: #fff; overflow: hidden; margin-bottom: 1rem; }
        .form-body-modern { padding: 1.25rem; }
        .form-row-modern { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
        .form-group-modern { display: flex; flex-direction: column; gap: 0.35rem; }
        .form-label-modern { font-weight: 600; color: #475569; }
        .form-input-modern { border: 1px solid #cfe1ff; border-radius: 10px; padding: 0.6rem 0.8rem; }

        .table-header-modern { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.9rem 1.25rem; background: #1d4ed8; color: #fff; }
        .table-title-modern { font-weight: 700; }
        .table-wrapper-modern { overflow-x: auto; }
        .table-modern { width: 100%; border-collapse: collapse; }
        .table-modern th { background: #f1f5ff; color: #1e3a8a; text-align: left; padding: 0.75rem; border-bottom: 1px solid #e2e8f0; }
        .table-modern td { padding: 0.75rem; border-bottom: 1px solid #eef2ff; }

        .badge-modern { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .badge-published { background: #dbeafe; color: #1e40af; }
        .badge-draft { background: #f1f5f9; color: #64748b; }
        .badge-closed { background: #fee2e2; color: #991b1b; }

        .progress-modern { width: 100%; height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .progress-bar-modern { height: 100%; background: #1d4ed8; }

        .alert-modern { padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .alert-success-modern { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error-modern { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .btn-modern-sm { padding: 0.35rem 0.6rem; border-radius: 8px; border: none; cursor: pointer; }

        .primary-action-modern { border-radius: 999px; padding: 0.6rem 1rem; }

    
        .tabs-modern { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
        .tab-modern { border: 1px solid #cfe1ff; background: #fff; color: #1d4ed8; padding: 0.5rem 0.9rem; border-radius: 999px; font-weight: 600; cursor: pointer; }
        .tab-modern.active { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }

        .form-card-modern, .filter-card-modern, .table-container-modern { border: 1px solid #cfe1ff; border-radius: 12px; background: #fff; overflow: hidden; margin-bottom: 1rem; }
        .form-body-modern { padding: 1.25rem; }
        .form-row-modern { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
        .form-group-modern { display: flex; flex-direction: column; gap: 0.35rem; }
        .form-label-modern { font-weight: 600; color: #475569; }
        .form-input-modern { border: 1px solid #cfe1ff; border-radius: 10px; padding: 0.6rem 0.8rem; }

        .table-header-modern { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.9rem 1.25rem; background: #1d4ed8; color: #fff; }
        .table-title-modern { font-weight: 700; }
        .table-wrapper-modern { overflow-x: auto; }
        .table-modern { width: 100%; border-collapse: collapse; }
        .table-modern th { background: #f1f5ff; color: #1e3a8a; text-align: left; padding: 0.75rem; border-bottom: 1px solid #e2e8f0; }
        .table-modern td { padding: 0.75rem; border-bottom: 1px solid #eef2ff; }

        .badge-modern { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .badge-published { background: #dbeafe; color: #1e40af; }
        .badge-draft { background: #f1f5f9; color: #64748b; }
        .badge-closed { background: #fee2e2; color: #991b1b; }

        .progress-modern { width: 100%; height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .progress-bar-modern { height: 100%; background: #1d4ed8; }

        .alert-modern { padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .alert-success-modern { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error-modern { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .btn-modern-sm { padding: 0.35rem 0.6rem; border-radius: 8px; border: none; cursor: pointer; }

        .primary-action-modern { border-radius: 999px; padding: 0.6rem 1rem; }

    </style>
</head>
<body>
    <?php include '../includes/mobile_navigation.php'; ?>

    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Class Activities</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include '../includes/teacher_sidebar.php'; ?>
        <main class="main-content">
            <div class="main-container">
        
        <div class="content-header">
            <div class="welcome-section">
                <h2>Class Activities</h2>
                <p>Create, manage, and grade classroom activities for your assigned classes</p>
            </div>
            <div class="header-actions">
                <a href="teacher_class_activities.php?action=create" class="btn-modern-primary">
                    <i class="fas fa-plus-circle"></i>
                    <span>Create Activity</span>
                </a>
            </div>
        </div>

<?php
        // Show success messages
        if (isset($_GET['success'])) {
            $messages = [
                'created' => 'Activity created successfully!',
                'updated' => 'Activity updated successfully!',
                'deleted' => 'Activity deleted successfully!',
                'graded' => 'Submission graded successfully!'
            ];

            if (isset($messages[$_GET['success']])) {
                echo '<div class="alert-modern alert-success-modern animate-fade-in-up">
                        <i class="fas fa-check-circle"></i>
                        <span>' . $messages[$_GET['success']] . '</span>
                      </div>';
            }
        }
        ?>

        <?php if (in_array($action, ['create', 'edit']) && $action !== 'grade'): ?>
            <!-- Create/Edit Activity Form -->
            <?php
            $activity = null;
            if ($action === 'edit' && $activity_id > 0) {
                $activity_query = "SELECT * FROM class_activities WHERE id = ? AND teacher_id = ? AND school_id = ?";
                $activity_stmt = $pdo->prepare($activity_query);
                $activity_stmt->execute([$activity_id, $teacher_id, $current_school_id]);
                $activity = $activity_stmt->fetch();

                if (!$activity) {
                    echo '<div class="alert-modern alert-error-modern animate-fade-in-up">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Activity not found or access denied.</span>
                          </div>';
                    exit;
                }
            }
            ?>

            <div class="form-page-modern">
                <div class="form-card-modern">
                    <div class="form-header-modern">
                        <div class="form-title-modern">
                            <i class="fas fa-plus-circle"></i>
                            <?= $action === 'create' ? 'Create New Activity' : 'Edit Activity' ?>
                        </div>
                        <p class="text-center mb-0 opacity-75">
                            <?= $action === 'create' ? 'Create a new class activity for your students' : 'Modify existing activity details' ?>
                        </p>
                    </div>

                    <div class="form-body-modern">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <a href="teacher_class_activities.php?action=activities" class="btn-modern-outline">
                                <i class="fas fa-arrow-left"></i>
                                <span>Back to Activities</span>
                            </a>
                        </div>

                        <form method="POST">
                            <div class="form-row-modern">
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Activity Type *</label>
                                    <select class="form-input-modern" name="activity_type" required>
                                        <option value="">Select activity type</option>
                                        <option value="classwork" <?= ($activity['activity_type'] ?? '') === 'classwork' ? 'selected' : '' ?>>📝 Classwork</option>
                                        <option value="assignment" <?= ($activity['activity_type'] ?? '') === 'assignment' ? 'selected' : '' ?>>📋 Assignment</option>
                                        <option value="quiz" <?= ($activity['activity_type'] ?? '') === 'quiz' ? 'selected' : '' ?>>🧠 Quiz</option>
                                        <option value="project" <?= ($activity['activity_type'] ?? '') === 'project' ? 'selected' : '' ?>>🚀 Project</option>
                                        <option value="homework" <?= ($activity['activity_type'] ?? '') === 'homework' ? 'selected' : '' ?>>🏠 Homework</option>
                                    </select>
                                </div>
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Activity Title *</label>
                                    <input type="text" class="form-input-modern" name="title" value="<?= htmlspecialchars($activity['title'] ?? '') ?>" placeholder="Enter activity title" required>
                                </div>
                            </div>

                            <div class="form-row-modern">
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Subject *</label>
                                    <select class="form-input-modern" name="subject_id" required>
                                        <option value="">Select subject</option>
                                        <?php foreach ($assigned_subjects as $subject): ?>
                                            <option value="<?= $subject['id'] ?>" <?= ($activity['subject_id'] ?? '') == $subject['id'] ? 'selected' : '' ?>>
                                                <?= $subject['subject_name'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Class *</label>
                                    <select class="form-input-modern" name="class_id" required>
                                        <option value="">Select class</option>
                                        <?php foreach ($assigned_classes as $class): ?>
                                            <option value="<?= $class['id'] ?>" <?= ($activity['class_id'] ?? '') == $class['id'] ? 'selected' : '' ?>>
                                                <?= $class['class_name'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group-modern">
                                <label class="form-label-modern">Description</label>
                                <textarea class="form-input-modern" name="description" rows="3" placeholder="Brief description of the activity (optional)"><?= htmlspecialchars($activity['description'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group-modern">
                                <label class="form-label-modern">Detailed Instructions *</label>
                                <textarea class="form-input-modern" name="instructions" rows="4" placeholder="Provide detailed instructions for students" required><?= htmlspecialchars($activity['instructions'] ?? '') ?></textarea>
                            </div>

                            <div class="form-row-modern">
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Due Date & Time</label>
                                    <?php
                                    $due_date = $activity['due_date'] ?? '';
                                    if ($due_date) {
                                        $due_date = date('Y-m-d\TH:i', strtotime($due_date));
                                    }
                                    ?>
                                    <input type="datetime-local" class="form-input-modern" name="due_date" value="<?= $due_date ?>">
                                </div>
                                <div class="form-group-modern">
                                    <label class="form-label-modern">Total Marks</label>
                                    <input type="number" class="form-input-modern" name="total_marks" min="0" step="0.01" value="<?= $activity['total_marks'] ?? 100 ?>" placeholder="100">
                                </div>
                            </div>

                            <div class="form-group-modern">
                                <label class="form-label-modern">Publication Status</label>
                                <select class="form-input-modern" name="status">
                                    <option value="draft" <?= ($activity['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>📝 Draft (Save as draft)</option>
                                    <option value="published" <?= ($activity['status'] ?? '') === 'published' ? 'selected' : '' ?>>📢 Published (Students can see)</option>
                                </select>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a href="teacher_class_activities.php?action=activities" class="btn-modern-outline">
                                    <i class="fas fa-times"></i>
                                    <span>Cancel</span>
                                </a>
                                <button type="submit" class="btn-modern-primary">
                                    <i class="fas fa-save"></i>
                                    <span><?= $action === 'create' ? 'Create Activity' : 'Update Activity' ?></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'grade' && $activity_id > 0): ?>
            <!-- Grade Submission Page -->
            <?php
            $submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;

            $submission_query = "
                SELECT ss.*, st.full_name, st.admission_no, ca.title as activity_title,
                       ca.total_marks, ca.instructions, ca.activity_type
                FROM student_submissions ss
                JOIN students st ON ss.student_id = st.id
                JOIN class_activities ca ON ss.activity_id = ca.id
                WHERE ss.id = ? AND ca.teacher_id = ? AND ca.school_id = ? AND st.school_id = ?
            ";
            $submission_stmt = $pdo->prepare($submission_query);
            $submission_stmt->execute([$submission_id, $teacher_id, $current_school_id, $current_school_id]);
            $submission = $submission_stmt->fetch();

            if (!$submission) {
                echo '<div class="alert-modern alert-error-modern animate-fade-in-up">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Submission not found or access denied.</span>
                      </div>
                      <a href="teacher_class_activities.php?action=submissions&id=' . $activity_id . '" class="btn-modern-primary">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Submissions</span>
                      </a>';
                exit;
            }
            ?>

            <div class="form-page-modern">
                <!-- Activity Details Card -->
                
                            <div class="form-group-modern">
                                <label class="form-label-modern"><i class="fas fa-calendar"></i> Submitted On</label>
                                <div class="form-input-modern" style="background: var(--gray-50);">
                                    <?= date('M d, Y H:i', strtotime($submission['submitted_at'])) ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-row-modern">
                            <div class="form-group-modern">
                                <label class="form-label-modern"><i class="fas fa-trophy"></i> Maximum Marks</label>
                                <div class="form-input-modern" style="background: var(--gray-50);">
                                    <?= $submission['total_marks'] ?> marks
                                </div>
                            </div>
                            <div class="form-group-modern">
                                <label class="form-label-modern"><i class="fas fa-chart-line"></i> Current Marks</label>
                                <div class="form-input-modern" style="background: var(--gray-50);">
                                    <?php if ($submission['marks_obtained'] !== null): ?>
                                        <span class="badge-modern badge-published">
                                            <i class="fas fa-check"></i>
                                            <?= $submission['marks_obtained'] ?>/<?= $submission['total_marks'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-modern badge-draft">
                                            <i class="fas fa-clock"></i>
                                            Not graded yet
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern"><i class="fas fa-list"></i> Activity Instructions</label>
                            <div class="form-input-modern" style="background: var(--gray-50); min-height: 100px; resize: vertical;">
                                <?= nl2br(htmlspecialchars($submission['instructions'])) ?>
                            </div>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern"><i class="fas fa-file-alt"></i> Student's Submission</label>
                            <div class="form-input-modern" style="background: white; min-height: 120px; resize: vertical;">
                                <?php if ($submission['submission_text']): ?>
                                    <?= nl2br(htmlspecialchars($submission['submission_text'])) ?>
                                <?php else: ?>
                                    <em class="text-muted">No text submitted</em>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($submission['attachment_path']): ?>
                        <div class="form-group-modern">
                            <label class="form-label-modern"><i class="fas fa-paperclip"></i> Attachment</label>
                            <a href="<?= $submission['attachment_path'] ?>" class="btn-modern-primary" target="_blank">
                                <i class="fas fa-download"></i>
                                <span>Download Attachment</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Grading Form -->
                <div class="form-card-modern animate-fade-in-up">
                    <div class="form-header-modern">
                        <div class="form-title-modern">
                            <i class="fas fa-edit"></i>
                            Grade Submission
                        </div>
                        <p class="text-center mb-0 opacity-75">
                            Provide marks and feedback for this submission
                        </p>
                    </div>

                    <div class="form-body-modern">
                        <form method="POST">
                            <input type="hidden" name="submission_id" value="<?= $submission_id ?>">
                            <input type="hidden" name="activity_id" value="<?= $activity_id ?>">

                            <div class="form-row-modern">
                                <div class="form-group-modern">
                                    <label class="form-label-modern">
                                        <i class="fas fa-star"></i>
                                        Marks (Maximum: <?= $submission['total_marks'] ?>) *
                                    </label>
                                    <input type="number" class="form-input-modern" name="marks"
                                           value="<?= $submission['marks_obtained'] ?>"
                                           min="0" max="<?= $submission['total_marks'] ?>" step="0.01"
                                           placeholder="Enter marks" required>
                                </div>
                            </div>

                            <div class="form-group-modern">
                                <label class="form-label-modern">
                                    <i class="fas fa-comment"></i>
                                    Feedback
                                </label>
                                <textarea class="form-input-modern" name="feedback" rows="4"
                                          placeholder="Provide constructive feedback for the student..."><?= htmlspecialchars($submission['feedback'] ?? '') ?></textarea>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a href="teacher_class_activities.php?action=submissions&id=<?= $activity_id ?>" class="btn-modern-outline">
                                    <i class="fas fa-times"></i>
                                    <span>Cancel</span>
                                </a>
                                <button type="submit" class="btn-modern-primary">
                                    <i class="fas fa-save"></i>
                                    <span>Save Grade</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Main Dashboard/Activities/Submissions/Reports -->
            <!-- Modern Welcome Card -->
            
            </div>

            <!-- Modern Tabs Navigation -->
            <div class="tabs-modern">
                <button class="tab-modern <?= $action === 'dashboard' || $action === 'index' ? 'active' : '' ?>"
                        onclick="window.location.href='?action=dashboard'">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </button>
                <button class="tab-modern <?= $action === 'activities' ? 'active' : '' ?>"
                        onclick="window.location.href='?action=activities'">
                    <i class="fas fa-tasks"></i>
                    <span>My Activities</span>
                </button>
                <button class="tab-modern <?= $action === 'submissions' ? 'active' : '' ?>"
                        onclick="window.location.href='?action=submissions'">
                    <i class="fas fa-paper-plane"></i>
                    <span>Submissions</span>
                </button>
                <button class="tab-modern <?= $action === 'reports' ? 'active' : '' ?>"
                        onclick="window.location.href='?action=reports'">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </button>
            </div>

            <!-- Tab Content -->
            <div class="tab-content">
                <?php if ($action === 'dashboard'): ?>
                    <!-- Dashboard Content -->
                    <!-- Statistics Grid -->
                    <div class="stats-modern animate-slide-in-left">
                        <?php
                        $total_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM class_activities WHERE teacher_id = ?");
                        $total_stmt->execute([$teacher_id]);
                        $total_activities = $total_stmt->fetch()['total'];

                        $pending_stmt = $pdo->prepare("
                            SELECT COUNT(DISTINCT a.id) as pending
                            FROM class_activities a
                            LEFT JOIN student_submissions s ON a.id = s.activity_id
                            WHERE a.teacher_id = ?
                            AND a.status = 'published'
                            AND (s.status IS NULL OR s.status = 'pending')
                        ");
                        $pending_stmt->execute([$teacher_id]);
                        $pending = $pending_stmt->fetch()['pending'];

                        $graded_stmt = $pdo->prepare("
                            SELECT COUNT(*) as graded FROM student_submissions s
                            JOIN class_activities a ON s.activity_id = a.id
                            WHERE a.teacher_id = ? AND s.status = 'graded'
                        ");
                        $graded_stmt->execute([$teacher_id]);
                        $graded = $graded_stmt->fetch()['graded'];

                        $upcoming_stmt = $pdo->prepare("
                            SELECT COUNT(*) as upcoming FROM class_activities
                            WHERE teacher_id = ?
                            AND due_date > NOW()
                            AND due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                        ");
                        $upcoming_stmt->execute([$teacher_id]);
                        $upcoming = $upcoming_stmt->fetch()['upcoming'];
                        ?>

                        <div class="stat-card-modern stat-total animate-slide-in-left">
                            <div class="stat-icon-modern">
                                <i class="fas fa-tasks"></i>
                            </div>
                            <div class="stat-value-modern"><?php echo $total_activities; ?></div>
                            <div class="stat-label-modern">Total Activities</div>
                        </div>

                        <div class="stat-card-modern stat-pending animate-slide-in-left">
                            <div class="stat-icon-modern">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-value-modern"><?php echo $pending; ?></div>
                            <div class="stat-label-modern">Pending Submissions</div>
                        </div>

                        <div class="stat-card-modern stat-graded animate-slide-in-right">
                            <div class="stat-icon-modern">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-value-modern"><?php echo $graded; ?></div>
                            <div class="stat-label-modern">Graded Submissions</div>
                        </div>

                        <div class="stat-card-modern stat-upcoming animate-slide-in-right">
                            <div class="stat-icon-modern">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="stat-value-modern"><?php echo $upcoming; ?></div>
                            <div class="stat-label-modern">Upcoming Due</div>
                        </div>
                    </div>

                    <!-- Recent Activities -->
                    <?php
                    $recent_stmt = $pdo->prepare("
                        SELECT ca.*, s.subject_name, c.class_name
                        FROM class_activities ca
                        JOIN subjects s ON ca.subject_id = s.id
                        JOIN classes c ON ca.class_id = c.id
                        WHERE ca.teacher_id = ? AND ca.school_id = ?
                        ORDER BY ca.created_at DESC
                        LIMIT 5
                    ");
                    $recent_stmt->execute([$teacher_id, $current_school_id]);
                    $recent_activities = $recent_stmt->fetchAll();
                    ?>
                    <div class="activity-list-modern animate-fade-in-up">
                        <div class="table-header-modern">
                            <div class="table-title-modern">
                                <i class="fas fa-clock"></i>
                                Recent Activities
                            </div>
                            <a href="?action=activities" class="btn-modern-secondary">
                                <i class="fas fa-list"></i>
                                <span>View All</span>
                            </a>
                        </div>
                        <div class="activity-body-modern">
                            <?php if (!empty($recent_activities)): ?>
                                <?php foreach ($recent_activities as $activity): ?>
                                    <div class="activity-card-modern activity-<?= $activity['activity_type'] ?> animate-fade-in-up">
                                        <div class="activity-header-modern">
                                            <div>
                                                <h4><?= htmlspecialchars($activity['title']) ?></h4>
                                                <p><?= htmlspecialchars($activity['subject_name']) ?> - <?= htmlspecialchars($activity['class_name']) ?></p>
                                            </div>
                                            <span class="status-badge-modern <?= $activity['status'] === 'published' ? 'status-active-modern' : 'status-inactive-modern' ?>">
                                                <?= ucfirst($activity['status']) ?>
                                            </span>
                                        </div>
                                        <div class="activity-meta-modern">
                                            <span class="badge-modern badge-<?= $activity['status'] === 'published' ? 'published' : 'draft' ?>">
                                                <i class="fas fa-<?= $activity['status'] === 'published' ? 'eye' : 'edit' ?>"></i>
                                                <?= ucfirst($activity['status']) ?>
                                            </span>
                                            <?php if ($activity['due_date']): ?>
                                                <span class="badge-modern <?= strtotime($activity['due_date']) < time() ? 'badge-closed' : 'badge-published' ?>">
                                                    <i class="fas fa-calendar"></i>
                                                    Due: <?= date('M d, Y H:i', strtotime($activity['due_date'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-actions-modern">
                                            <a href="?action=submissions&id=<?= $activity['id'] ?>" class="btn-modern-outline">
                                                <i class="fas fa-eye"></i>
                                                <span>View</span>
                                            </a>
                                            <a href="?action=edit&id=<?= $activity['id'] ?>" class="btn-modern-success">
                                                <i class="fas fa-edit"></i>
                                                <span>Edit</span>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No activities created yet</h5>
                                    <p class="text-muted">Start by creating your first class activity!</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

<?php elseif ($action === 'activities'): ?>
                    <!-- My Activities Content -->
                    <div class="table-container-modern animate-fade-in-up">
                        <div class="table-header-modern">
                            <div class="table-title-modern">
                                <i class="fas fa-tasks"></i>
                                My Activities
                            </div>
                            <div class="table-filters-modern">
                                <select class="form-input-modern" id="filterType" style="width: 150px; padding: 0.5rem;">
                                    <option value="">All Types</option>
                                    <option value="classwork">Classwork</option>
                                    <option value="assignment">Assignment</option>
                                    <option value="quiz">Quiz</option>
                                    <option value="project">Project</option>
                                    <option value="homework">Homework</option>
                                </select>
                                <select class="form-input-modern" id="filterStatus" style="width: 150px; padding: 0.5rem;">
                                    <option value="">All Status</option>
                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>
                        </div>

                        <div class="table-wrapper-modern">
                            <table class="table-modern" id="activitiesTable">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-book"></i> Title</th>
                                        <th><i class="fas fa-tag"></i> Type</th>
                                        <th><i class="fas fa-graduation-cap"></i> Subject</th>
                                        <th><i class="fas fa-users"></i> Class</th>
                                        <th><i class="fas fa-calendar"></i> Due Date</th>
                                        <th><i class="fas fa-info-circle"></i> Status</th>
                                        <th><i class="fas fa-chart-line"></i> Progress</th>
                                        <th><i class="fas fa-cogs"></i> Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $activities_query = "
                                        SELECT ca.*, s.subject_name, c.class_name,
                                        (SELECT COUNT(*) FROM student_submissions ss WHERE ss.activity_id = ca.id) as total_submissions,
                                        (SELECT COUNT(*) FROM student_submissions ss WHERE ss.activity_id = ca.id AND ss.status = 'graded') as graded_submissions
                                        FROM class_activities ca
                                        JOIN subjects s ON ca.subject_id = s.id
                                        JOIN classes c ON ca.class_id = c.id
                                        WHERE ca.teacher_id = ? AND ca.school_id = ?
                                        ORDER BY ca.created_at DESC
                                    ";
                                    $activities_stmt = $pdo->prepare($activities_query);
                                    $activities_stmt->execute([$teacher_id, $current_school_id]);
                                    $activities = $activities_stmt->fetchAll();

                                    foreach ($activities as $activity):
                                        $submission_rate = $activity['total_submissions'] > 0 ?
                                            round(($activity['graded_submissions'] / $activity['total_submissions']) * 100, 0) : 0;
                                    ?>
                                    <tr data-type="<?= $activity['activity_type'] ?>" data-status="<?= $activity['status'] ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($activity['title']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge-modern badge-published">
                                                <i class="fas fa-tag"></i>
                                                <?= ucfirst($activity['activity_type']) ?>
                                            </span>
                                        </td>
                                        <td><?= $activity['subject_name'] ?></td>
                                        <td><?= $activity['class_name'] ?></td>
                                        <td>
                                            <?php if ($activity['due_date']): ?>
                                                <div>
                                                    <div><?= date('M d, Y', strtotime($activity['due_date'])) ?></div>
                                                    <?php if (strtotime($activity['due_date']) < time()): ?>
                                                        <span class="badge-modern badge-closed">
                                                            <i class="fas fa-exclamation-triangle"></i>
                                                            Overdue
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">No due date</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-modern badge-<?= $activity['status'] === 'published' ? 'published' : ($activity['status'] === 'closed' ? 'closed' : 'draft') ?>">
                                                <i class="fas fa-<?= $activity['status'] === 'published' ? 'eye' : ($activity['status'] === 'closed' ? 'lock' : 'edit') ?>"></i>
                                                <?= ucfirst($activity['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="progress-modern">
                                                <div class="progress-bar-modern" style="width: <?= $submission_rate ?>%"></div>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-chart-line"></i>
                                                <?= $activity['graded_submissions'] ?>/<?= $activity['total_submissions'] ?> graded
                                            </small>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem;">
                                                <a href="?action=submissions&id=<?= $activity['id'] ?>"
                                                   class="btn-modern-outline" title="View Submissions">
                                                    <i class="fas fa-eye"></i>
                                                    <span>View</span>
                                                </a>
                                                <a href="?action=edit&id=<?= $activity['id'] ?>"
                                                   class="btn-modern-success" title="Edit Activity">
                                                    <i class="fas fa-edit"></i>
                                                    <span>Edit</span>
                                                </a>
                                                <button onclick="deleteActivity(<?= $activity['id'] ?>)"
                                                        class="btn-modern-sm" style="background: var(--error-500); color: white;" title="Delete Activity">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($action === 'submissions'): ?>
                    <!-- Submissions Management -->
                    <?php if ($activity_id > 0): ?>
                        <!-- Single Activity Submissions -->
                        <?php
                        $activity_query = "
                            SELECT ca.*, s.subject_name, c.class_name
                            FROM class_activities ca
                            JOIN subjects s ON ca.subject_id = s.id
                            JOIN classes c ON ca.class_id = c.id
                            WHERE ca.id = ? AND ca.teacher_id = ? AND ca.school_id = ?
                        ";
                        $activity_stmt = $pdo->prepare($activity_query);
                        $activity_stmt->execute([$activity_id, $teacher_id, $current_school_id]);
                        $activity = $activity_stmt->fetch();

                        if (!$activity) {
                            echo '<div class="alert-modern alert-error-modern animate-fade-in-up">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Activity not found or access denied.</span>
                                  </div>';
                        } else {
                        ?>
                        <!-- Activity Overview Card -->
                        
                                    <div class="form-group-modern">
                                        <label class="form-label-modern"><i class="fas fa-trophy"></i> Total Marks</label>
                                        <div class="form-input-modern" style="background: var(--gray-50);">
                                            <?= $activity['total_marks'] ?> marks
                                        </div>
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern"><i class="fas fa-info-circle"></i> Status</label>
                                        <div class="form-input-modern" style="background: var(--gray-50);">
                                            <span class="badge-modern badge-<?= $activity['status'] === 'published' ? 'published' : 'draft' ?>">
                                                <i class="fas fa-<?= $activity['status'] === 'published' ? 'eye' : 'edit' ?>"></i>
                                                <?= ucfirst($activity['status']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Activity Instructions -->
                                <div class="form-group-modern">
                                    <label class="form-label-modern"><i class="fas fa-list"></i> Activity Instructions</label>
                                    <div class="form-input-modern" style="background: var(--gray-50); min-height: 100px; resize: vertical;">
                                        <?= nl2br(htmlspecialchars($activity['instructions'])) ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Submissions Table -->
                        <div class="table-container-modern animate-fade-in-up">
                            <div class="table-header-modern">
                                <div class="table-title-modern">
                                    <i class="fas fa-users"></i>
                                    Student Submissions
                                </div>
                            </div>

                            <div class="table-wrapper-modern">
                                <table class="table-modern">
                                    <thead>
                                        <tr>
                                            <th><i class="fas fa-user"></i> Student</th>
                                            <th><i class="fas fa-calendar"></i> Submitted On</th>
                                            <th><i class="fas fa-info-circle"></i> Status</th>
                                            <th><i class="fas fa-trophy"></i> Marks</th>
                                            <th><i class="fas fa-cogs"></i> Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $submissions_query = "
                                            SELECT ss.*, st.full_name, st.admission_no
                                            FROM student_submissions ss
                                            JOIN students st ON ss.student_id = st.id
                                            WHERE ss.activity_id = ?
                                            ORDER BY ss.submitted_at DESC
                                        ";
                                        $submissions_stmt = $pdo->prepare($submissions_query);
                                        $submissions_stmt->execute([$activity_id]);
                                        $submissions = $submissions_stmt->fetchAll();

                                        foreach ($submissions as $submission):
                                        ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?= htmlspecialchars($submission['full_name']) ?></strong><br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-id-card"></i>
                                                        <?= $submission['admission_no'] ?>
                                                    </small>
                                                </div>
                                            </td>
                                            <td>
                                                <div>
                                                    <i class="fas fa-calendar-check"></i>
                                                    <?= $submission['submitted_at'] ? date('M d, Y H:i', strtotime($submission['submitted_at'])) : 'Not submitted' ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge-modern badge-<?=
                                                    $submission['status'] === 'graded' ? 'published' :
                                                    ($submission['status'] === 'late' ? 'draft' :
                                                    ($submission['status'] === 'submitted' ? 'published' : 'closed'))
                                                ?>">
                                                    <i class="fas fa-<?=
                                                        $submission['status'] === 'graded' ? 'check-circle' :
                                                        ($submission['status'] === 'late' ? 'clock' :
                                                        ($submission['status'] === 'submitted' ? 'paper-plane' : 'times-circle'))
                                                    ?>"></i>
                                                    <?= ucfirst($submission['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($submission['marks_obtained'] !== null): ?>
                                                    <div>
                                                        <strong class="text-success">
                                                            <i class="fas fa-star"></i>
                                                            <?= $submission['marks_obtained'] ?>/<?= $activity['total_marks'] ?>
                                                        </strong>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">
                                                        <i class="fas fa-minus"></i>
                                                        Not graded
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="?action=grade&id=<?= $activity_id ?>&submission_id=<?= $submission['id'] ?>"
                                                   class="btn-modern-primary">
                                                    <i class="fas fa-edit"></i>
                                                    <span>Grade</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php } ?>
                    <?php else: ?>
                        <!-- All Submissions -->
                        <div class="table-container-modern animate-fade-in-up">
                            <div class="table-header-modern">
                                <div class="table-title-modern">
                                    <i class="fas fa-paper-plane"></i>
                                    All Submissions
                                </div>
                            </div>

                            <div class="table-wrapper-modern">
                                <table class="table-modern">
                                    <thead>
                                        <tr>
                                            <th><i class="fas fa-graduation-cap"></i> Activity</th>
                                            <th><i class="fas fa-user"></i> Student</th>
                                            <th><i class="fas fa-users"></i> Class</th>
                                            <th><i class="fas fa-calendar"></i> Submitted</th>
                                            <th><i class="fas fa-info-circle"></i> Status</th>
                                            <th><i class="fas fa-trophy"></i> Marks</th>
                                            <th><i class="fas fa-cogs"></i> Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $all_submissions_query = "
                                            SELECT ss.*, ca.title as activity_title, ca.activity_type, ca.total_marks, ca.id as activity_id,
                                                   st.full_name as student_name, st.admission_no,
                                                   c.class_name
                                            FROM student_submissions ss
                                            JOIN class_activities ca ON ss.activity_id = ca.id
                                            JOIN students st ON ss.student_id = st.id
                                            JOIN classes c ON st.class_id = c.id
                                            WHERE ca.teacher_id = ?
                                            ORDER BY ss.updated_at DESC
                                            LIMIT 50
                                        ";
                                        $all_submissions_stmt = $pdo->prepare($all_submissions_query);
                                        $all_submissions_stmt->execute([$teacher_id]);
                                        $all_submissions = $all_submissions_stmt->fetchAll();

                                        foreach ($all_submissions as $submission):
                                        ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?= htmlspecialchars($submission['activity_title']) ?></strong><br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-tag"></i>
                                                        <?= ucfirst($submission['activity_type']) ?>
                                                    </small>
                                                </div>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?= htmlspecialchars($submission['student_name']) ?></strong><br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-id-card"></i>
                                                        <?= $submission['admission_no'] ?>
                                                    </small>
                                                </div>
                                            </td>
                                            <td>
                                                <i class="fas fa-school"></i>
                                                <?= $submission['class_name'] ?>
                                            </td>
                                            <td>
                                                <i class="fas fa-calendar-check"></i>
                                                <?= date('M d, Y', strtotime($submission['submitted_at'])) ?>
                                            </td>
                                            <td>
                                                <span class="badge-modern badge-<?=
                                                    $submission['status'] === 'graded' ? 'published' :
                                                    ($submission['status'] === 'late' ? 'draft' :
                                                    ($submission['status'] === 'submitted' ? 'published' : 'closed'))
                                                ?>">
                                                    <i class="fas fa-<?=
                                                        $submission['status'] === 'graded' ? 'check-circle' :
                                                        ($submission['status'] === 'late' ? 'clock' :
                                                        ($submission['status'] === 'submitted' ? 'paper-plane' : 'times-circle'))
                                                    ?>"></i>
                                                    <?= ucfirst($submission['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($submission['marks_obtained'] !== null): ?>
                                                    <strong class="text-success">
                                                        <i class="fas fa-star"></i>
                                                        <?= $submission['marks_obtained'] ?>/<?= $submission['total_marks'] ?>
                                                    </strong>
                                                <?php else: ?>
                                                    <span class="text-muted">
                                                        <i class="fas fa-minus"></i>
                                                        Not graded
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="?action=submissions&id=<?= $submission['activity_id'] ?>"
                                                   class="btn-modern-outline">
                                                    <i class="fas fa-eye"></i>
                                                    <span>View</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php elseif ($action === 'reports'): ?>
                    <!-- Reports Section -->
                    <!-- Chart Cards -->
                    <div class="stats-modern animate-slide-in-left">
                        <div class="stat-card-modern animate-slide-in-left" style="grid-column: span 2;">
                            <div class="stat-icon-modern" style="background: var(--gradient-accent);">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <div class="stat-value-modern">Performance Analytics</div>
                            <div class="stat-label-modern">Activity Type Performance & Submission Statistics</div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
                        <!-- Performance by Activity Type Chart -->
                        
                        </div>

                        <!-- Submission Statistics Chart -->
                        
                        </div>
                    </div>

                    <!-- Detailed Activity Report Table -->
                    <div class="table-container-modern animate-fade-in-up">
                        <div class="table-header-modern">
                            <div class="table-title-modern">
                                <i class="fas fa-table"></i>
                                Detailed Activity Report
                            </div>
                        </div>

                        <div class="table-wrapper-modern">
                            <table class="table-modern">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-graduation-cap"></i> Activity</th>
                                        <th><i class="fas fa-tag"></i> Type</th>
                                        <th><i class="fas fa-users"></i> Class</th>
                                        <th><i class="fas fa-user-friends"></i> Total Students</th>
                                        <th><i class="fas fa-paper-plane"></i> Submitted</th>
                                        <th><i class="fas fa-check-circle"></i> Graded</th>
                                        <th><i class="fas fa-chart-line"></i> Avg Score</th>
                                        <th><i class="fas fa-percentage"></i> Submission Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $report_query = "
                                        SELECT ca.id, ca.title, ca.activity_type, c.class_name,
                                               (SELECT COUNT(*) FROM students st WHERE st.class_id = ca.class_id) as total_students,
                                               COUNT(ss.id) as submitted_count,
                                               SUM(CASE WHEN ss.status = 'graded' THEN 1 ELSE 0 END) as graded_count,
                                               AVG(ss.marks_obtained) as avg_score
                                        FROM class_activities ca
                                        JOIN classes c ON ca.class_id = c.id
                                        LEFT JOIN student_submissions ss ON ca.id = ss.activity_id
                                        WHERE ca.teacher_id = ?
                                        GROUP BY ca.id
                                        ORDER BY ca.created_at DESC
                                    ";
                                    $report_stmt = $pdo->prepare($report_query);
                                    $report_stmt->execute([$teacher_id]);
                                    $reports = $report_stmt->fetchAll();

                                    foreach ($reports as $report):
                                        $submission_rate = $report['total_students'] > 0 ?
                                            round(($report['submitted_count'] / $report['total_students']) * 100, 0) : 0;
                                        $avg_score = $report['avg_score'] ? number_format($report['avg_score'], 1) : '-';
                                    ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong class="text-primary">
                                                    <i class="fas fa-book"></i>
                                                    <?= htmlspecialchars($report['title']) ?>
                                                </strong>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge-modern badge-published">
                                                <i class="fas fa-tag"></i>
                                                <?= ucfirst($report['activity_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="fas fa-school"></i>
                                            <?= $report['class_name'] ?>
                                        </td>
                                        <td>
                                            <div class="text-center">
                                                <strong class="font-semibold">
                                                    <i class="fas fa-users"></i>
                                                    <?= $report['total_students'] ?>
                                                </strong>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-center">
                                                <span class="badge-modern badge-published">
                                                    <i class="fas fa-paper-plane"></i>
                                                    <?= $report['submitted_count'] ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-center">
                                                <span class="badge-modern badge-<?php echo $report['graded_count'] > 0 ? 'published' : 'draft'; ?>">
                                                    <i class="fas fa-check-circle"></i>
                                                    <?= $report['graded_count'] ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-center">
                                                <?php if ($avg_score !== '-'): ?>
                                                    <strong class="text-success">
                                                        <i class="fas fa-star"></i>
                                                        <?= $avg_score ?>
                                                    </strong>
                                                <?php else: ?>
                                                    <span class="text-muted">
                                                        <i class="fas fa-minus"></i>
                                                        N/A
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <div class="progress-modern">
                                                    <div class="progress-bar-modern" style="width: <?= $submission_rate ?>%"></div>
                                                </div>
                                                <div class="text-center mt-1">
                                                    <strong class="font-semibold">
                                                        <i class="fas fa-percentage"></i>
                                                        <?= $submission_rate ?>%
                                                    </strong>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

        </main>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Filter activities table
        document.addEventListener('DOMContentLoaded', function() {
            const filterType = document.getElementById('filterType');
            const filterStatus = document.getElementById('filterStatus');
            const activitiesTable = document.getElementById('activitiesTable');
            
            if (filterType && filterStatus && activitiesTable) {
                function filterTable() {
                    const typeValue = filterType.value;
                    const statusValue = filterStatus.value;
                    const rows = activitiesTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    
                    for (let row of rows) {
                        const rowType = row.getAttribute('data-type');
                        const rowStatus = row.getAttribute('data-status');
                        
                        const typeMatch = !typeValue || rowType === typeValue;
                        const statusMatch = !statusValue || rowStatus === statusValue;
                        
                        row.style.display = (typeMatch && statusMatch) ? '' : 'none';
                    }
                }
                
                filterType.addEventListener('change', filterTable);
                filterStatus.addEventListener('change', filterTable);
            }

            // Charts for reports
            <?php if ($action === 'reports'): ?>
            // Type Performance Chart
            const typeCtx = document.getElementById('typePerformanceChart').getContext('2d');
            new Chart(typeCtx, {
                type: 'bar',
                data: {
                    labels: ['Classwork', 'Assignment', 'Quiz', 'Project', 'Homework'],
                    datasets: [{
                        label: 'Average Score',
                        data: [85, 78, 92, 88, 80],
                        backgroundColor: ['#198754', '#0dcaf0', '#ffc107', '#6f42c1', '#fd7e14']
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // Submission Stats Chart
            const statsCtx = document.getElementById('submissionStatsChart').getContext('2d');
            new Chart(statsCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Submitted', 'Graded', 'Pending', 'Late'],
                    datasets: [{
                        data: [65, 45, 20, 5],
                        backgroundColor: ['#198754', '#0dcaf0', '#ffc107', '#dc3545']
                    }]
                }
            });
            <?php endif; ?>
        });

        function deleteActivity(id) {
            if (confirm('Are you sure you want to delete this activity? This will also delete all submissions.')) {
                window.location.href = 'teacher_class_activities.php?action=delete&id=' + id;
            }
        }
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
