<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

// Allow teacher exports and student self-service exports.
$session_role = strtolower((string) ($_SESSION['role'] ?? ''));
$is_teacher = isset($_SESSION['user_id']) && $session_role === 'teacher';
$is_student = isset($_SESSION['student_id']) || isset($_SESSION['admission_no']);

if (!$is_teacher && !$is_student) {
    header("Location: ../index.php");
    exit;
}

$current_school_id = $is_teacher ? require_school_auth() : get_current_school_id();
if (!$current_school_id) {
    header("Location: ../index.php");
    exit;
}

// Validate POST data
$student_id = (int) ($_POST['student_id'] ?? 0);
$class_id = (int) ($_POST['class_id'] ?? 0);
$term = trim((string) ($_POST['term'] ?? ''));
$academic_session = trim((string) ($_POST['academic_session'] ?? ''));

if ($is_student) {
    $session_student_id = (int) ($_SESSION['student_id'] ?? 0);
    $session_admission_no = trim((string) ($_SESSION['admission_no'] ?? ''));

    $student_resolve_sql = "SELECT id, class_id FROM students WHERE school_id = :school_id";
    $student_resolve_params = ['school_id' => $current_school_id];
    if ($session_student_id > 0) {
        $student_resolve_sql .= " AND id = :session_student_id";
        $student_resolve_params['session_student_id'] = $session_student_id;
    } elseif ($session_admission_no !== '') {
        $student_resolve_sql .= " AND admission_no = :session_admission_no";
        $student_resolve_params['session_admission_no'] = $session_admission_no;
    } else {
        header("Location: ../student/index.php");
        exit;
    }
    $student_resolve_sql .= " LIMIT 1";

    $student_resolve_stmt = $pdo->prepare($student_resolve_sql);
    $student_resolve_stmt->execute($student_resolve_params);
    $resolved_student = $student_resolve_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resolved_student) {
        header("Location: ../student/index.php");
        exit;
    }

    $resolved_student_id = (int) ($resolved_student['id'] ?? 0);
    $resolved_class_id = (int) ($resolved_student['class_id'] ?? 0);

    // Prevent student from requesting another student's transcript.
    if ($student_id > 0 && $student_id !== $resolved_student_id) {
        http_response_code(403);
        die("Unauthorized request.");
    }

    $student_id = $resolved_student_id;
    $class_id = $resolved_class_id;
}

if ($student_id <= 0 || $class_id <= 0 || $term === '') {
    die("Invalid request parameters.");
}

