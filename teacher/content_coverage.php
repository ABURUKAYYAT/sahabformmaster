<?php
// teacher/content_coverage.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow teachers to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// Get current school context
$current_school_id = require_school_auth();

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_coverage') {
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $term = trim($_POST['term'] ?? '');
        $week = intval($_POST['week'] ?? 0);
        $date_covered = trim($_POST['date_covered'] ?? '');
        $time_start = trim($_POST['time_start'] ?? '');
        $time_end = trim($_POST['time_end'] ?? '');
        $period = trim($_POST['period'] ?? '');
        $topics_covered = trim($_POST['topics_covered'] ?? '');
        $objectives_achieved = trim($_POST['objectives_achieved'] ?? '');
        $resources_used = trim($_POST['resources_used'] ?? '');
        $assessment_done = trim($_POST['assessment_done'] ?? '');
        $challenges = trim($_POST['challenges'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if ($subject_id <= 0) $errors[] = 'Subject is required.';
        if ($class_id <= 0) $errors[] = 'Class is required.';
        if (empty($term)) $errors[] = 'Term is required.';
        if (empty($date_covered)) $errors[] = 'Date covered is required.';
        if (empty($topics_covered)) $errors[] = 'Topics covered is required.';

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO content_coverage
                    (school_id, teacher_id, subject_id, class_id, term, week, date_covered, time_start, time_end, period,
                     topics_covered, objectives_achieved, resources_used, assessment_done, challenges, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $stmt->execute([
                    $current_school_id, $teacher_id, $subject_id, $class_id, $term, $week, $date_covered,
                    $time_start ?: null, $time_end ?: null, $period ?: null,
                    $topics_covered, $objectives_achieved ?: null, $resources_used ?: null,
                    $assessment_done ?: null, $challenges ?: null, $notes ?: null
                ]);

                $success = 'Content coverage submitted successfully and is pending principal approval.';

                // Reset form
                $_POST = [];
            } catch (Exception $e) {
                $errors[] = 'Failed to submit coverage: ' . $e->getMessage();
            }
        }
    }
}

