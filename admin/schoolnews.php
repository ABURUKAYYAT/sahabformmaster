<?php
// filepath: c:\xampp\htdocs\sahabformmaster\admin\schoolnews.php
session_start();
require_once '../config/db.php';

// Only allow principal (admin) to access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'User';

$errors = [];
$success = '';

// Handle Create / Update via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $title = trim($_POST['title'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $target_audience = trim($_POST['target_audience'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    $allow_comments = isset($_POST['allow_comments']) ? 1 : 0;
    $tags = trim($_POST['tags'] ?? '');
    $published_date = trim($_POST['published_date'] ?? '');
    $scheduled_date = trim($_POST['scheduled_date'] ?? '');

    // Validate inputs
    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if ($content === '') {
        $errors[] = 'Content is required.';
    }
    if ($category === '') {
        $errors[] = 'Category is required.';
    }
    if ($target_audience === '') {
        $errors[] = 'Target audience is required.';
    }
    if ($status === 'published' && ($published_date === '' || !strtotime($published_date))) {
        $errors[] = 'Valid published date is required for published news.';
    }
    if ($scheduled_date !== '' && !strtotime($scheduled_date)) {
        $errors[] = 'Invalid scheduled date format.';
    }

    // Generate slug from title
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));

    // Handle featured image upload
    $featured_image = null;
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($_FILES['featured_image']['type'], $allowed_types)) {
            $errors[] = 'Only JPG, PNG, GIF, and WebP images are allowed.';
        } elseif ($_FILES['featured_image']['size'] > 5242880) { // 5MB
            $errors[] = 'Image size must not exceed 5MB.';
        } else {
            $upload_dir = '../uploads/news/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
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
        if ($action === 'add') {
            // Check if slug already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM school_news WHERE slug = :slug");
            $stmt->execute(['slug' => $slug]);
            if ($stmt->fetchColumn() > 0) {
                $slug .= '-' . time();
            }

            $stmt = $pdo->prepare("INSERT INTO school_news 
                                  (title, slug, excerpt, content, category, featured_image, author_id, 
                                   priority, target_audience, status, allow_comments, tags, published_date, scheduled_date) 
                                  VALUES (:title, :slug, :excerpt, :content, :category, :featured_image, :author_id, 
                                          :priority, :target_audience, :status, :allow_comments, :tags, :published_date, :scheduled_date)");
            $stmt->execute([
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt,
                'content' => $content,
                'category' => $category,
                'featured_image' => $featured_image,
                'author_id' => $user_id,
                'priority' => $priority,
                'target_audience' => $target_audience,
                'status' => $status,
                'allow_comments' => $allow_comments,
                'tags' => $tags,
                'published_date' => $status === 'published' ? $published_date : null,
                'scheduled_date' => $scheduled_date !== '' ? $scheduled_date : null
            ]);
            $success = 'News item created successfully.';
            header("Location: schoolnews.php");
            exit;
        }

        if ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid news ID.';
            } else {
                // Fetch old featured image
                $stmt = $pdo->prepare("SELECT featured_image FROM school_news WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $old_news = $stmt->fetch();

                $featured_image = $featured_image ?? $old_news['featured_image'];

                $stmt = $pdo->prepare("UPDATE school_news SET 
                                      title = :title, slug = :slug, excerpt = :excerpt, content = :content, 
                                      category = :category, featured_image = :featured_image, priority = :priority, 
                                      target_audience = :target_audience, status = :status, allow_comments = :allow_comments, 
                                      tags = :tags, published_date = :published_date, scheduled_date = :scheduled_date 
                                      WHERE id = :id");
                $stmt->execute([
                    'title' => $title,
                    'slug' => $slug,
                    'excerpt' => $excerpt,
                    'content' => $content,
                    'category' => $category,
                    'featured_image' => $featured_image,
                    'priority' => $priority,
                    'target_audience' => $target_audience,
                    'status' => $status,
                    'allow_comments' => $allow_comments,
                    'tags' => $tags,
                    'published_date' => $status === 'published' ? $published_date : null,
                    'scheduled_date' => $scheduled_date !== '' ? $scheduled_date : null,
                    'id' => $id
                ]);
                $success = 'News item updated successfully.';
                header("Location: schoolnews.php");
                exit;
            }
        }

        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid news ID.';
            } else {
                // Soft delete (archive)
                $stmt = $pdo->prepare("UPDATE school_news SET status = :status WHERE id = :id");
                $stmt->execute(['status' => 'archived', 'id' => $id]);
                $success = 'News item archived.';
                header("Location: schoolnews.php");
                exit;
            }
        }

        if ($action === 'publish') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid news ID.';
            } else {
                $stmt = $pdo->prepare("UPDATE school_news SET status = :status, published_date = :published_date WHERE id = :id");
                $stmt->execute(['status' => 'published', 'published_date' => date('Y-m-d H:i:s'), 'id' => $id]);
                $success = 'News published successfully.';
                header("Location: schoolnews.php");
                exit;
            }
        }

        if ($action === 'unpublish') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid news ID.';
            } else {
                $stmt = $pdo->prepare("UPDATE school_news SET status = :status WHERE id = :id");
                $stmt->execute(['status' => 'draft', 'id' => $id]);
                $success = 'News unpublished.';
                header("Location: schoolnews.php");
                exit;
            }
        }
    }
}

