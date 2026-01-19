<?php
// teacher/permissions.php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}

// School authentication and context
$current_school_id = require_school_auth();

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_type = $_POST['request_type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $duration_hours = $_POST['duration_hours'] ?? '';
    $priority = $_POST['priority'] ?? 'medium';

    // Validate inputs
    if (empty($title) || empty($start_date)) {
        $error = "Title and start date are required!";
    } else {
        try {
            // Handle file upload
            $attachment_path = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/permissions/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_name = time() . '_' . basename($_FILES['attachment']['name']);
                $target_file = $upload_dir . $file_name;

                // Check file type and size
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $max_size = 5 * 1024 * 1024; // 5MB

                if (in_array($_FILES['attachment']['type'], $allowed_types) &&
                    $_FILES['attachment']['size'] <= $max_size) {
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file);
                    $attachment_path = $target_file;
                }
            }

            // Insert permission request
            $stmt = $pdo->prepare("
                INSERT INTO permissions (
                    school_id, staff_id, request_type, title, description,
                    start_date, end_date, duration_hours, priority,
                    attachment_path, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $stmt->execute([
                $current_school_id, $user_id, $request_type, $title, $description,
                $start_date, $end_date ?: null, $duration_hours ?: null,
                $priority, $attachment_path
            ]);

            $message = "Permission request submitted successfully!";

        } catch (PDOException $e) {
            $error = "Error submitting request: " . $e->getMessage();
        }
    }
}

// Fetch teacher's permission requests
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name as approved_by_name
        FROM permissions p
        LEFT JOIN users u ON p.approved_by = u.id
        WHERE p.staff_id = ? AND p.school_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$user_id, $current_school_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching requests: " . $e->getMessage();
    $requests = [];
}

