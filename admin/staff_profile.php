<?php
// admin/staff_profile.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow principal (admin) to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$admin_name = $_SESSION['full_name'] ?? 'Administrator';
$current_school_id = require_school_auth();

// Get staff ID from URL
$staff_id = intval($_GET['id'] ?? 0);

if ($staff_id <= 0) {
    header("Location: manage_user.php");
    exit;
}

// Fetch staff details scoped to current school
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND school_id = :school_id");
$stmt->execute(['id' => $staff_id, 'school_id' => $current_school_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    header("Location: manage_user.php");
    exit;
}

// Calculate age from date of birth
$age = '';
if (!empty($staff['date_of_birth'])) {
    $birthDate = new DateTime($staff['date_of_birth']);
    $today = new DateTime('today');
    $age = $birthDate->diff($today)->y;
}

// Calculate years of service
$years_of_service = '';
if (!empty($staff['date_employed'])) {
    $employedDate = new DateTime($staff['date_employed']);
    $today = new DateTime('today');
    $years_of_service = $employedDate->diff($today)->y;
}

// Fetch staff documents count
$stmt = $pdo->prepare("SELECT COUNT(*) as doc_count FROM staff_documents WHERE user_id = :user_id");
$stmt->execute(['user_id' => $staff_id]);
$doc_count = $stmt->fetchColumn();

// Fetch recent lesson plans by this teacher
$stmt = $pdo->prepare("SELECT COUNT(*) as lesson_count FROM lesson_plans WHERE teacher_id = :teacher_id");
$stmt->execute(['teacher_id' => $staff_id]);
$lesson_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?php echo htmlspecialchars($staff['full_name']); ?> | Staff Profile</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .profile-hero {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 16px;
            align-items: center;
        }
        .profile-avatar {
            width: 92px;
            height: 92px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            box-shadow: var(--shadow-md);
        }
        .profile-name {
            margin: 0;
            font-size: 1.6rem;
            color: var(--secondary);
            font-weight: 800;
        }
        .role-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .role-principal { background: #fff0e6; color: #e65c00; }
        .role-teacher { background: #e6f7ff; color: #0066cc; }
        .role-admin { background: #f3f4f6; color: #4b5563; }
        .role-support { background: #e6f9f0; color: #166534; }
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }
        .info-row {
            display: grid;
            grid-template-columns: 170px 1fr;
            gap: 12px;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eef2f7;
        }
        .info-label { color: #6b7280; font-weight: 600; }
        .info-value { color: #111827; }
        .empty-field { color: #9ca3af; font-style: italic; }
        @media (max-width: 1024px) {
            .profile-grid { grid-template-columns: 1fr; }
            .info-row { grid-template-columns: 1fr; gap: 4px; }
            .profile-hero { grid-template-columns: 1fr; text-align: center; }
            .profile-avatar { margin: 0 auto; }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Admin Portal</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Administrator</p>
                    <span class="principal-name"><?php echo htmlspecialchars($admin_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon">??</span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
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
                        <a href="manage_user.php" class="nav-link active">
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

        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2><i class="fas fa-id-card"></i> Staff Profile</h2>
                    <p>Detailed staff profile and employment information</p>
                </div>
            </div>

            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-file-alt"></i>
                    <h3>Documents</h3>
                    <div class="count"><?php echo (int)$doc_count; ?></div>
                    <p class="stat-description">Uploaded files</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-book"></i>
                    <h3>Lesson Plans</h3>
                    <div class="count"><?php echo (int)$lesson_count; ?></div>
                    <p class="stat-description">Created plans</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-calendar-alt"></i>
                    <h3>Years of Service</h3>
                    <div class="count"><?php echo $years_of_service !== '' ? (int)$years_of_service : 0; ?></div>
                    <p class="stat-description">Since employment date</p>
                </div>
            </div>

            <section class="panel">
                <div class="profile-hero">
                    <div class="profile-avatar"><i class="fas fa-user-tie"></i></div>
                    <div>
                        <h3 class="profile-name"><?php echo htmlspecialchars($staff['full_name']); ?></h3>
                        <?php
                        $roleClass = 'role-support';
                        if ($staff['role'] === 'principal') $roleClass = 'role-principal';
                        elseif ($staff['role'] === 'teacher') $roleClass = 'role-teacher';
                        elseif ($staff['role'] === 'admin') $roleClass = 'role-admin';
                        ?>
                        <span class="role-pill <?php echo $roleClass; ?>">
                            <i class="fas fa-user-shield"></i> <?php echo htmlspecialchars(ucfirst($staff['role'])); ?>
                        </span>
                        <p style="margin:10px 0 0;color:#6b7280;">Staff ID: <strong><?php echo htmlspecialchars($staff['staff_id'] ?? 'N/A'); ?></strong> | Username: <strong>@<?php echo htmlspecialchars($staff['username']); ?></strong></p>
                        <p style="margin:6px 0 0;color:#6b7280;"><?php echo !empty($staff['designation']) ? htmlspecialchars($staff['designation']) : 'No designation set'; ?></p>
                    </div>
                </div>
            </section>

            <div class="profile-grid">
                <section class="panel">
                    <div class="panel-header"><h2><i class="fas fa-user-circle"></i> Personal Information</h2></div>
                    <div class="info-row"><div class="info-label">Full Name</div><div class="info-value"><?php echo htmlspecialchars($staff['full_name']); ?></div></div>
                    <div class="info-row"><div class="info-label">Date of Birth</div><div class="info-value"><?php echo !empty($staff['date_of_birth']) ? htmlspecialchars(date('F j, Y', strtotime($staff['date_of_birth']))) . " ($age years)" : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Email</div><div class="info-value"><?php echo !empty($staff['email']) ? '<a href="mailto:' . htmlspecialchars($staff['email']) . '">' . htmlspecialchars($staff['email']) . '</a>' : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Phone</div><div class="info-value"><?php echo !empty($staff['phone']) ? '<a href="tel:' . htmlspecialchars($staff['phone']) . '">' . htmlspecialchars($staff['phone']) . '</a>' : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Address</div><div class="info-value"><?php echo !empty($staff['address']) ? htmlspecialchars($staff['address']) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                </section>

                <section class="panel">
                    <div class="panel-header"><h2><i class="fas fa-briefcase"></i> Professional Information</h2></div>
                    <div class="info-row"><div class="info-label">Designation</div><div class="info-value"><?php echo !empty($staff['designation']) ? htmlspecialchars($staff['designation']) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Department</div><div class="info-value"><?php echo !empty($staff['department']) ? htmlspecialchars(ucfirst($staff['department'])) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Qualification</div><div class="info-value"><?php echo !empty($staff['qualification']) ? htmlspecialchars($staff['qualification']) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Teacher License</div><div class="info-value"><?php echo !empty($staff['teacher_license']) ? htmlspecialchars($staff['teacher_license']) : '<span class="empty-field">Not applicable</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Employment Type</div><div class="info-value"><?php echo !empty($staff['employment_type']) ? htmlspecialchars(ucfirst(str_replace('-', ' ', $staff['employment_type']))) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Date Employed</div><div class="info-value"><?php echo !empty($staff['date_employed']) ? htmlspecialchars(date('F j, Y', strtotime($staff['date_employed']))) . " ($years_of_service years)" : '<span class="empty-field">Not specified</span>'; ?></div></div>
                </section>

                <section class="panel">
                    <div class="panel-header"><h2><i class="fas fa-phone-alt"></i> Emergency Contact</h2></div>
                    <div class="info-row"><div class="info-label">Contact Name</div><div class="info-value"><?php echo !empty($staff['emergency_contact']) ? htmlspecialchars($staff['emergency_contact']) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Contact Phone</div><div class="info-value"><?php echo !empty($staff['emergency_phone']) ? '<a href="tel:' . htmlspecialchars($staff['emergency_phone']) . '">' . htmlspecialchars($staff['emergency_phone']) . '</a>' : '<span class="empty-field">Not specified</span>'; ?></div></div>
                </section>

                <section class="panel">
                    <div class="panel-header"><h2><i class="fas fa-university"></i> Financial Information</h2></div>
                    <div class="info-row"><div class="info-label">Bank Name</div><div class="info-value"><?php echo !empty($staff['bank_name']) ? htmlspecialchars($staff['bank_name']) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Account Number</div><div class="info-value"><?php echo !empty($staff['account_number']) ? htmlspecialchars($staff['account_number']) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Tax ID</div><div class="info-value"><?php echo !empty($staff['tax_id']) ? htmlspecialchars($staff['tax_id']) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                    <div class="info-row"><div class="info-label">Pension Number</div><div class="info-value"><?php echo !empty($staff['pension_number']) ? htmlspecialchars($staff['pension_number']) : '<span class="empty-field">Not specified</span>'; ?></div></div>
                </section>
            </div>

            <section class="panel">
                <div class="form-actions" style="margin-top:0;padding-top:0;border-top:0;justify-content:flex-start;">
                    <a href="manage_user.php" class="btn secondary"><i class="fas fa-arrow-left"></i> Back to Staff List</a>
                    <a href="manage_user.php?edit=<?php echo $staff_id; ?>" class="btn warning"><i class="fas fa-edit"></i> Edit Profile</a>
                    <a href="staff_documents.php?staff_id=<?php echo $staff_id; ?>" class="btn success"><i class="fas fa-file-upload"></i> Manage Documents</a>
                </div>
            </section>
        </main>
    </div>

    <script>
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

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>
</body>
</html>



