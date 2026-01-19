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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-50: #eff6ff;
            --primary-100: #dbeafe;
            --primary-200: #bfdbfe;
            --primary-300: #93c5fd;
            --primary-400: #60a5fa;
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --primary-700: #1d4ed8;
            --primary-800: #1e40af;
            --primary-900: #1e3a8a;

            --success-50: #f0fdf4;
            --success-100: #dcfce7;
            --success-500: #22c55e;
            --success-600: #16a34a;

            --warning-50: #fffbeb;
            --warning-100: #fef3c7;
            --warning-500: #f59e0b;
            --warning-600: #d97706;

            --error-50: #fef2f2;
            --error-100: #fee2e2;
            --error-500: #ef4444;
            --error-600: #dc2626;

            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;

            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 32px rgba(0, 0, 0, 0.12);
            --shadow-strong: 0 16px 48px rgba(0, 0, 0, 0.15);

            --gradient-primary: linear-gradient(135deg, var(--primary-500) 0%, var(--primary-700) 100%);
            --gradient-bg: linear-gradient(135deg, var(--primary-50) 0%, var(--accent-50) 50%, var(--primary-100) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--gradient-bg);
            color: var(--gray-800);
            line-height: 1.6;
            min-height: 100vh;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-soft);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-container {
            width: 56px;
            height: 56px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: var(--shadow-medium);
        }

        .brand-text h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.125rem;
        }

        .brand-text p {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 12px;
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .back-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .page-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: 1.1rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: var(--success-50);
            color: var(--success-600);
            border: 1px solid var(--success-100);
        }

        .alert-error {
            background: var(--error-50);
            color: var(--error-600);
            border: 1px solid var(--error-100);
        }

        .form-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .section-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            letter-spacing: 0.025em;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-control::placeholder {
            color: var(--gray-400);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-medium);
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        .btn-secondary {
            background: white;
            color: var(--gray-700);
            border: 2px solid var(--gray-200);
        }

        .btn-secondary:hover {
            border-color: var(--primary-300);
            box-shadow: var(--shadow-medium);
        }

        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: var(--primary-500);
            color: white;
            padding: 1.25rem 1.5rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .table td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .table tr:hover {
            background: var(--primary-50);
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-pending {
            background: var(--warning-100);
            color: var(--warning-600);
        }

        .status-approved {
            background: var(--success-100);
            color: var(--success-600);
        }

        .status-rejected {
            background: var(--error-100);
            color: var(--error-600);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-600);
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--gray-400);
            margin-bottom: 1rem;
        }

        .empty-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .table th,
            .table td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="header-brand">
                <a href="index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
                <div class="logo-container">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="brand-text">
                    <h1>SahabFormMaster</h1>
                    <p>Content Coverage</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="user-info">
                    <div class="user-avatar">
                        <div style="width: 40px; height: 40px; background: var(--gradient-primary); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                            <?php echo strtoupper(substr($teacher_name, 0, 1)); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-clipboard-check"></i>
                Content Coverage Tracking
            </h2>
            <p class="page-subtitle">
                Submit weekly content coverage for principal approval
            </p>
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
        <div class="form-section">
            <h3 class="section-title">
                <i class="fas fa-plus-circle"></i>
                Submit New Coverage
            </h3>

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

                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i>
                        <span>Submit for Approval</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Coverage History -->
        <div class="form-section">
            <h3 class="section-title">
                <i class="fas fa-history"></i>
                Coverage History
            </h3>

            <?php if (empty($coverage_entries)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list empty-icon"></i>
                    <h3 class="empty-title">No Coverage Entries</h3>
                    <p>You haven't submitted any content coverage yet. Use the form above to submit your first entry.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Term</th>
                                <th>Topics</th>
                                <th>Status</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($coverage_entries as $entry): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($entry['date_covered'])); ?></td>
                                    <td><?php echo htmlspecialchars($entry['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['term']); ?></td>
                                    <td>
                                        <div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars(substr($entry['topics_covered'], 0, 100)); ?>
                                            <?php if (strlen($entry['topics_covered']) > 100): ?>...<?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $entry['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $entry['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, H:i', strtotime($entry['submitted_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../includes/floating-button.php'; ?>
</body>
</html>