// Fetch school profile - filtered by school_id
$stmt = $pdo->prepare("SELECT * FROM school_profile WHERE school_id = ?");
$stmt->execute([$current_school_id]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch class information - filtered by school_id
$stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :class_id AND school_id = :school_id");
$stmt->execute(['class_id' => $class_id, 'school_id' => $current_school_id]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch student information - filtered by school_id
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = :student_id AND school_id = :school_id");
$stmt->execute(['student_id' => $student_id, 'school_id' => $current_school_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student record not found.");
}

if (!$class) {
    $class = ['class_name' => 'N/A'];
}

if (!$school) {
    $school = [];
}

// Fetch results for the student - filtered by school_id
$stmt = $pdo->prepare("
    SELECT r.*, sub.subject_name
    FROM results r
    JOIN subjects sub ON r.subject_id = sub.id
    WHERE r.student_id = :student_id
      AND r.term = :term
      AND r.school_id = :school_id_result
      AND sub.school_id = :school_id
      AND (
        :academic_session_filter = ''
        OR LOWER(TRIM(COALESCE(r.academic_session, ''))) = LOWER(TRIM(:academic_session_match))
      )
    ORDER BY sub.subject_name
");
$stmt->execute([
    'student_id' => $student_id,
    'term' => $term,
    'school_id_result' => $current_school_id,
    'school_id' => $current_school_id,
    'academic_session_filter' => $academic_session,
    'academic_session_match' => $academic_session
]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate class statistics - filtered by school_id
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT r.student_id) as total_students,
           AVG(COALESCE(r.first_ca,0) + COALESCE(r.second_ca,0) + COALESCE(r.exam,0)) as class_average,
           MAX(COALESCE(r.first_ca,0) + COALESCE(r.second_ca,0) + COALESCE(r.exam,0)) as highest_score,
           MIN(COALESCE(r.first_ca,0) + COALESCE(r.second_ca,0) + COALESCE(r.exam,0)) as lowest_score
    FROM results r
    JOIN students s ON r.student_id = s.id
    WHERE s.class_id = :class_id
      AND r.term = :term
      AND s.school_id = :school_id
      AND r.school_id = :school_id_result
      AND (
        :academic_session_filter = ''
        OR LOWER(TRIM(COALESCE(r.academic_session, ''))) = LOWER(TRIM(:academic_session_match))
      )
");
$stmt->execute([
    'class_id' => $class_id,
    'term' => $term,
    'school_id' => $current_school_id,
    'school_id_result' => $current_school_id,
    'academic_session_filter' => $academic_session,
    'academic_session_match' => $academic_session
]);
$class_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate student's position in class
if (!empty($results)) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 as student_position
        FROM (
            SELECT r.student_id, AVG(COALESCE(r.first_ca,0) + COALESCE(r.second_ca,0) + COALESCE(r.exam,0)) as avg_score
            FROM results r
            JOIN students s ON r.student_id = s.id
            WHERE s.class_id = :class_id AND r.term = :term
              AND s.school_id = :school_id AND r.school_id = :school_id_result
              AND (
                :academic_session_rank_filter = ''
                OR LOWER(TRIM(COALESCE(r.academic_session, ''))) = LOWER(TRIM(:academic_session_rank_match))
              )
            GROUP BY r.student_id
        ) student_averages
        WHERE student_averages.avg_score > (
            SELECT AVG(COALESCE(r2.first_ca,0) + COALESCE(r2.second_ca,0) + COALESCE(r2.exam,0))
            FROM results r2
            WHERE r2.student_id = :student_id AND r2.term = :term_student AND r2.school_id = :school_id_student_result
              AND (
                :academic_session_student_filter = ''
                OR LOWER(TRIM(COALESCE(r2.academic_session, ''))) = LOWER(TRIM(:academic_session_student_match))
              )
        )
    ");
    $stmt->execute([
        'class_id' => $class_id,
        'student_id' => $student_id,
        'term' => $term,
        'term_student' => $term,
        'school_id' => $current_school_id,
        'school_id_result' => $current_school_id,
        'school_id_student_result' => $current_school_id,
        'academic_session_rank_filter' => $academic_session,
        'academic_session_rank_match' => $academic_session,
        'academic_session_student_filter' => $academic_session,
        'academic_session_student_match' => $academic_session
    ]);
    $position = $stmt->fetchColumn();
} else {
    $position = 'N/A';
}

function resolve_pdf_asset_path(?string $raw_path): ?string
{
    $path = trim((string) $raw_path);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return null;
    }

    $normalized = str_replace('\\', '/', $path);
    $normalized = preg_replace('#\.\./+#', '../', $normalized);

    $candidates = [
        __DIR__ . '/' . ltrim($normalized, '/'),
        __DIR__ . '/' . $normalized,
        dirname(__DIR__) . '/' . ltrim($normalized, '/'),
        dirname(__DIR__) . '/' . ltrim(preg_replace('#^\.\./#', '', $normalized), '/')
    ];

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false && is_file($resolved)) {
            return $resolved;
        }
    }

    return null;
}

function resolve_student_photo_path(array $student): ?string
{
    $fields = ['passport_photo', 'student_photo', 'photo', 'profile_image', 'image', 'avatar'];
    foreach ($fields as $field) {
        $candidate = resolve_pdf_asset_path((string) ($student[$field] ?? ''));
        if ($candidate !== null) {
            return $candidate;
        }
    }
    return null;
}

function safe_pdf_text($value, string $fallback = 'N/A'): string
{
    $text = trim((string) $value);
    return $text !== '' ? $text : $fallback;
}

