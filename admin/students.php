<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth-check.php';

// Only principal/admin with school authentication
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();

$errors = [];
$success = '';

// Handle file uploads
function uploadFile($file, $type) {
    require_once '../includes/security.php';

    $uploadDir = '../uploads/students/' . $type . '/';

    // Create directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

                // Use centralized security validation - include both images and documents
                $validation = Security::validateFileUpload($file, array_merge(
                    SecurityConfig::ALLOWED_IMAGE_TYPES, 
                    SecurityConfig::ALLOWED_DOCUMENT_TYPES
                ));

    if (!$validation['valid']) {
        return ['error' => $validation['error']];
    }

    $fileName = time() . '_' . basename($file['name']);
    $targetPath = $uploadDir . $fileName;

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
        
        // Check admission number uniqueness within the school
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ? AND school_id = ?");
        $stmt->execute([$ad, $current_school_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Admission number already exists in your school.';
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
                // Verify class belongs to user's school
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE id = ? AND school_id = ?");
                $stmt->execute([$class_id, $current_school_id]);
                if ($stmt->fetchColumn() == 0) {
                    $errors[] = "Selected class is not available for your school.";
                } else {
                    // Insert student
                    $stmt = $pdo->prepare("
                        INSERT INTO students (
                            full_name, admission_no, class_id, school_id, phone, address, dob, gender,
                            guardian_name, guardian_phone, guardian_email, guardian_address,
                            guardian_occupation, guardian_relation, enrollment_date,
                            student_type, birth_certificate, passport_photo, transfer_letter,
                            previous_school, nationality, religion, blood_group,
                            medical_conditions, allergies, emergency_contact_name,
                            emergency_contact_phone, emergency_contact_relation,
                            registration_date, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                        )
                    ");

                    $stmt->execute([
                        $name,
                        $ad,
                        $class_id,
                        $current_school_id,
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
                }
                    
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

                    // Log student registration
                    log_admin_action('create_student', 'student', $student_id, "Registered student: {$name} (Admission: {$ad})");

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
            // Verify student belongs to user's school
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $current_school_id]);
            if ($stmt->fetchColumn() == 0) {
                $errors[] = "Student not found or access denied.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // Delete related records first
                    $pdo->prepare("DELETE FROM student_documents WHERE student_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM student_academic_history WHERE student_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM student_notes WHERE student_id = ?")->execute([$id]);

                    // Delete the student
                    $stmt = $pdo->prepare("DELETE FROM students WHERE id = ? AND school_id = ?");
                    $stmt->execute([$id, $current_school_id]);

                    if ($stmt->rowCount() > 0) {
                        $success = 'Student deleted successfully.';

                        // Get student details for logging
                        $stmt = $pdo->prepare("SELECT full_name, admission_no FROM students WHERE id = ?");
                        $stmt->execute([$id]);
                        $deleted_student = $stmt->fetch(PDO::FETCH_ASSOC);

                        // Log student deletion
                        log_admin_action('delete_student', 'student', $id, "Deleted student: {$deleted_student['full_name']} (Admission: {$deleted_student['admission_no']})");
                    }

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Delete failed: ' . $e->getMessage();
                }
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
    WHERE s.school_id = ?
";

$params = [$current_school_id];

if ($search) {
    $query .= " AND (s.full_name LIKE ? OR s.guardian_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($admission_no) {
    $query .= " AND s.admission_no LIKE ?";
    $params[] = "%$admission_no%";
}

if ($class_filter) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_filter;
}

if ($student_type_filter) {
    $query .= " AND s.student_type = ?";
    $params[] = $student_type_filter;
}

$query .= " ORDER BY s.created_at DESC LIMIT 10";

// Fetch students with filters
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes for dropdown (school-filtered)
$classes = get_school_classes($pdo, $current_school_id);

// Get total count for statistics (school-filtered)
$total_students = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ?")->execute([$current_school_id]);
$total_students = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ?");
$total_students->execute([$current_school_id]);
$total_students = $total_students->fetch(PDO::FETCH_ASSOC)['total'];

$fresh_students = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE student_type = 'fresh' AND school_id = ?");
$fresh_students->execute([$current_school_id]);
$fresh_students = $fresh_students->fetch(PDO::FETCH_ASSOC)['total'];

$transfer_students = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE student_type = 'transfer' AND school_id = ?");
$transfer_students->execute([$current_school_id]);
$transfer_students = $transfer_students->fetch(PDO::FETCH_ASSOC)['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="pwa-sw" content="../sw.js">
    <title>Students Management | SahabFormMaster</title>
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="stylesheet" href="../assets/css/offline-status.css">
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

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>

            <!-- Principal Info and Logout -->
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon">🚪</span>
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
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📰</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link">
                            <span class="nav-icon">📔</span>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link active">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students Registration</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students-evaluations.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Students Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_class.php" class="nav-link">
                            <span class="nav-icon">🎓</span>
                            <span class="nav-text">Manage Classes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage-school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                                                            <li class="nav-item">
                        <a href="support.php" class="nav-link">
                            <span class="nav-icon">🛟</span>
                            <span class="nav-text">Support</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subscription.php" class="nav-link">
                            <span class="nav-icon">💳</span>
                            <span class="nav-text">Subscription</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <span class="nav-icon">📖</span>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">👤</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🚶</span>
                            <span class="nav-text">Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_timebook.php" class="nav-link">
                            <span class="nav-icon">⏰</span>
                            <span class="nav-text">Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_attendance.php" class="nav-link">
                            <span class="nav-icon">📋</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments_dashboard.php" class="nav-link">
                            <span class="nav-icon">💰</span>
                            <span class="nav-text">School Fees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="sessions.php" class="nav-link">
                            <span class="nav-icon">📅</span>
                            <span class="nav-text">School Sessions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_calendar.php" class="nav-link">
                            <span class="nav-icon">🗓️</span>
                            <span class="nav-text">School Calendar</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="applicants.php" class="nav-link">
                            <span class="nav-icon">📄</span>
                            <span class="nav-text">Applicants</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>👥 Student Management</h2>
                    <p>Manage student registrations, records, and information</p>
                </div>
            </div>
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
                <p class="stat-description">All registered students</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-user-plus"></i>
                <h3>Fresh Students</h3>
                <div class="count"><?php echo $fresh_students; ?></div>
                <p class="stat-description">New admissions</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-exchange-alt"></i>
                <h3>Transfer Students</h3>
                <div class="count"><?php echo $transfer_students; ?></div>
                <p class="stat-description">Transferred in</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-chart-line"></i>
                <h3>This Month</h3>
                <div class="count">
                    <?php
                    $month_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND school_id = ?");
                    $month_stmt->execute([$current_school_id]);
                    $month_count = $month_stmt->fetch(PDO::FETCH_ASSOC)['total'];
                    echo $month_count;
                    ?>
                </div>
                <p class="stat-description">Recent registrations</p>
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
            
            <form id="registrationForm" method="POST" enctype="multipart/form-data" class="hidden" data-offline-sync="1" data-offline-allow-files="1" data-offline-max-bytes="5242880">
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
                        <input name="medical_conditions" placeholder="Medical Conditions" rows="2"></textarea>
                        <input name="allergies" placeholder="Allergies" rows="2"></textarea>
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
                    <button class="btn small primary" onclick="showCSVExportModal()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button class="btn small success" onclick="showPDFExportModal()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <button class="btn small secondary" onclick="printTable()">
                        <i class="fas fa-print"></i> Print Table
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

        <!-- Export Modals -->
        <!-- PDF Export Modal -->
        <div id="pdfExportModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-file-pdf"></i> Export Student List as PDF</h3>
                    <button class="modal-close" onclick="closePDFModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="pdfExportForm" method="POST" action="../helpers/generate-students-pdf.php" target="_blank">
                        <div class="form-group">
                            <label for="pdf_class_id">Select Class *</label>
                            <select name="class_id" id="pdf_class_id" required>
                                <option value="">Choose a class...</option>
                                <?php foreach($classes as $c): ?>
                                    <option value="<?php echo intval($c['id']); ?>">
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="pdf_export_type">Export Type</label>
                            <select name="export_type" id="pdf_export_type">
                                <option value="full">Full Details</option>
                                <option value="summary">Summary View</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="pdf_orientation">Orientation</label>
                            <select name="orientation" id="pdf_orientation">
                                <option value="P">Portrait</option>
                                <option value="L">Landscape</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="include_photos" value="1" id="pdf_include_photos">
                                Include student photos (Landscape only)
                            </label>
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="btn secondary" onclick="closePDFModal()">Cancel</button>
                            <button type="submit" class="btn success">
                                <i class="fas fa-file-pdf"></i> Generate PDF
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- CSV Export Modal -->
        <div id="csvExportModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-file-csv"></i> Export Student List as CSV</h3>
                    <button class="modal-close" onclick="closeCSVModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="csvExportForm" method="POST" action="../helpers/generate-students-csv.php" target="_blank">
                        <div class="form-group">
                            <label for="csv_class_id">Select Class *</label>
                            <select name="class_id" id="csv_class_id" required>
                                <option value="">Choose a class...</option>
                                <?php foreach($classes as $c): ?>
                                    <option value="<?php echo intval($c['id']); ?>">
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="export-info">
                            <p><i class="fas fa-info-circle"></i> CSV export will include all student details for the selected class.</p>
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="btn secondary" onclick="closeCSVModal()">Cancel</button>
                            <button type="submit" class="btn primary">
                                <i class="fas fa-file-csv"></i> Generate CSV
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        </main>
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
        // Toggle registration form with animation
        function toggleRegistrationForm() {
            const form = document.getElementById('registrationForm');
            const button = document.querySelector('.toggle-form-btn');

            if (form.classList.contains('hidden')) {
                form.classList.remove('hidden');
                button.innerHTML = '<i class="fas fa-minus"></i> Hide Form';
                button.style.background = 'var(--gradient-danger)';
                // Animate form appearance
                form.style.opacity = '0';
                form.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    form.style.transition = 'all 0.3s ease';
                    form.style.opacity = '1';
                    form.style.transform = 'translateY(0)';
                }, 10);
            } else {
                form.style.opacity = '0';
                form.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    form.classList.add('hidden');
                    form.style.opacity = '';
                    form.style.transform = '';
                    button.innerHTML = '<i class="fas fa-plus"></i> Show/Hide Form';
                    button.style.background = '';
                }, 300);
            }
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
        
        // Edit student function
        function editStudent(id) {
            // Redirect to edit student page
            window.location.href = 'edit_student.php?id=' + id;
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

        // Add loading state to form submission
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.classList.add('loading');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
            submitBtn.disabled = true;
        });

        // Add smooth scroll to top when page loads
        window.addEventListener('load', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Add ripple effect to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                ripple.style.position = 'absolute';
                ripple.style.borderRadius = '50%';
                ripple.style.background = 'rgba(255, 255, 255, 0.3)';
                ripple.style.transform = 'scale(0)';
                ripple.style.animation = 'ripple 0.6s linear';
                ripple.style.left = (e.offsetX - 10) + 'px';
                ripple.style.top = (e.offsetY - 10) + 'px';
                ripple.style.width = '20px';
                ripple.style.height = '20px';

                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);

                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add CSS animation for ripple
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Add intersection observer for fade-in animations
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

        // Initially hide elements for animation
        document.querySelectorAll('.panel, .stat-card, .alert').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'all 0.6s ease';
            observer.observe(el);
        });

        // Modal functions
        function showPDFExportModal() {
            document.getElementById('pdfExportModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closePDFModal() {
            document.getElementById('pdfExportModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('pdfExportForm').reset();
        }

        function showCSVExportModal() {
            document.getElementById('csvExportModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeCSVModal() {
            document.getElementById('csvExportModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('csvExportForm').reset();
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (this.id === 'pdfExportModal') closePDFModal();
                    if (this.id === 'csvExportModal') closeCSVModal();
                }
            });
        });

        // Handle orientation change for photos checkbox
        document.getElementById('pdf_orientation')?.addEventListener('change', function() {
            const photosCheckbox = document.getElementById('pdf_include_photos');
            const photosLabel = photosCheckbox.closest('.checkbox-label');

            if (this.value === 'P') {
                photosCheckbox.disabled = true;
                photosLabel.style.opacity = '0.5';
                photosLabel.title = 'Photos only available in Landscape mode';
            } else {
                photosCheckbox.disabled = false;
                photosLabel.style.opacity = '1';
                photosLabel.title = '';
            }
        });
    </script>

    <script src="../assets/js/offline-core.js" defer></script>
    <?php include '../includes/floating-button.php'; ?>
</body>
</html>



