<?php
// admin/manage_user.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow principal (admin) to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

// Get current school for data isolation
$current_school_id = require_school_auth();

$admin_name = $_SESSION['full_name'] ?? 'Administrator';
$errors = [];
$success = '';

function uploadStaffDocumentFile(array $file, int $staff_user_id): array {
    $allowed_types = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    $max_file_size = 5 * 1024 * 1024; // 5MB

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload error code: ' . (int)($file['error'] ?? -1)];
    }
    if (($file['size'] ?? 0) > $max_file_size) {
        return ['error' => 'File size exceeds 5MB limit.'];
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed_types[$ext])) {
        return ['error' => 'Invalid file extension.'];
    }

    $mime_ok = true;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $mime_ok = ($mime === $allowed_types[$ext]);
        }
    }
    if (!$mime_ok) {
        return ['error' => 'Invalid file type.'];
    }

    $upload_dir = '../uploads/staff_documents/' . $staff_user_id . '/';
    if (!file_exists($upload_dir) && !mkdir($upload_dir, 0777, true)) {
        return ['error' => 'Could not create upload directory.'];
    }

    $safe_name = preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', (string)$file['name']);
    $unique_name = uniqid('staffdoc_', true) . '_' . $safe_name;
    $file_path = $upload_dir . $unique_name;

    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        return ['error' => 'Failed to move uploaded file.'];
    }

    return ['success' => $file_path];
}

