<?php
// teacher/curriculum.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow teachers to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// Get current school context
$current_school_id = require_school_auth();

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

// Fetch teacher's assigned classes
$stmt = $pdo->prepare("SELECT DISTINCT c.* FROM classes c
                       JOIN curriculum cu ON c.id = cu.class_id
                       WHERE cu.teacher_id = :teacher_id
                       ORDER BY c.class_name ASC");
$stmt->execute(['teacher_id' => $teacher_id]);
$assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all classes for filter (school-filtered)
$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$stmt->execute([$current_school_id]);
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all subjects (school-filtered)
$stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name ASC");
$stmt->execute([$current_school_id]);
$all_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search and filter
$search = trim($_GET['search'] ?? '');
$filter_class = $_GET['filter_class'] ?? '';
$filter_term = $_GET['filter_term'] ?? '';
$filter_week = $_GET['filter_week'] ?? '';
$filter_subject = $_GET['filter_subject'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'active';

// Build query for teacher's curriculum (school-filtered)
$query = "SELECT DISTINCT c.*, cl.class_name
          FROM curriculum c
          LEFT JOIN classes cl ON c.class_id = cl.id AND cl.school_id = :school_id
          WHERE c.status = :status AND c.school_id = :school_id";
$params = ['status' => $filter_status, 'school_id' => $current_school_id, 'school_id' => $current_school_id];

// Add teacher filter only if not viewing all
if ($filter_status === 'active') {
    $query .= " AND (c.teacher_id = :teacher_id OR c.teacher_id IS NULL)";
    $params['teacher_id'] = $teacher_id;
}

if ($search !== '') {
    $query .= " AND (c.subject_name LIKE :search OR c.description LIKE :search OR c.topics LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($filter_class !== '' && $filter_class !== 'all') {
    $query .= " AND c.class_id = :class_id";
    $params['class_id'] = $filter_class;
}

if ($filter_term !== '' && $filter_term !== 'all') {
    $query .= " AND c.term = :term";
    $params['term'] = $filter_term;
}

if ($filter_week !== '' && $filter_week !== 'all') {
    $query .= " AND c.week = :week";
    $params['week'] = $filter_week;
}

if ($filter_subject !== '' && $filter_subject !== 'all') {
    $query .= " AND c.subject_id = :subject_id";
    $params['subject_id'] = $filter_subject;
}

// Get unique terms from curriculum (school-filtered)
$stmt = $pdo->prepare("SELECT DISTINCT term FROM curriculum WHERE term IS NOT NULL AND term != '' AND school_id = ? ORDER BY
    CASE term
        WHEN '1st Term' THEN 1
        WHEN '2nd Term' THEN 2
        WHEN '3rd Term' THEN 3
        ELSE 4
    END");
$stmt->execute([$current_school_id]);
$terms = $stmt->fetchAll(PDO::FETCH_COLUMN);

// If no terms found, use default terms
if (empty($terms)) {
    $terms = ['1st Term', '2nd Term', '3rd Term'];
}

// Execute main query
$query .= " ORDER BY c.term, c.week, c.status, c.grade_level, c.subject_name";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$curriculums = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to count topics
function countTopics($topics_string) {
    if (empty($topics_string)) return 0;
    $topics = array_filter(array_map('trim', explode("\n", $topics_string)));
    return count($topics);
}

// Function to get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'active': return 'status-active';
        case 'inactive': return 'status-inactive';
        default: return 'status-inactive';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Management | SahabFormMaster</title>
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

        .stat-curriculum .stat-icon-modern {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-assigned .stat-icon-modern {
            background: var(--gradient-success);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-subjects .stat-icon-modern {
            background: var(--gradient-accent);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-topics .stat-icon-modern {
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

        .btn-modern-secondary {
            padding: 1rem 2rem;
            background: white;
            color: var(--gray-700);
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-modern-secondary:hover {
            border-color: var(--primary-300);
            box-shadow: var(--shadow-medium);
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

        /* Curriculum Grid */
        .curriculum-table-container {
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

        .curriculum-count-modern {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .table-wrapper-modern {
            overflow-x: auto;
        }

        .curriculum-table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .curriculum-table-modern th {
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

        .curriculum-table-modern td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .curriculum-table-modern tr:nth-child(even) {
            background: var(--gray-50);
        }

        .curriculum-table-modern tr:hover {
            background: var(--primary-50);
        }

        .curriculum-subject-modern {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 1.125rem;
        }

        .curriculum-grade-modern {
            font-weight: 500;
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .curriculum-description-modern {
            color: var(--gray-600);
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .curriculum-meta-modern {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .meta-tag-modern {
            background: var(--primary-100);
            color: var(--primary-700);
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge-modern {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-active-modern {
            background: var(--success-100);
            color: var(--success-700);
        }

        .status-inactive-modern {
            background: var(--error-100);
            color: var(--error-700);
        }

        .action-buttons-modern {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-small-modern {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-view-modern {
            background: var(--primary-500);
            color: white;
        }

        .btn-view-modern:hover {
            background: var(--primary-600);
            transform: translateY(-2px);
        }

        .btn-edit-modern {
            background: var(--warning-500);
            color: white;
        }

        .btn-edit-modern:hover {
            background: var(--warning-600);
            transform: translateY(-2px);
        }

        .btn-delete-modern {
            background: var(--error-500);
            color: white;
        }

        .btn-delete-modern:hover {
            background: var(--error-600);
            transform: translateY(-2px);
        }

        /* Modal */
        .modal-modern {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
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
            max-width: 800px;
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

        .detail-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .detail-item-modern {
            margin-bottom: 1rem;
        }

        .detail-label-modern {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }

        .detail-value-modern {
            color: var(--gray-600);
            font-size: 0.95rem;
        }

        .topics-preview-modern {
            max-height: 200px;
            overflow-y: auto;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 0.5rem;
        }

        .topic-item-modern {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .topic-item-modern:last-child {
            border-bottom: none;
        }

        .topic-icon-modern {
            color: var(--accent-500);
            font-size: 0.75rem;
        }

        .modal-actions-modern {
            padding: 2rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Empty State */
        .empty-state-modern {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-600);
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: var(--shadow-soft);
        }

        .empty-icon-modern {
            font-size: 4rem;
            color: var(--gray-400);
            margin-bottom: 1rem;
        }

        .empty-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .empty-text-modern {
            font-size: 1rem;
            margin-bottom: 2rem;
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

            .actions-grid-modern {
                grid-template-columns: repeat(2, 1fr);
            }

            .table-header-modern {
                padding: 1rem;
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .curriculum-table-modern th,
            .curriculum-table-modern td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }

            .action-buttons-modern {
                flex-direction: column;
                gap: 0.25rem;
            }

            .btn-small-modern {
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
        .font-medium { font-weight: 500; }

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
                    <p>Curriculum Management</p>
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
                <a href="../logout.php" class="logout-btn">
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
                    Curriculum Management System
                </h2>
                <p class="card-subtitle-modern">
                    Efficiently manage and track curriculum content for your assigned classes
                </p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-modern">
            <?php
            $total_curriculum = count($curriculums);
            $assigned_curriculum = array_filter($curriculums, fn($c) => $c['teacher_id'] == $teacher_id);
            $unique_subjects = count(array_unique(array_column($curriculums, 'subject_name')));
            $total_topics = array_sum(array_map('countTopics', array_column($curriculums, 'topics')));
            ?>
            <div class="stat-card-modern stat-curriculum animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-value-modern"><?php echo $total_curriculum; ?></div>
                <div class="stat-label-modern">Total Curriculum</div>
            </div>

            <div class="stat-card-modern stat-assigned animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value-modern"><?php echo count($assigned_curriculum); ?></div>
                <div class="stat-label-modern">Assigned to Me</div>
            </div>

            <div class="stat-card-modern stat-subjects animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-flask"></i>
                </div>
                <div class="stat-value-modern"><?php echo $unique_subjects; ?></div>
                <div class="stat-label-modern">Subjects</div>
            </div>

            <div class="stat-card-modern stat-topics animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-list-ol"></i>
                </div>
                <div class="stat-value-modern"><?php echo $total_topics; ?></div>
                <div class="stat-label-modern">Total Topics</div>
            </div>
        </div>

        <!-- Controls Section -->
        <div class="controls-modern animate-fade-in-up">
            <form method="GET" class="filter-form">
                <div class="form-row-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Search</label>
                        <input type="text" class="form-input-modern" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by subject, description, or topics">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Class</label>
                        <select class="form-input-modern" name="filter_class">
                            <option value="all">All Classes</option>
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo intval($class['id']); ?>"
                                    <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Term</label>
                        <select class="form-input-modern" name="filter_term">
                            <option value="all">All Terms</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo htmlspecialchars($term); ?>"
                                    <?php echo $filter_term === $term ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($term); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Week</label>
                        <input type="number" class="form-input-modern" name="filter_week"
                               value="<?php echo htmlspecialchars($filter_week !== 'all' ? $filter_week : ''); ?>"
                               placeholder="Enter week number" min="0" max="52">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Subject</label>
                        <select class="form-input-modern" name="filter_subject">
                            <option value="all">All Subjects</option>
                            <?php foreach ($all_subjects as $subject): ?>
                                <option value="<?php echo intval($subject['id']); ?>"
                                    <?php echo $filter_subject == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Status</label>
                        <select class="form-input-modern" name="filter_status">
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="submit" class="btn-modern-primary">
                        <i class="fas fa-filter"></i>
                        <span>Apply Filters</span>
                    </button>
                    <a href="curricullum.php" class="btn-modern-secondary">
                        <i class="fas fa-redo"></i>
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </div>

        <!-- Quick Actions -->
        <div class="actions-modern animate-fade-in-up">
            <div class="actions-grid-modern">
                <a href="content_coverage.php" class="action-btn-modern">
                    <i class="fas fa-clipboard-check action-icon-modern"></i>
                    <span class="action-text-modern">Content Coverage</span>
                </a>
                <button class="action-btn-modern" onclick="exportCurriculum()">
                    <i class="fas fa-download action-icon-modern"></i>
                    <span class="action-text-modern">Export Report</span>
                </button>
                <button class="action-btn-modern" onclick="printCurriculum()">
                    <i class="fas fa-print action-icon-modern"></i>
                    <span class="action-text-modern">Print Curriculum</span>
                </button>
                <button class="action-btn-modern" onclick="createLessonPlan()">
                    <i class="fas fa-plus action-icon-modern"></i>
                    <span class="action-text-modern">Create Lesson</span>
                </button>
                <button class="action-btn-modern" onclick="viewAnalytics()">
                    <i class="fas fa-chart-bar action-icon-modern"></i>
                    <span class="action-text-modern">View Analytics</span>
                </button>
            </div>
        </div>

        <!-- Curriculum Table -->
        <?php if (empty($curriculums)): ?>
            <div class="empty-state-modern animate-fade-in-up">
                <i class="fas fa-book-open empty-icon-modern"></i>
                <h3 class="empty-title-modern">No Curriculum Found</h3>
                <p class="empty-text-modern">No curriculum matches your current filters. Try adjusting your search criteria.</p>
                <a href="curricullum.php" class="btn-modern-primary">Clear Filters</a>
            </div>
        <?php else: ?>
            <div class="curriculum-table-container animate-fade-in-up">
                <div class="table-header-modern">
                    <div class="table-title-modern">
                        <i class="fas fa-clipboard-list"></i>
                        Curriculum Overview
                    </div>
                    <div class="curriculum-count-modern">
                        <?php echo count($curriculums); ?> Items
                    </div>
                </div>

                <div class="table-wrapper-modern">
                    <table class="curriculum-table-modern">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Class & Term</th>
                                <th>Description</th>
                                <th>Topics</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($curriculums as $curriculum): ?>
                                <tr>
                                    <td>
                                        <div class="curriculum-subject-modern">
                                            <?php echo htmlspecialchars($curriculum['subject_name']); ?>
                                        </div>
                                        <div class="curriculum-grade-modern">
                                            <?php echo htmlspecialchars($curriculum['grade_level']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="curriculum-meta-modern">
                                            <?php if ($curriculum['class_name']): ?>
                                                <span class="meta-tag-modern">
                                                    <i class="fas fa-users"></i> <?php echo htmlspecialchars($curriculum['class_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($curriculum['term']): ?>
                                                <span class="meta-tag-modern">
                                                    <i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($curriculum['term']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($curriculum['week'] > 0): ?>
                                                <span class="meta-tag-modern">
                                                    <i class="fas fa-calendar-week"></i> Week <?php echo intval($curriculum['week']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="curriculum-description-modern">
                                            <?php echo htmlspecialchars(substr($curriculum['description'] ?? '', 0, 100)); ?>
                                            <?php if (strlen($curriculum['description'] ?? '') > 100): ?>...<?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $topics_count = countTopics($curriculum['topics']);
                                        echo $topics_count > 0 ? $topics_count . ' topics' : 'No topics';
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge-modern <?php echo getStatusBadgeClass($curriculum['status']); ?>-modern">
                                            <?php echo ucfirst($curriculum['status']); ?>
                                        </span>
                                        <?php if ($curriculum['teacher_id'] == $teacher_id): ?>
                                            <br><small style="color: var(--success-600); font-weight: 600;">Assigned to Me</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons-modern">
                                            <button class="btn-small-modern btn-view-modern"
                                                    onclick="viewCurriculumDetails(<?php echo htmlspecialchars(json_encode($curriculum)); ?>)">
                                                <i class="fas fa-eye"></i>
                                                <span>View</span>
                                            </button>
                                            <button class="btn-small-modern btn-edit-modern"
                                                    onclick="printCurriculumItem(<?php echo intval($curriculum['id']); ?>)">
                                                <i class="fas fa-print"></i>
                                                <span>Print</span>
                                            </button>
                                            <?php if ($curriculum['teacher_id'] == $teacher_id): ?>
                                                <button class="btn-small-modern btn-edit-modern"
                                                        onclick="createLessonPlanFromCurriculum(<?php echo intval($curriculum['id']); ?>)">
                                                    <i class="fas fa-plus"></i>
                                                    <span>Lesson</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        
    </div>

    <!-- Curriculum Details Modal -->
    <div class="modal-modern" id="curriculumModal">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern" id="modalTitle">Curriculum Details</h3>
                <button class="modal-close-modern" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body-modern" id="modalContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-actions-modern">
                <button class="btn-modern-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                    <span>Close</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        // View curriculum details
        function viewCurriculumDetails(curriculum) {
            const modal = document.getElementById('curriculumModal');
            const modalContent = document.getElementById('modalContent');
            const modalTitle = document.getElementById('modalTitle');

            let topicsHtml = '';
            if (curriculum.topics) {
                const topics = curriculum.topics.split('\n').filter(t => t.trim());
                topicsHtml = topics.map(topic =>
                    `<div class="topic-item-modern">
                        <i class="fas fa-chevron-right topic-icon-modern"></i>
                        <span>${topic.trim()}</span>
                    </div>`
                ).join('');
            }

            modalContent.innerHTML = `
                <div class="detail-grid-modern">
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Subject</div>
                        <div class="detail-value-modern">${curriculum.subject_name}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Grade Level</div>
                        <div class="detail-value-modern">${curriculum.grade_level}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Class</div>
                        <div class="detail-value-modern">${curriculum.class_name || 'Not assigned'}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Term</div>
                        <div class="detail-value-modern">${curriculum.term || 'Not specified'}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Week</div>
                        <div class="detail-value-modern">${curriculum.week || 'Not specified'}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Duration</div>
                        <div class="detail-value-modern">${curriculum.duration || 'Not specified'}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Status</div>
                        <div class="detail-value-modern">
                            <span class="status-badge-modern ${curriculum.status === 'active' ? 'status-active' : 'status-inactive'}-modern">
                                ${curriculum.status ? curriculum.status.charAt(0).toUpperCase() + curriculum.status.slice(1) : 'N/A'}
                            </span>
                        </div>
                    </div>
                </div>

                ${curriculum.description ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Description</div>
                        <div class="detail-value-modern">${curriculum.description}</div>
                    </div>
                ` : ''}

                ${curriculum.learning_objectives ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Learning Objectives</div>
                        <div class="detail-value-modern">${curriculum.learning_objectives}</div>
                    </div>
                ` : ''}

                ${curriculum.resources ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Resources</div>
                        <div class="detail-value-modern">${curriculum.resources}</div>
                    </div>
                ` : ''}

                ${curriculum.assessment_methods ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Assessment Methods</div>
                        <div class="detail-value-modern">${curriculum.assessment_methods}</div>
                    </div>
                ` : ''}

                ${curriculum.prerequisites ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Prerequisites</div>
                        <div class="detail-value-modern">${curriculum.prerequisites}</div>
                    </div>
                ` : ''}

                ${topicsHtml ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Topics/Modules</div>
                        <div class="topics-preview-modern">
                            ${topicsHtml}
                        </div>
                    </div>
                ` : ''}
            `;

            modalTitle.textContent = curriculum.subject_name;
            modal.style.display = 'block';
        }

        // Close modal
        function closeModal() {
            document.getElementById('curriculumModal').style.display = 'none';
        }

        // Print curriculum item
        function printCurriculumItem(id) {
            window.open(`../admin/print_curriculum.php?id=${id}`, '_blank');
        }

        // Print all curriculum
        function printCurriculum() {
            const params = new URLSearchParams(window.location.search);
            window.open(`../admin/print_curriculum.php?${params.toString()}`, '_blank');
        }

        // Create lesson plan from curriculum
        function createLessonPlanFromCurriculum(curriculumId) {
            window.location.href = `lesson-plan.php?curriculum_id=${curriculumId}`;
        }

        // Export curriculum
        function exportCurriculum() {
            const params = new URLSearchParams(window.location.search);
            window.open(`../admin/export_curriculum.php?${params.toString()}`, '_blank');
        }

        // Create lesson plan
        function createLessonPlan() {
            window.location.href = 'lesson-plan.php';
        }

        // View analytics
        function viewAnalytics() {
            window.location.href = 'curriculum-analytics.php';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('curriculumModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
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
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
