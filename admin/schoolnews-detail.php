<?php
// filepath: c:\xampp\htdocs\sahabformmaster\admin\schoolnews-detail.php
session_start();
require_once '../config/db.php';

// Get news ID from URL
$news_id = intval($_GET['id'] ?? 0);
if ($news_id <= 0) {
    header("Location: schoolnews.php");
    exit;
}

// Fetch news details
$stmt = $pdo->prepare("SELECT sn.*, u.full_name as author_name 
                      FROM school_news sn 
                      JOIN users u ON sn.author_id = u.id 
                      WHERE sn.id = :id");
$stmt->execute(['id' => $news_id]);
$news = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$news) {
    header("Location: schoolnews.php");
    exit;
}

// Check permissions: principal can view all, others only published
$is_principal = isset($_SESSION['user_id']) && $_SESSION['role'] === 'principal';
$user_id = $_SESSION['user_id'] ?? null;

if ($news['status'] !== 'published' && !$is_principal) {
    header("Location: schoolnews.php");
    exit;
}

// Increment view count
$stmt = $pdo->prepare("UPDATE school_news SET view_count = view_count + 1 WHERE id = :id");
$stmt->execute(['id' => $news_id]);

// Track view (if user logged in)
if ($user_id) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO school_news_views (news_id, user_id, ip_address) 
                          VALUES (:news_id, :user_id, :ip_address)");
    $stmt->execute([
        'news_id' => $news_id,
        'user_id' => $user_id,
        'ip_address' => $_SERVER['REMOTE_ADDR']
    ]);
}

$errors = [];
$success = '';

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $news['allow_comments']) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_comment') {
        $commenter_name = trim($_POST['name'] ?? '');
        $commenter_email = trim($_POST['email'] ?? '');
        $comment_text = trim($_POST['comment'] ?? '');

        if ($commenter_name === '') {
            $errors[] = 'Name is required.';
        }
        if ($commenter_email === '' || !filter_var($commenter_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required.';
        }
        if ($comment_text === '') {
            $errors[] = 'Comment cannot be empty.';
        }
        if (strlen($comment_text) > 1000) {
            $errors[] = 'Comment must not exceed 1000 characters.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO school_news_comments 
                                  (news_id, user_id, name, email, comment, status) 
                                  VALUES (:news_id, :user_id, :name, :email, :comment, :status)");
            $stmt->execute([
                'news_id' => $news_id,
                'user_id' => $user_id,
                'name' => $commenter_name,
                'email' => $commenter_email,
                'comment' => $comment_text,
                'status' => 'pending'
            ]);
            $success = 'Your comment has been submitted and is awaiting moderation.';
            // Refresh page
            header("Location: schoolnews-detail.php?id=" . $news_id);
            exit;
        }
    }

    if ($action === 'delete_comment' && $is_principal) {
        $comment_id = intval($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM school_news_comments WHERE id = :id AND news_id = :news_id");
            $stmt->execute(['id' => $comment_id, 'news_id' => $news_id]);
            $success = 'Comment deleted.';
            header("Location: schoolnews-detail.php?id=" . $news_id);
            exit;
        }
    }

    if ($action === 'approve_comment' && $is_principal) {
        $comment_id = intval($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $stmt = $pdo->prepare("UPDATE school_news_comments SET status = :status WHERE id = :id AND news_id = :news_id");
            $stmt->execute(['status' => 'approved', 'id' => $comment_id, 'news_id' => $news_id]);
            $success = 'Comment approved.';
            header("Location: schoolnews-detail.php?id=" . $news_id);
            exit;
        }
    }

    if ($action === 'reject_comment' && $is_principal) {
        $comment_id = intval($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $stmt = $pdo->prepare("UPDATE school_news_comments SET status = :status WHERE id = :id AND news_id = :news_id");
            $stmt->execute(['status' => 'rejected', 'id' => $comment_id, 'news_id' => $news_id]);
            $success = 'Comment rejected.';
            header("Location: schoolnews-detail.php?id=" . $news_id);
            exit;
        }
    }
}

// Fetch approved comments (and pending if principal)
$comment_query = "SELECT * FROM school_news_comments WHERE news_id = :news_id";
if ($is_principal) {
    $comment_query .= " ORDER BY created_at DESC";
} else {
    $comment_query .= " AND status = 'approved' ORDER BY created_at DESC";
}

