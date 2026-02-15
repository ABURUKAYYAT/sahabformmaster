<?php
// student/photo_details.php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];

// Get parameters from URL
$type = isset($_GET['type']) ? $_GET['type'] : '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (empty($type) || $id <= 0 || !in_array($type, ['student', 'teacher'])) {
    header("Location: photo_album.php");
    exit;
}

// Initialize variables
$person_data = null;
$page_title = '';
$back_link = 'photo_album.php';

if ($type === 'student') {
    // Fetch student data
    $query = "SELECT id, full_name, admission_no, passport_photo, gender, phone, address,
                     guardian_name, guardian_phone, guardian_email, guardian_relation,
                     dob, enrollment_date, student_type, blood_group, medical_conditions, allergies,
                     class_id
              FROM students
              WHERE id = ? AND is_active = 1";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);
    $person_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($person_data) {
        $page_title = htmlspecialchars($person_data['full_name']) . ' | Student Profile';
    }
} elseif ($type === 'teacher') {
    // Fetch teacher data
    $query = "SELECT id, full_name, designation, department, qualification, profile_image,
                     date_of_birth, date_employed, phone, address, emergency_contact, emergency_phone
              FROM users
              WHERE id = ? AND role = 'teacher' AND is_active = 1";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);
    $person_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($person_data) {
        $page_title = htmlspecialchars($person_data['full_name']) . ' | Teacher Profile';
    }
}

