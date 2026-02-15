<?php

session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$activity_id = $_GET['id'];
$principal_id = $_SESSION['user_id'];
$principal_name = $_SESSION['full_name'];

try {
    // Get activity details
    $stmt = $pdo->prepare("
        SELECT sd.*, u.full_name as coordinator_name
        FROM school_diary sd
        LEFT JOIN users u ON sd.coordinator_id = u.id
        WHERE sd.id = ? AND sd.created_by = ?
    ");
    $stmt->execute([$activity_id, $principal_id]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        header("Location: index.php");
        exit;
    }

    // Get attachments
    $attachments_stmt = $pdo->prepare("SELECT * FROM school_diary_attachments WHERE diary_id = ?");
    $attachments_stmt->execute([$activity_id]);
    $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format date and time
    $activity_date = date('F j, Y', strtotime($activity['activity_date']));
    $start_time = $activity['start_time'] ? date('h:i A', strtotime($activity['start_time'])) : 'Not specified';
    $end_time = $activity['end_time'] ? date('h:i A', strtotime($activity['end_time'])) : 'Not specified';
    $time_display = ($activity['start_time'] && $activity['end_time']) ?
                   "$start_time to $end_time" : $start_time;

    // Determine status badge color
    $status_colors = [
        'Upcoming' => 'success',
        'Ongoing' => 'info',
        'Completed' => 'primary',
        'Cancelled' => 'danger'
    ];
    $status_color = $status_colors[$activity['status']] ?? 'secondary';

    // Check if activity has completion details
    $has_completion_details = $activity['status'] === 'Completed' &&
                             ($activity['participant_count'] || $activity['winners_list'] ||
                              $activity['achievements'] || $activity['feedback_summary']);

} catch (PDOException $e) {
    error_log("Activity details error: " . $e->getMessage());
    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Details | <?php echo htmlspecialchars($activity['activity_title']); ?> - SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Activity Details Specific Styles */
        .activity-info-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
        }

        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            border-bottom: 2px solid var(--gray-100);
            padding-bottom: 0.5rem;
        }

        .info-item {
            margin-bottom: 1rem;
        }

        .info-item:last-child {
            margin-bottom: 0;
        }

        .info-label {
            display: block;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-content {
            background: var(--gray-50);
            padding: 1rem;
            border-radius: var(--border-radius-md);
            border-left: 3px solid var(--primary-color);
        }

        .info-content p {
            color: var(--gray-700);
            line-height: 1.6;
        }

        /* Enhanced card body padding */
        .card-body {
            padding: 2rem !important;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .activity-info-section {
                padding: 1rem;
            }

            .card-body {
                padding: 1.5rem !important;
            }

            .section-title {
                font-size: 1rem;
            }
        }

        @media (max-width: 480px) {
            .activity-info-section {
                padding: 0.75rem;
            }

            .card-body {
                padding: 1rem !important;
            }

            .info-content {
                padding: 0.75rem;
            }
        }
    </style>
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
                        <a href="school_diary.php" class="nav-link active">
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
                        <a href="payments_dashboard.php" class="nav-link">
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
      
            <!-- Content Header -->
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Activity Details <i class="bi bi-journal-text ms-2"></i></h2>
                    <p><?php echo htmlspecialchars($activity['activity_title']); ?> - Complete information and details</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo $status_color === 'success' ? '✓' : ($status_color === 'info' ? '⟳' : ($status_color === 'primary' ? '●' : '✗')); ?></span>
                        <span class="quick-stat-label"><?php echo htmlspecialchars($activity['status']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Activity Details Card -->
            <div class="card mb-4">
                <div class="card-body p-4">
                    <!-- Activity Overview Section -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="badge badge-<?php echo $status_color; ?> fs-6 px-3 py-2">
                                        <i class="bi bi-circle-fill me-1"></i><?php echo htmlspecialchars($activity['status']); ?>
                                    </span>
                                    <span class="text-muted">
                                        <i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($activity['activity_type']); ?>
                                    </span>
                                </div>
                                <?php if ($activity['participant_count']): ?>
                                <div class="stat-box d-inline-block">
                                    <div class="stat-number"><?php echo $activity['participant_count']; ?></div>
                                    <div class="stat-label">Participants</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Details Grid -->
                    <div class="row g-4 mb-5">
                        <div class="col-md-6 col-lg-3">
                            <div class="text-center p-3 bg-light rounded-3 h-100">
                                <i class="bi bi-calendar-date text-primary fs-2 mb-2"></i>
                                <h6 class="text-muted small mb-1">Date</h6>
                                <div class="fw-bold text-dark"><?php echo $activity_date; ?></div>
                            </div>
                        </div>

                        <?php if ($time_display !== 'Not specified'): ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="text-center p-3 bg-light rounded-3 h-100">
                                <i class="bi bi-clock text-info fs-2 mb-2"></i>
                                <h6 class="text-muted small mb-1">Time</h6>
                                <div class="fw-bold text-dark"><?php echo $time_display; ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($activity['venue']): ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="text-center p-3 bg-light rounded-3 h-100">
                                <i class="bi bi-geo-alt text-success fs-2 mb-2"></i>
                                <h6 class="text-muted small mb-1">Venue</h6>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($activity['venue']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($activity['participant_count']): ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="text-center p-3 bg-light rounded-3 h-100">
                                <i class="bi bi-people text-warning fs-2 mb-2"></i>
                                <h6 class="text-muted small mb-1">Participants</h6>
                                <div class="fw-bold text-dark"><?php echo $activity['participant_count']; ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Activity Information Sections -->
                    <div class="row g-4">
                        <!-- Left Column - Organization & People -->
                        <div class="col-lg-6">
                            <div class="activity-info-section mb-4">
                                <h5 class="section-title mb-4">
                                    <i class="bi bi-building text-primary me-2"></i>
                                    Organization & People
                                </h5>

                                <!-- Organizing Department -->
                                <?php if ($activity['organizing_dept']): ?>
                                <div class="info-item mb-3">
                                    <label class="info-label">Organizing Department</label>
                                    <div class="info-content">
                                        <span class="fw-semibold"><?php echo htmlspecialchars($activity['organizing_dept']); ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Coordinator -->
                                <div class="info-item mb-3">
                                    <label class="info-label">Coordinator</label>
                                    <div class="info-content">
                                        <?php echo $activity['coordinator_name'] ?
                                           '<span class="fw-semibold">' . htmlspecialchars($activity['coordinator_name']) . '</span>' :
                                           '<span class="text-muted fst-italic">Not assigned</span>'; ?>
                                    </div>
                                </div>

                                <!-- Target Audience -->
                                <div class="info-item">
                                    <label class="info-label">Target Audience</label>
                                    <div class="info-content">
                                        <div class="fw-semibold mb-1"><?php echo htmlspecialchars($activity['target_audience']); ?></div>
                                        <?php if ($activity['target_classes']): ?>
                                            <small class="text-muted">Classes: <?php echo htmlspecialchars($activity['target_classes']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Details & Objectives -->
                        <div class="col-lg-6">
                            <div class="activity-info-section mb-4">
                                <h5 class="section-title mb-4">
                                    <i class="bi bi-info-circle text-info me-2"></i>
                                    Activity Details
                                </h5>

                                <!-- Description -->
                                <div class="info-item mb-3">
                                    <label class="info-label">Description</label>
                                    <div class="info-content">
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($activity['description'])); ?></p>
                                    </div>
                                </div>

                                <!-- Objectives -->
                                <?php if ($activity['objectives']): ?>
                                <div class="info-item mb-3">
                                    <label class="info-label">Objectives</label>
                                    <div class="info-content">
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($activity['objectives'])); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Resources -->
                                <?php if ($activity['resources']): ?>
                                <div class="info-item">
                                    <label class="info-label">Resources Required</label>
                                    <div class="info-content">
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($activity['resources'])); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Attachments Section -->
                    <?php if (!empty($attachments)): ?>
                    <hr class="my-4">
                    <div class="mb-4">
                        <h5 class="text-primary mb-3">
                            <i class="bi bi-paperclip me-2"></i>
                            Attachments
                        </h5>
                        <div class="row g-3">
                            <?php foreach ($attachments as $attachment): ?>
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="card h-100 border-0 shadow-sm hover-lift">
                                        <div class="card-body text-center">
                                            <?php if ($attachment['file_type'] == 'image'): ?>
                                                <img src="<?php echo htmlspecialchars($attachment['file_path']); ?>"
                                                     alt="<?php echo htmlspecialchars($attachment['file_name']); ?>"
                                                     class="img-fluid rounded mb-3"
                                                     onclick="openImageModal('<?php echo htmlspecialchars($attachment['file_path']); ?>')"
                                                     style="height: 120px; object-fit: cover; width: 100%;">
                                                <p class="small text-truncate-2 fw-semibold mb-2"><?php echo htmlspecialchars($attachment['file_name']); ?></p>
                                                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>"
                                                   download
                                                   class="btn btn-outline-primary btn-sm w-100">
                                                    <i class="bi bi-download me-1"></i> Download
                                                </a>

                                            <?php elseif ($attachment['file_type'] == 'video'): ?>
                                                <div class="mb-3">
                                                    <i class="bi bi-play-circle-fill text-danger fs-1"></i>
                                                </div>
                                                <p class="small text-truncate-2 fw-semibold mb-2"><?php echo htmlspecialchars($attachment['file_name']); ?></p>
                                                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>"
                                                   download
                                                   class="btn btn-outline-primary btn-sm w-100">
                                                    <i class="bi bi-download me-1"></i> Download
                                                </a>

                                            <?php else: ?>
                                                <div class="mb-3">
                                                    <i class="bi bi-file-earmark-text text-success fs-1"></i>
                                                </div>
                                                <p class="small text-truncate-2 fw-semibold mb-2"><?php echo htmlspecialchars($attachment['file_name']); ?></p>
                                                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>"
                                                   download
                                                   class="btn btn-outline-primary btn-sm w-100">
                                                    <i class="bi bi-download me-1"></i> Download
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Completion Details (Only for Completed Activities) -->
                    <?php if ($has_completion_details): ?>
                    <hr class="my-4">
                    <div class="completion-section">
                        <h4 class="text-primary mb-4">
                            <i class="bi bi-check-circle me-2"></i>
                            Activity Completion Details
                        </h4>

                        <div class="row align-items-start mb-4">
                            <?php if ($activity['participant_count']): ?>
                            <div class="col-md-3 mb-4 mb-md-0">
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo $activity['participant_count']; ?></div>
                                    <div class="stat-label">Participants</div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($activity['winners_list']): ?>
                            <div class="col-md-9">
                                <h6 class="text-success mb-3">
                                    <i class="bi bi-trophy me-2"></i>
                                    Winners List
                                </h6>
                                <div class="bg-light p-3 rounded">
                                    <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($activity['winners_list'])); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($activity['achievements']): ?>
                        <div class="mb-4">
                            <h6 class="text-warning mb-3">
                                <i class="bi bi-star me-2"></i>
                                Achievements
                            </h6>
                            <div class="bg-light p-3 rounded">
                                <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($activity['achievements'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($activity['feedback_summary']): ?>
                        <div class="mb-4">
                            <h6 class="text-info mb-3">
                                <i class="bi bi-chat-left-text me-2"></i>
                                Feedback Summary
                            </h6>
                            <div class="bg-light p-3 rounded">
                                <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($activity['feedback_summary'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Activity Metadata -->
                    <hr class="my-4">
                    <div class="bg-light p-3 rounded">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-2 mb-md-0">
                                <i class="bi bi-person-plus text-primary me-2"></i>
                                <span class="fw-semibold">Created by Principal</span>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <i class="bi bi-clock-history text-muted me-2"></i>
                                <span class="fw-semibold">Last updated: <?php echo date('M j, Y \a\t h:i A', strtotime($activity['updated_at'] ?? $activity['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    

    <!-- Image Modal -->
    <div id="imageModal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Attachment Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" src="" alt="Attachment" class="img-fluid">
                </div>
            </div>
        </div>
    </div>

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

        // Image Modal Function
        function openImageModal(imageSrc) {
            document.getElementById('modalImage').src = imageSrc;
            const modal = new bootstrap.Modal(document.getElementById('imageModal'));
            modal.show();
        }

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

