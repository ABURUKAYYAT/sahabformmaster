<?php
// student/dashboard.php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_number'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | SahabFormMaster</title>
    <!-- <link rel="stylesheet" href="../assets/css/styles.css"> -->
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
</head>
<body>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name (Right) -->
            <div class="header-right">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <h1 class="school-name">SahabFormMaster</h1>
                </div>
            </div>

            <!-- Student Info and Logout (Left) -->
            <div class="header-left">
                <div class="student-info">
                    <span class="student-name"><?php echo htmlspecialchars($student_name); ?></span>
                    <span class="admission-number"><?php echo htmlspecialchars($admission_number); ?></span>
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
                        <a href="dashboard.php" class="nav-link active">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="first-term-result.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">First Term Result</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="second-term-result.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Second Term Result</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="third-term-result.php" class="nav-link">
                            <span class="nav-icon">📉</span>
                            <span class="nav-text">Third Term Result</span>
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
                        <a href="schoolfees.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School Fees Payments</span>
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
                <h2>Welcome, <?php echo htmlspecialchars($student_name); ?>!</h2>
                <p>Student Dashboard</p>
            </div>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card">
                    <div class="card-icon">📚</div>
                    <h3>First Term Result</h3>
                    <p class="card-description">View your first term examination results</p>
                    <a href="first-term-result.php" class="card-link">View Results</a>
                </div>

                <div class="card">
                    <div class="card-icon">📖</div>
                    <h3>Second Term Result</h3>
                    <p class="card-description">View your second term examination results</p>
                    <a href="second-term-result.php" class="card-link">View Results</a>
                </div>

                <div class="card">
                    <div class="card-icon">📝</div>
                    <h3>Third Term Result</h3>
                    <p class="card-description">View your third term examination results</p>
                    <a href="third-term-result.php" class="card-link">View Results</a>
                </div>
            </div>

            <!-- Overall Performance Section -->
            <div class="performance-section">
                <h3>Overall Performance</h3>
                <div class="performance-grid">
                    <div class="performance-item">
                        <span class="performance-label">Total Score</span>
                        <span class="performance-value">245/300</span>
                        <span class="performance-percentage">82%</span>
                    </div>
                    <div class="performance-item">
                        <span class="performance-label">Grade</span>
                        <span class="performance-value">A</span>
                        <span class="performance-percentage">Excellent</span>
                    </div>
                    <div class="performance-item">
                        <span class="performance-label">Attendance</span>
                        <span class="performance-value">95%</span>
                        <span class="performance-percentage">Perfect</span>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Section -->
            <div class="activity-section">
                <h3>Recent Activity</h3>
                <div class="activity-list">
                    <div class="activity-item">
                        <span class="activity-icon">✓</span>
                        <div class="activity-content">
                            <span class="activity-text">Third Term Results Released</span>
                            <span class="activity-date">2 days ago</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <span class="activity-icon">📝</span>
                        <div class="activity-content">
                            <span class="activity-text">Assignment Submitted - Mathematics</span>
                            <span class="activity-date">1 week ago</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <span class="activity-icon">✓</span>
                        <div class="activity-content">
                            <span class="activity-text">Second Term Results Available</span>
                            <span class="activity-date">2 weeks ago</span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-content">
                <!-- About Section -->
                <div class="footer-section">
                    <h4>About SahabFormMaster</h4>
                    <p>A comprehensive educational management system designed to help students track their academic progress and performance.</p>
                </div>

                <!-- Quick Links Section -->
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <div class="footer-links">
                        <a href="dashboard.php">Dashboard</a>
                        <a href="first-term-result.php">First Term</a>
                        <a href="second-term-result.php">Second Term</a>
                        <a href="third-term-result.php">Third Term</a>
                    </div>
                </div>

                <!-- Support Section -->
                <div class="footer-section">
                    <h4>Support & Help</h4>
                    <p>Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
                    <p>Phone: +234 (0) 800 000 0000</p>
                </div>
            </div>

            <!-- Footer Bottom -->
            <div class="footer-bottom">
                <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
                <p class="footer-version">Version 1.0 | 
                    <a href="#">Privacy Policy</a> | 
                    <a href="#">Terms of Service</a>
                </p>
            </div>
        </div>
    </footer>

</body>
</html>