// Get statistics
try {
    $totalRequests = count($requests);
    $pendingRequests = count(array_filter($requests, function($r) { return $r['status'] === 'pending'; }));
    $approvedRequests = count(array_filter($requests, function($r) { return $r['status'] === 'approved'; }));
    $rejectedRequests = count(array_filter($requests, function($r) { return $r['status'] === 'rejected'; }));
} catch (Exception $e) {
    $totalRequests = $pendingRequests = $approvedRequests = $rejectedRequests = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Requests | SahabFormMaster</title>
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

        .stat-pending .stat-icon-modern {
            background: var(--gradient-warning);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-approved .stat-icon-modern {
            background: var(--gradient-success);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-rejected .stat-icon-modern {
            background: var(--gradient-error);
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

        /* Quick Actions */
        .actions-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .actions-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .action-btn-modern {
            padding: 1.25rem 1.5rem;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--gray-700);
            box-shadow: var(--shadow-soft);
            position: relative;
            overflow: hidden;
        }

        .action-btn-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.1), transparent);
            transition: left 0.5s;
        }

        .action-btn-modern:hover::before {
            left: 100%;
        }

        .action-btn-modern:hover {
            transform: translateY(-4px);
            border-color: var(--primary-300);
            box-shadow: var(--shadow-strong);
        }

        .action-icon-modern {
            font-size: 1.5rem;
            color: var(--primary-600);
            transition: transform 0.3s ease;
        }

        .action-btn-modern:hover .action-icon-modern {
            transform: scale(1.1);
        }

        .action-text-modern {
            font-weight: 600;
            font-size: 0.875rem;
            text-align: center;
        }

        /* Permissions Table */
        .permissions-table-container {
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

        .table-wrapper-modern {
            overflow-x: auto;
        }

        .permissions-table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .permissions-table-modern th {
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

        .permissions-table-modern td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .permissions-table-modern tr:nth-child(even) {
            background: var(--gray-50);
        }

        .permissions-table-modern tr:hover {
            background: var(--primary-50);
        }

        .request-id-modern {
            font-weight: 600;
            color: var(--gray-900);
        }

        .request-type-modern {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 1.125rem;
        }

        .request-title-modern {
            font-weight: 600;
            color: var(--gray-900);
        }

        .status-badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .status-pending-modern {
            background: var(--warning-100);
            color: var(--warning-700);
        }

        .status-approved-modern {
            background: var(--success-100);
            color: var(--success-700);
        }

        .status-rejected-modern {
            background: var(--error-100);
            color: var(--error-700);
        }

        .status-cancelled-modern {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .priority-badge-modern {
            padding: 0.375rem 0.75rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .priority-low-modern {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .priority-medium-modern {
            background: var(--warning-100);
            color: var(--warning-700);
        }

        .priority-high-modern {
            background: var(--error-100);
            color: var(--error-700);
        }

        .priority-urgent-modern {
            background: var(--error-100);
            color: var(--error-700);
            font-weight: bold;
        }

        .action-buttons-modern {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-modern-sm {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            text-decoration: none;
        }

        .btn-primary-modern {
            background: var(--primary-500);
            color: white;
        }

        .btn-primary-modern:hover {
            background: var(--primary-600);
            transform: translateY(-2px);
        }

        .btn-danger-modern {
            background: var(--error-500);
            color: white;
        }

        .btn-danger-modern:hover {
            background: var(--error-600);
            transform: translateY(-2px);
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

        /* Modal Styles */
        .modal-modern {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
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
            max-width: 600px;
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

        .modal-footer-modern {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .file-upload-modern {
            border: 2px dashed var(--gray-300);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: var(--gray-50);
        }

        .file-upload-modern:hover {
            border-color: var(--primary-400);
            background: var(--primary-50);
        }

        .file-upload-modern input[type="file"] {
            display: none;
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

            .actions-grid-modern {
                grid-template-columns: repeat(2, 1fr);
            }

            .table-header-modern {
                padding: 1rem;
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .permissions-table-modern th,
            .permissions-table-modern td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }

            .action-buttons-modern {
                flex-direction: column;
                gap: 0.25rem;
            }

            .btn-modern-sm {
                padding: 0.375rem 0.5rem;
                font-size: 0.7rem;
            }
        }

        @media (max-width: 480px) {
            .stats-modern {
                grid-template-columns: 1fr;
            }

            .actions-grid-modern {
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
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="brand-text">
                    <h1>SahabFormMaster</h1>
                    <p>Permission Requests</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'T', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <p>Teacher</p>
                        <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
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
                    <i class="fas fa-clipboard-check"></i>
                    Permission Request Management
                </h2>
                <p class="card-subtitle-modern">
                    Submit and track your permission requests efficiently
                </p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-modern">
            <div class="stat-card-modern stat-total animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-list"></i>
                </div>
                <div class="stat-value-modern"><?php echo $totalRequests; ?></div>
                <div class="stat-label-modern">Total Requests</div>
            </div>

            <div class="stat-card-modern stat-pending animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value-modern"><?php echo $pendingRequests; ?></div>
                <div class="stat-label-modern">Pending</div>
            </div>

            <div class="stat-card-modern stat-approved animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $approvedRequests; ?></div>
                <div class="stat-label-modern">Approved</div>
            </div>

            <div class="stat-card-modern stat-rejected animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $rejectedRequests; ?></div>
                <div class="stat-label-modern">Rejected</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="actions-modern animate-fade-in-up">
            <div class="actions-grid-modern">
                <button class="action-btn-modern" onclick="openRequestModal()">
                    <i class="fas fa-plus action-icon-modern"></i>
                    <span class="action-text-modern">New Request</span>
                </button>
                <button class="action-btn-modern" onclick="exportRequests()">
                    <i class="fas fa-download action-icon-modern"></i>
                    <span class="action-text-modern">Export Report</span>
                </button>
                <button class="action-btn-modern" onclick="filterByStatus('pending')">
                    <i class="fas fa-clock action-icon-modern"></i>
                    <span class="action-text-modern">View Pending</span>
                </button>
                <button class="action-btn-modern" onclick="filterByStatus('approved')">
                    <i class="fas fa-check-circle action-icon-modern"></i>
                    <span class="action-text-modern">View Approved</span>
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <!-- Permissions Table -->
        <div class="permissions-table-container animate-fade-in-up">
            <div class="table-header-modern">
                <div class="table-title-modern">
                    <i class="fas fa-table"></i>
                    Permission Requests (<?php echo count($requests); ?>)
                </div>
            </div>

            <div class="table-wrapper-modern">
                <table class="permissions-table-modern">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Date</th>
                            <th>Duration</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Approved By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="9" class="text-center" style="padding: 3rem;">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3" style="color: var(--gray-400);"></i>
                                    <p style="color: var(--gray-500); font-size: 1.1rem;">No permission requests found</p>
                                    <p style="color: var(--gray-400); margin-top: 0.5rem;">Click "New Request" to submit your first permission request</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td>
                                        <span class="request-id-modern">#<?php echo str_pad($request['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                    </td>
                                    <td>
                                        <span class="request-type-modern"><?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?></span>
                                    </td>
                                    <td>
                                        <span class="request-title-modern"><?php echo htmlspecialchars($request['title']); ?></span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($request['start_date'])); ?></td>
                                    <td>
                                        <?php if ($request['duration_hours']): ?>
                                            <?php echo $request['duration_hours']; ?> hours
                                        <?php else: ?>
                                            Full day
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="priority-badge-modern priority-<?php echo $request['priority']; ?>-modern">
                                            <?php echo ucfirst($request['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge-modern status-<?php echo $request['status']; ?>-modern">
                                            <i class="fas fa-circle"></i>
                                            <span><?php echo ucfirst($request['status']); ?></span>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $request['approved_by_name'] ?: '—'; ?>
                                        <?php if ($request['approved_at']): ?>
                                            <br><small style="color: var(--gray-500);"><?php echo date('M d', strtotime($request['approved_at'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons-modern">
                                            <button class="btn-modern-sm btn-primary-modern" onclick="viewRequestDetails(<?php echo $request['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                                <span>View</span>
                                            </button>
                                            <?php if ($request['status'] === 'pending'): ?>
                                                <button class="btn-modern-sm btn-danger-modern" onclick="cancelRequest(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-times"></i>
                                                    <span>Cancel</span>
                                                </button>
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

        
    </div>

    <!-- Request Modal -->
    <div class="modal-modern" id="requestModal">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern">New Permission Request</h3>
                <button class="modal-close-modern" onclick="closeRequestModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body-modern">
                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Request Type *</label>
                            <select class="form-input-modern" name="request_type" required>
                                <option value="">Select type</option>
                                <option value="leave">Leave</option>
                                <option value="early_departure">Early Departure</option>
                                <option value="late_arrival">Late Arrival</option>
                                <option value="personal_work">Personal Work</option>
                                <option value="training">Training/Workshop</option>
                                <option value="emergency">Emergency</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group-modern">
                            <label class="form-label-modern">Priority *</label>
                            <select class="form-input-modern" name="priority" required>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern">Title *</label>
                        <input type="text" class="form-input-modern" name="title" placeholder="Brief title for your request" required>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern">Description *</label>
                        <textarea class="form-input-modern" name="description" rows="3"
                                  placeholder="Provide details about your request..." required></textarea>
                    </div>

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Start Date & Time *</label>
                            <input type="datetime-local" class="form-input-modern" name="start_date" required>
                        </div>
                        <div class="form-group-modern">
                            <label class="form-label-modern">End Date & Time (Optional)</label>
                            <input type="datetime-local" class="form-input-modern" name="end_date">
                        </div>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern">Duration (hours, leave empty for full day)</label>
                        <input type="number" class="form-input-modern" name="duration_hours"
                               step="0.5" min="0.5" max="24" placeholder="e.g., 2.5">
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern">Attachment (Optional)</label>
                        <div class="file-upload-modern">
                            <i class="fas fa-cloud-upload-alt fa-2x" style="color: var(--primary-500); margin-bottom: 1rem;"></i>
                            <p style="margin-bottom: 0.5rem; font-weight: 500;">Click to upload supporting document</p>
                            <p style="color: var(--gray-500); font-size: 0.875rem;">Max size: 5MB | Formats: JPG, PNG, PDF, DOC</p>
                            <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                        </div>
                        <div id="file-name" style="margin-top: 1rem; font-size: 0.875rem; color: var(--gray-600);"></div>
                    </div>
                </div>
                <div class="modal-footer-modern">
                    <button type="button" class="btn-modern-secondary" onclick="closeRequestModal()">Cancel</button>
                    <button type="submit" class="btn-modern-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal-modern" id="detailsModal">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern">Request Details</h3>
                <button class="modal-close-modern" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body-modern">
                <div id="detailsContent"></div>
            </div>
            <div class="modal-footer-modern">
                <button type="button" class="btn-modern-secondary" onclick="closeDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openRequestModal() {
            document.getElementById('requestModal').style.display = 'block';
        }

        function closeRequestModal() {
            document.getElementById('requestModal').style.display = 'none';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // File upload display
        document.querySelector('input[type="file"]').addEventListener('change', function(e) {
            const fileName = document.getElementById('file-name');
            if (this.files.length > 0) {
                fileName.textContent = 'Selected: ' + this.files[0].name;
                fileName.style.color = 'var(--success-600)';
            } else {
                fileName.textContent = '';
            }
        });

        // View request details
        function viewRequestDetails(requestId) {
            // Find the request data from the table row
            const rows = document.querySelectorAll('.permissions-table-modern tbody tr');
            let requestData = null;

            rows.forEach(row => {
                const idCell = row.querySelector('.request-id-modern');
                if (idCell && idCell.textContent.trim() === '#' + requestId.toString().padStart(5, '0')) {
                    // Extract data from the row
                    const cells = row.querySelectorAll('td');
                    requestData = {
                        id: requestId,
                        type: cells[1].textContent.trim(),
                        title: cells[2].textContent.trim(),
                        date: cells[3].textContent.trim(),
                        duration: cells[4].textContent.trim(),
                        priority: cells[5].textContent.trim(),
                        status: cells[6].textContent.trim(),
                        approved_by: cells[7].textContent.trim()
                    };
                }
            });

            if (requestData) {
                const content = `
                    <div style="line-height: 1.6;">
                        <h4 style="margin-bottom: 1.5rem; color: var(--gray-900);">${requestData.title}</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div><strong>Type:</strong> ${requestData.type}</div>
                            <div><strong>Date:</strong> ${requestData.date}</div>
                            <div><strong>Duration:</strong> ${requestData.duration}</div>
                            <div><strong>Priority:</strong> <span class="priority-badge-modern priority-${requestData.priority.toLowerCase()}-modern">${requestData.priority}</span></div>
                            <div><strong>Status:</strong> <span class="status-badge-modern status-${requestData.status.toLowerCase()}-modern">${requestData.status}</span></div>
                            <div><strong>Approved By:</strong> ${requestData.approved_by || 'Not approved yet'}</div>
                        </div>
                    </div>
                `;
                document.getElementById('detailsContent').innerHTML = content;
                document.getElementById('detailsModal').style.display = 'block';
            }
        }

        // Cancel request
        function cancelRequest(requestId) {
            if (confirm('Are you sure you want to cancel this request?')) {
                window.location.href = `cancel_permission.php?id=${requestId}`;
            }
        }

        // Quick actions
        function exportRequests() {
            alert('Export functionality would be implemented here');
        }

        function filterByStatus(status) {
            // Simple client-side filtering
            const rows = document.querySelectorAll('.permissions-table-modern tbody tr');
            rows.forEach(row => {
                const statusCell = row.querySelector('td:nth-child(7)');
                if (statusCell) {
                    const statusText = statusCell.textContent.toLowerCase();
                    if (status === 'all' || statusText.includes(status)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
        }

        // Close modals when clicking outside
        document.getElementById('requestModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRequestModal();
            }
        });

        document.getElementById('detailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailsModal();
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
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
