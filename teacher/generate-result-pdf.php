<?php

require_once '../config/db.php';
require_once '../tcpdf/tcpdf.php'; // Include TCPDF library

// Validate POST data
$student_id = $_POST['student_id'] ?? null;
$class_id = $_POST['class_id'] ?? null;
$term = $_POST['term'] ?? null;

if (!$student_id || !$class_id || !$term) {
    die("Invalid request.");
}

// Fetch school profile
$stmt = $pdo->query("SELECT * FROM school_profile WHERE id = 1");
$school = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch class information
$stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :class_id");
$stmt->execute(['class_id' => $class_id]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch student information
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = :student_id");
$stmt->execute(['student_id' => $student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch results for the student
$stmt = $pdo->prepare("SELECT r.*, sub.subject_name 
                       FROM results r
                       JOIN subjects sub ON r.subject_id = sub.id
                       WHERE r.student_id = :student_id AND r.term = :term");
$stmt->execute(['student_id' => $student_id, 'term' => $term]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total students in the class
$stmt = $pdo->prepare("SELECT COUNT(*) AS total_students FROM students WHERE class_id = :class_id");
$stmt->execute(['class_id' => $class_id]);
$total_students = $stmt->fetchColumn();

// Generate PDF
$pdf = new TCPDF();
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($school['school_name']);
$pdf->SetTitle("Result Sheet - " . $student['full_name']);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);
$pdf->AddPage();

// School Profile Header
$html = '<h1 style="text-align: center;">' . htmlspecialchars($school['school_name']) . '</h1>';
$html .= '<p style="text-align: center;">' . htmlspecialchars($school['school_address']) . '</p>';
$html .= '<p style="text-align: center;">' . htmlspecialchars($school['school_motto']) . '</p>';
if ($school['school_logo']) {
    $pdf->Image('../' . $school['school_logo'], 10, 10, 30, 30, '', '', '', true);
}

// Class and Student Information
$html .= '<h2>Class: ' . htmlspecialchars($class['class_name']) . '</h2>';
$html .= '<p>Student Name: ' . htmlspecialchars($student['full_name']) . '</p>';
$html .= '<p>Term: ' . htmlspecialchars($term) . '</p>';
$html .= '<p>Total Students in Class: ' . $total_students . '</p>';

// Results Table
$html .= '<table border="1" cellpadding="5">
            <thead>
                <tr>
                    <th>S/N</th>
                    <th>Subject</th>
                    <th>First C.A.</th>
                    <th>Second C.A.</th>
                    <th>C.A. Total</th>
                    <th>Exam</th>
                    <th>Grand Total</th>
                    <th>Grade</th>
                    <th>Remark</th>
                </tr>
            </thead>
            <tbody>';

foreach ($results as $index => $result) {
    $ca_total = $result['first_ca'] + $result['second_ca'];
    $grand_total = $ca_total + $result['exam'];
    $grade_data = calculateGrade($grand_total);

    $html .= '<tr>
                <td>' . ($index + 1) . '</td>
                <td>' . htmlspecialchars($result['subject_name']) . '</td>
                <td>' . $result['first_ca'] . '</td>
                <td>' . $result['second_ca'] . '</td>
                <td>' . $ca_total . '</td>
                <td>' . $result['exam'] . '</td>
                <td>' . $grand_total . '</td>
                <td>' . $grade_data['grade'] . '</td>
                <td>' . $grade_data['remark'] . '</td>
              </tr>';
}

$html .= '</tbody></table>';

// Footer with Comments and Dates
$html .= '<h3>Teacher\'s Comment:</h3><p>__________________________</p>';
$html .= '<h3>Principal\'s Comment:</h3><p>__________________________</p>';
$html .= '<p>Date of Resumption: __________________________</p>';
$html .= '<p>Next Term Begins: __________________________</p>';

$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$pdf->Output('Result_Sheet_' . $student['full_name'] . '.pdf', 'I');

// Function to calculate grade and remark
function calculateGrade($grand_total) {
    if ($grand_total >= 90) return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($grand_total >= 80) return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($grand_total >= 70) return ['grade' => 'C', 'remark' => 'Good'];
    if ($grand_total >= 60) return ['grade' => 'D', 'remark' => 'Fair'];
    if ($grand_total >= 50) return ['grade' => 'E', 'remark' => 'Pass'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}
?>