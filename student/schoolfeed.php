<?php
// filepath: c:\xampp\htdocs\sahabformmaster\student\schoolfeed.php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}
 
$user_id = $_SESSION['student_id'];
$user_name = $_SESSION['student_name'] ?? 'Student';
$student_class = $_SESSION['class'] ?? '';
$student_section = $_SESSION['section'] ?? '';

// Fetch news relevant to student (All + Students + their specific class)
$query = "SELECT * FROM school_news 
          WHERE status = 'published' 
          AND (target_audience = 'All' 
               OR target_audience = 'Students' 
               OR target_audience LIKE :class) 
          ORDER BY published_date DESC 
          LIMIT 20";

$stmt = $pdo->prepare($query);
$stmt->execute(['class' => '%' . $student_class . '%']);
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Feed | SahabFormMaster</title>
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

        .btn-logout {
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

        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .main-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .welcome-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 3rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            opacity: 0.3;
            animation: float 20s linear infinite;
        }

        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(30px, 30px) rotate(360deg); }
        }

        .welcome-card h1 {
            font-size: 2.8rem;
            margin-bottom: 0.8rem;
            position: relative;
        }

        .welcome-card p {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .welcome-stats {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-top: 1.5rem;
            position: relative;
        }

        .welcome-stat {
            background: rgba(255, 255, 255, 0.2);
            padding: 1rem 2rem;
            border-radius: var(--radius);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .welcome-stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .welcome-stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .news-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 2.5rem;
            margin-bottom: 3rem;
        }

        .news-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            flex-direction: column;
            height: 100%;
            position: relative;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .news-card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: var(--hover-shadow);
        }

        .news-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
            z-index: 2;
        }

        .news-image-container {
            height: 220px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .news-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s ease;
        }

        .news-card:hover .news-image {
            transform: scale(1.1);
        }

        .image-placeholder {
            height: 100%;
            width: 100%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5rem;
            color: white;
        }

        .news-category-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            color: var(--primary-color);
            padding: 0.5rem 1.2rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            z-index: 1;
        }

        .news-content {
            padding: 1.8rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .news-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark-color);
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .news-excerpt {
            color: var(--gray-color);
            margin-bottom: 1.5rem;
            line-height: 1.7;
            flex: 1;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .news-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: auto;
            padding-top: 1.2rem;
            border-top: 1px solid #eee;
            font-size: 0.9rem;
            color: var(--gray-color);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .read-more-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 0.8rem 1.8rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
            align-self: flex-start;
        }

        .read-more-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }

        .no-news {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            color: var(--gray-color);
            font-size: 1.2rem;
            max-width: 600px;
            margin: 0 auto;
        }

        .no-news i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: var(--primary-color);
            opacity: 0.5;
        }

        .no-news h3 {
            font-size: 1.8rem;
            margin-bottom: 1rem;
            color: var(--dark-color);
        }

        .footer {
            background: linear-gradient(135deg, var(--dark-color), #343a40);
            color: white;
            padding: 3rem 2rem 2rem;
            margin-top: 4rem;
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
            color: var(--accent-color);
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
        }

        .footer-links {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .footer-links a {
            color: #adb5bd;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        .footer-copyright {
            font-size: 0.95rem;
            color: #adb5bd;
        }

        .footer-version {
            font-size: 0.9rem;
            color: #6c757d;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .news-grid {
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .news-grid {
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
                gap: 2rem;
            }
            
            .welcome-card h1 {
                font-size: 2.4rem;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 1.2rem;
                text-align: center;
            }
            
            .user-info {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .news-grid {
                grid-template-columns: 1fr;
                max-width: 500px;
                margin-left: auto;
                margin-right: auto;
            }
            
            .welcome-card {
                padding: 2rem 1.5rem;
            }
            
            .welcome-card h1 {
                font-size: 2rem;
            }
            
            .welcome-stats {
                flex-direction: column;
                gap: 1rem;
            }
            
            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .footer-links a {
                justify-content: center;
            }
            
            .footer-bottom {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .main-container {
                padding: 0 0.5rem;
            }
            
            .news-card {
                margin: 0;
            }
            
            .news-content {
                padding: 1.5rem;
            }
            
            .news-title {
                font-size: 1.3rem;
            }
            
            .read-more-btn {
                width: 100%;
                justify-content: center;
            }
            
            .header {
                padding: 1rem;
            }
        }

        /* Animation for new items */
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

        .news-card {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }

        .news-card:nth-child(1) { animation-delay: 0.1s; }
        .news-card:nth-child(2) { animation-delay: 0.2s; }
        .news-card:nth-child(3) { animation-delay: 0.3s; }
        .news-card:nth-child(4) { animation-delay: 0.4s; }
        .news-card:nth-child(5) { animation-delay: 0.5s; }
        .news-card:nth-child(6) { animation-delay: 0.6s; }
        .news-card:nth-child(7) { animation-delay: 0.7s; }
        .news-card:nth-child(8) { animation-delay: 0.8s; }
        .news-card:nth-child(9) { animation-delay: 0.9s; }
        .news-card:nth-child(10) { animation-delay: 1.0s; }
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
                <a href="../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header> -->
     <br />
      <div class="user-info">
                <!-- <div class="user-avatar">
                    <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($student_class); ?> Student</span>
                </div> -->
                <a href="dashboard.php" class="btn-logout">
                    Dashboard
                </a>
            </div
           
            <br />
            <br />

    <main class="main-container">
        <div class="welcome-card">
            <h1><i class="fas fa-newspaper"></i> School News Feed</h1>
            <p>Stay updated with the latest news and announcements from your school</p>
            <p><i class="fas fa-user-graduate"></i> Personalized feed for <?php echo htmlspecialchars($student_class); ?> students</p>
            
            <div class="welcome-stats">
                <div class="welcome-stat">
                    <div class="welcome-stat-number"><?php echo count($news_items); ?></div>
                    <div class="welcome-stat-label">News Articles</div>
                </div>
                <div class="welcome-stat">
                    <div class="welcome-stat-number">
                        <?php echo array_sum(array_column($news_items, 'view_count')); ?>
                    </div>
                    <div class="welcome-stat-label">Total Views</div>
                </div>
                <div class="welcome-stat">
                    <div class="welcome-stat-number">
                        <?php echo count(array_filter($news_items, fn($n) => $n['priority'] === 'high')); ?>
                    </div>
                    <div class="welcome-stat-label">High Priority</div>
                </div>
            </div>
        </div>

        <?php if (empty($news_items)): ?>
            <div class="no-news">
                <i class="fas fa-newspaper"></i>
                <h3>No news available for your class yet</h3>
                <p>Check back later for school announcements and updates!</p>
            </div>
        <?php else: ?>
            <div class="news-grid">
                <?php foreach ($news_items as $item): ?>
                    <div class="news-card">
                        <div class="news-image-container">
                            <?php if ($item['featured_image']): ?>
                                <img src="../<?php echo htmlspecialchars($item['featured_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['title']); ?>" 
                                     class="news-image">
                            <?php else: ?>
                                <div class="image-placeholder">
                                    <i class="fas fa-newspaper"></i>
                                </div>
                            <?php endif; ?>
                            <div class="news-category-badge">
                                <?php echo htmlspecialchars($item['category']); ?>
                            </div>
                        </div>
                        
                        <div class="news-content">
                            <h3 class="news-title"><?php echo htmlspecialchars($item['title']); ?></h3>
                            
                            <p class="news-excerpt">
                                <?php 
                                $content = strip_tags($item['content']);
                                $excerpt = strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content;
                                echo htmlspecialchars($excerpt);
                                ?>
                            </p>
                            
                            <div class="news-meta">
                                <div class="meta-item">
                                    <i class="far fa-calendar"></i>
                                    <?php echo date('M d, Y', strtotime($item['published_date'])); ?>
                                </div>
                                <div class="meta-item">
                                    <i class="far fa-eye"></i>
                                    <?php echo intval($item['view_count']); ?> views
                                </div>
                                <?php if ($item['allow_comments']): ?>
                                    <div class="meta-item">
                                        <i class="far fa-comment"></i>
                                        <?php echo intval($item['comment_count'] ?? 0); ?> comments
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <a href="newsdetails.php?id=<?php echo $item['id']; ?>" class="read-more-btn">
                                <i class="fas fa-book-open"></i> Read Full Story
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>SahabFormMaster</h4>
                    <p>Comprehensive school management system with integrated communication features for students, teachers, and parents.</p>
                </div>
                <!-- <div class="footer-section">
                    <h4>Quick Links</h4>
                    <div class="footer-links">
                        <a href="student-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                        <a href="schoolfeed.php" class="active"><i class="fas fa-newspaper"></i> School News</a>
                        <a href="student-results.php"><i class="fas fa-chart-line"></i> Results</a>
                        <a href="student-attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a>
                    </div>
                </div>
                <div class="footer-section">
                    <h4>Contact Support</h4>
                    <p><i class="fas fa-envelope"></i> student-support@sahabformmaster.com</p>
                    <p><i class="fas fa-phone"></i> +123 456 7890</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 8:00 AM - 4:00 PM</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
                <p class="footer-version">Student Portal v2.0 | News Feed System</p>
            </div> -->
        </div>
    </footer>

    <script>
        // Smooth scrolling animation
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Add hover effect to news cards
        document.querySelectorAll('.news-card').forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.zIndex = '10';
            });
            
            card.addEventListener('mouseleave', () => {
                card.style.zIndex = '1';
            });
        });

        // Auto-refresh news feed every 2 minutes
        setInterval(() => {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const newDoc = parser.parseFromString(html, 'text/html');
                    const newNewsGrid = newDoc.querySelector('.news-grid');
                    const currentNewsGrid = document.querySelector('.news-grid');
                    
                    if (newNewsGrid && currentNewsGrid && newNewsGrid.innerHTML !== currentNewsGrid.innerHTML) {
                        if (confirm('New updates available! Refresh page to see latest news?')) {
                            location.reload();
                        }
                    }
                })
                .catch(err => console.log('Auto-refresh check failed:', err));
        }, 120000); // 2 minutes
    </script>
</body>
</html>