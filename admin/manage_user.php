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
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Manage Staff | SahabFormMaster</title>

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
            <h2><i class="fas fa-users-cog"></i> Manage Staff Members</h2>
            <p class="small-muted">Create, update or remove staff accounts and manage their profiles</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Error:</strong><br>
                    <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert" style="background:rgba(200,255,200,0.8); color:#064;">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>

        <!-- Create / Edit form -->
        <section>
            <div class="form-section">
                <h3><i class="fas fa-user-edit"></i> <?php echo $edit_user ? 'Edit Staff Profile' : 'Add New Staff Member'; ?></h3>

                <form method="POST">
                    <input type="hidden" name="action" value="<?php echo $edit_user ? 'edit' : 'add'; ?>">
                    <?php if ($edit_user): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_user['id']); ?>"></input>
                    <?php endif; ?>

                    <div class="form-section">
                        <h4><i class="fas fa-id-card"></i> Basic Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required">Staff ID</label>
                                <input type="text" name="staff_id" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['staff_id'] ?? ''); ?>"
                                       required placeholder="e.g., STF2023001">
                                <small class="small-muted">Unique identifier for the staff member</small>
                            </div>
                            <div class="form-group">
                                <label class="required">Username</label>
                                <input type="text" name="username" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['username'] ?? ''); ?>"
                                       required placeholder="For login">
                                <small class="small-muted">Used for system login</small>
                            </div>
                            <div class="form-group">
                                <label class="required">Full Name</label>
                                <input type="text" name="full_name" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['full_name'] ?? ''); ?>"
                                       required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Date of Birth</label>
                                <input type="date" name="date_of_birth" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['date_of_birth'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label class="required">Role</label>
                                <select name="role" class="form-control" required>
                                    <?php $selRole = $edit_user['role'] ?? 'teacher'; ?>
                                    <option value="principal" <?php echo $selRole === 'principal' ? 'selected' : ''; ?>>Principal</option>
                                    <option value="teacher" <?php echo $selRole === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                                    <option value="admin" <?php echo $selRole === 'admin' ? 'selected' : ''; ?>>Administrative Staff</option>
                                    <option value="support" <?php echo $selRole === 'support' ? 'selected' : ''; ?>>Support Staff</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><?php echo $edit_user ? 'New Password (leave blank to keep current)' : 'Password (required)'; ?></label>
                                <div style="position: relative;">
                                    <input type="password" name="password" class="form-control"
                                           id="passwordField"
                                           placeholder="<?php echo $edit_user ? 'Enter new password' : 'Set password'; ?>"
                                           <?php echo !$edit_user ? 'required' : ''; ?>>
                                    <button type="button" onclick="togglePassword()" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--primary-color); cursor: pointer;">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="small-muted">Minimum 6 characters</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4><i class="fas fa-briefcase"></i> Professional Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Designation/Position</label>
                                <input type="text" name="designation" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['designation'] ?? ''); ?>"
                                       placeholder="e.g., Math Teacher, Head of Department">
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <select name="department" class="form-control">
                                    <option value="">Select Department</option>
                                    <option value="primary" <?php echo ($edit_user['department'] ?? '') === 'primary' ? 'selected' : ''; ?>>Primary</option>
                                    <option value="secondary" <?php echo ($edit_user['department'] ?? '') === 'secondary' ? 'selected' : ''; ?>>Secondary</option>
                                    <option value="administration" <?php echo ($edit_user['department'] ?? '') === 'administration' ? 'selected' : ''; ?>>Administration</option>
                                    <option value="science" <?php echo ($edit_user['department'] ?? '') === 'science' ? 'selected' : ''; ?>>Science</option>
                                    <option value="arts" <?php echo ($edit_user['department'] ?? '') === 'arts' ? 'selected' : ''; ?>>Arts</option>
                                    <option value="sports" <?php echo ($edit_user['department'] ?? '') === 'sports' ? 'selected' : ''; ?>>Sports</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Qualification</label>
                                <input type="text" name="qualification" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['qualification'] ?? ''); ?>"
                                       placeholder="e.g., B.Ed, M.Sc">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Teacher License No.</label>
                                <input type="text" name="teacher_license" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['teacher_license'] ?? ''); ?>"
                                       placeholder="If applicable">
                            </div>
                            <div class="form-group">
                                <label>Date Employed</label>
                                <input type="date" name="date_employed" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['date_employed'] ?? date('Y-m-d')); ?>">
                            </div>
                            <div class="form-group">
                                <label>Employment Type</label>
                                <select name="employment_type" class="form-control">
                                    <option value="full-time" <?php echo ($edit_user['employment_type'] ?? 'full-time') === 'full-time' ? 'selected' : ''; ?>>Full Time</option>
                                    <option value="part-time" <?php echo ($edit_user['employment_type'] ?? '') === 'part-time' ? 'selected' : ''; ?>>Part Time</option>
                                    <option value="contract" <?php echo ($edit_user['employment_type'] ?? '') === 'contract' ? 'selected' : ''; ?>>Contract</option>
                                    <option value="probation" <?php echo ($edit_user['employment_type'] ?? '') === 'probation' ? 'selected' : ''; ?>>Probation</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4><i class="fas fa-address-book"></i> Contact & Emergency Details</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Emergency Contact Name</label>
                                <input type="text" name="emergency_contact" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['emergency_contact'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Emergency Contact Phone</label>
                                <input type="tel" name="emergency_phone" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['emergency_phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Residential Address</label>
                                <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($edit_user['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4><i class="fas fa-university"></i> Financial & Administrative Details</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Bank Name</label>
                                <input type="text" name="bank_name" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['bank_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Account Number</label>
                                <input type="text" name="account_number" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['account_number'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Tax ID Number</label>
                                <input type="text" name="tax_id" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['tax_id'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Pension Number</label>
                                <input type="text" name="pension_number" class="form-control"
                                       value="<?php echo htmlspecialchars($edit_user['pension_number'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <?php if ($edit_user): ?>
                            <button type="submit" class="btn-gold">
                                <i class="fas fa-save"></i> Update Staff Profile
                            </button>
                            <a href="manage_user.php" class="btn-gold" style="background: var(--gray-light); color: var(--dark-color);">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <a href="staff_documents.php?staff_id=<?php echo intval($edit_user['id']); ?>" class="btn-gold" style="background: linear-gradient(135deg, var(--info-color) 0%, #2d7d46 100%);">
                                <i class="fas fa-file-upload"></i> Upload Documents
                            </a>
                        <?php else: ?>
                            <button type="submit" class="btn-gold">
                                <i class="fas fa-user-plus"></i> Create Staff Account
                            </button>
                            <button type="reset" class="btn-gold" style="background: var(--gray-light); color: var(--dark-color);">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- Staff table -->
        <section>
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-list"></i> All Staff Members</h3>
                    <p class="small-muted">Total: <?php echo count($users); ?> staff members</p>
                </div>

                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Staff ID</th>
                                <th>Name</th>
                                <th>Position</th>
                                <th>Department</th>
                                <th>Contact</th>
                                <th>Date Employed</th>
                                <th>Role</th>
                                <th style="width: 220px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($users) === 0): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class="fas fa-users-slash"></i>
                                            <h3>No Staff Members Found</h3>
                                            <p>Add your first staff member using the form above to get started.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                    <?php
                                    $badge_class = '';
                                    if ($u['role'] === 'principal') $badge_class = 'badge-principal';
                                    elseif ($u['role'] === 'teacher') $badge_class = 'badge-teacher';
                                    elseif ($u['role'] === 'admin') $badge_class = 'badge-admin';
                                    else $badge_class = 'badge-support';
                                    ?>
                                    <tr>
                                        <td><span class="staff-id"><?php echo htmlspecialchars($u['staff_id'] ?? 'N/A'); ?></span></td>
                                        <td>
                                            <div style="font-weight: 600; color: var(--primary-dark);"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                            <div class="small-muted" style="font-size: 0.85rem;">@<?php echo htmlspecialchars($u['username']); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($u['designation'] ?? 'Not set'); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst($u['department'] ?? 'N/A')); ?></td>
                                        <td class="small-muted"><?php echo htmlspecialchars($u['phone'] ?? 'No phone'); ?></td>
                                        <td class="small-muted"><?php echo htmlspecialchars($u['date_employed'] ?? ($u['created_at'] ?? '')); ?></td>
                                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars(ucfirst($u['role'])); ?></span></td>
                                        <td>
                                            <div class="manage-actions">
                                                <a class="btn-small btn-edit" href="manage_user.php?edit=<?php echo intval($u['id']); ?>" title="Edit">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>

                                                <a class="btn-small btn-view" href="staff_profile.php?id=<?php echo intval($u['id']); ?>" title="View Profile">
                                                    <i class="fas fa-eye"></i> View
                                                </a>

                                                <?php if (intval($u['id']) !== ($_SESSION['user_id'] ?? 0)): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($u['full_name']); ?>? This action cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo intval($u['id']); ?>">
                                                        <button type="submit" class="btn-small btn-delete" title="Delete">
                                                            <i class="fas fa-trash-alt"></i> Delete
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="badge" style="background: var(--gray-light); color: var(--gray);">Current</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>


<script>
    // Toggle password visibility
    function togglePassword() {
        const passwordField = document.getElementById('passwordField');
        const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordField.setAttribute('type', type);
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

    const observer = new IntersectionObserver((entries) => {
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
        observer.observe(section);
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
</script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
