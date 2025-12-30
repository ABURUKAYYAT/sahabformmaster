<?php
session_start();
require_once '../config/db.php';

// Only teachers
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
$teacher_id = intval($_SESSION['user_id']);
$errors = [];
$success = '';

// Fetch classes assigned to teacher from BOTH subject_assignments AND class_teachers
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name, 'subject' as assignment_type
    FROM classes c 
    JOIN subject_assignments sa ON c.id = sa.class_id 
    WHERE sa.teacher_id = :tid 
    
    UNION
    
    SELECT DISTINCT c.id, c.class_name, 'form_master' as assignment_type
    FROM classes c 
    JOIN class_teachers ct ON c.id = ct.class_id 
    WHERE ct.teacher_id = :tid2
    
    ORDER BY class_name
");
$stmt->execute(['tid'=>$teacher_id, 'tid2'=>$teacher_id]);
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

// Get total subject count for this teacher
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT subject_id) FROM subject_assignments WHERE teacher_id = ?");
$stmt->execute([$teacher_id]);
$subject_count = $stmt->fetchColumn();

// Fetch ALL classes for the dropdown
$stmt = $pdo->prepare("SELECT id, class_name FROM classes ORDER BY class_name");
$stmt->execute();
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
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ?");
        $stmt->execute([$admission_no]);
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
                    INSERT INTO students (class_id, full_name, admission_no, gender, dob, phone, 
                                         guardian_name, guardian_phone, address, student_type, enrollment_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $class_id, $full_name, $admission_no, $gender, $dob ?: null,
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
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ? AND id != ?");
        $stmt->execute([$admission_no, $student_id]);
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
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ?");
                            $stmt->execute([$admission_no]);
                            if ($stmt->fetchColumn() > 0) {
                                $skipped++;
                                $errors_list[] = "Skipped: Admission no '$admission_no' already exists";
                                continue;
                            }
                            
                            try {
                                $stmt = $pdo->prepare("
                                    INSERT INTO students (class_id, full_name, admission_no, gender, dob, phone, 
                                                         guardian_name, guardian_phone, address, student_type, enrollment_date)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $class_id, $full_name, $admission_no, $gender, 
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
    $pdf->SetTitle('Students List Export');
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

    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 12, 'STUDENTS REGISTRATION LIST', 0, 1, 'C');
    $pdf->Ln(5);

    // Get students for current teacher with class info
    $stmt = $pdo->prepare("
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
        ORDER BY c.class_name, s.full_name
    ");
    $stmt->execute([$teacher_id, $teacher_id]);
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

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

            <!-- Teacher Info and Logout -->
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
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
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link">
                            <i class="fas fa-tachometer-alt nav-icon"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="schoolfeed.php" class="nav-link">
                            <i class="fas fa-newspaper nav-icon"></i>
                            <span class="nav-text">School Feeds</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link">
                            <i class="fas fa-book nav-icon"></i>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link active">
                            <i class="fas fa-users nav-icon"></i>
                            <span class="nav-text">Students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="results.php" class="nav-link">
                            <i class="fas fa-chart-line nav-icon"></i>
                            <span class="nav-text">Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <i class="fas fa-book-open nav-icon"></i>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="questions.php" class="nav-link">
                            <i class="fas fa-question-circle nav-icon"></i>
                            <span class="nav-text">Questions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plan.php" class="nav-link">
                            <i class="fas fa-clipboard-list nav-icon"></i>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="curricullum.php" class="nav-link">
                            <i class="fas fa-graduation-cap nav-icon"></i>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="teacher_class_activities.php" class="nav-link">
                            <i class="fas fa-tasks nav-icon"></i>
                            <span class="nav-text">Class Activities</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="student-evaluation.php" class="nav-link">
                            <i class="fas fa-star nav-icon"></i>
                            <span class="nav-text">Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="class_attendance.php" class="nav-link">
                            <i class="fas fa-calendar-check nav-icon"></i>
                            <span class="nav-text">Attendance</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="timebook.php" class="nav-link">
                            <i class="fas fa-clock nav-icon"></i>
                            <span class="nav-text">Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <i class="fas fa-key nav-icon"></i>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments.php" class="nav-link">
                            <i class="fas fa-money-bill-wave nav-icon"></i>
                            <span class="nav-text">Payments</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Student Management 👨‍🎓</h2>
                    <p>Manage your students, track attendance, and maintain records</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo count($assigned_classes); ?></span>
                        <span class="quick-stat-label">Classes</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $student_count; ?></span>
                        <span class="quick-stat-label">Students</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $subject_count; ?></span>
                        <span class="quick-stat-label">Subjects</span>
                    </div>
                </div>
            </div>

            <!-- Enhanced Analytics Dashboard -->
            <div class="analytics-dashboard">
                <div class="analytics-header">
                    <div class="analytics-title">
                        <i class="fas fa-chart-line"></i>
                        <h2>Student Analytics & Insights</h2>
                    </div>
                    <div class="analytics-meta">
                        <span class="update-badge">
                            <i class="fas fa-clock"></i> Last updated: <?php echo date('M d, H:i'); ?>
                        </span>
                        <span class="data-badge">
                            <i class="fas fa-database"></i> Real-time Data
                        </span>
                    </div>
                </div>

                <!-- Key Performance Indicators -->
                <div class="kpi-grid">
                    <?php
                    // Calculate comprehensive stats
                    $fresh_students = 0;
                    $transfer_students = 0;
                    $total_guardians = 0;
                    $with_phone = 0;
                    $with_guardian_phone = 0;
                    $male_students = 0;
                    $female_students = 0;
                    $other_gender = 0;
                    $recent_enrollments = 0;

                    $all_students_query = $pdo->prepare("
                        SELECT s.* FROM students s
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
                    $all_students_query->execute([$teacher_id, $teacher_id]);
                    $all_students = $all_students_query->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($all_students as $s) {
                        if ($s['student_type'] === 'fresh') $fresh_students++;
                        if ($s['student_type'] === 'transfer') $transfer_students++;
                        if (!empty($s['phone'])) $with_phone++;
                        if (!empty($s['guardian_phone'])) $with_guardian_phone++;

                        // Gender counts
                        if (strtolower($s['gender']) === 'male') $male_students++;
                        elseif (strtolower($s['gender']) === 'female') $female_students++;
                        else $other_gender++;

                        // Recent enrollments (last 30 days)
                        if ($s['enrollment_date'] && strtotime($s['enrollment_date']) > strtotime('-30 days')) {
                            $recent_enrollments++;
                        }
                    }

                    $total_guardians = count(array_unique(array_column(array_filter($all_students, function($s) {
                        return !empty($s['guardian_name']);
                    }), 'guardian_name')));

                    $total_students = count($all_students);
                    $contact_completeness = $total_students > 0 ? round(($with_phone / $total_students) * 100) : 0;
                    ?>

                    <div class="kpi-card kpi-primary">
                        <div class="kpi-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-value"><?php echo $total_students; ?></div>
                            <div class="kpi-label">Total Students</div>
                            <div class="kpi-trend">
                                <i class="fas fa-arrow-up"></i>
                                <span>+<?php echo $recent_enrollments; ?> this month</span>
                            </div>
                        </div>
                    </div>

                    <div class="kpi-card kpi-success">
                        <div class="kpi-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-value"><?php echo $fresh_students; ?></div>
                            <div class="kpi-label">Fresh Students</div>
                            <div class="kpi-percentage"><?php echo $total_students > 0 ? round(($fresh_students / $total_students) * 100) : 0; ?>%</div>
                        </div>
                    </div>

                    <div class="kpi-card kpi-warning">
                        <div class="kpi-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-value"><?php echo $transfer_students; ?></div>
                            <div class="kpi-label">Transfer Students</div>
                            <div class="kpi-percentage"><?php echo $total_students > 0 ? round(($transfer_students / $total_students) * 100) : 0; ?>%</div>
                        </div>
                    </div>

                    <div class="kpi-card kpi-info">
                        <div class="kpi-icon">
                            <i class="fas fa-venus-mars"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-value"><?php echo $male_students; ?>/<?php echo $female_students; ?></div>
                            <div class="kpi-label">Male/Female Ratio</div>
                            <div class="kpi-percentage"><?php echo $total_students > 0 ? round(($male_students / $total_students) * 100) : 0; ?>% male</div>
                        </div>
                    </div>

                    <div class="kpi-card kpi-secondary">
                        <div class="kpi-icon">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-value"><?php echo $total_guardians; ?></div>
                            <div class="kpi-label">Active Guardians</div>
                            <div class="kpi-percentage">Contact available</div>
                        </div>
                    </div>

                    <div class="kpi-card kpi-danger">
                        <div class="kpi-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-value"><?php echo $contact_completeness; ?>%</div>
                            <div class="kpi-label">Contact Complete</div>
                            <div class="kpi-percentage"><?php echo $with_phone; ?>/<?php echo $total_students; ?> students</div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="charts-section">
                    <div class="charts-header">
                        <h3>Detailed Analytics</h3>
                        <div class="chart-controls">
                            <button class="btn btn-outline" onclick="refreshCharts()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <div class="charts-grid">
                        <div class="chart-container">
                            <div class="chart-header">
                                <h4><i class="fas fa-school"></i> Class Distribution</h4>
                                <span class="chart-info"><?php echo count($assigned_classes); ?> classes</span>
                            </div>
                            <div class="chart-wrapper">
                                <canvas id="classChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-container">
                            <div class="chart-header">
                                <h4><i class="fas fa-venus-mars"></i> Gender Breakdown</h4>
                                <span class="chart-info"><?php echo $male_students + $female_students + $other_gender; ?> students</span>
                            </div>
                            <div class="chart-wrapper">
                                <canvas id="genderChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-container">
                            <div class="chart-header">
                                <h4><i class="fas fa-calendar-alt"></i> Enrollment Trends</h4>
                                <span class="chart-info">Last 6 months</span>
                            </div>
                            <div class="chart-wrapper">
                                <canvas id="enrollmentChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-container">
                            <div class="chart-header">
                                <h4><i class="fas fa-graduation-cap"></i> Student Types</h4>
                                <span class="chart-info">Fresh vs Transfer</span>
                            </div>
                            <div class="chart-wrapper">
                                <canvas id="typeChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Insights -->
                    <div class="insights-section">
                        <h3>Key Insights</h3>
                        <div class="insights-grid">
                            <?php
                            $attendance_rate = 0;
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) as total_attendance,
                                       SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count
                                FROM attendance a
                                JOIN students s ON a.student_id = s.id
                                WHERE a.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                AND s.class_id IN (
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
                            $attendance_data = $stmt->fetch();
                            if ($attendance_data['total_attendance'] > 0) {
                                $attendance_rate = round(($attendance_data['present_count'] / $attendance_data['total_attendance']) * 100, 1);
                            }
                            ?>

                            <div class="insight-card">
                                <div class="insight-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="insight-content">
                                    <h4>Attendance Rate</h4>
                                    <div class="insight-value"><?php echo $attendance_rate; ?>%</div>
                                    <p>Last 30 days average attendance</p>
                                </div>
                            </div>

                            <div class="insight-card">
                                <div class="insight-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="insight-content">
                                    <h4>Growth Rate</h4>
                                    <div class="insight-value">+<?php echo $recent_enrollments; ?></div>
                                    <p>New enrollments this month</p>
                                </div>
                            </div>

                            <div class="insight-card">
                                <div class="insight-icon">
                                    <i class="fas fa-balance-scale"></i>
                                </div>
                                <div class="insight-content">
                                    <h4>Class Balance</h4>
                                    <div class="insight-value">
                                        <?php
                                        $max_class = 0;
                                        $min_class = PHP_INT_MAX;
                                        foreach($assigned_classes as $c) {
                                            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ?");
                                            $stmt->execute([$c['id']]);
                                            $count = $stmt->fetch()['count'];
                                            $max_class = max($max_class, $count);
                                            $min_class = min($min_class, $count);
                                        }
                                        echo $max_class - $min_class;
                                        ?>
                                    </div>
                                    <p>Student difference between largest and smallest class</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Student Form Above List -->
            <div class="panel" style="margin-bottom: 2rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
                    <h2 style="margin: 0;">
                        <i class="fas fa-user-plus"></i> Add New Student
                    </h2>
                    <div style="display: flex; gap: 1rem;">
                        <button onclick="openModal('bulkUploadModal')" class="btn btn-success">
                            <i class="fas fa-file-upload"></i> Bulk Upload CSV
                        </button>
                        <a href="?export=students_pdf" class="btn btn-primary">
                            <i class="fas fa-download"></i> Export Students PDF
                        </a>
                    </div>
                </div>

                <form method="POST" id="quickAddStudentForm">
                    <input type="hidden" name="action" value="add_student">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="quick_class_id">
                                <i class="fas fa-school"></i> Class *
                            </label>
                            <select id="quick_class_id" name="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach($assigned_classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>">
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="quick_full_name">
                                <i class="fas fa-user"></i> Full Name *
                            </label>
                            <input type="text" id="quick_full_name" name="full_name" class="form-control" required
                                   placeholder="Enter full name">
                        </div>

                        <div class="form-group">
                            <label for="quick_admission_no">
                                <i class="fas fa-id-card"></i> Admission Number *
                            </label>
                            <input type="text" id="quick_admission_no" name="admission_no" class="form-control" required
                                   placeholder="Enter admission number">
                        </div>

                        <div class="form-group">
                            <label for="quick_gender">
                                <i class="fas fa-venus-mars"></i> Gender
                            </label>
                            <select id="quick_gender" name="gender" class="form-control">
                                <option value="">-- Select Gender --</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="quick_dob">
                                <i class="fas fa-birthday-cake"></i> Date of Birth
                            </label>
                            <input type="date" id="quick_dob" name="dob" class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="quick_phone">
                                <i class="fas fa-phone"></i> Phone Number
                            </label>
                            <input type="tel" id="quick_phone" name="phone" class="form-control"
                                   placeholder="Enter phone number">
                        </div>

                        <div class="form-group">
                            <label for="quick_guardian_name">
                                <i class="fas fa-user-friends"></i> Guardian Name
                            </label>
                            <input type="text" id="quick_guardian_name" name="guardian_name" class="form-control"
                                   placeholder="Enter guardian name">
                        </div>

                        <div class="form-group">
                            <label for="quick_guardian_phone">
                                <i class="fas fa-phone-alt"></i> Guardian Phone
                            </label>
                            <input type="tel" id="quick_guardian_phone" name="guardian_phone" class="form-control"
                                   placeholder="Enter guardian phone">
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="quick_address">
                                <i class="fas fa-map-marker-alt"></i> Address
                            </label>
                            <textarea id="quick_address" name="address" class="form-control" rows="2"
                                      placeholder="Enter address"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="quick_student_type">
                                <i class="fas fa-graduation-cap"></i> Student Type
                            </label>
                            <select id="quick_student_type" name="student_type" class="form-control">
                                <option value="fresh">Fresh Student</option>
                                <option value="transfer">Transfer Student</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn-gold">
                            <i class="fas fa-save"></i> Add Student
                        </button>
                        <button type="reset" class="btn">
                            <i class="fas fa-undo"></i> Reset Form
                        </button>
                    </div>
                </form>
            </div>

            <!-- Student Management Section -->
            <?php if($class_id || ($show_all_classes && $search)): ?>
                <div class="quick-actions-section">
                    <div class="section-header">
                        <h3><?php echo $class_details ? htmlspecialchars($class_details['class_name']) : 'Search Results'; ?> Students</h3>
                        <span class="section-badge"><?php echo count($students); ?> Students</span>
                    </div>

                    <?php if($students): ?>
                        <!-- Modern Student Cards -->
                        <div class="students-grid" style="margin-top: 1.5rem;">
                            <?php foreach($students as $index => $s): ?>
                                <div class="student-card">
                                    <div class="student-card-header">
                                        <div class="student-avatar">
                                            <div class="avatar-circle">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        </div>
                                        <div class="student-info">
                                            <h4 class="student-name">
                                                <?php echo htmlspecialchars($s['full_name']); ?>
                                                <?php if($s['student_type'] === 'fresh'): ?>
                                                    <span class="badge badge-success">New</span>
                                                <?php endif; ?>
                                            </h4>
                                            <p class="student-admission">
                                                <i class="fas fa-id-card"></i>
                                                <?php echo htmlspecialchars($s['admission_no']); ?>
                                            </p>
                                            <p class="student-class">
                                                <i class="fas fa-school"></i>
                                                <?php echo htmlspecialchars($s['class_name']); ?>
                                            </p>
                                        </div>
                                        <div class="student-status">
                                            <span class="badge <?php echo strtolower($s['gender']) === 'male' ? 'badge-primary' : 'badge-danger'; ?>">
                                                <i class="fas fa-<?php echo strtolower($s['gender']) === 'male' ? 'mars' : 'venus'; ?>"></i>
                                                <?php echo htmlspecialchars($s['gender']); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="student-card-body">
                                        <div class="contact-info">
                                            <div class="info-item">
                                                <i class="fas fa-phone"></i>
                                                <span><?php echo htmlspecialchars($s['phone'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <i class="fas fa-user-friends"></i>
                                                <span><?php echo htmlspecialchars($s['guardian_name'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <i class="fas fa-calendar"></i>
                                                <span><?php echo $s['enrollment_date'] ? date('M d, Y', strtotime($s['enrollment_date'])) : 'N/A'; ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="student-card-footer">
                                        <div class="card-actions">
                                            <a href="student_details.php?id=<?php echo $s['id']; ?>" class="btn btn-primary btn-small">
                                                <i class="fas fa-eye"></i> View Details
                                            </a>
                                            <a href="edit_student.php?id=<?php echo $s['id']; ?><?php echo isset($_GET['class_id']) ? '&class_id=' . intval($_GET['class_id']) : ''; ?>" class="btn btn-success btn-small">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <button class="btn btn-info btn-small"
                                                    onclick="showQuickActions(<?php echo $s['id']; ?>, '<?php echo addslashes($s['full_name']); ?>')">
                                                <i class="fas fa-bolt"></i> Actions
                                            </button>
                                        </div>

                                        <form method="POST" style="display: inline;"
                                              onsubmit="return confirm('Delete <?php echo addslashes($s['full_name']); ?>?')">
                                            <input type="hidden" name="action" value="delete_student">
                                            <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-small">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem 1rem; color: var(--gray-500);">
                            <i class="fas fa-users" style="font-size: 3rem; opacity: 0.5; margin-bottom: 1rem;"></i>
                            <h3 style="margin: 0 0 0.5rem 0;">No students found</h3>
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
                <!-- Class Selection -->
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
                                    <a href="?class_id=<?php echo $c['id']; ?>" class="btn btn-primary btn-small" style="margin-left: auto;">
                                        <i class="fas fa-eye"></i> View Students
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                <i class="fas fa-graduation-cap" style="font-size: 3rem; opacity: 0.5; margin-bottom: 1rem;"></i>
                                <h3>No Classes Assigned</h3>
                                <p>You haven't been assigned to any classes yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Recent Activity Section -->
            <div class="activity-section">
                <div class="section-header">
                    <h3>📋 Recent Student Activity</h3>
                    <a href="#" class="view-all-link">View All</a>
                </div>
                <div class="activity-list">
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-success">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">New student <strong>John Doe</strong> added to JSS 1A</span>
                            <span class="activity-date">Today, 2:30 PM</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-info">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">Attendance marked for <strong>JSS 2B</strong></span>
                            <span class="activity-date">Yesterday, 8:45 AM</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-warning">
                            <i class="fas fa-sticky-note"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">Note added for <strong>Jane Smith</strong></span>
                            <span class="activity-date">2 days ago</span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>About SahabFormMaster</h4>
                    <p>A comprehensive school management system designed for effective teaching and learning.</p>
                </div>
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="lesson-plan.php">Lesson Plans</a></li>
                        <li><a href="students.php">Students</a></li>
                        <li><a href="results.php">Results</a></li>
                        <li><a href="#">Support</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact Information</h4>
                    <p>📧 teacher.support@sahabformmaster.com</p>
                    <p>📱 +234 808 683 5607</p>
                    <p>🌐 www.sahabformmaster.com</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 SahabFormMaster. All rights reserved.</p>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <span>•</span>
                    <a href="#">Terms of Service</a>
                    <span>•</span>
                    <span>Version 2.0</span>
                </div>
            </div>
        </div>
    </footer>

<!-- Add Student Modal -->
<div id="addStudentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="margin: 0; color: var(--primary-color);">
                <i class="fas fa-user-plus"></i> Add New Student
            </h2>
            <button class="close-btn" onclick="closeModal('addStudentModal')">×</button>
        </div>
        <form method="POST" id="addStudentForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_student">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="add_class_id">Class *</label>
                        <select id="add_class_id" name="class_id" class="form-control" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach($assigned_classes as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo htmlspecialchars($c['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_full_name">Full Name *</label>
                        <input type="text" id="add_full_name" name="full_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_admission_no">Admission Number *</label>
                        <input type="text" id="add_admission_no" name="admission_no" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_gender">Gender</label>
                        <select id="add_gender" name="gender" class="form-control">
                            <option value="">-- Select --</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_dob">Date of Birth</label>
                        <input type="date" id="add_dob" name="dob" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="add_phone">Phone Number</label>
                        <input type="tel" id="add_phone" name="phone" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="add_guardian_name">Guardian Name</label>
                        <input type="text" id="add_guardian_name" name="guardian_name" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="add_guardian_phone">Guardian Phone</label>
                        <input type="tel" id="add_guardian_phone" name="guardian_phone" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="add_address">Address</label>
                        <textarea id="add_address" name="address" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_student_type">Student Type</label>
                        <select id="add_student_type" name="student_type" class="form-control">
                            <option value="fresh">Fresh Student</option>
                            <option value="transfer">Transfer Student</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('addStudentModal')">Cancel</button>
                <button type="submit" class="btn-gold">Add Student</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Upload Modal -->
<div id="bulkUploadModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="margin: 0; color: var(--primary-color);">
                <i class="fas fa-file-upload"></i> Bulk Upload Students
            </h2>
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
                
                <div class="upload-area" id="dropZone" 
                     onclick="document.getElementById('csvFile').click()">
                    <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: var(--primary-color); margin-bottom: 1rem;"></i>
                    <h3 style="margin: 0 0 0.5rem 0;">Upload CSV File</h3>
                    <p style="color: #6b7280; margin-bottom: 1.5rem;">
                        Click to select or drag & drop your CSV file here
                    </p>
                    <input type="file" id="csvFile" name="csv_file" accept=".csv" style="display: none;" 
                           onchange="updateFileName(this)">
                    <div id="fileName" style="font-weight: 500; color: var(--primary-color);"></div>
                </div>
                
                <div style="margin-top: 2rem;">
                    <h4 style="color: var(--primary-color);">CSV Format</h4>
                    <p>Your CSV file should have the following columns (in order):</p>
                    <div style="background: #f3f4f6; padding: 1rem; border-radius: 8px; font-family: monospace;">
                        full_name, admission_no, gender, dob, phone, guardian_name, guardian_phone, address, student_type
                    </div>
                    <p style="margin-top: 0.5rem; color: #6b7280; font-size: 0.9rem;">
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
            <h2 style="margin: 0; color: var(--primary-color);">
                <i class="fas fa-bolt"></i> Quick Actions
            </h2>
            <button class="close-btn" onclick="closeModal('quickActionsModal')">×</button>
        </div>
        <div class="modal-body">
            <div id="quickActionsContent">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
    // Mobile Menu Toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarClose = document.getElementById('sidebarClose');

    mobileMenuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
        mobileMenuToggle.classList.toggle('active');
    });

    sidebarClose.addEventListener('click', () => {
        sidebar.classList.remove('active');
        mobileMenuToggle.classList.remove('active');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            }
        }
    });

    // Modal functions
    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
        document.body.style.overflow = 'auto';

        // If edit modal was open, remove edit parameter from URL
        if (modalId === 'editStudentModal') {
            const url = new URL(window.location);
            url.searchParams.delete('edit');
            window.history.replaceState({}, '', url);
        }
    }

    function openEditModal(studentId) {
        window.location.href = `?edit=${studentId}${window.location.search.includes('class_id') ? '&' + window.location.search.split('?')[1] : ''}`;
    }

    <?php if($edit_student): ?>
        openModal('editStudentModal');
    <?php endif; ?>

    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
            document.body.style.overflow = 'auto';

            // Remove edit parameter if edit modal was open
            if (event.target.id === 'editStudentModal') {
                const url = new URL(window.location);
                url.searchParams.delete('edit');
                window.history.replaceState({}, '', url);
            }
        }
    }
    
    // Bulk upload functionality
    function updateFileName(input) {
        const fileName = input.files[0]?.name || 'No file chosen';
        document.getElementById('fileName').textContent = fileName;
    }
    
    // Drag and drop for file upload
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('csvFile');
    
    if (dropZone) {
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                updateFileName(fileInput);
            }
        });
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
                    <textarea name="note" placeholder="Enter note..." 
                              style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 0.5rem;"></textarea>
                    <button type="submit" class="btn btn-primary btn-small" style="width: 100%;">Add Note</button>
                </form>
                
                <!-- Mark Attendance -->
                <form method="POST" style="background: #f9fafb; padding: 1rem; border-radius: 8px;">
                    <input type="hidden" name="action" value="attendance">
                    <input type="hidden" name="student_id" value="${studentId}">
                    <h4 style="margin: 0 0 0.5rem 0;">✓ Mark Attendance</h4>
                    <input type="date" name="date" value="${new Date().toISOString().split('T')[0]}" 
                           style="width: 100%; padding: 0.5rem; margin-bottom: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                    <select name="status" style="width: 100%; padding: 0.5rem; margin-bottom: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="present">✅ Present</option>
                        <option value="absent">❌ Absent</option>
                        <option value="late">⏰ Late</option>
                    </select>
                    <button type="submit" class="btn btn-success btn-small" style="width: 100%;">Record</button>
                </form>
            </div>
            
            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                <a href="edit_student.php?id=${studentId}" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Student
                </a>
                <form method="POST" style="display: inline;" 
                      onsubmit="return confirm('Delete ${studentName}?')">
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
    
    // Auto-hide toasts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.toast').forEach(toast => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        });
    }, 5000);
    
    // Form validation
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--danger-color)';
                    isValid = false;
                    
                    // Add error message
                    if (!field.nextElementSibling?.classList.contains('error-message')) {
                        const errorMsg = document.createElement('div');
                        errorMsg.className = 'error-message';
                        errorMsg.style.color = 'var(--danger-color)';
                        errorMsg.style.fontSize = '0.85rem';
                        errorMsg.style.marginTop = '0.25rem';
                        errorMsg.textContent = 'This field is required';
                        field.parentNode.appendChild(errorMsg);
                    }
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                // Scroll to first error
                const firstError = this.querySelector('[required]:invalid');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    });
    
    // Remove error styling when user starts typing
    document.querySelectorAll('[required]').forEach(field => {
        field.addEventListener('input', function() {
            this.style.borderColor = '';
            const errorMsg = this.parentNode.querySelector('.error-message');
            if (errorMsg) errorMsg.remove();
        });
    });
    
    // Auto-generate admission number
    document.getElementById('add_admission_no')?.addEventListener('focus', function() {
        if (!this.value) {
            const now = new Date();
            const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            this.value = `STU${now.getFullYear()}${(now.getMonth() + 1).toString().padStart(2, '0')}${random}`;
        }
    });

    // Analytics Charts
    document.addEventListener('DOMContentLoaded', function() {
        // Check if Chart.js is available
        if (typeof Chart !== 'undefined') {
            // Class Distribution Chart
            const classChartCanvas = document.getElementById('classChart');
            if (classChartCanvas) {
                const classCtx = classChartCanvas.getContext('2d');
                const classData = {
                    labels: <?php
                        $classLabels = [];
                        $classCounts = [];
                        foreach($assigned_classes as $c) {
                            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ?");
                            $stmt->execute([$c['id']]);
                            $count = $stmt->fetch()['count'];
                            $classLabels[] = $c['class_name'];
                            $classCounts[] = $count;
                        }
                        echo json_encode($classLabels);
                    ?>,
                    datasets: [{
                        label: 'Students',
                        data: <?php echo json_encode($classCounts); ?>,
                        backgroundColor: [
                            'rgba(79, 70, 229, 0.8)',
                            'rgba(6, 182, 212, 0.8)',
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(239, 68, 68, 0.8)',
                            'rgba(139, 92, 246, 0.8)'
                        ],
                        borderColor: [
                            'rgba(79, 70, 229, 1)',
                            'rgba(6, 182, 212, 1)',
                            'rgba(16, 185, 129, 1)',
                            'rgba(245, 158, 11, 1)',
                            'rgba(239, 68, 68, 1)',
                            'rgba(139, 92, 246, 1)'
                        ],
                        borderWidth: 2
                    }]
                };

                new Chart(classCtx, {
                    type: 'bar',
                    data: classData,
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.y + ' students';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }

            // Gender Distribution Chart
            const genderChartCanvas = document.getElementById('genderChart');
            if (genderChartCanvas) {
                const genderCtx = genderChartCanvas.getContext('2d');

                <?php
                // Calculate gender distribution from current students
                $male_count = 0;
                $female_count = 0;
                $other_count = 0;

                if (!empty($students)) {
                    foreach ($students as $s) {
                        $gender = strtolower($s['gender']);
                        if ($gender === 'male') $male_count++;
                        elseif ($gender === 'female') $female_count++;
                        else $other_count++;
                    }
                }
                ?>

                const genderData = {
                    labels: ['Male', 'Female', 'Other'],
                    datasets: [{
                        data: [<?php echo $male_count; ?>, <?php echo $female_count; ?>, <?php echo $other_count; ?>],
                        backgroundColor: [
                            'rgba(59, 130, 246, 0.8)',
                            'rgba(239, 68, 68, 0.8)',
                            'rgba(156, 163, 175, 0.8)'
                        ],
                        borderColor: [
                            'rgba(59, 130, 246, 1)',
                            'rgba(239, 68, 68, 1)',
                            'rgba(156, 163, 175, 1)'
                        ],
                        borderWidth: 2
                    }]
                };

                new Chart(genderCtx, {
                    type: 'doughnut',
                    data: genderData,
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? Math.round((context.parsed / total) * 100) : 0;
                                        return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Enrollment Trends Chart (Last 6 Months)
            const enrollmentChartCanvas = document.getElementById('enrollmentChart');
            if (enrollmentChartCanvas) {
                const enrollmentCtx = enrollmentChartCanvas.getContext('2d');

                <?php
                // Calculate enrollment trends for last 6 months
                $enrollment_data = [];
                $month_labels = [];

                for ($i = 5; $i >= 0; $i--) {
                    $date = date('Y-m-01', strtotime("-$i months"));
                    $month_name = date('M Y', strtotime($date));

                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count
                        FROM students s
                        WHERE DATE_FORMAT(s.enrollment_date, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
                        AND s.class_id IN (
                            SELECT DISTINCT c.id
                            FROM classes c
                            WHERE EXISTS (
                                SELECT 1 FROM subject_assignments sa WHERE sa.class_id = c.id AND sa.teacher_id = ?
                            ) OR EXISTS (
                                SELECT 1 FROM class_teachers ct WHERE ct.class_id = c.id AND ct.teacher_id = ?
                            )
                        )
                    ");
                    $stmt->execute([$date, $teacher_id, $teacher_id]);
                    $count = $stmt->fetch()['count'];

                    $month_labels[] = $month_name;
                    $enrollment_data[] = $count;
                }
                ?>

                const enrollmentData = {
                    labels: <?php echo json_encode($month_labels); ?>,
                    datasets: [{
                        label: 'New Enrollments',
                        data: <?php echo json_encode($enrollment_data); ?>,
                        backgroundColor: 'rgba(16, 185, 129, 0.8)',
                        borderColor: 'rgba(16, 185, 129, 1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                };

                new Chart(enrollmentCtx, {
                    type: 'line',
                    data: enrollmentData,
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.y + ' new enrollments';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }

            // Student Type Distribution Chart
            const typeChartCanvas = document.getElementById('typeChart');
            if (typeChartCanvas) {
                const typeCtx = typeChartCanvas.getContext('2d');

                <?php
                // Calculate student type distribution
                $fresh_count = 0;
                $transfer_count = 0;

                if (!empty($all_students)) {
                    foreach ($all_students as $s) {
                        if ($s['student_type'] === 'fresh') $fresh_count++;
                        elseif ($s['student_type'] === 'transfer') $transfer_count++;
                    }
                }
                ?>

                const typeData = {
                    labels: ['Fresh Students', 'Transfer Students'],
                    datasets: [{
                        data: [<?php echo $fresh_count; ?>, <?php echo $transfer_count; ?>],
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(245, 158, 11, 0.8)'
                        ],
                        borderColor: [
                            'rgba(16, 185, 129, 1)',
                            'rgba(245, 158, 11, 1)'
                        ],
                        borderWidth: 2
                    }]
                };

                new Chart(typeCtx, {
                    type: 'pie',
                    data: typeData,
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? Math.round((context.parsed / total) * 100) : 0;
                                        return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        } else {
            // Fallback for when Chart.js is not available
            console.log('Chart.js not loaded, analytics charts disabled');
            const chartContainers = document.querySelectorAll('.chart-card canvas');
            chartContainers.forEach(canvas => {
                const parent = canvas.parentElement;
                parent.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--gray-500);"><i class="fas fa-chart-bar" style="font-size: 3rem; margin-bottom: 1rem;"></i><p>Analytics charts require Chart.js library</p></div>';
            });
        }
    });

    // Smooth scroll for internal links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // Add active class on scroll for header
    window.addEventListener('scroll', () => {
        const header = document.querySelector('.dashboard-header');
        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });

    // Animate cards on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Observe dashboard cards
    document.querySelectorAll('.card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(card);
    });

    // Observe quick action cards
    document.querySelectorAll('.quick-action-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(card);
    });

    // Add hover effects for cards
    document.querySelectorAll('.card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });

        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });

    // Quick action cards hover effect
    document.querySelectorAll('.quick-action-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.05)';
        });

        card.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });

    // Auto-refresh dashboard data every 5 minutes
    setInterval(() => {
        // You can add AJAX calls here to refresh dynamic data
        console.log('Dashboard data refresh check...');
    }, 300000);

    // Refresh Charts Function
    function refreshCharts() {
        // Show loading state
        const refreshBtn = document.querySelector('.chart-controls .btn');
        const originalText = refreshBtn.innerHTML;
        refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
        refreshBtn.disabled = true;

        // Simulate refresh (in real app, this would fetch new data)
        setTimeout(() => {
            // Re-render charts with potentially updated data
            console.log('Charts refreshed');

            // Reset button
            refreshBtn.innerHTML = originalText;
            refreshBtn.disabled = false;

            // Show success feedback
            showToast('Analytics refreshed successfully', 'success');
        }, 1500);
    }

    // Toast notification function
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        `;

        // Add toast styles if not exist
        if (!document.querySelector('#toast-styles')) {
            const styles = document.createElement('style');
            styles.id = 'toast-styles';
            styles.textContent = `
                .toast {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: var(--white);
                    border-radius: var(--border-radius);
                    padding: 1rem 1.5rem;
                    box-shadow: var(--shadow-lg);
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                    z-index: 10000;
                    transform: translateX(400px);
                    opacity: 0;
                    transition: all 0.3s ease;
                    border-left: 4px solid var(--primary-color);
                }
                .toast-success { border-left-color: var(--success-color); }
                .toast-error { border-left-color: var(--danger-color); }
                .toast-info { border-left-color: var(--info-color); }
                .toast.show {
                    transform: translateX(0);
                    opacity: 1;
                }
            `;
            document.head.appendChild(styles);
        }

        document.body.appendChild(toast);

        // Trigger animation
        setTimeout(() => toast.classList.add('show'), 100);

        // Auto remove after 5 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }

    // Enhanced form validation and submission
    document.addEventListener('DOMContentLoaded', function() {
        // Add student form enhancements
        const addStudentForm = document.getElementById('quickAddStudentForm');
        if (addStudentForm) {
            // Auto-generate admission number when class is selected
            const classSelect = addStudentForm.querySelector('[name="class_id"]');
            const admissionInput = addStudentForm.querySelector('[name="admission_no"]');

            classSelect.addEventListener('change', function() {
                if (!admissionInput.value && this.value) {
                    const now = new Date();
                    const year = now.getFullYear();
                    const month = String(now.getMonth() + 1).padStart(2, '0');
                    const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                    admissionInput.value = `STU${year}${month}${random}`;
                }
            });

            // Real-time validation
            const requiredFields = addStudentForm.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                field.addEventListener('blur', function() {
                    validateField(this);
                });

                field.addEventListener('input', function() {
                    if (this.classList.contains('invalid')) {
                        validateField(this);
                    }
                });
            });

            // Enhanced form submission
            addStudentForm.addEventListener('submit', function(e) {
                e.preventDefault();

                // Validate all required fields
                let isValid = true;
                requiredFields.forEach(field => {
                    if (!validateField(field)) {
                        isValid = false;
                    }
                });

                if (!isValid) {
                    showToast('Please fill in all required fields correctly', 'error');
                    return;
                }

                // Show loading state
                const submitBtn = this.querySelector('.btn-gold');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding Student...';
                submitBtn.disabled = true;

                // Submit form
                const formData = new FormData(this);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(result => {
                    if (result.includes('Student added successfully')) {
                        showToast('Student added successfully!', 'success');
                        this.reset();
                        // Refresh page after short delay to show new student
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showToast('Error adding student. Please try again.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Network error. Please try again.', 'error');
                })
                .finally(() => {
                    // Reset button
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
            });
        }

        // Bulk upload form enhancements
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const classId = this.querySelector('[name="class_id"]').value;
                const csvFile = this.querySelector('[name="csv_file"]').files[0];

                if (!classId) {
                    showToast('Please select a class', 'error');
                    return;
                }

                if (!csvFile) {
                    showToast('Please select a CSV file', 'error');
                    return;
                }

                if (!csvFile.name.toLowerCase().endsWith('.csv')) {
                    showToast('Please select a valid CSV file', 'error');
                    return;
                }

                // Show loading state
                const submitBtn = this.querySelector('.btn-success');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                submitBtn.disabled = true;

                // Submit form
                const formData = new FormData(this);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(result => {
                    if (result.includes('Bulk upload completed')) {
                        showToast('Bulk upload completed successfully!', 'success');
                        closeModal('bulkUploadModal');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showToast('Error during bulk upload. Please check your file format.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Network error during upload. Please try again.', 'error');
                })
                .finally(() => {
                    // Reset button
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
            });
        }
    });

    // Field validation function
    function validateField(field) {
        const value = field.value.trim();
        const fieldName = field.name;

        // Remove existing error messages
        const existingError = field.parentNode.querySelector('.field-error');
        if (existingError) {
            existingError.remove();
        }

        // Remove validation classes
        field.classList.remove('invalid', 'valid');

        let isValid = true;
        let errorMessage = '';

        // Required field validation
        if (field.hasAttribute('required') && !value) {
            isValid = false;
            errorMessage = 'This field is required';
        }
        // Email validation
        else if (field.type === 'email' && value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(value)) {
                isValid = false;
                errorMessage = 'Please enter a valid email address';
            }
        }
        // Phone validation
        else if (fieldName === 'phone' && value) {
            const phoneRegex = /^[\+]?[0-9\-\(\)\s]+$/;
            if (!phoneRegex.test(value)) {
                isValid = false;
                errorMessage = 'Please enter a valid phone number';
            }
        }
        // Admission number validation
        else if (fieldName === 'admission_no' && value) {
            if (value.length < 3) {
                isValid = false;
                errorMessage = 'Admission number must be at least 3 characters';
            }
        }

        if (!isValid) {
            field.classList.add('invalid');

            // Add error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'field-error';
            errorDiv.style.color = 'var(--danger-color)';
            errorDiv.style.fontSize = '0.8rem';
            errorDiv.style.marginTop = '0.25rem';
            errorDiv.textContent = errorMessage;
            field.parentNode.appendChild(errorDiv);
        } else if (value) {
            field.classList.add('valid');
        }

        return isValid;
    }
</script>
</body>
</html>
