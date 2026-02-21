<?php
// mysubjects.php
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
$current_school_id = get_current_school_id();
$message = '';
$subjects = [];
$class_name = '';

try {
    // First, get the student's class ID from the students table
    $stmt = $pdo->prepare("SELECT class_id FROM students WHERE id = ? AND school_id = ?");
    $stmt->execute([$student_id, $current_school_id]);
    
    if ($stmt->rowCount() === 0) {
        $message = "Student profile not found. Please contact administration.";
    } else {
        $student_data = $stmt->fetch();
        $class_id = $student_data['class_id'];
        
        // Get class name
        $class_stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
        $class_stmt->execute([$class_id, $current_school_id]);
        
        if ($class_stmt->rowCount() > 0) {
            $class_data = $class_stmt->fetch();
            $class_name = $class_data['class_name'];
        } else {
            $class_name = 'Unknown Class';
        }
        
        // Fetch subjects assigned to the student's class along with teacher information
        $query = "
            SELECT
                s.id AS subject_id,
                s.subject_name,
                s.subject_code,
                s.description AS subject_description,
                u.id AS teacher_id,
                u.full_name AS teacher_name,
                u.email AS teacher_email,
                sa.assigned_at
            FROM subject_assignments sa
            JOIN subjects s ON sa.subject_id = s.id AND s.school_id = :school_id
            LEFT JOIN users u ON sa.teacher_id = u.id
            WHERE sa.class_id = :class_id
            ORDER BY s.subject_name
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([':class_id' => $class_id, ':school_id' => $current_school_id]);
        
        if ($stmt->rowCount() > 0) {
            $subjects = $stmt->fetchAll();
        } else {
            $message = "No subjects have been assigned to your class yet.";
        }
    }
} catch (PDOException $e) {
    $message = "Error fetching data: " . $e->getMessage();
    error_log("Error in mysubjects.php: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Subjects - Student Portal</title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">`r`n<link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
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
                    <span class="admission-number"><?php echo htmlspecialchars($_SESSION['admission_no']); ?></span>
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
            <!-- Content Header -->
            <div class="content-header">
                <div class="welcome-section">
                    <h2><i class="fas fa-book-open"></i> My Subjects Overview</h2>
                    <p>View and manage your enrolled subjects for <?php echo htmlspecialchars($class_name); ?></p>
                </div>
            </div>

            <!-- Statistics -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">
                            <i class="fas fa-book-open"></i>
                        </div>
                    </div>
                    <div class="card-content">
                        <h3>Total Subjects</h3>
                        <div class="card-value"><?php echo count($subjects); ?></div>
                    </div>
                </div>
                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                    </div>
                    <div class="card-content">
                        <h3>Assigned Teachers</h3>
                        <div class="card-value"><?php echo count(array_filter($subjects, function($s) { return !empty($s['teacher_name']); })); ?></div>
                    </div>
                </div>
                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                    </div>
                    <div class="card-content">
                        <h3>Current Class</h3>
                        <div class="card-value"><?php echo htmlspecialchars($class_name); ?></div>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Subject Cards Grid -->
            <?php if (empty($subjects)): ?>
                <div class="card text-center">
                    <div class="card-body p-5">
                        <div class="text-muted mb-3" style="font-size: 3rem;">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <h4 class="text-muted mb-3">No Subjects Found</h4>
                        <p class="text-muted mb-0">There are currently no subjects assigned to your class.</p>
                        <p class="text-muted">Please check back later or contact your class teacher.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="dashboard-cards">
                    <?php
                    $gradients = ['card-gradient-1', 'card-gradient-2', 'card-gradient-3', 'card-gradient-4', 'card-gradient-5', 'card-gradient-6'];
                    foreach ($subjects as $index => $subject):
                        $gradient_class = $gradients[$index % count($gradients)];
                    ?>
                        <div class="card <?php echo $gradient_class; ?>">
                            <div class="card-icon-wrapper">
                                <div class="card-icon">
                                    <i class="fas fa-book"></i>
                                </div>
                            </div>
                            <div class="card-content">
                                <h3><?php echo htmlspecialchars($subject['subject_name']); ?></h3>
                                <p class="card-value"><?php echo htmlspecialchars($subject['subject_code'] ?? 'No Code'); ?></p>

                                <?php if (!empty($subject['subject_description'])): ?>
                                    <p class="text-muted small mb-3"><?php echo htmlspecialchars($subject['subject_description']); ?></p>
                                <?php endif; ?>

                                <div class="card-footer">
                                    <?php if (!empty($subject['teacher_name'])): ?>
                                        <div class="teacher-info mb-3">
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <div class="teacher-avatar">
                                                    <i class="fas fa-user-tie"></i>
                                                </div>
                                                <div>
                                                    <strong class="text-sm"><?php echo htmlspecialchars($subject['teacher_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-envelope"></i>
                                                        <?php echo htmlspecialchars($subject['teacher_email'] ?? 'No email'); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="card-badge">Active</span>
                                        <a href="mailto:<?php echo htmlspecialchars($subject['teacher_email'] ?? ''); ?>" class="card-link">Contact Teacher →</a>
                                    <?php else: ?>
                                        <span class="card-badge warning">Pending</span>
                                        <span class="text-muted small">No teacher assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>




