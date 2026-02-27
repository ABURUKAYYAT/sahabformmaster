<?php

session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

// School authentication and context
$current_school_id = require_school_auth();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Teacher';
$teacher_subject = $_SESSION['subject'] ?? '';

// Get news ID from URL
$news_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($news_id <= 0) {
    header("Location: schoolfeed.php");
    exit;
}

// Fetch the specific news item - filtered by school_id
$stmt = $pdo->prepare("SELECT * FROM school_news WHERE id = :id AND status = 'published' AND school_id = :school_id");
$stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);
$news_item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$news_item) {
    header("Location: schoolfeed.php");
    exit;
}

// Update view count - filtered by school_id
$stmt = $pdo->prepare("UPDATE school_news SET view_count = view_count + 1 WHERE id = :id AND school_id = :school_id");
$stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'comment') {
        $comment = trim($_POST['comment'] ?? '');
        
        if (!empty($comment)) {
            $stmt = $pdo->prepare("INSERT INTO school_news_comments (news_id, user_id, name, comment) 
                                   VALUES (:news_id, :user_id, :name, :comment)");
            $stmt->execute([
                'news_id' => $news_id,
                'user_id' => $user_id,
                'name' => $user_name,
                'comment' => $comment
            ]);
            
            // Redirect to prevent form resubmission
            header("Location: newsdetails.php?id=" . $news_id);
            exit;
        }
    }
}

