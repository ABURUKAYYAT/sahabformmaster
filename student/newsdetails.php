<?php
// filepath: c:\xampp\htdocs\sahabformmaster\student\newsdetails.php
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['student_name'];
$user_name = $_SESSION['student_name'] ?? 'Student';
$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];
$current_school_id = get_current_school_id();

// Get news ID from URL
$news_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($news_id <= 0) {
    header("Location: schoolfeed.php");
    exit;
}

// Fetch specific news item
$query = "SELECT * FROM school_news WHERE id = :id AND status = 'published' AND school_id = :school_id";
$stmt = $pdo->prepare($query);
$stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);
$news_item = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if student can view this news (target audience check)
if (!$news_item ||
    ($news_item['target_audience'] !== 'All' &&
     $news_item['target_audience'] !== 'Students' &&
     strpos($news_item['target_audience'], $admission_number) === false)) {
    header("Location: schoolfeed.php");
    exit;
}

// Increment view count
$stmt = $pdo->prepare("UPDATE school_news SET view_count = view_count + 1 WHERE id = :id AND school_id = :school_id");
$stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'comment') {
    $comment = trim($_POST['comment'] ?? '');

    if (!empty($comment)) {
        $stmt = $pdo->prepare("INSERT INTO school_news_comments (news_id, user_id, name, comment, school_id)
                               VALUES (:news_id, :user_id, :name, :comment, :school_id)");
        $stmt->execute([
            'news_id' => $news_id,
            'user_id' => $user_id,
            'name' => $user_name,
            'comment' => $comment,
            'school_id' => $current_school_id
        ]);

        // Update comment count
        $stmt = $pdo->prepare("UPDATE school_news SET comment_count = comment_count + 1 WHERE id = :id AND school_id = :school_id");
        $stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);

        // Redirect to avoid form resubmission
        header("Location: newsdetails.php?id=" . $news_id);
        exit;
    }
}

