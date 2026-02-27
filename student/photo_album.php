<?php
// student/photo_album.php
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

// Get current student's class information
$stmt = $pdo->prepare("SELECT s.class_id, c.class_name FROM students s JOIN classes c ON s.class_id = c.id AND c.school_id = ? WHERE s.id = ? AND s.school_id = ?");
$stmt->execute([$current_school_id, $student_id, $current_school_id]);
$current_student = $stmt->fetch();

if (!$current_student) {
    header("Location: dashboard.php");
    exit;
}

$class_id = $current_student['class_id'];
$class_name = $current_student['class_name'];

// Get all active teachers in the school
$teachers_query = "SELECT id, full_name, designation, department, qualification, profile_image,
                          date_of_birth, date_employed, phone, address, emergency_contact, emergency_phone
                   FROM users
                   WHERE role = 'teacher' AND is_active = 1 AND school_id = ?
                   ORDER BY full_name ASC";
$teachers_stmt = $pdo->prepare($teachers_query);
$teachers_stmt->execute([$current_school_id]);
$teachers = $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all active students in the same class with full details
$query = "SELECT id, full_name, admission_no, passport_photo, gender, phone, address,
                 guardian_name, guardian_phone, guardian_email, guardian_relation,
                 dob, enrollment_date, student_type, blood_group, medical_conditions, allergies
          FROM students
          WHERE class_id = ? AND is_active = 1 AND school_id = ?
          ORDER BY full_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute([$class_id, $current_school_id]);
