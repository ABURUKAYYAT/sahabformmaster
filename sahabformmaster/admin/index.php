<?php
// admin/index.php
session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_name = $_SESSION['full_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Principal Dashboard | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css">
</head>
<body>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name (Right) -->
            <div class="header-right">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>

            <!-- Principal Info and Logout (Left) -->
            <div class="header-left">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation (1/3 width) -->
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
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Manage Students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Manage Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Manage Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage-school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="timebook.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="travelling.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Travelling</span>
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
                </ul>
            </nav>
        </aside>

        <!-- Main Content (2/3 width) -->
        <main class="main-content">
            <div class="content-header">
                <h2>Welcome, <?php echo htmlspecialchars($principal_name); ?>!</h2>
                <p>Principal Dashboard & Management System</p>
            </div>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card">
                    <div class="card-icon">👥</div>
                    <h3>Total Students</h3>
                    <p class="card-value">450</p>
                    <a href="students.php" class="card-link">Manage Students</a>
                </div>

                <div class="card">
                    <div class="card-icon">👨‍🏫</div>
                    <h3>Total Teachers</h3>
                    <p class="card-value">35</p>
                    <a href="users.php" class="card-link">Manage Teachers</a>
                </div>

                <div class="card">
                    <div class="card-icon">📈</div>
                    <h3>Visitors This Year</h3>
                    <p class="card-value">20</p>
                    <a href="results.php" class="card-link">View Results</a>
                </div>

                <div class="card">
                    <div class="card-icon">📈</div>
                    <h3>Results Processed</h3>
                    <p class="card-value">28</p>
                    <a href="results.php" class="card-link">View Results</a>
                </div>

                <div class="card">
                    <div class="card-icon">📝</div>
                    <h3>Lesson Plans</h3>
                    <p class="card-value">85</p>
                    <a href="lesson-plans.php" class="card-link">View Plans</a>
                </div>

                <div class="card">
                    <div class="card-icon">📚</div>
                    <h3>Curriculum Topics</h3>
                    <p class="card-value">120</p>
                    <a href="curriculum.php" class="card-link">Manage Topics</a>
                </div>

                <div class="card">
                    <div class="card-icon">🏫</div>
                    <h3>School Info</h3>
                    <p class="card-value">Update</p>
                    <a href="school.php" class="card-link">Edit School</a>
                </div>
            </div>

            <!-- Statistics Section -->
            <div class="stats-section">
                <h3>School Statistics</h3>
                <div class="stats-grid">
                    <div class="stat-box">
                        <span class="stat-label">Classes</span>
                        <span class="stat-value">12</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Departments</span>
                        <span class="stat-value">5</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Active Users</span>
                        <span class="stat-value">42</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Pending Tasks</span>
                        <span class="stat-value">7</span>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Section -->
            <div class="activity-section">
                <h3>Recent Activity</h3>
                <div class="activity-list">
                    <div class="activity-item">
                        <span class="activity-date">Today</span>
                        <span class="activity-text">New teacher account created - Mr. Adeyemi</span>
                    </div>
                    <div class="activity-item">
                        <span class="activity-date">Yesterday</span>
                        <span class="activity-text">Student results batch uploaded - Mathematics</span>
                    </div>
                    <div class="activity-item">
                        <span class="activity-date">2 days ago</span>
                        <span class="activity-text">Curriculum updated for Science Department</span>
                    </div>
                    <div class="activity-item">
                        <span class="activity-date">3 days ago</span>
                        <span class="activity-text">School information updated successfully</span>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>About SahabFormMaster</h4>
                    <p>A comprehensive school management system for academic excellence.</p>
                </div>
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="school.php">School Settings</a></li>
                        <li><a href="users.php">User Management</a></li>
                        <li><a href="#">Support</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact</h4>
                    <p>Email: admin@sahabformmaster.com</p>
                    <p>Phone: +234 8086835607</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 SahabFormMaster. All rights reserved. | Version 1.0</p>
                <p><a href="#">Privacy Policy</a> | <a href="#">Terms of Service</a></p>
            </div>
        </div>
    </footer>

</body>
</html>