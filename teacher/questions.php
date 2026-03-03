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

// Search text for question library
$search_text = $_GET['search_text'] ?? '';

// Build query for question library
$questions_base_query = "
    FROM questions_bank q
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN classes c ON q.class_id = c.id
    LEFT JOIN question_tags t ON q.id = t.question_id
    WHERE q.school_id = ?
";

$question_params = [$user_school_id];

if (!empty($subject_filter)) {
    $questions_base_query .= " AND q.subject_id = ?";
    $question_params[] = $subject_filter;
}

if (!empty($class_filter)) {
    $questions_base_query .= " AND q.class_id = ?";
    $question_params[] = $class_filter;
}

if (!empty($difficulty_filter)) {
    $questions_base_query .= " AND q.difficulty_level = ?";
    $question_params[] = $difficulty_filter;
}

if (!empty($type_filter)) {
    $questions_base_query .= " AND q.question_type = ?";
    $question_params[] = $type_filter;
}

if (!empty($status_filter)) {
    $questions_base_query .= " AND q.status = ?";
    $question_params[] = $status_filter;
}

if (!empty($search_text)) {
    $questions_base_query .= " AND (q.question_text LIKE ? OR q.question_code LIKE ? OR q.topic LIKE ? OR q.sub_topic LIKE ?)";
    $search_like = '%' . $search_text . '%';
    $question_params[] = $search_like;
    $question_params[] = $search_like;
    $question_params[] = $search_like;
    $question_params[] = $search_like;
}

$question_page = isset($_GET['question_page']) ? max(1, intval($_GET['question_page'])) : 1;
$question_per_page = 10;
$question_offset = ($question_page - 1) * $question_per_page;

$question_count_query = "SELECT COUNT(DISTINCT q.id) " . $questions_base_query;
$question_count_stmt = $pdo->prepare($question_count_query);
$question_count_stmt->execute($question_params);
$total_filtered_questions = (int)$question_count_stmt->fetchColumn();
$question_total_pages = max(1, (int)ceil($total_filtered_questions / $question_per_page));

$questions_query = "
    SELECT q.*, s.subject_name, c.class_name,
           GROUP_CONCAT(DISTINCT t.tag_name ORDER BY t.tag_name SEPARATOR ', ') as tags
    " . $questions_base_query . "
    GROUP BY q.id
    ORDER BY q.created_at DESC
    LIMIT " . (int)$question_per_page . " OFFSET " . (int)$question_offset;

$stmt = $pdo->prepare($questions_query);
$stmt->execute($question_params);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($questions as &$question) {
    $option_stmt = $pdo->prepare("
        SELECT option_letter, option_text, is_correct
        FROM question_options
        WHERE question_id = ?
        ORDER BY option_letter
    ");
    $option_stmt->execute([$question['id']]);
    $question['options'] = $option_stmt->fetchAll(PDO::FETCH_ASSOC);

    $correct_answers = [];
    foreach ($question['options'] as $option) {
        if ((int)$option['is_correct'] === 1) {
            $correct_answers[] = $option['option_letter'];
        }
    }
    $question['correct_answer'] = implode(', ', $correct_answers);
}
unset($question);

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
<?php include '../includes/teacher_questions_page.php'; ?>
