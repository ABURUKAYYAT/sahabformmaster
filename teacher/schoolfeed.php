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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-dark: #3730a3;
            --secondary-color: #06b6d4;
            --accent-color: #f59e0b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #3b82f6;
            --light-color: #f9fafb;
            --dark-color: #1f2937;
            --gray-color: #6b7280;
            --card-bg: #ffffff;
            --shadow: 0 20px 40px rgba(79, 70, 229, 0.15);
            --shadow-hover: 0 30px 60px rgba(79, 70, 229, 0.25);
            --radius: 20px;
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-accent: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-success: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --gradient-warning: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --gradient-info: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #ffffff;
            min-height: 100vh;
            color: var(--dark-color);
            overflow-x: hidden;
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
            color: var(--dark-color);
            background: var(--accent-color);
            padding: 0.2rem 0.8rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: 600;
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
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .welcome-card {
            background: var(--gradient-primary);
            color: white;
            padding: 2.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
            animation: slideInUp 0.8s ease-out;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shimmer 2s infinite;
        }

        .welcome-content h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .welcome-content p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0.95) 100%);
            backdrop-filter: blur(10px);
            padding: 2rem 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 107, 53, 0.1);
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
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 107, 53, 0.05), transparent);
            transition: left 0.5s ease;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-hover);
        }

        .stat-card:hover::after {
            left: 100%;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray-color);
            font-size: 0.95rem;
        }

        .news-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .news-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.98) 100%);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(255, 107, 53, 0.08);
            position: relative;
        }

        .news-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-accent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .news-card:hover::before {
            opacity: 1;
        }

        .news-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: var(--shadow-hover);
        }

        .news-image-container {
            height: 200px;
            overflow: hidden;
            position: relative;
        }

        .news-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .news-card:hover .news-image {
            transform: scale(1.05);
        }

        .news-category {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: var(--gradient-accent);
            color: var(--dark-color);
            padding: 0.4rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            z-index: 2;
            box-shadow: 0 4px 12px rgba(255, 253, 63, 0.3);
        }

        .news-card-content {
            padding: 1.5rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .news-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark-color);
            line-height: 1.4;
        }

        .news-excerpt {
            color: var(--gray-color);
            line-height: 1.6;
            margin-bottom: 1.5rem;
            flex-grow: 1;
        }

        .news-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            color: var(--gray-color);
            margin-bottom: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .read-more-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            width: 100%;
            text-align: center;
        }

        .read-more-btn:hover {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            transform: translateY(-2px);
        }

        .no-news {
            grid-column: 1 / -1;
            text-align: center;
            padding: 4rem 2rem;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0.95) 100%);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 107, 53, 0.1);
            position: relative;
            overflow: hidden;
        }

        .no-news::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-secondary);
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .no-news h3 {
            font-size: 1.8rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .no-news p {
            color: var(--gray-color);
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }

        .footer {
            background: linear-gradient(135deg, var(--dark-color) 0%, #1a202c 100%);
            color: white;
            padding: 4rem 2rem 2rem;
            position: relative;
            margin-top: 5rem;
            overflow: hidden;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .footer::after {
            content: '';
            position: absolute;
            top: -50px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(255, 107, 53, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 3rem;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }

        .footer-section h4 {
            color: var(--accent-color);
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
            font-weight: 700;
            position: relative;
        }

        .footer-section h4::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }

        .footer-section p {
            color: #a0aec0;
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }

        .footer-links {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .footer-links a {
            color: #cbd5e0;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
            border-radius: 8px;
            padding-left: 0.5rem;
        }

        .footer-links a:hover {
            color: var(--accent-color);
            background: rgba(255, 253, 63, 0.1);
            transform: translateX(5px);
        }

        .footer-links a i {
            font-size: 0.9rem;
            width: 16px;
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            z-index: 1;
        }

        .footer-bottom p {
            color: #718096;
            font-size: 0.9rem;
            margin: 0;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .social-link {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .social-link:hover {
            background: var(--gradient-primary);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(255, 107, 53, 0.3);
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
            
            .welcome-card {
                flex-direction: column;
                text-align: center;
                gap: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .news-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes shimmer {
            0% {
                left: -100%;
            }
            100% {
                left: 100%;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        .stats-grid {
            animation: fadeInUp 0.8s ease-out 0.2s both;
        }

        .news-grid {
            animation: fadeInUp 0.8s ease-out 0.4s both;
        }

        .stat-card:hover .stat-number {
            animation: pulse 0.6s ease-in-out;
        }

        .news-card {
            animation: fadeInUp 0.6s ease-out both;
            animation-delay: calc(var(--index, 0) * 0.1s);
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .welcome-content h1 {
                font-size: 2rem;
            }

            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
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
                
             
            </div>
        </div>
    </header> -->
       <div class="nav-links">
                    <a href="index.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <!-- <a href="logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a> -->
        </div>

    <main class="main-container">
        <div class="welcome-card">
            <div class="welcome-content">
                <h1>School News Feed</h1>
                <p>Stay updated with the latest announcements and events</p>
                <p><i class="fas fa-chalkboard-teacher"></i> Teacher Access: View all posts and click to read details</p>
            </div>
            <div class="welcome-stats">
                <div class="stat-card" style="background: rgba(255,255,255,0.2); border: none; color: white;">
                    <div class="stat-number"><?php echo count($news_items); ?></div>
                    <div class="stat-label">Active Posts</div>
                </div>
            </div>
        </div>

        <div class="stats-grid">
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
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_filter($news_items, fn($n) => $n['priority'] === 'high')); ?></div>
                <div class="stat-label">High Priority</div>
            </div>
        </div>

        <div class="news-grid">
            <?php if (empty($news_items)): ?>
                <div class="no-news">
                    <h3>No news available yet</h3>
                    <p>Check back later for updates!</p>
                </div>
            <?php else: ?>
                <?php foreach ($news_items as $index => $item):
                    // Get first 200 characters of content
                    $content_preview = strip_tags($item['content']);
                    if (strlen($content_preview) > 200) {
                        $content_preview = substr($content_preview, 0, 200) . '...';
                    }
                ?>
                    <div class="news-card" style="--index: <?php echo $index; ?>">
                        <div class="news-image-container">
                            <?php if ($item['featured_image']): ?>
                                <img src="../<?php echo htmlspecialchars($item['featured_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['title']); ?>" 
                                     class="news-image"
                                     onerror="this.src='https://via.placeholder.com/400x200/4CAF50/ffffff?text=News+Image'">
                            <?php else: ?>
                                <img src="https://via.placeholder.com/400x200/4CAF50/ffffff?text=School+News" 
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
    </main>
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
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Add interactive hover effects
        document.addEventListener('DOMContentLoaded', function() {
            // Add ripple effect to buttons
            const buttons = document.querySelectorAll('.read-more-btn, .nav-link');
            buttons.forEach(button => {
                button.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    ripple.style.position = 'absolute';
                    ripple.style.borderRadius = '50%';
                    ripple.style.background = 'rgba(255, 255, 255, 0.3)';
                    ripple.style.transform = 'scale(0)';
                    ripple.style.animation = 'ripple 0.6s linear';
                    ripple.style.left = (e.offsetX - 10) + 'px';
                    ripple.style.top = (e.offsetY - 10) + 'px';
                    ripple.style.width = '20px';
                    ripple.style.height = '20px';

                    this.appendChild(ripple);

                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });

            // Add floating animation to welcome card
            const welcomeCard = document.querySelector('.welcome-card');
            let floatingDirection = 1;
            setInterval(() => {
                welcomeCard.style.transform = `translateY(${floatingDirection * 2}px)`;
                floatingDirection *= -1;
            }, 3000);
        });

        // Auto-refresh every 45 seconds
        setInterval(() => {
            location.reload();
        }, 45000);
    </script>

    <style>
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        .read-more-btn, .nav-link {
            position: relative;
            overflow: hidden;
        }

        .welcome-card {
            transition: transform 3s ease-in-out;
        }
    </style>
</body>
</html>