// Get teacher's assigned subjects and classes (school-filtered)
$stmt = $pdo->prepare("
    SELECT DISTINCT
        s.id as subject_id, s.subject_name,
        c.id as class_id, c.class_name,
        sa.assigned_at
    FROM subject_assignments sa
    JOIN subjects s ON sa.subject_id = s.id AND s.school_id = ?
    JOIN classes c ON sa.class_id = c.id AND c.school_id = ?
    WHERE sa.teacher_id = ?
    ORDER BY s.subject_name, c.class_name
");
$stmt->execute([$current_school_id, $current_school_id, $teacher_id]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get existing coverage entries for this teacher (school-filtered)
$stmt = $pdo->prepare("
    SELECT cc.*,
           s.subject_name, cl.class_name,
           u.full_name as principal_name
    FROM content_coverage cc
    JOIN subjects s ON cc.subject_id = s.id
    JOIN classes cl ON cc.class_id = cl.id
    LEFT JOIN users u ON cc.principal_id = u.id
    WHERE cc.teacher_id = ? AND cc.school_id = ?
    ORDER BY cc.date_covered DESC, cc.submitted_at DESC
");
$stmt->execute([$teacher_id, $current_school_id]);
$coverage_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current academic year
$current_year = date('Y') . '/' . (date('Y') + 1);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Coverage | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f5f7fb;
        }

        .dashboard-container .main-content {
            width: 100%;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .panel,
        .content-header,
        .alert {
            background: #ffffff;
            border: 1px solid #cfe1ff;
            border-radius: 12px;
            box-shadow: none;
        }

        .content-header {
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
        }

        .panel-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #cfe1ff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: #1d4ed8;
            color: #fff;
            border-radius: 12px 12px 0 0;
        }

        .panel-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .panel-body {
            padding: 1.5rem;
        }

        .btn-toggle-form {
            border: 1px solid #1d4ed8;
            background: #fff;
            color: #1d4ed8;
            border-radius: 999px;
            padding: 0.5rem 0.9rem;
            font-weight: 600;
            cursor: pointer;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .coverage-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        .coverage-table th {
            text-align: left;
            padding: 0.85rem 1rem;
            background: #f3f6fb;
            color: #0f172a;
            font-weight: 700;
            font-size: 0.85rem;
            border-bottom: 1px solid #dbe7fb;
        }

        .coverage-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #eef2f7;
            color: #334155;
            font-size: 0.9rem;
        }

        .coverage-table tbody tr:hover {
            background: #f9fbff;
        }

        .coverage-table tbody tr:last-child td {
            border-bottom: none;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .form-body.is-collapsed {
            display: none;
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column-reverse;
            }
        }
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
                        <p class="school-tagline">Content Coverage</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($teacher_name); ?></span>
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
                        <h2>Content Coverage</h2>
                        <p>Submit weekly content coverage for principal approval.</p>
                    </div>
                    <div class="header-stats">
                        <div class="quick-stat">
                            <span class="quick-stat-value"><?php echo htmlspecialchars($current_year); ?></span>
                            <span class="quick-stat-label">Academic Year</span>
                        </div>
                    </div>
                </div>

        <!-- Alerts -->
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Submit Coverage Form -->
        <div class="panel">
            <div class="panel-header">
                <h3><i class="fas fa-plus-circle"></i> Submit New Coverage</h3>
                <button type="button" class="btn-toggle-form" id="toggleCoverageForm" aria-expanded="true">
                    <i class="fas fa-eye-slash"></i>
                    <span>Hide Form</span>
                </button>
            </div>

            <div class="panel-body form-body" id="coverageFormBody">
                <form method="POST">
                    <input type="hidden" name="action" value="submit_coverage">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Subject *</label>
                            <select name="subject_id" class="form-control" required>
                                <option value="">Select Subject</option>
                                <?php
                                $unique_subjects = [];
                                foreach ($assignments as $assignment) {
                                    if (!in_array($assignment['subject_id'], array_column($unique_subjects, 'subject_id'))) {
                                        $unique_subjects[] = $assignment;
                                    }
                                }
                                foreach ($unique_subjects as $subject):
                                ?>
                                    <option value="<?php echo $subject['subject_id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Class *</label>
                            <select name="class_id" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php
                                $unique_classes = [];
                                foreach ($assignments as $assignment) {
                                    if (!in_array($assignment['class_id'], array_column($unique_classes, 'class_id'))) {
                                        $unique_classes[] = $assignment;
                                    }
                                }
                                foreach ($unique_classes as $class):
                                ?>
                                    <option value="<?php echo $class['class_id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Term *</label>
                            <select name="term" class="form-control" required>
                                <option value="">Select Term</option>
                                <option value="1st Term">1st Term</option>
                                <option value="2nd Term">2nd Term</option>
                                <option value="3rd Term">3rd Term</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Week</label>
                            <input type="number" name="week" class="form-control" min="1" max="52" placeholder="Enter week number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Date Covered *</label>
                            <input type="date" name="date_covered" class="form-control" required
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Time Start</label>
                            <input type="time" name="time_start" class="form-control">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Time End</label>
                            <input type="time" name="time_end" class="form-control">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Period</label>
                            <input type="text" name="period" class="form-control" placeholder="e.g. Period 1, Morning Session">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Topics Covered *</label>
                        <textarea name="topics_covered" class="form-control" rows="4" required
                                  placeholder="Enter the actual topics you covered in this session..."></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Objectives Achieved</label>
                            <textarea name="objectives_achieved" class="form-control" rows="3"
                                      placeholder="What learning objectives were achieved..."></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Resources Used</label>
                            <textarea name="resources_used" class="form-control" rows="3"
                                      placeholder="Textbooks, materials, equipment used..."></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Assessment Done</label>
                            <textarea name="assessment_done" class="form-control" rows="3"
                                      placeholder="Tests, quizzes, assignments given..."></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Challenges Faced</label>
                            <textarea name="challenges" class="form-control" rows="3"
                                      placeholder="Any difficulties encountered..."></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Additional Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Any additional comments or observations..."></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i>
                            <span>Submit for Approval</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Coverage History -->
        <div class="panel">
            <div class="panel-header">
                <h3><i class="fas fa-history"></i> Coverage History</h3>
            </div>
            <div class="panel-body">
                <?php if (empty($coverage_entries)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3 style="margin-bottom: 0.5rem;">No Coverage Entries</h3>
                        <p>You haven't submitted any content coverage yet. Use the form above to submit your first entry.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="coverage-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Subject</th>
                                    <th>Topics</th>
                                    <th>Class</th>
                                    <th>Time</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coverage_entries as $entry): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($entry['date_covered'])); ?></td>
                                        <td><?php echo htmlspecialchars($entry['subject_name']); ?></td>
                                        <td>
                                            <div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?php echo htmlspecialchars(substr($entry['topics_covered'], 0, 100)); ?>
                                                <?php if (strlen($entry['topics_covered']) > 100): ?>...<?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($entry['class_name']); ?></td>
                                        <td>
                                            <?php
                                            $time_start = $entry['time_start'] ?? '';
                                            $time_end = $entry['time_end'] ?? '';
                                            $time_range = trim($time_start) !== '' || trim($time_end) !== ''
                                                ? trim($time_start . ' - ' . $time_end)
                                                : '--';
                                            echo htmlspecialchars($time_range);
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($entry['period'] ?? '--'); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $entry['status'] ?? 'pending')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
            </div>
        </main>
    </div>

    <script>
        (function () {
            var toggleButton = document.getElementById('toggleCoverageForm');
            var formBody = document.getElementById('coverageFormBody');
            if (!toggleButton || !formBody) return;

            toggleButton.addEventListener('click', function () {
                var isCollapsed = formBody.classList.toggle('is-collapsed');
                toggleButton.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                toggleButton.innerHTML = isCollapsed
                    ? '<i class="fas fa-eye"></i><span>Show Form</span>'
                    : '<i class="fas fa-eye-slash"></i><span>Hide Form</span>';
            });
        })();
    </script>

    <?php include '../includes/floating-button.php'; ?>
</body>
</html>
