<?php
// mysubjects.php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}


$student_id = $_SESSION['student_id'];
$message = '';
$subjects = [];
$class_name = '';

try {
    // First, get the student's class ID from the students table
    $stmt = $pdo->prepare("SELECT class_id FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    
    if ($stmt->rowCount() === 0) {
        $message = "Student profile not found. Please contact administration.";
    } else {
        $student_data = $stmt->fetch();
        $class_id = $student_data['class_id'];
        
        // Get class name
        $class_stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
        $class_stmt->execute([$class_id]);
        
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
            FROM subjects s
            LEFT JOIN subject_assignments sa ON s.id = sa.subject_id AND sa.class_id = :class_id
            LEFT JOIN users u ON sa.teacher_id = u.id
            WHERE sa.class_id = :class_id
            ORDER BY s.subject_name
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([':class_id' => $class_id]);
        
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

// Alternative approach: If you want to show ALL subjects available for the class (not just assigned ones)
// Uncomment the code below if you want to show all subjects

/*
try {
    // Get all subjects (without teacher assignment check)
    $query = "
        SELECT 
            s.id AS subject_id,
            s.subject_name,
            s.subject_code,
            s.description AS subject_description,
            sa.teacher_id,
            u.full_name AS teacher_name,
            u.email AS teacher_email,
            sa.assigned_at
        FROM subjects s
        LEFT JOIN subject_assignments sa ON s.id = sa.subject_id AND sa.class_id = :class_id
        LEFT JOIN users u ON sa.teacher_id = u.id
        WHERE EXISTS (
            SELECT 1 FROM subject_assignments sa2 
            WHERE sa2.subject_id = s.id AND sa2.class_id = :class_id
        )
        ORDER BY s.subject_name
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([':class_id' => $class_id]);
    
    if ($stmt->rowCount() > 0) {
        $subjects = $stmt->fetchAll();
    } else {
        $message = "No subjects available for your class.";
    }
} catch (PDOException $e) {
    $message = "Error fetching data: " . $e->getMessage();
}
*/
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Subjects - Student Portal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: linear-gradient(135deg, #4b6cb7 0%, #182848 100%);
            color: white;
            padding: 25px 0;
            margin-bottom: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }
        
        h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .class-info {
            font-size: 18px;
            opacity: 0.9;
            margin-bottom: 15px;
        }
        
        .subjects-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .subjects-header {
            background: #f8f9fa;
            padding: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .subjects-header h2 {
            color: #2c3e50;
            font-size: 22px;
            margin-bottom: 5px;
        }
        
        .subjects-count {
            color: #6c757d;
            font-size: 16px;
        }
        
        .message {
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            background-color: #e7f3ff;
            border-left: 4px solid #4b6cb7;
            color: #2c3e50;
        }
        
        .message.error {
            background-color: #ffeaea;
            border-left-color: #e74c3c;
            color: #c0392b;
        }
        
        .message.success {
            background-color: #e8f6ef;
            border-left-color: #27ae60;
            color: #1e8449;
        }
        
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            padding: 30px;
        }
        
        .subject-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
        }
        
        .subject-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .subject-header {
            background: linear-gradient(135deg, #3498db 0%, #2c3e50 100%);
            color: white;
            padding: 20px;
        }
        
        .subject-code {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .subject-name {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .subject-description {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.5;
        }
        
        .subject-body {
            padding: 20px;
        }
        
        .teacher-info {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f1f1f1;
        }
        
        .teacher-icon {
            width: 50px;
            height: 50px;
            background: #f8f9fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: #4b6cb7;
            font-size: 20px;
            font-weight: bold;
        }
        
        .teacher-details h4 {
            color: #2c3e50;
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .teacher-email {
            color: #6c757d;
            font-size: 14px;
        }
        
        .subject-meta {
            display: flex;
            justify-content: space-between;
            color: #6c757d;
            font-size: 14px;
            margin-top: 10px;
        }
        
        .no-teacher {
            color: #e74c3c;
            font-style: italic;
            padding: 10px;
            background: #fff5f5;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .no-subjects {
            text-align: center;
            padding: 50px 20px;
            color: #6c757d;
            font-size: 18px;
        }
        
        .no-subjects i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #bdc3c7;
        }
        
        footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: #6c757d;
            font-size: 14px;
            border-top: 1px solid #e9ecef;
        }
        
        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #4b6cb7;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            transition: background 0.3s;
        }
        
        .back-btn:hover {
            background: #3a5699;
        }
        
        @media (max-width: 768px) {
            .subjects-grid {
                grid-template-columns: 1fr;
                padding: 20px;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .class-info {
                font-size: 16px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <div>
                    <h1><i class="fas fa-book-open"></i> My Subjects</h1>
                    <p class="class-info">Class: <strong><?php echo htmlspecialchars($class_name); ?></strong></p>
                </div>
                <div>
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </header>
        
        <div class="subjects-container">
            <div class="subjects-header">
                <h2><i class="fas fa-list-alt"></i> Subjects List</h2>
                <p class="subjects-count">Total Subjects: <strong><?php echo count($subjects); ?></strong></p>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="message <?php echo strpos($message, 'Error') !== false ? 'error' : ''; ?>">
                    <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (empty($subjects)): ?>
                <div class="no-subjects">
                    <i class="fas fa-book"></i>
                    <h3>No Subjects Found</h3>
                    <p>There are currently no subjects assigned to your class.</p>
                    <p>Please check back later or contact your class teacher.</p>
                </div>
            <?php else: ?>
                <div class="subjects-grid">
                    <?php foreach ($subjects as $subject): ?>
                        <div class="subject-card">
                            <div class="subject-header">
                                <div class="subject-code"><?php echo htmlspecialchars($subject['subject_code'] ?? 'No Code'); ?></div>
                                <h3 class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></h3>
                                <?php if (!empty($subject['subject_description'])): ?>
                                    <p class="subject-description"><?php echo htmlspecialchars($subject['subject_description']); ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="subject-body">
                                <?php if (!empty($subject['teacher_name'])): ?>
                                    <div class="teacher-info">
                                        <div class="teacher-icon">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                        </div>
                                        <div class="teacher-details">
                                            <h4><?php echo htmlspecialchars($subject['teacher_name']); ?></h4>
                                            <p class="teacher-email">
                                                <i class="fas fa-envelope"></i> 
                                                <?php echo htmlspecialchars($subject['teacher_email'] ?? 'No email'); ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="no-teacher">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        No teacher assigned to this subject yet
                                    </div>
                                <?php endif; ?>
                                
                                <div class="subject-meta">
                                    <div>
                                        <i class="fas fa-calendar-alt"></i> 
                                        Assigned: <?php echo !empty($subject['assigned_at']) ? date('M d, Y', strtotime($subject['assigned_at'])) : 'Not available'; ?>
                                    </div>
                                    <div>
                                        Subject ID: #<?php echo htmlspecialchars($subject['subject_id']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <footer>
            <p>Sahab Academy &copy; <?php echo date('Y'); ?> | Student Portal - My Subjects</p>
            <p>If you notice any discrepancies, please contact the administration.</p>
        </footer>
    </div>
    
    <script>
        // Add some interactive functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Add click animation to subject cards
            const subjectCards = document.querySelectorAll('.subject-card');
            subjectCards.forEach(card => {
                card.addEventListener('click', function() {
                    this.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 200);
                });
            });
            
            // Show notification if there are subjects without teachers
            const noTeacherDivs = document.querySelectorAll('.no-teacher');
            if (noTeacherDivs.length > 0) {
                console.log(`${noTeacherDivs.length} subject(s) do not have assigned teachers.`);
            }
        });
    </script>
</body>
</html>