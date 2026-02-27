<?php
// student/dashboard.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if student is logged in and get school_id
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];
$current_school_id = get_current_school_id();

// Get student details and stats
$query = "SELECT * FROM students WHERE id = ? AND school_id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$student_id, $current_school_id]);
$student = $stmt->fetch();

// If student doesn't belong to user's school, logout
if (!$student) {
    session_destroy();
    header("Location: index.php?error=access_denied");
    exit;
}

// Get attendance stats
$query = "
    SELECT
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
        ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as attendance_rate
    FROM attendance
    WHERE student_id = ? AND school_id = ?
";
$attendance_stmt = $pdo->prepare($query);
$attendance_stmt->execute([$student_id, $current_school_id]);
$attendance = $attendance_stmt->fetch();

// Get results count
$query = "SELECT COUNT(*) as results_count FROM results WHERE student_id = ? AND school_id = ?";
$results_stmt = $pdo->prepare($query);
$results_stmt->execute([$student_id, $current_school_id]);
$results_count = $results_stmt->fetch()['results_count'];

// Get pending activities
$query = "
    SELECT COUNT(*) as pending_activities
    FROM student_submissions ss
    JOIN class_activities ca ON ss.activity_id = ca.id
    WHERE ss.student_id = ? AND ss.status = 'pending' AND ca.school_id = ?
