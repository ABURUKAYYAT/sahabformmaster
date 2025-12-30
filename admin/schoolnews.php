<?php
session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Principal';

$errors = [];
$success = '';
$bulk_action = $_GET['action'] ?? '';

// Handle bulk operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_news'] ?? [];

    if (empty($selected_ids)) {
        $errors[] = 'Please select news items to perform bulk action.';
    } else {
        try {
            $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';

            switch ($bulk_action) {
                case 'publish':
                    $stmt = $pdo->prepare("UPDATE school_news SET status = 'published', published_date = NOW() WHERE id IN ($placeholders) AND status = 'draft'");
                    $stmt->execute($selected_ids);
                    $success = 'Selected news items published successfully.';
                    break;

                case 'unpublish':
                    $stmt = $pdo->prepare("UPDATE school_news SET status = 'draft' WHERE id IN ($placeholders) AND status = 'published'");
                    $stmt->execute($selected_ids);
                    $success = 'Selected news items unpublished.';
                    break;

                case 'archive':
                    $stmt = $pdo->prepare("UPDATE school_news SET status = 'archived' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $success = 'Selected news items archived.';
                    break;

                case 'delete':
                    if ($bulk_action === 'delete') {
                        $stmt = $pdo->prepare("DELETE FROM school_news WHERE id IN ($placeholders)");
                        $stmt->execute($selected_ids);
                        $success = 'Selected news items permanently deleted.';
                    }
                    break;
            }
        } catch (PDOException $e) {
            $errors[] = 'Bulk operation failed: ' . $e->getMessage();
        }
    }
}

