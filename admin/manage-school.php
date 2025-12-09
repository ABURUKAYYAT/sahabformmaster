<?php
// filepath: c:\xampp\htdocs\sahabformmaster\admin\manage-school.php
session_start();
require_once '../config/db.php';

// Only allow principal (admin) to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_name = $_SESSION['full_name'];
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
            // Update school profile in the database
            $stmt = $pdo->prepare("UPDATE school_profile SET 
                school_name = :school_name, 
                school_address = :school_address, 
                school_phone = :school_phone, 
                school_email = :school_email, 
                school_website = :school_website, 
                school_motto = :school_motto, 
                school_logo = COALESCE(:school_logo, school_logo)
                WHERE id = 1");
            $stmt->execute([
                'school_name' => $school_name,
                'school_address' => $school_address,
                'school_phone' => $school_phone,
                'school_email' => $school_email,
                'school_website' => $school_website,
                'school_motto' => $school_motto,
                'school_logo' => $school_logo
            ]);
            $success = 'School profile updated successfully.';
        }
    }

    // Add other actions like managing academic years, classes, etc., here...
}

// Fetch School Profile
$stmt = $pdo->query("SELECT * FROM school_profile WHERE id = 1");
$school = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$school) {
    $school = [
        'school_name' => '',
        'school_address' => '',
        'school_phone' => '',
        'school_email' => '',
        'school_website' => '',
        'school_motto' => '',
        'school_logo' => null
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Manage School | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/manage-school.css">
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

    <main class="main-content">
        <div class="content-header">
            <h2>Manage School</h2>
            <p class="small-muted">Update school profile and settings</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- School Profile Form -->
        <section class="school-profile-section">
            <div class="profile-card">
                <h3>🏫 School Profile</h3>
                <form method="POST" enctype="multipart/form-data" class="profile-form">
                    <input type="hidden" name="action" value="update_school_profile">

                    <div class="form-group">
                        <label for="school_name">School Name *</label>
                        <input type="text" id="school_name" name="school_name" class="form-control" 
                               value="<?php echo htmlspecialchars($school['school_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="school_address">Address *</label>
                        <textarea id="school_address" name="school_address" class="form-control" rows="3" required><?php echo htmlspecialchars($school['school_address']); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="school_phone">Phone *</label>
                                <input type="text" id="school_phone" name="school_phone" class="form-control" 
                                       value="<?php echo htmlspecialchars($school['school_phone']); ?>" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="school_email">Email</label>
                                <input type="email" id="school_email" name="school_email" class="form-control" 
                                       value="<?php echo htmlspecialchars($school['school_email']); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="school_website">Website</label>
                        <input type="url" id="school_website" name="school_website" class="form-control" 
                               value="<?php echo htmlspecialchars($school['school_website']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="school_motto">Motto</label>
                        <textarea id="school_motto" name="school_motto" class="form-control" rows="2"><?php echo htmlspecialchars($school['school_motto']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="school_logo">School Logo</label>
                        <input type="file" id="school_logo" name="school_logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                        <?php if ($school['school_logo']): ?>
                            <div class="image-preview">
                                <img src="../<?php echo htmlspecialchars($school['school_logo']); ?>" alt="School Logo">
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn-gold">Update Profile</button>
                </form>
            </div>
        </section>
    </main>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Professional school management system.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php">Dashboard</a>
                    <a href="schoolnews.php">News Management</a>
                    <a href="manage_user.php">Users</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p>Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Version 1.0</p>
        </div>
    </div>
</footer>

</body>
</html>