// Fetch comments for this news item
$stmt = $pdo->prepare("SELECT * FROM school_news_comments WHERE news_id = :news_id AND school_id = :school_id ORDER BY created_at DESC");
$stmt->execute(['news_id' => $news_id, 'school_id' => $current_school_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($news_item['title']); ?> | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        :root {
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --secondary-color: #06b6d4;
            --accent-color: #8b5cf6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #3b82f6;
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
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --border-radius-sm: 0.375rem;
            --border-radius-md: 0.5rem;
            --border-radius-lg: 0.75rem;
            --border-radius-xl: 1rem;
            --transition-fast: 0.15s ease-in-out;
            --transition-normal: 0.3s ease-in-out;
            --transition-slow: 0.5s ease-in-out;
        }

        .news-details-container {
            min-height: calc(100vh - 80px);
        }

        .news-header-card {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            margin-bottom: 2rem;
            transition: var(--transition-normal);
        }

        .news-header-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .back-to-feed-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius-lg);
            font-size: 0.9rem;
            font-weight: 500;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: var(--transition-fast);
            margin-bottom: 1rem;
        }

        .back-to-feed-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }

        .news-category-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            backdrop-filter: blur(10px);
        }

        .news-title {
            margin: 0;
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 0.5rem;
        }

        .news-excerpt {
            margin: 0.5rem 0 0;
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .news-meta {
            display: flex;
            gap: 2rem;
            margin-top: 1.5rem;
            color: rgba(255,255,255,0.9);
            flex-wrap: wrap;
        }

        .news-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }

        .news-content-card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 2rem;
            transition: var(--transition-normal);
        }

        .news-content-card:hover {
            box-shadow: var(--shadow-xl);
        }

        .news-featured-image {
            width: 100%;
            max-height: 500px;
            object-fit: cover;
            border-radius: var(--border-radius-lg);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .news-content {
            font-size: 1.1rem;
            line-height: 1.8;
            color: var(--gray-700);
            margin-bottom: 2rem;
        }

        .news-tags {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--gray-100);
        }

        .news-tags strong {
            color: var(--gray-800);
            margin-bottom: 1rem;
            display: block;
            font-weight: 600;
        }

        .tag-item {
            display: inline-block;
            background: var(--gray-100);
            color: var(--gray-700);
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            margin: 0.3rem;
            font-size: 0.9rem;
            border: 1px solid var(--gray-200);
            transition: var(--transition-fast);
        }

        .tag-item:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-1px);
        }

        .comments-section {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .comments-header {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .comments-header h4 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .comments-body {
            padding: 2rem;
        }

        .comment-form {
            margin-bottom: 2rem;
            background: var(--gray-50);
            padding: 1.5rem;
            border-radius: var(--border-radius-lg);
            border: 1px solid var(--gray-200);
        }

        .comment-form-group {
            margin-bottom: 1rem;
        }

        .comment-form label {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            display: block;
        }

        .comment-textarea {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-lg);
            background: var(--white);
            color: var(--gray-700);
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: var(--transition-fast);
            resize: vertical;
        }

        .comment-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .btn-submit-comment {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius-lg);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition-fast);
            font-size: 0.95rem;
        }

        .btn-submit-comment:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .no-comments {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray-500);
        }

        .no-comments i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--gray-300);
        }

        .no-comments h5 {
            margin-bottom: 0.5rem;
            color: var(--gray-600);
        }

        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .comment-item {
            background: var(--gray-50);
            padding: 1.5rem;
            border-radius: var(--border-radius-lg);
            border-left: 4px solid var(--primary-color);
            transition: var(--transition-normal);
        }

        .comment-item:hover {
            background: var(--gray-100);
            box-shadow: var(--shadow-sm);
        }

        .comment-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .comment-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            flex-shrink: 0;
        }

        .comment-info {
            flex: 1;
        }

        .comment-author {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .comment-date {
            color: var(--gray-500);
            font-size: 0.85rem;
        }

        .comment-text {
            color: var(--gray-700);
            line-height: 1.6;
        }

        .comments-disabled {
            text-align: center;
            padding: 3rem 2rem;
        }

        .comments-disabled i {
            font-size: 3rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
        }

        .comments-disabled h5 {
            margin-bottom: 0.5rem;
            color: var(--gray-600);
        }

        .comments-disabled p {
            color: var(--gray-500);
            margin: 0;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .news-title {
                font-size: 2rem;
            }

            .news-meta {
                gap: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .news-title {
                font-size: 1.75rem;
            }

            .news-excerpt {
                font-size: 1rem;
            }

            .news-meta {
                flex-direction: column;
                gap: 1rem;
            }

            .news-featured-image {
                max-height: 300px;
            }

            .comments-body {
                padding: 1.5rem;
            }

            .comment-item {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .news-title {
                font-size: 1.5rem;
            }

            .news-category-badge {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }

            .news-meta-item {
                font-size: 0.9rem;
            }

            .news-content {
                font-size: 1rem;
            }

            .comments-header {
                padding: 1rem;
            }

            .comments-header h4 {
                font-size: 1.1rem;
            }

            .comment-form {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
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
            <!-- News Header Card -->
            <div class="news-header-card">
                <div style="padding: 2rem;">
                    <a href="schoolfeed.php" class="back-to-feed-btn">
                        <i class="fas fa-arrow-left"></i>
                        Back to School Feed
                    </a>
                    <span class="news-category-badge">
                        <?php echo htmlspecialchars($news_item['category']); ?>
                    </span>
                    <h1 class="news-title"><?php echo htmlspecialchars($news_item['title']); ?></h1>
                    <p class="news-excerpt"><?php echo htmlspecialchars($news_item['excerpt']); ?></p>

                    <div class="news-meta">
                        <span class="news-meta-item">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('F j, Y', strtotime($news_item['published_date'])); ?>
                        </span>
                        <span class="news-meta-item">
                            <i class="fas fa-eye"></i>
                            <?php echo intval($news_item['view_count']); ?> views
                        </span>
                        <?php if ($news_item['allow_comments']): ?>
                            <span class="news-meta-item">
                                <i class="fas fa-comment"></i>
                                <?php echo count($comments); ?> comments
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- News Content Card -->
            <div class="news-content-card">
                <div style="padding: 2rem;">
                    <?php if ($news_item['featured_image']): ?>
                        <img src="../<?php echo htmlspecialchars($news_item['featured_image']); ?>"
                             alt="<?php echo htmlspecialchars($news_item['title']); ?>"
                             class="news-featured-image">
                    <?php endif; ?>

                    <div class="news-content">
                        <?php echo nl2br(htmlspecialchars($news_item['content'])); ?>
                    </div>

                    <?php if (!empty($news_item['tags'])): ?>
                        <div class="news-tags">
                            <strong>Tags:</strong>
                            <?php
                            $tags = explode(',', $news_item['tags']);
                            foreach ($tags as $tag):
                                $trimmed_tag = trim($tag);
                                if (!empty($trimmed_tag)):
                            ?>
                                <span class="tag-item">#<?php echo htmlspecialchars($trimmed_tag); ?></span>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($news_item['allow_comments']): ?>
                <!-- Comments Section -->
                <div class="comments-section">
                    <div class="comments-header">
                        <i class="fas fa-comments"></i>
                        <h4>Comments (<?php echo count($comments); ?>)</h4>
                    </div>
                    <div class="comments-body">
                        <form method="POST" class="comment-form">
                            <input type="hidden" name="action" value="comment">
                            <div class="comment-form-group">
                                <label for="comment">Share your thoughts:</label>
                                <textarea name="comment" id="comment" rows="4" class="comment-textarea"
                                          placeholder="Write your comment here..." required></textarea>
                            </div>
                            <button type="submit" class="btn-submit-comment">
                                <i class="fas fa-paper-plane"></i>
                                Post Comment
                            </button>
                        </form>

                        <?php if (empty($comments)): ?>
                            <div class="no-comments">
                                <i class="fas fa-comment"></i>
                                <h5>No comments yet</h5>
                                <p>Be the first to share your thoughts!</p>
                            </div>
                        <?php else: ?>
                            <div class="comments-list">
                                <?php foreach ($comments as $comment): ?>
                                    <div class="comment-item">
                                        <div class="comment-header">
                                            <div class="comment-avatar">
                                                <?php echo strtoupper(substr($comment['name'], 0, 1)); ?>
                                            </div>
                                            <div class="comment-info">
                                                <div class="comment-author"><?php echo htmlspecialchars($comment['name']); ?></div>
                                                <div class="comment-date">
                                                    <?php echo date('M j, Y \a\t g:i A', strtotime($comment['created_at'])); ?>
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
                </div>
            <?php else: ?>
                <!-- Comments Disabled Section -->
                <div class="comments-section">
                    <div class="comments-disabled">
                        <i class="fas fa-comment-slash"></i>
                        <h5>Comments are disabled</h5>
                        <p>Comments are not allowed for this news article.</p>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
