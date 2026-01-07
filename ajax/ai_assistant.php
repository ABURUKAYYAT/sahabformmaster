<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'response' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$user_role = $input['user_role'] ?? 'unknown';
$current_page = $input['current_page'] ?? '';

if (empty($message)) {
    echo json_encode(['success' => false, 'response' => 'Message cannot be empty']);
    exit;
}

// Load AI configuration
$ai_config = require_once '../config/ai_config.php';

// Check if OpenAI API key is configured
if (!isset($ai_config['openai_api_key']) || empty($ai_config['openai_api_key'])) {
    echo json_encode(['success' => false, 'response' => 'AI service is not configured. Please contact administrator.']);
    exit;
}

// Process the message and determine if it's an analytics query
$analytics_result = processAnalyticsQuery($message, $user_role, $pdo);

if ($analytics_result !== false) {
    // This was an analytics query, return the result
    echo json_encode(['success' => true, 'response' => $analytics_result]);
    exit;
}

// Build system prompt based on user role
$system_prompt = buildSystemPrompt($user_role, $current_page);

// Call OpenAI API
$response = callOpenAI($message, $system_prompt, $ai_config['openai_api_key']);

if ($response) {
    echo json_encode(['success' => true, 'response' => $response]);
} else {
    echo json_encode(['success' => false, 'response' => 'I apologize, but I\'m having trouble connecting to the AI service right now. Please try again later.']);
}

/**
 * Process analytics queries
 */
function processAnalyticsQuery($message, $user_role, $pdo) {
    $message_lower = strtolower($message);

    // Attendance analytics
    if (strpos($message_lower, 'attendance') !== false && (strpos($message_lower, 'analytics') !== false || strpos($message_lower, 'stats') !== false || strpos($message_lower, 'report') !== false)) {
        return getAttendanceAnalytics($user_role, $pdo);
    }

    // Fee collection status
    if ((strpos($message_lower, 'fee') !== false || strpos($message_lower, 'payment') !== false) && (strpos($message_lower, 'status') !== false || strpos($message_lower, 'collection') !== false || strpos($message_lower, 'report') !== false)) {
        return getFeeAnalytics($user_role, $pdo);
    }

    // Student performance
    if ((strpos($message_lower, 'student') !== false || strpos($message_lower, 'performance') !== false) && (strpos($message_lower, 'analytics') !== false || strpos($message_lower, 'stats') !== false)) {
        return getStudentPerformanceAnalytics($user_role, $pdo);
    }

    // General analytics
    if (strpos($message_lower, 'analytics') !== false || strpos($message_lower, 'dashboard') !== false || strpos($message_lower, 'overview') !== false) {
        return getGeneralAnalytics($user_role, $pdo);
    }

    return false; // Not an analytics query
}

/**
 * Get attendance analytics
 */
