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
$uid = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$errors = [];
$success = '';

// Handle create/update/delete for teachers (teacher-created subjects)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        if ($name === '') $errors[] = 'Subject name required.';
        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, subject_code, description, created_by, school_id, created_at) VALUES (:name,:code,:desc,:uid,:school_id,NOW())");
            $stmt->execute(['name'=>$name,'code'=>$code,'desc'=>trim($_POST['description'] ?? ''),'uid'=>$uid,'school_id'=>$current_school_id]);
            $success = 'Subject created successfully!';
            header("Location: subjects.php");
            exit;
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0) $errors[] = 'Invalid id.';
        if ($name === '') $errors[] = 'Name required.';
        if (empty($errors)) {
            // ensure teacher is owner or allow updating any? keep simple: owner only
            $stmt = $pdo->prepare("SELECT created_by FROM subjects WHERE id = :id");
            $stmt->execute(['id'=>$id]);
            if ($stmt->fetchColumn() != $uid) { $errors[] = 'Access denied.'; }
            else {
                $pdo->prepare("UPDATE subjects SET subject_name = :name, subject_code = :code, description = :desc, updated_at = NOW() WHERE id = :id")
                    ->execute(['name'=>$name,'code'=>trim($_POST['code'] ?? ''),'desc'=>trim($_POST['description'] ?? ''),'id'=>$id]);
                $success = 'Subject updated successfully!';
                header("Location: subjects.php");
                exit;
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT created_by FROM subjects WHERE id = :id");
            $stmt->execute(['id'=>$id]);
            if ($stmt->fetchColumn() != $uid) { $errors[] = 'Access denied.'; }
            else {
                $pdo->prepare("DELETE FROM subject_assignments WHERE subject_id = :id")->execute(['id'=>$id]);
                $pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute(['id'=>$id]);
                $success = 'Subject deleted successfully!';
                header("Location: subjects.php");
                exit;
            }
        }
    }
}

// Fetch subjects: show all subjects in the school and mark which are assigned to teacher's classes
$all_subjects = $pdo->prepare("SELECT * FROM subjects WHERE school_id = ? ORDER BY subject_name");
$all_subjects->execute([$current_school_id]);
$all_subjects = $all_subjects->fetchAll(PDO::FETCH_ASSOC);
$my_assignments = $pdo->prepare("SELECT sa.id, sa.subject_id, sa.class_id, c.class_name FROM subject_assignments sa JOIN classes c ON sa.class_id = c.id WHERE sa.teacher_id = :uid ORDER BY c.class_name");
$my_assignments->execute(['uid'=>$uid]);
$assignments = $my_assignments->fetchAll(PDO::FETCH_ASSOC);
$assigned_subject_ids = array_column($assignments, 'subject_id');

