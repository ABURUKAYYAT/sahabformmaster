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
$teacher_id = intval($_SESSION['user_id']);
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
    WHERE s.class_id IN (
        SELECT DISTINCT c.id
        FROM classes c
        WHERE EXISTS (
            SELECT 1 FROM subject_assignments sa WHERE sa.class_id = c.id AND sa.teacher_id = ?
        ) OR EXISTS (
            SELECT 1 FROM class_teachers ct WHERE ct.class_id = c.id AND ct.teacher_id = ?
        )
    )
");
$stmt->execute([$teacher_id, $teacher_id]);
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

    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('SahabFormMaster');
    $pdf->SetAuthor('School Management System');
    $pdf->SetSubject('Student Registration Numbers');

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

    // Get school info
    $stmt = $pdo->prepare("SELECT * FROM school_profile LIMIT 1");
    $stmt->execute();
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

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

    // Get export class filter
    $export_class_id = intval($_GET['class_id'] ?? 0);
    $export_class_name = '';

    if ($export_class_id > 0) {
        // Check if teacher has access to this class
        $stmt_check = $pdo->prepare("
            SELECT class_name
            FROM classes c
            WHERE c.id = ?
            AND (
                EXISTS (SELECT 1 FROM subject_assignments sa WHERE sa.class_id = c.id AND sa.teacher_id = ?)
                OR EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = c.id AND ct.teacher_id = ?)
            )
        ");
        $stmt_check->execute([$export_class_id, $teacher_id, $teacher_id]);
        $export_class_name = $stmt_check->fetchColumn();

        if (!$export_class_name) {
            // No access, export all instead
            $export_class_id = 0;
        }
    }

    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $title = $export_class_name ? $export_class_name . ' Students List' : 'STUDENTS REGISTRATION LIST';
    $pdf->Cell(0, 12, $title, 0, 1, 'C');
    $pdf->Ln(5);

    // Get students for current teacher with class info
    $sql = "
        SELECT s.id, s.full_name, s.admission_no, c.class_name
        FROM students s
        JOIN classes c ON s.class_id = c.id
        WHERE s.class_id IN (
            SELECT DISTINCT c2.id
            FROM classes c2
            WHERE EXISTS (
                SELECT 1 FROM subject_assignments sa WHERE sa.class_id = c2.id AND sa.teacher_id = ?
            ) OR EXISTS (
                SELECT 1 FROM class_teachers ct WHERE ct.class_id = c2.id AND ct.teacher_id = ?
            )
        )
    ";
    $params = [$teacher_id, $teacher_id];

    if ($export_class_id > 0) {
        $sql .= " AND s.class_id = ?";
        $params[] = $export_class_id;
    }

    $sql .= " ORDER BY c.class_name, s.full_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $export_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Table Header
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(15, 10, 'S/N', 1, 0, 'C', true);
    $pdf->Cell(70, 10, 'Student Name', 1, 0, 'C', true);
    $pdf->Cell(45, 10, 'Admission Number', 1, 0, 'C', true);
    $pdf->Cell(35, 10, 'Class', 1, 1, 'C', true);

    // Table Data
    $pdf->SetFont('helvetica', '', 11);
    $serial = 1;
    foreach ($export_students as $student) {
        $pdf->Cell(15, 8, $serial++, 1, 0, 'C');
        $pdf->Cell(70, 8, $student['full_name'], 1, 0, 'L');
        $pdf->Cell(45, 8, $student['admission_no'], 1, 0, 'C');
        $pdf->Cell(35, 8, $student['class_name'], 1, 1, 'C');
    }

    // Summary
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Total Students: ' . count($export_students), 0, 1, 'L');

    // Footer
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 5, 'Generated by SahabFormMaster on ' . date('d/m/Y H:i'), 0, 1, 'C');
    $pdf->Cell(0, 5, 'This document contains student registration information', 0, 1, 'C');

    // Output PDF
    $filename = 'students_list_' . date('Y-m-d') . '.pdf';
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
    $stmt = $pdo->prepare("SELECT * FROM school_profile LIMIT 1");
    $stmt->execute();
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get class details
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :id");
    $stmt->execute(['id' => $record_class_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        die("Class not found.");
    }

    // Get students in class
    $stmt = $pdo->prepare("SELECT id, full_name, admission_no FROM students WHERE class_id = :class_id ORDER BY full_name ASC");
    $stmt->execute(['class_id' => $record_class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Create PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('SahabFormMaster');
    $pdf->SetAuthor('School Management System');
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
            // Build query with search - only students in assigned classes
            $sql = "SELECT s.id, s.full_name, s.admission_no, s.gender, s.phone,
                           s.guardian_name, s.guardian_phone, s.dob, s.enrollment_date,
                           s.address, s.student_type, c.class_name
                    FROM students s
                    JOIN classes c ON s.class_id = c.id
                    WHERE s.class_id = :cid
                    AND s.class_id IN (
                        SELECT DISTINCT c2.id
                        FROM classes c2
                        WHERE EXISTS (
                            SELECT 1 FROM subject_assignments sa WHERE sa.class_id = c2.id AND sa.teacher_id = :tid
                        ) OR EXISTS (
                            SELECT 1 FROM class_teachers ct WHERE ct.class_id = c2.id AND ct.teacher_id = :tid2
                        )
                    )";
            $params = ['cid' => $class_id, 'tid' => $teacher_id, 'tid2' => $teacher_id];

            if (!empty($search)) {
                $sql .= " AND (s.full_name LIKE :search
                        OR s.admission_no LIKE :search
                        OR s.guardian_name LIKE :search
                        OR s.guardian_phone LIKE :search
                        OR s.phone LIKE :search)";
                $params['search'] = "%$search%";
            }

            $sql .= " ORDER BY s.full_name";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $errors[] = 'Class not found.';
        $class_id = 0;
    }
} elseif ($show_all_classes && !empty($search)) {
    // Search across all classes
    $sql = "SELECT s.id, s.full_name, s.admission_no, s.gender, s.phone,
                   s.guardian_name, s.guardian_phone, s.dob, s.enrollment_date,
                   s.address, s.student_type, c.class_name, c.id as class_id
            FROM students s
            JOIN classes c ON s.class_id = c.id
            WHERE (s.full_name LIKE :search
                   OR s.admission_no LIKE :search
                   OR s.guardian_name LIKE :search
                   OR s.guardian_phone LIKE :search
                   OR s.phone LIKE :search)
            ORDER BY c.class_name, s.full_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['search' => "%$search%"]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Students | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Mobile Navigation Dropdown -->
    <div class="mobile-nav-dropdown" id="mobileNavDropdown">
        <div class="mobile-nav-header">
            <h3>Navigation</h3>
            <button class="mobile-nav-close" id="mobileNavClose">&times;</button>
        </div>
        <nav class="mobile-nav-menu">
            <a href="index.php" class="mobile-nav-link">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="schoolfeed.php" class="mobile-nav-link">
                <i class="fas fa-newspaper"></i>
                <span>School Feeds</span>
            </a>
            <a href="school_diary.php" class="mobile-nav-link">
                <i class="fas fa-book"></i>
                <span>School Diary</span>
            </a>
            <a href="students.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'students.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span>Students</span>
            </a>
            <a href="results.php" class="mobile-nav-link">
                <i class="fas fa-chart-line"></i>
                <span>Results</span>
            </a>
            <a href="subjects.php" class="mobile-nav-link">
                <i class="fas fa-book-open"></i>
                <span>Subjects</span>
            </a>
            <a href="questions.php" class="mobile-nav-link">
                <i class="fas fa-question-circle"></i>
                <span>Questions</span>
            </a>
            <a href="lesson-plan.php" class="mobile-nav-link">
                <i class="fas fa-clipboard-list"></i>
                <span>Lesson Plans</span>
            </a>
            <a href="curricullum.php" class="mobile-nav-link">
                <i class="fas fa-graduation-cap"></i>
                <span>Curriculum</span>
            </a>
            <a href="teacher_class_activities.php" class="mobile-nav-link">
                <i class="fas fa-tasks"></i>
                <span>Class Activities</span>
            </a>
            <a href="student-evaluation.php" class="mobile-nav-link">
                <i class="fas fa-star"></i>
                <span>Evaluations</span>
            </a>
            <a href="class_attendance.php" class="mobile-nav-link">
                <i class="fas fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
            <a href="timebook.php" class="mobile-nav-link">
                <i class="fas fa-clock"></i>
                <span>Time Book</span>
            </a>
            <a href="permissions.php" class="mobile-nav-link">
                <i class="fas fa-key"></i>
                <span>Permissions</span>
            </a>
            <a href="payments.php" class="mobile-nav-link">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payments</span>
            </a>
        </nav>
    </div>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Students Management</p>
                    </div>
                </div>
            </div>

            <!-- Teacher Info, Dashboard, and Logout -->
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
                </div>
                <a href="index.php" class="btn-dashboard">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content" style="margin-left: 0; max-width: 100%; padding: 2rem;">
            <!-- Content Header -->
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Student Management</h2>
                    <p>Manage your students, add new enrollments, and track attendance</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $student_count; ?></span>
                        <span class="quick-stat-label">Total Students</span>
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

            <!-- Quick Actions -->
            <div class="quick-actions-section">
                <div class="section-header">
                    <h3>Quick Actions</h3>
                </div>
                <div class="quick-actions-grid">
                    <a href="add_student.php" class="quick-action-card">
                        <i class="fas fa-user-plus"></i>
                        <span>Add Student</span>
                    </a>
                    <button onclick="openModal('bulkUploadModal')" class="quick-action-card">
                        <i class="fas fa-file-upload"></i>
                        <span>Bulk Upload</span>
                    </button>
                    <button onclick="openModal('examsRecordModal')" class="quick-action-card">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Exams Record List</span>
                    </button>
                    <a href="?export=students_pdf&class_id=<?php echo $class_id; ?>" class="quick-action-card">
                        <i class="fas fa-download"></i>
                        <span>Export PDF</span>
                    </a>
                </div>
            </div>

            <!-- Class Selection -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Class Selection</h2>
                </div>
                <div style="padding: 1.5rem;">
                    <form method="GET" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px;">
                            <label for="class_id" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Select Class:</label>
                            <select name="class_id" id="class_id" onchange="this.form.submit()" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px;">
                                <option value="">-- All Classes --</option>
                                <?php foreach($assigned_classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $class_id == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <label for="search" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Search Students:</label>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, admission number, or guardian..."
                                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px;">
                        </div>
                        <div style="display: flex; gap: 0.5rem; align-items: flex-end;">
                            <button type="submit" class="btn" style="padding: 0.75rem 1.5rem;">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="?view_all=1" class="btn" style="padding: 0.75rem 1.5rem;">
                                <i class="fas fa-list"></i> All Students
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Student Management Section -->
            <?php if($class_id || ($show_all_classes && $search)): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h2><?php echo $class_details ? htmlspecialchars($class_details['class_name']) : 'Search Results'; ?> Students (<?php echo count($students); ?>)</h2>
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
                                            <td>
                                                <div class="student-name-cell">
                                                    <?php echo htmlspecialchars($s['full_name']); ?>
                                                    <?php if($s['student_type'] === 'fresh'): ?>
                                                        <span class="badge badge-success badge-small">New</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($s['admission_no']); ?></td>
                                            <?php if($show_all_classes): ?>
                                                <td><?php echo htmlspecialchars($s['class_name']); ?></td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="badge <?php echo strtolower($s['gender']) === 'male' ? 'badge-primary' : 'badge-danger'; ?> badge-small">
                                                    <i class="fas fa-<?php echo strtolower($s['gender']) === 'male' ? 'mars' : 'venus'; ?>"></i>
                                                    <?php echo htmlspecialchars($s['gender']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($s['phone'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($s['guardian_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo $s['enrollment_date'] ? date('M d, Y', strtotime($s['enrollment_date'])) : 'N/A'; ?></td>
                                            <td>
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
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem; color: #666;">
                            <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3 style="margin-bottom: 0.5rem;">No students found</h3>
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
                            <div style="text-align: center; padding: 3rem; color: #666;">
                                <i class="fas fa-graduation-cap" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3 style="margin-bottom: 0.5rem;">No Classes Assigned</h3>
                                <p>You haven't been assigned to any classes yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
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
        // Mobile Menu Toggle - Dropdown Navigation
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileNavDropdown = document.getElementById('mobileNavDropdown');
        const mobileNavClose = document.getElementById('mobileNavClose');

        // Toggle dropdown menu
        mobileMenuToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            mobileNavDropdown.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        // Close dropdown when clicking close button
        mobileNavClose.addEventListener('click', () => {
            mobileNavDropdown.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!mobileNavDropdown.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                mobileNavDropdown.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            }
        });

        // Close dropdown when clicking on a navigation link
        document.querySelectorAll('.mobile-nav-link').forEach(link => {
            link.addEventListener('click', () => {
                mobileNavDropdown.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            });
        });

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

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

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
