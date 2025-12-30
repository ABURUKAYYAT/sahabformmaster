<?php
session_start();
require_once '../config/db.php';

// Only principal/admin
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$errors = [];
$success = '';

// Handle file uploads
function uploadFile($file, $type) {
    $uploadDir = '../uploads/students/' . $type . '/';
    
    // Create directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $fileName = time() . '_' . basename($file['name']);
    $targetPath = $uploadDir . $fileName;
    
    // Allowed file types
    $allowedTypes = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
        'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    
    // Max file size 5MB
    $maxSize = 5 * 1024 * 1024;
    
    if (!in_array($file['type'], $allowedTypes)) {
        return ['error' => 'Invalid file type. Only JPG, PNG, GIF, PDF, DOC, DOCX allowed.'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['error' => 'File size too large. Maximum 5MB allowed.'];
    }
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => $targetPath];
    }
    
    return ['error' => 'File upload failed.'];
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        // Collect form data
        $name = trim($_POST['full_name'] ?? '');
        $ad = trim($_POST['admission_no'] ?? '');
        $class_id = intval($_POST['class_id'] ?? 0);
        $student_type = trim($_POST['student_type'] ?? 'fresh');
        
        // Basic validation
        if ($name === '' || $ad === '' || $class_id <= 0) {
            $errors[] = 'Full name, admission number and class are required.';
        }
        
        // Check admission number uniqueness
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ?");
        $stmt->execute([$ad]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Admission number already exists.';
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // Handle file uploads
                $birth_certificate = null;
                $passport_photo = null;
                $transfer_letter = null;
                
                if (!empty($_FILES['birth_certificate']['name'])) {
                    $upload = uploadFile($_FILES['birth_certificate'], 'birth_certificates');
                    if (isset($upload['success'])) {
                        $birth_certificate = $upload['success'];
                    } else {
                        $errors[] = $upload['error'] ?? 'Birth certificate upload failed.';
                    }
                }
                
                if (!empty($_FILES['passport_photo']['name'])) {
                    $upload = uploadFile($_FILES['passport_photo'], 'photos');
                    if (isset($upload['success'])) {
                        $passport_photo = $upload['success'];
                    } else {
                        $errors[] = $upload['error'] ?? 'Passport photo upload failed.';
                    }
                }
                
                if ($student_type === 'transfer' && !empty($_FILES['transfer_letter']['name'])) {
                    $upload = uploadFile($_FILES['transfer_letter'], 'transfer_letters');
                    if (isset($upload['success'])) {
                        $transfer_letter = $upload['success'];
                    } else {
                        $errors[] = $upload['error'] ?? 'Transfer letter upload failed.';
                    }
                }
                
                if (empty($errors)) {
                    // Insert student
                    $stmt = $pdo->prepare("
                        INSERT INTO students (
                            full_name, admission_no, class_id, phone, address, dob, gender, 
                            guardian_name, guardian_phone, guardian_email, guardian_address,
                            guardian_occupation, guardian_relation, enrollment_date,
                            student_type, birth_certificate, passport_photo, transfer_letter,
                            previous_school, nationality, religion, blood_group,
                            medical_conditions, allergies, emergency_contact_name,
                            emergency_contact_phone, emergency_contact_relation,
                            registration_date, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                        )
                    ");
                    
                    $stmt->execute([
                        $name,
                        $ad,
                        $class_id,
                        $_POST['phone'] ?? null,
                        $_POST['address'] ?? null,
                        $_POST['dob'] ?: null,
                        $_POST['gender'] ?? null,
                        $_POST['guardian_name'] ?? null,
                        $_POST['guardian_phone'] ?? null,
                        $_POST['guardian_email'] ?? null,
                        $_POST['guardian_address'] ?? null,
                        $_POST['guardian_occupation'] ?? null,
                        $_POST['guardian_relation'] ?? null,
                        $_POST['enrollment_date'] ?: null,
                        $student_type,
                        $birth_certificate,
                        $passport_photo,
                        $transfer_letter,
                        $_POST['previous_school'] ?? null,
                        $_POST['nationality'] ?? null,
                        $_POST['religion'] ?? null,
                        $_POST['blood_group'] ?? null,
                        $_POST['medical_conditions'] ?? null,
                        $_POST['allergies'] ?? null,
                        $_POST['emergency_contact_name'] ?? null,
                        $_POST['emergency_contact_phone'] ?? null,
                        $_POST['emergency_contact_relation'] ?? null
                    ]);
                    
                    $student_id = $pdo->lastInsertId();
                    
                    // Handle additional documents
                    if (!empty($_FILES['additional_docs']['name'][0])) {
                        for ($i = 0; $i < count($_FILES['additional_docs']['name']); $i++) {
                            if ($_FILES['additional_docs']['error'][$i] === UPLOAD_ERR_OK) {
                                $file = [
                                    'name' => $_FILES['additional_docs']['name'][$i],
                                    'type' => $_FILES['additional_docs']['type'][$i],
                                    'tmp_name' => $_FILES['additional_docs']['tmp_name'][$i],
                                    'size' => $_FILES['additional_docs']['size'][$i]
                                ];
                                
                                $upload = uploadFile($file, 'additional_docs');
                                if (isset($upload['success'])) {
                                    $doc_stmt = $pdo->prepare("
                                        INSERT INTO student_documents (student_id, document_type, document_name, file_path, uploaded_by)
                                        VALUES (?, ?, ?, ?, ?)
                                    ");
                                    $doc_stmt->execute([
                                        $student_id,
                                        $_POST['doc_type'][$i] ?? 'other',
                                        $file['name'],
                                        $upload['success'],
                                        $_SESSION['user_id']
                                    ]);
                                }
                            }
                        }
                    }
                    
                    $pdo->commit();
                    $success = 'Student registered successfully!';
                } else {
                    $pdo->rollBack();
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
    
    // Handle delete action
    elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->beginTransaction();
                
                // Delete related records first
                $pdo->prepare("DELETE FROM student_documents WHERE student_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM student_academic_history WHERE student_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM student_notes WHERE student_id = ?")->execute([$id]);
                
                // Delete the student
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
                $stmt->execute([$id]);
                
                if ($stmt->rowCount() > 0) {
                    $success = 'Student deleted successfully.';
                }
                
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Invalid student ID.';
        }
    }
}

// Handle search and filter
$search = $_GET['search'] ?? '';
$admission_no = $_GET['admission_no'] ?? '';
$class_filter = $_GET['class_filter'] ?? '';
$student_type_filter = $_GET['student_type_filter'] ?? '';

// Build query with filters
$query = "
    SELECT s.*, c.class_name 
    FROM students s 
    LEFT JOIN classes c ON s.class_id = c.id 
    WHERE 1=1
";

$params = [];
$types = '';

if ($search) {
    $query .= " AND (s.full_name LIKE ? OR s.guardian_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if ($admission_no) {
    $query .= " AND s.admission_no LIKE ?";
    $params[] = "%$admission_no%";
    $types .= 's';
}

if ($class_filter) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_filter;
    $types .= 'i';
}

