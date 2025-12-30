<?php
// filepath: c:\xampp\htdocs\sahabformmaster\student\newsdetails.php
session_start();

require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['student_name'];
$user_name = $_SESSION['student_name'] ?? 'Student';
$student_class = $_SESSION['admission_no'] ?? '';

// Get news ID from URL
$news_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($news_id <= 0) {
    header("Location: schoolfeed.php");
    exit;
}

// Fetch specific news item
$query = "SELECT * FROM school_news WHERE id = :id AND status = 'published'";
$stmt = $pdo->prepare($query);
$stmt->execute(['id' => $news_id]);
$news_item = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if student can view this news (target audience check)
if (!$news_item || 
    ($news_item['target_audience'] !== 'All' && 
     $news_item['target_audience'] !== 'Students' && 
     strpos($news_item['target_audience'], $student_class) === false)) {
    header("Location: schoolfeed.php");
    exit;
}

// Increment view count
$stmt = $pdo->prepare("UPDATE school_news SET view_count = view_count + 1 WHERE id = :id");
$stmt->execute(['id' => $news_id]);

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'comment') {
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
        
        // Update comment count
        $stmt = $pdo->prepare("UPDATE school_news SET comment_count = comment_count + 1 WHERE id = :id");
        $stmt->execute(['id' => $news_id]);
        
        // Redirect to avoid form resubmission
        header("Location: newsdetails.php?id=" . $news_id);
        exit;
    }
}

