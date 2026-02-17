<?php
session_start();
require_once '../config/db.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
require_once '../includes/functions.php';
$current_school_id = require_school_auth();

$teacher_id = $_SESSION['user_id'];
// School authentication and context
$current_school_id = require_school_auth();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Teacher';
$teacher_subject = $_SESSION['subject'] ?? '';

// Fetch school-specific published news (teachers can only see their school's news)
$query = "SELECT id, title, excerpt, content, category, target_audience,
                 view_count, published_date, featured_image, allow_comments,
                 priority, status
          FROM school_news
          WHERE status = 'published' AND school_id = ?
          ORDER BY published_date DESC
          LIMIT 20";

$stmt = $pdo->prepare($query);
$stmt->execute([$current_school_id]);
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total comments for stats
$total_comments = 0;
if (!empty($news_items)) {
    $news_ids = array_column($news_items, 'id');
    $placeholders = str_repeat('?,', count($news_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM school_news_comments WHERE news_id IN ($placeholders)");
    $stmt->execute($news_ids);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_comments = $result['count'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Blog | SahabFormMaster</title>
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

        .panel,
        .content-header {
            background: #ffffff;
            border: 1px solid #cfe1ff;
            border-radius: 12px;
            box-shadow: none;
        }

        .content-header {
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
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
            border-radius: 12px 12px 0 0;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .stat-card {
            background: #f5f9ff;
            border: 1px solid #dbe7fb;
            border-radius: 12px;
            padding: 1.25rem;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1d4ed8;
        }

        .stat-label {
            color: #4b5563;
            font-size: 0.9rem;
        }

        .news-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .news-card {
            border: 1px solid #dbe7fb;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            display: flex;
            flex-direction: column;
        }

        .news-image-container {
            height: 180px;
            overflow: hidden;
            background: #eaf2ff;
            position: relative;
        }

        .news-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .news-category {
            position: absolute;
            top: 0.75rem;
            left: 0.75rem;
            background: #1d4ed8;
            color: #fff;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .news-card-content {
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            flex: 1;
        }

        .news-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .news-excerpt {
            color: #4b5563;
            font-size: 0.95rem;
        }

        .news-meta {
            display: flex;
            gap: 1rem;
            color: #64748b;
            font-size: 0.85rem;
            border-top: 1px solid #eef2f7;
            padding-top: 0.75rem;
        }

        .read-more-btn {
            margin-top: auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: #1d4ed8;
            color: #fff;
            padding: 0.7rem 1rem;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
        }

        .no-news {
            text-align: center;
            padding: 3rem;
            color: #667085;
            border: 1px dashed #cfe1ff;
            border-radius: 12px;
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 1rem;
            }

            .news-grid {
                grid-template-columns: 1fr;
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
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">School News Feed</p>
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
                    <div class="welcome-section">
                        <h2>School News Feed</h2>
                        <p>Stay updated with the latest announcements and events.</p>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-chart-bar"></i> Quick Stats</h3>
                    </div>
                    <div class="panel-body">
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-number"><?php echo count($news_items); ?></div>
                                <div class="stat-label">Active Posts</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo array_sum(array_column($news_items, 'view_count')); ?></div>
                                <div class="stat-label">Total Views</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo count(array_filter($news_items, fn($n) => $n['allow_comments'])); ?></div>
                                <div class="stat-label">Posts with Comments</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $total_comments; ?></div>
                                <div class="stat-label">Total Comments</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-newspaper"></i> Latest Posts</h3>
                    </div>
                    <div class="panel-body">
                        <div class="news-grid">
                            <?php if (empty($news_items)): ?>
                                <div class="no-news">
                                    <h3>No news available yet</h3>
                                    <p>Check back later for updates!</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($news_items as $index => $item):
                                    $content_preview = strip_tags($item['content']);
                                    if (strlen($content_preview) > 200) {
                                        $content_preview = substr($content_preview, 0, 200) . '...';
                                    }
                                ?>
                                    <div class="news-card">
                                        <div class="news-image-container">
                                            <?php if ($item['featured_image']): ?>
                                                <img src="../<?php echo htmlspecialchars($item['featured_image']); ?>"
                                                     alt="<?php echo htmlspecialchars($item['title']); ?>"
                                                     class="news-image"
                                                     onerror="this.src='https://via.placeholder.com/400x200/1d4ed8/ffffff?text=News+Image'">
                                            <?php else: ?>
                                                <img src="https://via.placeholder.com/400x200/1d4ed8/ffffff?text=School+News"
                                                     alt="News Image"
                                                     class="news-image">
                                            <?php endif; ?>
                                            <span class="news-category"><?php echo htmlspecialchars($item['category']); ?></span>
                                        </div>

                                        <div class="news-card-content">
                                            <h3 class="news-title"><?php echo htmlspecialchars($item['title']); ?></h3>

                                            <p class="news-excerpt">
                                                <?php if (!empty($item['excerpt'])): ?>
                                                    <?php echo htmlspecialchars($item['excerpt']); ?>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($content_preview); ?>
                                                <?php endif; ?>
                                            </p>

                                            <div class="news-meta">
                                                <div class="meta-item">
                                                    <i class="far fa-calendar"></i>
                                                    <?php echo date('M d, Y', strtotime($item['published_date'])); ?>
                                                </div>
                                                <div class="meta-item">
                                                    <i class="far fa-eye"></i>
                                                    <?php echo intval($item['view_count']); ?>
                                                </div>
                                                <?php if ($item['allow_comments']): ?>
                                                    <div class="meta-item">
                                                        <i class="far fa-comments"></i>
                                                        Comments
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <a href="newsdetails.php?id=<?php echo $item['id']; ?>" class="read-more-btn">
                                                <i class="fas fa-book-open"></i> Read Full Story
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
<!-- 
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-section">
                <h4>SahabFormMaster</h4>
                <p>Empowering educational excellence through comprehensive school management solutions and innovative communication tools.</p>
                <div class="social-links">
                    <a href="#" class="social-link" aria-label="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="#" class="social-link" aria-label="Twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="#" class="social-link" aria-label="LinkedIn">
                        <i class="fab fa-linkedin-in"></i>
                    </a>
                    <a href="#" class="social-link" aria-label="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>
                </div>
            </div>

            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php">
                        <i class="fas fa-tachometer-alt"></i>
                        Dashboard
                    </a>
                    <a href="schoolfeed.php">
                        <i class="fas fa-newspaper"></i>
                        News Feed
                    </a>
                    <a href="school_diary.php">
                        <i class="fas fa-book"></i>
                        School Diary
                    </a>
                    <a href="curricullum.php">
                        <i class="fas fa-graduation-cap"></i>
                        Curriculum
                    </a>
                </div>
            </div>

            <div class="footer-section">
                <h4>Support</h4>
                <div class="footer-links">
                    <a href="#">
                        <i class="fas fa-question-circle"></i>
                        Help Center
                    </a>
                    <a href="#">
                        <i class="fas fa-envelope"></i>
                        Contact Us
                    </a>
                    <a href="#">
                        <i class="fas fa-shield-alt"></i>
                        Privacy Policy
                    </a>
                    <a href="#">
                        <i class="fas fa-file-contract"></i>
                        Terms of Service
                    </a>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; 2025 SahabFormMaster. All rights reserved. | Designed with <i class="fas fa-heart" style="color: var(--primary-color);"></i> for education</p>
        </div>
    </footer> -->

    <script>
        // Auto-refresh every 45 seconds
        setInterval(() => {
            location.reload();
        }, 45000);
    </script>
</body>
</html>
