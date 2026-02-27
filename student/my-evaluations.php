<?php
// student/my-evaluations.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];
$current_school_id = get_current_school_id();

// Fetch student's evaluations
$stmt = $pdo->prepare("
    SELECT e.*, t.full_name as teacher_fname
    FROM evaluations e
    JOIN users t ON e.teacher_id = t.id
    JOIN students s ON e.student_id = s.id
    WHERE e.student_id = ? AND s.school_id = ?
    ORDER BY e.academic_year DESC, e.term DESC
");
$stmt->execute([$student_id, $current_school_id]);
$evaluations = $stmt->fetchAll();

// Fetch student details
$student = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$student->execute([$student_id, $current_school_id]);
$student_data = $student->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Evaluations | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                    <span class="student-name"><?php echo htmlspecialchars($student_name); ?></span>
                    <span class="admission-number"><?php echo htmlspecialchars($admission_number); ?></span>
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
        <?php include '../includes/student_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2><i class="fas fa-chart-line"></i> My Academic Evaluations</h2>
                    <p>Track your academic progress and performance evaluations</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="dashboard-cards">
                <?php
                $total_evaluations = count($evaluations);
                $excellent_count = 0;
                $current_term = !empty($evaluations) ? $evaluations[0]['term'] : 'N/A';
                $overall_progress = 0;

                if (!empty($evaluations)) {
                    foreach ($evaluations as $eval) {
                        if ($eval['academic'] === 'excellent' || $eval['non_academic'] === 'excellent') {
                            $excellent_count++;
                        }
                    }

                    $total_ratings = count($evaluations) * 5;
                    $positive_ratings = 0;
                    foreach ($evaluations as $eval) {
                        $ratings = [$eval['academic'], $eval['non_academic'], $eval['cognitive'], $eval['psychomotor'], $eval['affective']];
                        foreach ($ratings as $rating) {
                            if ($rating === 'excellent' || $rating === 'very-good') {
                                $positive_ratings++;
                            }
                        }
                    }
                    $overall_progress = round(($positive_ratings / $total_ratings) * 100);
                }
                ?>
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Total Evaluations</h3>
                        <p class="card-value"><?php echo $total_evaluations; ?></p>
                    </div>
                </div>
                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-star"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Excellent Ratings</h3>
                        <p class="card-value"><?php echo $excellent_count; ?></p>
                    </div>
                </div>
                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-calendar-alt"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Current Term</h3>
                        <p class="card-value"><?php echo $current_term; ?></p>
                    </div>
                </div>
                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-chart-pie"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Overall Progress</h3>
                        <p class="card-value"><?php echo $overall_progress; ?>%</p>
                    </div>
                </div>
            </div>

            <!-- Student Profile -->
            <div class="card">
                <div class="card-body">
                    <div class="profile-grid">
                        <div class="profile-avatar">
                            <div class="avatar-circle">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                        </div>
                        <div class="profile-info">
                            <h3 class="profile-name"><?php echo htmlspecialchars($student_data['full_name']); ?></h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class="fas fa-graduation-cap"></i>
                                    <div class="info-content">
                                        <strong>Class:</strong> <?php echo htmlspecialchars($student_data['class_id']); ?>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-id-card"></i>
                                    <div class="info-content">
                                        <strong>Roll No:</strong> <?php echo htmlspecialchars($student_data['admission_no']); ?>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar"></i>
                                    <div class="info-content">
                                        <strong>Academic Year:</strong> <?php echo date('Y'); ?> - <?php echo date('Y')+1; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Controls Section -->
            <div class="card">
                <div class="card-body">
                    <div class="control-header">
                        <i class="fas fa-filter"></i>
                        <h4>Filter Evaluations</h4>
                    </div>
                    <div class="control-group">
                        <label for="termFilter" class="control-label">Filter by Term</label>
                        <select id="termFilter" class="control-select">
                            <option value="all">All Terms</option>
                            <option value="1">Term 1</option>
                            <option value="2">Term 2</option>
                            <option value="3">Term 3</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Evaluations List -->
            <h3 style="margin: 3rem 0 2rem 0; font-family: 'Poppins', sans-serif; font-size: 1.5rem; font-weight: 600; color: var(--gray-900);">
                <i class="fas fa-list" style="margin-right: 0.75rem;"></i>
                My Evaluations
            </h3>

            <?php if (empty($evaluations)): ?>
                <div class="card">
                    <div class="card-body text-center" style="padding: 3rem;">
                        <div style="font-size: 3rem; color: var(--gray-400); margin-bottom: 1rem;">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <h4 style="color: var(--gray-600); margin-bottom: 0.5rem;">No Evaluations Yet</h4>
                        <p style="color: var(--gray-500); margin: 0;">You don't have any evaluations yet. Your teacher will evaluate you soon.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($evaluations as $eval): ?>
                    <div class="card" data-term="<?php echo $eval['term']; ?>">
                        <div class="card-header" style="background: var(--gradient-2);">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <span class="badge badge-primary" style="font-size: 0.9rem;">
                                        Term <?php echo $eval['term']; ?> - <?php echo $eval['academic_year']; ?>
                                    </span>
                                    <small style="display: block; margin-top: 0.5rem; color: rgba(255, 255, 255, 0.8);">
                                        <i class="fas fa-calendar" style="margin-right: 0.25rem;"></i>
                                        <?php echo date('F d, Y', strtotime($eval['created_at'])); ?>
                                    </small>
                                </div>
                                <div style="text-align: right; color: white;">
                                    <div style="font-weight: 600; color: inherit; margin-bottom: 0.25rem;">
                                        <?php echo htmlspecialchars($eval['teacher_fname']); ?>
                                    </div>
                                    <small style="opacity: 0.8;">Evaluated by</small>
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="rating-grid">
                                <div class="rating-item">
                                    <div class="rating-label">Academic</div>
                                    <span class="rating-badge rating-<?php echo $eval['academic']; ?>">
                                        <?php echo ucfirst($eval['academic']); ?>
                                    </span>
                                </div>
                                <div class="rating-item">
                                    <div class="rating-label">Non-Academic</div>
                                    <span class="rating-badge rating-<?php echo $eval['non_academic']; ?>">
                                        <?php echo ucfirst($eval['non_academic']); ?>
                                    </span>
                                </div>
                                <div class="rating-item">
                                    <div class="rating-label">Cognitive</div>
                                    <span class="rating-badge rating-<?php echo $eval['cognitive']; ?>">
                                        <?php echo ucfirst($eval['cognitive']); ?>
                                    </span>
                                </div>
                                <div class="rating-item">
                                    <div class="rating-label">Psychomotor</div>
                                    <span class="rating-badge rating-<?php echo $eval['psychomotor']; ?>">
                                        <?php echo ucfirst($eval['psychomotor']); ?>
                                    </span>
                                </div>
                                <div class="rating-item">
                                    <div class="rating-label">Affective</div>
                                    <span class="rating-badge rating-<?php echo $eval['affective']; ?>">
                                        <?php echo ucfirst($eval['affective']); ?>
                                    </span>
                                </div>
                            </div>

                            <?php if (!empty($eval['comments'])): ?>
                                <div class="comments-section">
                                    <strong class="comments-title">Teacher's Comments:</strong>
                                    <p class="comments-text">
                                        <?php echo nl2br(htmlspecialchars($eval['comments'])); ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <div class="card-actions">
                                <button class="action-btn action-btn-secondary" onclick="printEvaluation(<?php echo $eval['id']; ?>)">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <button class="action-btn action-btn-primary" onclick="viewDetails(<?php echo $eval['id']; ?>)">
                                    <i class="fas fa-expand"></i> View Details
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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

        function printEvaluation(evaluationId) {
            window.open(`print-evaluation.php?id=${evaluationId}`, '_blank');
        }

        function viewDetails(evaluationId) {
            alert('View details for evaluation ID: ' + evaluationId + '\n\nFeature coming soon!');
        }

        // Term filter functionality
        document.getElementById('termFilter').addEventListener('change', function() {
            const selectedTerm = this.value;
            const evaluationCards = document.querySelectorAll('[data-term]');

            evaluationCards.forEach(card => {
                const cardTerm = card.getAttribute('data-term');
                if (selectedTerm === 'all' || cardTerm === selectedTerm) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
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
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