$stmt = $pdo->prepare($comment_query);
$stmt->execute(['news_id' => $news_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get related news (same category, excluding current)
$stmt = $pdo->prepare("SELECT id, title, featured_image, excerpt, published_date 
                      FROM school_news 
                      WHERE category = :category AND id != :id AND status = 'published' 
                      ORDER BY published_date DESC 
                      LIMIT 4");
$stmt->execute(['category' => $news['category'], 'id' => $news_id]);
$related_news = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format content (handle markdown-like formatting)
function formatContent($text) {
    // Convert markdown-like syntax to HTML
    $text = htmlspecialchars($text);
    
    // Bold **text**
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    
    // Italic *text*
    $text = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $text);
    
    // Underline __text__
    $text = preg_replace('/__(.+?)__/s', '<u>$1</u>', $text);
    
    // Headings ## text
    $text = preg_replace('/^## (.*?)$/m', '<h2 class="detail-h2">$1</h2>', $text);
    $text = preg_replace('/^### (.*?)$/m', '<h3 class="detail-h3">$1</h3>', $text);
    
    // Code blocks ```code```
    $text = preg_replace('/```(.*?)```/s', '<pre class="code-block"><code>$1</code></pre>', $text);
    
    // Inline code `code`
    $text = preg_replace('/`(.+?)`/', '<code class="inline-code">$1</code>', $text);
    
    // Blockquotes > text
    $text = preg_replace('/^> (.*?)$/m', '<blockquote class="detail-quote">$1</blockquote>', $text);
    
    // Line breaks
    $text = nl2br($text);
    
    return $text;
}

$user_name = $_SESSION['full_name'] ?? 'Guest';
$user_role = $_SESSION['role'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?php echo htmlspecialchars($news['title']); ?> | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/admin-unified.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===================================================
           Sidebar and Layout Styles - Matching Schoolnews Page
           =================================================== */

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 999;
            background: #4f46e5;
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
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            background: #3730a3;
            transform: scale(1.1);
        }

        .mobile-menu-toggle.active {
            background: #ef4444;
        }

        /* Dashboard Layout */
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 80px);
            max-width: 1400px;
            margin: 2rem auto;
            gap: 2rem;
            padding: 0 1rem;
        }

        .sidebar {
            width: 280px;
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            position: fixed;
            left: 0;
            top: 80px;
            height: calc(100vh - 80px);
            overflow-y: auto;
            z-index: 999;
            transition: transform 0.3s ease;
            border-radius: 0 1rem 1rem 0;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
        }

        .sidebar-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #9ca3af;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.375rem;
            transition: all 0.3s ease;
        }

        .sidebar-close:hover {
            background: #f3f4f6;
            color: #374151;
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
            color: #4b5563;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-link:hover {
            background: #f9fafb;
            color: #4f46e5;
            border-left-color: #4f46e5;
        }

        .nav-link.active {
            background: #4f46e5;
            color: white;
            border-left-color: #4f46e5;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
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

        .main-content {
            flex: 1;
            margin-left: 280px;
            max-width: calc(100vw - 280px);
        }

        /* Responsive Design for Sidebar */
        @media (max-width: 1200px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 999;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
            }

            .mobile-menu-toggle {
                display: flex;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0 0.5rem;
                gap: 1rem;
            }
        }

        /* ===================================================
           Updated Main Content Layout
           =================================================== */

        .detail-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
            min-height: calc(100vh - 80px);
        }
        :root {
            /* Color Palette - Matching Teacher Dashboard */
            --primary-color: #4f46e5;
            --primary-dark: #3730a3;
            --secondary-color: #06b6d4;
            --accent-color: #f59e0b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #3b82f6;

            /* Gradient Colors for Cards - Matching Teacher Dashboard */
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-3: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-4: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --gradient-5: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --gradient-6: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);

            /* Neutral Colors - Matching Teacher Dashboard */
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

            /* Shadows - Matching Teacher Dashboard */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);

            /* Border Radius - Matching Teacher Dashboard */
            --border-radius-sm: 0.375rem;
            --border-radius-md: 0.5rem;
            --border-radius-lg: 0.75rem;
            --border-radius-xl: 1rem;

            /* Transitions - Matching Teacher Dashboard */
            --transition-fast: 0.15s ease-in-out;
            --transition-normal: 0.3s ease-in-out;
            --transition-slow: 0.5s ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--dark-color);
        }

        /* Full-width layout without sidebar */
        .detail-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
            min-height: calc(100vh - 80px);
        }

        /* Page header styles */
        .page-header {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
        }

        .page-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .btn-dashboard-back {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .btn-dashboard-back:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: white;
        }

        .btn-dashboard-back i {
            font-size: 1.1rem;
        }

        .page-title {
            text-align: center;
        }

        .page-title h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
        }

        .page-title p {
            color: var(--gray-color);
            font-size: 1.1rem;
            margin: 0;
        }

        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .school-logo-container {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .school-logo {
            height: 50px;
            width: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
        }

        .school-name {
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .teacher-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .teacher-name {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .teacher-role {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .btn-logout {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 0.6rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .btn-logout:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .detail-main {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .news-article {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease;
        }

        .news-article:hover {
            transform: translateY(-5px);
        }

        .article-header {
            margin-bottom: 2rem;
            border-bottom: 2px solid #f8f9fa;
            padding-bottom: 2rem;
        }

        .article-meta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }

        .category-badge, .priority-badge, .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .category-badge {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .priority-badge.priority-high {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .priority-badge.priority-medium {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .priority-badge.priority-low {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }

        .status-badge.status-published {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .status-badge.status-draft {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }

        .status-badge.status-scheduled {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .article-title {
            font-size: 3rem;
            font-weight: 700;
            color: var(--secondary-color);
            line-height: 1.2;
            margin-bottom: 1.5rem;
        }

        .article-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .info-item .label {
            font-size: 0.85rem;
            color: var(--gray-color);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-item .value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark-color);
        }

        .article-featured {
            margin: 2rem 0;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .featured-image {
            width: 100%;
            height: auto;
            max-height: 500px;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .featured-image:hover {
            transform: scale(1.02);
        }

        .article-excerpt {
            font-size: 1.25rem;
            line-height: 1.6;
            color: var(--gray-color);
            margin: 2rem 0;
            font-style: italic;
            padding: 2rem;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            border-left: 5px solid var(--primary-color);
        }

        .article-content {
            font-size: 1.1rem;
            line-height: 1.8;
            color: var(--dark-color);
            margin: 2rem 0;
        }

        .article-content h2.detail-h2 {
            font-size: 2rem;
            color: var(--secondary-color);
            margin: 2rem 0 1rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 3px solid var(--primary-color);
        }

        .article-content h3.detail-h3 {
            font-size: 1.5rem;
            color: var(--secondary-color);
            margin: 1.5rem 0 1rem 0;
        }

        .article-content blockquote.detail-quote {
            margin: 1.5rem 0;
            padding: 1.5rem 2rem;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 5px solid var(--warning-color);
            font-style: italic;
            color: var(--gray-color);
        }

        .article-content pre.code-block {
            background: #2d3748;
            color: #e2e8f0;
            padding: 1.5rem;
            border-radius: 10px;
            overflow-x: auto;
            margin: 1.5rem 0;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }

        .article-content code.inline-code {
            background: #f1f5f9;
            color: #475569;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }

        .article-tags {
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 15px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .tags-label {
            font-weight: 600;
            color: var(--secondary-color);
        }

        .tag {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            text-decoration: none;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .tag:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .admin-actions {
            margin: 2rem 0;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--warning-color), var(--danger-color));
            border-radius: 15px;
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .admin-actions .btn-gold, .admin-actions .btn-secondary {
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .admin-actions .btn-gold:hover, .admin-actions .btn-secondary:hover {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .comments-section {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 2rem;
            color: var(--secondary-color);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .comment-form-wrapper {
            margin-bottom: 3rem;
            padding: 2rem;
            background: #f8f9fa;
            border-radius: 15px;
        }

        .comment-form-wrapper h3 {
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
        }

        .comment-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-control {
            padding: 0.9rem 1.2rem;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            background: white;
        }

        .char-count {
            font-size: 0.85rem;
            color: var(--gray-color);
            text-align: right;
        }

        .btn-gold {
            background: linear-gradient(135deg, var(--gold-color), #FFA500);
            color: var(--dark-color);
            padding: 0.9rem 2rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn-gold:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(255, 215, 0, 0.3);
        }

        .comments-list {
            margin-top: 2rem;
        }

        .comments-list h3 {
            color: var(--secondary-color);
            margin-bottom: 2rem;
            font-size: 1.5rem;
        }

        .comment-item {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 5px solid var(--primary-color);
            transition: all 0.3s ease;
        }

        .comment-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .comment-author {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .comment-author strong {
            color: var(--secondary-color);
            font-size: 1.1rem;
        }

        .comment-date {
            font-size: 0.85rem;
            color: var(--gray-color);
        }

        .comment-status {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .status-pending, .status-approved, .status-rejected {
            font-size: 0.85rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .status-pending {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .status-approved {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .status-rejected {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-approve-comment, .btn-reject-comment, .btn-delete-comment {
            background: none;
            border: none;
            padding: 0.5rem;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .btn-approve-comment:hover {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .btn-reject-comment:hover {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .btn-delete-comment:hover {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .comment-body {
            color: var(--dark-color);
            line-height: 1.6;
        }

        .no-comments {
            text-align: center;
            padding: 3rem;
            color: var(--gray-color);
        }

        .no-comments p {
            font-size: 1.1rem;
            font-style: italic;
        }

        .related-section {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .related-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }

        .related-card {
            background: #f8f9fa;
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .related-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .related-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .related-image-placeholder {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
        }

        .related-content {
            padding: 1.5rem;
        }

        .related-content h4 {
            color: var(--secondary-color);
            margin-bottom: 1rem;
            font-size: 1.2rem;
            line-height: 1.4;
        }

        .related-content p {
            color: var(--gray-color);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .related-content small {
            color: var(--gray-color);
            font-size: 0.9rem;
        }

        .read-more {
            display: inline-block;
            margin-top: 1rem;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .read-more:hover {
            color: var(--secondary-color);
            transform: translateX(5px);
        }

        .alert {
            padding: 1.5rem 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            font-weight: 500;
            border-left: 5px solid;
            font-size: 1rem;
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border-color: #dc2626;
        }

        .alert-success {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #16a34a;
        }

        .alert-info {
            background: #eff6ff;
            color: #2563eb;
            border-color: #2563eb;
        }

        .dashboard-footer {
            background: #1a202c;
            background: linear-gradient(135deg, #1a202c 0%, #2d3748 100%);
            color: white;
            padding: 3rem 2rem 1.5rem;
            margin-top: 3rem;
            border-top: 4px solid #4f46e5;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
        }

        .footer-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
            margin-bottom: 2rem;
        }

        .footer-section h4 {
            color: var(--gold-color);
            margin-bottom: 1.2rem;
            font-size: 1.2rem;
        }

        .footer-links {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .footer-links a {
            color: #adb5bd;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: white;
            transform: translateX(5px);
        }

        .footer-bottom {
            border-top: 1px solid #495057;
            padding-top: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .detail-main {
                grid-template-columns: 1fr;
            }

            .article-title {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 768px) {
            .detail-container {
                padding: 1rem;
            }

            .page-header {
                padding: 1.5rem;
            }

            .page-title h1 {
                font-size: 2rem;
            }

            .article-title {
                font-size: 2rem;
            }

            .news-article {
                padding: 2rem;
            }

            .comments-section {
                padding: 2rem;
            }

            .related-section {
                padding: 2rem;
            }

            .article-info {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            .admin-actions {
                flex-direction: column;
                align-items: center;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .comment-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .related-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .page-header-content {
                flex-direction: column;
                text-align: center;
            }

            .article-meta {
                justify-content: center;
                flex-wrap: wrap;
            }

            .article-title {
                font-size: 1.75rem;
            }

            .news-article {
                padding: 1.5rem;
            }

            .comments-section {
                padding: 1.5rem;
            }

            .related-section {
                padding: 1.5rem;
            }

            .section-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<header class="dashboard-header">
    <div class="header-container">
        <div class="school-logo-container">
            <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
            <h1 class="school-name">SahabFormMaster</h1>
        </div>

        <div class="header-left">
            <div class="teacher-info">
                <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="teacher-role"><?php echo ucfirst($user_role); ?></span>
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<!-- Mobile Menu Toggle -->
<button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
    <span class="icon icon-menu"></span>
</button>

<div class="dashboard-container">
    <!-- Sidebar Navigation -->
    <?php include '../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="detail-container">
    <div class="page-header">
        <div class="page-header-content">
            <div class="page-title">
                <h1><i class="fas fa-newspaper"></i> News Article</h1>
                <p><?php echo htmlspecialchars($news['title']); ?></p>
            </div>
        </div>
    </div>

    <main class="detail-main">
        <!-- Article Section -->
        <article class="news-article">
            <!-- Header -->
            <header class="article-header">
                <div class="article-meta">
                    <span class="category-badge"><?php echo htmlspecialchars($news['category']); ?></span>
                    <span class="priority-badge priority-<?php echo $news['priority']; ?>">
                        <?php echo ucfirst($news['priority']); ?> Priority
                    </span>
                    <span class="status-badge status-<?php echo $news['status']; ?>">
                        <?php echo ucfirst($news['status']); ?>
                    </span>
                </div>

                <h1 class="article-title"><?php echo htmlspecialchars($news['title']); ?></h1>

                <div class="article-info">
                    <div class="info-item">
                        <span class="label">By</span>
                        <span class="value"><?php echo htmlspecialchars($news['author_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Published</span>
                        <span class="value"><?php echo date('F d, Y • h:i A', strtotime($news['published_date'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">For</span>
                        <span class="value"><?php echo htmlspecialchars($news['target_audience']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Views</span>
                        <span class="value"><?php echo intval($news['view_count']); ?></span>
                    </div>
                </div>
            </header>

            <!-- Featured Image -->
            <?php if ($news['featured_image']): ?>
            <div class="article-featured">
                <img src="../<?php echo htmlspecialchars($news['featured_image']); ?>" 
                     alt="<?php echo htmlspecialchars($news['title']); ?>" 
                     class="featured-image">
            </div>
            <?php endif; ?>

            <!-- Excerpt -->
            <?php if ($news['excerpt']): ?>
            <div class="article-excerpt">
                <p><?php echo htmlspecialchars($news['excerpt']); ?></p>
            </div>
            <?php endif; ?>

            <!-- Content -->
            <div class="article-content">
                <?php echo formatContent($news['content']); ?>
            </div>

            <!-- Tags -->
            <?php if ($news['tags']): ?>
            <div class="article-tags">
                <span class="tags-label">Tags:</span>
                <?php 
                $tags = array_map('trim', explode(',', $news['tags']));
                foreach ($tags as $tag): 
                ?>
                    <a href="schoolnews.php?search=<?php echo urlencode($tag); ?>" class="tag">
                        #<?php echo htmlspecialchars($tag); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Admin Actions -->
            <?php if ($is_principal): ?>
            <div class="admin-actions">
                <a href="schoolnews.php?edit=<?php echo intval($news['id']); ?>" class="btn-gold">✏️ Edit</a>
                <a href="schoolnews.php" class="btn-secondary">📋 All News</a>
            </div>
            <?php endif; ?>
        </article>

        <!-- Errors/Success -->
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Comments Section -->
        <?php if ($news['allow_comments']): ?>
        <section class="comments-section">
            <h2 class="section-title">💬 Comments</h2>

            <!-- Comment Form -->
            <div class="comment-form-wrapper">
                <h3>Add Your Comment</h3>
                <form method="POST" class="comment-form">
                    <input type="hidden" name="action" value="add_comment">

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="name">Name *</label>
                                <input type="text" id="name" name="name" class="form-control" 
                                       placeholder="Your name" required maxlength="100">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="email">Email *</label>
                                <input type="email" id="email" name="email" class="form-control" 
                                       placeholder="your.email@example.com" required maxlength="100">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="comment">Comment *</label>
                        <textarea id="comment" name="comment" class="form-control" rows="4" 
                                  placeholder="Share your thoughts... (max 1000 characters)" 
                                  required maxlength="1000"></textarea>
                        <small class="char-count"><span id="char-current">0</span>/1000</small>
                    </div>

                    <button type="submit" class="btn-gold">Post Comment</button>
                </form>
            </div>

            <!-- Comments List -->
            <div class="comments-list">
                <h3><?php echo count($comments); ?> Comment<?php echo count($comments) !== 1 ? 's' : ''; ?></h3>

                <?php if (count($comments) === 0): ?>
                    <div class="no-comments">
                        <p>No comments yet. Be the first to comment!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item">
                            <div class="comment-header">
                                <div class="comment-author">
                                    <strong><?php echo htmlspecialchars($comment['name']); ?></strong>
                                    <span class="comment-date">
                                        <?php echo date('M d, Y • h:i A', strtotime($comment['created_at'])); ?>
                                    </span>
                                </div>
                                <?php if ($is_principal): ?>
                                    <div class="comment-status">
                                        <?php if ($comment['status'] === 'pending'): ?>
                                            <span class="status-pending">⏳ Pending</span>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="approve_comment">
                                                <input type="hidden" name="comment_id" value="<?php echo intval($comment['id']); ?>">
                                                <button type="submit" class="btn-approve-comment" title="Approve">✓</button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="reject_comment">
                                                <input type="hidden" name="comment_id" value="<?php echo intval($comment['id']); ?>">
                                                <button type="submit" class="btn-reject-comment" title="Reject">✗</button>
                                            </form>
                                        <?php elseif ($comment['status'] === 'approved'): ?>
                                            <span class="status-approved">✓ Approved</span>
                                        <?php else: ?>
                                            <span class="status-rejected">✗ Rejected</span>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this comment?');">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?php echo intval($comment['id']); ?>">
                                            <button type="submit" class="btn-delete-comment" title="Delete">🗑️</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="comment-body">
                                <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        <?php else: ?>
            <div class="alert alert-info">
                <p>💭 Comments are disabled for this news item.</p>
            </div>
        <?php endif; ?>

        <!-- Related News -->
        <?php if (count($related_news) > 0): ?>
        <section class="related-section">
            <h2 class="section-title">📚 Related News</h2>
            <div class="related-grid">
                <?php foreach ($related_news as $item): ?>
                <div class="related-card">
                    <?php if ($item['featured_image']): ?>
                        <img src="../<?php echo htmlspecialchars($item['featured_image']); ?>" 
                             alt="<?php echo htmlspecialchars($item['title']); ?>" 
                             class="related-image">
                    <?php else: ?>
                        <div class="related-image-placeholder">📰</div>
                    <?php endif; ?>
                    <div class="related-content">
                        <h4><?php echo htmlspecialchars($item['title']); ?></h4>
                        <p><?php echo htmlspecialchars(substr($item['excerpt'], 0, 100)); ?>...</p>
                        <small><?php echo date('M d, Y', strtotime($item['published_date'])); ?></small>
                        <a href="schoolnews-detail.php?id=<?php echo intval($item['id']); ?>" class="read-more">Read More →</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        </div>
    </main>
</div>

<!-- <footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Professional school management system.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php">Dashboard</a>
                    <a href="schoolnews.php">News Management</a>
                    <a href="manage_user.php">Users</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p>Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Version 1.0</p>
        </div>
    </div>
</footer> -->

<script>
// Character counter for comment
const commentTextarea = document.getElementById('comment');
const charCurrent = document.getElementById('char-current');

if (commentTextarea) {
    commentTextarea.addEventListener('input', function() {
        charCurrent.textContent = this.value.length;
    });
}

// Mobile sidebar toggle functionality
const mobileMenuToggle = document.getElementById('mobileMenuToggle');
const sidebar = document.querySelector('.sidebar');

if (mobileMenuToggle && sidebar) {
    mobileMenuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
        this.classList.toggle('active');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 1200) {
            if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            }
        }
    });
}

// Handle responsive layout on window resize
function handleResponsiveLayout() {
    const isMobile = window.innerWidth <= 1200;
    if (mobileMenuToggle) {
        mobileMenuToggle.style.display = isMobile ? 'flex' : 'none';
    }
    if (!isMobile && sidebar) {
        sidebar.classList.remove('active');
    }
    if (mobileMenuToggle) {
        mobileMenuToggle.classList.remove('active');
    }
}

window.addEventListener('resize', handleResponsiveLayout);
handleResponsiveLayout(); // Initial call
</script>

</body>
</html>

