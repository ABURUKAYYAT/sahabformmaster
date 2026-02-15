add school time table
add school library


-- Add/alter columns required by student pages to support admission login, contact, class mapping and timestamps
ALTER TABLE `students`
  -- ADD COLUMN `admission_no` VARCHAR(100) NOT NULL AFTER `full_name`,
  -- ADD COLUMN `user_id` INT(11) DEFAULT NULL AFTER `admission_no`,
 -- ADD COLUMN `class_id` INT(11) DEFAULT NULL AFTER `user_id`,
  -- ADD COLUMN `phone` VARCHAR(30) DEFAULT NULL AFTER `class_id`,
  -- ADD COLUMN `address` VARCHAR(255) DEFAULT NULL AFTER `phone`,
  -- ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER `address`,
  -- ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
  -- ADD UNIQUE KEY `uq_students_admission_no` (`admission_no`),
 -- ADD INDEX `idx_students_class` (`class_id`),
--  ADD INDEX `idx_students_user` (`user_id`);

-- Foreign keys linking students to classes and users (set NULL if related row removed)
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;


-- Create student_notes table (teacher notes / behavior) if not present
CREATE TABLE IF NOT EXISTS `student_notes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `teacher_id` INT(11) NOT NULL,
  `note_text` TEXT NOT NULL,
  `note_type` VARCHAR(50) DEFAULT 'note',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sn_student` (`student_id`),
  KEY `idx_sn_teacher` (`teacher_id`),
  CONSTRAINT `fk_sn_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sn_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Create attendance table if not present (one row per student per date)
CREATE TABLE IF NOT EXISTS `attendance` (
  `student_id` INT(11) NOT NULL,
  `date` DATE NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `recorded_by` INT(11) DEFAULT NULL,
  `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`,`date`),
  KEY `idx_att_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_att_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_att_user` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Create results_complaints table if not present (student complaints about results)
CREATE TABLE IF NOT EXISTS `results_complaints` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `result_id` INT(11) NOT NULL,
  `student_id` INT(11) NOT NULL,
  `complaint_text` TEXT NOT NULL,
  `status` ENUM('pending','resolved','rejected') NOT NULL DEFAULT 'pending',
  `teacher_response` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rc_result` (`result_id`),
  KEY `idx_rc_student` (`student_id`),
  CONSTRAINT `fk_rc_result` FOREIGN KEY (`result_id`) REFERENCES `results`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rc_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- (Optional) Ensure results table has expected columns used by the pages