// Handle individual operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['bulk_action'])) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        // Handle news creation/editing
        $title = trim($_POST['title'] ?? '');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';
        $target_audience = trim($_POST['target_audience'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        $allow_comments = isset($_POST['allow_comments']) ? 1 : 0;
        $featured = isset($_POST['featured']) ? 1 : 0;
        $tags = trim($_POST['tags'] ?? '');
        $published_date = trim($_POST['published_date'] ?? '');
        $scheduled_date = trim($_POST['scheduled_date'] ?? '');

        // Validation
        if (empty($title)) $errors[] = 'Title is required.';
        if (empty($content)) $errors[] = 'Content is required.';
        if (empty($category)) $errors[] = 'Category is required.';
        if (empty($target_audience)) $errors[] = 'Target audience is required.';
        if ($status === 'published' && empty($published_date)) $errors[] = 'Published date is required for published news.';
        if (!empty($scheduled_date) && strtotime($scheduled_date) < time()) $errors[] = 'Scheduled date must be in the future.';

        // Generate slug
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
        if ($action === 'edit') {
            $existing_slug = $pdo->prepare("SELECT slug FROM school_news WHERE id = ?");
            $existing_slug->execute([$_POST['id']]);
            $current_slug = $existing_slug->fetchColumn();
            if ($current_slug !== $slug) {
                $slug_check = $pdo->prepare("SELECT COUNT(*) FROM school_news WHERE slug = ? AND id != ?");
                $slug_check->execute([$slug, $_POST['id']]);
                if ($slug_check->fetchColumn() > 0) $slug .= '-' . time();
            }
        } else {
            $slug_check = $pdo->prepare("SELECT COUNT(*) FROM school_news WHERE slug = ?");
            $slug_check->execute([$slug]);
            if ($slug_check->fetchColumn() > 0) $slug .= '-' . time();
        }

        // Handle image upload
        $featured_image = null;
        if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($_FILES['featured_image']['type'], $allowed_types)) {
                $errors[] = 'Only JPG, PNG, GIF, and WebP images are allowed.';
            } elseif ($_FILES['featured_image']['size'] > 5242880) {
                $errors[] = 'Image size must not exceed 5MB.';
            } else {
                $upload_dir = '../uploads/news/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $file_ext = pathinfo($_FILES['featured_image']['name'], PATHINFO_EXTENSION);
                $file_name = uniqid('news_') . '.' . $file_ext;
                if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $upload_dir . $file_name)) {
                    $featured_image = 'uploads/news/' . $file_name;
                } else {
                    $errors[] = 'Failed to upload image.';
                }
            }
        }

        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO school_news
                        (title, slug, excerpt, content, category, featured_image, author_id, priority,
                         target_audience, status, allow_comments, featured, tags, published_date, scheduled_date, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $title, $slug, $excerpt, $content, $category, $featured_image, $user_id, $priority,
                        $target_audience, $status, $allow_comments, $featured, $tags,
                        $status === 'published' ? $published_date : null,
                        !empty($scheduled_date) ? $scheduled_date : null
                    ]);
                    $success = 'News item created successfully.';
                } elseif ($action === 'edit') {
                    $id = intval($_POST['id']);
                    $stmt = $pdo->prepare("UPDATE school_news SET
                        title=?, slug=?, excerpt=?, content=?, category=?, featured_image=?,
                        priority=?, target_audience=?, status=?, allow_comments=?, featured=?,
                        tags=?, published_date=?, scheduled_date=?, updated_at=NOW()
                        WHERE id=?");
                    $stmt->execute([
                        $title, $slug, $excerpt, $content, $category,
                        $featured_image ?: $_POST['current_image'], $priority, $target_audience,
                        $status, $allow_comments, $featured, $tags,
                        $status === 'published' ? $published_date : null,
                        !empty($scheduled_date) ? $scheduled_date : null, $id
                    ]);
                    $success = 'News item updated successfully.';
                }
                header("Location: schoolnews.php");
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // Handle individual actions
    if (in_array($action, ['publish', 'unpublish', 'archive', 'delete', 'feature', 'unfeature'])) {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Invalid news ID.';
        } else {
            try {
                switch ($action) {
                    case 'publish':
                        $pdo->prepare("UPDATE school_news SET status='published', published_date=NOW() WHERE id=?")
                             ->execute([$id]);
                        $success = 'News published successfully.';
                        break;
                    case 'unpublish':
                        $pdo->prepare("UPDATE school_news SET status='draft' WHERE id=?")->execute([$id]);
                        $success = 'News unpublished.';
                        break;
                    case 'archive':
                        $pdo->prepare("UPDATE school_news SET status='archived' WHERE id=?")->execute([$id]);
                        $success = 'News archived.';
                        break;
                    case 'delete':
                        $pdo->prepare("DELETE FROM school_news WHERE id=?")->execute([$id]);
                        $success = 'News permanently deleted.';
                        break;
                    case 'feature':
                        $pdo->prepare("UPDATE school_news SET featured=1 WHERE id=?")->execute([$id]);
                        $success = 'News marked as featured.';
                        break;
                    case 'unfeature':
                        $pdo->prepare("UPDATE school_news SET featured=0 WHERE id=?")->execute([$id]);
                        $success = 'News unmarked as featured.';
                        break;
                }
            } catch (PDOException $e) {
                $errors[] = 'Action failed: ' . $e->getMessage();
            }
        }
    }
}