// Search and filter
$search = trim($_GET['search'] ?? '');
$filter_category = $_GET['filter_category'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$filter_priority = $_GET['filter_priority'] ?? '';

$query = "SELECT * FROM school_news WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (title LIKE :search OR excerpt LIKE :search OR content LIKE :search OR tags LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($filter_category !== '') {
    $query .= " AND category = :category";
    $params['category'] = $filter_category;
}

if ($filter_status !== '') {
    $query .= " AND status = :status";
    $params['status'] = $filter_status;
}

if ($filter_priority !== '') {
    $query .= " AND priority = :priority";
    $params['priority'] = $filter_priority;
}

// Exclude archived by default unless specifically filtered
if ($filter_status === '') {
    $query .= " AND status != 'archived'";
}

$query .= " ORDER BY published_date DESC, created_at DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all categories for filter dropdown
$stmt = $pdo->query("SELECT DISTINCT category FROM school_news WHERE category IS NOT NULL ORDER BY category ASC");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// If editing, fetch news data
$edit_news = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM school_news WHERE id = :id");
        $stmt->execute(['id' => $edit_id]);
        $edit_news = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Get status badge color
function getStatusBadge($status) {
    $classes = [
        'draft' => 'badge-secondary',
        'published' => 'badge-success',
        'archived' => 'badge-danger'
    ];
    return $classes[$status] ?? 'badge-default';
}