-- If `results` exists but lacks columns, alter as needed; otherwise create a minimal schema.
-- Example ALTER if columns missing (run only when necessary):
ALTER TABLE `results`
  ADD COLUMN IF NOT EXISTS `term` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `first_ca` DECIMAL(5,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `second_ca` DECIMAL(5,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `exam` DECIMAL(5,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

  <?php

session_start();
require_once '../config/db.php';

// Access control: only teachers
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: ../index.php');
    exit;
}

$errors = [];
$student = null;
$class = null;
$results = [];
$school = null;
$class_count = 0;
$term = $_GET['term'] ?? $_POST['term'] ?? '1st Term';
$academic_session = trim($_GET['academic_session'] ?? $_POST['academic_session'] ?? '');

function calculate_grade($total) {
    if ($total >= 90) return ['A','Excellent'];
    if ($total >= 80) return ['B','Very Good'];
    if ($total >= 70) return ['C','Good'];
    if ($total >= 60) return ['D','Fair'];
    if ($total >= 50) return ['E','Pass'];
    return ['F','Fail'];
}


    $school = fetchSchoolInfo($pdo);


    function fetchSchoolInfo(PDO $pdo) {
    $candidates = ['school','schools','settings','app_settings','site_settings','config','options'];
    foreach ($candidates as $tbl) {
        try {
            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl))->fetchColumn();
            if (!$exists) continue;

            // Inspect columns
            $cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
            $colsLower = array_map('strtolower', $cols);

            // If table looks like key/value rows (common names)
            $isKeyValue = in_array('option_name', $colsLower) || in_array('option_key', $colsLower) ||
                          (in_array('name', $colsLower) && in_array('value', $colsLower));

            if ($isKeyValue) {
                // Load all rows and normalize into a map
                $rows = $pdo->query("SELECT * FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) continue;
                $map = [];
                foreach ($rows as $r) {
                    $k = $r['option_name'] ?? $r['option_key'] ?? $r['name'] ?? $r['key'] ?? null;
                    $v = $r['option_value'] ?? $r['value'] ?? $r['option_val'] ?? ($r['val'] ?? null);
                    if ($k !== null) $map[$k] = $v;
                }
                // map common field names
                $name = $map['school_name'] ?? $map['site_name'] ?? $map['name'] ?? null;
                $address = $map['address'] ?? $map['location'] ?? null;
                $phone = $map['phone'] ?? $map['contact'] ?? null;
                $email = $map['email'] ?? $map['contact_email'] ?? null;
                return array_filter(['name'=>$name,'address'=>$address,'phone'=>$phone,'email'=>$email]);
            } else {
                // Single-row style table: try select first row and map typical columns
                $row = $pdo->query("SELECT * FROM `$tbl` LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;
                $name = $row['name'] ?? $row['school_name'] ?? $row['title'] ?? null;
                $address = $row['address'] ?? $row['location'] ?? null;
                $phone = $row['phone'] ?? $row['contact'] ?? null;
                $email = $row['email'] ?? null;
                return array_filter(['name'=>$name,'address'=>$address,'phone'=>$phone,'email'=>$email]);
            }
        } catch (Exception $e) {
            error_log("fetchSchoolInfo error for table {$tbl}: " . $e->getMessage());
            continue;
        }
    }
        return null;
    }


// Try to fetch basic school info (works if you have `school` or `settings` table)
try {
    $tbl = $pdo->query("SHOW TABLES LIKE 'school'")->fetchColumn();
    if ($tbl) {
        $school = $pdo->query("SELECT * FROM school LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $tbl2 = $pdo->query("SHOW TABLES LIKE 'settings'")->fetchColumn();
        if ($tbl2) $school = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Exception $e) {
    $school = null;
}

// Handle form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admission_no = trim($_POST['admission_no'] ?? '');
    $term = $_POST['term'] ?? $term;
    $academic_session = trim($_POST['academic_session'] ?? $academic_session);

    // inside the POST handler, replace results query with this safer logic
try {
    // Fetch student (existing code) ...
    // after student found and class info fetched:

    // Check whether academic_session column exists
    $hasSessionCol = (bool) $pdo->query("SHOW COLUMNS FROM `results` LIKE 'academic_session'")->fetchColumn();

    // Build results query and bind parameters only when needed
    $sql = "SELECT r.*, sub.subject_name
              FROM results r
              LEFT JOIN subjects sub ON r.subject_id = sub.id
              WHERE r.student_id = :sid AND r.term = :term";
    $params = ['sid' => $student['id'], 'term' => $term];

    if ($hasSessionCol && $academic_session !== '') {
        $sql .= " AND r.academic_session LIKE :session";
        $params['session'] = '%' . $academic_session . '%';
    }

    $sql .= " ORDER BY sub.subject_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    $errors[] = 'Database error: ' . $ex->getMessage();
    error_log('single_result.php DB error: ' . $ex->getMessage());
}

    if ($admission_no === '') {
        $errors[] = 'Please enter an admission number.';
    } else {
        try {
            // Fetch student
            $stmt = $pdo->prepare("SELECT * FROM students WHERE admission_no = :ad LIMIT 1");
            $stmt->execute(['ad' => $admission_no]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                $errors[] = 'Student not found for the provided admission number.';
            } else {
                // Fetch class info
                if (!empty($student['class_id'])) {
                    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :cid LIMIT 1");
                    $stmt->execute(['cid' => $student['class_id']]);
                    $class = $stmt->fetch(PDO::FETCH_ASSOC);
                    // Count students in class
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = :cid");
                    $stmt->execute(['cid' => $student['class_id']]);
                    $class_count = (int)$stmt->fetchColumn();
                }

                // Fetch results for term (+ optional session filter)
                $sql = "SELECT r.*, sub.subject_name
                          FROM results r
                          LEFT JOIN subjects sub ON r.subject_id = sub.id
                          WHERE r.student_id = :sid AND r.term = :term AND ( :session = '' OR r.academic_session LIKE CONCAT('%', :session, '%') )";
                $params = ['sid' => $student['id'], 'term' => $term];
                if ($academic_session !== '') {
                    $sql .= " AND r.academic_session = :session";
                    $params['session'] = $academic_session;
                }
                $sql .= " ORDER BY sub.subject_name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
        } catch (PDOException $ex) {
            $errors[] = 'Database error.';
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Single Student Results</title>
<style>
  :root{--gold:#b8860b;--muted:#666;--bg:#fbfbfb}
  body{font-family:Segoe UI,Arial,sans-serif;background:var(--bg);margin:0;padding:0}
  header{background:linear-gradient(90deg,var(--gold),#ffd700);color:#fff;padding:12px 18px}
  .container{max-width:980px;margin:18px auto;padding:12px}
  .card{background:#fff;padding:14px;border-radius:8px;margin-bottom:12px;box-shadow:0 6px 18px rgba(0,0,0,.04)}
  .form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
  input[type="text"], select{padding:8px;border:1px solid #e9e9e9;border-radius:6px;font-size:14px}
  button{background:linear-gradient(135deg,#b8860b,#ffd700);color:#fff;border:0;padding:8px 12px;border-radius:6px;cursor:pointer}
  table{width:100%;border-collapse:collapse;margin-top:12px}
  th,td{padding:8px;border:1px solid #f0e6c2;text-align:left}
  .muted{color:var(--muted);font-size:13px}
  .small{font-size:13px}
  .error{background:#fff6f6;color:#c00;padding:10px;border-radius:6px;border:1px solid #f2cfcf}
</style>
</head>
<body>
<header><div class="container"><strong>Teacher</strong> — Single Student Lookup</div></header>
<main class="container">
  <section class="card">
    <form method="POST" id="lookupForm">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:220px">
          <label class="small">Admission Number</label>
          <input type="text" name="admission_no" placeholder="e.g. A12345" value="<?php echo htmlspecialchars($_POST['admission_no'] ?? ''); ?>" required>
        </div>

        <div style="min-width:160px">
          <label class="small">Term</label>
          <select name="term">
            <option value="1st Term" <?php echo ($term === '1st Term') ? 'selected' : ''; ?>>1st Term</option>
            <option value="2nd Term" <?php echo ($term === '2nd Term') ? 'selected' : ''; ?>>2nd Term</option>
            <option value="3rd Term" <?php echo ($term === '3rd Term') ? 'selected' : ''; ?>>3rd Term</option>
          </select>
        </div>

        <div style="min-width:180px">
          <label class="small">Academic Session (optional)</label>
          <input type="text" name="academic_session" placeholder="e.g. 2024/2025" value="<?php echo htmlspecialchars($academic_session); ?>">
        </div>

        <div style="min-width:120px">
          <button type="submit">Fetch</button>
        </div>
      </div>
    </form>
  </section>

  <?php if (!empty($errors)): ?>
    <section class="card error"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></section>
  <?php endif; ?>

  <?php if ($student): ?>
<section class="card">
      <h3>Student Information</h3>
      <div class="small muted">Admission No: <?php echo htmlspecialchars($student['admission_no']); ?></div>
      <div style="display:flex;gap:24px;margin-top:8px;flex-wrap:wrap">
        <div><strong>Name</strong><div class="muted"><?php echo htmlspecialchars($student['full_name']); ?></div></div>
        <div><strong>Class</strong><div class="muted"><?php echo htmlspecialchars($class['class_name'] ?? 'Unassigned'); ?></div></div>
        <div><strong>Total in Class</strong><div class="muted"><?php echo intval($class_count); ?></div></div>
        <div><strong>Term</strong><div class="muted"><?php echo htmlspecialchars($term); ?></div></div>
        <div><strong>Session</strong><div class="muted"><?php echo $academic_session ? htmlspecialchars($academic_session) : '<span class="muted">Not filtered</span>'; ?></div></div>
      </div>
    <!-- </section> -->

    <!-- <section class="card"> -->
      <h3>Results</h3>
      <?php if (empty($results)): ?>
        <div class="muted">No results found for the selected term/session.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Subject</th>
              <th>1st CA</th>
              <th>2nd CA</th>
              <th>Total C.A.</th>
              <th>Exam</th>
              <th>Grand Total</th>
              <th>Grade</th>
              <th>Remark</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $sum_total = 0;
              foreach ($results as $i => $r):
                $first = floatval($r['first_ca'] ?? 0);
                $second = floatval($r['second_ca'] ?? 0);
                $total_ca = $first + $second;
                $exam = floatval($r['exam'] ?? 0);
                $grand = $total_ca + $exam;
                $sum_total += $grand;
                list($g, $rem) = calculate_grade($grand);
            ?>
              <tr>
                <td><?php echo $i + 1; ?></td>
                <td><?php echo htmlspecialchars($r['subject_name'] ?? ''); ?></td>
                <td><?php echo $first; ?></td>
                <td><?php echo $second; ?></td>
                <td><?php echo $total_ca; ?></td>
                <td><?php echo $exam; ?></td>
                <td><?php echo $grand; ?></td>
                <td><?php echo $g; ?></td>
                <td><?php echo $rem; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="6" style="text-align:right"><strong>Total Score</strong></td>
              <td colspan="3"><?php echo $sum_total; ?></td>
            </tr>
            <tr>
              <td colspan="6" style="text-align:right"><strong>Average</strong></td>
              <td colspan="3"><?php echo count($results) ? round($sum_total / count($results), 2) : 0; ?></td>
            </tr>
          </tfoot>
        </table>
      <?php endif; ?>
    <!-- </section> -->
  <?php endif; ?>

  <!-- <section class="card small muted"> -->
    <?php if ($school): ?>
      <div><strong><?php echo htmlspecialchars($school['name'] ?? $school['school_name'] ?? 'School'); ?></strong></div>
      <div><?php echo htmlspecialchars($school['address'] ?? $school['location'] ?? ''); ?></div>
      <div><?php echo htmlspecialchars($school['phone'] ?? $school['contact'] ?? ''); ?></div>
    <?php else: ?>
      <div>No school information available in DB.</div>
    <?php endif; ?>


    <form method="POST" action="generate-result-pdf.php" style="display:inline;">
        <input type="hidden" name="student_id" value="<?php echo intval($student['id'] ?? 0); ?>">
        <input type="hidden" name="class_id" value="<?php echo intval($student['class_id'] ?? 0); ?>">
        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
        <button type="submit" class="btn-pdf">PDF</button>
   </form>

</section>
  
    
</main>
</body>
</html>

<?php

require_once '../config/db.php';
require_once '../TCPDF-main/TCPDF-main/tcpdf.php'; // Include TCPDF library

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

// load CSS file for PDF (embed inside <style> so TCPDF applies it)
$cssPath = __DIR__ . '/../assets/css/result-pdf.css';
$css = '';
if (file_exists($cssPath)) {
    $css = file_get_contents($cssPath);
}

// Ensure a PDF-safe font (DejaVu Sans) is used for most unicode support
$pdf->setFont('dejavusans', '', 12);

// School Profile Header and content already prepared in $html
// Prepend the stylesheet so TCPDF will parse it when writing HTML
$html = '<style>' . $css . '</style>' . $html;

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


results compilations processes

<?php

session_start();
require_once '../config/db.php';

// Only allow class teachers to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];

$class_id = $_GET['id'] ?? $_GET['class_id'] ?? $_REQUEST['class'] ?? $_POST['class_id'] ?? null;
// normalize term values and use same default tokens the UI uses
function normalize_term(string $t): string {
    $map = [
        'first term' => '1st Term', '1st term' => '1st Term', '1st' => '1st Term', 'first' => '1st Term',
        'second term' => '2nd Term', '2nd term' => '2nd Term', '2nd' => '2nd Term', 'second' => '2nd Term',
        'third term' => '3rd Term', '3rd term' => '3rd Term', '3rd' => '3rd Term', 'third' => '3rd Term'
    ];
    $k = strtolower(trim($t));
    return $map[$k] ?? $t;
}
$term = $_GET['term'] ?? $_REQUEST['term'] ?? '1st Term';
$term = normalize_term($term);
 // Default term
$errors = [];
$success = '';

// normalize and validate class_id; if missing, try to infer when teacher has exactly one assigned class
if ($class_id !== null) {
    $class_id = filter_var($class_id, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
}

if (!$class_id) {
    // try to infer a single class assigned to this teacher
    $stmt = $pdo->prepare("SELECT DISTINCT sa.class_id FROM subject_assignments sa WHERE sa.teacher_id = :teacher_id");
    $stmt->execute(['teacher_id' => $teacher_id]);
    $classes_for_teacher = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($classes_for_teacher) === 1) {
        $class_id = (int)$classes_for_teacher[0]; // use the only assigned class
    } else {
        // Friendly redirect to teacher home if no class provided and cannot infer
        header("Location: ../index.php?error=invalid_or_missing_class_id");
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name
    FROM classes c
    JOIN subject_assignments sa ON c.id = sa.class_id
    WHERE sa.teacher_id = :teacher_id
    ORDER BY c.class_name
");
$stmt->execute(['teacher_id' => $teacher_id]);
$teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Accept an optional filter_class_id from GET (must be a class teacher is assigned to)
$filter_class_id = isset($_GET['filter_class_id']) ? filter_var($_GET['filter_class_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : null;
if ($filter_class_id) {
    // ensure teacher is assigned to this class
    $assignedIds = array_column($teacher_classes, 'id');
    if (!in_array($filter_class_id, $assignedIds, true)) {
        // ignore invalid class filter
        $filter_class_id = $class_id; // fallback to current class
    }
} else {
    $filter_class_id = $class_id;
}

// Accept optional admission_no filter
$filter_admission_no = isset($_GET['admission_no']) ? trim($_GET['admission_no']) : '';

// normalize term if provided via GET (already done above), allow override from GET
if (isset($_GET['term']) && $_GET['term'] !== '') {
    $term = normalize_term($_GET['term']);
}

$results_sql = "
    SELECT r.*, s.full_name AS student_name, s.admission_no, sub.subject_name
    FROM results r
    JOIN students s ON r.student_id = s.id
    JOIN subjects sub ON r.subject_id = sub.id
    WHERE s.class_id = :class_id AND r.term = :term
";

$params = [
    'class_id' => $filter_class_id,
    'term' => $term,
];

if ($filter_admission_no !== '') {
    $results_sql .= " AND s.admission_no = :admission_no";
    $params['admission_no'] = $filter_admission_no;
}

$results_sql .= " ORDER BY s.full_name, sub.subject_name";

$stmt = $pdo->prepare($results_sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ensure teacher is assigned to this class (at least one subject)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_assignments WHERE class_id = :class_id AND teacher_id = :teacher_id");
$stmt->execute(['class_id' => $class_id, 'teacher_id' => $teacher_id]);
if ((int)$stmt->fetchColumn() === 0) {
    die("Access denied. You are not assigned to this class.");
}

// Fetch class information
$stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :id");
$stmt->execute(['id' => $class_id]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$class) {
    header("Location: index.php?error=Class not found.");
    exit;
}

// Fetch students in the class
$stmt = $pdo->prepare("SELECT * FROM students WHERE class_id = :class_id ORDER BY full_name ASC");
$stmt->execute(['class_id' => $class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects assigned to this teacher for this class (limit subjects list)
$stmt = $pdo->prepare("SELECT s.* FROM subjects s JOIN subject_assignments sa ON s.id = sa.subject_id WHERE sa.class_id = :class_id AND sa.teacher_id = :teacher_id ORDER BY s.subject_name ASC");
$stmt->execute(['class_id' => $class_id, 'teacher_id' => $teacher_id]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle CRUD operations and complaint resolution
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_result') {
        $student_id = intval($_POST['student_id'] ?? 0);
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $first_ca = floatval($_POST['first_ca'] ?? 0);
        $second_ca = floatval($_POST['second_ca'] ?? 0);
        $exam = floatval($_POST['exam'] ?? 0);

        // basic validation
        if ($student_id <= 0 || $subject_id <= 0) $errors[] = "Student and subject are required.";
        // ensure student belongs to class
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id AND class_id = :class_id");
        $stmt->execute(['id' => $student_id, 'class_id' => $class_id]);
        if ((int)$stmt->fetchColumn() === 0) $errors[] = "Student does not belong to this class.";

        // ensure subject is assigned to this teacher for this class
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_assignments WHERE subject_id = :subject_id AND class_id = :class_id AND teacher_id = :teacher_id");
        $stmt->execute(['subject_id' => $subject_id, 'class_id' => $class_id, 'teacher_id' => $teacher_id]);
        if ((int)$stmt->fetchColumn() === 0) $errors[] = "You are not assigned to teach this subject for this class.";

        // clamp scores 0-100
        foreach (['first_ca' => $first_ca, 'second_ca' => $second_ca, 'exam' => $exam] as $k => $v) {
            if ($v < 0) ${$k} = 0;
            if ($v > 100) ${$k} = 100;
        }

        if (empty($errors)) {
            // Check if result already exists
            $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = :student_id AND subject_id = :subject_id AND term = :term");
            $stmt->execute([
                'student_id' => $student_id,
                'subject_id' => $subject_id,
                'term' => $term
            ]);
            $existing_result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_result) {
                // Update existing result
                $hasUpdatedAt = (bool) $pdo->query("SHOW COLUMNS FROM `results` LIKE 'updated_at'")->fetchColumn();

if ($hasUpdatedAt) {
    $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, updated_at = NOW() WHERE id = :id");
} else {
    $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam WHERE id = :id");
}

$stmt->execute([
    'first_ca' => $first_ca,
    'second_ca' => $second_ca,
    'exam' => $exam,
    'id' => $existing_result['id']
]);
$success = "Result updated successfully.";
           } else {
                // Insert new result
                $academic_session = trim($_POST['academic_session'] ?? '');
                $total_ca = $first_ca + $second_ca;
                $total_score = $total_ca + $exam;

                $stmt = $pdo->prepare("
                    INSERT INTO results 
                      (student_id, subject_id, term, academic_session, total_ca, first_ca, second_ca, exam, total_score, created_at)
                    VALUES 
                      (:student_id, :subject_id, :term, :academic_session, :total_ca, :first_ca, :second_ca, :exam, :total_score, NOW())
                ");

                $stmt->execute([
                    'student_id'       => $student_id,
                    'subject_id'       => $subject_id,
                    'term'             => $term,
                    'academic_session' => $academic_session,
                    'total_ca'         => $total_ca,
                    'first_ca'         => $first_ca,
                    'second_ca'        => $second_ca,
                    'exam'             => $exam,
                    'total_score'      => $total_score,
                ]);
                $success = "Result added successfully.";
            }
        }
    }

    if ($action === 'delete_result') {
        $result_id = intval($_POST['result_id'] ?? 0);
        if ($result_id > 0) {
            // ensure result belongs to this class
            $stmt = $pdo->prepare("SELECT r.id FROM results r JOIN students s ON r.student_id = s.id WHERE r.id = :id AND s.class_id = :class_id");
            $stmt->execute(['id' => $result_id, 'class_id' => $class_id]);
            if ($stmt->fetchColumn()) {
                $pdo->prepare("DELETE FROM results WHERE id = :id")->execute(['id' => $result_id]);
                $success = "Result deleted successfully.";
            } else {
                $errors[] = "Result not found or does not belong to this class.";
            }
        }
    }

    if ($action === 'resolve_complaint') {
        $complaint_id = intval($_POST['complaint_id'] ?? 0);
        $response = trim($_POST['response'] ?? '');
        if ($complaint_id > 0) {
            // mark resolved and save teacher response
            $stmt = $pdo->prepare("UPDATE results_complaints SET status = 'resolved', teacher_response = :response, resolved_at = NOW() WHERE id = :id");
            $stmt->execute(['response' => $response, 'id' => $complaint_id]);
            $success = "Complaint marked resolved.";
        }
    }
}

// Fetch results for the class and term
$stmt = $pdo->prepare("SELECT r.*, s.full_name AS student_name, sub.subject_name 
                       FROM results r
                       JOIN students s ON r.student_id = s.id
                       JOIN subjects sub ON r.subject_id = sub.id
                       WHERE s.class_id = :class_id AND r.term = :term
                       ORDER BY s.full_name, sub.subject_name");
$stmt->execute(['class_id' => $class_id, 'term' => $term]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch complaints for this class/term
$complaints = [];
try {
    $tbl = $pdo->query("SHOW TABLES LIKE 'results_complaints'")->fetchColumn();
    if ($tbl) {
        $stmt = $pdo->prepare("
            SELECT rc.*, r.student_id, r.subject_id, s.full_name, sub.subject_name
            FROM results_complaints rc
            JOIN results r ON rc.result_id = r.id
            JOIN students s ON r.student_id = s.id
            JOIN subjects sub ON r.subject_id = sub.id
            WHERE s.class_id = :class_id AND r.term = :term
            ORDER BY rc.status ASC, rc.created_at DESC
        ");
        $stmt->execute(['class_id' => $class_id, 'term' => $term]);
        $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // results_complaints table not present — proceed with empty complaints
        $complaints = [];
    }
} catch (PDOException $ex) {
    // Optional: log the error to server logs for debugging
    // error_log($ex->getMessage());
    $complaints = [];
}

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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Results | SahabFormMaster</title>
    <!-- <link rel="stylesheet" href="../assets/css/dashboard.css"> -->
    <link rel="stylesheet" href="../assets/css/tresults.css">
</head>
<body>

<header class="dashboard-header">
    <div class="header-container">
        <h1>Manage Results</h1>
        <p>Class: <?php echo htmlspecialchars($class['class_name']); ?> | Term: <?php echo htmlspecialchars($term); ?></p>
    </div>
</header>

<div class="dashboard-container">
    <main class="main-content">
                <div class="content-header">
            <h2>Results for <?php echo htmlspecialchars($class['class_name']); ?></h2>
            <p class="small-muted">Manage student results for the selected class and term.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Create / Update Result Form -->
        <section class="create-result-section">
            <div class="lesson-card">
                <h3>Create / Update Result</h3>
                <form method="POST" class="lesson-form" action="results.php">
                    <input type="hidden" name="action" value="save_result">

                    <div class="form-row">
                        <div class="form-col">
                            <label for="student_id">Student *</label>
                            <select id="student_id" name="student_id" class="form-control" required>
                                <option value="">Select student</option>
                                <?php foreach ($students as $s): ?>
                                    <option value="<?php echo intval($s['id']); ?>"><?php echo htmlspecialchars($s['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-col">
                            <label for="subject_id">Subject *</label>
                            <select id="subject_id" name="subject_id" class="form-control" required>
                                <option value="">Select subject</option>
                                <?php foreach ($subjects as $sub): ?>
                                    <option value="<?php echo intval($sub['id']); ?>"><?php echo htmlspecialchars($sub['subject_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class='form-col'>
                        <label for="term">Term</label>
                                <select id="term" name="term" class="form-control">
                                    <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                                    <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                                    <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                                </select>
                        </div>

                        <div class="form-col">
                            <label for="first_ca">Academic Session *</label>
                            <input type="text" id="academic_session" name="academic_session" class="form-control" min="0" max="100" step="0.1" value="0" required>
                        </div>

                        <div class="form-col">
                            <label for="first_ca">First C.A. *</label>
                            <input type="number" id="first_ca" name="first_ca" class="form-control" min="0" max="100" step="0.1" value="0" required>
                        </div>

                        <div class="form-col">
                            <label for="second_ca">Second C.A. *</label>
                            <input type="number" id="second_ca" name="second_ca" class="form-control" min="0" max="100" step="0.1" value="0" required>
                        </div>

                        <div class="form-col">
                            <label for="exam">Exam *</label>
                            <input type="number" id="exam" name="exam" class="form-control" min="0" max="100" step="0.1" value="0" required>
                        </div>
                    </div>

                    <div class="form-actions" style="margin-top:10px;">
                        <button type="submit" class="btn-gold">Save Result</button>
                    </div>
                </form>
            </div>
        </section>

         <section class="create-result-section">
            <form method="GET" class="lesson-form" style="margin-bottom:12px;">
                <div class="form-row" style="align-items:center;">
                    <div class="form-col" style="min-width:180px;">
                        <label for="term_filter">Term</label>
                        <select id="term_filter" name="term" class="form-control">
                            <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                            <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                            <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                        </select>
                    </div>

                    <div class="form-col" style="min-width:220px;">
                        <label for="class_filter">Class</label>
                        <select id="class_filter" name="filter_class_id" class="form-control">
                            <?php foreach ($teacher_classes as $tc): ?>
                                <option value="<?php echo intval($tc['id']); ?>" <?php echo $tc['id'] == $filter_class_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tc['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-col" style="min-width:220px;">
                        <label for="admission_no">Admission No</label>
                        <input id="admission_no" name="admission_no" class="form-control" value="<?php echo htmlspecialchars($filter_admission_no); ?>" placeholder="Optional - filter by admission no">
                    </div>

                    <div style="display:flex;align-items:flex-end;gap:8px;">
                        <button type="submit" class="btn-gold">Apply Filter</button>
                        <a href="results.php?id=<?php echo intval($class_id); ?>" class="btn-small" style="align-self:flex-end">Reset</a>
                    </div>
                </div>
            </form>
        </section>


        <!-- Results Table -->
        <section class="results-section">
            <table class="results-table">
                <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Student Name</th>
                        <th>Subject</th>
                        <th>First C.A.</th>
                        <th>Second C.A.</th>
                        <th>C.A. Total</th>
                        <th>Exam</th>
                        <th>Grand Total</th>
                        <th>Grade</th>
                        <th>Remark</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($results) === 0): ?>
                        <tr>
                            <td colspan="11" class="text-center">No results found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($results as $index => $result):
                            $ca_total = $result['first_ca'] + $result['second_ca'];
                            $grand_total = $ca_total + $result['exam'];
                            $grade_data = calculateGrade($grand_total);
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                            <td><?php echo $result['first_ca']; ?></td>
                            <td><?php echo $result['second_ca']; ?></td>
                            <td><?php echo $ca_total; ?></td>
                            <td><?php echo $result['exam']; ?></td>
                            <td><?php echo $grand_total; ?></td>
                            <td><?php echo $grade_data['grade']; ?></td>
                            <td><?php echo $grade_data['remark']; ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_result">
                                    <input type="hidden" name="result_id" value="<?php echo intval($result['id']); ?>">
                                    <button type="submit" class="btn-delete">Delete</button>
                                </form>

                                <form method="POST" action="generate-result-pdf.php" style="display:inline;">
                                    <input type="hidden" name="student_id" value="<?php echo intval($result['student_id']); ?>">
                                    <input type="hidden" name="class_id" value="<?php echo intval($class_id); ?>">
                                    <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                                    <button type="submit" class="btn-pdf">PDF</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- Complaints Section -->
        <section class="lesson-section">
            <h3>Student Result Complaints</h3>
            <?php if (empty($complaints)): ?>
                <p class="small-muted">No complaints.</p>
            <?php else: ?>
                <table class="results-table">
                    <thead>
                        <tr><th>#</th><th>Student</th><th>Subject</th><th>Complaint</th><th>Status</th><th>Teacher Response</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $i => $c): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo htmlspecialchars($c['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($c['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($c['complaint_text']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($c['status'])); ?></td>
                                <td><?php echo htmlspecialchars($c['teacher_response'] ?? ''); ?></td>
                                <td>
                                    <?php if ($c['status'] !== 'resolved'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="resolve_complaint">
                                            <input type="hidden" name="complaint_id" value="<?php echo intval($c['id']); ?>">
                                            <input type="text" name="response" placeholder="Response" required>
                                            <button class="btn-small">Resolve</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="small-muted">Resolved</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>