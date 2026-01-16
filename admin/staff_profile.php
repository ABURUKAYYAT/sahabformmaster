<?php
// admin/staff_profile.php
session_start();
require_once '../config/db.php';

// Only allow principal (admin) to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$admin_name = $_SESSION['full_name'] ?? 'Administrator';

// Get staff ID from URL
$staff_id = intval($_GET['id'] ?? 0);

if ($staff_id <= 0) {
    header("Location: manage_user.php");
    exit;
}

// Fetch staff details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $staff_id]);
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
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-header {
            background: linear-gradient(135deg, var(--gold) 0%, var(--dark-gold) 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 25px;
        }
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            border: 4px solid white;
        }
        .profile-info h1 {
            margin: 0 0 5px 0;
            font-size: 28px;
        }
        .profile-info p {
            margin: 0;
            opacity: 0.9;
        }
        .profile-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        .stat-box {
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 14px;
        }
        .profile-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .info-card {
            background: var(--white);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .info-card h3 {
            margin-top: 0;
            color: var(--dark-gold);
            border-bottom: 2px solid var(--light-gold);
            padding-bottom: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-row {
            display: flex;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-label {
            font-weight: 600;
            color: #666;
            width: 180px;
            flex-shrink: 0;
        }
        .info-value {
            color: #333;
            flex: 1;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-principal { background:#fff0e6; color:#e65c00; }
        .badge-teacher { background:#e6f7ff; color:#0066cc; }
        .badge-admin { background:#f0f0f0; color:#666; }
        .badge-support { background:#e6f9f0; color:#006633; }
        .btn-back {
            display: inline-block;
            padding: 10px 20px;
            background: var(--white);
            color: var(--dark-gold);
            border: 2px solid var(--gold);
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 20px;
            transition: all 0.3s;
        }
        .btn-back:hover {
            background: var(--gold);
            color: white;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        @media (max-width: 992px) {
            .profile-content {
                grid-template-columns: 1fr;
            }
        }
        .empty-field {
            color: #999;
            font-style: italic;
        }
    </style>
</head>
<body>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-right">
            <div class="school-logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                <h1 class="school-name">SahabFormMaster</h1>
            </div>
        </div>

        <div class="header-left">
            <div class="teacher-info">
                <span class="teacher-name"><?php echo htmlspecialchars($admin_name); ?></span>
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <ul class="nav-list">
                <li class="nav-item">
                    <a href="index.php" class="nav-link">
                        <span class="nav-icon">📊</span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_user.php" class="nav-link active">
                        <span class="nav-icon">👨‍🏫</span>
                        <span class="nav-text">Manage Staff</span>
                    </a>
                </li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <div class="profile-header">
            <div class="profile-avatar">
                <i class="fas fa-user-tie"></i>
            </div>
            <div class="profile-info">
                <h1><?php echo htmlspecialchars($staff['full_name']); ?></h1>
                <p>
                    <span class="badge <?php 
                        if ($staff['role'] === 'principal') echo 'badge-principal';
                        elseif ($staff['role'] === 'teacher') echo 'badge-teacher';
                        elseif ($staff['role'] === 'admin') echo 'badge-admin';
                        else echo 'badge-support';
                    ?>">
                        <?php echo htmlspecialchars(ucfirst($staff['role'])); ?>
                    </span>
                    • Staff ID: <?php echo htmlspecialchars($staff['staff_id'] ?? 'N/A'); ?>
                    • Username: @<?php echo htmlspecialchars($staff['username']); ?>
                </p>
                <p><?php echo htmlspecialchars($staff['designation'] ?? 'No designation set'); ?></p>
                <div class="profile-stats">
                    <div class="stat-box">
                        <i class="fas fa-file-alt"></i> <?php echo $doc_count; ?> Documents
                    </div>
                    <div class="stat-box">
                        <i class="fas fa-book"></i> <?php echo $lesson_count; ?> Lesson Plans
                    </div>
                    <div class="stat-box">
                        <i class="fas fa-calendar-alt"></i> <?php echo $years_of_service; ?> Years Service
                    </div>
                </div>
            </div>
        </div>

        <div class="profile-content">
            <!-- Personal Information -->
            <div class="info-card">
                <h3><i class="fas fa-user-circle"></i> Personal Information</h3>
                <div class="info-row">
                    <div class="info-label">Full Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($staff['full_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date of Birth:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['date_of_birth']) ? 
                            htmlspecialchars(date('F j, Y', strtotime($staff['date_of_birth']))) . " ($age years)" : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email Address:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['email']) ? 
                            '<a href="mailto:' . htmlspecialchars($staff['email']) . '">' . htmlspecialchars($staff['email']) . '</a>' : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Phone Number:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['phone']) ? 
                            '<a href="tel:' . htmlspecialchars($staff['phone']) . '">' . htmlspecialchars($staff['phone']) . '</a>' : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Address:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['address']) ? 
                            htmlspecialchars($staff['address']) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
            </div>

            <!-- Professional Information -->
            <div class="info-card">
                <h3><i class="fas fa-briefcase"></i> Professional Information</h3>
                <div class="info-row">
                    <div class="info-label">Designation:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['designation']) ? 
                            htmlspecialchars($staff['designation']) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Department:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['department']) ? 
                            htmlspecialchars(ucfirst($staff['department'])) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Qualification:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['qualification']) ? 
                            htmlspecialchars($staff['qualification']) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Teacher License:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['teacher_license']) ? 
                            htmlspecialchars($staff['teacher_license']) : 
                            '<span class="empty-field">Not applicable</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Employment Type:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['employment_type']) ? 
                            htmlspecialchars(ucfirst(str_replace('-', ' ', $staff['employment_type']))) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date Employed:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['date_employed']) ? 
                            htmlspecialchars(date('F j, Y', strtotime($staff['date_employed']))) . " ($years_of_service years)" : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
            </div>

            <!-- Emergency Contact -->
            <div class="info-card">
                <h3><i class="fas fa-phone-alt"></i> Emergency Contact</h3>
                <div class="info-row">
                    <div class="info-label">Contact Name:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['emergency_contact']) ? 
                            htmlspecialchars($staff['emergency_contact']) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Contact Phone:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['emergency_phone']) ? 
                            '<a href="tel:' . htmlspecialchars($staff['emergency_phone']) . '">' . htmlspecialchars($staff['emergency_phone']) . '</a>' : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
            </div>

            <!-- Financial Information -->
            <div class="info-card">
                <h3><i class="fas fa-university"></i> Financial Information</h3>
                <div class="info-row">
                    <div class="info-label">Bank Name:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['bank_name']) ? 
                            htmlspecialchars($staff['bank_name']) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Account Number:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['account_number']) ? 
                            htmlspecialchars($staff['account_number']) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Tax ID:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['tax_id']) ? 
                            htmlspecialchars($staff['tax_id']) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Pension Number:</div>
                    <div class="info-value">
                        <?php echo !empty($staff['pension_number']) ? 
                            htmlspecialchars($staff['pension_number']) : 
                            '<span class="empty-field">Not specified</span>'; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="action-buttons">
            <a href="manage_user.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Staff List
            </a>
            <a href="manage_user.php?edit=<?php echo $staff_id; ?>" class="btn-back" style="background: var(--gold); color: white;">
                <i class="fas fa-edit"></i> Edit Profile
            </a>
            <a href="staff_documents.php?staff_id=<?php echo $staff_id; ?>" class="btn-back" style="background: #0066cc; color: white; border-color: #0066cc;">
                <i class="fas fa-file-upload"></i> Manage Documents
            </a>
        </div>
    </main>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>Staff Profile</h4>
                <p>Detailed profile view for <?php echo htmlspecialchars($staff['full_name']); ?></p>
            </div>
            <div class="footer-section">
                <h4>Last Updated</h4>
                <p><?php echo htmlspecialchars(date('F j, Y', strtotime($staff['created_at']))); ?></p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
        </div>
    </div>
</footer>

</body>
</html>
