<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

// Get current school for data isolation
$current_school_id = require_school_auth();

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
                    $stmt = $pdo->prepare("UPDATE school_news SET status = 'published', published_date = NOW() WHERE id IN ($placeholders) AND status = 'draft' AND school_id = ?");
                    $params = array_merge($selected_ids, [$current_school_id]);
                    $stmt->execute($params);
                    $success = 'Selected news items published successfully.';
                    break;

                case 'unpublish':
                    $stmt = $pdo->prepare("UPDATE school_news SET status = 'draft' WHERE id IN ($placeholders) AND status = 'published' AND school_id = ?");
                    $params = array_merge($selected_ids, [$current_school_id]);
                    $stmt->execute($params);
                    $success = 'Selected news items unpublished.';
                    break;

                case 'archive':
                    $stmt = $pdo->prepare("UPDATE school_news SET status = 'archived' WHERE id IN ($placeholders) AND school_id = ?");
                    $params = array_merge($selected_ids, [$current_school_id]);
                    $stmt->execute($params);
                    $success = 'Selected news items archived.';
                    break;

                case 'delete':
                    if ($bulk_action === 'delete') {
                        $stmt = $pdo->prepare("DELETE FROM school_news WHERE id IN ($placeholders) AND school_id = ?");
                        $params = array_merge($selected_ids, [$current_school_id]);
                        $stmt->execute($params);
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
    // Validate CSRF token
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token validation failed. Please refresh the page and try again.';
    } else {
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
                        (title, slug, excerpt, content, category, featured_image, author_id, school_id, priority,
                         target_audience, status, allow_comments, featured, tags, published_date, scheduled_date, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $title, $slug, $excerpt, $content, $category, $featured_image, $user_id, $current_school_id, $priority,
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
                        WHERE id=? AND school_id=?");
                    $stmt->execute([
                        $title, $slug, $excerpt, $content, $category,
                        $featured_image ?: $_POST['current_image'], $priority, $target_audience,
                        $status, $allow_comments, $featured, $tags,
                        $status === 'published' ? $published_date : null,
                        !empty($scheduled_date) ? $scheduled_date : null, $id, $current_school_id
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
                        $pdo->prepare("UPDATE school_news SET status='published', published_date=NOW() WHERE id=? AND school_id=?")
                             ->execute([$id, $current_school_id]);
                        $success = 'News published successfully.';
                        break;
                    case 'unpublish':
                        $pdo->prepare("UPDATE school_news SET status='draft' WHERE id=? AND school_id=?")->execute([$id, $current_school_id]);
                        $success = 'News unpublished.';
                        break;
                    case 'archive':
                        $pdo->prepare("UPDATE school_news SET status='archived' WHERE id=? AND school_id=?")->execute([$id, $current_school_id]);
                        $success = 'News archived.';
                        break;
                    case 'delete':
                        $pdo->prepare("DELETE FROM school_news WHERE id=? AND school_id=?")->execute([$id, $current_school_id]);
                        $success = 'News permanently deleted.';
                        break;
                    case 'feature':
                        $pdo->prepare("UPDATE school_news SET featured=1 WHERE id=? AND school_id=?")->execute([$id, $current_school_id]);
                        $success = 'News marked as featured.';
                        break;
                    case 'unfeature':
                        $pdo->prepare("UPDATE school_news SET featured=0 WHERE id=? AND school_id=?")->execute([$id, $current_school_id]);
                        $success = 'News unmarked as featured.';
                        break;
                }
            } catch (PDOException $e) {
                $errors[] = 'Action failed: ' . $e->getMessage();
            }
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
          WHERE sn.school_id = ?";
$params = [$current_school_id];

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

// Get statistics - filtered by current school
$stats_query = $pdo->prepare("
    SELECT
        COUNT(CASE WHEN status = 'published' THEN 1 END) as published_count,
        COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_count,
        COUNT(CASE WHEN featured = 1 THEN 1 END) as featured_count,
        SUM(view_count) as total_views
    FROM school_news
    WHERE status != 'archived' AND school_id = ?
");
$stats_query->execute([$current_school_id]);
$stats = $stats_query->fetch();

// Get categories - filtered by current school
$categories_query = $pdo->prepare("SELECT DISTINCT category FROM school_news WHERE category IS NOT NULL AND school_id = ? ORDER BY category");
$categories_query->execute([$current_school_id]);
$categories = $categories_query->fetchAll(PDO::FETCH_COLUMN);

// Handle editing
$edit_news = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM school_news WHERE id = ? AND school_id = ?");
    $stmt->execute([$edit_id, $current_school_id]);
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School News | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color: #38bdf8;
            --primary-light: #60a5fa;
            --gradient-card-1: linear-gradient(135deg, #2563eb 0%, #38bdf8 100%);
            --gradient-card-2: linear-gradient(135deg, #1d4ed8 0%, #60a5fa 100%);
            --gradient-card-3: linear-gradient(135deg, #0ea5e9 0%, #22d3ee 100%);
            --gradient-card-4: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
        }

        .icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1em;
            height: 1em;
            font-style: normal;
            font-weight: 600;
            text-decoration: none;
            vertical-align: middle;
            user-select: none;
        }

        .icon-dashboard::before { content: "\1F4CA"; }
        .icon-news::before { content: "\1F4F0"; }
        .icon-users::before { content: "\1F465"; }
        .icon-plus::before { content: "\2795"; }
        .icon-edit::before { content: "\270F"; }
        .icon-delete::before { content: "\1F5D1"; }
        .icon-search::before { content: "\1F50D"; }
        .icon-filter::before { content: "\1F3AF"; }
        .icon-chart::before { content: "\1F4C8"; }
        .icon-eye::before { content: "\1F441"; }
        .icon-star::before { content: "\2B50"; }
        .icon-check::before { content: "\2713"; }
        .icon-close::before { content: "\2715"; }
        .icon-menu::before { content: "\2630"; }
        .icon-arrow-left::before { content: "\2190"; }
        .icon-tag::before { content: "\1F3F7"; }
        .icon-exclamation::before { content: "\26A0"; }
        .icon-bullhorn::before { content: "\1F4E3"; }
        .icon-calendar::before { content: "\1F4C5"; }
        .icon-clock::before { content: "\23F0"; }
        .icon-info::before { content: "\2139"; }
        .icon-success::before { content: "\2705"; }
        .icon-error::before { content: "\274C"; }
        .icon-logout::before { content: "\1F6AA"; }

        .content-header {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        .content-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .small-muted {
            color: var(--gray-500);
            font-size: 0.95rem;
        }

        .news-section {
            background: var(--white);
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            margin-bottom: 2rem;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-top: 1.25rem;
        }

        .card {
            position: relative;
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            color: white;
            box-shadow: var(--shadow-lg);
            display: flex;
            gap: 1rem;
            align-items: center;
            overflow: hidden;
        }

        .card-gradient-1 { background: var(--gradient-card-1); }
        .card-gradient-2 { background: var(--gradient-card-2); }
        .card-gradient-3 { background: var(--gradient-card-3); }
        .card-gradient-4 { background: var(--gradient-card-4); }

        .card-icon-wrapper {
            width: 54px;
            height: 54px;
            display: grid;
            place-items: center;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 16px;
        }

        .card-icon {
            font-size: 1.8rem;
        }

        .card-content h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
        }

        .card-value {
            font-size: 2rem;
            font-weight: 800;
            margin: 0 0 0.35rem 0;
        }

        .card-footer {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .card-badge {
            font-size: 0.75rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.25);
        }

        .card-link {
            color: white;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .news-card {
            background: var(--gray-50);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            border: 1px solid var(--gray-200);
        }

        .news-form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-control {
            padding: 0.85rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-md);
            font-size: 0.95rem;
            background: white;
            transition: var(--transition-fast);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn-gold {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 0.9rem 1.6rem;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--gray-600);
            color: white;
            border: none;
            padding: 0.9rem 1.6rem;
            border-radius: var(--border-radius-md);
            text-decoration: none;
            font-weight: 600;
        }

        .editor-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            padding: 0.75rem;
            background: var(--gray-100);
            border-radius: var(--border-radius-md);
            border: 1px solid var(--gray-200);
        }

        .toolbar-btn {
            border: 1px solid var(--gray-300);
            background: white;
            padding: 0.35rem 0.6rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray-700);
        }

        .toolbar-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .toolbar-divider {
            width: 1px;
            background: var(--gray-300);
            margin: 0 0.25rem;
        }

        .editor-textarea {
            min-height: 260px;
            font-family: 'Inter', sans-serif;
        }

        .search-filter {
            background: var(--gray-50);
            padding: 1rem;
            border-radius: var(--border-radius-lg);
            border: 1px solid var(--gray-200);
        }

        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            align-items: end;
        }

        .btn-search {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius-md);
            padding: 0.85rem 1.25rem;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-reset {
            background: var(--gray-600);
            color: white;
            border: none;
            border-radius: var(--border-radius-md);
            padding: 0.85rem 1.25rem;
            text-decoration: none;
            text-align: center;
            font-weight: 600;
        }

        .table-wrapper {
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-lg);
            overflow: auto;
            background: white;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .table thead {
            background: var(--gray-50);
        }

        .table th,
        .table td {
            padding: 0.85rem 0.9rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.92rem;
        }

        .table tbody tr:hover {
            background: var(--gray-50);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success { background: rgba(16, 185, 129, 0.15); color: #059669; }
        .badge-warning { background: rgba(245, 158, 11, 0.15); color: #d97706; }
        .badge-danger { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
        .badge-info { background: rgba(59, 130, 246, 0.15); color: #2563eb; }
        .badge-secondary { background: rgba(107, 114, 128, 0.15); color: #4b5563; }
        .badge-default { background: var(--gray-100); color: var(--gray-600); }

        .manage-actions {
            display: flex;
            gap: 0.35rem;
            flex-wrap: wrap;
        }

        .btn-small {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.35rem 0.6rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
            text-decoration: none;
        }

        .btn-view { background: var(--info-color); }
        .btn-edit { background: var(--warning-color); color: #111827; }
        .btn-approve { background: var(--success-color); }
        .btn-unpublish { background: var(--warning-color); color: #111827; }
        .btn-delete { background: var(--error-color); }

        .news-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .news-thumb {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            object-fit: cover;
        }

        .news-thumb-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: var(--gray-100);
            display: grid;
            place-items: center;
            color: var(--gray-500);
        }

        .image-preview img {
            margin-top: 0.75rem;
            width: 160px;
            height: 100px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
        }

        @media (max-width: 900px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
        }

    </style>
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-left">
            <div class="school-logo-container">
                <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                <div class="school-info">
                    <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                    <p class="school-tagline">Principal Portal</p>
                </div>
            </div>
        </div>

        <div class="header-right">
            <div class="principal-info">
                <p class="principal-label">Principal</p>
                <span class="principal-name"><?php echo htmlspecialchars($user_name); ?></span>
            </div>
            <a href="logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt logout-icon"></i>
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
            <button class="sidebar-close" id="sidebarClose">✖</button>
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
                    <a href="schoolnews.php" class="nav-link active">
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
                        <span class="nav-icon">📆</span>
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
            <h2><span class="icon icon-news"></span> School News Management</h2>
            <p class="small-muted">Create, edit, and manage school news and announcements with our powerful editor</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <span class="icon icon-error"></span>
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <span class="icon icon-success"></span>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Card -->
        <section class="news-section">
            <h3><span class="icon icon-chart"></span> News Overview</h3>
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📰</div>
                    </div>
                    <div class="card-content">
                        <h3>Published News</h3>
                        <p class="card-value"><?php echo count(array_filter($news_items, fn($n) => $n['status'] === 'published')); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Active</span>
                            <a href="?filter_status=published" class="card-link">View All →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📝</div>
                    </div>
                    <div class="card-content">
                        <h3>Draft Articles</h3>
                        <p class="card-value"><?php echo count(array_filter($news_items, fn($n) => $n['status'] === 'draft')); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Pending</span>
                            <a href="?filter_status=draft" class="card-link">Review →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">👁️</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Views</h3>
                        <p class="card-value"><?php echo number_format(array_sum(array_column($news_items, 'view_count'))); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Engagement</span>
                            <a href="#" class="card-link">Analytics →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">🚨</div>
                    </div>
                    <div class="card-content">
                        <h3>High Priority</h3>
                        <p class="card-value"><?php echo count(array_filter($news_items, fn($n) => $n['priority'] === 'high')); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Important</span>
                            <a href="?filter_priority=high" class="card-link">Manage →</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Create / Edit Form -->
        <section class="news-section">
            <div class="news-card">
                <h3><?php echo $edit_news ? '✏️ Edit News Item' : '➕ Create New News Item'; ?></h3>

                <form method="POST" enctype="multipart/form-data" class="news-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="<?php echo $edit_news ? 'edit' : 'add'; ?>">
                    <?php if ($edit_news): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_news['id']); ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="title"><span class="icon icon-edit"></span> Title *</label>
                                <input type="text" id="title" name="title" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_news['title'] ?? ''); ?>" 
                                       placeholder="e.g. Annual Sports Day 2025" required>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="category"><span class="icon icon-tag"></span> Category *</label>
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
                                <label for="priority"><span class="icon icon-exclamation"></span> Priority Level</label>
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
                                <label for="target_audience"><span class="icon icon-users"></span> Target Audience *</label>
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
                                <label for="status"><span class="icon icon-bullhorn"></span> Status</label>
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
                                <label for="published_date"><span class="icon icon-calendar"></span> Published Date</label>
                                <input type="datetime-local" id="published_date" name="published_date" class="form-control" 
                                       value="<?php echo $edit_news['published_date'] ? date('Y-m-d\TH:i', strtotime($edit_news['published_date'])) : ''; ?>">
                                <small class="small-muted">Required if status is Published</small>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="scheduled_date"><span class="icon icon-clock"></span> Schedule for Later (Optional)</label>
                                <input type="datetime-local" id="scheduled_date" name="scheduled_date" class="form-control"
                                       value="<?php echo $edit_news['scheduled_date'] ? date('Y-m-d\TH:i', strtotime($edit_news['scheduled_date'])) : ''; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="excerpt"><span class="icon icon-edit"></span> Excerpt/Summary</label>
                        <textarea id="excerpt" name="excerpt" class="form-control" rows="2"
                                  placeholder="Brief summary of the news (appears in news feed)..."><?php echo htmlspecialchars($edit_news['excerpt'] ?? ''); ?></textarea>
                        <small class="small-muted">100-150 characters recommended</small>
                    </div>

                    <div class="form-group">
                        <label for="content"><span class="icon icon-edit"></span> Content *</label>
                        <div class="editor-toolbar">
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('bold')" title="Bold"><span class="icon icon-check"></span></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('italic')" title="Italic"><span class="icon icon-info"></span></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('underline')" title="Underline"><span class="icon icon-info"></span></button>
                            <div class="toolbar-divider"></div>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('h2')" title="Heading"><span class="icon icon-info"></span></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('h3')" title="Subheading"><span class="icon icon-info"></span></button>
                            <div class="toolbar-divider"></div>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('ul')" title="Bullet List"><span class="icon icon-info"></span></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('ol')" title="Numbered List"><span class="icon icon-info"></span></button>
                            <div class="toolbar-divider"></div>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('link')" title="Insert Link"><span class="icon icon-info"></span></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('quote')" title="Quote"><span class="icon icon-info"></span></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('code')" title="Code Block"><span class="icon icon-info"></span></button>
                        </div>
                        <textarea id="content" name="content" class="form-control editor-textarea" rows="12" 
                                  placeholder="Full news content..."><?php echo htmlspecialchars($edit_news['content'] ?? ''); ?></textarea>
                        <small class="small-muted">💡 Tip: Use the toolbar buttons above to format your text</small>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="featured_image"><span class="icon icon-info"></span> Featured Image</label>
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
                                <label for="tags"><span class="icon icon-tag"></span> Tags (Optional)</label>
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
                                    <span class="icon icon-info"></span> Allow Comments on This News Item
                                </label>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="featured" <?php echo ($edit_news['featured'] ?? 0) ? 'checked' : ''; ?>>
                                    <span class="icon icon-star"></span> Mark as Featured News
                                </label>
                                <small class="small-muted">Featured news appears prominently on the homepage</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <?php if ($edit_news): ?>
                            <button type="submit" class="btn-gold">
                                <span class="icon icon-info"></span> Update News Item
                            </button>
                            <a href="schoolnews.php" class="btn-secondary">
                                <span class="icon icon-close"></span> Cancel
                            </a>
                        <?php else: ?>
                            <button type="submit" class="btn-gold">
                                <span class="icon icon-plus"></span> Create News Item
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- Search and Filter -->
        <section class="news-section">
            <h3><span class="icon icon-search"></span> Search & Filter News</h3>
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
                        <span class="icon icon-search"></span> Search
                    </button>
                    <a href="schoolnews.php" class="btn-reset">
                        <span class="icon icon-info"></span> Reset
                    </a>
                </form>
            </div>
        </section>

        <!-- News Table -->
        <section class="news-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h3><span class="icon icon-info"></span> Manage News Items</h3>
                <?php if (count($news_items) > 0): ?>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <span style="font-size: 0.9rem; color: var(--gray-500);">Bulk Actions:</span>
                    <form method="POST" id="bulkForm" style="display: flex; gap: 0.5rem;">
                        <input type="hidden" name="bulk_action" id="bulkActionInput">
                        <button type="button" onclick="performBulkAction('publish')" class="btn-small btn-approve">
                            <span class="icon icon-check"></span> Publish
                        </button>
                        <button type="button" onclick="performBulkAction('unpublish')" class="btn-small btn-unpublish">
                            <span class="icon icon-close"></span> Unpublish
                        </button>
                        <button type="button" onclick="performBulkAction('archive')" class="btn-small btn-delete">
                            <span class="icon icon-info"></span> Archive
                        </button>
                        <button type="button" onclick="performBulkAction('delete')" class="btn-small btn-delete" style="background: #dc3545;">
                            <span class="icon icon-delete"></span> Delete
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
                                                <span class="icon icon-eye"></span>
                                            </a>

                                            <a class="btn-small btn-edit" href="schoolnews.php?edit=<?php echo intval($item['id']); ?>" title="Edit">
                                                <span class="icon icon-edit"></span>
                                            </a>

                                            <?php if ($item['status'] === 'draft'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="publish">
                                                    <input type="hidden" name="id" value="<?php echo intval($item['id']); ?>">
                                                    <button type="submit" class="btn-small btn-approve" title="Publish">
                                                        <span class="icon icon-check"></span>
                                                    </button>
                                                </form>
                                            <?php elseif ($item['status'] === 'published'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="unpublish">
                                                    <input type="hidden" name="id" value="<?php echo intval($item['id']); ?>">
                                                    <button type="submit" class="btn-small btn-unpublish" title="Unpublish">
                                                        <span class="icon icon-close"></span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this news item?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo intval($item['id']); ?>">
                                                <button type="submit" class="btn-small btn-delete">
                                                    <span class="icon icon-delete"></span>
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

<!-- <footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Professional school management system designed to streamline educational administration and communication.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php"><span class="icon icon-dashboard"></span> Dashboard</a>
                    <a href="schoolnews.php"><span class="icon icon-news"></span> News Management</a>
                    <a href="manage_user.php"><span class="icon icon-users"></span> Users</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p><span class="icon icon-info"></span> Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
                <p><span class="icon icon-info"></span> Phone: +123 456 7890</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Version 1.0 | Blog Management System</p>
        </div>
    </div>
</footer> -->

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
        <span class="icon icon-${type === 'success' ? 'success' : type === 'error' ? 'error' : 'info'}"></span>
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
        const isMobile = window.innerWidth <= 1024;
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        const mobileToggle = document.getElementById('mobileMenuToggle');

        if (isMobile) {
            // Mobile layout
            if (sidebar) {
                sidebar.classList.remove('active');
            }
            if (mobileToggle) {
                mobileToggle.style.display = 'flex';
                mobileToggle.classList.remove('active');
            }
        } else {
            // Desktop layout
            if (sidebar) {
                sidebar.classList.remove('active');
            }
            if (mobileToggle) {
                mobileToggle.style.display = 'none';
            }
        }
    }

    // Mobile menu toggle functionality
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.querySelector('.sidebar');
    const sidebarClose = document.getElementById('sidebarClose');

    if (mobileMenuToggle && sidebar) {
        mobileMenuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            this.classList.toggle('active');
        });

        if (sidebarClose) {
            sidebarClose.addEventListener('click', function() {
                sidebar.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });
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
</script><?php include '../includes/floating-button.php'; ?></body>
</html>