if ($student_type_filter) {
    $query .= " AND s.student_type = ?";
    $params[] = $student_type_filter;
    $types .= 's';
}

$query .= " ORDER BY s.created_at DESC LIMIT 10";

// Fetch students with filters
if (!empty($params)) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $students = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch classes for dropdown
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

// Get total count for statistics
$total_students = $pdo->query("SELECT COUNT(*) as total FROM students")->fetch(PDO::FETCH_ASSOC)['total'];
$fresh_students = $pdo->query("SELECT COUNT(*) as total FROM students WHERE student_type = 'fresh'")->fetch(PDO::FETCH_ASSOC)['total'];
$transfer_students = $pdo->query("SELECT COUNT(*) as total FROM students WHERE student_type = 'transfer'")->fetch(PDO::FETCH_ASSOC)['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management System</title>
    <link rel="stylesheet" href="../assets/css/admin-students.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Additional styles for enhanced features */
        :root {
            --primary: #3498db;
            --secondary: #2c3e50;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --light: #ecf0f1;
            --dark: #2c3e50;
        }
        
        .hidden {
            display: none !important;
        }
        
        .dashboard-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--secondary);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 0;
            transition: all 0.3s;
        }
        
        .dashboard-link:hover {
            background: var(--dark);
            transform: translateY(-2px);
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 5px solid var(--primary);
        }
        
        .stat-card i {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 10px;
        }
        
        .stat-card h3 {
            margin: 0;
            color: var(--dark);
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        
        .stat-card .count {
            font-size: 2rem;
            font-weight: bold;
            color: var(--secondary);
            margin: 10px 0;
        }
        
        .toggle-form-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 10px 0;
            font-size: 1rem;
        }
        
        .file-upload-group {
            margin: 15px 0;
        }
        
        .file-upload-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        
        .file-upload-group .file-input {
            display: block;
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .file-preview {
            margin-top: 5px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .additional-docs-container {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        
        .additional-doc-item {
            margin-bottom: 10px;
            padding: 10px;
            background: white;
            border-radius: 4px;
            border-left: 3px solid var(--primary);
        }
        
        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .form-inline {
                flex-direction: column;
            }
            
            .form-inline input,
            .form-inline select,
            .form-inline button {
                width: 100%;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="header-content">
            <h1><i class="fas fa-user-graduate"></i> Student Management System</h1>
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></span>
                <span class="badge">Principal</span>
            </div>
        </div>
    </header>

    <main class="container">
        <!-- Dashboard Link -->
        <a href="../admin/index.php" class="dashboard-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <!-- Statistics Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3>Total Students</h3>
                <div class="count"><?php echo $total_students; ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-user-plus"></i>
                <h3>Fresh Students</h3>
                <div class="count"><?php echo $fresh_students; ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-exchange-alt"></i>
                <h3>Transfer Students</h3>
                <div class="count"><?php echo $transfer_students; ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-chart-line"></i>
                <h3>This Month</h3>
                <div class="count">
                    <?php 
                    $month_count = $pdo->query("SELECT COUNT(*) as total FROM students WHERE MONTH(created_at) = MONTH(CURRENT_DATE())")->fetch(PDO::FETCH_ASSOC)['total'];
                    echo $month_count;
                    ?>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if($errors): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php foreach($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Registration Form (Collapsible) -->
        <section class="panel">
            <div class="panel-header">
                <h2><i class="fas fa-user-plus"></i> Register New Student</h2>
                <button class="toggle-form-btn" onclick="toggleRegistrationForm()">
                    <i class="fas fa-plus"></i> Show/Hide Form
                </button>
            </div>
            
            <form id="registrationForm" method="POST" enctype="multipart/form-data" class="hidden">
                <input type="hidden" name="action" value="create">
                
                <div class="form-section">
                    <h3><i class="fas fa-user-circle"></i> Basic Information</h3>
                    <div class="form-inline">
                        <input name="full_name" placeholder="Full Name *" required>
                        <input name="admission_no" placeholder="Admission Number *" required>
                        
                        <select name="student_type" required onchange="toggleTransferFields()">
                            <option value="">Select Student Type *</option>
                            <option value="fresh">Fresh Student</option>
                            <option value="transfer">Transfer Student</option>
                        </select>
                        
                        <select name="class_id" required>
                            <option value="">Select Class *</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?php echo intval($c['id']); ?>">
                                    <?php echo htmlspecialchars($c['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="date" name="dob" placeholder="Date of Birth *" required>
                        
                        <select name="gender" required>
                            <option value="">Gender *</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <!-- Transfer Student Fields (Initially Hidden) -->
                <div id="transferFields" class="form-section hidden">
                    <h3><i class="fas fa-exchange-alt"></i> Transfer Information</h3>
                    <div class="form-inline">
                        <input name="previous_school" placeholder="Previous School Name">
                        <input name="transfer_letter" type="file" class="file-input" accept=".pdf,.doc,.docx">
                    </div>
                </div>

                <!-- Guardian Information -->
                <div class="form-section">
                    <h3><i class="fas fa-users"></i> Guardian Information</h3>
                    <div class="form-inline">
                        <input name="guardian_name" placeholder="Guardian Name *" required>
                        <input name="guardian_phone" placeholder="Guardian Phone *" required>
                        <input name="guardian_email" type="email" placeholder="Guardian Email">
                        <input name="guardian_relation" placeholder="Relationship *" required>
                        <input name="guardian_address" placeholder="Guardian Address">
                        <input name="guardian_occupation" placeholder="Guardian Occupation">
                    </div>
                </div>

                <!-- Contact & Additional Information -->
                <div class="form-section">
                    <h3><i class="fas fa-address-card"></i> Contact & Additional Information</h3>
                    <div class="form-inline">
                        <input name="phone" placeholder="Student Phone">
                        <input name="address" placeholder="Residential Address">
                        <input type="date" name="enrollment_date" placeholder="Enrollment Date">
                        
                        <select name="nationality">
                            <option value="">Nationality</option>
                            <option value="Nigerian">Nigerian</option>
                            <option value="Other">Other</option>
                        </select>
                        
                        <select name="religion">
                            <option value="">Religion</option>
                            <option value="Islam">Islam</option>
                            <option value="Christianity">Christianity</option>
                            <option value="Other">Other</option>
                        </select>
                        
                        <select name="blood_group">
                            <option value="">Blood Group</option>
                            <option value="A+">A+</option>
                            <option value="A-">A-</option>
                            <option value="B+">B+</option>
                            <option value="B-">B-</option>
                            <option value="AB+">AB+</option>
                            <option value="AB-">AB-</option>
                            <option value="O+">O+</option>
                            <option value="O-">O-</option>
                        </select>
                    </div>
                    
                    <div class="form-inline">
                        <textarea name="medical_conditions" placeholder="Medical Conditions" rows="2"></textarea>
                        <textarea name="allergies" placeholder="Allergies" rows="2"></textarea>
                    </div>
                </div>

                <!-- Emergency Contact -->
                <div class="form-section">
                    <h3><i class="fas fa-phone-alt"></i> Emergency Contact</h3>
                    <div class="form-inline">
                        <input name="emergency_contact_name" placeholder="Emergency Contact Name">
                        <input name="emergency_contact_phone" placeholder="Emergency Contact Phone">
                        <input name="emergency_contact_relation" placeholder="Relationship">
                    </div>
                </div>

                <!-- File Uploads -->
                <div class="form-section">
                    <h3><i class="fas fa-file-upload"></i> Required Documents</h3>
                    
                    <div class="file-upload-group">
                        <label>Birth Certificate *</label>
                        <input type="file" name="birth_certificate" class="file-input" accept=".pdf,.jpg,.jpeg,.png" required>
                        <div class="file-preview">Upload PDF, JPG, or PNG file (Max 5MB)</div>
                    </div>
                    
                    <div class="file-upload-group">
                        <label>Passport Photograph *</label>
                        <input type="file" name="passport_photo" class="file-input" accept=".jpg,.jpeg,.png" required>
                        <div class="file-preview">Upload JPG or PNG file (Max 5MB)</div>
                    </div>
                    
                    <!-- Additional Documents (Dynamic) -->
                    <div class="additional-docs-container">
                        <h4><i class="fas fa-folder-plus"></i> Additional Documents (Optional)</h4>
                        <div id="additionalDocs">
                            <div class="additional-doc-item">
                                <div class="form-inline">
                                    <select name="doc_type[]" class="small">
                                        <option value="report_card">Report Card</option>
                                        <option value="immunization">Immunization Record</option>
                                        <option value="certificate">Certificate</option>
                                        <option value="other">Other</option>
                                    </select>
                                    <input type="file" name="additional_docs[]" class="file-input small">
                                    <button type="button" class="btn small danger" onclick="removeDocField(this)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn small" onclick="addDocField()">
                            <i class="fas fa-plus"></i> Add Another Document
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="form-actions">
                    <button type="submit" class="btn primary">
                        <i class="fas fa-save"></i> Register Student
                    </button>
                    <button type="reset" class="btn secondary">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                </div>
            </form>
        </section>

        <!-- Search and Filter Section -->
        <section class="panel">
            <h2><i class="fas fa-search"></i> Search & Filter Students</h2>
            <form method="GET" class="form-inline">
                <input type="text" name="search" placeholder="Search by Name or Guardian Name" 
                       value="<?php echo htmlspecialchars($search); ?>">
                
                <input type="text" name="admission_no" placeholder="Admission Number" 
                       value="<?php echo htmlspecialchars($admission_no); ?>">
                
                <select name="class_filter">
                    <option value="">All Classes</option>
                    <?php foreach($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" 
                            <?php echo ($class_filter == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['class_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="student_type_filter">
                    <option value="">All Types</option>
                    <option value="fresh" <?php echo ($student_type_filter == 'fresh') ? 'selected' : ''; ?>>Fresh</option>
                    <option value="transfer" <?php echo ($student_type_filter == 'transfer') ? 'selected' : ''; ?>>Transfer</option>
                </select>
                
                <button type="submit" class="btn primary">
                    <i class="fas fa-search"></i> Search
                </button>
                
                <a href="students.php" class="btn secondary">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </form>
        </section>

        <!-- Students List -->
        <section class="panel">
            <div class="panel-header">
                <h2><i class="fas fa-list"></i> Recent Students (Last 10)</h2>
                <div class="export-buttons">
                    <button class="btn small" onclick="exportToCSV()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button class="btn small" onclick="printTable()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <div class="table-wrap">
                <table class="table" id="studentsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Photo</th>
                            <th>Name</th>
                            <th>Admission No</th>
                            <th>Class</th>
                            <th>Type</th>
                            <th>Guardian</th>
                            <th>Phone</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($students)): ?>
                            <tr>
                                <td colspan="9" class="text-center">
                                    <i class="fas fa-user-slash"></i> No students found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($students as $index => $s): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php if($s['passport_photo']): ?>
                                            <img height="40px" width="40px" style="border-radius: 50%; border: 4px solid gold" src="<?php echo htmlspecialchars($s['passport_photo']); ?>" 
                                                 alt="Photo" class="student-photo">
                                        <?php else: ?>
                                            <div class="no-photo">
                                                <i class="fas fa-user-circle"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($s['admission_no']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($s['class_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $s['student_type'] == 'fresh' ? 'badge-success' : 'badge-warning'; ?>">
                                            <?php echo ucfirst($s['student_type'] ?? 'fresh'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($s['guardian_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($s['guardian_phone'] ?? $s['phone'] ?? 'N/A'); ?></td>
                                    <td class="actions">
                                        <a href="student_details.php?id=<?php echo $s['id']; ?>" 
                                           class="btn small primary" title="View Details">
                                            <i class="fas fa-eye"></i> Details
                                        </a>
                                        
                                        <button class="btn small warning" 
                                                onclick="editStudent(<?php echo $s['id']; ?>)" 
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <form method="POST" class="inline-form" 
                                              onsubmit="return confirm('Are you sure you want to delete this student?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                            <button type="submit" class="btn small danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div class="pagination">
                <button class="btn small" disabled>Previous</button>
                <span class="page-info">Showing 1-10 of <?php echo $total_students; ?> students</span>
                <a href="students.php?page=2" class="btn small">Next</a>
            </div>
        </section>
    </main>

    <script>
        // Toggle registration form
        function toggleRegistrationForm() {
            const form = document.getElementById('registrationForm');
            form.classList.toggle('hidden');
        }
        
        // Toggle transfer fields
        function toggleTransferFields() {
            const studentType = document.querySelector('select[name="student_type"]').value;
            const transferFields = document.getElementById('transferFields');
            
            if (studentType === 'transfer') {
                transferFields.classList.remove('hidden');
            } else {
                transferFields.classList.add('hidden');
            }
        }
        
        // Add additional document field
        function addDocField() {
            const container = document.getElementById('additionalDocs');
            const newField = document.createElement('div');
            newField.className = 'additional-doc-item';
            newField.innerHTML = `
                <div class="form-inline">
                    <select name="doc_type[]" class="small">
                        <option value="report_card">Report Card</option>
                        <option value="immunization">Immunization Record</option>
                        <option value="certificate">Certificate</option>
                        <option value="other">Other</option>
                    </select>
                    <input type="file" name="additional_docs[]" class="file-input small">
                    <button type="button" class="btn small danger" onclick="removeDocField(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            container.appendChild(newField);
        }
        
        // Remove document field
        function removeDocField(button) {
            button.closest('.additional-doc-item').remove();
        }
        
        // Edit student function (simplified - would need proper implementation)
        function editStudent(id) {
            // This would typically fetch student data via AJAX and populate an edit form
            alert('Edit functionality for student ID: ' + id);
            // Implement AJAX call to fetch student data and populate edit form
        }
        
        // Export to CSV
        function exportToCSV() {
            // This is a simplified version - in production, you'd generate a proper CSV
            const table = document.getElementById('studentsTable');
            let csv = [];
            
            // Get headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                headers.push(th.textContent);
            });
            csv.push(headers.join(','));
            
            // Get rows
            table.querySelectorAll('tbody tr').forEach(row => {
                const rowData = [];
                row.querySelectorAll('td').forEach((cell, index) => {
                    // Skip the first column (index) and photo column
                    if (index !== 0 && index !== 1) {
                        rowData.push(cell.textContent.replace(/,/g, ''));
                    }
                });
                csv.push(rowData.join(','));
            });
            
            // Download CSV
            const csvContent = 'data:text/csv;charset=utf-8,' + csv.join('\n');
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement('a');
            link.setAttribute('href', encodedUri);
            link.setAttribute('download', 'students.csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Print table
        function printTable() {
            const printContent = document.getElementById('studentsTable').outerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = `
                <html>
                    <head>
                        <title>Student List</title>
                        <style>
                            table { width: 100%; border-collapse: collapse; }
                            th, td { border: 1px solid #000; padding: 8px; text-align: left; }
                            th { background-color: #f2f2f2; }
                        </style>
                    </head>
                    <body>${printContent}</body>
                </html>
            `;
            
            window.print();
            document.body.innerHTML = originalContent;
        }
        
        // File upload preview
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function(e) {
                const preview = this.parentElement.querySelector('.file-preview');
                if (this.files.length > 0) {
                    preview.textContent = `Selected: ${this.files[0].name}`;
                } else {
                    preview.textContent = 'No file selected';
                }
            });
        });
    </script>
</body>
</html>