$classmates = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Photo Album | <?php echo htmlspecialchars($class_name); ?> | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* Photo Album Page Styles - Modern Design with Sidebar Layout */

        /* Welcome Section - Hero Banner */
        .photo-album-welcome {
            margin-bottom: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 1rem;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .photo-album-welcome .card-body {
            padding: 2.5rem;
            text-align: center;
        }

        .photo-album-welcome h2 {
            margin-bottom: 0.75rem;
            font-family: 'Poppins', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
        }

        .photo-album-welcome h2 i {
            margin-right: 0.75rem;
            opacity: 0.9;
        }

        .photo-album-welcome p {
            margin: 0;
            opacity: 0.9;
            font-size: 1.1rem;
            font-weight: 400;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.8);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-card-blue {
            border-left: 4px solid #007bff;
        }

        .stat-card-green {
            border-left: 4px solid #28a745;
        }

        .stat-card-yellow {
            border-left: 4px solid #ffc107;
        }

        .stat-card .card-body {
            padding: 2rem;
            text-align: center;
        }

        .stat-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            background: linear-gradient(135deg, rgba(0, 123, 255, 0.1), rgba(0, 123, 255, 0.2));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: #007bff;
        }

        .stat-card-green .stat-icon {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.2));
            color: #28a745;
        }

        .stat-card-yellow .stat-icon {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(255, 193, 7, 0.2));
            color: #ffc107;
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            color: #004085;
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-card-green .stat-number {
            color: #155724;
        }

        .stat-card-yellow .stat-number {
            color: #856404;
        }

        .stat-label {
            margin: 0;
            color: #004085;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }

        .stat-card-green .stat-label {
            color: #155724;
        }

        .stat-card-yellow .stat-label {
            color: #856404;
        }

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.8);
        }

        .section-header {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white;
            padding: 1.5rem 2rem;
            border: none;
        }

        .section-header h4 {
            margin: 0 0 0.5rem 0;
            font-family: 'Poppins', sans-serif;
            font-size: 1.4rem;
            font-weight: 600;
        }

        .section-header h4 i {
            margin-right: 0.75rem;
        }

        .section-header small {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .students-section .section-header {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
        }

        .section-body {
            padding: 2.5rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }

        .empty-state h5 {
            font-size: 1.3rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .empty-state p {
            font-size: 1rem;
            line-height: 1.6;
            margin: 0;
        }

        /* Photo Grid */
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }

        .photo-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid #0ea5e9;
        }

        .photo-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(14, 165, 233, 0.3);
            border-color: #0284c7;
        }

        .students-section .photo-card {
            border-color: #6366f1;
        }

        .students-section .photo-card:hover {
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
            border-color: #8b5cf6;
        }

        .photo-card-body {
            padding: 1.5rem;
            text-align: center;
        }

        /* Avatar Container */
        .avatar-container {
            position: relative;
            width: 100px;
            height: 100px;
            margin: 0 auto 1.5rem;
        }

        .avatar-bg {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .students-section .avatar-bg {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
        }

        .avatar-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        /* Gender Badge */
        .gender-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .gender-male {
            background: #007bff;
        }

        .gender-female {
            background: #e83e8c;
        }

        /* Student Info */
        .student-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
        }

        .student-admission {
            color: #6c757d;
            margin: 0;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .teacher-designation {
            color: #6c757d;
            margin: 0 0 0.5rem 0;
            font-size: 0.9rem;
        }

        .teacher-department {
            color: #6c757d;
            font-size: 0.8rem;
        }

        /* Modal Styles */
        .photo-modal .modal-content {
            border-radius: 1rem;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .photo-modal .modal-header {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            border: none;
            border-radius: 1rem 1rem 0 0;
            padding: 1.5rem;
        }

        .photo-modal .modal-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }
        
        .photo-modal .close {
            color: white;
            opacity: 0.8;
            font-size: 1.5rem;
        }

        .photo-modal .close:hover {
            opacity: 1;
        }

        .modal-photo-container {
            text-align: center;
            margin-bottom: 2rem;
        }

        .modal-photo {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 1rem;
            border: 4px solid #6366f1;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .modal-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .info-section h6 {
            color: #6366f1;
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .info-item {
            margin-bottom: 0.75rem;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.25rem;
        }

        .info-value {
            color: #6c757d;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .photo-album-welcome .card-body {
                padding: 2rem 1.5rem;
            }

            .photo-album-welcome h2 {
                font-size: 1.8rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
                margin-bottom: 2rem;
            }

            .photo-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1.5rem;
            }

            .section-body {
                padding: 2rem 1.5rem;
            }

            .avatar-container {
                width: 80px;
                height: 80px;
            }

            .stat-card .card-body {
                padding: 1.5rem;
            }

            .stat-icon {
                width: 60px;
                height: 60px;
                font-size: 2rem;
            }

            .stat-number {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 480px) {
            .photo-grid {
                grid-template-columns: 1fr;
            }

            .photo-album-welcome .card-body {
                padding: 1.5rem 1rem;
            }

            .photo-album-welcome h2 {
                font-size: 1.5rem;
            }

            .section-body {
                padding: 1.5rem 1rem;
            }

            .section-header {
                padding: 1.25rem 1.5rem;
            }

            .section-header h4 {
                font-size: 1.2rem;
            }

            .empty-state {
                padding: 3rem 1rem;
            }

            .empty-state i {
                font-size: 3rem;
            }

            .modal-info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        /* Mobile Menu Toggle Animation */
        .mobile-menu-toggle {
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle.active {
            background: #ef4444;
        }

        /* Sidebar Animation */
        .sidebar {
            transition: transform 0.3s ease;
        }

        /* Smooth Animations */
        .photo-card,
        .stat-card,
        .section-card {
            animation: fadeInUp 0.6s ease-out;
            animation-fill-mode: both;
        }

        .photo-card:nth-child(1) { animation-delay: 0.1s; }
        .photo-card:nth-child(2) { animation-delay: 0.2s; }
        .photo-card:nth-child(3) { animation-delay: 0.3s; }
        .photo-card:nth-child(4) { animation-delay: 0.4s; }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
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
            <!-- Welcome Section -->
            <div class="photo-album-welcome">
                <div class="card-body">
                    <h2><i class="fas fa-images"></i> Class Photo Album</h2>
                    <p><?php echo htmlspecialchars($class_name); ?> - Meet Your Classmates</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card stat-card-blue">
                    <div class="card-body">
                        <div class="stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-number"><?php echo count($teachers); ?></div>
                        <div class="stat-label">Teachers</div>
                    </div>
                </div>
                <div class="stat-card stat-card-green">
                    <div class="card-body">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-number"><?php echo count($classmates); ?></div>
                        <div class="stat-label">Classmates</div>
                    </div>
                </div>
                <div class="stat-card stat-card-yellow">
                    <div class="card-body">
                        <div class="stat-icon">
                            <i class="fas fa-camera"></i>
                        </div>
                        <div class="stat-number"><?php echo count(array_filter($classmates, fn($s) => !empty($s['passport_photo']))); ?></div>
                        <div class="stat-label">Photos Uploaded</div>
                    </div>
                </div>
            </div>

            <!-- Teachers Section -->
            <div class="section-card">
                <div class="section-header">
                    <h4><i class="fas fa-chalkboard-teacher"></i> Teaching Staff</h4>
                    <small>Our dedicated educators who inspire and guide us</small>
                </div>
                <div class="section-body">
                    <?php if (empty($teachers)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h5>No Teachers Found</h5>
                            <p>Teacher information will be displayed here once available.</p>
                        </div>
                    <?php else: ?>
                        <div class="photo-grid">
                            <?php foreach ($teachers as $teacher): ?>
                                <?php
                                $hasPhoto = !empty($teacher['profile_image']) && $teacher['profile_image'] !== '';
                                $photoSrc = $hasPhoto ? $teacher['profile_image'] : '../assets/images/default-avatar.png';
                                ?>
                                <div class="photo-card" onclick="window.location.href='photo_details.php?type=teacher&id=<?php echo $teacher['id']; ?>'">
                                    <div class="photo-card-body">
                                        <div class="avatar-container">
                                            <div class="avatar-bg">
                                                <img src="<?php echo htmlspecialchars($photoSrc); ?>"
                                                     alt="<?php echo htmlspecialchars($teacher['full_name']); ?>"
                                                     class="avatar-image">
                                            </div>
                                        </div>
                                        <div class="student-name"><?php echo htmlspecialchars($teacher['full_name']); ?></div>
                                        <div class="teacher-designation"><?php echo htmlspecialchars($teacher['designation'] ?: 'Teacher'); ?></div>
                                        <?php if ($teacher['department']): ?>
                                            <div class="teacher-department"><?php echo htmlspecialchars($teacher['department']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Students Section -->
            <div class="section-card students-section">
                <div class="section-header">
                    <h4><i class="fas fa-users"></i> Class Students</h4>
                    <small><?php echo htmlspecialchars($class_name); ?> - Meet Your Classmates</small>
                </div>
                <div class="section-body">
                    <?php if (empty($classmates)): ?>
                        <div class="empty-state">
                            <i class="fas fa-camera"></i>
                            <h5>No Photos Available</h5>
                            <p>It looks like no students in <?php echo htmlspecialchars($class_name); ?> have uploaded their photos yet.</p>
                            <p>Photos will appear here once students complete their profile setup.</p>
                        </div>
                    <?php else: ?>
                        <div class="photo-grid">
                            <?php foreach ($classmates as $student): ?>
                                <?php
                                $hasPhoto = !empty($student['passport_photo']) && $student['passport_photo'] !== '';
                                $photoSrc = $hasPhoto ? $student['passport_photo'] : '../assets/images/default-avatar.png';
                                ?>
                                <div class="photo-card" onclick="window.location.href='photo_details.php?type=student&id=<?php echo $student['id']; ?>'">
                                    <div class="photo-card-body">
                                        <div class="avatar-container">
                                            <div class="avatar-bg">
                                                <img src="<?php echo htmlspecialchars($photoSrc); ?>"
                                                     alt="<?php echo htmlspecialchars($student['full_name']); ?>"
                                                     class="avatar-image">
                                                <div class="gender-badge <?php echo $student['gender'] === 'Male' ? 'gender-male' : 'gender-female'; ?>">
                                                    <?php echo $student['gender'] === 'Male' ? '♂' : '♀'; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                        <div class="student-admission"><?php echo htmlspecialchars($student['admission_no']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>



    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Mobile Menu Toggle Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.querySelector('.sidebar');

            if (mobileMenuToggle && sidebar) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                    mobileMenuToggle.classList.toggle('active');
                });

                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function(event) {
                    if (!sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                        sidebar.classList.remove('active');
                        mobileMenuToggle.classList.remove('active');
                    }
                });

                // Close sidebar on window resize if desktop size
                window.addEventListener('resize', function() {
                    if (window.innerWidth > 1024) {
                        sidebar.classList.remove('active');
                        mobileMenuToggle.classList.remove('active');
                    }
                });
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