// Fetch comments for this news item
$stmt = $pdo->prepare("SELECT * FROM school_news_comments WHERE news_id = :news_id ORDER BY created_at DESC");
$stmt->execute(['news_id' => $news_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent news for sidebar - filtered by school_id
$recent_stmt = $pdo->prepare("SELECT id, title, published_date, featured_image FROM school_news
                              WHERE status = 'published' AND id != :news_id AND school_id = :school_id
                              ORDER BY published_date DESC LIMIT 5");
$recent_stmt->execute(['news_id' => $news_id, 'school_id' => $current_school_id]);
$recent_news = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($news_item['title']); ?> | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f5f7fb;
        }

        .dashboard-container .main-content {
            width: 100%;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .content-header {
            background: #ffffff;
            border: 1px solid #cfe1ff;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #1d4ed8;
            text-decoration: none;
            font-weight: 600;
            background: #eef4ff;
            padding: 0.6rem 1rem;
            border-radius: 999px;
        }

        .news-layout {
            display: grid;
            grid-template-columns: minmax(0, 2.2fr) minmax(0, 1fr);
            gap: 1.5rem;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #cfe1ff;
            border-radius: 12px;
            box-shadow: none;
            overflow: hidden;
        }

        .panel-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #cfe1ff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: #1d4ed8;
            color: #fff;
        }

        .panel-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .panel-body {
            padding: 1.5rem;
        }

        .news-hero {
            width: 100%;
            height: 360px;
            object-fit: cover;
            background: #eaf2ff;
        }

        .news-category {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: #1d4ed8;
            color: #fff;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .news-title {
            font-size: 1.9rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.75rem;
        }

        .news-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            color: #64748b;
            font-size: 0.9rem;
        }

        .news-meta .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .news-content-text {
            color: #334155;
            line-height: 1.8;
            font-size: 1rem;
        }

        .news-content-text p {
            margin-bottom: 1rem;
        }

        .comments-section {
            border-top: 1px solid #eef2f7;
            padding-top: 1.5rem;
            margin-top: 1.5rem;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 1rem;
        }

        .comment-form {
            background: #f5f9ff;
            border: 1px solid #dbe7fb;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .comment-input {
            width: 100%;
            padding: 0.9rem;
            border: 1px solid #dbe7fb;
            border-radius: 10px;
            font-size: 0.95rem;
            resize: vertical;
            min-height: 110px;
        }

        .comment-input:focus {
            outline: none;
            border-color: #1d4ed8;
            box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.15);
        }

        .btn-comment {
            margin-top: 0.75rem;
            background: #1d4ed8;
            color: #fff;
            border: none;
            padding: 0.7rem 1.2rem;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .comments-list {
            display: grid;
            gap: 0.9rem;
        }

        .comment-item {
            border: 1px solid #e5eefb;
            background: #fff;
            border-radius: 12px;
            padding: 1rem;
        }

        .comment-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .comment-author {
            font-weight: 600;
            color: #1d4ed8;
        }

        .comment-time {
            font-size: 0.85rem;
            color: #64748b;
        }

        .sidebar-card {
            background: #fff;
            border: 1px solid #cfe1ff;
            border-radius: 12px;
            padding: 1.25rem;
        }

        .sidebar-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 1rem;
        }

        .recent-news-list {
            display: grid;
            gap: 0.75rem;
        }

        .recent-news-item {
            display: flex;
            gap: 0.75rem;
            text-decoration: none;
            color: inherit;
            padding: 0.75rem;
            border-radius: 10px;
            background: #f7faff;
            border: 1px solid #e5eefb;
        }

        .recent-news-image {
            width: 72px;
            height: 56px;
            border-radius: 8px;
            object-fit: cover;
        }

        .recent-news-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: #0f172a;
        }

        .recent-news-date {
            font-size: 0.8rem;
            color: #64748b;
        }

        .info-list {
            display: grid;
            gap: 0.5rem;
            color: #475569;
            font-size: 0.95rem;
        }

        .info-list strong {
            color: #0f172a;
        }

        .no-comments {
            text-align: center;
            padding: 1.5rem;
            color: #64748b;
            border: 1px dashed #cfe1ff;
            border-radius: 12px;
            background: #f7faff;
        }

        @media (max-width: 1024px) {
            .news-layout {
                grid-template-columns: 1fr;
            }

            .news-hero {
                height: 280px;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 1rem;
            }

            .news-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/mobile_navigation.php'; ?>

    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">News Details</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include '../includes/teacher_sidebar.php'; ?>
        <main class="main-content">
            <div class="main-container">
                <div class="content-header">
                    <div>
                        <h2>School News</h2>
                        <p>Full details and teacher discussion.</p>
                    </div>
                    <a href="schoolfeed.php" class="back-link">
                        <i class="fas fa-arrow-left"></i> Back to News Feed
                    </a>
                </div>

                <div class="news-layout">
                    <div class="panel">
                        <?php if ($news_item['featured_image']): ?>
                            <img src="../<?php echo htmlspecialchars($news_item['featured_image']); ?>"
                                 alt="<?php echo htmlspecialchars($news_item['title']); ?>"
                                 class="news-hero"
                                 onerror="this.src='https://via.placeholder.com/800x400/1d4ed8/ffffff?text=News+Image'">
                        <?php else: ?>
                            <img src="https://via.placeholder.com/800x400/1d4ed8/ffffff?text=School+News"
                                 alt="News Image"
                                 class="news-hero">
                        <?php endif; ?>

                        <div class="panel-body">
                            <div class="news-category">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($news_item['category']); ?>
                            </div>
                            <h1 class="news-title"><?php echo htmlspecialchars($news_item['title']); ?></h1>
                            <div class="news-meta">
                                <div class="meta-item">
                                    <i class="far fa-calendar"></i>
                                    <?php echo date('F d, Y', strtotime($news_item['published_date'])); ?>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-users"></i>
                                    <?php echo htmlspecialchars($news_item['target_audience']); ?>
                                </div>
                                <div class="meta-item">
                                    <i class="far fa-eye"></i>
                                    <?php echo intval($news_item['view_count']); ?> views
                                </div>
                                <?php if ($news_item['priority'] === 'high'): ?>
                                    <div class="meta-item" style="color: #dc2626;">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        High Priority
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="news-content-text" style="margin-top: 1.5rem;">
                                <?php echo nl2br(htmlspecialchars($news_item['content'])); ?>
                            </div>

                            <div class="comments-section">
                                <?php if ($news_item['allow_comments']): ?>
                                    <h2 class="section-title">
                                        <i class="fas fa-comments"></i> Comments (<?php echo count($comments); ?>)
                                    </h2>
                                    <div class="comment-form">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="comment">
                                            <input type="hidden" name="news_id" value="<?php echo $news_id; ?>">
                                            <textarea name="comment" class="comment-input" placeholder="Share your thoughts as a teacher..." required></textarea>
                                            <button type="submit" class="btn-comment">
                                                <i class="fas fa-paper-plane"></i> Post Comment
                                            </button>
                                        </form>
                                    </div>
                                    <div class="comments-list">
                                        <?php if (empty($comments)): ?>
                                            <div class="no-comments">
                                                <p>No comments yet. Be the first to comment!</p>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($comments as $comment): ?>
                                                <div class="comment-item">
                                                    <div class="comment-header">
                                                        <span class="comment-author">
                                                            <?php echo htmlspecialchars($comment['name']); ?>
                                                            <?php if ($comment['user_id'] == $user_id): ?>
                                                                <span style="font-size: 0.8rem; color: #1d4ed8;">(You)</span>
                                                            <?php endif; ?>
                                                        </span>
                                                        <span class="comment-time">
                                                            <?php echo date('M d, Y', strtotime($comment['created_at'])); ?> &bull; <?php echo date('h:i A', strtotime($comment['created_at'])); ?>
                                                        </span>
                                                    </div>
                                                    <p class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-comments">
                                        <p><i class="fas fa-comment-slash"></i> Comments are disabled for this post.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <aside class="sidebar">
                        <div class="sidebar-card">
                            <div class="sidebar-title"><i class="fas fa-user"></i> Teacher Info</div>
                            <div class="info-list">
                                <div><strong>Name:</strong> <?php echo htmlspecialchars($user_name); ?></div>
                                <div><strong>Subject:</strong> <?php echo htmlspecialchars($teacher_subject); ?></div>
                                <div><strong>Role:</strong> Teacher</div>
                                <div><strong>Comment Access:</strong> <?php echo $news_item['allow_comments'] ? 'Enabled' : 'Disabled'; ?></div>
                            </div>
                        </div>

                        <?php if (!empty($recent_news)): ?>
                            <div class="sidebar-card">
                                <div class="sidebar-title"><i class="fas fa-history"></i> Recent News</div>
                                <div class="recent-news-list">
                                    <?php foreach ($recent_news as $recent): ?>
                                        <a href="newsdetails.php?id=<?php echo $recent['id']; ?>" class="recent-news-item">
                                            <?php if ($recent['featured_image']): ?>
                                                <img src="../<?php echo htmlspecialchars($recent['featured_image']); ?>"
                                                     alt="<?php echo htmlspecialchars($recent['title']); ?>"
                                                     class="recent-news-image"
                                                     onerror="this.style.display='none'">
                                            <?php endif; ?>
                                            <div>
                                                <div class="recent-news-title"><?php echo htmlspecialchars($recent['title']); ?></div>
                                                <div class="recent-news-date"><?php echo date('M d, Y', strtotime($recent['published_date'])); ?></div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="sidebar-card">
                            <div class="sidebar-title"><i class="fas fa-info-circle"></i> News Details</div>
                            <div class="info-list">
                                <div><strong>Status:</strong> Published</div>
                                <div><strong>Comments:</strong> <?php echo $news_item['allow_comments'] ? 'Allowed' : 'Not Allowed'; ?></div>
                                <div><strong>Priority:</strong> <?php echo ucfirst($news_item['priority']); ?></div>
                                <div><strong>Target:</strong> <?php echo htmlspecialchars($news_item['target_audience']); ?></div>
                                <div><strong>Views:</strong> <?php echo intval($news_item['view_count']); ?></div>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </main>
    </div>

    <script>
        const commentTextarea = document.querySelector('.comment-input');
        if (commentTextarea) {
            commentTextarea.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = this.scrollHeight + 'px';
            });
        }

        window.addEventListener('beforeunload', function (e) {
            if (commentTextarea && commentTextarea.value.trim() !== '') {
                e.preventDefault();
                e.returnValue = 'You have an unsaved comment. Are you sure you want to leave?';
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>
</body>
</html>

