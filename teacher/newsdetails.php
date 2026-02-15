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
    <title><?php echo htmlspecialchars($news_item['title']); ?> | SahabFormMaster</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2E7D32;
            --secondary-color: #1B5E20;
            --accent-color: #4CAF50;
            --success-color: #66BB6A;
            --warning-color: #FFA726;
            --danger-color: #EF5350;
            --light-color: #f8f9fa;
            --dark-color: #263238;
            --gray-color: #546E7A;
            --card-bg: #ffffff;
            --shadow: 0 8px 30px rgba(0,0,0,0.08);
            --radius: 15px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #E8F5E9 0%, #C8E6C9 100%);
            min-height: 100vh;
            color: var(--dark-color);
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            height: 50px;
            width: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-color);
        }

        .school-name {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .teacher-panel {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .teacher-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.3rem;
        }

        .teacher-details {
            display: flex;
            flex-direction: column;
        }

        .teacher-name {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .teacher-subject {
            font-size: 0.9rem;
            color: var(--gray-color);
            background: #E8F5E9;
            padding: 0.2rem 0.8rem;
            border-radius: 50px;
            display: inline-block;
        }

        .nav-links {
            display: flex;
            gap: 1rem;
        }

        .nav-link {
            padding: 0.6rem 1.5rem;
            background: var(--accent-color);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link:hover {
            background: var(--primary-color);
            transform: translateY(-2px);
        }

        .main-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .back-btn {
            margin-bottom: 2rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            padding: 0.8rem 1.5rem;
            background: white;
            border-radius: 50px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .back-link:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .news-detail-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .news-detail-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .news-featured-image {
            width: 100%;
            height: 400px;
            object-fit: cover;
        }

        .news-header {
            padding: 2rem;
            border-bottom: 1px solid #eee;
        }

        .news-category {
            display: inline-block;
            background: var(--accent-color);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .news-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark-color);
            line-height: 1.3;
        }

        .news-meta {
            display: flex;
            gap: 2rem;
            align-items: center;
            font-size: 0.95rem;
            color: var(--gray-color);
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .news-content {
            padding: 2rem;
        }

        .news-content-text {
            line-height: 1.8;
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        .news-content-text p {
            margin-bottom: 1.5rem;
        }

        .comments-section {
            padding: 2rem;
            border-top: 1px solid #eee;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--accent-color);
        }

        .comment-form {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
        }

        .comment-input-group {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .comment-input {
            padding: 1rem;
            border: 2px solid #e0e0e0;
            border-radius: var(--radius);
            font-size: 1rem;
            transition: all 0.3s ease;
            min-height: 120px;
            resize: vertical;
        }

        .comment-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn-comment {
            align-self: flex-end;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-comment:hover {
            transform: translateY(-2px);
        }

        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .comment-item {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: var(--radius);
            border-left: 4px solid var(--accent-color);
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .comment-author {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .comment-time {
            font-size: 0.9rem;
            color: var(--gray-color);
        }

        .comment-text {
            line-height: 1.6;
            font-size: 1rem;
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .sidebar-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
        }

        .sidebar-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--accent-color);
        }

        .recent-news-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .recent-news-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--dark-color);
            transition: all 0.3s ease;
        }

        .recent-news-item:hover {
            background: var(--accent-color);
            color: white;
            transform: translateX(5px);
        }

        .recent-news-image {
            width: 80px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
        }

        .recent-news-content {
            flex: 1;
        }

        .recent-news-title {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 0.3rem;
        }

        .recent-news-date {
            font-size: 0.8rem;
            color: inherit;
            opacity: 0.8;
        }

        .teacher-info-sidebar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            border-radius: var(--radius);
        }

        .teacher-info-sidebar h3 {
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .teacher-info-sidebar p {
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .no-comments {
            text-align: center;
            padding: 2rem;
            color: var(--gray-color);
            font-style: italic;
        }

        .footer {
            background: var(--dark-color);
            color: white;
            padding: 2rem;
            text-align: center;
            margin-top: 3rem;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 1.5rem;
        }

        .footer-section h4 {
            color: var(--accent-color);
            margin-bottom: 1rem;
        }

        .footer-links {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .footer-links a {
            color: #ddd;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: white;
        }

        @media (max-width: 1024px) {
            .news-detail-container {
                grid-template-columns: 1fr;
            }
            
            .news-featured-image {
                height: 300px;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .teacher-panel {
                flex-direction: column;
                gap: 1rem;
            }
            
            .news-title {
                font-size: 1.8rem;
            }
            
            .news-meta {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- <header class="header">
        <div class="header-container">
            <div class="logo-container">
                <img src="assets/images/nysc.jpg" alt="School Logo" class="logo">
                <h1 class="school-name">SahabFormMaster</h1>
            </div>
            
            <div class="teacher-panel">
                <div class="teacher-info">
                    <div class="teacher-avatar">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                    <div class="teacher-details">
                        <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
                        <span class="teacher-subject"><?php echo htmlspecialchars($teacher_subject); ?></span>
                    </div>
                </div>
                
                <div class="nav-links">
                    <a href="schoolfeed.php" class="nav-link">
                        <i class="fas fa-newspaper"></i> News Feed
                    </a>
                    <a href="teacher-dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header> -->
      <div class="nav-links">
                    <a href="index.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                      <a href="schoolfeed.php" class="nav-link">
                        <i class="fas fa-newspaper"></i> News Feed
                    </a>
                    <!-- <a href="logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a> -->
                </div>

    <main class="main-container">
        <div class="back-btn">
            <a href="schoolfeed.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to News Feed
            </a>
        </div>

        <div class="news-detail-container">
            <div class="news-detail-card">
                <?php if ($news_item['featured_image']): ?>
                    <img src="../<?php echo htmlspecialchars($news_item['featured_image']); ?>" 
                         alt="<?php echo htmlspecialchars($news_item['title']); ?>" 
                         class="news-featured-image"
                         onerror="this.src='https://via.placeholder.com/800x400/4CAF50/ffffff?text=News+Image'">
                <?php else: ?>
                    <img src="https://via.placeholder.com/800x400/4CAF50/ffffff?text=School+News" 
                         alt="News Image" 
                         class="news-featured-image">
                <?php endif; ?>
                
                <div class="news-header">
                    <span class="news-category"><?php echo htmlspecialchars($news_item['category']); ?></span>
                    <h1 class="news-title"><?php echo htmlspecialchars($news_item['title']); ?></h1>
                    
                    <div class="news-meta">
                        <div class="meta-item">
                            <i class="far fa-calendar"></i>
                            Published: <?php echo date('F d, Y', strtotime($news_item['published_date'])); ?>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-users"></i>
                            Audience: <?php echo htmlspecialchars($news_item['target_audience']); ?>
                        </div>
                        <div class="meta-item">
                            <i class="far fa-eye"></i>
                            Views: <?php echo intval($news_item['view_count']); ?>
                        </div>
                        <?php if ($news_item['priority'] === 'high'): ?>
                            <div class="meta-item" style="color: var(--danger-color);">
                                <i class="fas fa-exclamation-triangle"></i>
                                High Priority
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="news-content">
                    <div class="news-content-text">
                        <?php echo nl2br(htmlspecialchars($news_item['content'])); ?>
                    </div>
                </div>
                
                <?php if ($news_item['allow_comments']): ?>
                    <div class="comments-section">
                        <h2 class="section-title">
                            <i class="fas fa-comments"></i> Comments (<?php echo count($comments); ?>)
                        </h2>
                        
                        <div class="comment-form">
                            <form method="POST">
                                <input type="hidden" name="action" value="comment">
                                <input type="hidden" name="news_id" value="<?php echo $news_id; ?>">
                                <div class="comment-input-group">
                                    <textarea name="comment" class="comment-input" 
                                              placeholder="Share your thoughts as a teacher..." required></textarea>
                                    <button type="submit" class="btn-comment">
                                        <i class="fas fa-paper-plane"></i> Post Comment
                                    </button>
                                </div>
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
                                                    <span style="font-size: 0.8rem; color: var(--accent-color);">(You)</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="comment-time">
                                                <?php echo date('M d, Y • h:i A', strtotime($comment['created_at'])); ?>
                                            </span>
                                        </div>
                                        <p class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="comments-section">
                        <div class="no-comments">
                            <p><i class="fas fa-comment-slash"></i> Comments are disabled for this post.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="sidebar">
                <div class="sidebar-card teacher-info-sidebar">
                    <h3><i class="fas fa-chalkboard-teacher"></i> Teacher Info</h3>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($user_name); ?></p>
                    <p><strong>Subject:</strong> <?php echo htmlspecialchars($teacher_subject); ?></p>
                    <p><strong>Role:</strong> Teacher</p>
                    <p><strong>Comment Access:</strong> <?php echo $news_item['allow_comments'] ? 'Enabled' : 'Disabled for this post'; ?></p>
                </div>
                
                <?php if (!empty($recent_news)): ?>
                    <div class="sidebar-card">
                        <h3 class="sidebar-title"><i class="fas fa-history"></i> Recent News</h3>
                        <div class="recent-news-list">
                            <?php foreach ($recent_news as $recent): ?>
                                <a href="newsdetails.php?id=<?php echo $recent['id']; ?>" class="recent-news-item">
                                    <?php if ($recent['featured_image']): ?>
                                        <img src="../<?php echo htmlspecialchars($recent['featured_image']); ?>" 
                                             alt="<?php echo htmlspecialchars($recent['title']); ?>" 
                                             class="recent-news-image"
                                             onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    <div class="recent-news-content">
                                        <div class="recent-news-title"><?php echo htmlspecialchars($recent['title']); ?></div>
                                        <div class="recent-news-date">
                                            <?php echo date('M d, Y', strtotime($recent['published_date'])); ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="sidebar-card">
                    <h3 class="sidebar-title"><i class="fas fa-info-circle"></i> News Details</h3>
                    <div class="news-details-info">
                        <p><strong>Status:</strong> Published</p>
                        <p><strong>Comments:</strong> <?php echo $news_item['allow_comments'] ? 'Allowed' : 'Not Allowed'; ?></p>
                        <p><strong>Priority:</strong> <?php echo ucfirst($news_item['priority']); ?></p>
                        <p><strong>Target:</strong> <?php echo htmlspecialchars($news_item['target_audience']); ?></p>
                        <p><strong>Views:</strong> <?php echo intval($news_item['view_count']); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </main>
<!-- 
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-section">
                <h4>SahabFormMaster</h4>
                <p>Comprehensive school management system with integrated blog and communication features.</p>
            </div> -->
            <!-- <div class="footer-section">
                <h4>Teacher Resources</h4>
                <div class="footer-links">
                    <a href="teacher-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="schoolfeed.php"><i class="fas fa-newspaper"></i> News Feed</a>
                    <a href="lesson-plans.php"><i class="fas fa-book"></i> Lesson Plans</a>
                    <a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Contact</h4>
                <p><i class="fas fa-envelope"></i> support@sahabformmaster.com</p>
                <p><i class="fas fa-phone"></i> +123 456 7890</p>
            </div> -->
        <!-- </div>
        <p>&copy; 2025 SahabFormMaster. All rights reserved.</p>
    </footer> -->

    <script>
        // Auto-expand textarea as user types
        const commentTextarea = document.querySelector('.comment-input');
        if (commentTextarea) {
            commentTextarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        // Confirm before leaving if comment is typed
        window.addEventListener('beforeunload', function(e) {
            if (commentTextarea && commentTextarea.value.trim() !== '') {
                e.preventDefault();
                e.returnValue = 'You have unsaved comment. Are you sure you want to leave?';
            }
        });
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