// Get some statistics
$total_subjects = count($all_subjects);
$owned_subjects = count(array_filter($all_subjects, fn($s) => $s['created_by'] == $uid));
$assigned_subjects = count(array_unique($assigned_subject_ids));
$total_assignments = count($assignments);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Management | SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-50: #eff6ff;
            --primary-100: #dbeafe;
            --primary-200: #bfdbfe;
            --primary-300: #93c5fd;
            --primary-400: #60a5fa;
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --primary-700: #1d4ed8;
            --primary-800: #1e40af;
            --primary-900: #1e3a8a;

            --accent-50: #fdf4ff;
            --accent-100: #fae8ff;
            --accent-200: #f5d0fe;
            --accent-300: #f0abfc;
            --accent-400: #e879f9;
            --accent-500: #d946ef;
            --accent-600: #c026d3;
            --accent-700: #a21caf;
            --accent-800: #86198f;
            --accent-900: #701a75;

            --success-50: #f0fdf4;
            --success-100: #dcfce7;
            --success-500: #22c55e;
            --success-600: #16a34a;
            --success-700: #15803d;

            --error-50: #fef2f2;
            --error-100: #fee2e2;
            --error-500: #ef4444;
            --error-600: #dc2626;

            --warning-50: #fffbeb;
            --warning-100: #fef3c7;
            --warning-500: #f59e0b;
            --warning-600: #d97706;

            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;

            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 32px rgba(0, 0, 0, 0.12);
            --shadow-strong: 0 16px 48px rgba(0, 0, 0, 0.15);

            --gradient-primary: linear-gradient(135deg, var(--primary-500) 0%, var(--primary-700) 100%);
            --gradient-accent: linear-gradient(135deg, var(--accent-500) 0%, var(--accent-700) 100%);
            --gradient-bg: linear-gradient(135deg, var(--primary-50) 0%, var(--accent-50) 50%, var(--primary-100) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--gradient-bg);
            color: var(--gray-800);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* Modern Header */
        .modern-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-soft);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-container {
            width: 56px;
            height: 56px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: var(--shadow-medium);
        }

        .brand-text h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.125rem;
        }

        .brand-text p {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 12px;
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .back-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--gradient-accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-details p {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.125rem;
        }

        .user-details span {
            font-weight: 600;
            color: var(--gray-900);
        }

        .logout-btn {
            padding: 0.75rem 1.25rem;
            background: var(--error-500);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: var(--error-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Modern Cards */
        .modern-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }

        .modern-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
        }

        .card-header-modern {
            padding: 2rem;
            background: var(--gradient-primary);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .card-header-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/><circle cx="10" cy="90" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .card-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .card-subtitle-modern {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .card-body-modern {
            padding: 2rem;
        }

        /* Statistics Grid */
        .stats-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .stat-card-modern:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-strong);
        }

        .stat-icon-modern {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-total .stat-icon-modern {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-owned .stat-icon-modern {
            background: var(--gradient-accent);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-assigned .stat-icon-modern {
            background: var(--gradient-success);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-assignments .stat-icon-modern {
            background: var(--gradient-warning);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-value-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label-modern {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Form Controls */
        .controls-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .form-row-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group-modern {
            position: relative;
        }

        .form-label-modern {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            letter-spacing: 0.025em;
        }

        .form-input-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-input-modern::placeholder {
            color: var(--gray-400);
        }

        .form-textarea-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
            min-height: 100px;
            resize: vertical;
        }

        .form-textarea-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .btn-modern-primary {
            padding: 1rem 2rem;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-medium);
        }

        .btn-modern-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        /* Subjects Table */
        .subjects-table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        .table-header-modern {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .table-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .table-subtitle-modern {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .table-wrapper-modern {
            overflow-x: auto;
        }

        .subjects-table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .subjects-table-modern th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1.25rem 1.5rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
        }

        .subjects-table-modern td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .subjects-table-modern tr:nth-child(even) {
            background: var(--gray-50);
        }

        .subjects-table-modern tr:hover {
            background: var(--primary-50);
        }

        .subject-number-modern {
            font-weight: 600;
            color: var(--gray-900);
        }

        .subject-name-modern {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 1.125rem;
        }

        .subject-code-modern {
            font-weight: 500;
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .badge-modern {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .badge-owned {
            background: var(--accent-100);
            color: var(--accent-700);
        }

        .badge-assigned {
            background: var(--success-100);
            color: var(--success-700);
        }

        .badge-not-owned {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .badge-not-assigned {
            background: var(--error-100);
            color: var(--error-600);
        }

        .action-buttons-modern {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-edit-modern {
            padding: 0.5rem 1rem;
            background: var(--primary-500);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            text-decoration: none;
        }

        .btn-edit-modern:hover {
            background: var(--primary-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .btn-delete-modern {
            padding: 0.5rem 1rem;
            background: var(--error-500);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-delete-modern:hover {
            background: var(--error-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Assignments Grid */
        .assignments-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .assignment-card-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .assignment-card-modern:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
        }

        .assignment-icon-modern {
            width: 48px;
            height: 48px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        .assignment-details-modern h4 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .assignment-details-modern p {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        /* Empty State */
        .empty-state-modern {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray-600);
        }

        .empty-icon-modern {
            font-size: 4rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
        }

        .empty-title-modern {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .empty-description-modern {
            font-size: 1rem;
        }

        /* Modal */
        .modal-modern {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            z-index: 1050;
            backdrop-filter: blur(8px);
        }

        .modal-content-modern {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: var(--shadow-strong);
        }

        .modal-header-modern {
            padding: 2rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0;
        }

        .modal-close-modern {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-400);
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .modal-close-modern:hover {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .modal-body-modern {
            padding: 2rem;
        }

        .modal-actions-modern {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .btn-secondary-modern {
            padding: 0.75rem 1.5rem;
            background: var(--gray-100);
            color: var(--gray-700);
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-secondary-modern:hover {
            background: var(--gray-200);
            transform: translateY(-1px);
        }

        /* Alerts */
        .alert-modern {
            padding: 1.25rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .alert-success-modern {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success-700);
            border-left: 4px solid var(--success-500);
        }

        .alert-error-modern {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-700);
            border-left: 4px solid var(--error-500);
        }

        /* Footer */
        .footer-modern {
            background: var(--gray-900);
            color: var(--gray-300);
            padding: 3rem 2rem 2rem;
            margin-top: 4rem;
            position: relative;
        }

        .footer-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gray-700), transparent);
        }

        .footer-content-modern {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-section-modern h4 {
            color: white;
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 1rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .footer-section-modern p {
            margin-bottom: 0.75rem;
            line-height: 1.6;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
            }

            .main-container {
                padding: 1rem;
            }

            .stats-modern {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .form-row-modern {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .assignments-grid-modern {
                grid-template-columns: 1fr;
            }

            .table-header-modern {
                padding: 1rem;
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .subjects-table-modern th,
            .subjects-table-modern td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }

            .modal-content-modern {
                width: 95%;
                margin: 2rem auto;
            }

            .modal-header-modern,
            .modal-body-modern,
            .modal-actions-modern {
                padding: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .stats-modern {
                grid-template-columns: 1fr;
            }

            .assignments-grid-modern {
                grid-template-columns: 1fr;
            }

            .modern-card {
                margin-bottom: 1rem;
            }

            .card-header-modern,
            .card-body-modern {
                padding: 1.5rem;
            }

            .stat-card-modern {
                padding: 1.5rem;
            }

            .stat-icon-modern {
                width: 48px;
                height: 48px;
                font-size: 1.25rem;
            }

            .stat-value-modern {
                font-size: 2rem;
            }

            .action-buttons-modern {
                flex-direction: column;
                gap: 0.25rem;
            }

            .btn-edit-modern,
            .btn-delete-modern {
                padding: 0.375rem 0.5rem;
                font-size: 0.7rem;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .animate-slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .animate-slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        /* Utility Classes */
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .font-semibold { font-weight: 600; }

        .gradient-success { background: linear-gradient(135deg, var(--success-500) 0%, var(--success-600) 100%); }
        .gradient-error { background: linear-gradient(135deg, var(--error-500) 0%, var(--error-600) 100%); }
        .gradient-warning { background: linear-gradient(135deg, var(--warning-500) 0%, var(--warning-600) 100%); }
    </style>
</head>
<body>
    <!-- Modern Header -->
    <header class="modern-header">
        <div class="header-content">
            <div class="header-brand">
                <a href="index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
                <div class="logo-container">
                    <i class="fas fa-book"></i>
                </div>
                <div class="brand-text">
                    <h1>SahabFormMaster</h1>
                    <p>Subject Management</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($teacher_name, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <p>Teacher</p>
                        <span><?php echo htmlspecialchars($teacher_name); ?></span>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Welcome Section -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h2 class="card-title-modern">
                    <i class="fas fa-graduation-cap"></i>
                    Subject Management Dashboard
                </h2>
                <p class="card-subtitle-modern">
                    Efficiently manage and organize your subjects and assignments
                </p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-modern">
            <div class="stat-card-modern stat-total animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-value-modern"><?php echo $total_subjects; ?></div>
                <div class="stat-label-modern">Total Subjects</div>
            </div>

            <!-- <div class="stat-card-modern stat-owned animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-user-edit"></i>
                </div>
                <div class="stat-value-modern"><?php echo $owned_subjects; ?></div>
                <div class="stat-label-modern">My Subjects</div>
            </div> -->

            <div class="stat-card-modern stat-assigned animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $assigned_subjects; ?></div>
                <div class="stat-label-modern">Assigned Subjects</div>
            </div>

            <div class="stat-card-modern stat-assignments animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-value-modern"><?php echo $total_assignments; ?></div>
                <div class="stat-label-modern">Total Assignments</div>
            </div>
        </div>

        <!-- Create Subject Section -->
        <!-- <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-plus-circle"></i>
                    Create New Subject
                </h3>
                <p class="card-subtitle-modern">
                    Add custom subjects to your teaching portfolio
                </p>
            </div>
            <div class="card-body-modern">
                <form method="POST" class="controls-modern">
                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Subject Name *</label>
                            <input type="text" class="form-input-modern" name="name" placeholder="Enter subject name" required>
                        </div>
                        <div class="form-group-modern">
                            <label class="form-label-modern">Subject Code</label>
                            <input type="text" class="form-input-modern" name="code" placeholder="Optional code">
                        </div>
                    </div>
                    <div class="form-row-modern">
                        <div class="form-group-modern" style="grid-column: 1 / -1;">
                            <label class="form-label-modern">Description</label>
                            <textarea class="form-textarea-modern" name="description" placeholder="Subject description" rows="3"></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn-modern-primary" name="action" value="create">
                        <i class="fas fa-plus"></i>
                        <span>Create Subject</span>
                    </button>
                </form>
            </div>
        </div> -->

        <!-- Alerts -->
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php safe_echo($_SESSION['success']); unset($_SESSION['success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php safe_echo($_SESSION['error']); unset($_SESSION['error']); ?></span>
            </div>
        <?php endif; ?>

        <?php if($errors): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?></span>
            </div>
        <?php endif; ?>

        <!-- All Subjects Section -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-list"></i>
                    All Subjects
                </h3>
                <p class="card-subtitle-modern">
                    Complete overview of all available subjects in the system
                </p>
            </div>
            <div class="card-body-modern">
                <div class="subjects-table-container">
                    <div class="table-header-modern">
                        <div class="table-title-modern">
                            <i class="fas fa-clipboard-list"></i>
                            Subject Directory
                        </div>
                        <div class="table-subtitle-modern">
                            <?php echo count($all_subjects); ?> Subjects Total
                        </div>
                    </div>

                    <div class="table-wrapper-modern">
                        <table class="subjects-table-modern">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Subject Name</th>
                                    <th>Code</th>
                                    <th>Ownership</th>
                                    <th>Assignment Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach($all_subjects as $s): ?>
                                    <tr>
                                        <td>
                                            <span class="subject-number-modern"><?php echo $counter++; ?></span>
                                        </td>
                                        <td>
                                            <div class="subject-name-modern"><?php echo htmlspecialchars($s['subject_name']); ?></div>
                                            <?php if($s['description']): ?>
                                                <div class="subject-code-modern"><?php echo htmlspecialchars(substr($s['description'], 0, 50)); ?><?php echo strlen($s['description']) > 50 ? '...' : ''; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="subject-code-modern"><?php echo htmlspecialchars($s['subject_code'] ?: '-'); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge-modern <?php echo ($s['created_by'] == $uid) ? 'badge-owned' : 'badge-not-owned'; ?>">
                                                <i class="fas <?php echo ($s['created_by'] == $uid) ? 'fa-check' : 'fa-times'; ?>"></i>
                                                <?php echo ($s['created_by'] == $uid) ? 'Owned' : 'System'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-modern <?php echo in_array($s['id'], $assigned_subject_ids) ? 'badge-assigned' : 'badge-not-assigned'; ?>">
                                                <i class="fas <?php echo in_array($s['id'], $assigned_subject_ids) ? 'fa-check' : 'fa-times'; ?>"></i>
                                                <?php echo in_array($s['id'], $assigned_subject_ids) ? 'Assigned' : 'Not Assigned'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons-modern">
                                                <button class="btn-edit-modern" onclick="editSubject(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars($s['subject_name']); ?>', '<?php echo htmlspecialchars($s['subject_code']); ?>', '<?php echo htmlspecialchars($s['description'] ?? ''); ?>')" <?php echo ($s['created_by'] != $uid) ? 'title="You can only edit subjects you created"' : ''; ?>>
                                                    <i class="fas fa-edit"></i>
                                                    <span>Edit</span>
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this subject?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                                                    <button type="submit" class="btn-delete-modern" <?php echo ($s['created_by'] != $uid) ? 'title="You can only delete subjects you created" disabled' : ''; ?>>
                                                        <i class="fas fa-trash"></i>
                                                        <span>Delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- My Assignments Section -->
        <!-- <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-user-check"></i>
                    My Subject Assignments
                </h3>
                <p class="card-subtitle-modern">
                    Subjects currently assigned to your classes
                </p>
            </div>
            <div class="card-body-modern">
                <?php if(empty($assignments)): ?>
                    <div class="empty-state-modern">
                        <div class="empty-icon-modern">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <h4 class="empty-title-modern">No Assignments Found</h4>
                        <p class="empty-description-modern">You haven't been assigned to any subjects yet. Contact your administrator for subject assignments.</p>
                    </div>
                <?php else: ?>
                    <div class="assignments-grid-modern">
                        <?php foreach($assignments as $a): ?>
                            <div class="assignment-card-modern">
                                <div class="assignment-icon-modern">
                                    <i class="fas fa-graduation-cap"></i>
                                </div>
                                <div class="assignment-details-modern">
                                    <h4><?php echo htmlspecialchars($a['class_name']); ?></h4>
                                    <p>Subject ID: <?php echo htmlspecialchars($a['subject_id']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div> -->

        
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal-modern">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern">
                    <i class="fas fa-edit"></i>
                    Edit Subject
                </h3>
                <button class="modal-close-modern" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="modal-body-modern">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-row-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Subject Name *</label>
                        <input type="text" class="form-input-modern" id="edit_name" name="name" required>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Subject Code</label>
                        <input type="text" class="form-input-modern" id="edit_code" name="code">
                    </div>
                </div>
                <div class="form-row-modern">
                    <div class="form-group-modern" style="grid-column: 1 / -1;">
                        <label class="form-label-modern">Description</label>
                        <textarea class="form-textarea-modern" id="edit_desc" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-actions-modern">
                    <button type="button" class="btn-secondary-modern" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-modern-primary">Update Subject</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function editSubject(id, name, code, description) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_code').value = code;
            document.getElementById('edit_desc').value = description || '';
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Header scroll effect
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.modern-header');
            if (window.scrollY > 50) {
                header.style.background = 'rgba(255, 255, 255, 0.98)';
                header.style.backdropFilter = 'blur(20px)';
            } else {
                header.style.background = 'rgba(255, 255, 255, 0.95)';
            }
        });

        // Add entrance animations on scroll
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

        // Observe all animated elements
        document.querySelectorAll('.animate-fade-in-up, .animate-slide-in-left, .animate-slide-in-right').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            observer.observe(el);
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
    </script><?php include '../includes/floating-button.php'; ?></body>
</html>
