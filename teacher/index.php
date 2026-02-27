<?php
// teacher/index.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if teacher is logged in and get school_id
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$teacher_name = $_SESSION['full_name'];
$current_school_id = require_school_auth();

// Get dynamic stats for the teacher
$teacher_id = $_SESSION['user_id'];

// Get teacher's class count
$query = "SELECT COUNT(*) as class_count FROM class_teachers ct JOIN classes c ON ct.class_id = c.id WHERE ct.teacher_id = ? AND c.school_id = ?";
$class_stmt = $pdo->prepare($query);
$class_stmt->execute([$teacher_id, $current_school_id]);
$class_count = $class_stmt->fetch()['class_count'];

// Get student's count for teacher's classes
$query = "
    SELECT COUNT(DISTINCT s.id) as student_count
    FROM students s
    JOIN class_teachers ct ON s.class_id = ct.class_id
    JOIN classes c ON s.class_id = c.id
    WHERE ct.teacher_id = ? AND s.school_id = ? AND c.school_id = ?
";
$student_stmt = $pdo->prepare($query);
$student_stmt->execute([$teacher_id, $current_school_id, $current_school_id]);
$student_count = $student_stmt->fetch()['student_count'];

// Get lesson plans count
$query = "SELECT COUNT(*) as lesson_count FROM lesson_plans WHERE teacher_id = ? AND school_id = ?";
$lesson_stmt = $pdo->prepare($query);
$lesson_stmt->execute([$teacher_id, $current_school_id]);
$lesson_count = $lesson_stmt->fetch()['lesson_count'];

// Get subjects count
$query = "
    SELECT COUNT(DISTINCT sa.subject_id) as subject_count
    FROM subject_assignments sa
    JOIN class_teachers ct ON sa.class_id = ct.class_id
    JOIN classes c ON sa.class_id = c.id
    JOIN subjects sub ON sa.subject_id = sub.id
    WHERE ct.teacher_id = ? AND c.school_id = ? AND sub.school_id = ?
";
$subject_stmt = $pdo->prepare($query);
$subject_stmt->execute([$teacher_id, $current_school_id, $current_school_id]);
$subject_count = $subject_stmt->fetch()['subject_count'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/education-theme-main.css">
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
                        <p class="school-tagline">Teacher Portal</p>
                    </div>
                </div>
            </div>

            <!-- Teacher Info and Logout -->
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($teacher_name); ?></span>
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
                        <a href="index.php" class="nav-link active">
                            <i class="fas fa-tachometer-alt nav-icon"></i>
                            <span class="nav-text">Dashboard</span>
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
                        <a href="students.php" class="nav-link">
                            <i class="fas fa-users nav-icon"></i>
                            <span class="nav-text">Students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="results.php" class="nav-link">
                            <i class="fas fa-chart-line nav-icon"></i>
                            <span class="nav-text">Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <i class="fas fa-book-open nav-icon"></i>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="questions.php" class="nav-link">
                            <i class="fas fa-question-circle nav-icon"></i>
                            <span class="nav-text">Questions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plan.php" class="nav-link">
                            <i class="fas fa-clipboard-list nav-icon"></i>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="curricullum.php" class="nav-link">
                            <i class="fas fa-graduation-cap nav-icon"></i>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="teacher_class_activities.php" class="nav-link">
                            <i class="fas fa-tasks nav-icon"></i>
                            <span class="nav-text">Class Activities</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="cbt_tests.php" class="nav-link">
                            <i class="fas fa-laptop-code nav-icon"></i>
                            <span class="nav-text">CBT Tests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="student-evaluation.php" class="nav-link">
                            <i class="fas fa-star nav-icon"></i>
                            <span class="nav-text">Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="class_attendance.php" class="nav-link">
                            <i class="fas fa-calendar-check nav-icon"></i>
                            <span class="nav-text">Attendance</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="timebook.php" class="nav-link">
                            <i class="fas fa-clock nav-icon"></i>
                            <span class="nav-text">Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <i class="fas fa-key nav-icon"></i>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments.php" class="nav-link">
                            <i class="fas fa-money-bill-wave nav-icon"></i>
                            <span class="nav-text">Payments</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Welcome back, <?php echo htmlspecialchars($teacher_name); ?>! 👋</h2>
                    <p>Here's what's happening in your classes today</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $student_count; ?></span>
                        <span class="quick-stat-label">Students</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $class_count; ?></span>
                        <span class="quick-stat-label">Classes</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $subject_count; ?></span>
                        <span class="quick-stat-label">Subjects</span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>My Students</h3>
                        <p class="card-value"><?php echo $student_count; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Active</span>
                            <a href="students.php" class="card-link">Manage Students →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>My Classes</h3>
                        <p class="card-value"><?php echo $class_count; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Assigned</span>
                            <a href="students.php" class="card-link">View Classes →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Lesson Plans</h3>
                        <p class="card-value"><?php echo $lesson_count; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Created</span>
                            <a href="lesson-plan.php" class="card-link">Create New →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-book-open"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Subjects</h3>
                        <p class="card-value"><?php echo $subject_count; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Teaching</span>
                            <a href="subjects.php" class="card-link">View Subjects →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-5">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Results</h3>
                        <p class="card-value">Pending</p>
                        <div class="card-footer">
                            <span class="card-badge">Update</span>
                            <a href="results.php" class="card-link">Enter Results →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-6">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-question-circle"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Question Bank</h3>
                        <p class="card-value">Active</p>
                        <div class="card-footer">
                            <span class="card-badge">Manage</span>
                            <a href="questions.php" class="card-link">View Questions →</a>
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
                    <span class="section-badge">Frequently Used</span>
                </div>
                <div class="quick-actions-grid">
                    <a href="lesson-plan.php" class="quick-action-card academic">
                        <i class="fas fa-plus-circle"></i>
                        <span>Create Lesson Plan</span>
                    </a>
                    <a href="class_attendance.php" class="quick-action-card attendance">
                        <i class="fas fa-calendar-check"></i>
                        <span>Mark Attendance</span>
                    </a>
                    <a href="results.php" class="quick-action-card academic">
                        <i class="fas fa-chart-bar"></i>
                        <span>Enter Results</span>
                    </a>
                    <a href="teacher_class_activities.php" class="quick-action-card academic">
                        <i class="fas fa-tasks"></i>
                        <span>Class Activities</span>
                    </a>
                    <a href="permissions.php" class="quick-action-card admin">
                        <i class="fas fa-key"></i>
                        <span>Request Permission</span>
                    </a>
                    <a href="timebook.php" class="quick-action-card attendance">
                        <i class="fas fa-clock"></i>
                        <span>Time Book</span>
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
                            <span class="activity-text">Lesson plan approved for <strong>Mathematics</strong></span>
                            <span class="activity-date">Today, 10:30 AM</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-info">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">Results submitted for <strong>Biology Class</strong></span>
                            <span class="activity-date">Yesterday, 3:45 PM</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-warning">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">Attendance marked for <strong>JSS 1A</strong></span>
                            <span class="activity-date">2 days ago</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon activity-icon-primary">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <div class="activity-content">
                            <span class="activity-text">New questions added to <strong>Mathematics</strong> bank</span>
                            <span class="activity-date">3 days ago</span>
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
            });

            card.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });

        // Auto-refresh dashboard data every 5 minutes
        setInterval(() => {
            // You can add AJAX calls here to refresh dynamic data
            console.log('Dashboard data refresh check...');
        }, 300000);
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
