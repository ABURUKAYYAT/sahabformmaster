<?php
// principal/payments_dashboard.php
session_start();
if (isset($_SESSION['role']) && $_SESSION['role'] === 'clerk') {
    header('Location: ../clerk/payments.php');
    exit();
}
header('Location: ../index.php');
exit();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';

// Check if user is principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: ../login.php');
    exit();
}

// Get current school for data isolation
$current_school_id = require_school_auth();

$principal_name = $_SESSION['full_name'];
$paymentHelper = new PaymentHelper();

// Get statistics
$stats = [];

// Total collection
$stats['total_collected'] = $pdo->prepare("SELECT SUM(amount_paid) FROM student_payments WHERE status = 'completed' AND school_id = ?");
$stats['total_collected']->execute([$current_school_id]);
$stats['total_collected'] = $stats['total_collected']->fetchColumn() ?? 0;

// Collection by term
$stats['term_collection'] = $pdo->prepare("SELECT term, academic_year, SUM(amount_paid) as total
                                         FROM student_payments
                                         WHERE status = 'completed' AND school_id = ?
                                         GROUP BY term, academic_year
                                         ORDER BY academic_year DESC, term");
$stats['term_collection']->execute([$current_school_id]);
$stats['term_collection'] = $stats['term_collection']->fetchAll();

// Collection by class
$stats['class_collection'] = $pdo->prepare("SELECT c.class_name, SUM(sp.amount_paid) as total
                                          FROM student_payments sp
                                          JOIN classes c ON sp.class_id = c.id
                                          WHERE sp.status = 'completed' AND sp.school_id = ? AND c.school_id = ?
                                          GROUP BY sp.class_id
                                          ORDER BY total DESC");
$stats['class_collection']->execute([$current_school_id, $current_school_id]);
$stats['class_collection'] = $stats['class_collection']->fetchAll();

// Defaulters
$defaulters_query = $pdo->prepare("SELECT s.full_name, s.admission_no, c.class_name,
                                   (SELECT SUM(amount_paid) FROM student_payments sp2
                                    WHERE sp2.student_id = s.id AND sp2.status = 'completed' AND sp2.school_id = ?) as total_paid,
                                   (SELECT SUM(fs.amount) FROM fee_structure fs
                                    WHERE fs.class_id = s.class_id AND fs.is_active = 1) as total_fee
                                   FROM students s
                                   JOIN classes c ON s.class_id = c.id
                                   WHERE s.is_active = 1 AND s.school_id = ? AND c.school_id = ?
                                   HAVING total_paid < total_fee OR total_paid IS NULL
                                   LIMIT 20");
$defaulters_query->execute([$current_school_id, $current_school_id, $current_school_id]);
$stats['defaulters'] = $defaulters_query->fetchAll();

// Recent payments
$recent_payments_query = $pdo->prepare("SELECT sp.*, s.full_name, c.class_name
                                         FROM student_payments sp
                                         JOIN students s ON sp.student_id = s.id
                                         JOIN classes c ON sp.class_id = c.id
                                         WHERE sp.school_id = ? AND s.school_id = ? AND c.school_id = ?
                                         ORDER BY sp.created_at DESC LIMIT 10");
$recent_payments_query->execute([$current_school_id, $current_school_id, $current_school_id]);
$stats['recent_payments'] = $recent_payments_query->fetchAll();

// Payment methods distribution
$payment_methods_query = $pdo->prepare("SELECT payment_method, COUNT(*) as count, SUM(amount_paid) as total
                                         FROM student_payments
                                         WHERE status = 'completed' AND school_id = ?
                                         GROUP BY payment_method");
$payment_methods_query->execute([$current_school_id]);
$stats['payment_methods'] = $payment_methods_query->fetchAll();

// Additional stats for dashboard
$total_students_query = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active = 1 AND school_id = ?");
$total_students_query->execute([$current_school_id]);
$total_students = $total_students_query->fetchColumn();
$unpaid_students = count($stats['defaulters']);
$paid_students = $total_students - $unpaid_students;
$completion_rate = $total_students > 0 ? round(($paid_students / $total_students) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Fees Dashboard | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>

            <!-- Principal Info and Logout -->
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon">🚪</span>
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
                        <a href="index.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📰</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link">
                            <span class="nav-icon">📔</span>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students Registration</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students-evaluations.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Students Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_class.php" class="nav-link">
                            <span class="nav-icon">🎓</span>
                            <span class="nav-text">Manage Classes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage-school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                                                            <li class="nav-item">
                        <a href="support.php" class="nav-link">
                            <span class="nav-icon">🛟</span>
                            <span class="nav-text">Support</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subscription.php" class="nav-link">
                            <span class="nav-icon">💳</span>
                            <span class="nav-text">Subscription</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <span class="nav-icon">📖</span>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">👤</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🚶</span>
                            <span class="nav-text">Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_timebook.php" class="nav-link">
                            <span class="nav-icon">⏰</span>
                            <span class="nav-text">Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_attendance.php" class="nav-link">
                            <span class="nav-icon">📋</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments_dashboard.php" class="nav-link active">
                            <span class="nav-icon">💰</span>
                            <span class="nav-text">School Fees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="sessions.php" class="nav-link">
                            <span class="nav-icon">📅</span>
                            <span class="nav-text">School Sessions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_calendar.php" class="nav-link">
                            <span class="nav-icon">🗓️</span>
                            <span class="nav-text">School Calendar</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="applicants.php" class="nav-link">
                            <span class="nav-icon">📄</span>
                            <span class="nav-text">Applicants</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>💰 School Fees Dashboard</h2>
                    <p>Financial overview and payment analytics</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($total_students); ?></span>
                        <span class="quick-stat-label">Total Students</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $completion_rate; ?>%</span>
                        <span class="quick-stat-label">Payment Rate</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value">₦<?php echo number_format($stats['total_collected'] / 1000000, 1); ?>M</span>
                        <span class="quick-stat-label">Collected</span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">💰</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Collection</h3>
                        <p class="card-value">₦<?php echo $paymentHelper->formatCurrency($stats['total_collected']); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">All Time</span>
                            <a href="manage_fees.php" class="card-link">Manage Fees →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">✅</div>
                    </div>
                    <div class="card-content">
                        <h3>Paid Students</h3>
                        <p class="card-value"><?php echo number_format($paid_students); ?></p>
                        <div class="card-footer">
                            <span class="card-badge"><?php echo $completion_rate; ?>% completion</span>
                            <a href="students.php" class="card-link">View Students →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">⚠️</div>
                    </div>
                    <div class="card-content">
                        <h3>Unpaid Students</h3>
                        <p class="card-value"><?php echo number_format($unpaid_students); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Outstanding</span>
                            <a href="#defaulters" class="card-link">View Details →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📊</div>
                    </div>
                    <div class="card-content">
                        <h3>Active Students</h3>
                        <p class="card-value"><?php echo number_format($total_students); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Enrolled</span>
                            <a href="students.php" class="card-link">View All →</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-section">
                <div class="section-header">
                    <h3>📊 Payment Analytics</h3>
                    <span class="section-badge">Interactive</span>
                </div>
                <div class="charts-grid">
                    <div class="chart-card">
                        <h4>Payment Methods Distribution</h4>
                        <canvas id="paymentMethodsChart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h4>Collection Trends</h4>
                        <canvas id="collectionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tables Section -->
            <div class="charts-section">
                <div class="section-header">
                    <h3>📋 Detailed Reports</h3>
                    <span class="section-badge">Real-time</span>
                </div>
                <div class="charts-grid">
                    <div class="chart-card">
                        <h4>Collection by Term</h4>
                        <div class="table-responsive">
                            <table class="students-table">
                                <thead>
                                    <tr>
                                        <th>Term</th>
                                        <th>Academic Year</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['term_collection'] as $term): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($term['term']); ?></td>
                                            <td><?php echo htmlspecialchars($term['academic_year']); ?></td>
                                            <td><?php echo $paymentHelper->formatCurrency($term['total']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="chart-card">
                        <h4>Collection by Class</h4>
                        <div class="table-responsive">
                            <table class="students-table">
                                <thead>
                                    <tr>
                                        <th>Class</th>
                                        <th>Total Collected</th>
                                        <th>Payments</th>
                                        <th>Average</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['class_collection'] as $class): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                                            <td><?php echo $paymentHelper->formatCurrency($class['total']); ?></td>
                                            <td>
                                                <?php
                                                $count_query = $pdo->prepare("SELECT COUNT(*) FROM student_payments sp
                                                                             JOIN classes c ON sp.class_id = c.id
                                                                             WHERE c.class_name = ? AND sp.status = 'completed'
                                                                             AND sp.school_id = ? AND c.school_id = ?");
                                                $count_query->execute([$class['class_name'], $current_school_id, $current_school_id]);
                                                $count = $count_query->fetchColumn();
                                                echo $count;
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                $count = $count ?: 1;
                                                $avg = $class['total'] / $count;
                                                echo $paymentHelper->formatCurrency($avg);
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Defaulters Section -->
            <div class="charts-section" id="defaulters">
                <div class="section-header">
                    <h3>⚠️ Outstanding Payments</h3>
                    <span class="section-badge"><?php echo count($stats['defaulters']); ?> students</span>
                </div>
                <div class="chart-card">
                    <div class="table-responsive">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Admission No</th>
                                    <th>Class</th>
                                    <th>Paid</th>
                                    <th>Total Fee</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['defaulters'] as $defaulter): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($defaulter['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($defaulter['admission_no']); ?></td>
                                        <td><?php echo htmlspecialchars($defaulter['class_name']); ?></td>
                                        <td><?php echo $paymentHelper->formatCurrency($defaulter['total_paid'] ?: 0); ?></td>
                                        <td><?php echo $paymentHelper->formatCurrency($defaulter['total_fee'] ?: 0); ?></td>
                                        <td style="color: #ef4444; font-weight: bold;">
                                            <?php
                                            $balance = ($defaulter['total_fee'] ?: 0) - ($defaulter['total_paid'] ?: 0);
                                            echo $paymentHelper->formatCurrency($balance);
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Payments Section -->
            <div class="activity-section">
                <div class="section-header">
                    <h3>💳 Recent Payments</h3>
                    <span class="section-badge">Latest 10</span>
                </div>
                <div class="chart-card">
                    <div class="table-responsive">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['recent_payments'] as $payment): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($payment['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($payment['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['class_name']); ?></td>
                                        <td><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $payment['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($payment['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

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

        // Add active class on scroll
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Payment Methods Chart
            const methodsCtx = document.getElementById('paymentMethodsChart').getContext('2d');
            const methodsChart = new Chart(methodsCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        <?php foreach ($stats['payment_methods'] as $method): ?>
                            '<?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        data: [
                            <?php foreach ($stats['payment_methods'] as $method): ?>
                                <?php echo $method['count']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: [
                            '#3498db', '#27ae60', '#e74c3c', '#f39c12', '#9b59b6', '#1abc9c'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Collection Trends Chart (Line Chart)
            const collectionCtx = document.getElementById('collectionChart').getContext('2d');
            const collectionChart = new Chart(collectionCtx, {
                type: 'line',
                data: {
                    labels: [
                        <?php foreach ($stats['term_collection'] as $term): ?>
                            '<?php echo htmlspecialchars($term['term'] . ' ' . $term['academic_year']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Collection Amount',
                        data: [
                            <?php foreach ($stats['term_collection'] as $term): ?>
                                <?php echo $term['total']; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₦' + (value / 1000).toFixed(0) + 'K';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        });

        // Quick Actions Functionality
        document.querySelectorAll('.quick-action-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Add loading state
                this.style.opacity = '0.7';
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Loading...</span>';

                // Reset after a short delay (simulating navigation)
                setTimeout(() => {
                    window.location.href = this.href;
                }, 500);
            });
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>



