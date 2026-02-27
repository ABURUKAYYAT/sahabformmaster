<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_name = $_SESSION['full_name'];
$current_school_id = require_school_auth();
$errors = [];
$success = '';

// Handle Create/Update/Delete Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Update School Profile
    if ($action === 'update_school_profile') {
        $school_name = trim($_POST['school_name'] ?? '');
        $school_address = trim($_POST['school_address'] ?? '');
        $school_phone = trim($_POST['school_phone'] ?? '');
        $school_email = trim($_POST['school_email'] ?? '');
        $school_website = trim($_POST['school_website'] ?? '');
        $school_motto = trim($_POST['school_motto'] ?? '');

        // Validate inputs
        if ($school_name === '') {
            $errors[] = 'School name is required.';
        }
        if ($school_email !== '' && !filter_var($school_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        // Handle logo upload
        $school_logo = null;
        $remove_logo = isset($_POST['remove_logo']) ? true : false;
        if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($_FILES['school_logo']['type'], $allowed_types)) {
                $errors[] = 'Only JPG, PNG, and WebP images are allowed for the logo.';
            } elseif ($_FILES['school_logo']['size'] > 5242880) { // 5MB
                $errors[] = 'Logo size must not exceed 5MB.';
            } else {
                $upload_dir = '../uploads/school/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_ext = pathinfo($_FILES['school_logo']['name'], PATHINFO_EXTENSION);
                $file_name = 'school_logo.' . $file_ext;
                if (move_uploaded_file($_FILES['school_logo']['tmp_name'], $upload_dir . $file_name)) {
                    $school_logo = 'uploads/school/' . $file_name;
                } else {
                    $errors[] = 'Failed to upload the logo.';
                }
            }
        }

        if (empty($errors)) {
            // Ensure profile exists for this school
            $stmt = $pdo->prepare("SELECT id FROM school_profile WHERE school_id = ? LIMIT 1");
            $stmt->execute([$current_school_id]);
            $profile_id = $stmt->fetchColumn();

            if ($profile_id) {
                $stmt = $pdo->prepare("UPDATE school_profile SET
                    school_name = :school_name,
                    school_address = :school_address,
                    school_phone = :school_phone,
                    school_email = :school_email,
                    school_website = :school_website,
                    school_motto = :school_motto,
                    school_logo = CASE
                        WHEN :remove_logo = 1 THEN ''
                        WHEN :school_logo IS NOT NULL THEN :school_logo
                        ELSE school_logo
                    END
                    WHERE id = :id AND school_id = :school_id");
                $stmt->execute([
                    'school_name' => $school_name,
                    'school_address' => $school_address,
                    'school_phone' => $school_phone,
                    'school_email' => $school_email,
                    'school_website' => $school_website,
                    'school_motto' => $school_motto,
                    'school_logo' => $school_logo,
                    'remove_logo' => $remove_logo ? 1 : 0,
                    'id' => $profile_id,
                    'school_id' => $current_school_id
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO school_profile
                    (profile, school_name, school_address, school_phone, school_email, school_logo, school_id, school_website, school_motto)
                    VALUES
                    (:profile, :school_name, :school_address, :school_phone, :school_email, :school_logo, :school_id, :school_website, :school_motto)");
                $stmt->execute([
                    'profile' => '',
                    'school_name' => $school_name,
                    'school_address' => $school_address,
                    'school_phone' => $school_phone,
                    'school_email' => $school_email,
                    'school_logo' => $school_logo ?? '',
                    'school_id' => $current_school_id,
                    'school_website' => $school_website,
                    'school_motto' => $school_motto
                ]);
            }

            $success = 'School profile updated successfully.';
        }
    }

    // Add other actions like managing academic years, classes, etc., here...
}

// Fetch School Profile
$stmt = $pdo->prepare("SELECT * FROM school_profile WHERE school_id = ? LIMIT 1");
$stmt->execute([$current_school_id]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$school) {
    $school = [
        'school_name' => '',
        'school_address' => '',
        'school_phone' => '',
        'school_email' => '',
        'school_website' => '',
        'school_motto' => '',
        'school_logo' => ''
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage School | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="stylesheet" href="../assets/css/manage-school.css">
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
                        <a href="manage-school.php" class="nav-link active">
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
            <div class="content-header">
                <div class="welcome-section">
                    <h2>🏫 Manage School Settings</h2>
                    <p>Update your school profile and configuration</p>
                </div>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- School Information -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-info-circle"></i> School Information</h2>
                </div>
                <div class="panel-body">
                    <div class="stats-container">
                        <div class="stat-card">
                            <i class="fas fa-building"></i>
                            <h3>School Name</h3>
                            <div class="count"><?php echo htmlspecialchars($school['school_name'] ?: 'N/A'); ?></div>
                            <p class="stat-description">Registered name</p>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-phone"></i>
                            <h3>Phone</h3>
                            <div class="count"><?php echo htmlspecialchars($school['school_phone'] ?: 'N/A'); ?></div>
                            <p class="stat-description">Primary contact</p>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-envelope"></i>
                            <h3>Email</h3>
                            <div class="count"><?php echo htmlspecialchars($school['school_email'] ?: 'N/A'); ?></div>
                            <p class="stat-description">Official email</p>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-globe"></i>
                            <h3>Website</h3>
                            <div class="count"><?php echo htmlspecialchars($school['school_website'] ?: 'N/A'); ?></div>
                            <p class="stat-description">Public site</p>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; color:#64748b;">
                        <strong>Address:</strong> <?php echo htmlspecialchars($school['school_address'] ?: 'N/A'); ?><br>
                        <strong>Motto:</strong> <?php echo htmlspecialchars($school['school_motto'] ?: 'N/A'); ?>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="panel" style="margin-bottom: 2rem;">
                <div class="panel-header">
                    <h2><i class="fas fa-link"></i> Quick Links</h2>
                </div>
                <div class="panel-body">
                    <div class="stats-container">
                        <a href="sessions.php" class="stat-card" style="text-decoration: none;">
                            <i class="fas fa-calendar-alt"></i>
                            <h3>Academic Sessions</h3>
                            <div class="count">Open</div>
                            <p class="stat-description">Manage sessions</p>
                        </a>
                        <a href="school_calendar.php" class="stat-card" style="text-decoration: none;">
                            <i class="fas fa-calendar-check"></i>
                            <h3>Term Dates</h3>
                            <div class="count">Open</div>
                            <p class="stat-description">Manage calendar</p>
                        </a>
                        <a href="manage_class.php" class="stat-card" style="text-decoration: none;">
                            <i class="fas fa-users"></i>
                            <h3>Classes</h3>
                            <div class="count">Open</div>
                            <p class="stat-description">Manage classes</p>
                        </a>
                    </div>
                </div>
            </section>

            <!-- School Profile Form -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-school"></i> School Profile</h2>
                </div>
                <div class="panel-body">
                    <form method="POST" enctype="multipart/form-data" class="filters-form">
                        <input type="hidden" name="action" value="update_school_profile">

                        <div class="stats-grid">
                            <div class="form-group">
                                <label for="school_name"><i class="fas fa-building"></i> School Name *</label>
                                <input type="text" id="school_name" name="school_name" class="form-control"
                                       value="<?php echo htmlspecialchars($school['school_name']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="school_phone"><i class="fas fa-phone"></i> Phone *</label>
                                <input type="text" id="school_phone" name="school_phone" class="form-control"
                                       value="<?php echo htmlspecialchars($school['school_phone']); ?>" required>
                            </div>

                            <div class="form-group form-group-full">
                                <label for="school_address"><i class="fas fa-map-marker-alt"></i> Address *</label>
                                <textarea id="school_address" name="school_address" class="form-control" rows="3" required><?php echo htmlspecialchars($school['school_address']); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="school_email"><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" id="school_email" name="school_email" class="form-control"
                                       value="<?php echo htmlspecialchars($school['school_email']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="school_website"><i class="fas fa-globe"></i> Website</label>
                                <input type="url" id="school_website" name="school_website" class="form-control"
                                       value="<?php echo htmlspecialchars($school['school_website']); ?>">
                            </div>

                            <div class="form-group form-group-full">
                                <label for="school_motto"><i class="fas fa-quote-left"></i> Motto</label>
                                <textarea id="school_motto" name="school_motto" class="form-control" rows="2"><?php echo htmlspecialchars($school['school_motto']); ?></textarea>
                            </div>

                            <div class="form-group form-group-full">
                                <label for="school_logo"><i class="fas fa-image"></i> School Logo</label>
                                <input type="file" id="school_logo" name="school_logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                                <?php if (!empty($school['school_logo'])): ?>
                                    <div class="image-preview">
                                        <img src="../<?php echo htmlspecialchars($school['school_logo']); ?>" alt="School Logo">
                                        <p class="image-caption">Current logo</p>
                                    </div>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.75rem;">
                                        <input type="checkbox" name="remove_logo" value="1">
                                        Remove current logo
                                    </label>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-top:1.5rem;">
                            <button type="submit" class="btn primary">
                                <i class="fas fa-save"></i>
                                Update Profile
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>

    


    <?php include '../includes/floating-button.php'; ?>
</body>
</html>



