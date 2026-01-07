<?php
// admin/manage_user.php
session_start();
require_once '../config/db.php';

// Only allow principal (admin) to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

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
                        // Insert into users table
                        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, staff_id, email, phone, designation, department, qualification, date_of_birth, date_employed, employment_type, teacher_license, emergency_contact, emergency_phone, address, bank_name, account_number, tax_id, pension_number) VALUES (:username, :password, :full_name, :role, :staff_id, :email, :phone, :designation, :department, :qualification, :date_of_birth, :date_employed, :employment_type, :teacher_license, :emergency_contact, :emergency_phone, :address, :bank_name, :account_number, :tax_id, :pension_number)");
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

// Fetch users list with additional fields
$stmt = $pdo->query("SELECT id, username, full_name, role, staff_id, designation, department, phone, date_employed, created_at FROM users ORDER BY id DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch user data with all fields
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => $edit_id]);
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
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ===== CSS Variables ===== */
        :root {
            --primary-color: #4f46e5;
            --primary-dark: #3730a3;
            --secondary-color: #06b6d4;
            --accent-color: #f59e0b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #3b82f6;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --gray-light: #e9ecef;
            --gray: #adb5bd;
            --white: #ffffff;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
            --border-radius-sm: 6px;
            --border-radius: 10px;
            --border-radius-lg: 16px;
            --transition: all 0.3s ease;
        }

        /* ===== Base Styles ===== */
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--dark-color);
        }

        /* ===== Header Enhancement ===== */
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            box-shadow: var(--shadow-md);
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .school-logo-container {
            padding: 1rem 0;
        }

        .school-name {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(45deg, #fff 30%, var(--secondary-color) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .teacher-info {
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius-lg);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .teacher-name {
            font-weight: 600;
            color: white;
        }

        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 0.6rem 1.5rem;
            border-radius: var(--border-radius-lg);
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* ===== Sidebar Enhancement ===== */
        .sidebar {
            background: linear-gradient(180deg, var(--white) 0%, #f8fafc 100%);
            box-shadow: var(--shadow-md);
            border-radius: 0 var(--border-radius-lg) var(--border-radius-lg) 0;
            border-right: none;
        }

        .nav-item {
            margin: 0.3rem 0.5rem;
        }

        .nav-link {
            border-radius: var(--border-radius);
            padding: 0.8rem 1rem;
            transition: var(--transition);
            border-left: 4px solid transparent;
        }

        .nav-link:hover {
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.1) 0%, rgba(79, 70, 229, 0.05) 100%);
            transform: translateX(5px);
            border-left-color: var(--primary-color);
        }

        .nav-link.active {
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: var(--shadow-sm);
            border-left-color: white;
        }

        .nav-icon {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }

        /* ===== Main Content Enhancement ===== */
        .main-content {
            padding: 2rem;
            background: transparent;
        }

        .content-header {
            margin-bottom: 2.5rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--white) 0%, #f8fafc 100%);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            border-left: 5px solid var(--primary-color);
        }

        .content-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .content-header h2 i {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 2rem;
        }

        .small-muted {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* ===== Form Enhancement ===== */
        .form-section {
            background: linear-gradient(135deg, var(--white) 0%, #f8fafc 100%);
            padding: 1.75rem;
            border-radius: var(--border-radius-lg);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(67, 97, 238, 0.1);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }

        .form-section:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .form-section h4 {
            color: var(--primary-dark);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .form-section h4 i {
            color: var(--primary-color);
            background: rgba(67, 97, 238, 0.1);
            padding: 0.5rem;
            border-radius: var(--border-radius-sm);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .form-group {
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group .required::after {
            content: " *";
            color: var(--danger-color);
            font-weight: bold;
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
            font-size: 0.95rem;
            background: var(--white);
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            transition: var(--transition);
            color: var(--dark-color);
            box-shadow: var(--shadow-sm);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
            transform: translateY(-1px);
        }

        .form-control::placeholder {
            color: var(--gray);
            opacity: 0.7;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%234361ee' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
            padding-right: 2.5rem;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
            line-height: 1.5;
        }

        /* ===== Button Enhancement ===== */
        .btn-gold {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            padding: 0.875rem 1.75rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-sm);
            text-decoration: none;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .btn-gold::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transition: width 0.3s ease;
            z-index: -1;
        }

        .btn-gold:hover::before {
            width: 100%;
        }

        .btn-gold:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            color: white;
        }

        .btn-gold:active {
            transform: translateY(-1px);
        }

        .btn-gold i {
            font-size: 1rem;
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(67, 97, 238, 0.1);
        }

        /* Small action buttons */
        .btn-small {
            padding: 0.5rem 0.875rem;
            border-radius: var(--border-radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .btn-edit {
            background: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
            color: white;
        }

        .btn-edit:hover {
            background: linear-gradient(135deg, #4895ef 0%, #4361ee 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-view {
            background: linear-gradient(135deg, #43aa8b 0%, #38b000 100%);
            color: white;
        }

        .btn-view:hover {
            background: linear-gradient(135deg, #38b000 0%, #2d7d46 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-delete {
            background: linear-gradient(135deg, #f72585 0%, #e63946 100%);
            color: white;
        }

        .btn-delete:hover {
            background: linear-gradient(135deg, #e63946 0%, #d00000 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* ===== Table Enhancement ===== */
        .table-container {
            background: linear-gradient(135deg, var(--white) 0%, #f8fafc 100%);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-top: 1.5rem;
            border: 1px solid rgba(67, 97, 238, 0.1);
        }

        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(67, 97, 238, 0.1);
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, rgba(114, 9, 183, 0.05) 100%);
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: transparent;
        }

        .table thead {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        }

        .table th {
            padding: 1rem 1.25rem;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
            position: relative;
        }

        .table th::after {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 1px;
            height: 60%;
            background: rgba(255,255,255,0.2);
        }

        .table th:last-child::after {
            display: none;
        }

        .table tbody tr {
            transition: var(--transition);
            border-bottom: 1px solid rgba(67, 97, 238, 0.05);
        }

        .table tbody tr:hover {
            background: linear-gradient(90deg, rgba(67, 97, 238, 0.05) 0%, rgba(114, 9, 183, 0.05) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .table td {
            padding: 1.125rem 1.25rem;
            color: var(--dark-color);
            font-size: 0.95rem;
            border: none;
            vertical-align: middle;
        }

        /* Staff ID badge */
        .staff-id {
            font-family: 'Courier New', monospace;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.1) 0%, rgba(114, 9, 183, 0.1) 100%);
            padding: 0.375rem 0.75rem;
            border-radius: var(--border-radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary-dark);
            display: inline-block;
        }

        /* Role badges */
        .badge {
            display: inline-block;
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-principal {
            background: linear-gradient(135deg, #f8961e 0%, #f3722c 100%);
            color: white;
        }

        .badge-teacher {
            background: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
            color: white;
        }

        .badge-admin {
            background: linear-gradient(135deg, #7209b7 0%, #560bad 100%);
            color: white;
        }

        .badge-support {
            background: linear-gradient(135deg, #43aa8b 0%, #2d7d46 100%);
            color: white;
        }

        /* ===== Alert Enhancement ===== */
        .alert {
            padding: 1.25rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid;
        }

        .alert[role="alert"] {
            background: linear-gradient(135deg, rgba(247, 37, 133, 0.1) 0%, rgba(230, 57, 70, 0.1) 100%);
            color: var(--danger-color);
            border-left-color: var(--danger-color);
        }

        .alert[style*="background:rgba(200,255,200,0.8)"] {
            background: linear-gradient(135deg, rgba(76, 201, 240, 0.1) 0%, rgba(67, 170, 139, 0.1) 100%);
            color: #2d7d46;
            border-left-color: #43aa8b;
        }

        /* ===== Manage Actions Enhancement ===== */
        .manage-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        /* ===== Empty State ===== */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.3;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
            color: var(--dark-color);
        }

        .empty-state p {
            font-size: 1rem;
            max-width: 500px;
            margin: 0 auto;
        }

        /* ===== Footer Enhancement ===== */
        .dashboard-footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #2d00aa 100%);
            color: white;
            margin-top: 3rem;
            padding: 2.5rem 0 0;
        }

        .footer-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            padding-bottom: 2rem;
        }

        .footer-section h4 {
            color: white;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .footer-section p, .footer-section a {
            color: rgba(255,255,255,0.8);
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .footer-section a:hover {
            color: white;
            text-decoration: underline;
        }

        .footer-links {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .footer-bottom {
            padding: 1.5rem 0;
            border-top: 1px solid rgba(255,255,255,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .footer-copyright, .footer-version {
            color: rgba(255,255,255,0.7);
            font-size: 0.9rem;
        }

        /* ===== Responsive Design ===== */
        @media (max-width: 1200px) {
            .form-row {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .dashboard-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                border-radius: 0;
                margin-bottom: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .table {
                display: block;
                overflow-x: auto;
            }

            .manage-actions {
                flex-wrap: wrap;
            }

            .action-buttons {
                flex-direction: column;
                gap: 0.75rem;
            }

            .btn-gold {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .header-container {
                padding: 0 1rem;
            }

            .content-header {
                padding: 1rem;
            }

            .form-section {
                padding: 1.25rem;
            }

            .footer-content {
                grid-template-columns: 1fr;
            }

            .footer-bottom {
                flex-direction: column;
                gap: 0.75rem;
                text-align: center;
            }
        }

        /* ===== Animation Effects ===== */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .main-content > section {
            animation: fadeIn 0.5s ease-out;
        }

        .form-section:nth-child(1) { animation-delay: 0.1s; }
        .form-section:nth-child(2) { animation-delay: 0.2s; }
        .form-section:nth-child(3) { animation-delay: 0.3s; }
        .form-section:nth-child(4) { animation-delay: 0.4s; }

        /* ===== Custom Scrollbar ===== */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--gray-light);
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
        }
    </style>
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

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Comprehensive school management system for staff, students, and administrative tasks.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
                    <a href="manage_user.php"><i class="fas fa-users-cog"></i> Manage Staff</a>
                    <a href="students.php"><i class="fas fa-user-graduate"></i> Students</a>
                    <a href="staff_documents.php"><i class="fas fa-file-alt"></i> Staff Documents</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p><i class="fas fa-envelope"></i> Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
                <p><i class="fas fa-phone"></i> Phone: +123 456 7890</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Staff Management Module v2.0</p>
        </div>
    </div>
</footer>

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