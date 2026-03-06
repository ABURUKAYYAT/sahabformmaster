<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only teachers
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
$current_school_id = require_school_auth();
$teacher_id = intval($_SESSION['user_id']);
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$errors = [];
$success = '';

// Fetch classes assigned to teacher from BOTH subject_assignments AND class_teachers
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name, 'subject' as assignment_type
    FROM classes c
    JOIN subject_assignments sa ON c.id = sa.class_id
    WHERE sa.teacher_id = :tid AND c.school_id = :school_id

    UNION

    SELECT DISTINCT c.id, c.class_name, 'form_master' as assignment_type
    FROM classes c
    JOIN class_teachers ct ON c.id = ct.class_id
    WHERE ct.teacher_id = :tid2 AND c.school_id = :school_id2

    ORDER BY class_name
");
$stmt->execute(['tid'=>$teacher_id, 'school_id'=>$current_school_id, 'tid2'=>$teacher_id, 'school_id2'=>$current_school_id]);
$assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total student count across assigned classes
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT s.id)
    FROM students s
    WHERE s.school_id = ?
    AND s.class_id IN (
        SELECT DISTINCT c.id
        FROM classes c
        WHERE c.school_id = ?
        AND (
            EXISTS (
                SELECT 1 FROM subject_assignments sa WHERE sa.class_id = c.id AND sa.teacher_id = ?
            )
            OR EXISTS (
                SELECT 1 FROM class_teachers ct WHERE ct.class_id = c.id AND ct.teacher_id = ?
            )
        )
    )
");
$stmt->execute([$current_school_id, $current_school_id, $teacher_id, $teacher_id]);
$student_count = $stmt->fetchColumn();