if (!$person_data) {
    header("Location: photo_album.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* Photo Details Page Styles */

        .profile-header {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border-radius: 1rem;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .profile-header-content {
            padding: 3rem 2rem;
            text-align: center;
        }

        .back-to-album-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 0.75rem;
            font-size: 0.95rem;
            font-weight: 500;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }

        .back-to-album-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            text-decoration: none;
            color: white;
        }

        .profile-avatar-large {
            width: 150px;
            height: 150px;
            margin: 0 auto 2rem;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .gender-badge-large {
            position: absolute;
            bottom: -8px;
            right: -8px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            font-weight: bold;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .profile-name-large {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            font-family: 'Poppins', sans-serif;
        }

        .profile-role {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 1rem;
        }

        .profile-id {
            font-size: 1rem;
            opacity: 0.8;
            font-weight: 500;
        }

        /* Information Sections */
        .info-sections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .info-section {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.8);
        }

        .info-section-header {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            color: white;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .info-section-header i {
            font-size: 1.2rem;
        }

        .info-section-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
        }

        .info-section-body {
            padding: 2rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .info-item {
            background: #f8f9fa;
            padding: 1.25rem;
            border-radius: 0.75rem;
            border-left: 4px solid #6366f1;
            transition: all 0.3s ease;
        }

        .info-item:hover {
            background: #e9ecef;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .info-label {
            display: block;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            color: #6c757d;
            font-size: 1rem;
            line-height: 1.5;
        }

        /* Special sections for students */
        .medical-section .info-section-header {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .guardian-section .info-section-header {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        /* Emergency contact section for teachers */
        .emergency-section .info-section-header {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .info-sections-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .profile-header-content {
                padding: 2rem 1.5rem;
            }

            .profile-avatar-large {
                width: 120px;
                height: 120px;
            }

            .profile-name-large {
                font-size: 2rem;
            }

            .profile-role {
                font-size: 1.1rem;
            }

            .info-section-body {
                padding: 1.5rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        @media (max-width: 480px) {
            .profile-header-content {
                padding: 1.5rem 1rem;
            }

            .profile-name-large {
                font-size: 1.75rem;
            }

            .profile-avatar-large {
                width: 100px;
                height: 100px;
                margin-bottom: 1.5rem;
            }

            .gender-badge-large {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .back-to-album-btn {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
        }

        /* Loading animation */
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
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
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
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
            <!-- Profile Header -->
            <div class="profile-header fade-in">
                <div class="profile-header-content">
                    <a href="photo_album.php" class="back-to-album-btn">
                        <i class="fas fa-arrow-left"></i>
                        Back to Photo Album
                    </a>

                    <div class="profile-avatar-large">
                        <img src="<?php
                            if ($type === 'student') {
                                echo htmlspecialchars($person_data['passport_photo'] ?: '../assets/images/default-avatar.png');
                            } else {
                                echo htmlspecialchars($person_data['profile_image'] ?: '../assets/images/default-avatar.png');
                            }
                        ?>" alt="<?php echo htmlspecialchars($person_data['full_name']); ?>">
                        <?php if ($type === 'student' && isset($person_data['gender'])): ?>
                            <div class="gender-badge-large <?php echo $person_data['gender'] === 'Male' ? 'gender-male' : 'gender-female'; ?>">
                                <?php echo $person_data['gender'] === 'Male' ? '♂' : '♀'; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h1 class="profile-name-large"><?php echo htmlspecialchars($person_data['full_name']); ?></h1>
                    <div class="profile-role">
                        <?php
                        if ($type === 'student') {
                            echo htmlspecialchars($person_data['student_type'] ?: 'Student');
                        } else {
                            echo htmlspecialchars($person_data['designation'] ?: 'Teacher');
                        }
                        ?>
                    </div>
                    <div class="profile-id">
                        <?php
                        if ($type === 'student') {
                            echo 'Admission No: ' . htmlspecialchars($person_data['admission_no']);
                        } else {
                            echo htmlspecialchars($person_data['designation'] ?: 'Teacher');
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Information Sections -->
            <div class="info-sections-grid">
                <!-- Personal Information -->
                <div class="info-section fade-in">
                    <div class="info-section-header">
                        <i class="fas fa-user"></i>
                        <h3>Personal Information</h3>
                    </div>
                    <div class="info-section-body">
                        <div class="info-grid">
                            <?php if ($type === 'student'): ?>
                                <div class="info-item">
                                    <span class="info-label">Gender</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['gender'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Date of Birth</span>
                                    <span class="info-value"><?php echo $person_data['dob'] ? date('M j, Y', strtotime($person_data['dob'])) : 'Not specified'; ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Student Type</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['student_type'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Blood Group</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['blood_group'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Enrollment Date</span>
                                    <span class="info-value"><?php echo $person_data['enrollment_date'] ? date('M j, Y', strtotime($person_data['enrollment_date'])) : 'Not specified'; ?></span>
                                </div>
                            <?php else: ?>
                                <div class="info-item">
                                    <span class="info-label">Department</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['department'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Qualification</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['qualification'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Date of Birth</span>
                                    <span class="info-value"><?php echo $person_data['date_of_birth'] ? date('M j, Y', strtotime($person_data['date_of_birth'])) : 'Not specified'; ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Date Employed</span>
                                    <span class="info-value"><?php echo $person_data['date_employed'] ? date('M j, Y', strtotime($person_data['date_employed'])) : 'Not specified'; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="info-section fade-in">
                    <div class="info-section-header">
                        <i class="fas fa-phone"></i>
                        <h3>Contact Information</h3>
                    </div>
                    <div class="info-section-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Phone</span>
                                <span class="info-value"><?php echo htmlspecialchars($person_data['phone'] ?: 'Not specified'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Address</span>
                                <span class="info-value"><?php echo htmlspecialchars($person_data['address'] ?: 'Not specified'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($type === 'student'): ?>
                    <!-- Guardian Information -->
                    <div class="info-section guardian-section fade-in">
                        <div class="info-section-header">
                            <i class="fas fa-user-friends"></i>
                            <h3>Guardian Information</h3>
                        </div>
                        <div class="info-section-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Name</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['guardian_name'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Phone</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['guardian_phone'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['guardian_email'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Relation</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['guardian_relation'] ?: 'Not specified'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Medical Information -->
                    <div class="info-section medical-section fade-in">
                        <div class="info-section-header">
                            <i class="fas fa-heartbeat"></i>
                            <h3>Medical Information</h3>
                        </div>
                        <div class="info-section-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Conditions</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['medical_conditions'] ?: 'None specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Allergies</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['allergies'] ?: 'None specified'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Emergency Contact -->
                    <div class="info-section emergency-section fade-in">
                        <div class="info-section-header">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3>Emergency Contact</h3>
                        </div>
                        <div class="info-section-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Name</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['emergency_contact'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Phone</span>
                                    <span class="info-value"><?php echo htmlspecialchars($person_data['emergency_phone'] ?: 'Not specified'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>



    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