// Get priority badge color
function getPriorityBadge($priority) {
    $classes = [
        'low' => 'badge-secondary',
        'medium' => 'badge-warning',
        'high' => 'badge-danger'
    ];
    return $classes[$priority] ?? 'badge-default';
}
?>
<?php
// ...existing code...
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>School News | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/schoolnews.css">
</head>
<body>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-right">
            <div class="school-logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                <h1 class="school-name">SahabFormMaster</h1>
            </div>
        </div> 

        <div class="header-left">
            <div class="teacher-info">
                <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="teacher-role">Principal</span>
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Manage Students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Manage Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Manage Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="timebook.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="travelling.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Travelling</span>
                        </a>
                    </li>
                                        
                    <li class="nav-item">
                        <a href="classwork.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Class Work</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="assignment.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Assignment</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="attendance.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="schoolfees.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School Fees Payments</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

    <main class="main-content">
        <div class="content-header">
            <h2>School News Management</h2>
            <p class="small-muted">Create, edit, and manage school news and announcements</p>
        </div>

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
                                <label for="title">Title *</label>
                                <input type="text" id="title" name="title" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_news['title'] ?? ''); ?>" 
                                       placeholder="e.g. Annual Sports Day 2025" required>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="category">Category *</label>
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
                                <label for="priority">Priority Level</label>
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
                                <label for="target_audience">Target Audience *</label>
                                <select id="target_audience" name="target_audience" class="form-control" required>
                                    <option value="">Select Audience</option>
                                    <?php $sel_aud = $edit_news['target_audience'] ?? ''; ?>
                                    <option value="All" <?php echo $sel_aud === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Students" <?php echo $sel_aud === 'Students' ? 'selected' : ''; ?>>Students</option>
                                    <option value="Parents" <?php echo $sel_aud === 'Parents' ? 'selected' : ''; ?>>Parents</option>
                                    <option value="Staff" <?php echo $sel_aud === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <?php $sel_stat = $edit_news['status'] ?? 'draft'; ?>
                                    <option value="draft" <?php echo $sel_stat === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="published" <?php echo $sel_stat === 'published' ? 'selected' : ''; ?>>Published</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="published_date">Published Date</label>
                                <input type="datetime-local" id="published_date" name="published_date" class="form-control" 
                                       value="<?php echo $edit_news['published_date'] ? date('Y-m-d\TH:i', strtotime($edit_news['published_date'])) : ''; ?>">
                                <small class="small-muted">Required if status is Published</small>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="scheduled_date">Schedule for Later (Optional)</label>
                                <input type="datetime-local" id="scheduled_date" name="scheduled_date" class="form-control" 
                                       value="<?php echo $edit_news['scheduled_date'] ? date('Y-m-d\TH:i', strtotime($edit_news['scheduled_date'])) : ''; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="excerpt">Excerpt/Summary</label>
                        <textarea id="excerpt" name="excerpt" class="form-control" rows="2" 
                                  placeholder="Brief summary of the news (appears in news feed)..."><?php echo htmlspecialchars($edit_news['excerpt'] ?? ''); ?></textarea>
                        <small class="small-muted">100-150 characters recommended</small>
                    </div>

                    <div class="form-group">
                        <label for="content">Content *</label>
                        <div class="editor-toolbar">
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('bold')" title="Bold"><strong>B</strong></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('italic')" title="Italic"><em>I</em></button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('underline')" title="Underline"><u>U</u></button>
                            <div class="toolbar-divider"></div>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('h2')" title="Heading">H2</button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('h3')" title="Subheading">H3</button>
                            <div class="toolbar-divider"></div>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('ul')" title="Bullet List">• List</button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('ol')" title="Numbered List">1. List</button>
                            <div class="toolbar-divider"></div>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('link')" title="Insert Link">🔗 Link</button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('quote')" title="Quote">❝ Quote</button>
                            <button type="button" class="toolbar-btn" onclick="insertFormatting('code')" title="Code Block">{ } Code</button>
                        </div>
                        <textarea id="content" name="content" class="form-control editor-textarea" rows="12" 
                                  placeholder="Full news content..."><?php echo htmlspecialchars($edit_news['content'] ?? ''); ?></textarea>
                        <small class="small-muted">💡 Tip: Use the toolbar buttons above to format your text</small>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="featured_image">Featured Image</label>
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
                                <label for="tags">Tags (Optional)</label>
                                <input type="text" id="tags" name="tags" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_news['tags'] ?? ''); ?>" 
                                       placeholder="Comma-separated (e.g. sports, achievement, award)">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="allow_comments" <?php echo ($edit_news['allow_comments'] ?? 1) ? 'checked' : ''; ?>>
                            Allow Comments on This News Item
                        </label>
                    </div>

                    <div class="form-actions">
                        <?php if ($edit_news): ?>
                            <button type="submit" class="btn-gold">Update News Item</button>
                            <a href="schoolnews.php" class="btn-secondary">Cancel</a>
                        <?php else: ?>
                            <button type="submit" class="btn-gold">Create News Item</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- Search and Filter -->
        <section class="news-section">
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

                    <button type="submit" class="btn-search">Search</button>
                    <a href="schoolnews.php" class="btn-reset">Reset</a>
                </form>
            </div>
        </section>

        <!-- News Table -->
        <section class="news-section">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Audience</th>
                            <th>Published</th>
                            <th>Views</th>
                            <th style="width:200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($news_items) === 0): ?>
                            <tr><td colspan="9" class="text-center small-muted">No news items found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($news_items as $item): ?>
                                <tr>
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
                                            <a class="btn-small btn-view" href="schoolnews-detail.php?id=<?php echo intval($item['id']); ?>" title="View">👁</a>

                                            <a class="btn-small btn-edit" href="schoolnews.php?edit=<?php echo intval($item['id']); ?>" title="Edit">Edit</a>

                                            <?php if ($item['status'] === 'draft'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="publish">
                                                    <input type="hidden" name="id" value="<?php echo intval($item['id']); ?>">
                                                    <button type="submit" class="btn-small btn-approve" title="Publish">✓</button>
                                                </form>
                                            <?php elseif ($item['status'] === 'published'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="unpublish">
                                                    <input type="hidden" name="id" value="<?php echo intval($item['id']); ?>">
                                                    <button type="submit" class="btn-small btn-unpublish" title="Unpublish">⊘</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this news item?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo intval($item['id']); ?>">
                                                <button type="submit" class="btn-small btn-delete">Delete</button>
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

        <!-- Statistics Card -->
        <section class="news-section">
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
    </main>
</div>

<footer class="dashboard-footer">
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
</script>
 
</body>
</html>