// Fetch ALL classes for the dropdown - school-filtered
$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name");
$stmt->execute([$current_school_id]);
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions: add_note, attendance, CRUD operations, bulk upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add note
    if ($action === 'add_note') {
        $student_id = intval($_POST['student_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($student_id <= 0 || $note === '') $errors[] = 'Student and note required.';
        else {
            // verify teacher has access to student's class
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM students s
                WHERE s.id = :sid
                AND (
                    EXISTS (
                        SELECT 1 FROM subject_assignments sa
                        WHERE sa.class_id = s.class_id
                        AND sa.teacher_id = :tid
                    )
                    OR
                    EXISTS (
                        SELECT 1 FROM class_teachers ct
                        WHERE ct.class_id = s.class_id
                        AND ct.teacher_id = :tid2
                    )
                )
            ");
            $stmt->execute(['sid'=>$student_id,'tid'=>$teacher_id,'tid2'=>$teacher_id]);
            if ($stmt->fetchColumn() == 0) $errors[] = 'Access denied. You can only add notes to students in your assigned classes.';
            else {
                $pdo->prepare("INSERT INTO student_notes (student_id, teacher_id, note_text, created_at) VALUES (:sid,:tid,:note,NOW())")
                    ->execute(['sid'=>$student_id,'tid'=>$teacher_id,'note'=>$note]);
                $success = 'Note saved.';
            }
        }
    }
    // Attendance
    elseif ($action === 'attendance') {
        $student_id = intval($_POST['student_id'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        $status = $_POST['status'] ?? 'present';
        if ($student_id <= 0) $errors[] = 'Student required.';
        else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM students s
                WHERE s.id = :sid
                AND (
                    EXISTS (
                        SELECT 1 FROM subject_assignments sa
                        WHERE sa.class_id = s.class_id
                        AND sa.teacher_id = :tid
                    )
                    OR
                    EXISTS (
                        SELECT 1 FROM class_teachers ct
                        WHERE ct.class_id = s.class_id
                        AND ct.teacher_id = :tid2
                    )
                )
            ");
            $stmt->execute(['sid'=>$student_id,'tid'=>$teacher_id,'tid2'=>$teacher_id]);
            if ($stmt->fetchColumn() == 0) $errors[] = 'Access denied. You can only mark attendance for students in your assigned classes.';
            else {
                $stmt = $pdo->prepare("REPLACE INTO attendance (student_id, date, status, recorded_by, recorded_at) VALUES (:sid,:date,:status,:tid,NOW())");
                $stmt->execute(['sid'=>$student_id,'date'=>$date,'status'=>$status,'tid'=>$teacher_id]);
                $success = 'Attendance recorded.';
            }
        }
    }
    // Add student
    elseif ($action === 'add_student') {
        $class_id = intval($_POST['class_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $admission_no = trim($_POST['admission_no'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $dob = $_POST['dob'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $guardian_name = trim($_POST['guardian_name'] ?? '');
        $guardian_phone = trim($_POST['guardian_phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $student_type = $_POST['student_type'] ?? 'fresh';

        // Validate
        if (empty($full_name)) $errors[] = 'Full name is required.';
        if (empty($admission_no)) $errors[] = 'Admission number is required.';
        if (empty($class_id)) $errors[] = 'Class is required.';

        // Check if admission number already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ? AND school_id = ?");
        $stmt->execute([$admission_no, $current_school_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Admission number already exists.';
        }

        // Check teacher access to class
        if ($class_id > 0) {
            $stmt = $pdo->prepare("
                SELECT CASE
                    WHEN EXISTS (SELECT 1 FROM subject_assignments WHERE class_id = ? AND teacher_id = ?) THEN 'subject_teacher'
                    WHEN EXISTS (SELECT 1 FROM class_teachers WHERE class_id = ? AND teacher_id = ?) THEN 'form_master'
                    ELSE 'no_access'
                END as access_type
            ");
            $stmt->execute([$class_id, $teacher_id, $class_id, $teacher_id]);
            if ($stmt->fetchColumn() === 'no_access') {
                $errors[] = 'You are not assigned to this class.';
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO students (school_id, class_id, full_name, admission_no, gender, dob, phone,
                                         guardian_name, guardian_phone, address, student_type, enrollment_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $current_school_id, $class_id, $full_name, $admission_no, $gender, $dob ?: null,
                    $phone, $guardian_name, $guardian_phone, $address, $student_type
                ]);

                $student_id = $pdo->lastInsertId();

                // Add a note about creation
                $pdo->prepare("INSERT INTO student_notes (student_id, teacher_id, note_text, created_at) VALUES (?, ?, ?, NOW())")
                    ->execute([$student_id, $teacher_id, "Student added to system."]);

                $pdo->commit();
                $success = 'Student added successfully.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Error adding student: ' . $e->getMessage();
            }
        }
    }
    // Edit student
    elseif ($action === 'edit_student') {
        $student_id = intval($_POST['student_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $admission_no = trim($_POST['admission_no'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $dob = $_POST['dob'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $guardian_name = trim($_POST['guardian_name'] ?? '');
        $guardian_phone = trim($_POST['guardian_phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $student_type = $_POST['student_type'] ?? 'fresh';

        // Validate
        if (empty($full_name)) $errors[] = 'Full name is required.';
        if (empty($admission_no)) $errors[] = 'Admission number is required.';
        if (empty($class_id)) $errors[] = 'Class is required.';

        // Check if admission number already exists (excluding current student)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ? AND id != ? AND school_id = ?");
        $stmt->execute([$admission_no, $student_id, $current_school_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Admission number already exists.';
        }

        // Check teacher access to class and student
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM students s
            WHERE s.id = ?
            AND (
                EXISTS (SELECT 1 FROM subject_assignments sa WHERE sa.class_id = s.class_id AND sa.teacher_id = ?)
                OR EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = s.class_id AND ct.teacher_id = ?)
            )
        ");
        $stmt->execute([$student_id, $teacher_id, $teacher_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'Access denied. You can only edit students in your assigned classes.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    UPDATE students
                    SET class_id = ?, full_name = ?, admission_no = ?, gender = ?, dob = ?,
                        phone = ?, guardian_name = ?, guardian_phone = ?, address = ?, student_type = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $class_id, $full_name, $admission_no, $gender, $dob ?: null,
                    $phone, $guardian_name, $guardian_phone, $address, $student_type, $student_id
                ]);

                // Add a note about update
                $pdo->prepare("INSERT INTO student_notes (student_id, teacher_id, note_text, created_at) VALUES (?, ?, ?, NOW())")
                    ->execute([$student_id, $teacher_id, "Student information updated."]);

                $pdo->commit();
                $success = 'Student updated successfully.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Error updating student: ' . $e->getMessage();
            }
        }
    }
    // Delete student
    elseif ($action === 'delete_student') {
        $student_id = intval($_POST['student_id'] ?? 0);

        if ($student_id <= 0) {
            $errors[] = 'Invalid student ID.';
        } else {
            // Check teacher access
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM students s
                WHERE s.id = ?
                AND (
                    EXISTS (SELECT 1 FROM subject_assignments sa WHERE sa.class_id = s.class_id AND sa.teacher_id = ?)
                    OR EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = s.class_id AND ct.teacher_id = ?)
                )
            ");
            $stmt->execute([$student_id, $teacher_id, $teacher_id]);

            if ($stmt->fetchColumn() == 0) {
                $errors[] = 'Access denied. You can only delete students in your assigned classes.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Delete related records first
                    $pdo->prepare("DELETE FROM student_notes WHERE student_id = ?")->execute([$student_id]);
                    $pdo->prepare("DELETE FROM attendance WHERE student_id = ?")->execute([$student_id]);

                    // Delete student
                    $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
                    $stmt->execute([$student_id]);

                    $pdo->commit();
                    $success = 'Student deleted successfully.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Error deleting student: ' . $e->getMessage();
                }
            }
        }
    }
    // Bulk upload students
    elseif ($action === 'bulk_upload') {
        $class_id = intval($_POST['class_id'] ?? 0);
        $upload_type = $_POST['upload_type'] ?? 'csv';

        if ($class_id <= 0) {
            $errors[] = 'Please select a class.';
        } else {
            // Check teacher access to class
            $stmt = $pdo->prepare("
                SELECT CASE
                    WHEN EXISTS (SELECT 1 FROM subject_assignments WHERE class_id = ? AND teacher_id = ?) THEN 'subject_teacher'
                    WHEN EXISTS (SELECT 1 FROM class_teachers WHERE class_id = ? AND teacher_id = ?) THEN 'form_master'
                    ELSE 'no_access'
                END as access_type
            ");
            $stmt->execute([$class_id, $teacher_id, $class_id, $teacher_id]);
            if ($stmt->fetchColumn() === 'no_access') {
                $errors[] = 'You are not assigned to this class.';
            }
        }

        if (empty($errors)) {
            // Handle CSV upload
            if ($upload_type === 'csv' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['csv_file']['tmp_name'];
                $handle = fopen($file, 'r');

                if ($handle !== false) {
                    $added = 0;
                    $skipped = 0;
                    $errors_list = [];

                    // Skip header row
                    fgetcsv($handle);

                    $pdo->beginTransaction();

                    while (($data = fgetcsv($handle)) !== false) {
                        if (count($data) >= 8) {
                            $full_name = trim($data[0]);
                            $admission_no = trim($data[1]);
                            $gender = trim($data[2]);
                            $dob = trim($data[3]);
                            $phone = trim($data[4]);
                            $guardian_name = trim($data[5]);
                            $guardian_phone = trim($data[6]);
                            $address = trim($data[7]);
                            $student_type = isset($data[8]) ? trim($data[8]) : 'fresh';

                            // Validate required fields
                            if (empty($full_name) || empty($admission_no)) {
                                $skipped++;
                                $errors_list[] = "Skipped: Missing name or admission no for row " . ($added + $skipped);
                                continue;
                            }

                            // Check if admission number exists
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ? AND school_id = ?");
                            $stmt->execute([$admission_no, $current_school_id]);
                            if ($stmt->fetchColumn() > 0) {
                                $skipped++;
                                $errors_list[] = "Skipped: Admission no '$admission_no' already exists";
                                continue;
                            }

                            try {
                                $stmt = $pdo->prepare("
                                    INSERT INTO students (school_id, class_id, full_name, admission_no, gender, dob, phone,
                                                         guardian_name, guardian_phone, address, student_type, enrollment_date)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $current_school_id, $class_id, $full_name, $admission_no, $gender,
                                    !empty($dob) ? date('Y-m-d', strtotime($dob)) : null,
                                    $phone, $guardian_name, $guardian_phone, $address, $student_type
                                ]);

                                $student_id = $pdo->lastInsertId();

                                // Add creation note
                                $pdo->prepare("INSERT INTO student_notes (student_id, teacher_id, note_text, created_at) VALUES (?, ?, ?, NOW())")
                                    ->execute([$student_id, $teacher_id, "Added via bulk upload."]);

                                $added++;
                            } catch (Exception $e) {
                                $skipped++;
                                $errors_list[] = "Error for '$full_name': " . $e->getMessage();
                            }
                        }
                    }

                    fclose($handle);
                    $pdo->commit();

                    $success = "Bulk upload completed: $added students added, $skipped skipped.";
                    if (!empty($errors_list)) {
                        $_SESSION['upload_errors'] = array_slice($errors_list, 0, 10); // Store only first 10 errors
                    }
                } else {
                    $errors[] = 'Failed to read CSV file.';
                }
            } else {
                $errors[] = 'Please upload a valid CSV file.';
            }
        }
    }
}

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] === 'students_pdf') {
    require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

    $export_class_id = intval($_GET['class_id'] ?? 0);
    $export_gender = strtolower(trim($_GET['gender'] ?? 'all'));
    $allowed_gender_filters = ['all', 'male', 'female'];

    if ($export_class_id <= 0) {
        die('Please select a class to export.');
    }

    if (!in_array($export_gender, $allowed_gender_filters, true)) {
        $export_gender = 'all';
    }

    // Get school info
    $stmt = $pdo->prepare("SELECT * FROM school_profile WHERE school_id = ? LIMIT 1");
    $stmt->execute([$current_school_id]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if teacher has access to this class and fetch class info
    $stmt_check = $pdo->prepare("
        SELECT c.id, c.class_name
        FROM classes c
        WHERE c.id = :class_id
          AND c.school_id = :school_id
          AND (
              EXISTS (
                  SELECT 1
                  FROM subject_assignments sa
                  WHERE sa.class_id = c.id AND sa.teacher_id = :teacher_id
              )
              OR EXISTS (
                  SELECT 1
                  FROM class_teachers ct
                  WHERE ct.class_id = c.id AND ct.teacher_id = :teacher_id2
              )
          )
        LIMIT 1
    ");
    $stmt_check->execute([
        'class_id' => $export_class_id,
        'school_id' => $current_school_id,
        'teacher_id' => $teacher_id,
        'teacher_id2' => $teacher_id,
    ]);
    $export_class = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$export_class) {
        die('Access denied. You are not assigned to this class.');
    }

    $gender_labels = [
        'all' => 'All Male and Female',
        'male' => 'Male',
        'female' => 'Female',
    ];
    $gender_label = $gender_labels[$export_gender];

    // Get students for selected class and gender filter
    $sql = "
        SELECT s.id, s.full_name, s.admission_no
        FROM students s
        WHERE s.school_id = ?
          AND s.class_id = ?
    ";
    $params = [$current_school_id, $export_class_id];

    if ($export_gender !== 'all') {
        $sql .= " AND LOWER(TRIM(COALESCE(s.gender, ''))) = ?";
        $params[] = $export_gender;
    }

    $sql .= " ORDER BY s.full_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $export_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator($school['school_name'] ?? 'School');
    $pdf->SetAuthor($school['school_name'] ?? 'School');
    $pdf->SetSubject('Student Registration List');
    $pdf->SetTitle(($export_class['class_name'] ?? 'Class') . ' Students List');

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(15, 15, 15);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Add a page
    $pdf->AddPage();

    // School Header
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 15, $school['school_name'] ?? 'Sahab Academy', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, $school['school_address'] ?? '', 0, 1, 'C');
    $school_phone = trim((string)($school['school_phone'] ?? ''));
    $school_email = trim((string)($school['school_email'] ?? ''));
    $contact_parts = [];
    if ($school_phone !== '') {
        $contact_parts[] = 'Phone: ' . $school_phone;
    }
    if ($school_email !== '') {
        $contact_parts[] = 'Email: ' . $school_email;
    }
    if ($contact_parts) {
        $pdf->Cell(0, 8, implode(' | ', $contact_parts), 0, 1, 'C');
    }

    // Line separator
    $pdf->Ln(5);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(10);

    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 12, 'STUDENTS REGISTRATION LIST', 0, 1, 'C');
    $pdf->Ln(3);

    // Class info
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Class: ' . $export_class['class_name'], 0, 1, 'L');
    $pdf->Cell(0, 8, 'Gender: ' . $gender_label, 0, 1, 'L');
    $pdf->Cell(0, 8, 'Total Students: ' . count($export_students), 0, 1, 'L');
    $pdf->Ln(8);

    // Table Header
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(20, 10, 'S/N', 1, 0, 'C', true);
    $pdf->Cell(55, 10, 'Admission Number', 1, 0, 'C', true);
    $pdf->Cell(100, 10, 'Name', 1, 1, 'C', true);

    // Table Data
    $pdf->SetFont('helvetica', '', 11);
    $serial = 1;
    foreach ($export_students as $student) {
        $pdf->Cell(20, 8, $serial++, 1, 0, 'C');
        $pdf->Cell(55, 8, $student['admission_no'], 1, 0, 'C');
        $pdf->Cell(100, 8, $student['full_name'], 1, 1, 'L');
    }

    // Summary
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Generated Students: ' . count($export_students), 0, 1, 'L');

    // Footer
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 5, 'Generated by SahabFormMaster on ' . date('d/m/Y H:i'), 0, 1, 'C');
    $pdf->Cell(0, 5, 'This document contains student registration information', 0, 1, 'C');

    // Output PDF
    $filename = 'students_list_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $export_class['class_name']) . '_' . $export_gender . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// Handle Exams Record PDF generation
if (isset($_GET['generate_exams_record']) && $_GET['generate_exams_record'] == '1') {
    require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

    // Get parameters
    $record_class_id = intval($_GET['class_id'] ?? 0);
    $record_term = trim($_GET['term'] ?? '');
    $record_session = trim($_GET['session'] ?? '');
    $record_subject = trim($_GET['subject'] ?? '');

    // Validate inputs
    if (!$record_class_id || !$record_term || !$record_session || !$record_subject) {
        die("Missing required parameters.");
    }

    // Check teacher access to class
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM subject_assignments sa
        WHERE sa.class_id = :class_id AND sa.teacher_id = :teacher_id
    ");
    $stmt->execute(['class_id' => $record_class_id, 'teacher_id' => $teacher_id]);
    if ($stmt->fetchColumn() == 0) {
        die("Access denied. You are not assigned to this class.");
    }

    // Get school details
    $stmt = $pdo->prepare("SELECT * FROM school_profile WHERE school_id = ? LIMIT 1");
    $stmt->execute([$current_school_id]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get class details
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :id AND school_id = :school_id");
    $stmt->execute(['id' => $record_class_id, 'school_id' => $current_school_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        die("Class not found.");
    }

    // Get students in class
    $stmt = $pdo->prepare("SELECT id, full_name, admission_no FROM students WHERE class_id = :class_id AND school_id = :school_id ORDER BY full_name ASC");
    $stmt->execute(['class_id' => $record_class_id, 'school_id' => $current_school_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Create PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator($school['school_name'] ?? 'School');
    $pdf->SetAuthor($school['school_name'] ?? 'School');
    $pdf->SetSubject('Exams Record List');

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(15, 15, 15);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Add a page
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', '', 12);

    // School Header
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 15, $school['school_name'] ?? 'Sahab Academy', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, $school['school_address'] ?? '', 0, 1, 'C');
    $pdf->Cell(0, 8, 'Phone: ' . ($school['school_phone'] ?? '') . ' | Email: ' . ($school['school_email'] ?? ''), 0, 1, 'C');

    // Line separator
    $pdf->Ln(5);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(10);

    // Title and details
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 12, 'EXAMS RECORD LIST', 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Class: ' . htmlspecialchars($class['class_name']), 0, 1, 'L');
    $pdf->Cell(0, 8, 'Term: ' . htmlspecialchars($record_term), 0, 1, 'L');
    $pdf->Cell(0, 8, 'Session: ' . htmlspecialchars($record_session), 0, 1, 'L');
    $pdf->Cell(0, 8, 'Subject: ' . htmlspecialchars($record_subject), 0, 1, 'L');

    $pdf->Ln(10);

    // Table Header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);

    $pdf->Cell(15, 12, 'S/N', 1, 0, 'C', true);
    $pdf->Cell(30, 12, 'Admission No', 1, 0, 'C', true);
    $pdf->Cell(60, 12, 'Full Name', 1, 0, 'C', true);
    $pdf->Cell(20, 12, 'First C.A', 1, 0, 'C', true);
    $pdf->Cell(20, 12, 'Second C.A', 1, 0, 'C', true);
    $pdf->Cell(20, 12, 'Exams', 1, 0, 'C', true);
    $pdf->Cell(20, 12, 'Total', 1, 1, 'C', true);

    // Table Data
    $pdf->SetFont('helvetica', '', 10);
    $serial = 1;
    foreach ($students as $student) {
        $pdf->Cell(15, 10, $serial++, 1, 0, 'C');
        $pdf->Cell(30, 10, $student['admission_no'], 1, 0, 'C');
        $pdf->Cell(60, 10, $student['full_name'], 1, 0, 'L');
        $pdf->Cell(20, 10, '', 1, 0, 'C'); // Empty First C.A
        $pdf->Cell(20, 10, '', 1, 0, 'C'); // Empty Second C.A
        $pdf->Cell(20, 10, '', 1, 0, 'C'); // Empty Exams
        $pdf->Cell(20, 10, '', 1, 1, 'C'); // Empty Total
    }

    // Summary
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Total Students: ' . count($students), 0, 1, 'L');

    // Footer
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 5, 'Generated by SahabFormMaster on ' . date('d/m/Y H:i'), 0, 1, 'C');
    $pdf->Cell(0, 5, 'This is an empty exams record template for manual scoring', 0, 1, 'C');

    // Output PDF
    $filename = 'exams_record_' . $class['class_name'] . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// Get search parameters
$class_id = intval($_GET['class_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$view_all = isset($_GET['view_all']) ? (bool)$_GET['view_all'] : false;
$current_page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$total_visible_students = 0;
$total_pages = 1;
$pagination_start = 0;
$pagination_end = 0;
$students = [];
$class_details = [];

// Determine if we should show all classes or just assigned
$show_all_classes = $view_all || (empty($assigned_classes) && !$class_id);

// If no class selected, default to first assigned class if available
if (!$class_id && !empty($assigned_classes) && !$show_all_classes) {
    $class_id = $assigned_classes[0]['id'];
}

if ($class_id > 0) {
    // Get class details
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :cid");
    $stmt->execute(['cid' => $class_id]);
    $class_details = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($class_details) {
        // Check if teacher has access to this class (if not viewing all)
        if (!$show_all_classes) {
            $stmt = $pdo->prepare("
                SELECT
                    CASE
                        WHEN EXISTS (SELECT 1 FROM subject_assignments sa WHERE sa.class_id = :cid AND sa.teacher_id = :tid) THEN 'subject_teacher'
                        WHEN EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = :cid2 AND ct.teacher_id = :tid2) THEN 'form_master'
                        ELSE 'no_access'
                    END as access_type
            ");
            $stmt->execute(['cid'=>$class_id,'tid'=>$teacher_id,'cid2'=>$class_id,'tid2'=>$teacher_id]);
            $access_type = $stmt->fetchColumn();

            if ($access_type === 'no_access') {
                $errors[] = 'You are not assigned to this class. Switch to "View All Classes" to search across all classes.';
                $class_id = 0;
            }
        }

        if ($class_id > 0) {
            $base_sql = "FROM students s
                        JOIN classes c ON s.class_id = c.id
                        WHERE s.school_id = :school_id
                        AND c.school_id = :school_id_class
                        AND s.class_id = :cid
                        AND s.class_id IN (
                            SELECT DISTINCT c2.id
                            FROM classes c2
                            WHERE c2.school_id = :school_id_filter
                            AND (
                                EXISTS (
                                    SELECT 1 FROM subject_assignments sa
                                    WHERE sa.class_id = c2.id AND sa.teacher_id = :tid
                                )
                                OR EXISTS (
                                    SELECT 1 FROM class_teachers ct
                                    WHERE ct.class_id = c2.id AND ct.teacher_id = :tid2
                                )
                            )
                        )";
            $params = [
                'school_id' => $current_school_id,
                'school_id_class' => $current_school_id,
                'cid' => $class_id,
                'school_id_filter' => $current_school_id,
                'tid' => $teacher_id,
                'tid2' => $teacher_id
            ];

            if (!empty($search)) {
                $base_sql .= " AND (s.full_name LIKE :search
                            OR s.admission_no LIKE :search
                            OR s.guardian_name LIKE :search
                            OR s.guardian_phone LIKE :search
                            OR s.phone LIKE :search)";
                $params['search'] = "%$search%";
            }

            $count_stmt = $pdo->prepare("SELECT COUNT(*) " . $base_sql);
            $count_stmt->execute($params);
            $total_visible_students = (int) $count_stmt->fetchColumn();
            $total_pages = max(1, (int) ceil($total_visible_students / $per_page));
            $current_page = min($current_page, $total_pages);
            $offset = ($current_page - 1) * $per_page;

            $sql = "SELECT s.id, s.full_name, s.admission_no, s.gender, s.phone,
                           s.guardian_name, s.guardian_phone, s.dob, s.enrollment_date,
                           s.address, s.student_type, c.class_name " . $base_sql . "
                    ORDER BY s.full_name
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $pagination_start = $total_visible_students > 0 ? $offset + 1 : 0;
            $pagination_end = min($offset + $per_page, $total_visible_students);
        }
    } else {
        $errors[] = 'Class not found.';
        $class_id = 0;
    }
} elseif ($show_all_classes && !empty($search)) {
    // Search across all classes
    $base_sql = "FROM students s
                JOIN classes c ON s.class_id = c.id
                WHERE s.school_id = :school_id
                AND c.school_id = :school_id_class
                AND (
                    s.full_name LIKE :search
                    OR s.admission_no LIKE :search
                    OR s.guardian_name LIKE :search
                    OR s.guardian_phone LIKE :search
                    OR s.phone LIKE :search
                )
                AND s.class_id IN (
                    SELECT DISTINCT c2.id
                    FROM classes c2
                    WHERE c2.school_id = :school_id_filter
                    AND (
                        EXISTS (
                            SELECT 1 FROM subject_assignments sa
                            WHERE sa.class_id = c2.id AND sa.teacher_id = :tid
                        )
                        OR EXISTS (
                            SELECT 1 FROM class_teachers ct
                            WHERE ct.class_id = c2.id AND ct.teacher_id = :tid2
                        )
                    )
                )";
    $params = [
        'school_id' => $current_school_id,
        'school_id_class' => $current_school_id,
        'school_id_filter' => $current_school_id,
        'search' => "%$search%",
        'tid' => $teacher_id,
        'tid2' => $teacher_id
    ];

    $count_stmt = $pdo->prepare("SELECT COUNT(*) " . $base_sql);
    $count_stmt->execute($params);
    $total_visible_students = (int) $count_stmt->fetchColumn();
    $total_pages = max(1, (int) ceil($total_visible_students / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset = ($current_page - 1) * $per_page;

    $sql = "SELECT s.id, s.full_name, s.admission_no, s.gender, s.phone,
                   s.guardian_name, s.guardian_phone, s.dob, s.enrollment_date,
                   s.address, s.student_type, c.class_name, c.id as class_id " . $base_sql . "
            ORDER BY c.class_name, s.full_name
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagination_start = $total_visible_students > 0 ? $offset + 1 : 0;
    $pagination_end = min($offset + $per_page, $total_visible_students);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Students | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <meta name="theme-color" content="#0f172a">
    <style>
        body {
            background: #f8fafc;
        }

        .main-container {
            display: grid;
            gap: 1.5rem;
        }

        .content-header,
        .panel,
        .quick-actions-section,
        .activity-section,
        .alert {
            border-radius: 1.5rem;
            border: 1px solid rgba(15, 31, 45, 0.06);
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(15, 31, 51, 0.08);
        }

        .content-header {
            padding: 1.75rem;
            background: linear-gradient(135deg, #0f6a5c 0%, #059669 45%, #0284c7 100%);
            color: #fff;
        }

        .content-header,
        .content-header h1,
        .content-header h2,
        .content-header h3,
        .content-header p,
        .content-header span,
        .content-header i {
            color: #fff;
        }

        .content-header h2,
        .panel-header h2,
        .section-header h3 {
            font-family: "Fraunces", Georgia, serif;
        }

        .content-header p,
        .content-header .quick-stat-label {
            color: rgba(255, 255, 255, 0.82);
        }

        [data-sidebar] {
            overflow: hidden;
        }

        .sidebar-scroll-shell {
            height: 100%;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-y: contain;
            touch-action: pan-y;
            padding-bottom: max(1rem, env(safe-area-inset-bottom));
        }

        .header-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .quick-stat {
            min-width: 120px;
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.12);
            padding: 0.9rem 1rem;
            backdrop-filter: blur(8px);
        }

        .quick-stat-value {
            display: block;
            font-size: 1.75rem;
            font-weight: 700;
            color: #fff;
        }

        .panel-header,
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(15, 31, 45, 0.06);
        }

        .panel-header {
            background: #fff;
        }

        .panel-header h2,
        .section-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: #0f1f2d;
        }

        .panel-body,
        .quick-actions-section,
        .activity-section {
            padding: 1.5rem;
        }

        .btn,
        .btn-small,
        .btn-table,
        .btn-toggle-form {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border-radius: 999px;
            border: 1px solid transparent;
            padding: 0.65rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .btn:hover,
        .btn-small:hover,
        .btn-table:hover,
        .btn-toggle-form:hover {
            transform: translateY(-1px);
        }

        .btn,
        .btn-primary,
        .quick-action-card {
            background: #168575;
            border-color: #168575;
            color: #fff;
        }

        .btn-success {
            background: #0f766e;
            border-color: #0f766e;
            color: #fff;
        }

        .btn-info {
            background: #0284c7;
            border-color: #0284c7;
            color: #fff;
        }

        .btn-danger {
            background: #dc2626;
            border-color: #dc2626;
            color: #fff;
        }

        .btn-warning {
            background: #d97706;
            border-color: #d97706;
            color: #fff;
        }

        .btn-secondary,
        .btn-toggle-form {
            background: #fff;
            border-color: rgba(15, 31, 45, 0.12);
            color: #475569;
        }

        .btn-table {
            width: 2.4rem;
            height: 2.4rem;
            padding: 0;
            border-radius: 0.8rem;
        }

        .inline-form {
            display: inline-flex;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #0f1f2d;
        }

        .form-control {
            width: 100%;
            border-radius: 0.95rem;
            border: 1px solid rgba(15, 31, 45, 0.12);
            background: #fff;
            padding: 0.85rem 1rem;
            color: #0f1f2d;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.05);
        }

        .form-control:focus {
            outline: none;
            border-color: #168575;
            box-shadow: 0 0 0 4px rgba(22, 133, 117, 0.12);
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.9rem;
        }

        .quick-action-card {
            min-height: 108px;
            border-radius: 1.25rem;
            padding: 1.2rem;
            text-align: left;
            box-shadow: 0 12px 24px rgba(22, 133, 117, 0.18);
        }

        .quick-action-card i {
            display: inline-flex;
            margin-bottom: 0.75rem;
            font-size: 1.25rem;
        }

        .quick-action-card span {
            display: block;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .students-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 880px;
        }

        .students-table thead th {
            position: sticky;
            top: 0;
            background: #f8fafc;
            color: #475569;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid rgba(15, 31, 45, 0.08);
            padding: 1rem;
            text-align: left;
        }

        .students-table tbody td {
            padding: 1rem;
            border-bottom: 1px solid rgba(15, 31, 45, 0.06);
            vertical-align: middle;
            color: #334155;
        }

        .students-table tbody tr:hover {
            background: rgba(22, 133, 117, 0.03);
        }

        .student-name-cell {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
            color: #0f1f2d;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            padding: 0.3rem 0.65rem;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.12);
            color: #047857;
        }

        .badge-primary {
            background: rgba(59, 130, 246, 0.12);
            color: #1d4ed8;
        }

        .badge-danger {
            background: rgba(244, 63, 94, 0.12);
            color: #be123c;
        }

        .table-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }

        .table-responsive {
            overflow-x: auto;
            padding: 0 1.25rem 1.25rem;
        }

        .table-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0 1.25rem 1.25rem;
        }

        .table-summary {
            font-size: 0.92rem;
            color: #64748b;
        }

        .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            justify-content: flex-end;
        }

        .pagination-link,
        .pagination-current,
        .pagination-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            height: 2.5rem;
            padding: 0 0.8rem;
            border-radius: 999px;
            border: 1px solid rgba(15, 31, 45, 0.1);
            background: #fff;
            color: #334155;
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
        }

        .pagination-link:hover {
            border-color: rgba(22, 133, 117, 0.35);
            color: #0f6a5c;
            background: rgba(22, 133, 117, 0.06);
        }

        .pagination-current {
            border-color: #168575;
            background: #168575;
            color: #fff;
            box-shadow: 0 12px 24px rgba(22, 133, 117, 0.18);
        }

        .pagination-ellipsis {
            border-style: dashed;
            color: #94a3b8;
        }

        .alert {
            display: grid;
            gap: 0.35rem;
            padding: 1rem 1.25rem;
            color: #334155;
        }

        .alert-success {
            border-color: rgba(16, 185, 129, 0.2);
            background: #ecfdf5;
        }

        .alert-error {
            border-color: rgba(244, 63, 94, 0.2);
            background: #fff1f2;
        }

        .activity-list {
            display: grid;
            gap: 1rem;
        }

        .activity-item {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            border: 1px solid rgba(15, 31, 45, 0.06);
            border-radius: 1.25rem;
            padding: 1rem 1.15rem;
            background: #fff;
        }

        .activity-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            border-radius: 1rem;
            background: rgba(22, 133, 117, 0.12);
            color: #168575;
        }

        .activity-content {
            flex: 1 1 220px;
            min-width: 200px;
        }

        .activity-text {
            display: block;
            color: #0f1f2d;
        }

        .activity-date {
            display: block;
            margin-top: 0.2rem;
            font-size: 0.85rem;
            color: #64748b;
        }

        .view-all-link {
            font-size: 0.9rem;
            font-weight: 700;
            color: #168575;
        }

        .modal {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.55);
        }

        .modal-content {
            width: min(100%, 760px);
            max-height: min(90vh, 920px);
            overflow: auto;
            border-radius: 1.5rem;
            background: #fff;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        }

        .modal-header,
        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(15, 31, 45, 0.06);
        }

        .modal-footer {
            border-top: 1px solid rgba(15, 31, 45, 0.06);
            border-bottom: 0;
            justify-content: flex-end;
        }

        .modal-header h2 {
            margin: 0;
            font-family: "Fraunces", Georgia, serif;
            color: #0f1f2d;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .close-btn {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(15, 31, 45, 0.08);
            background: #fff;
            color: #475569;
            font-size: 1.2rem;
        }

        #uploadArea {
            border: 2px dashed rgba(15, 31, 45, 0.15) !important;
            border-radius: 1.25rem !important;
            background: #f8fafc;
        }

        @media (max-width: 900px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .content-header,
            .panel-body,
            .quick-actions-section,
            .activity-section,
            .table-responsive,
            .modal-body,
            .modal-header,
            .modal-footer {
                padding: 1rem;
            }

            .form-actions {
                flex-direction: column-reverse;
            }

            .btn,
            .btn-small,
            .btn-toggle-form {
                width: 100%;
            }

            .panel-header,
            .section-header,
            .activity-item {
                align-items: stretch;
            }
        }

        @media (max-width: 860px) {
            .students-table {
                min-width: 0;
                border-spacing: 0;
            }

            .students-table thead {
                display: none;
            }

            .students-table,
            .students-table tbody,
            .students-table tr,
            .students-table td {
                display: block;
                width: 100%;
            }

            .students-table tbody {
                display: grid;
                gap: 1rem;
            }

            .students-table tbody tr {
                border: 1px solid rgba(15, 31, 45, 0.08);
                border-radius: 1.2rem;
                background: #fff;
                padding: 0.95rem 1rem;
                box-shadow: 0 12px 24px rgba(15, 31, 45, 0.06);
            }

            .students-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 0.85rem;
                padding: 0.7rem 0;
                border-bottom: 1px solid rgba(15, 31, 45, 0.06);
                text-align: right;
            }

            .students-table tbody td:last-child {
                border-bottom: 0;
                padding-bottom: 0;
            }

            .students-table tbody td::before {
                content: attr(data-label);
                flex: 0 0 105px;
                max-width: 105px;
                text-align: left;
                color: #64748b;
                font-size: 0.78rem;
                font-weight: 800;
                letter-spacing: 0.06em;
                text-transform: uppercase;
            }

            .students-table tbody td.actions-cell {
                display: block;
                text-align: left;
            }

            .students-table tbody td.actions-cell::before {
                display: block;
                max-width: none;
                margin-bottom: 0.7rem;
            }

            .student-name-cell {
                justify-content: flex-end;
                text-align: right;
            }

            .table-actions {
                width: 100%;
            }

            .table-actions .btn-table,
            .table-actions .inline-form {
                flex: 1 1 calc(50% - 0.3rem);
            }

            .table-actions .inline-form button {
                width: 100%;
            }

            .table-footer {
                padding-top: 0.25rem;
            }

            .table-summary,
            .pagination {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body class="landing bg-slate-50">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="h-10 w-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                <a class="btn btn-outline" href="../index.php">Home</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto">
                <?php include '../includes/teacher_sidebar.php'; ?>
            </div>
        </aside>

        <main class="space-y-6">
        <div class="main-container">
            <!-- Main Content -->
            <!-- Content Header -->
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Student Management</h2>
                    <p>Manage class rosters, student records, guardian contacts, and classroom follow-up from one professional workspace.</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $student_count; ?></span>
                        <span class="quick-stat-label">Total Students</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo count($assigned_classes); ?></span>
                        <span class="quick-stat-label">Assigned Classes</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo ($class_id || ($show_all_classes && $search)) ? $total_visible_students : count($students); ?></span>
                        <span class="quick-stat-label"><?php echo $class_id || ($show_all_classes && $search) ? 'Visible Records' : 'Ready Views'; ?></span>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <p><?php echo htmlspecialchars($success); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['add_student_success'])): ?>
                <div class="alert alert-success">
                    <p><?php echo htmlspecialchars($_SESSION['add_student_success']); ?></p>
                    <?php unset($_SESSION['add_student_success']); ?>
                </div>
            <?php endif; ?>

            <!-- Add Student Form -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Add New Student</h2>
                    <button type="button" class="btn-toggle-form" id="toggleAddStudentForm" aria-expanded="true" aria-controls="addStudentFormBody">
                        <i class="fas fa-eye-slash"></i>
                        <span>Hide Form</span>
                    </button>
                </div>
                <div class="panel-body" id="addStudentFormBody">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_student">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="class_id_add">Class *</label>
                                <select id="class_id_add" name="class_id" class="form-control" required>
                                    <option value="">-- Select Class --</option>
                                    <?php foreach($assigned_classes as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="full_name_add">Full Name *</label>
                                <input type="text" id="full_name_add" name="full_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="admission_no_add">Admission Number *</label>
                                <input type="text" id="admission_no_add" name="admission_no" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="gender_add">Gender</label>
                                <select id="gender_add" name="gender" class="form-control">
                                    <option value="">-- Select --</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="dob_add">Date of Birth</label>
                                <input type="date" id="dob_add" name="dob" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="phone_add">Phone Number</label>
                                <input type="tel" id="phone_add" name="phone" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="guardian_name_add">Guardian Name</label>
                                <input type="text" id="guardian_name_add" name="guardian_name" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="guardian_phone_add">Guardian Phone</label>
                                <input type="tel" id="guardian_phone_add" name="guardian_phone" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="address_add">Address</label>
                                <textarea id="address_add" name="address" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="student_type_add">Student Type</label>
                                <select id="student_type_add" name="student_type" class="form-control">
                                    <option value="fresh" selected>Fresh Student</option>
                                    <option value="transfer">Transfer Student</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Add Student
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions-section">
                <div class="section-header">
                    <h3>Quick Actions</h3>
                </div>
                <div class="quick-actions-grid">
                    <a href="add_student.php" class="quick-action-card">
                        <i class="fas fa-user-plus"></i>
                        <span>Open add student page</span>
                    </a>
                    <button type="button" class="quick-action-card" onclick="toggleAddStudentForm()">
                        <i class="fas fa-pen-to-square"></i>
                        <span>Quick add form</span>
                    </button>
                    <button onclick="openModal('bulkUploadModal')" class="quick-action-card">
                        <i class="fas fa-file-upload"></i>
                        <span>Bulk upload roster</span>
                    </button>
                    <button onclick="openModal('examsRecordModal')" class="quick-action-card">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Exams record list</span>
                    </button>
                    <button type="button" onclick="openModal('studentExportModal')" class="quick-action-card">
                        <i class="fas fa-download"></i>
                        <span>Export student PDF</span>
                    </button>
                </div>
            </div>

            <!-- Class Selection -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Class Selection</h2>
                </div>
                <div class="panel-body">
                    <form method="GET" class="form-grid" style="align-items: end;">
                        <div class="form-group">
                            <label for="class_id">Select Class</label>
                            <select name="class_id" id="class_id" onchange="this.form.submit()" class="form-control">
                                <option value="">-- All Classes --</option>
                                <?php foreach($assigned_classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $class_id == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="search">Search Students</label>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, admission number, or guardian..."
                                   class="form-control">
                        </div>
                        <div class="form-actions" style="margin-top: 0;">
                            <button type="submit" class="btn">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="?view_all=1" class="btn btn-secondary">
                                <i class="fas fa-list"></i> Search all students
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Student Management Section -->
            <?php if($class_id || ($show_all_classes && $search)): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h2><?php echo $class_details ? htmlspecialchars($class_details['class_name']) : 'Search Results'; ?> Students (<?php echo $total_visible_students; ?>)</h2>
                    </div>

                    <?php if($students): ?>
                        <!-- Students Table -->
                        <div class="table-responsive">
                            <table class="students-table">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-user"></i> Name</th>
                                        <th><i class="fas fa-id-card"></i> Admission No</th>
                                        <?php if($show_all_classes): ?>
                                            <th><i class="fas fa-school"></i> Class</th>
                                        <?php endif; ?>
                                        <th><i class="fas fa-venus-mars"></i> Gender</th>
                                        <th><i class="fas fa-phone"></i> Phone</th>
                                        <th><i class="fas fa-user-friends"></i> Guardian</th>
                                        <th><i class="fas fa-calendar"></i> Enrolled</th>
                                        <th><i class="fas fa-cogs"></i> Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($students as $index => $s): ?>
                                        <tr>
                                            <td data-label="Name">
                                                <div class="student-name-cell">
                                                    <?php echo htmlspecialchars($s['full_name']); ?>
                                                    <?php if($s['student_type'] === 'fresh'): ?>
                                                        <span class="badge badge-success badge-small">New</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td data-label="Admission No"><?php echo htmlspecialchars($s['admission_no']); ?></td>
                                            <?php if($show_all_classes): ?>
                                                <td data-label="Class"><?php echo htmlspecialchars($s['class_name']); ?></td>
                                            <?php endif; ?>
                                            <td data-label="Gender">
                                                <span class="badge <?php echo strtolower($s['gender']) === 'male' ? 'badge-primary' : 'badge-danger'; ?> badge-small">
                                                    <i class="fas fa-<?php echo strtolower($s['gender']) === 'male' ? 'mars' : 'venus'; ?>"></i>
                                                    <?php echo htmlspecialchars($s['gender']); ?>
                                                </span>
                                            </td>
                                            <td data-label="Phone"><?php echo htmlspecialchars($s['phone'] ?? 'N/A'); ?></td>
                                            <td data-label="Guardian"><?php echo htmlspecialchars($s['guardian_name'] ?? 'N/A'); ?></td>
                                            <td data-label="Enrolled"><?php echo $s['enrollment_date'] ? date('M d, Y', strtotime($s['enrollment_date'])) : 'N/A'; ?></td>
                                            <td data-label="Actions" class="actions-cell">
                                                <div class="table-actions">
                                                    <a href="student_details.php?id=<?php echo $s['id']; ?>" class="btn btn-primary btn-table" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="edit_student.php?id=<?php echo $s['id']; ?><?php echo isset($_GET['class_id']) ? '&class_id=' . intval($_GET['class_id']) : ''; ?>" class="btn btn-success btn-table" title="Edit Student">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <button class="btn btn-info btn-table" title="Quick Actions"
                                                            onclick="showQuickActions(<?php echo $s['id']; ?>, '<?php echo addslashes($s['full_name']); ?>')">
                                                        <i class="fas fa-bolt"></i>
                                                    </button>
                                                    <form method="POST" class="inline-form" style="display: inline;"
                                                          onsubmit="return confirm('Delete <?php echo addslashes($s['full_name']); ?>?')">
                                                        <input type="hidden" name="action" value="delete_student">
                                                        <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-table" title="Delete Student">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <div class="table-footer">
                                <p class="table-summary">
                                    Showing <?php echo $pagination_start; ?>-<?php echo $pagination_end; ?> of <?php echo $total_visible_students; ?> students
                                </p>
                                <div class="pagination">
                                    <?php
                                        $pagination_params = $_GET;
                                        $build_page_url = function ($page_number) use ($pagination_params) {
                                            $pagination_params['page'] = $page_number;
                                            return '?' . http_build_query($pagination_params);
                                        };
                                    ?>
                                    <?php if ($current_page > 1): ?>
                                        <a href="<?php echo htmlspecialchars($build_page_url($current_page - 1)); ?>" class="pagination-link" aria-label="Previous page">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php
                                        $window_start = max(1, $current_page - 2);
                                        $window_end = min($total_pages, $current_page + 2);
                                        if ($window_start > 1):
                                    ?>
                                        <a href="<?php echo htmlspecialchars($build_page_url(1)); ?>" class="pagination-link">1</a>
                                        <?php if ($window_start > 2): ?>
                                            <span class="pagination-ellipsis">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($page_number = $window_start; $page_number <= $window_end; $page_number++): ?>
                                        <?php if ($page_number === $current_page): ?>
                                            <span class="pagination-current"><?php echo $page_number; ?></span>
                                        <?php else: ?>
                                            <a href="<?php echo htmlspecialchars($build_page_url($page_number)); ?>" class="pagination-link"><?php echo $page_number; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php if ($window_end < $total_pages): ?>
                                        <?php if ($window_end < $total_pages - 1): ?>
                                            <span class="pagination-ellipsis">...</span>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars($build_page_url($total_pages)); ?>" class="pagination-link"><?php echo $total_pages; ?></a>
                                    <?php endif; ?>

                                    <?php if ($current_page < $total_pages): ?>
                                        <a href="<?php echo htmlspecialchars($build_page_url($current_page + 1)); ?>" class="pagination-link" aria-label="Next page">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem; color: #64748b;">
                            <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.45;"></i>
                            <h3 style="margin-bottom: 0.5rem; color: #0f1f2d; font-family: 'Fraunces', Georgia, serif;">No students found</h3>
                            <p>
                                <?php if(!empty($search)): ?>
                                    No students match your search criteria.
                                <?php else: ?>
                                    No students found in this class.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Class Overview -->
                <div class="activity-section">
                    <div class="section-header">
                        <h3>My Classes</h3>
                        <a href="?view_all=1" class="view-all-link">Search All Students</a>
                    </div>
                    <div class="activity-list">
                        <?php if(!empty($assigned_classes)): ?>
                            <?php foreach($assigned_classes as $c): ?>
                                <div class="activity-item">
                                    <div class="activity-icon activity-icon-primary">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                    <div class="activity-content">
                                        <span class="activity-text"><strong><?php echo htmlspecialchars($c['class_name']); ?></strong></span>
                                        <span class="activity-date"><?php echo $c['assignment_type'] === 'form_master' ? 'Form Master' : 'Subject Teacher'; ?></span>
                                    </div>
                                    <a href="?class_id=<?php echo $c['id']; ?>" class="btn btn-primary btn-small">
                                        <i class="fas fa-eye"></i> View Students
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 3rem; color: #64748b;">
                                <i class="fas fa-graduation-cap" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.45;"></i>
                                <h3 style="margin-bottom: 0.5rem; color: #0f1f2d; font-family: 'Fraunces', Georgia, serif;">No Classes Assigned</h3>
                                <p>You haven't been assigned to any classes yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        </main>
    </div>

    

    <!-- Bulk Upload Modal -->
    <div id="bulkUploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Bulk Upload Students</h2>
                <button class="close-btn" onclick="closeModal('bulkUploadModal')">×</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_upload">
                    <input type="hidden" name="upload_type" value="csv">

                    <div class="form-group">
                        <label for="upload_class_id">Select Class *</label>
                        <select id="upload_class_id" name="class_id" class="form-control" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach($assigned_classes as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo htmlspecialchars($c['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="uploadArea" style="border: 2px dashed #ddd; border-radius: 8px; padding: 2rem; text-align: center; margin: 1rem 0; cursor: pointer; transition: all 0.3s ease;"
                         onclick="document.getElementById('csvFile').click()"
                         ondragover="handleDragOver(event)"
                         ondragleave="handleDragLeave(event)"
                         ondrop="handleDrop(event)">
                        <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: #666; margin-bottom: 1rem;"></i>
                        <h3 style="margin-bottom: 0.5rem;">Upload CSV File</h3>
                        <p style="color: #666; margin-bottom: 1.5rem;">Click to select or drag & drop your CSV file here</p>
                        <input type="file" id="csvFile" name="csv_file" accept=".csv" style="display: none;" onchange="handleFileSelect(this)">
                        <div id="fileName" style="font-weight: 500;"></div>
                        <div id="fileError" style="color: #dc3545; font-size: 0.9rem; margin-top: 0.5rem; display: none;"></div>
                    </div>

                    <div style="margin-top: 2rem;">
                        <h4>CSV Format</h4>
                        <p>Your CSV file should have the following columns (in order):</p>
                        <div style="background: #f5f5f5; padding: 1rem; border-radius: 6px; font-family: monospace; font-size: 0.9rem;">
                            full_name, admission_no, gender, dob, phone, guardian_name, guardian_phone, address, student_type
                        </div>
                        <p style="margin-top: 0.5rem; color: #666; font-size: 0.9rem;">
                            <strong>Note:</strong> First row should be headers. Date format: YYYY-MM-DD
                        </p>

                        <a href="#" onclick="downloadTemplate()" style="display: inline-flex; align-items: center; gap: 0.5rem; margin-top: 1rem;">
                            <i class="fas fa-download"></i> Download CSV Template
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('bulkUploadModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Upload & Process</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Actions Modal -->
    <div id="quickActionsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Quick Actions</h2>
                <button class="close-btn" onclick="closeModal('quickActionsModal')">×</button>
            </div>
            <div class="modal-body">
                <div id="quickActionsContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Student Export Modal -->
    <div id="studentExportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Export Student PDF</h2>
                <button class="close-btn" onclick="closeModal('studentExportModal')">Ã—</button>
            </div>
            <form method="GET" action="">
                <input type="hidden" name="export" value="students_pdf">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="export_class_id">Class *</label>
                            <select id="export_class_id" name="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach($assigned_classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $class_id == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="export_gender">Student Gender *</label>
                            <select id="export_gender" name="gender" class="form-control" required>
                                <option value="all">All Male and Female</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('studentExportModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate PDF</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Exams Record Modal -->
    <div id="examsRecordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Generate Exams Record List</h2>
                <button class="close-btn" onclick="closeModal('examsRecordModal')">×</button>
            </div>
            <form method="GET" action="">
                <input type="hidden" name="generate_exams_record" value="1">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="record_class_id">Class *</label>
                            <select id="record_class_id" name="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach($assigned_classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>">
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="record_term">Term *</label>
                            <select id="record_term" name="term" class="form-control" required>
                                <option value="">-- Select Term --</option>
                                <option value="1st Term">1st Term</option>
                                <option value="2nd Term">2nd Term</option>
                                <option value="3rd Term">3rd Term</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="record_session">Academic Session *</label>
                            <input type="text" id="record_session" name="session" class="form-control"
                                   placeholder="e.g., 2024/2025" required>
                        </div>

                        <div class="form-group">
                            <label for="record_subject">Subject Name *</label>
                            <input type="text" id="record_subject" name="subject" class="form-control"
                                   placeholder="Enter subject name" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('examsRecordModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate PDF</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        const sidebar = document.querySelector('[data-sidebar]');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        const body = document.body;

        const openSidebar = () => {
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('opacity-0', 'pointer-events-none');
            overlay.classList.add('opacity-100');
            body.classList.add('nav-open');
        };

        const closeSidebar = () => {
            if (!sidebar || !overlay) return;
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('opacity-0', 'pointer-events-none');
            overlay.classList.remove('opacity-100');
            body.classList.remove('nav-open');
        };

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                if (sidebar.classList.contains('-translate-x-full')) {
                    openSidebar();
                } else {
                    closeSidebar();
                }
            });
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Add student form toggle
        function toggleAddStudentForm(forceState) {
            const body = document.getElementById('addStudentFormBody');
            const btn = document.getElementById('toggleAddStudentForm');
            if (!body || !btn) return;
            const shouldShow = typeof forceState === 'boolean'
                ? forceState
                : body.style.display === 'none';
            body.style.display = shouldShow ? 'block' : 'none';
            btn.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
            btn.innerHTML = shouldShow
                ? '<i class="fas fa-eye-slash"></i><span>Hide Form</span>'
                : '<i class="fas fa-eye"></i><span>Show Form</span>';
        }

        document.addEventListener('DOMContentLoaded', function () {
            const btn = document.getElementById('toggleAddStudentForm');
            if (btn) {
                btn.addEventListener('click', function () {
                    toggleAddStudentForm();
                });
            }

            document.querySelectorAll('.close-btn').forEach((button) => {
                button.textContent = '×';
            });
        });

        // Bulk upload functionality
        function handleFileSelect(input) {
            const file = input.files[0];
            const fileNameDiv = document.getElementById('fileName');
            const fileErrorDiv = document.getElementById('fileError');
            const uploadArea = document.getElementById('uploadArea');

            // Reset previous state
            fileErrorDiv.style.display = 'none';
            fileErrorDiv.textContent = '';
            uploadArea.style.borderColor = '#ddd';
            uploadArea.style.backgroundColor = '';

            if (file) {
                // Validate file type
                if (!file.name.toLowerCase().endsWith('.csv')) {
                    fileErrorDiv.textContent = 'Please select a CSV file only.';
                    fileErrorDiv.style.display = 'block';
                    uploadArea.style.borderColor = '#dc3545';
                    uploadArea.style.backgroundColor = '#f8d7da';
                    input.value = ''; // Clear the file input
                    fileNameDiv.textContent = '';
                    return;
                }

                // Validate file size (5MB max)
                const maxSize = 5 * 1024 * 1024; // 5MB in bytes
                if (file.size > maxSize) {
                    fileErrorDiv.textContent = 'File size must be less than 5MB.';
                    fileErrorDiv.style.display = 'block';
                    uploadArea.style.borderColor = '#dc3545';
                    uploadArea.style.backgroundColor = '#f8d7da';
                    input.value = ''; // Clear the file input
                    fileNameDiv.textContent = '';
                    return;
                }

                // File is valid
                fileNameDiv.textContent = file.name;
                uploadArea.style.borderColor = '#28a745';
                uploadArea.style.backgroundColor = '#d4edda';
            } else {
                fileNameDiv.textContent = '';
            }
        }

        function handleDragOver(event) {
            event.preventDefault();
            event.stopPropagation();
            const uploadArea = document.getElementById('uploadArea');
            uploadArea.style.borderColor = '#007bff';
            uploadArea.style.backgroundColor = '#e3f2fd';
        }

        function handleDragLeave(event) {
            event.preventDefault();
            event.stopPropagation();
            const uploadArea = document.getElementById('uploadArea');
            uploadArea.style.borderColor = '#ddd';
            uploadArea.style.backgroundColor = '';
        }

        function handleDrop(event) {
            event.preventDefault();
            event.stopPropagation();

            const uploadArea = document.getElementById('uploadArea');
            uploadArea.style.borderColor = '#ddd';
            uploadArea.style.backgroundColor = '';

            const files = event.dataTransfer.files;
            if (files.length > 0) {
                const fileInput = document.getElementById('csvFile');
                fileInput.files = files;
                handleFileSelect(fileInput);
            }
        }

        function downloadTemplate() {
            const csvContent = "full_name,admission_no,gender,dob,phone,guardian_name,guardian_phone,address,student_type\n" +
                              "John Doe,STU001,Male,2005-05-15,1234567890,Jane Doe,0987654321,123 Main St,fresh\n" +
                              "Jane Smith,STU002,Female,2006-03-20,2345678901,John Smith,9876543210,456 Oak Ave,fresh";

            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'students_template.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Quick actions modal
        function showQuickActions(studentId, studentName) {
            const content = `
                <h3 style="margin-top: 0;">Quick Actions for ${studentName}</h3>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1.5rem 0;">
                    <!-- Add Note -->
                    <form method="POST" style="background: #f9fafb; padding: 1rem; border-radius: 8px;">
                        <input type="hidden" name="action" value="add_note">
                        <input type="hidden" name="student_id" value="${studentId}">
                        <h4 style="margin: 0 0 0.5rem 0;">📝 Add Note</h4>
                        <textarea name="note" placeholder="Enter note..." style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 0.5rem;"></textarea>
                        <button type="submit" class="btn btn-primary">Add Note</button>
                    </form>

                    <!-- Mark Attendance -->
                    <form method="POST" style="background: #f9fafb; padding: 1rem; border-radius: 8px;">
                        <input type="hidden" name="action" value="attendance">
                        <input type="hidden" name="student_id" value="${studentId}">
                        <h4 style="margin: 0 0 0.5rem 0;">✓ Mark Attendance</h4>
                        <input type="date" name="date" value="${new Date().toISOString().split('T')[0]}" style="width: 100%; padding: 0.5rem; margin-bottom: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                        <select name="status" style="width: 100%; padding: 0.5rem; margin-bottom: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="present">✅ Present</option>
                            <option value="absent">❌ Absent</option>
                            <option value="late">⏰ Late</option>
                        </select>
                        <button type="submit" class="btn btn-success">Record</button>
                    </form>
                </div>

                <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                    <a href="edit_student.php?id=${studentId}" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Student
                    </a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete ${studentName}?')">
                        <input type="hidden" name="action" value="delete_student">
                        <input type="hidden" name="student_id" value="${studentId}">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Student
                        </button>
                    </form>
                </div>
            `;

            document.getElementById('quickActionsContent').innerHTML = content;
            openModal('quickActionsModal');
        }

        function showQuickActions(studentId, studentName) {
            const content = `
                <h3 style="margin-top: 0; font-family: Fraunces, Georgia, serif; color: #0f1f2d;">Quick Actions for ${studentName}</h3>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin: 1.5rem 0;">
                    <form method="POST" style="background: #f8fafc; padding: 1rem; border-radius: 16px; border: 1px solid rgba(15,31,45,0.06);">
                        <input type="hidden" name="action" value="add_note">
                        <input type="hidden" name="student_id" value="${studentId}">
                        <h4 style="margin: 0 0 0.5rem 0; color: #0f1f2d;">Add Note</h4>
                        <textarea name="note" placeholder="Enter note..." style="width: 100%; padding: 0.75rem; border: 1px solid rgba(15,31,45,0.12); border-radius: 12px; margin-bottom: 0.5rem;"></textarea>
                        <button type="submit" class="btn btn-primary">Add Note</button>
                    </form>

                    <form method="POST" style="background: #f8fafc; padding: 1rem; border-radius: 16px; border: 1px solid rgba(15,31,45,0.06);">
                        <input type="hidden" name="action" value="attendance">
                        <input type="hidden" name="student_id" value="${studentId}">
                        <h4 style="margin: 0 0 0.5rem 0; color: #0f1f2d;">Mark Attendance</h4>
                        <input type="date" name="date" value="${new Date().toISOString().split('T')[0]}" style="width: 100%; padding: 0.75rem; margin-bottom: 0.5rem; border: 1px solid rgba(15,31,45,0.12); border-radius: 12px;">
                        <select name="status" style="width: 100%; padding: 0.75rem; margin-bottom: 0.5rem; border: 1px solid rgba(15,31,45,0.12); border-radius: 12px;">
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                            <option value="late">Late</option>
                        </select>
                        <button type="submit" class="btn btn-success">Record</button>
                    </form>
                </div>

                <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                    <a href="edit_student.php?id=${studentId}" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Student
                    </a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete ${studentName}?')">
                        <input type="hidden" name="action" value="delete_student">
                        <input type="hidden" name="student_id" value="${studentId}">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Student
                        </button>
                    </form>
                </div>
            `;

            document.getElementById('quickActionsContent').innerHTML = content;
            openModal('quickActionsModal');
        }

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
