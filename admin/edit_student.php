<?php
session_start();
require_once '../config/db.php';

// Only principal/admin
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_id = intval($_SESSION['user_id']);
$errors = [];
$success = '';

// Get student ID from URL
$student_id = intval($_GET['id'] ?? 0);
if ($student_id <= 0) {
    header("Location: students.php");
    exit;
}

// Fetch student details
$stmt = $pdo->prepare("
    SELECT s.*, c.class_name
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: students.php");
    exit;
}

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = intval($_POST['class_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $admission_no = trim($_POST['admission_no'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['dob'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $guardian_name = trim($_POST['guardian_name'] ?? '');
    $guardian_phone = trim($_POST['guardian_phone'] ?? '');
    $guardian_email = trim($_POST['guardian_email'] ?? '');
    $guardian_relation = trim($_POST['guardian_relation'] ?? '');
    $guardian_address = trim($_POST['guardian_address'] ?? '');
    $guardian_occupation = trim($_POST['guardian_occupation'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $student_type = $_POST['student_type'] ?? 'fresh';

    // Additional fields
    $nationality = trim($_POST['nationality'] ?? '');
    $religion = trim($_POST['religion'] ?? '');
    $blood_group = trim($_POST['blood_group'] ?? '');
    $medical_conditions = trim($_POST['medical_conditions'] ?? '');
    $allergies = trim($_POST['allergies'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
    $emergency_contact_relation = trim($_POST['emergency_contact_relation'] ?? '');
    $previous_school = trim($_POST['previous_school'] ?? '');
    $enrollment_date = $_POST['enrollment_date'] ?? '';

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

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Handle file uploads
            $birth_certificate = $student['birth_certificate']; // Keep existing
            $passport_photo = $student['passport_photo']; // Keep existing
            $transfer_letter = $student['transfer_letter']; // Keep existing

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
                // Update student
                $stmt = $pdo->prepare("
                    UPDATE students
                    SET class_id = ?, full_name = ?, admission_no = ?, gender = ?, dob = ?,
                        phone = ?, guardian_name = ?, guardian_phone = ?, guardian_email = ?,
                        guardian_relation = ?, guardian_address = ?, guardian_occupation = ?,
                        address = ?, student_type = ?, nationality = ?, religion = ?,
                        blood_group = ?, medical_conditions = ?, allergies = ?,
                        emergency_contact_name = ?, emergency_contact_phone = ?,
                        emergency_contact_relation = ?, previous_school = ?,
                        birth_certificate = ?, passport_photo = ?, transfer_letter = ?,
                        enrollment_date = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $class_id, $full_name, $admission_no, $gender, $dob ?: null,
                    $phone, $guardian_name, $guardian_phone, $guardian_email,
                    $guardian_relation, $guardian_address, $guardian_occupation,
                    $address, $student_type, $nationality, $religion,
                    $blood_group, $medical_conditions, $allergies,
                    $emergency_contact_name, $emergency_contact_phone,
                    $emergency_contact_relation, $previous_school,
                    $birth_certificate, $passport_photo, $transfer_letter,
                    $enrollment_date ?: null, $student_id
                ]);

                // Add a note about update
                $pdo->prepare("INSERT INTO student_notes (student_id, teacher_id, note_text, created_at) VALUES (?, ?, ?, NOW())")
                    ->execute([$student_id, $principal_id, "Student information updated by principal."]);

                $pdo->commit();
                $success = 'Student updated successfully.';

                // Refresh student data
                $stmt = $pdo->prepare("
                    SELECT s.*, c.class_name
                    FROM students s
                    LEFT JOIN classes c ON s.class_id = c.id
                    WHERE s.id = ?
                ");
                $stmt->execute([$student_id]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);

            } else {
                $pdo->rollBack();
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error updating student: ' . $e->getMessage();
        }
    }
}

// Fetch classes for dropdown
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
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
                    <h2>👤 Edit Student</h2>
                    <p>Update <?php echo htmlspecialchars($student['full_name']); ?>'s information</p>
                </div>
            </div>

            <!-- Dashboard Link -->
            <a href="students.php" class="dashboard-link">
                <i class="fas fa-arrow-left"></i> Back to Students
            </a>

            <!-- Success/Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Errors occurred:</strong>
                        <ul style="margin: 0.5rem 0 0 0; padding-left: 1.5rem;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Edit Form -->
            <div class="panel">
                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem;">
                    <div style="width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center; color: white;">
                        <i class="fas fa-user-edit" style="font-size: 1.5rem;"></i>
                    </div>
                    <div>
                        <h2 style="margin: 0 0 0.25rem 0;">Edit Student Information</h2>
                        <p style="margin: 0; color: var(--gray-600);">Update <?php echo htmlspecialchars($student['full_name']); ?>'s details</p>
                    </div>
                </div>

                <!-- Current Student Info Preview -->
                <div style="background: var(--gray-50); padding: 1.5rem; border-radius: var(--border-radius); margin-bottom: 2rem; border-left: 4px solid var(--primary-color);">
                    <h3 style="margin: 0 0 1rem 0; color: var(--primary-color); display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fas fa-user"></i> Current Student Details
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div style="background: white; padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--gray-200);">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-user" style="color: var(--primary-color);"></i>
                                <strong>Name</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['full_name']); ?></p>
                        </div>
                        <div style="background: white; padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--gray-200);">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-id-card" style="color: var(--primary-color);"></i>
                                <strong>Admission No</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['admission_no']); ?></p>
                        </div>
                        <div style="background: white; padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--gray-200);">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-school" style="color: var(--primary-color);"></i>
                                <strong>Class</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?></p>
                        </div>
                        <div style="background: white; padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--gray-200);">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-venus-mars" style="color: var(--primary-color);"></i>
                                <strong>Gender</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['gender']); ?></p>
                        </div>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="class_id">
                                <i class="fas fa-school"></i> Class *
                            </label>
                            <select id="class_id" name="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach($classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"
                                        <?php echo $c['id'] == $student['class_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="full_name">
                                <i class="fas fa-user"></i> Full Name *
                            </label>
                            <input type="text" id="full_name" name="full_name" class="form-control"
                                   value="<?php echo htmlspecialchars($student['full_name']); ?>" required
                                   placeholder="Enter full name">
                        </div>

                        <div class="form-group">
                            <label for="admission_no">
                                <i class="fas fa-id-card"></i> Admission Number *
                            </label>
                            <input type="text" id="admission_no" name="admission_no" class="form-control"
                                   value="<?php echo htmlspecialchars($student['admission_no']); ?>" required
                                   placeholder="Enter admission number">
                        </div>

                        <div class="form-group">
                            <label for="gender">
                                <i class="fas fa-venus-mars"></i> Gender
                            </label>
                            <select id="gender" name="gender" class="form-control">
                                <option value="">-- Select Gender --</option>
                                <option value="Male" <?php echo $student['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $student['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo $student['gender'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="dob">
                                <i class="fas fa-birthday-cake"></i> Date of Birth
                            </label>
                            <input type="date" id="dob" name="dob" class="form-control"
                                   value="<?php echo $student['dob'] ? date('Y-m-d', strtotime($student['dob'])) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="phone">
                                <i class="fas fa-phone"></i> Phone Number
                            </label>
                            <input type="tel" id="phone" name="phone" class="form-control"
                                   value="<?php echo htmlspecialchars($student['phone']); ?>"
                                   placeholder="Enter phone number">
                        </div>

                        <div class="form-group">
                            <label for="guardian_name">
                                <i class="fas fa-user-friends"></i> Guardian Name
                            </label>
                            <input type="text" id="guardian_name" name="guardian_name" class="form-control"
                                   value="<?php echo htmlspecialchars($student['guardian_name']); ?>"
                                   placeholder="Enter guardian name">
                        </div>

                        <div class="form-group">
                            <label for="guardian_phone">
                                <i class="fas fa-phone-alt"></i> Guardian Phone
                            </label>
                            <input type="tel" id="guardian_phone" name="guardian_phone" class="form-control"
                                   value="<?php echo htmlspecialchars($student['guardian_phone']); ?>"
                                   placeholder="Enter guardian phone">
                        </div>

                        <div class="form-group">
                            <label for="guardian_email">
                                <i class="fas fa-envelope"></i> Guardian Email
                            </label>
                            <input type="email" id="guardian_email" name="guardian_email" class="form-control"
                                   value="<?php echo htmlspecialchars($student['guardian_email']); ?>"
                                   placeholder="Enter guardian email">
                        </div>

                        <div class="form-group">
                            <label for="guardian_relation">
                                <i class="fas fa-link"></i> Guardian Relation
                            </label>
                            <input type="text" id="guardian_relation" name="guardian_relation" class="form-control"
                                   value="<?php echo htmlspecialchars($student['guardian_relation']); ?>"
                                   placeholder="e.g., Father, Mother, Uncle">
                        </div>

                        <div class="form-group">
                            <label for="guardian_address">
                                <i class="fas fa-map-marker-alt"></i> Guardian Address
                            </label>
                            <input type="text" id="guardian_address" name="guardian_address" class="form-control"
                                   value="<?php echo htmlspecialchars($student['guardian_address']); ?>"
                                   placeholder="Enter guardian address">
                        </div>

                        <div class="form-group">
                            <label for="guardian_occupation">
                                <i class="fas fa-briefcase"></i> Guardian Occupation
                            </label>
                            <input type="text" id="guardian_occupation" name="guardian_occupation" class="form-control"
                                   value="<?php echo htmlspecialchars($student['guardian_occupation']); ?>"
                                   placeholder="Enter guardian occupation">
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="address">
                                <i class="fas fa-home"></i> Student Address
                            </label>
                            <textarea id="address" name="address" class="form-control" rows="3"
                                      placeholder="Enter student address"><?php echo htmlspecialchars($student['address']); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="student_type">
                                <i class="fas fa-graduation-cap"></i> Student Type
                            </label>
                            <select id="student_type" name="student_type" class="form-control" onchange="toggleTransferFields()">
                                <option value="fresh" <?php echo $student['student_type'] == 'fresh' ? 'selected' : ''; ?>>Fresh Student</option>
                                <option value="transfer" <?php echo $student['student_type'] == 'transfer' ? 'selected' : ''; ?>>Transfer Student</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="enrollment_date">
                                <i class="fas fa-calendar-alt"></i> Enrollment Date
                            </label>
                            <input type="date" id="enrollment_date" name="enrollment_date" class="form-control"
                                   value="<?php echo $student['enrollment_date'] ? date('Y-m-d', strtotime($student['enrollment_date'])) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="nationality">
                                <i class="fas fa-flag"></i> Nationality
                            </label>
                            <select id="nationality" name="nationality" class="form-control">
                                <option value="">-- Select Nationality --</option>
                                <option value="Nigerian" <?php echo $student['nationality'] == 'Nigerian' ? 'selected' : ''; ?>>Nigerian</option>
                                <option value="Other" <?php echo $student['nationality'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="religion">
                                <i class="fas fa-pray"></i> Religion
                            </label>
                            <select id="religion" name="religion" class="form-control">
                                <option value="">-- Select Religion --</option>
                                <option value="Islam" <?php echo $student['religion'] == 'Islam' ? 'selected' : ''; ?>>Islam</option>
                                <option value="Christianity" <?php echo $student['religion'] == 'Christianity' ? 'selected' : ''; ?>>Christianity</option>
                                <option value="Other" <?php echo $student['religion'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="blood_group">
                                <i class="fas fa-tint"></i> Blood Group
                            </label>
                            <select id="blood_group" name="blood_group" class="form-control">
                                <option value="">-- Select Blood Group --</option>
                                <option value="A+" <?php echo $student['blood_group'] == 'A+' ? 'selected' : ''; ?>>A+</option>
                                <option value="A-" <?php echo $student['blood_group'] == 'A-' ? 'selected' : ''; ?>>A-</option>
                                <option value="B+" <?php echo $student['blood_group'] == 'B+' ? 'selected' : ''; ?>>B+</option>
                                <option value="B-" <?php echo $student['blood_group'] == 'B-' ? 'selected' : ''; ?>>B-</option>
                                <option value="AB+" <?php echo $student['blood_group'] == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                <option value="AB-" <?php echo $student['blood_group'] == 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                <option value="O+" <?php echo $student['blood_group'] == 'O+' ? 'selected' : ''; ?>>O+</option>
                                <option value="O-" <?php echo $student['blood_group'] == 'O-' ? 'selected' : ''; ?>>O-</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="emergency_contact_name">
                                <i class="fas fa-user-md"></i> Emergency Contact Name
                            </label>
                            <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="form-control"
                                   value="<?php echo htmlspecialchars($student['emergency_contact_name']); ?>"
                                   placeholder="Enter emergency contact name">
                        </div>

                        <div class="form-group">
                            <label for="emergency_contact_phone">
                                <i class="fas fa-phone-volume"></i> Emergency Contact Phone
                            </label>
                            <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="form-control"
                                   value="<?php echo htmlspecialchars($student['emergency_contact_phone']); ?>"
                                   placeholder="Enter emergency contact phone">
                        </div>

                        <div class="form-group">
                            <label for="emergency_contact_relation">
                                <i class="fas fa-link"></i> Emergency Contact Relation
                            </label>
                            <input type="text" id="emergency_contact_relation" name="emergency_contact_relation" class="form-control"
                                   value="<?php echo htmlspecialchars($student['emergency_contact_relation']); ?>"
                                   placeholder="e.g., Aunt, Uncle, Neighbor">
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="medical_conditions">
                                <i class="fas fa-heartbeat"></i> Medical Conditions
                            </label>
                            <textarea id="medical_conditions" name="medical_conditions" class="form-control" rows="2"
                                      placeholder="Enter any medical conditions"><?php echo htmlspecialchars($student['medical_conditions']); ?></textarea>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="allergies">
                                <i class="fas fa-allergies"></i> Allergies
                            </label>
                            <textarea id="allergies" name="allergies" class="form-control" rows="2"
                                      placeholder="Enter any allergies"><?php echo htmlspecialchars($student['allergies']); ?></textarea>
                        </div>

                        <!-- Transfer Student Fields -->
                        <div id="transferFields" class="form-group" style="grid-column: 1 / -1; <?php echo $student['student_type'] !== 'transfer' ? 'display: none;' : ''; ?>">
                            <label for="previous_school">
                                <i class="fas fa-school"></i> Previous School
                            </label>
                            <input type="text" id="previous_school" name="previous_school" class="form-control"
                                   value="<?php echo htmlspecialchars($student['previous_school']); ?>"
                                   placeholder="Enter previous school name">
                        </div>
                    </div>

                    <!-- File Uploads Section -->
                    <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--gray-200);">
                        <h3 style="margin-bottom: 1.5rem; color: var(--primary-color);">
                            <i class="fas fa-file-upload"></i> Update Documents
                        </h3>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                            <div class="form-group">
                                <label for="birth_certificate">
                                    <i class="fas fa-file-pdf"></i> Birth Certificate
                                </label>
                                <input type="file" id="birth_certificate" name="birth_certificate" class="form-control"
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <small style="color: var(--gray-500);">
                                    <?php if($student['birth_certificate']): ?>
                                        Current file exists. Upload new file to replace.
                                    <?php else: ?>
                                        Upload PDF, JPG, or DOC file (Max 5MB)
                                    <?php endif; ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="passport_photo">
                                    <i class="fas fa-camera"></i> Passport Photograph
                                </label>
                                <input type="file" id="passport_photo" name="passport_photo" class="form-control"
                                       accept=".jpg,.jpeg,.png">
                                <small style="color: var(--gray-500);">
                                    <?php if($student['passport_photo']): ?>
                                        Current photo exists. Upload new photo to replace.
                                    <?php else: ?>
                                        Upload JPG or PNG file (Max 5MB)
                                    <?php endif; ?>
                                </small>
                            </div>

                            <div id="transferDocField" class="form-group" style="<?php echo $student['student_type'] !== 'transfer' ? 'display: none;' : ''; ?>">
                                <label for="transfer_letter">
                                    <i class="fas fa-file-contract"></i> Transfer Letter
                                </label>
                                <input type="file" id="transfer_letter" name="transfer_letter" class="form-control"
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <small style="color: var(--gray-500);">
                                    <?php if($student['transfer_letter']): ?>
                                        Current file exists. Upload new file to replace.
                                    <?php else: ?>
                                        Upload PDF, JPG, or DOC file (Max 5MB)
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Important Notes -->
                    <div style="background: rgba(245, 158, 11, 0.1); padding: 1.5rem; border-radius: var(--border-radius); margin-top: 2rem; border-left: 4px solid var(--warning-color);">
                        <h3 style="margin: 0 0 1rem 0; color: var(--warning-color); display: flex; align-items: center; gap: 0.75rem;">
                            <i class="fas fa-info-circle"></i> Important Notes
                        </h3>
                        <ul style="margin: 0; padding-left: 1.5rem; color: var(--gray-700);">
                            <li>Changing the class will move the student to a different class</li>
                            <li>Ensure admission number is unique across all students</li>
                            <li>All changes will be logged in the student notes</li>
                            <li>File uploads will replace existing files if new ones are provided</li>
                        </ul>
                    </div>

                    <!-- Action Buttons -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--gray-200);">
                        <a href="student_details.php?id=<?php echo $student['id']; ?>" class="btn">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                        <div style="display: flex; gap: 1rem;">
                            <a href="students.php" class="btn btn-danger">
                                <i class="fas fa-arrow-left"></i> Back to Students
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Update Student
                            </button>
                        </div>
                    </div>
                </form>
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
                if (sidebar && !sidebar.contains(e.target) && mobileMenuToggle && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        // Toggle transfer fields
        function toggleTransferFields() {
            const studentType = document.getElementById('student_type').value;
            const transferFields = document.getElementById('transferFields');
            const transferDocField = document.getElementById('transferDocField');

            if (studentType === 'transfer') {
                transferFields.style.display = 'block';
                transferDocField.style.display = 'block';
            } else {
                transferFields.style.display = 'none';
                transferDocField.style.display = 'none';
            }
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
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

        // Remove error styling when user starts typing
        document.querySelectorAll('[required]').forEach(field => {
            field.addEventListener('input', function() {
                this.style.borderColor = '';
                const errorMsg = this.parentNode.querySelector('.error-message');
                if (errorMsg) errorMsg.remove();
            });
        });
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>