// Search and filtering
$search = trim($_GET['search'] ?? '');
$filter_category = $_GET['filter_category'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$filter_priority = $_GET['filter_priority'] ?? '';
$filter_featured = $_GET['filter_featured'] ?? '';

$query = "SELECT sn.*, CONCAT(u.full_name, ' (', u.role, ')') as author_name
          FROM school_news sn
          LEFT JOIN users u ON sn.author_id = u.id
          WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (sn.title LIKE ? OR sn.excerpt LIKE ? OR sn.content LIKE ? OR sn.tags LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if (!empty($filter_category)) {
    $query .= " AND sn.category = ?";
    $params[] = $filter_category;
}

if (!empty($filter_status)) {
    $query .= " AND sn.status = ?";
    $params[] = $filter_status;
} elseif (!isset($_GET['show_archived'])) {
    $query .= " AND sn.status != 'archived'";
}

if (!empty($filter_priority)) {
    $query .= " AND sn.priority = ?";
    $params[] = $filter_priority;
}

if ($filter_featured !== '') {
    $query .= " AND sn.featured = ?";
    $params[] = $filter_featured;
}

$query .= " ORDER BY sn.created_at DESC LIMIT 100";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $pdo->query("
    SELECT
        COUNT(CASE WHEN status = 'published' THEN 1 END) as published_count,
        COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_count,
        0 as featured_count,
        SUM(view_count) as total_views
    FROM school_news
    WHERE status != 'archived'
")->fetch();

// Get categories
$categories = $pdo->query("SELECT DISTINCT category FROM school_news WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// Handle editing
$edit_news = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM school_news WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_news = $stmt->fetch();
}

// Utility functions
function getStatusBadge($status) {
    return match($status) {
        'published' => 'badge-success',
        'draft' => 'badge-secondary',
        'archived' => 'badge-danger',
        default => 'badge-default'
    };
}

function getPriorityBadge($priority) {
    return match($priority) {
        'high' => 'badge-danger',
        'medium' => 'badge-warning',
        'low' => 'badge-secondary',
        default => 'badge-default'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>School News | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/admin-unified.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        .dashboard-container {
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
            background: linear-gradient(to right, #FFD700, #FFA500);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
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

        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 2rem auto;
            gap: 2rem;
            padding: 0 1rem;
        }

        .sidebar {
            width: 280px;
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            height: fit-content;
            position: sticky;
            top: 100px;
        }

        .nav-list {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.9rem 1.2rem;
            text-decoration: none;
            color: var(--gray-color);
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .nav-link:hover, .nav-link.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            transform: translateX(5px);
        }

        .nav-icon {
            font-size: 1.2rem;
            min-width: 30px;
        }

        .main-content {
            flex: 1;
            min-width: 0;
        }

        .content-header {
            background: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border-left: 6px solid var(--primary-color);
        }

        .content-header h2 {
            color: var(--secondary-color);
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
        }

        .small-muted {
            color: var(--gray-color);
            font-size: 0.95rem;
        }

        .news-section {
            background: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease;
        }

        .news-section:hover {
            transform: translateY(-5px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.95rem;
            opacity: 0.9;
        }

        .news-card h3 {
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .news-form {
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

        .editor-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 12px 12px 0 0;
            border: 2px solid #e0e0e0;
            border-bottom: none;
        }

        .toolbar-btn {
            padding: 0.6rem 1rem;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .toolbar-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .editor-textarea {
            min-height: 250px;
            border-radius: 0 0 12px 12px;
            border-top: none;
            resize: vertical;
        }

        .image-preview {
            margin-top: 1rem;
            max-width: 200px;
        }

        .image-preview img {
            width: 100%;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
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

        .btn-secondary {
            background: #6c757d;
            color: white;
            padding: 0.9rem 2rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
            font-size: 1rem;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-3px);
        }

        .search-filter {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1rem;
        }

        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .btn-search {
            background: var(--primary-color);
            color: white;
            padding: 0.9rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-search:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }

        .btn-reset {
            background: #6c757d;
            color: white;
            padding: 0.9rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
        }

        .btn-reset:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 15px;
            overflow: hidden;
        }

        .table thead {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .table th {
            padding: 1.2rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tbody tr {
            border-bottom: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .table td {
            padding: 1.2rem 1rem;
            vertical-align: middle;
        }

        .news-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .news-thumb {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            object-fit: cover;
        }

        .news-thumb-placeholder {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4cc9f0, #4361ee);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-secondary {
            background: #6c757d;
            color: white;
        }

        .badge-success {
            background: linear-gradient(135deg, #4cc9f0, #4361ee);
            color: white;
        }

        .badge-danger {
            background: linear-gradient(135deg, #f72585, #7209b7);
            color: white;
        }

        .badge-warning {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: var(--dark-color);
        }

        .manage-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .btn-view {
            background: #4cc9f0;
            color: white;
        }

        .btn-edit {
            background: var(--primary-color);
            color: white;
        }

        .btn-approve {
            background: #4CAF50;
            color: white;
        }

        .btn-unpublish {
            background: #FF9800;
            color: white;
        }

        .btn-delete {
            background: #f72585;
            color: white;
        }

        .btn-small:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }

        .alert {
            padding: 1.2rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            border-left: 5px solid;
        }

        .alert-error {
            background: #fde8e8;
            color: #c53030;
            border-color: #c53030;
        }

        .alert-success {
            background: #e6fffa;
            color: #2d7a5e;
            border-color: #2d7a5e;
        }

        .dashboard-footer {
            background: linear-gradient(135deg, var(--dark-color), #343a40);
            color: white;
            padding: 3rem 2rem 1.5rem;
            margin-top: 3rem;
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
        @media (max-width: 1200px) {
            .dashboard-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                position: static;
                margin-bottom: 1rem;
            }
            
            .nav-list {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 0.5rem;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .header-left {
                flex-direction: column;
                align-items: center;
            }
            
            .teacher-info {
                align-items: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .search-form {
                grid-template-columns: 1fr;
            }
            
            .manage-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .content-header h2 {
                font-size: 1.8rem;
            }
            
            .news-card h3 {
                font-size: 1.3rem;
            }
            
            .footer-content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            
            .footer-bottom {
                flex-direction: column;
                text-align: center;
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
                <span class="teacher-role">Principal</span>
            </div>
            <a href="../index.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</header>
<div class="page-header">
        <div class="page-header-content">
            <a href="index.php" class="btn-dashboard-back">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Dashboard</span>
            </a>
            <div class="page-title">
                <h1><i class="fas fa-newspaper"></i> School News Management</h1>
                <p>Create, edit, and manage school news and announcements</p>
            </div>
        </div>
    </div>
<div class="dashboard-container">
    

    <main class="main-content">
        <div class="content-header">
            <h2><i class="fas fa-newspaper"></i> School News Management</h2>
            <p class="small-muted">Create, edit, and manage school news and announcements with our powerful editor</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Card -->
        <section class="news-section">
            <h3><i class="fas fa-chart-bar"></i> News Overview</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_filter($news_items, fn($n) => $n['status'] === 'published')); ?></div>
                    <div class="stat-label">Published News</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_filter($news_items, fn($n) => $n['status'] === 'draft')); ?></div>
                    <div class="stat-label">Drafts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo array_sum(array_column($news_items, 'view_count')); ?></div>
                    <div class="stat-label">Total Views</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_filter($news_items, fn($n) => $n['priority'] === 'high')); ?></div>
                    <div class="stat-label">High Priority</div>
                </div>
            </div>
        </section>

        <!-- Create / Edit Form -->
        <section class="news-section">
            <div class="news-card">
                <h3><?php echo $edit_news ? '✏️ Edit News Item' : '➕ Create New News Item'; ?></h3>

                <form method="POST" enctype="multipart/form-data" class="news-form">
                    <input type="hidden" name="action" value="<?php echo $edit_news ? 'edit' : 'add'; ?>">
                    <?php if ($edit_news): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_news['id']); ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="title"><i class="fas fa-heading"></i> Title *</label>
                                <input type="text" id="title" name="title" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_news['title'] ?? ''); ?>" 
                                       placeholder="e.g. Annual Sports Day 2025" required>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="category"><i class="fas fa-tag"></i> Category *</label>
                                <select id="category" name="category" class="form-control" required>
                                    <option value="">Select Category</option>
                                    <?php $sel_cat = $edit_news['category'] ?? ''; ?>
                                    <option value="Academics" <?php echo $sel_cat === 'Academics' ? 'selected' : ''; ?>>Academics</option>
                                    <option value="Sports" <?php echo $sel_cat === 'Sports' ? 'selected' : ''; ?>>Sports</option>
                                    <option value="Events" <?php echo $sel_cat === 'Events' ? 'selected' : ''; ?>>Events</option>
                                    <option value="Announcements" <?php echo $sel_cat === 'Announcements' ? 'selected' : ''; ?>>Announcements</option>
                                    <option value="Achievements" <?php echo $sel_cat === 'Achievements' ? 'selected' : ''; ?>>Achievements</option>
                                    <option value="Administration" <?php echo $sel_cat === 'Administration' ? 'selected' : ''; ?>>Administration</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="priority"><i class="fas fa-exclamation-circle"></i> Priority Level</label>
                                <select id="priority" name="priority" class="form-control">
                                    <?php $sel_pri = $edit_news['priority'] ?? 'medium'; ?>
                                    <option value="low" <?php echo $sel_pri === 'low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="medium" <?php echo $sel_pri === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="high" <?php echo $sel_pri === 'high' ? 'selected' : ''; ?>>High</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="target_audience"><i class="fas fa-users"></i> Target Audience *</label>
                                <select id="target_audience" name="target_audience" class="form-control" required>
                                    <option value="">Select Audience</option>
                                    <?php $sel_aud = $edit_news['target_audience'] ?? ''; ?>
                                    <option value="All" <?php echo $sel_aud === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Students" <?php echo $sel_aud === 'Students' ? 'selected' : ''; ?>>Students</option>
                                    <option value="Parents" <?php echo $sel_aud === 'Parents' ? 'selected' : ''; ?>>Parents</option>
                                    <option value="Teachers" <?php echo $sel_aud === 'Teachers' ? 'selected' : ''; ?>>Teachers</option>
                                    <option value="Staff" <?php echo $sel_aud === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="status"><i class="fas fa-bullhorn"></i> Status</label>
                                <select id="status" name="status" class="form-control">
                                    <?php $sel_stat = $edit_news['status'] ?? 'draft'; ?>
                                    <option value="draft" <?php echo $sel_stat === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="published" <?php echo $sel_stat === 'published' ? 'selected' : ''; ?>>Published</option>
                                    <option value="scheduled" <?php echo $sel_stat === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="published_date"><i class="fas fa-calendar-alt"></i> Published Date</label>
                                <input type="datetime-local" id="published_date" name="published_date" class="form-control" 
                                       value="<?php echo $edit_news['published_date'] ? date('Y-m-d\TH:i', strtotime($edit_news['published_date'])) : ''; ?>">
                                <small class="small-muted">Required if status is Published</small>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="scheduled_date"><i class="fas fa-clock"></i> Schedule for Later (Optional)</label>
                                <input type="datetime-local" id="scheduled_date" name="scheduled_date" class="form-control" 
                                       value="<?php echo $edit_news['scheduled_date'] ? date('Y-m-d\TH:i', strtotime($edit_news['scheduled_date'])) : ''; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="excerpt"><i class="fas fa-align-left"></i> Excerpt/Summary</label>
                        <textarea id="excerpt" name="excerpt" class="form-control" rows="2" 
                                  placeholder="Brief summary of the news (appears in news feed)..."><?php echo htmlspecialchars($edit_news['excerpt'] ?? ''); ?></textarea>
                        <small class="small-muted">100-150 characters recommended</small>
                    </div>

                    <div class="form-group">
                        <label for="content"><i class="fas fa-edit"></i> Content *</label>
                        <div class="editor-toolbar">
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('bold')" title="Bold"><i class="fas fa-bold"></i></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('italic')" title="Italic"><i class="fas fa-italic"></i></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('underline')" title="Underline"><i class="fas fa-underline"></i></button>
                            <div class="toolbar-divider"></div>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('h2')" title="Heading"><i class="fas fa-heading"></i></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('h3')" title="Subheading"><i class="fas fa-heading"></i></button>
                            <div class="toolbar-divider"></div>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('ul')" title="Bullet List"><i class="fas fa-list-ul"></i></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('ol')" title="Numbered List"><i class="fas fa-list-ol"></i></button>
                            <div class="toolbar-divider"></div>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('link')" title="Insert Link"><i class="fas fa-link"></i></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('quote')" title="Quote"><i class="fas fa-quote-right"></i></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('code')" title="Code Block"><i class="fas fa-code"></i></button>
                        </div>
                        <textarea id="content" name="content" class="form-control editor-textarea" rows="12" 
                                  placeholder="Full news content..."><?php echo htmlspecialchars($edit_news['content'] ?? ''); ?></textarea>
                        <small class="small-muted">💡 Tip: Use the toolbar buttons above to format your text</small>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="featured_image"><i class="fas fa-image"></i> Featured Image</label>
                                <input type="file" id="featured_image" name="featured_image" class="form-control" 
                                       accept="image/jpeg,image/png,image/gif,image/webp">
                                <small class="small-muted">JPG, PNG, GIF, WebP. Max 5MB.</small>
                                <?php if ($edit_news && $edit_news['featured_image']): ?>
                                    <div class="image-preview">
                                        <img src="../<?php echo htmlspecialchars($edit_news['featured_image']); ?>" alt="Current Featured Image">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="tags"><i class="fas fa-tags"></i> Tags (Optional)</label>
                                <input type="text" id="tags" name="tags" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_news['tags'] ?? ''); ?>" 
                                       placeholder="Comma-separated (e.g. sports, achievement, award)">
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="allow_comments" <?php echo ($edit_news['allow_comments'] ?? 1) ? 'checked' : ''; ?>>
                                    <i class="fas fa-comments"></i> Allow Comments on This News Item
                                </label>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="featured" <?php echo ($edit_news['featured'] ?? 0) ? 'checked' : ''; ?>>
                                    <i class="fas fa-star"></i> Mark as Featured News
                                </label>
                                <small class="small-muted">Featured news appears prominently on the homepage</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <?php if ($edit_news): ?>
                            <button type="submit" class="btn-gold">
                                <i class="fas fa-save"></i> Update News Item
                            </button>
                            <a href="schoolnews.php" class="btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php else: ?>
                            <button type="submit" class="btn-gold">
                                <i class="fas fa-plus-circle"></i> Create News Item
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- Search and Filter -->
        <section class="news-section">
            <h3><i class="fas fa-search"></i> Search & Filter News</h3>
            <div class="search-filter">
                <form method="GET" class="search-form">
                    <div class="form-group">
                        <input type="text" name="search" class="form-control" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by title, content, or tags...">
                    </div>

                    <div class="form-group">
                        <select name="filter_category" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" 
                                        <?php echo $filter_category === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
 
                    <div class="form-group">
                        <select name="filter_status" class="form-control">
                            <option value="">Active News</option>
                            <option value="published" <?php echo $filter_status === 'published' ? 'selected' : ''; ?>>Published Only</option>
                            <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Drafts Only</option>
                            <option value="archived" <?php echo $filter_status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <select name="filter_priority" class="form-control">
                            <option value="">All Priorities</option>
                            <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High Priority</option>
                            <option value="medium" <?php echo $filter_priority === 'medium' ? 'selected' : ''; ?>>Medium Priority</option>
                            <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low Priority</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="schoolnews.php" class="btn-reset">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </form>
            </div>
        </section>

        <!-- News Table -->
        <section class="news-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h3><i class="fas fa-list"></i> Manage News Items</h3>
                <?php if (count($news_items) > 0): ?>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <span style="font-size: 0.9rem; color: var(--gray-color);">Bulk Actions:</span>
                    <form method="POST" id="bulkForm" style="display: flex; gap: 0.5rem;">
                        <input type="hidden" name="bulk_action" id="bulkActionInput">
                        <button type="button" onclick="performBulkAction('publish')" class="btn-small btn-approve">
                            <i class="fas fa-check"></i> Publish
                        </button>
                        <button type="button" onclick="performBulkAction('unpublish')" class="btn-small btn-unpublish">
                            <i class="fas fa-ban"></i> Unpublish
                        </button>
                        <button type="button" onclick="performBulkAction('archive')" class="btn-small btn-delete">
                            <i class="fas fa-archive"></i> Archive
                        </button>
                        <button type="button" onclick="performBulkAction('delete')" class="btn-small btn-delete" style="background: #dc3545;">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                            </th>
                            <th>#</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Audience</th>
                            <th>Published</th>
                            <th>Views</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($news_items) === 0): ?>
                            <tr><td colspan="9" class="text-center small-muted">No news items found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($news_items as $item): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_news[]" value="<?php echo intval($item['id']); ?>" class="news-checkbox"></td>
                                    <td><?php echo intval($item['id']); ?></td>
                                    <td>
                                        <div class="news-title">
                                            <?php if ($item['featured_image']): ?>
                                                <img src="../<?php echo htmlspecialchars($item['featured_image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="news-thumb">
                                            <?php else: ?>
                                                <div class="news-thumb-placeholder">📰</div>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars($item['title']); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                                    <td>
                                        <span class="badge <?php echo getStatusBadge($item['status']); ?>">
                                            <?php echo ucfirst($item['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getPriorityBadge($item['priority']); ?>">
                                            <?php echo ucfirst($item['priority']); ?>
                                        </span>
                                    </td>
                                    <td class="small-muted"><?php echo htmlspecialchars($item['target_audience']); ?></td>
                                    <td><?php echo $item['published_date'] ? date('M d, Y', strtotime($item['published_date'])) : '—'; ?></td>
                                    <td class="text-center"><?php echo intval($item['view_count']); ?></td>
                                    <td>
                                        <div class="manage-actions">
                                            <a class="btn-small btn-view" href="schoolnews-detail.php?id=<?php echo intval($item['id']); ?>" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>

                                            <a class="btn-small btn-edit" href="schoolnews.php?edit=<?php echo intval($item['id']); ?>" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <?php if ($item['status'] === 'draft'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="publish">
                                                    <input type="hidden" name="id" value="<?php echo intval($item['id']); ?>">
                                                    <button type="submit" class="btn-small btn-approve" title="Publish">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php elseif ($item['status'] === 'published'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="unpublish">
                                                    <input type="hidden" name="id" value="<?php echo intval($item['id']); ?>">
                                                    <button type="submit" class="btn-small btn-unpublish" title="Unpublish">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this news item?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo intval($item['id']); ?>">
                                                <button type="submit" class="btn-small btn-delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Professional school management system designed to streamline educational administration and communication.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="schoolnews.php"><i class="fas fa-newspaper"></i> News Management</a>
                    <a href="manage_user.php"><i class="fas fa-users"></i> Users</a>
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
            <p class="footer-version">Version 1.0 | Blog Management System</p>
        </div>
    </div>
</footer>

<script>
function insertFormatting(type) {
    const textarea = document.getElementById('content');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selectedText = textarea.value.substring(start, end) || 'Your text here';
    let newText = '';

    switch (type) {
        case 'bold':
            newText = `**${selectedText}**`;
            break;
        case 'italic':
            newText = `*${selectedText}*`;
            break;
        case 'underline':
            newText = `__${selectedText}__`;
            break;
        case 'h2':
            newText = `## ${selectedText}\n`;
            break;
        case 'h3':
            newText = `### ${selectedText}\n`;
            break;
        case 'ul':
            newText = `• ${selectedText}\n• Item 2\n• Item 3\n`;
            break;
        case 'ol':
            newText = `1. ${selectedText}\n2. Item 2\n3. Item 3\n`;
            break;
        case 'link':
            newText = `[${selectedText}](https://example.com)`;
            break;
        case 'quote':
            newText = `\n> ${selectedText}\n`;
            break;
        case 'code':
            newText = `\`\`\`\n${selectedText}\n\`\`\`\n`;
            break;
    }

    const newContent = textarea.value.substring(0, start) + newText + textarea.value.substring(end);
    textarea.value = newContent;
    textarea.focus();
    textarea.selectionStart = start + newText.length;
}

// Bulk operations functionality
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.news-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
}

function performBulkAction(action) {
    const selectedCheckboxes = document.querySelectorAll('.news-checkbox:checked');
    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one news item.');
        return;
    }

    const confirmMessage = {
        'publish': `Publish ${selectedCheckboxes.length} selected news items?`,
        'unpublish': `Unpublish ${selectedCheckboxes.length} selected news items?`,
        'archive': `Archive ${selectedCheckboxes.length} selected news items?`,
        'delete': `Permanently delete ${selectedCheckboxes.length} selected news items? This action cannot be undone!`
    };

    if (!confirm(confirmMessage[action])) {
        return;
    }

    document.getElementById('bulkActionInput').value = action;
    document.getElementById('bulkForm').submit();
}

// Rich text editor functionality
function insertFormatting(type) {
    const textarea = document.getElementById('content');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selectedText = textarea.value.substring(start, end) || 'Your text here';
    let newText = '';

    switch (type) {
        case 'bold':
            newText = `**${selectedText}**`;
            break;
        case 'italic':
            newText = `*${selectedText}*`;
            break;
        case 'underline':
            newText = `__${selectedText}__`;
            break;
        case 'h2':
            newText = `## ${selectedText}\n\n`;
            break;
        case 'h3':
            newText = `### ${selectedText}\n\n`;
            break;
        case 'ul':
            newText = `• ${selectedText}\n• Item 2\n• Item 3\n\n`;
            break;
        case 'ol':
            newText = `1. ${selectedText}\n2. Item 2\n3. Item 3\n\n`;
            break;
        case 'link':
            newText = `[${selectedText}](https://example.com)`;
            break;
        case 'quote':
            newText = `\n> ${selectedText}\n\n`;
            break;
        case 'code':
            newText = `\`\`\`\n${selectedText}\n\`\`\`\n\n`;
            break;
    }

    const newContent = textarea.value.substring(0, start) + newText + textarea.value.substring(end);
    textarea.value = newContent;
    textarea.focus();
    textarea.selectionStart = start + newText.length;
}

// Auto-save draft functionality
let autoSaveTimer;
function startAutoSave() {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(() => {
        const formData = new FormData(document.querySelector('form'));
        formData.append('action', 'auto_save');

        fetch('schoolnews.php', {
            method: 'POST',
            body: formData
        }).then(response => {
            if (response.ok) {
                showToast('Draft saved automatically', 'success');
            }
        });
    }, 30000); // Save every 30 seconds
}

// Toast notification system
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        ${message}
    `;

    const container = document.querySelector('.dashboard-container') || document.body;
    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }, 100);
}

// Image preview functionality
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('featured_image');
    if (imageInput) {
        imageInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    let preview = document.querySelector('.image-preview');
                    if (!preview) {
                        preview = document.createElement('div');
                        preview.className = 'image-preview';
                        imageInput.parentNode.appendChild(preview);
                    }
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // Auto-save for drafts
    const contentTextarea = document.getElementById('content');
    if (contentTextarea) {
        contentTextarea.addEventListener('input', startAutoSave);
        document.querySelector('form').addEventListener('input', startAutoSave);
    }

    // Status change handler
    const statusSelect = document.getElementById('status');
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            const publishedDate = document.getElementById('published_date');
            if (this.value === 'published' && !publishedDate.value) {
                publishedDate.value = new Date().toISOString().slice(0, 16);
            }
        });
    }

    // Mobile responsive enhancements
    function handleMobileLayout() {
        const isMobile = window.innerWidth <= 768;
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');

        if (isMobile) {
            // Create mobile toggle if not exists
            let mobileToggle = document.querySelector('.mobile-menu-toggle');
            if (!mobileToggle) {
                mobileToggle = document.createElement('button');
                mobileToggle.className = 'mobile-menu-toggle';
                mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
                mobileToggle.setAttribute('aria-label', 'Toggle Menu');
                document.body.insertBefore(mobileToggle, document.body.firstChild);

                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                    this.classList.toggle('active');
                });
            }
        } else {
            // Remove mobile toggle on desktop
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            if (mobileToggle) mobileToggle.remove();
        }
    }

    handleMobileLayout();
    window.addEventListener('resize', handleMobileLayout);

    // Enhanced form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const status = document.getElementById('status').value;
        const publishedDate = document.getElementById('published_date').value;

        if (status === 'published' && !publishedDate) {
            e.preventDefault();
            showToast('Published date is required for published news.', 'error');
            return false;
        }

        const scheduledDate = document.getElementById('scheduled_date').value;
        if (scheduledDate && new Date(scheduledDate) <= new Date()) {
            e.preventDefault();
            showToast('Scheduled date must be in the future.', 'error');
            return false;
        }
    });

    // Search enhancement
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            if (this.value.length > 2) {
                // Could add AJAX search suggestions here
            }
        });
    }
});

// Toast styles
const toastStyles = `
    .toast {
        position: fixed;
        top: 100px;
        right: 20px;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        color: white;
        font-weight: 500;
        z-index: 10000;
        transform: translateX(400px);
        transition: transform 0.3s ease;
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .toast.show {
        transform: translateX(0);
    }

    .toast-success {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .toast-error {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }

    .toast-info {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
    }
`;

// Add toast styles to head
const style = document.createElement('style');
style.textContent = toastStyles;
document.head.appendChild(style);
</script>
 
</body>
</html>
