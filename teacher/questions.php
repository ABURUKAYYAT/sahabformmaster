<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../config/db.php';

// Check authorization and get school context
require_once '../includes/functions.php';
$current_school_id = require_school_auth();

// Only allow teachers and principal
$allowed_roles = ['principal', 'teacher'];
if (!in_array(strtolower($_SESSION['role'] ?? ''), $allowed_roles)) {
    header("Location: ../index.php");
    exit;
}

$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$user_school_id = $current_school_id; // For backward compatibility

$errors = [];
$success = '';
$edit_mode = false;
$edit_question = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_question') {
        $question_text = trim($_POST['question_text'] ?? '');
        $question_type = $_POST['question_type'] ?? 'mcq';
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = floatval($_POST['marks'] ?? 1.0);
        $topic = trim($_POST['topic'] ?? '');
        $sub_topic = trim($_POST['sub_topic'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 0);
        $cognitive_level = $_POST['cognitive_level'] ?? 'knowledge';
        
        // Validation
        if (empty($question_text) || $subject_id <= 0 || $class_id <= 0) {
            $errors[] = 'Question text, subject and class are required.';
        }
        
        if ($marks <= 0) {
            $errors[] = 'Marks must be greater than 0.';
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // Generate question code
                $prefix = 'Q';
                $year = date('y');
                $month = date('m');
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM questions_bank WHERE YEAR(created_at) = YEAR(CURDATE())");
                $stmt->execute();
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
                
                $question_code = sprintf('%s%s%02d%04d', $prefix, $year, $month, $count);
                // Insert question
                $stmt = $pdo->prepare("
                    INSERT INTO questions_bank
                    (question_code, question_text, question_type, subject_id, class_id,
                     difficulty_level, marks, topic, sub_topic, category_id, cognitive_level,
                     created_by, status, school_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
                ");

                $stmt->execute([
                    $question_code,
                    $question_text,
                    $question_type,
                    $subject_id,
                    $class_id,
                    $difficulty_level,
                    $marks,
                    $topic,
                    $sub_topic,
                    $category_id ?: null,
                    $cognitive_level,
                    $_SESSION['user_id'],
                    $current_school_id
                ]);
                
                $question_id = $pdo->lastInsertId();
                
                // Handle MCQ options
                if ($question_type === 'mcq' && isset($_POST['options'])) {
                    foreach ($_POST['options'] as $index => $option_text) {
                        if (!empty(trim($option_text))) {
                            $option_letter = chr(65 + $index); // A, B, C, D...
                            $is_correct = ($_POST['correct_option'] ?? '') == $option_letter;
                            
                            $option_stmt = $pdo->prepare("
                                INSERT INTO question_options 
                                (question_id, option_text, option_letter, is_correct, school_id)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            
                            $option_stmt->execute([
                                $question_id,
                                trim($option_text),
                                $option_letter,
                                $is_correct ? 1 : 0,
                                $current_school_id
                            ]);
                        }
                    }
                }
                
                // Handle tags
                if (!empty($_POST['tags'])) {
                    $tags = array_map('trim', explode(',', $_POST['tags']));
                    foreach ($tags as $tag) {
                        if (!empty($tag)) {
                            $tag_stmt = $pdo->prepare("
                                INSERT INTO question_tags (question_id, tag_name)
                                VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE id=id
                            ");
                            $tag_stmt->execute([$question_id, $tag]);
                        }
                    }
                }
                
                $pdo->commit();
                $success = 'Question added successfully! Question Code: ' . $question_code;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to add question: ' . $e->getMessage();
            }
        }
        
    } elseif ($action === 'update_question') {
        $question_id = intval($_POST['question_id'] ?? 0);

        if ($question_id > 0) {
            $question_text = trim($_POST['question_text'] ?? '');
            $question_type = $_POST['question_type'] ?? 'mcq';
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $class_id = intval($_POST['class_id'] ?? 0);
            $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
            $marks = floatval($_POST['marks'] ?? 1.0);
            $topic = trim($_POST['topic'] ?? '');
            $sub_topic = trim($_POST['sub_topic'] ?? '');
            $category_id = intval($_POST['category_id'] ?? 0);
            $cognitive_level = $_POST['cognitive_level'] ?? 'knowledge';

            // Validation
            if (empty($question_text) || $subject_id <= 0 || $class_id <= 0) {
                $errors[] = 'Question text, subject and class are required.';
            }

            if ($marks <= 0) {
                $errors[] = 'Marks must be greater than 0.';
            }

            if (empty($errors)) {
                try {
                    $pdo->beginTransaction();

                    // Update question
                    $stmt = $pdo->prepare("
                        UPDATE questions_bank SET
                        question_text = ?, question_type = ?, subject_id = ?, class_id = ?,
                        difficulty_level = ?, marks = ?, topic = ?, sub_topic = ?, category_id = ?, cognitive_level = ?,
                        updated_at = NOW()
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $question_text,
                        $question_type,
                        $subject_id,
                        $class_id,
                        $difficulty_level,
                        $marks,
                        $topic,
                        $sub_topic,
                        $category_id ?: null,
                        $cognitive_level,
                        $question_id
                    ]);

                    // Delete existing options and tags for MCQ questions
                    $pdo->prepare("DELETE FROM question_options WHERE question_id = ?")->execute([$question_id]);
                    $pdo->prepare("DELETE FROM question_tags WHERE question_id = ?")->execute([$question_id]);

                    // Handle MCQ options
                    if ($question_type === 'mcq' && isset($_POST['options'])) {
                        foreach ($_POST['options'] as $index => $option_text) {
                            if (!empty(trim($option_text))) {
                                $option_letter = chr(65 + $index); // A, B, C, D...
                                $is_correct = ($_POST['correct_option'] ?? '') == $option_letter;

                                $option_stmt = $pdo->prepare("
                                    INSERT INTO question_options
                                    (question_id, option_text, option_letter, is_correct, school_id)
                                    VALUES (?, ?, ?, ?, ?)
                                ");

                                $option_stmt->execute([
                                    $question_id,
                                    trim($option_text),
                                    $option_letter,
                                    $is_correct ? 1 : 0,
                                    $current_school_id
                                ]);
                            }
                        }
                    }

                    // Handle tags
                    if (!empty($_POST['tags'])) {
                        $tags = array_map('trim', explode(',', $_POST['tags']));
                        foreach ($tags as $tag) {
                            if (!empty($tag)) {
                                $tag_stmt = $pdo->prepare("
                                    INSERT INTO question_tags (question_id, tag_name)
                                    VALUES (?, ?)
                                    ON DUPLICATE KEY UPDATE id=id
                                ");
                                $tag_stmt->execute([$question_id, $tag]);
                            }
                        }
                    }

                    $pdo->commit();
                    $success = 'Question updated successfully!';

                    // Redirect to avoid form resubmission
                    header("Location: questions.php?edit=" . $question_id);
                    exit;

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Failed to update question: ' . $e->getMessage();
                }
            }
        } else {
            $errors[] = 'Invalid question ID.';
        }
        
    } elseif ($action === 'delete_question') {
        $question_id = intval($_POST['question_id'] ?? 0);

        if ($question_id > 0) {
            try {
                $pdo->beginTransaction();

                // Check if question exists and belongs to user's school
                $check_stmt = $pdo->prepare("SELECT id FROM questions_bank WHERE id = ? AND school_id = ?");
                $check_stmt->execute([$question_id, $user_school_id]);
                if (!$check_stmt->fetch()) {
                    $errors[] = 'Question not found or does not belong to your school.';
                } else {
                    // Delete related records first
                    $pdo->prepare("DELETE FROM question_options WHERE question_id = ?")->execute([$question_id]);
                    $pdo->prepare("DELETE FROM question_tags WHERE question_id = ?")->execute([$question_id]);
                    $pdo->prepare("DELETE FROM question_attachments WHERE question_id = ?")->execute([$question_id]);

                    // Delete the question
                    $stmt = $pdo->prepare("DELETE FROM questions_bank WHERE id = ? AND school_id = ?");
        $stmt->execute([$question_id, $current_school_id]);

                    if ($stmt->rowCount() > 0) {
                        $pdo->commit();
                        $success = 'Question deleted successfully.';
                    } else {
                        $pdo->rollBack();
                        $errors[] = 'Question could not be deleted.';
                    }
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        }
        
    } elseif ($action === 'change_status') {
        $question_id = intval($_POST['question_id'] ?? 0);
        $new_status = $_POST['status'] ?? 'draft';
        
        if ($question_id > 0 && in_array($new_status, ['draft', 'reviewed', 'approved', 'rejected'])) {
            $stmt = $pdo->prepare("UPDATE questions_bank SET status = ? WHERE id = ?");
            if ($stmt->execute([$new_status, $question_id])) {
                $success = 'Question status updated to ' . ucfirst($new_status);
            }
        }
    }
}

// Handle edit mode
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("
            SELECT q.*, s.subject_name, c.class_name 
            FROM questions_bank q
            LEFT JOIN subjects s ON q.subject_id = s.id
            LEFT JOIN classes c ON q.class_id = c.id
            WHERE q.id = ?
        ");
        $stmt->execute([$edit_id]);
        $edit_question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($edit_question) {
            $edit_mode = true;
            
            // Fetch options for MCQ questions
            if ($edit_question['question_type'] === 'mcq') {
                $options_stmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY option_letter");
                $options_stmt->execute([$edit_id]);
                $edit_question['options'] = $options_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Fetch tags
            $tags_stmt = $pdo->prepare("SELECT GROUP_CONCAT(tag_name) as tags FROM question_tags WHERE question_id = ?");
            $tags_stmt->execute([$edit_id]);
            $tags = $tags_stmt->fetch(PDO::FETCH_ASSOC);
            $edit_question['tags'] = $tags['tags'] ?? '';
        }
    }
}

// Fetch data for filters and dropdowns
$subjects = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name");
$subjects->execute([$user_school_id]);
$subjects = $subjects->fetchAll(PDO::FETCH_ASSOC);

$classes = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name");
$classes->execute([$user_school_id]);
$classes = $classes->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, category_name FROM question_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

// Handle filters
$subject_filter = $_GET['subject_filter'] ?? '';
$class_filter = $_GET['class_filter'] ?? '';
$difficulty_filter = $_GET['difficulty_filter'] ?? '';
$type_filter = $_GET['type_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';

// Build query for question bank summary table
$query = "
    SELECT s.subject_name, q.question_type, c.class_name, COUNT(*) as question_count
    FROM questions_bank q
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN classes c ON q.class_id = c.id
    WHERE q.school_id = ?
";

$params = [$user_school_id];

if (!empty($subject_filter)) {
    $query .= " AND q.subject_id = ?";
    $params[] = $subject_filter;
}

if (!empty($class_filter)) {
    $query .= " AND q.class_id = ?";
    $params[] = $class_filter;
}

if (!empty($difficulty_filter)) {
    $query .= " AND q.difficulty_level = ?";
    $params[] = $difficulty_filter;
}

if (!empty($type_filter)) {
    $query .= " AND q.question_type = ?";
    $params[] = $type_filter;
}

if (!empty($status_filter)) {
    $query .= " AND q.status = ?";
    $params[] = $status_filter;
}

$query .= " GROUP BY s.subject_name, q.question_type, c.class_name ORDER BY s.subject_name, q.question_type, c.class_name";

// Summary pagination
$summary_page = isset($_GET['summary_page']) ? max(1, intval($_GET['summary_page'])) : 1;
$summary_per_page = 10;
$summary_offset = ($summary_page - 1) * $summary_per_page;

$summary_count_query = "SELECT COUNT(*) FROM (" . $query . ") as summary_counts";
$summary_stmt = $pdo->prepare($summary_count_query);
$summary_stmt->execute($params);
$total_summary_count = (int)$summary_stmt->fetchColumn();
$summary_total_pages = max(1, (int)ceil($total_summary_count / $summary_per_page));

$summary_query = $query . " LIMIT " . (int)$summary_per_page . " OFFSET " . (int)$summary_offset;
$summary_stmt = $pdo->prepare($summary_query);
$summary_stmt->execute($params);
$question_summary = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$total_questions = $pdo->prepare("SELECT COUNT(*) as total FROM questions_bank WHERE school_id = ?");
$total_questions->execute([$user_school_id]);
$total_questions = $total_questions->fetch(PDO::FETCH_ASSOC)['total'];

$mcq_count = $pdo->prepare("SELECT COUNT(*) as total FROM questions_bank WHERE question_type = 'mcq' AND school_id = ?");
$mcq_count->execute([$user_school_id]);
$mcq_count = $mcq_count->fetch(PDO::FETCH_ASSOC)['total'];

$approved_count = $pdo->prepare("SELECT COUNT(*) as total FROM questions_bank WHERE status = 'approved' AND school_id = ?");
$approved_count->execute([$user_school_id]);
$approved_count = $approved_count->fetch(PDO::FETCH_ASSOC)['total'];

$my_questions = $pdo->prepare("SELECT COUNT(*) as total FROM questions_bank WHERE created_by = ? AND school_id = ?");
$my_questions->execute([$_SESSION['user_id'], $user_school_id]);
$my_questions = $my_questions->fetch(PDO::FETCH_ASSOC)['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="pwa-sw" content="../sw.js">
    <title>Questions Bank Management | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="stylesheet" href="../assets/css/offline-status.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
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

            --accent-50: #fdf4ff;
            --accent-100: #fae8ff;
            --accent-200: #f5d0fe;
            --accent-300: #f0abfc;
            --accent-400: #e879f9;
            --accent-500: #d946ef;
            --accent-600: #c026d3;
            --accent-700: #a21caf;
            --accent-800: #86198f;
            --accent-900: #701a75;

            --success-50: #f0fdf4;
            --success-100: #dcfce7;
            --success-500: #22c55e;
            --success-600: #16a34a;
            --success-700: #15803d;

            --error-50: #fef2f2;
            --error-100: #fee2e2;
            --error-500: #ef4444;
            --error-600: #dc2626;

            --warning-50: #fffbeb;
            --warning-100: #fef3c7;
            --warning-500: #f59e0b;
            --warning-600: #d97706;

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
            --gradient-accent: linear-gradient(135deg, var(--accent-500) 0%, var(--accent-700) 100%);
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

        /* Modern Header */
        .modern-header {
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--gradient-accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-details p {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.125rem;
        }

        .user-details span {
            font-weight: 600;
            color: var(--gray-900);
        }

        .logout-btn {
            padding: 0.75rem 1.25rem;
            background: var(--error-500);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: var(--error-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Modern Cards */
        .modern-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }

        .modern-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
        }

        .card-header-modern {
            padding: 2rem;
            background: var(--gradient-primary);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .card-header-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/><circle cx="10" cy="90" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .card-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .card-subtitle-modern {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .card-body-modern {
            padding: 2rem;
        }

        /* Statistics Grid */
        .stats-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .stat-card-modern:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-strong);
        }

        .stat-icon-modern {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-total .stat-icon-modern {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-present .stat-icon-modern {
            background: var(--gradient-success);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-absent .stat-icon-modern {
            background: var(--gradient-error);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-late .stat-icon-modern {
            background: var(--gradient-warning);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-value-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label-modern {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Form Controls */
        .controls-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .form-row-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group-modern {
            position: relative;
        }

        .form-label-modern {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            letter-spacing: 0.025em;
        }

        .form-input-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-input-modern::placeholder {
            color: var(--gray-400);
        }

        .btn-modern-primary {
            padding: 1rem 2rem;
            background: var(--gradient-primary);
            color: white;
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

        .btn-modern-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        /* Quick Actions */
        .actions-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .actions-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .action-btn-modern {
            padding: 1.25rem 1.5rem;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--gray-700);
            box-shadow: var(--shadow-soft);
            position: relative;
            overflow: hidden;
        }

        .action-btn-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.1), transparent);
            transition: left 0.5s;
        }

        .action-btn-modern:hover::before {
            left: 100%;
        }

        .action-btn-modern:hover {
            transform: translateY(-4px);
            border-color: var(--primary-300);
            box-shadow: var(--shadow-strong);
        }

        .action-icon-modern {
            font-size: 1.5rem;
            color: var(--primary-600);
            transition: transform 0.3s ease;
        }

        .action-btn-modern:hover .action-icon-modern {
            transform: scale(1.1);
        }

        .action-text-modern {
            font-weight: 600;
            font-size: 0.875rem;
            text-align: center;
        }

        /* Attendance Table */
        .attendance-table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }


        .table-wrapper-modern {
            overflow-x: auto;
        }

        .summary-table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .summary-table-modern th {
            background: #f1f5f9;
            color: #0f172a;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 0.7rem 0.9rem;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }

        .summary-table-modern td {
            padding: 0.7rem 0.9rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        .summary-table-modern tr:nth-child(even) td {
            background: #f9fafb;
        }

        /* Submit Section */
        .submit-section-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .submit-btn-modern {
            padding: 1.25rem 3rem;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1.125rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: var(--shadow-medium);
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .submit-btn-modern:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-strong);
        }

        /* Alerts */
        .alert-modern {
            padding: 1.25rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .alert-success-modern {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success-700);
            border-left: 4px solid var(--success-500);
        }

        .alert-error-modern {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-700);
            border-left: 4px solid var(--error-500);
        }

        /* Footer */
        .footer-modern {
            background: var(--gray-900);
            color: var(--gray-300);
            padding: 3rem 2rem 2rem;
            margin-top: 4rem;
            position: relative;
        }

        .footer-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gray-700), transparent);
        }

        .footer-content-modern {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-section-modern h4 {
            color: white;
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 1rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .footer-section-modern p {
            margin-bottom: 0.75rem;
            line-height: 1.6;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
            }

            .main-container {
                padding: 1rem;
            }

            .stats-modern {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .form-row-modern {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .actions-grid-modern {
                grid-template-columns: repeat(2, 1fr);
            }

            .summary-table-modern th,
            .summary-table-modern td {
                padding: 0.7rem;
                font-size: 0.8rem;
            }

        }

        @media (max-width: 480px) {
            .stats-modern {
                grid-template-columns: 1fr;
            }

            .actions-grid-modern {
                grid-template-columns: 1fr;
            }

            .modern-card {
                margin-bottom: 1rem;
            }

            .card-header-modern,
            .card-body-modern {
                padding: 1.5rem;
            }

            .stat-card-modern {
                padding: 1.5rem;
            }

            .stat-icon-modern {
                width: 48px;
                height: 48px;
                font-size: 1.25rem;
            }

            .stat-value-modern {
                font-size: 2rem;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .animate-slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .animate-slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        /* Utility Classes */
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .font-semibold { font-weight: 600; }
        .font-medium { font-weight: 500; }

        .gradient-success { background: linear-gradient(135deg, var(--success-500) 0%, var(--success-600) 100%); }
        .gradient-error { background: linear-gradient(135deg, var(--error-500) 0%, var(--error-600) 100%); }
        .gradient-warning { background: linear-gradient(135deg, var(--warning-500) 0%, var(--warning-600) 100%); }

        * {
            animation: none !important;
            transition: none !important;
        }

        body {
            background: #f5f7fb;
        }

        .dashboard-container .main-content {
            width: 100%;
        }

        .main-container {
            padding: 1.5rem;
        }

        .modern-card {
            border-radius: 18px;
            margin-bottom: 1.5rem;
        }

        .modern-card:hover {
            transform: none;
            box-shadow: var(--shadow-soft);
        }

        .card-header-modern {
            padding: 1.25rem 1.5rem;
        }

        .card-title-modern {
            font-size: 1.4rem;
        }

        .card-subtitle-modern {
            font-size: 0.95rem;
        }

        .card-body-modern {
            padding: 1.5rem;
        }

        .card-header-modern.with-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .card-header-content {
            position: relative;
            z-index: 1;
        }

        .card-header-actions {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .btn-toggle-form {
            position: relative;
            z-index: 1;
            padding: 0.65rem 1rem;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.4);
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: transform 0.2s ease, background 0.2s ease;
        }

        .btn-toggle-form:hover {
            background: rgba(255, 255, 255, 0.28);
            transform: translateY(-1px);
        }

        .btn-toggle-form.is-collapsed {
            background: rgba(255, 255, 255, 0.35);
        }

        .preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.55);
            z-index: 2000;
            padding: 24px;
            overflow-y: auto;
        }

        .preview-content {
            background: #fff;
            border-radius: 16px;
            max-width: 900px;
            margin: 40px auto;
            padding: 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2);
            position: relative;
        }

        .close-preview {
            position: absolute;
            right: 16px;
            top: 12px;
            font-size: 28px;
            color: var(--gray-500);
            cursor: pointer;
        }

        .question-preview {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
        }

    </style>
</head>
<body>
    <!-- Mobile Navigation Component -->
    <?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">Teacher Portal</p>
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
    
        
    <!-- Main Container -->
    <div class="dashboard-container">
        <?php include '../includes/teacher_sidebar.php'; ?>
        <main class="main-content">
    <div class="main-container">
        <!-- Welcome Section -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h2 class="card-title-modern">
                    <i class="fas fa-question-circle"></i>
                    Questions Bank Management
                </h2>
                <p class="card-subtitle-modern">
                    Create, manage, and organize your question bank efficiently
                </p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-modern">
            <div class="stat-card-modern stat-total animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-question"></i>
                </div>
                <div class="stat-value-modern"><?php echo $total_questions; ?></div>
                <div class="stat-label-modern">Total Questions</div>
            </div>

            <div class="stat-card-modern stat-present animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-list-ul"></i>
                </div>
                <div class="stat-value-modern"><?php echo $mcq_count; ?></div>
                <div class="stat-label-modern">MCQ Questions</div>
            </div>

            <div class="stat-card-modern stat-absent animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $approved_count; ?></div>
                <div class="stat-label-modern">Approved</div>
            </div>

            <div class="stat-card-modern stat-late animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-user-edit"></i>
                </div>
                <div class="stat-value-modern"><?php echo $my_questions; ?></div>
                <div class="stat-label-modern">My Questions</div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if($errors): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php foreach($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></span>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <!-- Question Editor -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern with-actions">
                <div class="card-header-content">
                    <h2 class="card-title-modern">
                        <i class="fas fa-<?php echo $edit_mode ? 'edit' : 'plus'; ?>"></i>
                        <?php echo $edit_mode ? 'Edit Question' : 'Add New Question'; ?>
                    </h2>
                    <p class="card-subtitle-modern">
                        <?php echo $edit_mode ? 'Modify existing question details' : 'Create a new question for your question bank'; ?>
                    </p>
                </div>
                <button type="button" class="btn-toggle-form" id="toggleQuestionForm" aria-expanded="true" aria-controls="questionFormBody">
                    <i class="fas fa-eye-slash"></i>
                    <span>Hide Form</span>
                </button>
            </div>
            <div class="card-body-modern" id="questionFormBody">
                <form id="questionForm" method="POST" data-offline-sync="1">
                <input type="hidden" name="action" value="<?php echo $edit_mode ? 'update_question' : 'create_question'; ?>">
                <?php if($edit_mode): ?>
                    <input type="hidden" name="question_id" value="<?php echo $edit_question['id']; ?>">
                <?php endif; ?>
                
                <!-- Question Type Selection -->
                <div class="form-section">
                    <h3><i class="fas fa-tag"></i> Question Type & Basic Info</h3>
                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Question Type *</label>
                            <select name="question_type" id="questionType" class="form-input-modern" required
                                    onchange="toggleQuestionOptions()">
                                <option value="">Select Question Type *</option>
                                <option value="mcq" <?php echo ($edit_question['question_type'] ?? '') == 'mcq' ? 'selected' : ''; ?>>Multiple Choice (MCQ)</option>
                                <option value="true_false" <?php echo ($edit_question['question_type'] ?? '') == 'true_false' ? 'selected' : ''; ?>>True/False</option>
                                <option value="short_answer" <?php echo ($edit_question['question_type'] ?? '') == 'short_answer' ? 'selected' : ''; ?>>Short Answer</option>
                                <option value="essay" <?php echo ($edit_question['question_type'] ?? '') == 'essay' ? 'selected' : ''; ?>>Essay</option>
                                <option value="fill_blank" <?php echo ($edit_question['question_type'] ?? '') == 'fill_blank' ? 'selected' : ''; ?>>Fill in the Blank</option>
                            </select>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern">Subject *</label>
                            <select name="subject_id" class="form-input-modern" required>
                                <option value="">Select Subject *</option>
                                <?php foreach($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>"
                                        <?php echo ($edit_question['subject_id'] ?? '') == $subject['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern">Class *</label>
                            <select name="class_id" class="form-input-modern" required>
                                <option value="">Select Class *</option>
                                <?php foreach($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>"
                                        <?php echo ($edit_question['class_id'] ?? '') == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern">Difficulty Level *</label>
                            <select name="difficulty_level" class="form-input-modern" required>
                                <option value="">Difficulty Level *</option>
                                <option value="easy" <?php echo ($edit_question['difficulty_level'] ?? '') == 'easy' ? 'selected' : ''; ?>>Easy</option>
                                <option value="medium" <?php echo ($edit_question['difficulty_level'] ?? '') == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="hard" <?php echo ($edit_question['difficulty_level'] ?? '') == 'hard' ? 'selected' : ''; ?>>Hard</option>
                                <option value="very_hard" <?php echo ($edit_question['difficulty_level'] ?? '') == 'very_hard' ? 'selected' : ''; ?>>Very Hard</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Marks *</label>
                            <input type="number" name="marks" class="form-input-modern" placeholder="Marks *" step="0.5" min="0.5" max="100" required
                                   value="<?php echo $edit_question['marks'] ?? '1.00'; ?>">
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern">Cognitive Level</label>
                            <select name="cognitive_level" class="form-input-modern">
                                <option value="">Cognitive Level</option>
                                <option value="knowledge" <?php echo ($edit_question['cognitive_level'] ?? '') == 'knowledge' ? 'selected' : ''; ?>>Knowledge</option>
                                <option value="comprehension" <?php echo ($edit_question['cognitive_level'] ?? '') == 'comprehension' ? 'selected' : ''; ?>>Comprehension</option>
                                <option value="application" <?php echo ($edit_question['cognitive_level'] ?? '') == 'application' ? 'selected' : ''; ?>>Application</option>
                                <option value="analysis" <?php echo ($edit_question['cognitive_level'] ?? '') == 'analysis' ? 'selected' : ''; ?>>Analysis</option>
                                <option value="synthesis" <?php echo ($edit_question['cognitive_level'] ?? '') == 'synthesis' ? 'selected' : ''; ?>>Synthesis</option>
                                <option value="evaluation" <?php echo ($edit_question['cognitive_level'] ?? '') == 'evaluation' ? 'selected' : ''; ?>>Evaluation</option>
                            </select>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern">Category</label>
                            <select name="category_id" class="form-input-modern">
                                <option value="">Select Category</option>
                                <?php foreach($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"
                                        <?php echo ($edit_question['category_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Topic</label>
                            <input type="text" name="topic" class="form-input-modern" placeholder="Topic"
                                   value="<?php echo htmlspecialchars($edit_question['topic'] ?? ''); ?>">
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern">Sub-Topic</label>
                            <input type="text" name="sub_topic" class="form-input-modern" placeholder="Sub-Topic"
                                   value="<?php echo htmlspecialchars($edit_question['sub_topic'] ?? ''); ?>">
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern">Tags</label>
                            <input type="text" name="tags" class="form-input-modern" placeholder="Tags (comma separated)"
                                   value="<?php echo htmlspecialchars($edit_question['tags'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <!-- Question Text Editor -->
                <div class="form-section">
                    <h3><i class="fas fa-edit"></i> Question Text</h3>
                    <div class="editor-toolbar">
                        <button type="button" onclick="formatText('bold')"><i class="fas fa-bold"></i> Bold</button>
                        <button type="button" onclick="formatText('italic')"><i class="fas fa-italic"></i> Italic</button>
                        <button type="button" onclick="formatText('underline')"><i class="fas fa-underline"></i> Underline</button>
                        <button type="button" onclick="insertMath()"><i class="fas fa-square-root-alt"></i> Math</button>
                        <button type="button" onclick="insertImage()"><i class="fas fa-image"></i> Image</button>
                        <button type="button" onclick="insertTable()"><i class="fas fa-table"></i> Table</button>
                    </div>
                    
                    <textarea name="question_text" id="questionText" class="form-input-modern"
                              placeholder="Enter your question here..." required><?php echo htmlspecialchars($edit_question['question_text'] ?? ''); ?></textarea>
                    
                    <div style="margin-top: 10px; font-size: 0.9rem; color: #6c757d;">
                        <i class="fas fa-lightbulb"></i> Tip: Use HTML tags for formatting or the toolbar above.
                    </div>
                </div>

                <!-- MCQ Options (Initially hidden for non-MCQ types) -->
                <div id="mcqOptions" class="form-section" style="display: none;">
                    <h3><i class="fas fa-list-ul"></i> MCQ Options</h3>
                    <div id="optionsContainer">
                        <!-- Options will be added here dynamically -->
                        <?php if($edit_mode && $edit_question['question_type'] === 'mcq' && isset($edit_question['options'])): ?>
                            <?php foreach($edit_question['options'] as $index => $option): ?>
                                <div class="option-item <?php echo $option['is_correct'] ? 'correct-option' : ''; ?>">
                                    <div class="option-letter"><?php echo $option['option_letter']; ?></div>
                                    <input type="text" name="options[]" class="form-input-modern"
                                           value="<?php echo htmlspecialchars($option['option_text']); ?>"
                                           placeholder="Option <?php echo $option['option_letter']; ?>">
                                    <div class="option-actions">
                                        <input type="radio" name="correct_option" value="<?php echo $option['option_letter']; ?>"
                                               <?php echo $option['is_correct'] ? 'checked' : ''; ?>
                                               onchange="markCorrectOption(this)">
                                        <button type="button" class="btn-modern-primary" style="background: var(--error-500); padding: 0.5rem;" onclick="removeOption(this)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <button type="button" class="btn-modern-primary" onclick="addOption()">
                        <i class="fas fa-plus"></i>
                        <span>Add Option</span>
                    </button>
                    
                    <div style="margin-top: 10px; font-size: 0.9rem; color: #6c757d;">
                        <i class="fas fa-info-circle"></i> Select the radio button to mark an option as correct.
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="submit-section-modern">
                    <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                        <button type="submit" class="submit-btn-modern">
                            <i class="fas fa-save"></i>
                            <span><?php echo $edit_mode ? 'Update Question' : 'Save Question'; ?></span>
                        </button>
                        <button type="button" class="btn-modern-primary" style="background: var(--warning-500);" onclick="previewQuestion()">
                            <i class="fas fa-eye"></i>
                            <span>Preview</span>
                        </button>
                        <button type="reset" class="btn-modern-primary" style="background: var(--gray-500);">
                            <i class="fas fa-redo"></i>
                            <span>Reset</span>
                        </button>
                    </div>
                </div>
            </form>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="actions-modern animate-fade-in-up">
            <div class="actions-grid-modern">
                <a href="generate_paper.php" class="action-btn-modern">
                    <i class="fas fa-file-alt action-icon-modern"></i>
                    <span class="action-text-modern">Generate Paper</span>
                </a>
                <button class="action-btn-modern" onclick="exportQuestions()">
                    <i class="fas fa-download action-icon-modern"></i>
                    <span class="action-text-modern">Export Questions</span>
                </button>
                <button class="action-btn-modern" onclick="printTable()">
                    <i class="fas fa-print action-icon-modern"></i>
                    <span class="action-text-modern">Print Summary</span>
                </button>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="controls-modern animate-fade-in-up">
            <div class="form-row-modern">
                <div class="form-group-modern">
                    <label class="form-label-modern">Subject</label>
                    <select name="subject_filter" class="form-input-modern" form="searchForm">
                        <option value="">All Subjects</option>
                        <?php foreach($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>"
                                <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group-modern">
                    <label class="form-label-modern">Class</label>
                    <select name="class_filter" class="form-input-modern" form="searchForm">
                        <option value="">All Classes</option>
                        <?php foreach($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"
                                <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group-modern">
                    <label class="form-label-modern">Difficulty</label>
                    <select name="difficulty_filter" class="form-input-modern" form="searchForm">
                        <option value="">All Difficulty</option>
                        <option value="easy" <?php echo $difficulty_filter == 'easy' ? 'selected' : ''; ?>>Easy</option>
                        <option value="medium" <?php echo $difficulty_filter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="hard" <?php echo $difficulty_filter == 'hard' ? 'selected' : ''; ?>>Hard</option>
                        <option value="very_hard" <?php echo $difficulty_filter == 'very_hard' ? 'selected' : ''; ?>>Very Hard</option>
                    </select>
                </div>

                <div class="form-group-modern">
                    <label class="form-label-modern">Type</label>
                    <select name="type_filter" class="form-input-modern" form="searchForm">
                        <option value="">All Types</option>
                        <option value="mcq" <?php echo $type_filter == 'mcq' ? 'selected' : ''; ?>>MCQ</option>
                        <option value="true_false" <?php echo $type_filter == 'true_false' ? 'selected' : ''; ?>>True/False</option>
                        <option value="short_answer" <?php echo $type_filter == 'short_answer' ? 'selected' : ''; ?>>Short Answer</option>
                        <option value="essay" <?php echo $type_filter == 'essay' ? 'selected' : ''; ?>>Essay</option>
                    </select>
                </div>

                <div class="form-group-modern">
                    <label class="form-label-modern">Status</label>
                    <select name="status_filter" class="form-input-modern" form="searchForm">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="reviewed" <?php echo $status_filter == 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 2rem;">
                <button type="submit" class="btn-modern-primary" form="searchForm">
                    <i class="fas fa-search"></i>
                    <span>Search & Filter</span>
                </button>

                <a href="questions.php" class="btn-modern-primary" style="background: var(--gray-500);">
                    <i class="fas fa-redo"></i>
                    <span>Reset</span>
                </a>
            </div>

            <form method="GET" action="" id="searchForm" style="display: none;"></form>
        </div>

        <!-- Questions Bank Summary Table -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h2 class="card-title-modern">
                    <i class="fas fa-table"></i>
                    Questions Bank Summary (<?php echo $total_summary_count; ?> entries)
                </h2>
                <p class="card-subtitle-modern">
                    Overview of your question collection by subject and type
                </p>
            </div>
            <div class="card-body-modern">
                <?php if(empty($question_summary)): ?>
                    <div class="alert-modern alert-success-modern">
                        <i class="fas fa-info-circle"></i>
                        <span>No questions found. Add your first question above!</span>
                    </div>
                <?php else: ?>
                    <div class="attendance-table-container">
                        <div class="table-wrapper-modern">
                            <table class="summary-table-modern">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-book"></i> Subject</th>
                                        <th><i class="fas fa-tag"></i> Question Type</th>
                                        <th><i class="fas fa-hashtag"></i> Total Questions</th>
                                        <th><i class="fas fa-users"></i> Class</th>
                                    </tr>
                                </thead>
                            <tbody>
                                <?php foreach($question_summary as $summary): ?>
                                    <tr>
                                        <td class="subject-cell">
                                            <strong><?php echo htmlspecialchars($summary['subject_name']); ?></strong>
                                        </td>
                                        <td class="type-cell">
                                            <span class="question-type-badge type-<?php echo $summary['question_type']; ?>">
                                                <?php
                                                $type_labels = [
                                                    'mcq' => 'Multiple Choice',
                                                    'true_false' => 'True/False',
                                                    'short_answer' => 'Short Answer',
                                                    'essay' => 'Essay',
                                                    'fill_blank' => 'Fill in Blank'
                                                ];
                                                echo $type_labels[$summary['question_type']] ?? ucfirst(str_replace('_', ' ', $summary['question_type']));
                                                ?>
                                            </span>
                                        </td>
                                        <td class="count-cell">
                                            <span class="question-count"><?php echo $summary['question_count']; ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($summary['class_name']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-footer">
                        <div class="summary-stats">
                            <span class="total-entries">
                                <i class="fas fa-chart-bar"></i>
                                Total Entries: <?php echo $total_summary_count; ?>
                            </span>
                            <span class="last-updated">
                                <i class="fas fa-clock"></i>
                                Last Updated: <?php echo date('d/m/Y H:i'); ?>
                            </span>
                        </div>
                        <div class="pagination" style="margin-top: 12px;">
                            <?php
                                $summary_params = $_GET;
                                $summary_params['summary_page'] = max(1, $summary_page - 1);
                                $summary_prev_url = 'questions.php' . '?' . http_build_query($summary_params);
                                $summary_params['summary_page'] = min($summary_total_pages, $summary_page + 1);
                                $summary_next_url = 'questions.php' . '?' . http_build_query($summary_params);
                            ?>
                            <?php if ($summary_page > 1): ?>
                                <a href="<?php echo htmlspecialchars($summary_prev_url); ?>" class="btn-modern-primary" style="background: var(--gray-500);">
                                    <i class="fas fa-chevron-left"></i>
                                    <span>Previous</span>
                                </a>
                            <?php endif; ?>

                            <span style="margin: 0 10px;">
                                Page <?php echo $summary_page; ?> of <?php echo $summary_total_pages; ?>
                            </span>

                            <?php if ($summary_page < $summary_total_pages): ?>
                                <a href="<?php echo htmlspecialchars($summary_next_url); ?>" class="btn-modern-primary" style="background: var(--gray-500);">
                                    <span>Next</span>
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        
    </div>
        </main>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="preview-modal">
        <div class="preview-content">
            <span class="close-preview" onclick="closePreview()">&times;</span>
            <div id="previewContent"></div>
        </div>
    </div>

    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        // Initialize select2
        $(document).ready(function() {
            $('select').select2({
                width: '100%'
            });
        });
        
        // Toggle MCQ options based on question type
        function toggleQuestionOptions() {
            const questionType = document.getElementById('questionType').value;
            const mcqOptions = document.getElementById('mcqOptions');
            
            if (questionType === 'mcq') {
                mcqOptions.style.display = 'block';
                if (document.querySelectorAll('.option-item').length === 0) {
                    // Add initial options if none exist
                    addOption();
                    addOption();
                    addOption();
                    addOption();
                }
            } else {
                mcqOptions.style.display = 'none';
            }
        }
        
        // Add new option for MCQ
        let optionCounter = document.querySelectorAll('.option-item').length;
        
        function addOption() {
            optionCounter++;
            const optionLetter = String.fromCharCode(64 + optionCounter); // A, B, C...
            
            const optionItem = document.createElement('div');
            optionItem.className = 'option-item';
            optionItem.innerHTML = `
                <div class="option-letter">${optionLetter}</div>
                <input type="text" name="options[]" class="form-input-modern"
                       placeholder="Option ${optionLetter}" required>
                <div class="option-actions">
                    <input type="radio" name="correct_option" value="${optionLetter}"
                           onchange="markCorrectOption(this)">
                    <button type="button" class="btn-modern-primary" style="background: var(--error-500); padding: 0.5rem;" onclick="removeOption(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            document.getElementById('optionsContainer').appendChild(optionItem);
        }
        
        // Remove option
        function removeOption(button) {
            const optionItem = button.closest('.option-item');
            optionItem.remove();
            // Recalculate option letters
            recalculateOptionLetters();
        }
        
        // Mark option as correct
        function markCorrectOption(radio) {
            // Remove correct-option class from all options
            document.querySelectorAll('.option-item').forEach(item => {
                item.classList.remove('correct-option');
            });
            
            // Add correct-option class to selected option
            const optionItem = radio.closest('.option-item');
            optionItem.classList.add('correct-option');
        }
        
        // Recalculate option letters after removal
        function recalculateOptionLetters() {
            const options = document.querySelectorAll('.option-item');
            options.forEach((item, index) => {
                const newLetter = String.fromCharCode(65 + index); // A, B, C...
                item.querySelector('.option-letter').textContent = newLetter;
                item.querySelector('input[name="options[]"]').placeholder = `Option ${newLetter}`;
                const radio = item.querySelector('input[type="radio"]');
                if (radio) {
                    radio.value = newLetter;
                }
            });
            optionCounter = options.length;
        }
        
        // Text formatting functions
        function formatText(command) {
            const textarea = document.getElementById('questionText');
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = textarea.value.substring(start, end);
            
            let formattedText = '';
            switch(command) {
                case 'bold':
                    formattedText = `<strong>${selectedText}</strong>`;
                    break;
                case 'italic':
                    formattedText = `<em>${selectedText}</em>`;
                    break;
                case 'underline':
                    formattedText = `<u>${selectedText}</u>`;
                    break;
            }
            
            textarea.value = textarea.value.substring(0, start) + formattedText + textarea.value.substring(end);
            textarea.focus();
            textarea.setSelectionRange(start + formattedText.length, start + formattedText.length);
        }
        
        function insertMath() {
            const textarea = document.getElementById('questionText');
            const start = textarea.selectionStart;
            const mathText = prompt('Enter mathematical expression (supports LaTeX):', 'x = \\frac{-b \\pm \\sqrt{b^2 - 4ac}}{2a}');
            
            if (mathText) {
                const formattedMath = `\\[${mathText}\\]`;
                textarea.value = textarea.value.substring(0, start) + formattedMath + textarea.value.substring(start);
                textarea.focus();
            }
        }
        
        function insertImage() {
            const imageUrl = prompt('Enter image URL:', 'https://example.com/image.jpg');
            if (imageUrl) {
                const textarea = document.getElementById('questionText');
                const start = textarea.selectionStart;
                const imgTag = `<img src="${imageUrl}" alt="Image" style="max-width: 100%;">`;
                textarea.value = textarea.value.substring(0, start) + imgTag + textarea.value.substring(start);
                textarea.focus();
            }
        }
        
        function insertTable() {
            const rows = prompt('Number of rows:', '3');
            const cols = prompt('Number of columns:', '3');
            
            if (rows && cols) {
                let tableHTML = '<table border="1" style="width: 100%; border-collapse: collapse;">';
                for (let i = 0; i < rows; i++) {
                    tableHTML += '<tr>';
                    for (let j = 0; j < cols; j++) {
                        tableHTML += `<td style="padding: 5px;">Cell ${i+1}-${j+1}</td>`;
                    }
                    tableHTML += '</tr>';
                }
                tableHTML += '</table>';
                
                const textarea = document.getElementById('questionText');
                const start = textarea.selectionStart;
                textarea.value = textarea.value.substring(0, start) + tableHTML + textarea.value.substring(start);
                textarea.focus();
            }
        }
        
        // Preview question
        function previewQuestion() {
            const form = document.getElementById('questionForm');
            const formData = new FormData(form);
            
            // Build preview HTML
            let previewHTML = `
                <h2>Question Preview</h2>
                <div class="question-preview">
                    <div style="margin-bottom: 20px;">
                        <strong>Type:</strong> ${document.getElementById('questionType').options[document.getElementById('questionType').selectedIndex].text}<br>
                        <strong>Subject:</strong> ${document.querySelector('select[name="subject_id"]').options[document.querySelector('select[name="subject_id"]').selectedIndex].text}<br>
                        <strong>Class:</strong> ${document.querySelector('select[name="class_id"]').options[document.querySelector('select[name="class_id"]').selectedIndex].text}<br>
                        <strong>Difficulty:</strong> ${document.querySelector('select[name="difficulty_level"]').options[document.querySelector('select[name="difficulty_level"]').selectedIndex].text}<br>
                        <strong>Marks:</strong> ${formData.get('marks')}
                    </div>
                    
                    <div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                        ${document.getElementById('questionText').value}
                    </div>
            `;
            
            // Add options preview if MCQ
            if (document.getElementById('questionType').value === 'mcq') {
                const options = document.querySelectorAll('input[name="options[]"]');
                if (options.length > 0) {
                    previewHTML += '<h3>Options:</h3><ol>';
                    options.forEach((option, index) => {
                        if (option.value.trim()) {
                            const isCorrect = document.querySelector(`input[name="correct_option"][value="${String.fromCharCode(65 + index)}"]`)?.checked;
                            previewHTML += `<li ${isCorrect ? 'style="color: green; font-weight: bold;"' : ''}>${option.value} ${isCorrect ? '✓' : ''}</li>`;
                        }
                    });
                    previewHTML += '</ol>';
                }
            }
            
            previewHTML += '</div>';
            
            document.getElementById('previewContent').innerHTML = previewHTML;
            document.getElementById('previewModal').style.display = 'block';
        }
        
        // Close preview
        function closePreview() {
            document.getElementById('previewModal').style.display = 'none';
        }
        
        // Export questions
        function exportQuestions() {
            // This would export questions in various formats (PDF, Word, Excel)
            alert('Export functionality would be implemented here.\n\nOptions:\n1. Export as PDF\n2. Export as Word\n3. Export as Excel\n4. Export as JSON');
        }
        
        // Print table
        function printTable() {
            const tableContent = document.querySelector('.summary-table-modern').outerHTML;
            const originalContent = document.body.innerHTML;

            document.body.innerHTML = `
                <html>
                    <head>
                        <title>Questions Bank Summary</title>
                        <style>
                            body { font-family: Arial, sans-serif; padding: 20px; }
                            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                            th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
                            th { background: #f8f9fa; font-weight: bold; }
                            .question-count { background: #3498db; color: white; padding: 4px 8px; border-radius: 50px; font-weight: bold; }
                            .question-type-badge { padding: 4px 8px; border-radius: 20px; font-size: 0.8rem; font-weight: 500; }
                            .type-mcq { background: #e8f5e8; color: #2e7d32; }
                            .type-true_false { background: #fff3e0; color: #ef6c00; }
                            .type-short_answer { background: #e3f2fd; color: #1565c0; }
                            .type-essay { background: #f3e5f5; color: #6a1b9a; }
                            .type-fill_blank { background: #fce4ec; color: #ad1457; }
                            @media print { body { padding: 0; } }
                        </style>
                    </head>
                    <body>
                        <h1>Questions Bank Summary</h1>
                        <p>Generated on: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</p>
                        <p>Total Entries: ${document.querySelectorAll('.summary-table-modern tbody tr').length}</p>
                        ${tableContent}
                    </body>
                </html>
            `;

            window.print();
            document.body.innerHTML = originalContent;
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleQuestionOptions();

            const toggleBtn = document.getElementById('toggleQuestionForm');
            const formBody = document.getElementById('questionFormBody');
            if (toggleBtn && formBody) {
                const setState = (expanded) => {
                    formBody.style.display = expanded ? 'block' : 'none';
                    toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    toggleBtn.classList.toggle('is-collapsed', !expanded);
                    toggleBtn.innerHTML = expanded
                        ? '<i class="fas fa-eye-slash"></i><span>Hide Form</span>'
                        : '<i class="fas fa-eye"></i><span>Show Form</span>';
                };
                setState(true);
                toggleBtn.addEventListener('click', function() {
                    setState(formBody.style.display === 'none');
                });
            }
            
            // Close preview modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('previewModal');
                if (event.target === modal) {
                    closePreview();
                }
            };

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closePreview();
                }
            });
        });
    </script>

    <script src="../assets/js/offline-core.js" defer></script>
    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