";
$activities_stmt = $pdo->prepare($query);
$activities_stmt->execute([$student_id, $current_school_id]);
$pending_activities = $activities_stmt->fetch()['pending_activities'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/education-theme-main.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

    <!-- Mobile Navigation Component -->
    <?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">Student Portal</p>
                    </div>
                </div>
            </div>

            <!-- Student Info and Logout -->
            <div class="header-right">
                <div class="student-info">
                    <p class="student-label">Student</p>
                    <span class="student-name"><?php echo htmlspecialchars((string) ($student_name ?? '')); ?></span>
                    <span class="admission-number"><?php echo htmlspecialchars((string) ($admission_number ?? '')); ?></span>
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
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link active">
                            <i class="fas fa-tachometer-alt nav-icon"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="myresults.php" class="nav-link">
                            <i class="fas fa-chart-line nav-icon"></i>
                            <span class="nav-text">My Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="student_class_activities.php" class="nav-link">
                            <i class="fas fa-tasks nav-icon"></i>
                            <span class="nav-text">Class Activities</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="mysubjects.php" class="nav-link">
                            <i class="fas fa-book-open nav-icon"></i>
                            <span class="nav-text">My Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="my-evaluations.php" class="nav-link">
                            <i class="fas fa-star nav-icon"></i>
                            <span class="nav-text">My Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="attendance.php" class="nav-link">
                            <i class="fas fa-calendar-check nav-icon"></i>
                            <span class="nav-text">Attendance</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payment.php" class="nav-link">
                            <i class="fas fa-money-bill-wave nav-icon"></i>
                            <span class="nav-text">School Fees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="schoolfeed.php" class="nav-link">
                            <i class="fas fa-newspaper nav-icon"></i>
                            <span class="nav-text">School Feeds</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link">
                            <i class="fas fa-book nav-icon"></i>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="cbt_tests.php" class="nav-link">
                            <i class="fas fa-laptop-code nav-icon"></i>
                            <span class="nav-text">CBT Tests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="photo_album.php" class="nav-link">
                            <i class="fas fa-images nav-icon"></i>
                            <span class="nav-text">Photo Album</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Welcome back, <?php echo htmlspecialchars((string) ($student_name ?? '')); ?>! 🎓</h2>
                    <p>Here's your academic overview for today</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $attendance['attendance_rate'] ?? 0; ?>%</span>
                        <span class="quick-stat-label">Attendance</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $results_count; ?></span>
                        <span class="quick-stat-label">Results</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $pending_activities; ?></span>
                        <span class="quick-stat-label">Pending</span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Academic Results</h3>
                        <p class="card-value"><?php echo $results_count; ?> Terms</p>
                        <div class="card-footer">
                            <span class="card-badge">Available</span>
                            <a href="myresults.php" class="card-link">View Results →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-calendar-check"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Attendance Rate</h3>
                        <p class="card-value"><?php echo $attendance['attendance_rate'] ?? 0; ?>%</p>
                        <div class="card-footer">
                            <span class="card-badge"><?php echo $attendance['present_days'] ?? 0; ?>/<?php echo $attendance['total_days'] ?? 0; ?> days</span>
                            <a href="attendance.php" class="card-link">View Details →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-tasks"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Class Activities</h3>
                        <p class="card-value"><?php echo $pending_activities; ?> Pending</p>
                        <div class="card-footer">
                            <span class="card-badge">Due Soon</span>
                            <a href="student_class_activities.php" class="card-link">Complete Now →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-book-open"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>My Subjects</h3>
                        <p class="card-value">Active</p>
                        <div class="card-footer">
                            <span class="card-badge">Enrolled</span>
                            <a href="mysubjects.php" class="card-link">View Subjects →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-5">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-star"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Evaluations</h3>
                        <p class="card-value">Latest</p>
                        <div class="card-footer">
                            <span class="card-badge">View</span>
                            <a href="my-evaluations.php" class="card-link">See Reports →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-6">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-money-bill-wave"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>School Fees</h3>
                        <p class="card-value">Payment</p>
                        <div class="card-footer">
                            <span class="card-badge">Status</span>
                            <a href="payment.php" class="card-link">Pay Fees →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-7">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-mobile-alt"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>VTU Services</h3>
                        <p class="card-value">Available</p>
                        <div class="card-footer">
                            <span class="card-badge">Buy Now</span>
                            <a href="https://www.sahabdata.com.ng" target="_blank" class="card-link">Shop VTU →</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Section -->
            <div class="quick-actions-section">
                <div class="section-header">
                    <h3>⚡ Quick Actions</h3>
                    <span class="section-badge">Most Used</span>
                </div>
                <div class="quick-actions-grid">
                    <a href="myresults.php" class="quick-action-card academic">
                        <i class="fas fa-chart-bar"></i>
                        <span>Check Results</span>
                    </a>
                    <a href="student_class_activities.php" class="quick-action-card academic">
                        <i class="fas fa-tasks"></i>
                        <span>Submit Assignment</span>
                    </a>
                    <a href="attendance.php" class="quick-action-card attendance">
                        <i class="fas fa-calendar-check"></i>
                        <span>View Attendance</span>
                    </a>
                    <a href="payment.php" class="quick-action-card admin">
                        <i class="fas fa-credit-card"></i>
                        <span>Pay School Fees</span>
                    </a>
                    <a href="schoolfeed.php" class="quick-action-card general">
                        <i class="fas fa-newspaper"></i>
                        <span>School News</span>
                    </a>
                    <a href="school_diary.php" class="quick-action-card general">
                        <i class="fas fa-book"></i>
                        <span>School Events</span>
                    </a>
                    <a href="photo_album.php" class="quick-action-card general">
                        <i class="fas fa-images"></i>
                        <span>Class Photos</span>
                    </a>
                    <a href="https://www.sahabdata.com.ng" target="_blank" class="quick-action-card general">
                        <i class="fas fa-mobile-alt"></i>
                        <span>Buy VTU Services</span>
                    </a>
                </div>
            </div>

            <!-- Recent Activity Section -->
            <div class="activity-section">
                <div class="section-header">
                    <h3>📋 Recent Activity</h3>
                    <a href="#" class="view-all-link">View All</a>
                </div>
                <div class="activity-list">
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-success">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">Results published for <strong>Mathematics Term 1</strong></span>
                            <span class="activity-date">Today, 9:00 AM</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-info">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">New assignment posted in <strong>English Studies</strong></span>
                            <span class="activity-date">Yesterday, 2:30 PM</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-warning">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">Attendance marked for <strong>Science Class</strong></span>
                            <span class="activity-date">2 days ago</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-primary">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">New evaluation report <strong>available</strong></span>
                            <span class="activity-date">1 week ago</span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    

    <script>

        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Add active class on scroll for header
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Animate cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe dashboard cards
        document.querySelectorAll('.card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });

        // Observe quick action cards
        document.querySelectorAll('.quick-action-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });

        // Add hover effects for cards
        document.querySelectorAll('.card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });

            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });

        // Quick action cards hover effect
        document.querySelectorAll('.quick-action-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.05)';
                this.style.boxShadow = 'var(--shadow-xl)';
            });

            card.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
                this.style.boxShadow = 'var(--shadow-sm)';
            });
        });

        // Auto-refresh dashboard data every 5 minutes
        setInterval(() => {
            // You can add AJAX calls here to refresh dynamic data
            console.log('Dashboard data refresh check...');
        }, 300000);

        // Add loading animation for quick actions
        document.querySelectorAll('.quick-action-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Add loading state
                this.style.opacity = '0.7';
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Loading...</span>';

                // Reset after a short delay (simulating navigation)
                setTimeout(() => {
                    this.style.opacity = '1';
                    this.innerHTML = this.dataset.originalHtml || this.innerHTML;
                }, 1000);
            });
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