function getAttendanceAnalytics($user_role, $pdo) {
    try {
        if ($user_role === 'teacher') {
            // Teacher sees only their classes
            $teacher_id = $_SESSION['user_id'];
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(a.id) as total_records,
                    ROUND(AVG(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100, 1) as attendance_rate,
                    COUNT(DISTINCT a.student_id) as unique_students,
                    COUNT(DISTINCT s.class_id) as classes_covered
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                JOIN class_teachers ct ON s.class_id = ct.class_id
                WHERE ct.teacher_id = ? AND a.date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$teacher_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            return "<strong>📊 Your Attendance Analytics (Last 30 Days)</strong><br><br>" .
                   "• <strong>Overall Attendance Rate:</strong> {$data['attendance_rate']}%<br>" .
                   "• <strong>Students Marked:</strong> {$data['unique_students']}<br>" .
                   "• <strong>Classes Covered:</strong> {$data['classes_covered']}<br><br>" .
                   "<em>Tip: You can view detailed attendance in the Attendance section.</em>";
        } else {
            // Admin sees school-wide
            $stmt = $pdo->query("
                SELECT
                    COUNT(a.id) as total_records,
                    ROUND(AVG(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100, 1) as attendance_rate,
                    COUNT(DISTINCT a.student_id) as unique_students,
                    COUNT(DISTINCT s.class_id) as classes_count
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                WHERE a.date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            return "<strong>📊 School Attendance Analytics (Last 30 Days)</strong><br><br>" .
                   "• <strong>Overall Attendance Rate:</strong> {$data['attendance_rate']}%<br>" .
                   "• <strong>Total Students:</strong> {$data['unique_students']}<br>" .
                   "• <strong>Classes:</strong> {$data['classes_count']}<br><br>" .
                   "<em>You can generate detailed reports in the Attendance Register section.</em>";
        }
    } catch (Exception $e) {
        return "Sorry, I couldn't retrieve attendance data right now. Please try again later.";
    }
}

/**
 * Get fee collection analytics
 */
function getFeeAnalytics($user_role, $pdo) {
    try {
        if ($user_role === 'teacher') {
            return "As a teacher, you can view fee information for your students in the Payments section. Contact the administration for detailed fee analytics.";
        }

        // Admin sees fee analytics
        $stmt = $pdo->query("
            SELECT
                COUNT(*) as total_students,
                SUM(total_amount) as total_expected,
                SUM(amount_paid) as total_paid,
                SUM(total_amount - amount_paid) as total_outstanding,
                ROUND((SUM(amount_paid) / SUM(total_amount)) * 100, 1) as collection_rate
            FROM student_payments
            WHERE academic_year = YEAR(CURDATE())
        ");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return "<strong>💰 Fee Collection Analytics (Current Year)</strong><br><br>" .
               "• <strong>Collection Rate:</strong> {$data['collection_rate']}%<br>" .
               "• <strong>Total Expected:</strong> ₦" . number_format($data['total_expected']) . "<br>" .
               "• <strong>Total Collected:</strong> ₦" . number_format($data['total_paid']) . "<br>" .
               "• <strong>Outstanding:</strong> ₦" . number_format($data['total_outstanding']) . "<br><br>" .
               "<em>You can manage fees and generate reports in the School Fees section.</em>";
    } catch (Exception $e) {
        return "Sorry, I couldn't retrieve fee data right now. Please try again later.";
    }
}

/**
 * Get student performance analytics
 */
function getStudentPerformanceAnalytics($user_role, $pdo) {
    try {
        if ($user_role === 'teacher') {
            $teacher_id = $_SESSION['user_id'];
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(DISTINCT r.student_id) as students_with_results,
                    ROUND(AVG(r.score), 1) as avg_score,
                    COUNT(DISTINCT r.subject_id) as subjects_covered,
                    COUNT(r.id) as total_results
                FROM results r
                JOIN students s ON r.student_id = s.id
                JOIN class_teachers ct ON s.class_id = ct.class_id
                WHERE ct.teacher_id = ?
            ");
            $stmt->execute([$teacher_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            return "<strong>📈 Your Students' Performance</strong><br><br>" .
                   "• <strong>Students Assessed:</strong> {$data['students_with_results']}<br>" .
                   "• <strong>Average Score:</strong> {$data['avg_score']}%<br>" .
                   "• <strong>Subjects Covered:</strong> {$data['subjects_covered']}<br>" .
                   "• <strong>Total Results:</strong> {$data['total_results']}<br><br>" .
                   "<em>You can enter and view detailed results in the Results section.</em>";
        } else {
            $stmt = $pdo->query("
                SELECT
                    COUNT(DISTINCT student_id) as total_students,
                    ROUND(AVG(score), 1) as avg_score,
                    COUNT(DISTINCT subject_id) as subjects_count,
                    COUNT(*) as total_results
                FROM results
            ");
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            return "<strong>📈 School Performance Analytics</strong><br><br>" .
                   "• <strong>Students Assessed:</strong> {$data['total_students']}<br>" .
                   "• <strong>Overall Average:</strong> {$data['avg_score']}%<br>" .
                   "• <strong>Subjects:</strong> {$data['subjects_count']}<br>" .
                   "• <strong>Total Results:</strong> {$data['total_results']}<br><br>" .
                   "<em>You can manage results and generate reports in the Manage Results section.</em>";
        }
    } catch (Exception $e) {
        return "Sorry, I couldn't retrieve performance data right now. Please try again later.";
    }
}

/**
 * Get general analytics overview
 */
function getGeneralAnalytics($user_role, $pdo) {
    try {
        if ($user_role === 'teacher') {
            $teacher_id = $_SESSION['user_id'];

            // Get basic stats
            $stats = [];

            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT s.id) as student_count FROM students s JOIN class_teachers ct ON s.class_id = ct.class_id WHERE ct.teacher_id = ?");
            $stmt->execute([$teacher_id]);
            $stats['students'] = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) as class_count FROM class_teachers WHERE teacher_id = ?");
            $stmt->execute([$teacher_id]);
            $stats['classes'] = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) as lesson_count FROM lesson_plans WHERE teacher_id = ?");
            $stmt->execute([$teacher_id]);
            $stats['lessons'] = $stmt->fetchColumn();

            return "<strong>📊 Your Teaching Overview</strong><br><br>" .
                   "• <strong>Students:</strong> {$stats['students']}<br>" .
                   "• <strong>Classes:</strong> {$stats['classes']}<br>" .
                   "• <strong>Lesson Plans:</strong> {$stats['lessons']}<br><br>" .
                   "<em>Check your dashboard for more detailed statistics and recent activity.</em>";
        } else {
            // Admin overview
            $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM students WHERE is_active = 1");
            $total_students = $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) as total_teachers FROM users WHERE role IN ('teacher', 'principal') AND is_active = 1");
            $total_teachers = $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) as total_classes FROM classes");
            $total_classes = $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) as total_results FROM results");
            $total_results = $stmt->fetchColumn();

            return "<strong>📊 School Overview</strong><br><br>" .
                   "• <strong>Total Students:</strong> " . number_format($total_students) . "<br>" .
                   "• <strong>Total Teachers:</strong> " . number_format($total_teachers) . "<br>" .
                   "• <strong>Classes:</strong> " . number_format($total_classes) . "<br>" .
                   "• <strong>Results Processed:</strong> " . number_format($total_results) . "<br><br>" .
                   "<em>Visit your dashboard for charts, trends, and detailed analytics.</em>";
        }
    } catch (Exception $e) {
        return "Sorry, I couldn't retrieve overview data right now. Please try again later.";
    }
}

/**
 * Build system prompt based on user role
 */
function buildSystemPrompt($user_role, $current_page) {
    $base_prompt = "You are an AI assistant for SahabFormMaster, a comprehensive school management system. ";

    switch ($user_role) {
        case 'teacher':
            $base_prompt .= "You are helping a TEACHER. Provide guidance on:
- Managing students and classes
- Creating and managing lesson plans
- Entering and viewing results
- Taking attendance
- Managing subjects and curriculum
- Using question banks and generating papers
- Viewing student evaluations

Always provide step-by-step instructions and direct them to relevant sections of the system.";
            break;

        case 'principal':
        case 'admin':
            $base_prompt .= "You are helping an ADMINISTRATOR/PRINCIPAL. Provide guidance on:
- Managing students, teachers, and classes
- School-wide analytics and reporting
- Fee management and collection
- School calendar and diary
- User management and permissions
- Curriculum and subject management
- School news and announcements
- System configuration and settings

Always provide comprehensive guidance and direct them to appropriate admin sections.";
            break;

        default:
            $base_prompt .= "You are helping a USER. Provide general guidance about using the SahabFormMaster system.";
    }

    $base_prompt .= "

IMPORTANT: Keep responses helpful, accurate, and focused on the SahabFormMaster system. If asked about topics outside the school management system, politely redirect to system-related help.

Current page context: {$current_page}

Response guidelines:
- Be concise but comprehensive
- Use bullet points for steps
- Include relevant page/section references
- Suggest next actions when appropriate
- Use friendly, professional tone";

    return $base_prompt;
}

/**
 * Call OpenAI API
 */
function callOpenAI($message, $system_prompt, $api_key) {
    $url = 'https://api.openai.com/v1/chat/completions';

    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $message]
        ],
        'max_tokens' => 1000,
        'temperature' => 0.7
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("OpenAI API Error: " . $error);
        return false;
    }

    if ($http_code !== 200) {
        error_log("OpenAI API HTTP Error: " . $http_code . " - " . $response);
        return false;
    }

    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return trim($result['choices'][0]['message']['content']);
    }

    return false;
}
?>
