<?php
// teacher/index.php
session_start();
require_once '../config/db.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$teacher_name = $_SESSION['full_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | SahabFormMaster</title>
    <!-- <link rel="stylesheet" href="../assets/css/styles.css"> -->
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name (Left) -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <h1 class="school-name">SahabFormMaster</h1>
                </div>
            </div>

            <!-- Teacher Info and Logout (Right) -->
            <div class="header-right">
                <div class="teacher-info">
                    <span class="teacher-name"><?php echo htmlspecialchars($teacher_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
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
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plan.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plan</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Curriculum</span>
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
                        <a href="schoolfeed.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School Feeds</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <h2>Welcome, <?php echo htmlspecialchars($teacher_name); ?>!</h2>
                <p>Teacher Dashboard</p>
            </div>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card">
                    <div class="card-icon">👥</div>
                    <h3>Total Students</h3>
                    <p class="card-value">45</p>
                    <a href="students.php" class="card-link">View Details</a>
                </div>

                <div class="card">
                    <div class="card-icon">📝</div>
                    <h3>Lesson Plans</h3>
                    <p class="card-value">22</p>
                    <a href="lesson-plan.php" class="card-link">View Details</a>
                </div>

                <div class="card">
                    <div class="card-icon">📈</div>
                    <h3>Subjects</h3>
                    <p class="card-value">12</p>
                    <a href="subjects.php" class="card-link">View Details</a>
                </div>

                <div class="card">
                    <div class="card-icon">📈</div>
                    <h3>Results Submitted</h3>
                    <p class="card-value">8</p>
                    <a href="results.php" class="card-link">View Details</a>
                </div>

                <div class="card">
                    <div class="card-icon">📚</div>
                    <h3>Curriculum Topics</h3>
                    <p class="card-value">25</p>
                    <a href="curriculum.php" class="card-link">View Details</a>
                </div>
            </div>

            <!-- Recent Activity Section -->
            <div class="activity-section">
                <h3>Recent Activity</h3>
                <div class="activity-list">
                    <div class="activity-item">
                        <span class="activity-date">Today</span>
                        <span class="activity-text">Lesson plan created for Mathematics</span>
                    </div>
                    <div class="activity-item">
                        <span class="activity-date">Yesterday</span>
                        <span class="activity-text">Student results submitted for Biology Class</span>
                    </div>
                    <div class="activity-item">
                        <span class="activity-date">2 days ago</span>
                        <span class="activity-text">Curriculum updated for English Studies</span>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-content">
                <p>&copy; 2025 SahabFormMaster. All rights reserved.</p>
                <p>Version 1.0 | <a href="#">Privacy Policy</a> | <a href="#">Terms of Service</a></p>
            </div>
        </div>
    </footer>

</body>
</html>