// Fetch comments for this news item
$stmt = $pdo->prepare("SELECT * FROM school_news_comments WHERE news_id = :news_id ORDER BY created_at DESC");
$stmt->execute(['news_id' => $news_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --accent-color: #4cc9f0;
            --success-color: #4CAF50;
            --warning-color: #FF9800;
            --danger-color: #f44336;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --gray-color: #6c757d;
            --card-bg: #ffffff;
            --shadow: 0 8px 30px rgba(0,0,0,0.08);
            --hover-shadow: 0 15px 35px rgba(0,0,0,0.15);
            --radius: 15px;
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
            line-height: 1.6;
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
            max-width: 1200px;
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
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-avatar {
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

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .user-role {
            font-size: 0.9rem;
            color: var(--gray-color);
            background: #f0f2f5;
            padding: 0.2rem 0.8rem;
            border-radius: 50px;
            margin-top: 0.2rem;
        }

        .btn-back {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 0.7rem 1.8rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .main-container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .article-header {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .article-category {
            display: inline-block;
            background: linear-gradient(135deg, var(--accent-color), var(--primary-color));
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        .article-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark-color);
            line-height: 1.3;
        }

        .article-meta {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-top: 1.5rem;
            color: var(--gray-color);
            font-size: 0.95rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .article-featured-image {
            margin: 2rem 0;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            max-height: 500px;
        }

        .article-featured-image img {
            width: 100%;
            height: auto;
            display: block;
            transition: transform 0.6s ease;
        }

        .article-featured-image:hover img {
            transform: scale(1.03);
        }

        .article-content {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            font-size: 1.1rem;
            line-height: 1.8;
        }

        .article-content h2 {
            color: var(--primary-color);
            margin: 2rem 0 1rem;
            font-size: 1.8rem;
        }

        .article-content h3 {
            color: var(--secondary-color);
            margin: 1.5rem 0 1rem;
            font-size: 1.5rem;
        }

        .article-content p {
            margin-bottom: 1.5rem;
        }

        .article-content ul, .article-content ol {
            margin: 1rem 0 1.5rem 2rem;
        }

        .article-content li {
            margin-bottom: 0.5rem;
        }

        .article-content blockquote {
            border-left: 4px solid var(--accent-color);
            padding: 1rem 2rem;
            margin: 2rem 0;
            background: #f8f9fa;
            border-radius: 0 var(--radius) var(--radius) 0;
            font-style: italic;
            color: var(--gray-color);
        }

        .comments-section {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .comments-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f2f5;
        }

        .comments-title {
            font-size: 1.8rem;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .comment-count {
            background: var(--accent-color);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .comment-form {
            margin-bottom: 3rem;
        }

        .comment-form textarea {
            width: 100%;
            padding: 1.2rem;
            border: 2px solid #e0e0e0;
            border-radius: var(--radius);
            font-size: 1rem;
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .comment-form textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .comment-form button {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 0.9rem 2.5rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .comment-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }

        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .comment-item {
            background: #f8f9fa;
            border-radius: var(--radius);
            padding: 1.5rem;
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s ease;
        }

        .comment-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow);
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .comment-author {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .comment-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .comment-author-info {
            display: flex;
            flex-direction: column;
        }

        .comment-author-name {
            font-weight: 600;
            color: var(--dark-color);
        }

        .comment-time {
            font-size: 0.85rem;
            color: var(--gray-color);
            margin-top: 0.2rem;
        }

        .comment-text {
            line-height: 1.7;
            color: var(--dark-color);
            font-size: 1.05rem;
        }

        .no-comments {
            text-align: center;
            padding: 3rem;
            color: var(--gray-color);
            font-size: 1.1rem;
            background: #f8f9fa;
            border-radius: var(--radius);
        }

        .no-comments i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
            opacity: 0.5;
        }

        .footer {
            background: linear-gradient(135deg, var(--dark-color), #343a40);
            color: white;
            padding: 2rem;
            text-align: center;
            margin-top: 3rem;
        }

        .footer p {
            margin-bottom: 0.5rem;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: var(--accent-color);
            text-decoration: none;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-links a:hover {
            color: white;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 1.2rem;
                text-align: center;
            }
            
            .user-info {
                flex-direction: column;
                gap: 1rem;
            }
            
            .article-title {
                font-size: 2rem;
            }
            
            .article-meta {
                flex-direction: column;
                gap: 1rem;
            }
            
            .article-content {
                padding: 1.5rem;
            }
            
            .comments-section {
                padding: 1.5rem;
            }
            
            .comments-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .footer-links {
                flex-direction: column;
                gap: 1rem;
            }
        }

        @media (max-width: 480px) {
            .main-container {
                padding: 0 1rem;
            }
            
            .article-header {
                padding: 1.5rem;
            }
            
            .article-title {
                font-size: 1.7rem;
            }
            
            .comment-form button {
                width: 100%;
                justify-content: center;
            }
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .article-header, .article-content, .comments-section {
            animation: fadeIn 0.6s ease forwards;
        }

        .comment-item {
            animation: fadeIn 0.5s ease forwards;
            opacity: 0;
        }

        .comment-item:nth-child(1) { animation-delay: 0.1s; }
        .comment-item:nth-child(2) { animation-delay: 0.2s; }
        .comment-item:nth-child(3) { animation-delay: 0.3s; }
        .comment-item:nth-child(4) { animation-delay: 0.4s; }
        .comment-item:nth-child(5) { animation-delay: 0.5s; }
    </style>
</head>
<body>
    <!-- <header class="header">
        <div class="header-container">
            <div class="logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="logo">
                <h1 class="school-name">SahabFormMaster</h1>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($student_class); ?> Student</span>
                </div>
                <a href="schoolfeed.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Feed
                </a>
            </div>
        </div>
    </header> -->
    <div class="user-info">
                <!-- <div class="user-avatar">
                    <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($student_class); ?> Student</span>
                </div>-->
                <a href="schoolfeed.php" class="btn-back"> 
                    <i class="fas fa-arrow-left"></i> Back to Feed
                </a>
            </div>

    <main class="main-container">
        <div class="article-header">
            <span class="article-category"><?php echo htmlspecialchars($news_item['category']); ?></span>
            <h1 class="article-title"><?php echo htmlspecialchars($news_item['title']); ?></h1>
            <p class="article-excerpt"><?php echo htmlspecialchars($news_item['excerpt']); ?></p>
            
            <div class="article-meta">
                <div class="meta-item">
                    <i class="far fa-calendar"></i>
                    Published: <?php echo date('F j, Y', strtotime($news_item['published_date'])); ?>
                </div>
                <div class="meta-item">
                    <i class="far fa-eye"></i>
                    <?php echo intval($news_item['view_count']); ?> views
                </div>
                <?php if ($news_item['allow_comments']): ?>
                    <div class="meta-item">
                        <i class="far fa-comment"></i>
                        <?php echo count($comments); ?> comments
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($news_item['featured_image']): ?>
            <div class="article-featured-image">
                <img src="../<?php echo htmlspecialchars($news_item['featured_image']); ?>" 
                     alt="<?php echo htmlspecialchars($news_item['title']); ?>">
            </div>
        <?php endif; ?>

        <div class="article-content">
            <?php echo nl2br(htmlspecialchars($news_item['content'])); ?>
            
            <?php if (!empty($news_item['tags'])): ?>
                <div class="article-tags" style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #eee;">
                    <strong>Tags:</strong>
                    <?php 
                    $tags = explode(',', $news_item['tags']);
                    foreach ($tags as $tag): 
                        $trimmed_tag = trim($tag);
                        if (!empty($trimmed_tag)):
                    ?>
                        <span style="display: inline-block; background: #f0f2f5; color: #666; padding: 0.3rem 0.8rem; border-radius: 50px; margin: 0.3rem; font-size: 0.9rem;">
                            #<?php echo htmlspecialchars($trimmed_tag); ?>
                        </span>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($news_item['allow_comments']): ?>
            <div class="comments-section">
                <div class="comments-header">
                    <h2 class="comments-title">
                        <i class="fas fa-comments"></i> Comments
                        <span class="comment-count"><?php echo count($comments); ?></span>
                    </h2>
                </div>

                <form method="POST" class="comment-form">
                    <input type="hidden" name="action" value="comment">
                    <textarea name="comment" placeholder="Share your thoughts about this news article..." required></textarea>
                    <button type="submit">
                        <i class="fas fa-paper-plane"></i> Post Comment
                    </button>
                </form>

                <?php if (empty($comments)): ?>
                    <div class="no-comments">
                        <i class="far fa-comment"></i>
                        <h3>No comments yet</h3>
                        <p>Be the first to share your thoughts!</p>
                    </div>
                <?php else: ?>
                    <div class="comments-list">
                        <?php foreach ($comments as $comment): ?>
                            <div class="comment-item">
                                <div class="comment-header">
                                    <div class="comment-author">
                                        <div class="comment-avatar">
                                            <?php echo strtoupper(substr($comment['name'], 0, 1)); ?>
                                        </div>
                                        <div class="comment-author-info">
                                            <div class="comment-author-name">
                                                <?php echo htmlspecialchars($comment['name']); ?>
                                            </div>
                                            <div class="comment-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('M j, Y \a\t g:i A', strtotime($comment['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="comment-text">
                                    <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="comments-section" style="text-align: center; padding: 3rem;">
                <i class="fas fa-comment-slash" style="font-size: 3rem; color: var(--gray-color); margin-bottom: 1rem;"></i>
                <h3>Comments are disabled</h3>
                <p>Comments are not allowed for this news article.</p>
            </div>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <p>&copy; 2025 SahabFormMaster. All rights reserved.</p>
        <p>Student News Portal</p>
        <!-- <div class="footer-links">
            <a href="schoolfeed.php"><i class="fas fa-newspaper"></i> News Feed</a>
            <a href="student-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div> -->
    </footer>

    <script>
        // Smooth scroll to comments section if URL has #comments hash
        if (window.location.hash === '#comments') {
            const commentsSection = document.querySelector('.comments-section');
            if (commentsSection) {
                setTimeout(() => {
                    commentsSection.scrollIntoView({ behavior: 'smooth' });
                }, 300);
            }
        }

        // Auto-expand textarea as user types
        const textarea = document.querySelector('textarea');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        // Show confirmation when leaving page with unsaved comment
        let commentChanged = false;
        if (textarea) {
            textarea.addEventListener('input', () => {
                commentChanged = textarea.value.trim().length > 0;
            });
            
            window.addEventListener('beforeunload', (e) => {
                if (commentChanged) {
                    e.preventDefault();
                    e.returnValue = 'You have an unsaved comment. Are you sure you want to leave?';
                }
            });
        }
    </script>
</body>
</html>