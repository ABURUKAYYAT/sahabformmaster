<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'clerk') {
    header('Location: ../index.php');
    exit;
}

$current_school_id = require_school_auth();
PaymentHelper::ensureSchema();

$clerk_name = $_SESSION['full_name'] ?? 'Clerk';
$paymentHelper = new PaymentHelper();

// Quick stats
$stats = [
    'pending' => 0,
    'verified' => 0,
    'completed' => 0,
    'rejected' => 0
];

$stmt = $pdo->prepare("SELECT status, COUNT(*) as total
                      FROM student_payments
                      WHERE school_id = ?
                      GROUP BY status");
$stmt->execute([$current_school_id]);
foreach ($stmt->fetchAll() as $row) {
    $stats[$row['status']] = (int)$row['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clerk Dashboard - <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/education-theme-main.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include '../includes/mobile_navigation.php'; ?>

    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">Clerk Portal</p>
                    </div>
                </div>
            </div>

            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Clerk</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($clerk_name); ?></span>
                </div>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include '../includes/clerk_sidebar.php'; ?>

        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Welcome back, <?php echo htmlspecialchars($clerk_name); ?>!</h2>
                    <p>Track payment activity and manage fee structures for your school.</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $stats['pending'] ?? 0; ?></span>
                        <span class="quick-stat-label">Pending</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $stats['verified'] ?? 0; ?></span>
                        <span class="quick-stat-label">Verified</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $stats['completed'] ?? 0; ?></span>
                        <span class="quick-stat-label">Completed</span>
                    </div>
                </div>
            </div>

            <div class="dashboard-cards">
                <div class="card card-gradient-5">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-hourglass-half"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Pending Payments</h3>
                        <p class="card-value"><?php echo $stats['pending'] ?? 0; ?> Records</p>
                        <div class="card-footer">
                            <span class="card-badge">Needs Review</span>
                            <a href="payments.php" class="card-link">Review -></a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-user-check"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Verified Payments</h3>
                        <p class="card-value"><?php echo $stats['verified'] ?? 0; ?> Records</p>
                        <div class="card-footer">
                            <span class="card-badge">Awaiting Completion</span>
                            <a href="payments.php" class="card-link">View -></a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Completed Payments</h3>
                        <p class="card-value"><?php echo $stats['completed'] ?? 0; ?> Records</p>
                        <div class="card-footer">
                            <span class="card-badge">Successful</span>
                            <a href="payments.php" class="card-link">View -></a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-6">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-ban"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Rejected Payments</h3>
                        <p class="card-value"><?php echo $stats['rejected'] ?? 0; ?> Records</p>
                        <div class="card-footer">
                            <span class="card-badge">Needs Follow-up</span>
                            <a href="payments.php" class="card-link">Resolve -></a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="quick-actions-section">
                <div class="section-header">
                    <h3>Quick Actions</h3>
                    <span class="section-badge">Payments</span>
                </div>
                <div class="quick-actions-grid">
                    <a href="payments.php" class="quick-action-card admin">
                        <i class="fas fa-money-check-alt"></i>
                        <span>Verify Payments</span>
                    </a>
                    <a href="payments.php" class="quick-action-card attendance">
                        <i class="fas fa-receipt"></i>
                        <span>Issue Receipts</span>
                    </a>
                    <a href="fee_structure.php" class="quick-action-card academic">
                        <i class="fas fa-layer-group"></i>
                        <span>Fee Structure</span>
                    </a>
                    <a href="payments.php" class="quick-action-card general">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Payment History</span>
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script>
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

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

        document.querySelectorAll('.card, .quick-action-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>
</body>
</html>
