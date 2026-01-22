<?php
// filepath: c:\xampp\htdocs\sahabformmaster\student\schoolfeed.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];
$current_school_id = get_current_school_id();

// Fetch news relevant to student (All + Students + their specific class)
$query = "SELECT * FROM school_news
          WHERE status = 'published'
          AND school_id = :school_id
          AND (target_audience = 'All'
               OR target_audience = 'Students'
               OR target_audience LIKE :class)
          ORDER BY published_date DESC
          LIMIT 20";

$stmt = $pdo->prepare($query);
$class_param = isset($_SESSION['class']) ? '%' . $_SESSION['class'] . '%' : '%';
$stmt->execute(['school_id' => $current_school_id, 'class' => $class_param]);
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Feed | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* ===================================================
           Modern Student Dashboard - Internal Styles
           =================================================== */

        :root {
            /* Enhanced Color Palette */
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #a5b4fc;
            --secondary-color: #06b6d4;
            --accent-color: #8b5cf6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #3b82f6;

            /* Advanced Gradients */
            --gradient-primary: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            --gradient-secondary: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            --gradient-accent: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-error: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --gradient-info: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            --gradient-news: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-card: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);

            /* Enhanced Neutrals */
            --white: #ffffff;
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

            /* Premium Shadows */
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);

            /* Modern Border Radius */
            --border-radius-sm: 0.5rem;
            --border-radius-md: 0.75rem;
            --border-radius-lg: 1rem;
            --border-radius-xl: 1.5rem;
            --border-radius-2xl: 2rem;

            /* Smooth Transitions */
            --transition-fast: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);
            min-height: 100vh;
            color: var(--gray-800);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* ===================================================
           Mobile Menu Toggle
           =================================================== */

        .mobile-menu-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 9999;
            background: var(--gradient-primary);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            transition: var(--transition-normal);
            backdrop-filter: blur(10px);
        }

        .mobile-menu-toggle:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: var(--shadow-xl);
        }

        .mobile-menu-toggle.active {
            background: var(--gradient-error);
            transform: scale(1.1);
        }

        /* ===================================================
           Header Styles
           =================================================== */

        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 1000;
            transition: var(--transition-normal);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .dashboard-header.scrolled {
            box-shadow: var(--shadow-md);
            backdrop-filter: blur(30px);
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 80px;
        }

        .school-logo-container {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .school-logo {
            width: 50px;
            height: 50px;
            border-radius: var(--border-radius-lg);
            object-fit: cover;
            box-shadow: var(--shadow-md);
            transition: var(--transition-normal);
        }

        .school-logo:hover {
            transform: scale(1.05);
        }

        .school-info h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.125rem;
        }

        .school-tagline {
            font-size: 0.875rem;
            color: var(--gray-500);
            font-weight: 500;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .student-info {
            text-align: right;
        }

        .student-label {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .student-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-900);
            display: block;
        }

        .admission-number {
            font-size: 0.875rem;
            color: var(--primary-color);
            font-weight: 500;
        }

        .btn-logout {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--gradient-error);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius-lg);
            font-weight: 500;
            transition: var(--transition-normal);
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }

        .btn-logout::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition-slow);
        }

        .btn-logout:hover::before {
            left: 100%;
        }

        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-xl);
        }

        /* ===================================================
           Dashboard Container & Sidebar
           =================================================== */

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 80px);
        }

        .sidebar {
            width: 280px;
            background: var(--white);
            box-shadow: var(--shadow-lg);
            position: fixed;
            left: 0;
            top: 80px;
            height: calc(100vh - 80px);
            overflow-y: auto;
            z-index: 999;
            transition: var(--transition-normal);
            border-right: 1px solid var(--gray-200);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--gradient-card);
        }

        .sidebar-header h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .sidebar-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--gray-400);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--border-radius-md);
            transition: var(--transition-fast);
        }

        .sidebar-close:hover {
            background: var(--gray-100);
            color: var(--gray-600);
            transform: scale(1.1);
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-list {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 0.25rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            color: var(--gray-600);
            text-decoration: none;
            transition: var(--transition-normal);
            border-left: 3px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background: var(--gradient-primary);
            transition: var(--transition-normal);
            z-index: -1;
        }

        .nav-link:hover::before {
            width: 100%;
        }

        .nav-link:hover {
            color: white;
            border-left-color: var(--primary-color);
            transform: translateX(5px);
        }

        .nav-link.active {
            background: var(--gradient-primary);
            color: white;
            border-left-color: var(--primary-color);
            box-shadow: var(--shadow-md);
        }

        .nav-link.active::before {
            width: 100%;
        }

        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .nav-text {
            font-weight: 500;
            font-size: 0.95rem;
        }

        /* ===================================================
           Main Content Area
           =================================================== */

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100vw - 280px);
            background: transparent;
        }

        /* ===================================================
           Modern Card Components
           =================================================== */

        .card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: var(--transition-normal);
            border: 1px solid rgba(255, 255, 255, 0.8);
            position: relative;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            opacity: 0;
            transition: var(--transition-normal);
        }

        .card:hover {
            box-shadow: var(--shadow-2xl);
            transform: translateY(-8px);
        }

        .card:hover::before {
            opacity: 1;
        }

        .card-header {
            background: var(--gradient-news);
            color: white;
            padding: 1.5rem;
            border-radius: var(--border-radius-xl) var(--border-radius-xl) 0 0;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .card-body {
            padding: 1.5rem;
        }

        .card-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--gray-900);
        }

        .card-text {
            color: var(--gray-600);
            line-height: 1.7;
            margin-bottom: 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius-lg);
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition-normal);
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition-slow);
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-md);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-xl);
        }

        /* ===================================================
           News Feed Specific Styles
           =================================================== */

        .welcome-section {
            background: var(--gradient-news);
            color: white;
            border-radius: var(--border-radius-2xl);
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: float 6s infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateX(0) translateY(0) scale(1); }
            50% { transform: translateX(-20px) translateY(-10px) scale(1.05); }
        }

        .welcome-section h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .welcome-section p {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 1.5rem;
            text-align: center;
            box-shadow: var(--shadow-md);
            transition: var(--transition-normal);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary-light);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto 1rem;
        }

        .stat-icon.news { background: var(--gradient-primary); color: white; }
        .stat-icon.views { background: var(--gradient-success); color: white; }
        .stat-icon.priority { background: var(--gradient-accent); color: white; }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray-600);
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.05em;
        }

        .news-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }

        .news-card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: var(--transition-normal);
            border: 1px solid var(--gray-200);
            position: relative;
        }

        .news-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-2xl);
        }

        .news-header {
            background: var(--gradient-news);
            color: white;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .news-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }

        .news-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .news-category {
            font-size: 0.875rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .news-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            transition: var(--transition-normal);
        }

        .news-image:hover {
            transform: scale(1.05);
        }

        .news-placeholder {
            width: 100%;
            height: 200px;
            background: var(--gradient-news);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
        }

        .news-content {
            padding: 1.5rem;
        }

        .news-excerpt {
            color: var(--gray-600);
            line-height: 1.7;
            margin-bottom: 1rem;
        }

        .news-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--gray-500);
            font-size: 0.875rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .news-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .news-read-more {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--gradient-primary);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius-lg);
            font-weight: 500;
            transition: var(--transition-normal);
            width: 100%;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .news-read-more::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition-slow);
        }

        .news-read-more:hover::before {
            left: 100%;
        }

        .news-read-more:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-xl);
        }

        /* ===================================================
           Footer Styles
           =================================================== */

        .dashboard-footer {
            background: linear-gradient(135deg, var(--gray-900) 0%, var(--gray-800) 100%);
            color: var(--gray-300);
            margin-top: 4rem;
            position: relative;
        }

        .dashboard-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .footer-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 3rem 2rem 1rem;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-section h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 1rem;
            position: relative;
        }

        .footer-section h4::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 30px;
            height: 2px;
            background: var(--gradient-primary);
        }

        .footer-section p {
            line-height: 1.6;
            margin-bottom: 1rem;
            color: var(--gray-400);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: var(--gray-300);
            text-decoration: none;
            transition: var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-links a::before {
            content: '→';
            transition: var(--transition-fast);
            opacity: 0;
        }

        .footer-links a:hover::before {
            opacity: 1;
            transform: translateX(5px);
        }

        .footer-links a:hover {
            color: var(--primary-color);
            transform: translateX(10px);
        }

        .footer-bottom {
            padding-top: 2rem;
            border-top: 1px solid var(--gray-700);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .footer-copyright {
            color: var(--gray-400);
            font-size: 0.9rem;
        }

        .footer-version {
            color: var(--gray-400);
            font-size: 0.9rem;
            background: rgba(255,255,255,0.1);
            padding: 0.25rem 0.75rem;
            border-radius: var(--border-radius-md);
        }

        .footer-bottom-links {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .footer-bottom-links a {
            color: var(--gray-400);
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition-fast);
        }

        .footer-bottom-links a:hover {
            color: var(--primary-color);
        }

        /* ===================================================
           Responsive Design
           =================================================== */

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1.5rem;
                width: 100%;
                max-width: 100%;
            }

            .mobile-menu-toggle {
                display: flex;
            }

            .news-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                padding: 0 1rem;
                height: 70px;
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .school-logo-container {
                order: 1;
                flex: 1;
                min-width: 0;
            }

            .school-info h1 {
                font-size: 1.25rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .school-tagline {
                font-size: 0.8rem;
            }

            .header-right {
                order: 2;
                gap: 0.75rem;
            }

            .welcome-section h2 {
                font-size: 1.75rem;
                line-height: 1.3;
            }

            .welcome-section p {
                font-size: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .news-grid {
                gap: 1rem;
            }

            .news-card {
                border-radius: var(--border-radius-lg);
            }

            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 1.5rem;
                margin-bottom: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .header-container {
                padding: 0 0.75rem;
                height: auto;
                min-height: 60px;
                padding-top: 0.5rem;
                padding-bottom: 0.5rem;
                position: relative;
            }

            .mobile-menu-toggle {
                position: absolute;
                top: 5px;
                left: 5px;
                z-index: 999;
            }

            .school-logo {
                width: 40px;
                height: 40px;
            }

            .school-info h1 {
                font-size: 1.1rem;
            }

            .school-tagline {
                font-size: 0.75rem;
            }

            .header-right {
                gap: 0.5rem;
            }

            .student-info {
                text-align: left;
            }

            .student-label {
                font-size: 0.7rem;
            }

            .student-name {
                font-size: 0.85rem;
            }

            .admission-number {
                font-size: 0.75rem;
            }

            .btn-logout {
                padding: 0.5rem 0.75rem;
                font-size: 0.85rem;
            }

            .btn-logout span:last-child {
                display: none;
            }

            .mobile-menu-toggle {
                top: 15px;
                left: 15px;
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
            }

            .sidebar {
                top: 65px;
                height: calc(100vh - 65px);
            }

            .sidebar-header {
                padding: 1rem;
            }

            .sidebar-header h3 {
                font-size: 1.1rem;
            }

            .welcome-section {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .welcome-section h2 {
                font-size: 1.5rem;
                margin-bottom: 0.75rem;
            }

            .welcome-section p {
                font-size: 0.95rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1.25rem;
                margin-bottom: 0.75rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .stat-label {
                font-size: 0.8rem;
            }

            .news-content {
                padding: 1rem;
            }

            .news-title {
                font-size: 1rem;
            }

            .news-meta {
                font-size: 0.8rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }

            .main-content {
                padding: 0.75rem;
            }

            .dashboard-footer {
                margin-top: 2rem;
            }

            .footer-container {
                padding: 2rem 1rem 1rem;
            }

            .footer-bottom {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .footer-bottom-links {
                justify-content: center;
            }
        }

        /* ===================================================
           Animation Classes
           =================================================== */

        .fade-in {
            animation: fadeInUp 0.8s ease-out;
        }

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

        .slide-in-left {
            animation: slideInLeft 0.6s ease-out;
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

        .scale-in {
            animation: scaleIn 0.5s ease-out;
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Loading states */
        .loading {
            position: relative;
            pointer-events: none;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid var(--gray-300);
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Utility classes */
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .hidden { display: none !important; }
        .block { display: block !important; }
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
                        <p class="school-tagline">Student Portal</p>
                    </div>
                </div>
            </div>

            <!-- Student Info and Logout -->
            <div class="header-right">
                <div class="student-info">
                    <p class="student-label">Student</p>
                    <span class="student-name"><?php echo htmlspecialchars($student_name); ?></span>
                    <span class="admission-number"><?php echo htmlspecialchars($admission_number); ?></span>
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
        <?php include '../includes/student_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Welcome Section -->
            <div class="welcome-section fade-in">
                <h2><i class="fas fa-newspaper"></i> School News Feed</h2>
                <p>Stay updated with the latest news and announcements from your school</p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card slide-in-left">
                    <div class="stat-icon news">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <div class="stat-value"><?php echo count($news_items); ?></div>
                    <div class="stat-label">News Articles</div>
                </div>
                <div class="stat-card slide-in-left" style="animation-delay: 0.1s;">
                    <div class="stat-icon views">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-value"><?php echo array_sum(array_column($news_items, 'view_count')); ?></div>
                    <div class="stat-label">Total Views</div>
                </div>
                <div class="stat-card slide-in-left" style="animation-delay: 0.2s;">
                    <div class="stat-icon priority">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value"><?php echo count(array_filter($news_items, fn($n) => $n['priority'] === 'high')); ?></div>
                    <div class="stat-label">High Priority</div>
                </div>
            </div>

            <?php if (empty($news_items)): ?>
                <div class="card scale-in">
                    <div class="card-body text-center" style="padding: 3rem;">
                        <div style="font-size: 3rem; color: var(--gray-400); margin-bottom: 1rem;">
                            <i class="fas fa-newspaper"></i>
                        </div>
                        <h4 style="color: var(--gray-600); margin-bottom: 0.5rem;">No news available for your class yet</h4>
                        <p style="color: var(--gray-600); margin: 0;">Check back later for school announcements and updates!</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="news-grid">
                    <?php $index = 0; foreach ($news_items as $item): ?>
                        <div class="news-card scale-in" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                            <div class="news-header">
                                <div class="news-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                <div class="news-category"><?php echo htmlspecialchars($item['category']); ?></div>
                            </div>

                            <?php if ($item['featured_image']): ?>
                                <img src="../<?php echo htmlspecialchars($item['featured_image']); ?>"
                                     alt="<?php echo htmlspecialchars($item['title']); ?>"
                                     class="news-image">
                            <?php else: ?>
                                <div class="news-placeholder">
                                    <i class="fas fa-newspaper"></i>
                                </div>
                            <?php endif; ?>

                            <div class="news-content">
                                <div class="news-excerpt">
                                    <?php
                                    $content = strip_tags($item['content']);
                                    $excerpt = strlen($content) > 150 ? substr($content, 0, 150) . '...' : $content;
                                    echo htmlspecialchars($excerpt);
                                    ?>
                                </div>

                                <div class="news-meta">
                                    <div class="news-meta-item">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo date('M d, Y', strtotime($item['published_date'])); ?>
                                    </div>
                                    <div class="news-meta-item">
                                        <i class="fas fa-eye"></i>
                                        <?php echo intval($item['view_count']); ?> views
                                    </div>
                                    <?php if ($item['allow_comments']): ?>
                                        <div class="news-meta-item">
                                            <i class="fas fa-comment"></i>
                                            <?php echo intval($item['comment_count'] ?? 0); ?> comments
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <a href="newsdetails.php?id=<?php echo $item['id']; ?>" class="news-read-more">
                                    <i class="fas fa-book-open"></i>
                                    Read Full Story
                                </a>
                            </div>
                        </div>
                    <?php $index++; endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ===================================================
        // Modern Dashboard JavaScript
        // ===================================================

        document.addEventListener('DOMContentLoaded', function() {
            // Mobile Menu Toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarClose = document.getElementById('sidebarClose');

            if (mobileMenuToggle && sidebar) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                    mobileMenuToggle.classList.toggle('active');

                    // Animate hamburger icon
                    const icon = mobileMenuToggle.querySelector('i');
                    if (sidebar.classList.contains('active')) {
                        icon.style.transform = 'rotate(90deg)';
                    } else {
                        icon.style.transform = 'rotate(0deg)';
                    }
                });
            }

            if (sidebarClose) {
                sidebarClose.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    if (mobileMenuToggle) {
                        mobileMenuToggle.classList.remove('active');
                        const icon = mobileMenuToggle.querySelector('i');
                        icon.style.transform = 'rotate(0deg)';
                    }
                });
            }

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 1024) {
                    if (sidebar && !sidebar.contains(e.target) && mobileMenuToggle && !mobileMenuToggle.contains(e.target)) {
                        sidebar.classList.remove('active');
                        mobileMenuToggle.classList.remove('active');
                        const icon = mobileMenuToggle.querySelector('i');
                        icon.style.transform = 'rotate(0deg)';
                    }
                }
            });

            // Header scroll effect
            const header = document.querySelector('.dashboard-header');
            let lastScrollTop = 0;

            window.addEventListener('scroll', function() {
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;

                if (scrollTop > 50) {
                    header.classList.add('scrolled');
                } else {
                    header.classList.remove('scrolled');
                }

                lastScrollTop = scrollTop;
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

            // Enhanced card hover effects
            document.querySelectorAll('.news-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-10px) scale(1.02)';
                    this.style.boxShadow = 'var(--shadow-2xl)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = 'var(--shadow-lg)';
                });
            });

            // Stat card hover effects
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    const icon = this.querySelector('.stat-icon');
                    icon.style.transform = 'scale(1.1) rotate(5deg)';
                    icon.style.transition = 'transform 0.3s ease';
                });

                card.addEventListener('mouseleave', function() {
                    const icon = this.querySelector('.stat-icon');
                    icon.style.transform = 'scale(1) rotate(0deg)';
                });
            });

            // Button loading states
            document.querySelectorAll('.news-read-more').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    const originalHtml = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                    this.classList.add('loading');

                    // Reset after navigation simulation
                    setTimeout(() => {
                        this.innerHTML = originalHtml;
                        this.classList.remove('loading');
                    }, 1500);
                });
            });

            // Intersection Observer for animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';

                        // Add staggered animation for news cards
                        if (entry.target.classList.contains('news-card')) {
                            const delay = Array.from(entry.target.parentNode.children).indexOf(entry.target) * 0.1;
                            entry.target.style.transitionDelay = delay + 's';
                        }
                    }
                });
            }, observerOptions);

            // Observe elements for animation
            document.querySelectorAll('.news-card, .stat-card').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(el);
            });

            // Typing effect for welcome section (optional)
            const welcomeText = document.querySelector('.welcome-section h2');
            if (welcomeText) {
                const originalText = welcomeText.textContent;
                welcomeText.textContent = '';
                let i = 0;

                function typeWriter() {
                    if (i < originalText.length) {
                        welcomeText.textContent += originalText.charAt(i);
                        i++;
                        setTimeout(typeWriter, 50);
                    }
                }

                // Start typing effect after a delay
                setTimeout(typeWriter, 500);
            }

            // Parallax effect for background
            window.addEventListener('scroll', function() {
                const scrolled = window.pageYOffset;
                const rate = scrolled * -0.5;

                document.body.style.backgroundPosition = 'center ' + rate + 'px';
            });

            // Auto-refresh dashboard data (simulate)
            setInterval(() => {
                // Update view counts randomly for demo
                document.querySelectorAll('.stat-value').forEach(stat => {
                    if (Math.random() > 0.95) { // 5% chance to update
                        const current = parseInt(stat.textContent);
                        const change = Math.floor(Math.random() * 3) - 1; // -1, 0, or 1
                        const newValue = Math.max(0, current + change);
                        stat.textContent = newValue;

                        // Add flash effect
                        stat.style.color = 'var(--primary-color)';
                        setTimeout(() => {
                            stat.style.color = 'var(--gray-900)';
                        }, 500);
                    }
                });
            }, 30000); // Every 30 seconds

            // Keyboard navigation
            document.addEventListener('keydown', function(e) {
                // Escape key closes sidebar
                if (e.key === 'Escape' && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    if (mobileMenuToggle) {
                        mobileMenuToggle.classList.remove('active');
                        const icon = mobileMenuToggle.querySelector('i');
                        icon.style.transform = 'rotate(0deg)';
                    }
                }
            });

            // Touch gestures for mobile
            let startX = 0;
            let startY = 0;

            document.addEventListener('touchstart', function(e) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
            });

            document.addEventListener('touchend', function(e) {
                if (!startX || !startY) return;

                const endX = e.changedTouches[0].clientX;
                const endY = e.changedTouches[0].clientY;
                const diffX = startX - endX;
                const diffY = startY - endY;

                // Swipe left to close sidebar
                if (Math.abs(diffX) > Math.abs(diffY) && diffX > 50) {
                    if (sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        if (mobileMenuToggle) {
                            mobileMenuToggle.classList.remove('active');
                            const icon = mobileMenuToggle.querySelector('i');
                            icon.style.transform = 'rotate(0deg)';
                        }
                    }
                }

                startX = 0;
                startY = 0;
            });

            // Performance optimization: Lazy load images
            const images = document.querySelectorAll('.news-image');
            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.classList.add('loaded');
                        imageObserver.unobserve(img);
                    }
                });
            });

            images.forEach(img => imageObserver.observe(img));

            // Add pulse effect to unread items (simulate)
            setInterval(() => {
                const cards = document.querySelectorAll('.news-card');
                const randomCard = cards[Math.floor(Math.random() * cards.length)];
                if (randomCard && Math.random() > 0.9) {
                    randomCard.style.animation = 'pulse 0.5s ease-in-out';
                    setTimeout(() => {
                        randomCard.style.animation = '';
                    }, 500);
                }
            }, 10000);

            console.log('Modern Dashboard initialized successfully!');
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