function grade_palette(string $grade): array
{
    $map = [
        'A' => ['bg' => [220, 252, 231], 'text' => [22, 101, 52]],
        'B' => ['bg' => [219, 234, 254], 'text' => [30, 64, 175]],
        'C' => ['bg' => [254, 243, 199], 'text' => [146, 64, 14]],
        'D' => ['bg' => [254, 215, 170], 'text' => [154, 52, 18]],
        'E' => ['bg' => [255, 237, 213], 'text' => [124, 45, 18]],
        'F' => ['bg' => [254, 226, 226], 'text' => [153, 27, 27]],
    ];
    return $map[$grade] ?? ['bg' => [241, 245, 249], 'text' => [51, 65, 85]];
}

$school_name = safe_pdf_text($school['school_name'] ?? get_school_display_name($current_school_id), 'School');
$school_motto = safe_pdf_text($school['school_motto'] ?? 'Excellence and Character', '');
$school_address = safe_pdf_text($school['school_address'] ?? '');
$school_logo_path = resolve_pdf_asset_path((string) ($school['school_logo'] ?? ($school['logo'] ?? '')));
$student_photo_path = resolve_student_photo_path($student);

$subject_count = count($results);
$total_score = 0.0;
$pass_count = 0;
foreach ($results as $result_row) {
    $first_ca = (float) ($result_row['first_ca'] ?? 0);
    $second_ca = (float) ($result_row['second_ca'] ?? 0);
    $exam = (float) ($result_row['exam'] ?? 0);
    $score = $first_ca + $second_ca + $exam;
    $total_score += $score;
    if ($score >= 50) {
        $pass_count++;
    }
}
$overall_average = $subject_count > 0 ? ($total_score / $subject_count) : 0.0;
$overall_grade = calculateGrade($overall_average);
$pass_rate = $subject_count > 0 ? (($pass_count / $subject_count) * 100) : 0.0;
$total_students = (int) ($class_stats['total_students'] ?? 0);
$position_label = is_numeric($position) ? ((int) $position . '/' . max(1, $total_students)) : 'N/A';

class StudentTranscriptPDF extends TCPDF {
    private string $schoolName;
    private string $schoolMotto;
    private string $schoolAddress;
    private ?string $schoolLogoPath;
    private array $student;
    private array $class;
    private string $term;
    private string $academicSession;
    private ?string $studentPhotoPath;

    public function __construct(
        string $school_name,
        string $school_motto,
        string $school_address,
        ?string $school_logo_path,
        array $student,
        array $class,
        string $term,
        string $academic_session,
        ?string $student_photo_path
    ) {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->schoolName = $school_name;
        $this->schoolMotto = $school_motto;
        $this->schoolAddress = $school_address;
        $this->schoolLogoPath = $school_logo_path;
        $this->student = $student;
        $this->class = $class;
        $this->term = $term;
        $this->academicSession = trim($academic_session);
        $this->studentPhotoPath = $student_photo_path;
    }