// Handle Create / Update via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // common fields
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'teacher';

    // New staff profile fields
    $staff_id = trim($_POST['staff_id'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $date_employed = trim($_POST['date_employed'] ?? date('Y-m-d'));
    $employment_type = trim($_POST['employment_type'] ?? 'full-time');
    $teacher_license = trim($_POST['teacher_license'] ?? '');
    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
    $emergency_phone = trim($_POST['emergency_phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    $pension_number = trim($_POST['pension_number'] ?? '');

    if ($action === 'add') {
        if ($username === '' || $full_name === '' || $password === '' || $staff_id === '') {
            $errors[] = 'Please fill all required fields (username, full name, password, staff ID).';
        } else {
            // check username uniqueness
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Username already exists.';
            } else {
                // check staff ID uniqueness
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE staff_id = :staff_id");
                $stmt->execute(['staff_id' => $staff_id]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = 'Staff ID already exists.';
                } else {
                    // Start transaction
                    $pdo->beginTransaction();
                    try {
        // Insert into users table with school_id
                        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, staff_id, school_id, email, phone, designation, department, qualification, date_of_birth, date_employed, employment_type, teacher_license, emergency_contact, emergency_phone, address, bank_name, account_number, tax_id, pension_number) VALUES (:username, :password, :full_name, :role, :staff_id, :school_id, :email, :phone, :designation, :department, :qualification, :date_of_birth, :date_employed, :employment_type, :teacher_license, :emergency_contact, :emergency_phone, :address, :bank_name, :account_number, :tax_id, :pension_number)");
                        $stmt->execute([
                            'username' => $username,
                            'password' => password_hash($password, PASSWORD_DEFAULT),
                            'full_name' => $full_name,
                            'role' => $role,
                            'staff_id' => $staff_id,
                            'school_id' => $current_school_id,
                            'email' => $email,
                            'phone' => $phone,
                            'designation' => $designation,
                            'department' => $department,
                            'qualification' => $qualification,
                            'date_of_birth' => $date_of_birth,
                            'date_employed' => $date_employed,
                            'employment_type' => $employment_type,
                            'teacher_license' => $teacher_license,
                            'emergency_contact' => $emergency_contact,
                            'emergency_phone' => $emergency_phone,
                            'address' => $address,
                            'bank_name' => $bank_name,
                            'account_number' => $account_number,
                            'tax_id' => $tax_id,
                            'pension_number' => $pension_number
                        ]);

                        $new_user_id = (int)$pdo->lastInsertId();

                        // Optional documents upload
                        if (!empty($_FILES['staff_documents']['name']) && is_array($_FILES['staff_documents']['name'])) {
                            $file_count = count($_FILES['staff_documents']['name']);
                            for ($i = 0; $i < $file_count; $i++) {
                                if (empty($_FILES['staff_documents']['name'][$i])) {
                                    continue;
                                }

                                $doc_file = [
                                    'name' => $_FILES['staff_documents']['name'][$i] ?? '',
                                    'type' => $_FILES['staff_documents']['type'][$i] ?? '',
                                    'tmp_name' => $_FILES['staff_documents']['tmp_name'][$i] ?? '',
                                    'error' => $_FILES['staff_documents']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                                    'size' => $_FILES['staff_documents']['size'][$i] ?? 0
                                ];

                                $doc_type = trim($_POST['document_type'][$i] ?? 'other');
                                $doc_name = trim($_POST['document_name'][$i] ?? '');
                                if ($doc_name === '') {
                                    $doc_name = pathinfo((string)$doc_file['name'], PATHINFO_FILENAME);
                                }

                                $upload = uploadStaffDocumentFile($doc_file, $new_user_id);
                                if (isset($upload['error'])) {
                                    throw new Exception('Document upload failed: ' . $upload['error']);
                                }

                                $doc_stmt = $pdo->prepare("INSERT INTO staff_documents (user_id, document_type, document_name, file_path, uploaded_at, verified_by) VALUES (:user_id, :document_type, :document_name, :file_path, NOW(), :verified_by)");
                                $doc_stmt->execute([
                                    'user_id' => $new_user_id,
                                    'document_type' => $doc_type,
                                    'document_name' => $doc_name,
                                    'file_path' => $upload['success'],
                                    'verified_by' => $_SESSION['user_id']
                                ]);
                            }
                        }

                        $pdo->commit();
                        $success = 'Staff member created successfully.';
                        header("Location: manage_user.php");
                        exit;
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $errors[] = 'Error creating staff: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0 || $username === '' || $full_name === '' || $staff_id === '') {
            $errors[] = 'Invalid input for update.';
        } else {
            // ensure username uniqueness (exclude current user)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username AND id <> :id");
            $stmt->execute(['username' => $username, 'id' => $id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Username already used by another account.';
            } else {
                // ensure staff ID uniqueness (exclude current user)
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE staff_id = :staff_id AND id <> :id");
                $stmt->execute(['staff_id' => $staff_id, 'id' => $id]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = 'Staff ID already used by another staff member.';
                } else {
                    if ($password !== '') {
                        $stmt = $pdo->prepare("UPDATE users SET username = :username, password = :password, full_name = :full_name, role = :role, staff_id = :staff_id, email = :email, phone = :phone, designation = :designation, department = :department, qualification = :qualification, date_of_birth = :date_of_birth, date_employed = :date_employed, employment_type = :employment_type, teacher_license = :teacher_license, emergency_contact = :emergency_contact, emergency_phone = :emergency_phone, address = :address, bank_name = :bank_name, account_number = :account_number, tax_id = :tax_id, pension_number = :pension_number WHERE id = :id");
                        $stmt->execute([
                            'username' => $username,
                            'password' => password_hash($password, PASSWORD_DEFAULT),
                            'full_name' => $full_name,
                            'role' => $role,
                            'staff_id' => $staff_id,
                            'email' => $email,
                            'phone' => $phone,
                            'designation' => $designation,
                            'department' => $department,
                            'qualification' => $qualification,
                            'date_of_birth' => $date_of_birth,
                            'date_employed' => $date_employed,
                            'employment_type' => $employment_type,
                            'teacher_license' => $teacher_license,
                            'emergency_contact' => $emergency_contact,
                            'emergency_phone' => $emergency_phone,
                            'address' => $address,
                            'bank_name' => $bank_name,
                            'account_number' => $account_number,
                            'tax_id' => $tax_id,
                            'pension_number' => $pension_number,
                            'id' => $id
                        ]);
                    } else {
                        // don't change password if left blank
                        $stmt = $pdo->prepare("UPDATE users SET username = :username, full_name = :full_name, role = :role, staff_id = :staff_id, email = :email, phone = :phone, designation = :designation, department = :department, qualification = :qualification, date_of_birth = :date_of_birth, date_employed = :date_employed, employment_type = :employment_type, teacher_license = :teacher_license, emergency_contact = :emergency_contact, emergency_phone = :emergency_phone, address = :address, bank_name = :bank_name, account_number = :account_number, tax_id = :tax_id, pension_number = :pension_number WHERE id = :id");
                        $stmt->execute([
                            'username' => $username,
                            'full_name' => $full_name,
                            'role' => $role,
                            'staff_id' => $staff_id,
                            'email' => $email,
                            'phone' => $phone,
                            'designation' => $designation,
                            'department' => $department,
                            'qualification' => $qualification,
                            'date_of_birth' => $date_of_birth,
                            'date_employed' => $date_employed,
                            'employment_type' => $employment_type,
                            'teacher_license' => $teacher_license,
                            'emergency_contact' => $emergency_contact,
                            'emergency_phone' => $emergency_phone,
                            'address' => $address,
                            'bank_name' => $bank_name,
                            'account_number' => $account_number,
                            'tax_id' => $tax_id,
                            'pension_number' => $pension_number,
                            'id' => $id
                        ]);
                    }
                    $success = 'Staff profile updated successfully.';
                    header("Location: manage_user.php");
                    exit;
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Invalid staff id.';
        } elseif ($id === ($_SESSION['user_id'] ?? 0)) {
            $errors[] = 'You cannot delete your own account while logged in.';
        } else {
            // Begin transaction for safe deletion
            $pdo->beginTransaction();
            try {
                // First, update or nullify foreign key references
                // Update lesson_plans to set teacher_id to NULL
                $stmt = $pdo->prepare("UPDATE lesson_plans SET teacher_id = NULL WHERE teacher_id = :id");
                $stmt->execute(['id' => $id]);

                // Update other tables that reference users.id (add more as needed)
                // Example: Update classwork, assignments, etc. if they exist
                try {
                    $stmt = $pdo->prepare("UPDATE classwork SET teacher_id = NULL WHERE teacher_id = :id");
                    $stmt->execute(['id' => $id]);
                } catch (Exception $e) {
                    // Table might not exist, continue
                }

                try {
                    $stmt = $pdo->prepare("UPDATE assignment SET teacher_id = NULL WHERE teacher_id = :id");
                    $stmt->execute(['id' => $id]);
                } catch (Exception $e) {
                    // Table might not exist, continue
                }

                try {
                    $stmt = $pdo->prepare("UPDATE attendance SET teacher_id = NULL WHERE teacher_id = :id");
                    $stmt->execute(['id' => $id]);
                } catch (Exception $e) {
                    // Table might not exist, continue
                }

                // Now delete the user
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                $stmt->execute(['id' => $id]);

                $pdo->commit();
                $success = 'Staff member deleted successfully.';
                header("Location: manage_user.php");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Could not delete staff member. Error: ' . $e->getMessage();
            }
        }
    }
}

// Handle delete via GET (graceful fallback; still checks permission)
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    if ($del_id > 0 && $del_id !== ($_SESSION['user_id'] ?? 0)) {
        // Begin transaction for safe deletion
        $pdo->beginTransaction();
        try {
            // Update foreign key references first
            $stmt = $pdo->prepare("UPDATE lesson_plans SET teacher_id = NULL WHERE teacher_id = :id");
            $stmt->execute(['id' => $del_id]);

            // Update other tables as needed
            try {
                $stmt = $pdo->prepare("UPDATE classwork SET teacher_id = NULL WHERE teacher_id = :id");
                $stmt->execute(['id' => $del_id]);
            } catch (Exception $e) {
                // Continue if table doesn't exist
            }

            try {
                $stmt = $pdo->prepare("UPDATE assignment SET teacher_id = NULL WHERE teacher_id = :id");
                $stmt->execute(['id' => $del_id]);
            } catch (Exception $e) {
                // Continue if table doesn't exist
            }

            // Now delete the user
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $del_id]);

            $pdo->commit();
            header("Location: manage_user.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Could not delete staff member. Error: ' . $e->getMessage();
        }
    } else {
        $errors[] = 'Cannot delete this staff member.';
    }
}

// Fetch users list with additional fields - filtered by current school
$stmt = $pdo->prepare("SELECT id, username, full_name, role, staff_id, designation, department, phone, date_employed, created_at FROM users WHERE school_id = ? ORDER BY id DESC");
$stmt->execute([$current_school_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch user data with all fields - ensure user belongs to current school
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND school_id = :school_id");
        $stmt->execute(['id' => $edit_id, 'school_id' => $current_school_id]);
        $edit_user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css">
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
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">Admin Portal</p>
                    </div>
                </div>
            </div>

            <!-- Principal Info and Logout -->
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Administrator</p>
                    <span class="principal-name"><?php echo htmlspecialchars($admin_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon">🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

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
                        <a href="students.php" class="nav-link">
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
                        <a href="manage_user.php" class="nav-link active">
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

        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2><i class="fas fa-users-cog"></i> Staff Management</h2>
                    <p>Create, edit, and manage staff accounts and profiles</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <h3>Total Staff</h3>
                    <div class="count"><?php echo count($users); ?></div>
                    <p class="stat-description">All staff members in the system</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <h3>Teachers</h3>
                    <div class="count">
                        <?php
                        $teacher_count = 0;
                        foreach ($users as $u) {
                            if ($u['role'] === 'teacher') $teacher_count++;
                        }
                        echo $teacher_count;
                        ?>
                    </div>
                    <p class="stat-description">Teaching staff members</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-user-tie"></i>
                    <h3>Admin Staff</h3>
                    <div class="count">
                        <?php
                        $admin_count = 0;
                        foreach ($users as $u) {
                            if ($u['role'] === 'admin') $admin_count++;
                        }
                        echo $admin_count;
                        ?>
                    </div>
                    <p class="stat-description">Administrative staff members</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-hands-helping"></i>
                    <h3>Support Staff</h3>
                    <div class="count">
                        <?php
                        $support_count = 0;
                        foreach ($users as $u) {
                            if ($u['role'] === 'support') $support_count++;
                        }
                        echo $support_count;
                        ?>
                    </div>
                    <p class="stat-description">Support and auxiliary staff</p>
                </div>
            </div>

            <!-- Messages -->
            <?php if($errors): ?>
                <div class="alert" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Error:</strong><br>
                        <?php foreach($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if($success): ?>
                <div class="alert" style="background:rgba(200,255,200,0.8); color:#064;">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
            <?php endif; ?>

            <!-- Add New Staff Section -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-plus-circle"></i> Add New Staff Member</h2>
                    <button class="toggle-form-btn" type="button" onclick="toggleRegistrationForm()">
                        <i class="fas fa-plus"></i> Show/Hide Form
                    </button>
                </div>

                <form id="registrationForm" method="POST" enctype="multipart/form-data" class="hidden">
                    <input type="hidden" name="action" value="add">

                    <div class="form-section">
                        <h3><i class="fas fa-id-badge"></i> Core Account</h3>
                        <div class="form-inline">
                            <input name="staff_id" placeholder="Staff ID *" required>
                            <input name="username" placeholder="Username *" required>
                            <input name="full_name" placeholder="Full Name *" required>
                            <input id="passwordField" name="password" type="password" placeholder="Password *" required>
                            <select name="role" required>
                                <option value="">Select Role *</option>
                                <option value="teacher">Teacher</option>
                                <option value="admin">Administrative Staff</option>
                                <option value="support">Support Staff</option>
                                <option value="principal">Principal</option>
                                <option value="clerk">Clerk</option>
                            </select>
                            <button class="btn secondary" type="button" onclick="togglePassword()">
                                <i class="fas fa-eye"></i> Show/Hide Password
                            </button>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-briefcase"></i> Employment Details</h3>
                        <div class="form-inline">
                            <input name="designation" placeholder="Designation (e.g., Class Teacher)">
                            <input name="department" placeholder="Department">
                            <input name="qualification" placeholder="Highest Qualification">
                            <input type="date" name="date_of_birth" placeholder="Date of Birth">
                            <input type="date" name="date_employed" value="<?php echo date('Y-m-d'); ?>">
                            <select name="employment_type">
                                <option value="full-time">Full Time</option>
                                <option value="part-time">Part Time</option>
                                <option value="contract">Contract</option>
                                <option value="temporary">Temporary</option>
                            </select>
                            <input name="teacher_license" placeholder="Teacher License / Certification No.">
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-address-book"></i> Contact & Emergency</h3>
                        <div class="form-inline">
                            <input type="email" name="email" placeholder="Email Address">
                            <input name="phone" placeholder="Phone Number">
                            <input name="emergency_contact" placeholder="Emergency Contact Name">
                            <input name="emergency_phone" placeholder="Emergency Contact Phone">
                            <input name="address" placeholder="Residential Address">
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-university"></i> Payroll & Compliance</h3>
                        <div class="form-inline">
                            <input name="bank_name" placeholder="Bank Name">
                            <input name="account_number" placeholder="Account Number">
                            <input name="tax_id" placeholder="Tax ID / TIN">
                            <input name="pension_number" placeholder="Pension Number">
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-file-upload"></i> Staff Documents (Optional)</h3>
                        <div class="additional-docs-container">
                            <div id="staffDocs">
                                <div class="additional-doc-item">
                                    <div class="form-inline">
                                        <select name="document_type[]" class="small">
                                            <option value="cv">Curriculum Vitae</option>
                                            <option value="certificate">Academic Certificate</option>
                                            <option value="license">Teaching License</option>
                                            <option value="id_copy">ID Copy</option>
                                            <option value="medical">Medical Certificate</option>
                                            <option value="contract">Employment Contract</option>
                                            <option value="other">Other</option>
                                        </select>
                                        <input name="document_name[]" placeholder="Document Name (optional)">
                                        <input type="file" name="staff_documents[]" class="file-input small" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                        <button type="button" class="btn small danger" onclick="removeStaffDocField(this)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn small" onclick="addStaffDocField()">
                                <i class="fas fa-plus"></i> Add Another Document
                            </button>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn primary">
                            <i class="fas fa-user-plus"></i> Add Staff Member
                        </button>
                        <button type="reset" class="btn secondary">
                            <i class="fas fa-redo"></i> Reset Form
                        </button>
                    </div>
                </form>
            </section>

            <!-- Staff Management Section -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-list"></i> Staff Directory</h2>
                    <button class="btn small secondary" onclick="refreshPage()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Staff ID</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Contact</th>
                                <th>Date Employed</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($users)): ?>
                                <tr>
                                    <td colspan="7" class="text-center">
                                        <i class="fas fa-users-slash"></i> No staff members found. Add your first staff member above.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($users as $u): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($u['staff_id'] ?? 'N/A'); ?></td>
                                        <td>
                                            <div style="font-weight: 600; color: var(--secondary);">
                                                <?php echo htmlspecialchars($u['full_name']); ?>
                                            </div>
                                            <div class="small-muted" style="font-size: 0.85rem;">
                                                @<?php echo htmlspecialchars($u['username']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $role_color = '';
                                            $role_icon = '';
                                            switch($u['role']) {
                                                case 'principal': $role_color = 'var(--primary)'; $role_icon = 'fas fa-crown'; break;
                                                case 'teacher': $role_color = 'var(--info)'; $role_icon = 'fas fa-chalkboard-teacher'; break;
                                                case 'admin': $role_color = 'var(--warning)'; $role_icon = 'fas fa-user-tie'; break;
                                                case 'support': $role_color = 'var(--success)'; $role_icon = 'fas fa-hands-helping'; break;
                                                case 'clerk': $role_color = 'var(--secondary)'; $role_icon = 'fas fa-cash-register'; break;
                                                default: $role_color = 'var(--gray-600)'; $role_icon = 'fas fa-user';
                                            }
                                            ?>
                                            <span style="color: <?php echo $role_color; ?>; font-weight: 600;">
                                                <i class="<?php echo $role_icon; ?>"></i>
                                                <?php echo htmlspecialchars(ucfirst($u['role'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars(ucfirst($u['department'] ?? 'N/A')); ?></td>
                                        <td class="small-muted"><?php echo htmlspecialchars($u['phone'] ?? 'No phone'); ?></td>
                                        <td class="small-muted"><?php echo htmlspecialchars($u['date_employed'] ?? ($u['created_at'] ?? '')); ?></td>
                                        <td class="actions">
                                            <a class="btn small primary" href="staff_profile.php?id=<?php echo intval($u['id']); ?>">
                                                <i class="fas fa-eye"></i> Details
                                            </a>

                                            <button class="btn small warning" onclick="openEditModal(<?php echo intval($u['id']); ?>, '<?php echo addslashes(htmlspecialchars($u['full_name'])); ?>', '<?php echo addslashes(htmlspecialchars($u['username'])); ?>', '<?php echo addslashes(htmlspecialchars($u['staff_id'])); ?>', '<?php echo addslashes(htmlspecialchars($u['role'])); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>

                                            <?php if (intval($u['id']) !== ($_SESSION['user_id'] ?? 0)): ?>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($u['full_name']); ?>? This action cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo intval($u['id']); ?>">
                                                    <button type="submit" class="btn small danger">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge" style="background: var(--gray-200); color: var(--gray-600);">Current User</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>

        <!-- Edit Staff Modal -->
        <div id="editModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-edit"></i> Edit Staff Member</h3>
                    <button class="modal-close" onclick="closeEditModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editForm">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_staff_id">

                        <div class="form-group">
                            <label for="edit_staff_id_field">Staff ID</label>
                            <input type="text" name="staff_id" id="edit_staff_id_field" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_username">Username</label>
                            <input type="text" name="username" id="edit_username" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_full_name">Full Name</label>
                            <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_role">Role</label>
                            <select name="role" id="edit_role" class="form-control" required>
                                <option value="teacher">Teacher</option>
                                <option value="admin">Administrative Staff</option>
                                <option value="support">Support Staff</option>
                                <option value="principal">Principal</option>
                                <option value="clerk">Clerk</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit_password">New Password (leave blank to keep current)</label>
                            <input type="password" name="password" id="edit_password" class="form-control">
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="btn small secondary" onclick="closeEditModal()">Cancel</button>
                            <button type="submit" class="btn primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle registration form with animation (same behavior as students page)
        function toggleRegistrationForm() {
            const form = document.getElementById('registrationForm');
            const button = document.querySelector('.toggle-form-btn');

            if (!form || !button) return;

            if (form.classList.contains('hidden')) {
                form.classList.remove('hidden');
                button.innerHTML = '<i class="fas fa-minus"></i> Hide Form';
                button.style.background = 'var(--gradient-danger)';
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

        // Modal functions
        function openEditModal(id, name, username, staffId, role) {
            document.getElementById('edit_staff_id').value = id;
            document.getElementById('edit_full_name').value = name;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_staff_id_field').value = staffId;
            document.getElementById('edit_role').value = role;
            document.getElementById('editModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('editForm').reset();
        }

        // Toggle password visibility
        function togglePassword() {
            const passwordField = document.getElementById('passwordField');
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
        }

        function addStaffDocField() {
            const container = document.getElementById('staffDocs');
            if (!container) return;

            const newField = document.createElement('div');
            newField.className = 'additional-doc-item';
            newField.innerHTML = `
                <div class="form-inline">
                    <select name="document_type[]" class="small">
                        <option value="cv">Curriculum Vitae</option>
                        <option value="certificate">Academic Certificate</option>
                        <option value="license">Teaching License</option>
                        <option value="id_copy">ID Copy</option>
                        <option value="medical">Medical Certificate</option>
                        <option value="contract">Employment Contract</option>
                        <option value="other">Other</option>
                    </select>
                    <input name="document_name[]" placeholder="Document Name (optional)">
                    <input type="file" name="staff_documents[]" class="file-input small" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    <button type="button" class="btn small danger" onclick="removeStaffDocField(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            container.appendChild(newField);
        }

        function removeStaffDocField(button) {
            const row = button.closest('.additional-doc-item');
            const container = document.getElementById('staffDocs');
            if (!row || !container) return;

            if (container.querySelectorAll('.additional-doc-item').length === 1) {
                row.querySelector('input[name="document_name[]"]').value = '';
                row.querySelector('input[name="staff_documents[]"]').value = '';
                row.querySelector('select[name="document_type[]"]').value = 'cv';
                return;
            }
            row.remove();
        }

        // Form validation feedback
        const formControls = document.querySelectorAll('.form-control');
        formControls.forEach(control => {
            control.addEventListener('blur', function() {
                if (this.value.trim() !== '') {
                    this.classList.add('has-value');
                } else {
                    this.classList.remove('has-value');
                }
            });

            // Trigger on page load if field has value
            if (control.value.trim() !== '') {
                control.classList.add('has-value');
            }
        });

        // Add animation to form sections on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const sectionObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all form sections
        document.querySelectorAll('.form-section').forEach(section => {
            section.style.opacity = '0';
            section.style.transform = 'translateY(20px)';
            section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            sectionObserver.observe(section);
        });

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

        // Confirmation functions
        function confirmDeleteStaff() {
            return confirm("Are you sure you want to delete this staff member?\n\nThis action cannot be undone and will remove all associated data.");
        }

        function refreshPage() {
            window.location.reload();
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (this.id === 'editModal') closeEditModal();
                }
            });
        });

        // Form submission with loading state
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.classList.add('loading');
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    submitBtn.disabled = true;
                }
            });
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
        const panelObserver = new IntersectionObserver((entries) => {
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
            panelObserver.observe(el);
        });

        // Keyboard navigation for modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
            }
        });

        // Auto-hide alerts after 5 seconds
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



