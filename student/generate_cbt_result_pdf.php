<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/cbt_helpers.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

ensure_cbt_schema($pdo);

$student_id = (int)$_SESSION['student_id'];
$test_id = (int)($_GET['test_id'] ?? 0);
$current_school_id = get_current_school_id();

if ($test_id <= 0) {
    $_SESSION['cbt_error'] = 'Invalid test selected for result PDF.';
    header("Location: cbt_tests.php");
    exit;
}

if ($current_school_id === false) {
    $school_stmt = $pdo->prepare("SELECT school_id FROM students WHERE id = ? LIMIT 1");
    $school_stmt->execute([$student_id]);
    $resolved_school_id = $school_stmt->fetchColumn();
    if ($resolved_school_id !== false) {
        $_SESSION['school_id'] = $resolved_school_id;
        $current_school_id = $resolved_school_id;
    }
}

$student_stmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.admission_no, s.school_id, c.class_name
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE s.id = ? AND s.school_id = ?
    LIMIT 1
");
$student_stmt->execute([$student_id, $current_school_id]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $_SESSION['cbt_error'] = 'Student record not found.';
    header("Location: cbt_tests.php");
    exit;
}

$attempt_stmt = $pdo->prepare("
    SELECT
        a.id AS attempt_id,
        a.started_at,
        a.submitted_at,
        a.score,
        a.total_questions,
        t.id AS test_id,
        t.title,
        t.duration_minutes,
        t.starts_at,
        t.ends_at,
        subj.subject_name,
        cls.class_name
    FROM cbt_attempts a
    JOIN cbt_tests t ON a.test_id = t.id
    LEFT JOIN subjects subj ON t.subject_id = subj.id
    LEFT JOIN classes cls ON t.class_id = cls.id
    WHERE a.student_id = ?
      AND a.test_id = ?
      AND a.status = 'submitted'
      AND t.school_id = ?
    LIMIT 1
");
$attempt_stmt->execute([$student_id, $test_id, $current_school_id]);
$attempt = $attempt_stmt->fetch(PDO::FETCH_ASSOC);

if (!$attempt) {
    $_SESSION['cbt_error'] = 'No submitted result found for this test.';
    header("Location: cbt_tests.php");
    exit;
}

$school_stmt = $pdo->prepare("SELECT * FROM school_profile WHERE school_id = ? LIMIT 1");
$school_stmt->execute([$current_school_id]);
$school = $school_stmt->fetch(PDO::FETCH_ASSOC);
if (!$school) {
    $school_stmt = $pdo->query("SELECT * FROM school_profile LIMIT 1");
    $school = $school_stmt->fetch(PDO::FETCH_ASSOC);
}

$details_stmt = $pdo->prepare("
    SELECT
        q.id,
        q.question_text,
        q.correct_option,
        a.selected_option,
        a.is_correct
    FROM cbt_questions q
    LEFT JOIN cbt_answers a
      ON a.question_id = q.id
     AND a.attempt_id = ?
    WHERE q.test_id = ?
    ORDER BY q.question_order ASC, q.id ASC
");
$details_stmt->execute([(int)$attempt['attempt_id'], $test_id]);
$question_rows = $details_stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

$schoolName = $school['school_name'] ?? 'School';
$schoolAddress = $school['school_address'] ?? '';
$schoolPhone = $school['school_phone'] ?? '';
$schoolEmail = $school['school_email'] ?? '';

$score = (int)$attempt['score'];
$totalQuestions = max(0, (int)$attempt['total_questions']);
$percent = $totalQuestions > 0 ? round(($score / $totalQuestions) * 100, 2) : 0;
$resultText = $percent >= 50 ? 'PASS' : 'FAIL';

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('SahabFormMaster');
$pdf->SetAuthor($schoolName);
$pdf->SetTitle('CBT Result Slip');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 15);
$pdf->Cell(0, 8, strtoupper($schoolName), 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
if ($schoolAddress !== '') {
    $pdf->Cell(0, 6, $schoolAddress, 0, 1, 'C');
}
$contact = trim(($schoolPhone !== '' ? 'Phone: ' . $schoolPhone : '') . (($schoolPhone !== '' && $schoolEmail !== '') ? ' | ' : '') . ($schoolEmail !== '' ? 'Email: ' . $schoolEmail : ''));
if ($contact !== '') {
    $pdf->Cell(0, 6, $contact, 0, 1, 'C');
}
$pdf->Ln(2);

$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, 'CBT RESULT REPORT', 0, 1, 'C');
$pdf->Ln(1);

$infoHtml = '
<table border="1" cellpadding="5">
    <tr style="background-color:#f2f2f2;">
        <td width="50%"><strong>Student Name:</strong> ' . htmlspecialchars((string)$student['full_name']) . '</td>
        <td width="50%"><strong>Admission No:</strong> ' . htmlspecialchars((string)($student['admission_no'] ?? 'N/A')) . '</td>
    </tr>
    <tr>
        <td width="50%"><strong>Class:</strong> ' . htmlspecialchars((string)($attempt['class_name'] ?? $student['class_name'] ?? 'N/A')) . '</td>
        <td width="50%"><strong>Subject:</strong> ' . htmlspecialchars((string)($attempt['subject_name'] ?? 'N/A')) . '</td>
    </tr>
    <tr>
        <td width="50%"><strong>Exam Title:</strong> ' . htmlspecialchars((string)$attempt['title']) . '</td>
        <td width="50%"><strong>Duration:</strong> ' . (int)$attempt['duration_minutes'] . ' mins</td>
    </tr>
    <tr>
        <td width="50%"><strong>Scheduled:</strong> ' . (!empty($attempt['starts_at']) ? date('M d, Y H:i', strtotime((string)$attempt['starts_at'])) : 'Immediate') . '</td>
        <td width="50%"><strong>Submitted:</strong> ' . (!empty($attempt['submitted_at']) ? date('M d, Y H:i', strtotime((string)$attempt['submitted_at'])) : '-') . '</td>
    </tr>
    <tr style="background-color:#f9f9f9;">
        <td width="50%"><strong>Score:</strong> ' . $score . ' / ' . $totalQuestions . '</td>
        <td width="50%"><strong>Percentage:</strong> ' . $percent . '% (' . $resultText . ')</td>
    </tr>
</table>';
$pdf->writeHTML($infoHtml, true, false, true, false, '');
$pdf->Ln(2);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 7, 'Question Breakdown', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

$rowsHtml = '';
$count = 1;
foreach ($question_rows as $row) {
    $selected = $row['selected_option'] ?? '-';
    $correct = $row['correct_option'] ?? '-';
    $status = ((int)($row['is_correct'] ?? 0) === 1) ? 'Correct' : 'Wrong';
    if ($selected === '-' || $selected === '') {
        $status = 'Not Answered';
    }
    $rowsHtml .= '
        <tr>
            <td width="7%">' . $count . '</td>
            <td width="61%">' . htmlspecialchars((string)$row['question_text']) . '</td>
            <td width="10%">' . htmlspecialchars((string)$selected) . '</td>
            <td width="10%">' . htmlspecialchars((string)$correct) . '</td>
            <td width="12%">' . htmlspecialchars($status) . '</td>
        </tr>';
    $count++;
}

if ($rowsHtml === '') {
    $rowsHtml = '<tr><td colspan="5">No question records found.</td></tr>';
}

$questionsHtml = '
<table border="1" cellpadding="4">
    <tr style="background-color:#f2f2f2;">
        <th width="7%"><strong>#</strong></th>
        <th width="61%"><strong>Question</strong></th>
        <th width="10%"><strong>Your Ans</strong></th>
        <th width="10%"><strong>Correct</strong></th>
        <th width="12%"><strong>Status</strong></th>
    </tr>
    ' . $rowsHtml . '
</table>';
$pdf->writeHTML($questionsHtml, true, false, true, false, '');

$fileName = 'cbt_result_test_' . $test_id . '_student_' . $student_id . '.pdf';
$pdf->Output($fileName, 'D');
exit;