    public function Header(): void
    {
        $this->SetFillColor(15, 23, 42);
        $this->Rect(0, 0, 210, 34, 'F');
        $this->SetFillColor(20, 184, 166);
        $this->Rect(0, 34, 210, 4, 'F');

        if ($this->schoolLogoPath !== null) {
            $this->Image($this->schoolLogoPath, 11, 6.5, 19, 19, '', '', '', false, 300, '', false, false, 0, false, false, false);
        }

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(33, 7);
        $this->Cell(118, 7, strtoupper($this->schoolName), 0, 1, 'L');

        $this->SetFont('helvetica', '', 9);
        $this->SetXY(33, 14);
        $this->Cell(118, 5.5, $this->schoolMotto, 0, 1, 'L');

        $this->SetFont('helvetica', '', 8);
        $this->SetXY(33, 20);
        $this->Cell(125, 5, $this->schoolAddress, 0, 1, 'L');

        $this->SetFont('helvetica', 'B', 10);
        $this->SetXY(152, 8);
        $this->Cell(47, 5.5, 'STUDENT RESULT REPORT', 0, 1, 'R');
        $this->SetFont('helvetica', '', 8);
        $this->SetXY(152, 14);
        $this->Cell(47, 5, 'Generated: ' . date('M d, Y'), 0, 1, 'R');

        $this->SetDrawColor(203, 213, 225);
        $this->SetFillColor(255, 255, 255);
        $this->RoundedRect(10, 41, 190, 35, 2, '1111', 'DF');

        $this->SetTextColor(15, 23, 42);
        $this->SetFont('helvetica', 'B', 8.5);
        $this->SetXY(14, 45);
        $this->Cell(22, 5, 'Student:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 9);
        $this->Cell(58, 5, safe_pdf_text($this->student['full_name'] ?? ''), 0, 0, 'L');

        $this->SetFont('helvetica', 'B', 8.5);
        $this->Cell(22, 5, 'Admission:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 9);
        $this->Cell(40, 5, safe_pdf_text($this->student['admission_no'] ?? ''), 0, 1, 'L');

        $this->SetXY(14, 51);
        $this->SetFont('helvetica', 'B', 8.5);
        $this->Cell(22, 5, 'Class:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 9);
        $this->Cell(58, 5, safe_pdf_text($this->class['class_name'] ?? ''), 0, 0, 'L');

        $this->SetFont('helvetica', 'B', 8.5);
        $this->Cell(22, 5, 'Term:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 9);
        $this->Cell(40, 5, safe_pdf_text($this->term), 0, 1, 'L');

        $this->SetXY(14, 57);
        $display_session = $this->academicSession !== '' ? $this->academicSession : (date('Y') . '/' . (date('Y') + 1));
        $this->SetFont('helvetica', 'B', 8.5);
        $this->Cell(22, 5, 'Session:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 9);
        $this->Cell(58, 5, $display_session, 0, 0, 'L');

        $this->SetFont('helvetica', 'B', 8.5);
        $this->Cell(22, 5, 'Gender:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 9);
        $this->Cell(40, 5, safe_pdf_text($this->student['gender'] ?? '', '-'), 0, 1, 'L');

        $this->SetXY(169, 44);
        $this->SetFillColor(241, 245, 249);
        $this->SetDrawColor(148, 163, 184);
        $this->Rect(169, 44, 26, 30, 'DF');
        if ($this->studentPhotoPath !== null) {
            $this->Image($this->studentPhotoPath, 170, 45, 24, 28, '', '', '', false, 300, '', false, false, 1, false, false, false);
        } else {
            $this->SetFont('helvetica', 'B', 7);
            $this->SetTextColor(100, 116, 139);
            $this->SetXY(169, 56);
            $this->Cell(26, 5, 'NO PHOTO', 0, 1, 'C');
        }

        $this->SetY(82);
    }

    public function Footer(): void
    {
        $this->SetY(-17);
        $this->SetDrawColor(203, 213, 225);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetY(-14);
        $this->SetFont('helvetica', '', 7.8);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(120, 5, 'Prepared by ' . $this->schoolName, 0, 0, 'L');
        $this->Cell(70, 5, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

$pdf = new StudentTranscriptPDF(
    $school_name,
    $school_motto,
    $school_address,
    $school_logo_path,
    $student,
    $class,
    $term,
    $academic_session,
    $student_photo_path
);

$pdf->SetCreator($school_name);
$pdf->SetAuthor($school_name);
$pdf->SetTitle('Academic Transcript - ' . safe_pdf_text($student['full_name'] ?? 'Student'));
$pdf->SetSubject('Student Academic Results for ' . $term . ($academic_session !== '' ? (' - ' . $academic_session) : ''));

$pdf->SetMargins(10, 82, 10);
$pdf->SetHeaderMargin(4);
$pdf->SetFooterMargin(12);
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetFont('helvetica', '', 9);
$pdf->AddPage();

$draw_metric_card = static function (
    TCPDF $pdf,
    float $x,
    float $y,
    float $width,
    float $height,
    string $title,
    string $value,
    array $style
): void {
    $pdf->SetDrawColor($style['border'][0], $style['border'][1], $style['border'][2]);
    $pdf->SetFillColor($style['bg'][0], $style['bg'][1], $style['bg'][2]);
    $pdf->RoundedRect($x, $y, $width, $height, 1.8, '1111', 'DF');

    $pdf->SetTextColor($style['title'][0], $style['title'][1], $style['title'][2]);
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetXY($x + 2.2, $y + 2.2);
    $pdf->Cell($width - 4.4, 4, strtoupper($title), 0, 1, 'L');

    $pdf->SetTextColor($style['value'][0], $style['value'][1], $style['value'][2]);
    $pdf->SetFont('helvetica', 'B', 11.3);
    $pdf->SetXY($x + 2.2, $y + 8);
    $pdf->Cell($width - 4.4, 6.5, $value, 0, 1, 'L');
};

if (!empty($results)) {
    $metric_styles = [
        ['bg' => [236, 253, 245], 'border' => [16, 185, 129], 'title' => [6, 95, 70], 'value' => [6, 78, 59]],
        ['bg' => [239, 246, 255], 'border' => [59, 130, 246], 'title' => [30, 64, 175], 'value' => [30, 58, 138]],
        ['bg' => [245, 243, 255], 'border' => [139, 92, 246], 'title' => [91, 33, 182], 'value' => [76, 29, 149]],
        ['bg' => [255, 247, 237], 'border' => [249, 115, 22], 'title' => [154, 52, 18], 'value' => [124, 45, 18]],
    ];

    $metric_y = $pdf->GetY();
    $card_w = 45.6;
    $gap = 2.8;
    $card_h = 20.5;
    $start_x = 10;

    $draw_metric_card($pdf, $start_x, $metric_y, $card_w, $card_h, 'Average Score', number_format($overall_average, 1) . '%', $metric_styles[0]);
    $draw_metric_card($pdf, $start_x + ($card_w + $gap), $metric_y, $card_w, $card_h, 'Overall Grade', $overall_grade['grade'] . ' (' . $overall_grade['remark'] . ')', $metric_styles[1]);
    $draw_metric_card($pdf, $start_x + (2 * ($card_w + $gap)), $metric_y, $card_w, $card_h, 'Class Position', $position_label, $metric_styles[2]);
    $draw_metric_card($pdf, $start_x + (3 * ($card_w + $gap)), $metric_y, $card_w, $card_h, 'Pass Rate', number_format($pass_rate, 1) . '%', $metric_styles[3]);

    $pdf->SetY($metric_y + $card_h + 5.2);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Detailed Subject Performance', 0, 1, 'L');

    $table_widths = [10, 50, 14, 14, 16, 14, 16, 16, 40];
    $render_table_header = static function (TCPDF $pdf, array $widths): void {
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(248, 250, 252);
        $pdf->SetFillColor(15, 23, 42);
        $pdf->SetDrawColor(15, 23, 42);
        $pdf->Cell($widths[0], 7.5, 'S/N', 1, 0, 'C', true);
        $pdf->Cell($widths[1], 7.5, 'SUBJECT', 1, 0, 'L', true);
        $pdf->Cell($widths[2], 7.5, '1ST CA', 1, 0, 'C', true);
        $pdf->Cell($widths[3], 7.5, '2ND CA', 1, 0, 'C', true);
        $pdf->Cell($widths[4], 7.5, 'CA TOT', 1, 0, 'C', true);
        $pdf->Cell($widths[5], 7.5, 'EXAM', 1, 0, 'C', true);
        $pdf->Cell($widths[6], 7.5, 'TOTAL', 1, 0, 'C', true);
        $pdf->Cell($widths[7], 7.5, 'GRADE', 1, 0, 'C', true);
        $pdf->Cell($widths[8], 7.5, 'REMARK', 1, 1, 'L', true);
    };

    $render_table_header($pdf, $table_widths);
    $pdf->SetFont('helvetica', '', 8.2);
    $pdf->SetTextColor(30, 41, 59);
    $row_height = 6.8;

    foreach ($results as $index => $result_row) {
        if ($pdf->GetY() + $row_height > 268) {
            $pdf->AddPage();
            $pdf->SetY(86);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->SetFont('helvetica', 'B', 10.5);
            $pdf->Cell(0, 5.5, 'Detailed Subject Performance (Continued)', 0, 1, 'L');
            $render_table_header($pdf, $table_widths);
            $pdf->SetFont('helvetica', '', 8.2);
            $pdf->SetTextColor(30, 41, 59);
        }

        $first_ca = (float) ($result_row['first_ca'] ?? 0);
        $second_ca = (float) ($result_row['second_ca'] ?? 0);
        $ca_total = $first_ca + $second_ca;
        $exam = (float) ($result_row['exam'] ?? 0);
        $grand_total = $ca_total + $exam;
        $grade_data = calculateGrade($grand_total);
        $grade_style = grade_palette($grade_data['grade']);

        $zebra = ($index % 2 === 0) ? [250, 252, 255] : [255, 255, 255];
        $pdf->SetFillColor($zebra[0], $zebra[1], $zebra[2]);
        $pdf->SetDrawColor(226, 232, 240);

        $pdf->Cell($table_widths[0], $row_height, (string) ($index + 1), 1, 0, 'C', true);
        $pdf->Cell($table_widths[1], $row_height, substr(safe_pdf_text($result_row['subject_name'] ?? ''), 0, 32), 1, 0, 'L', true);
        $pdf->Cell($table_widths[2], $row_height, number_format($first_ca, 1), 1, 0, 'C', true);
        $pdf->Cell($table_widths[3], $row_height, number_format($second_ca, 1), 1, 0, 'C', true);
        $pdf->Cell($table_widths[4], $row_height, number_format($ca_total, 1), 1, 0, 'C', true);
        $pdf->Cell($table_widths[5], $row_height, number_format($exam, 1), 1, 0, 'C', true);
        $pdf->Cell($table_widths[6], $row_height, number_format($grand_total, 1), 1, 0, 'C', true);

        $pdf->SetFillColor($grade_style['bg'][0], $grade_style['bg'][1], $grade_style['bg'][2]);
        $pdf->SetTextColor($grade_style['text'][0], $grade_style['text'][1], $grade_style['text'][2]);
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->Cell($table_widths[7], $row_height, $grade_data['grade'], 1, 0, 'C', true);

        $pdf->SetTextColor(30, 41, 59);
        $pdf->SetFont('helvetica', '', 8.2);
        $pdf->SetFillColor($zebra[0], $zebra[1], $zebra[2]);
        $pdf->Cell($table_widths[8], $row_height, $grade_data['remark'], 1, 1, 'L', true);
    }

    $pdf->SetFont('helvetica', 'B', 8.6);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetDrawColor(15, 23, 42);
    $pdf->Cell($table_widths[0], 7.4, '', 1, 0, 'C', true);
    $pdf->Cell($table_widths[1], 7.4, 'OVERALL PERFORMANCE', 1, 0, 'L', true);
    $pdf->Cell($table_widths[2], 7.4, '', 1, 0, 'C', true);
    $pdf->Cell($table_widths[3], 7.4, '', 1, 0, 'C', true);
    $pdf->Cell($table_widths[4], 7.4, '', 1, 0, 'C', true);
    $pdf->Cell($table_widths[5], 7.4, '', 1, 0, 'C', true);
    $pdf->Cell($table_widths[6], 7.4, number_format($overall_average, 1), 1, 0, 'C', true);
    $pdf->Cell($table_widths[7], 7.4, $overall_grade['grade'], 1, 0, 'C', true);
    $pdf->Cell($table_widths[8], 7.4, $overall_grade['remark'], 1, 1, 'L', true);

    if ($pdf->GetY() + 46 > 268) {
        $pdf->AddPage();
        $pdf->SetY(86);
    } else {
        $pdf->Ln(5);
    }

    $pdf->SetFont('helvetica', 'B', 10.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 6, 'Class Insights & Endorsement', 0, 1, 'L');

    $box_y = $pdf->GetY();
    $box_w = 93;
    $box_h = 26;

    $pdf->SetDrawColor(191, 219, 254);
    $pdf->SetFillColor(239, 246, 255);
    $pdf->RoundedRect(10, $box_y, $box_w, $box_h, 2, '1111', 'DF');
    $pdf->SetXY(13, $box_y + 2.4);
    $pdf->SetFont('helvetica', 'B', 8.6);
    $pdf->SetTextColor(30, 64, 175);
    $pdf->Cell(86, 4.5, 'Academic Standing', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 8.3);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->SetX(13);
    $pdf->Cell(86, 4.4, 'Position: ' . $position_label, 0, 1, 'L');
    $pdf->SetX(13);
    $pdf->Cell(86, 4.4, 'Class average: ' . number_format((float) ($class_stats['class_average'] ?? 0), 1) . '%', 0, 1, 'L');
    $pdf->SetX(13);
    $pdf->Cell(86, 4.4, 'Pass rate: ' . number_format($pass_rate, 1) . '%', 0, 1, 'L');

    $pdf->SetDrawColor(167, 243, 208);
    $pdf->SetFillColor(236, 253, 245);
    $pdf->RoundedRect(107, $box_y, $box_w, $box_h, 2, '1111', 'DF');
    $pdf->SetXY(110, $box_y + 2.4);
    $pdf->SetFont('helvetica', 'B', 8.6);
    $pdf->SetTextColor(6, 95, 70);
    $pdf->Cell(86, 4.5, 'Performance Highlights', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 8.3);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->SetX(110);
    $pdf->Cell(86, 4.4, 'Highest score: ' . number_format((float) ($class_stats['highest_score'] ?? 0), 1) . '%', 0, 1, 'L');
    $pdf->SetX(110);
    $pdf->Cell(86, 4.4, 'Lowest score: ' . number_format((float) ($class_stats['lowest_score'] ?? 0), 1) . '%', 0, 1, 'L');
    $pdf->SetX(110);
    $pdf->Cell(86, 4.4, 'Subjects offered: ' . $subject_count, 0, 1, 'L');

    $pdf->SetY($box_y + $box_h + 6);
    $pdf->SetFont('helvetica', 'B', 8.8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 5.6, 'Teacher Comment:', 0, 1, 'L');
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->RoundedRect(10, $pdf->GetY(), 190, 17, 1.8, '1111', 'DF');
    $pdf->SetY($pdf->GetY() + 18.5);
    $pdf->Cell(0, 5.3, 'Class Teacher Signature: ____________________    Date: _______________', 0, 1, 'L');
    $pdf->Cell(0, 5.3, 'Principal Signature: _________________________    Date: _______________', 0, 1, 'L');
} else {
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Result Summary', 0, 1, 'L');

    $pdf->SetDrawColor(252, 211, 77);
    $pdf->SetFillColor(255, 251, 235);
    $pdf->RoundedRect(10, $pdf->GetY() + 1, 190, 25, 2, '1111', 'DF');
    $pdf->SetY($pdf->GetY() + 7);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(146, 64, 14);
    $pdf->Cell(0, 6, 'No published results for the selected term/session.', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 8.8);
    $pdf->SetTextColor(120, 53, 15);
    $pdf->Cell(0, 5, 'Please contact the class teacher or check back later.', 0, 1, 'C');
}

// Generate filename and output
$safe_name = preg_replace('/[^A-Za-z0-9\-_]/', '_', $student['full_name']);
$safe_term = preg_replace('/[^A-Za-z0-9\-_]/', '_', $term);
$safe_session = $academic_session !== '' ? preg_replace('/[^A-Za-z0-9\-_]/', '_', $academic_session) : '';
$filename = 'Transcript_' . $safe_name . '_' . $safe_term
    . ($safe_session !== '' ? ('_' . $safe_session) : '')
    . '_' . date('Ymd') . '.pdf';

// Create directory if it doesn't exist
$export_dir = dirname(__DIR__) . '/exports/transcripts/';
if (!file_exists($export_dir)) {
    mkdir($export_dir, 0777, true);
}

$filepath = $export_dir . $filename;

// Save PDF file
$pdf->Output($filepath, 'F');

// Log the export (optional - skip if table doesn't exist)
try {
    $stmt = $pdo->prepare("
        INSERT INTO export_logs (user_id, export_type, file_path, exported_at, ip_address)
        VALUES (?, 'transcript', ?, NOW(), ?)
    ");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $filepath,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
} catch (PDOException $e) {
    // Silently skip logging if export_logs table doesn't exist
    // This is not critical functionality
}

// Output PDF to browser
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
header('Cache-Control: private');
readfile($filepath);
exit;

// Function to calculate grade and remark
function calculateGrade($score) {
    if ($score >= 90) return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($score >= 80) return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($score >= 70) return ['grade' => 'C', 'remark' => 'Good'];
    if ($score >= 60) return ['grade' => 'D', 'remark' => 'Fair'];
    if ($score >= 50) return ['grade' => 'E', 'remark' => 'Pass'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}
?>
