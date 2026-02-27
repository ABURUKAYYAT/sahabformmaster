<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only teachers
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// Get current school context
$current_school_id = require_school_auth();

$teacher_id = intval($_SESSION['user_id']);
$errors = [];
$success = '';

// Get student ID from URL
$student_id = intval($_GET['id'] ?? 0);
if ($student_id <= 0) {
    header("Location: students.php");
    exit;
}

// Fetch student details
$stmt = $pdo->prepare("
    SELECT s.*, c.class_name
    FROM students s
    JOIN classes c ON s.class_id = c.id
    WHERE s.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: students.php");
    exit;
}

// Verify teacher access
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM students s
    WHERE s.id = ?
    AND (
        EXISTS (SELECT 1 FROM subject_assignments sa WHERE sa.class_id = s.class_id AND sa.teacher_id = ?)
        OR EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = s.class_id AND ct.teacher_id = ?)
    )
");
$stmt->execute([$student_id, $teacher_id, $teacher_id]);
if ($stmt->fetchColumn() == 0) {
    header("Location: students.php");
    exit;
}

// Fetch classes assigned to teacher for dropdown
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name
    FROM classes c
    JOIN subject_assignments sa ON c.id = sa.class_id
    WHERE sa.teacher_id = :tid

    UNION

    SELECT DISTINCT c.id, c.class_name
    FROM classes c
    JOIN class_teachers ct ON c.id = ct.class_id
    WHERE ct.teacher_id = :tid2

    ORDER BY class_name
");
$stmt->execute(['tid'=>$teacher_id, 'tid2'=>$teacher_id]);
$assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = intval($_POST['class_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $admission_no = trim($_POST['admission_no'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['dob'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $guardian_name = trim($_POST['guardian_name'] ?? '');
    $guardian_phone = trim($_POST['guardian_phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $student_type = $_POST['student_type'] ?? 'fresh';

    // Validate
    if (empty($full_name)) $errors[] = 'Full name is required.';
    if (empty($admission_no)) $errors[] = 'Admission number is required.';
    if (empty($class_id)) $errors[] = 'Class is required.';

    // Check if admission number already exists (excluding current student, school-filtered)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ? AND id != ? AND school_id = ?");
    $stmt->execute([$admission_no, $student_id, $current_school_id]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = 'Admission number already exists.';
    }

    // Check teacher access to new class
    if ($class_id > 0) {
        $stmt = $pdo->prepare("
            SELECT CASE
                WHEN EXISTS (SELECT 1 FROM subject_assignments WHERE class_id = ? AND teacher_id = ?) THEN 'subject_teacher'
                WHEN EXISTS (SELECT 1 FROM class_teachers WHERE class_id = ? AND teacher_id = ?) THEN 'form_master'
                ELSE 'no_access'
            END as access_type
        ");
        $stmt->execute([$class_id, $teacher_id, $class_id, $teacher_id]);
        if ($stmt->fetchColumn() === 'no_access') {
            $errors[] = 'You are not assigned to this class.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE students
                SET class_id = ?, full_name = ?, admission_no = ?, gender = ?, dob = ?,
                    phone = ?, guardian_name = ?, guardian_phone = ?, address = ?, student_type = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $class_id, $full_name, $admission_no, $gender, $dob ?: null,
                $phone, $guardian_name, $guardian_phone, $address, $student_type, $student_id
            ]);

            // Add a note about update
            $pdo->prepare("INSERT INTO student_notes (student_id, teacher_id, note_text, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$student_id, $teacher_id, "Student information updated."]);

            $pdo->commit();
            $success = 'Student updated successfully.';

            // Refresh student data
            $stmt = $pdo->prepare("
                SELECT s.*, c.class_name
                FROM students s
                JOIN classes c ON s.class_id = c.id
                WHERE s.id = ?
            ");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error updating student: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/teacher-students.css">
</head>
<body style="background-color: #f5f7fb;">
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
                        <p class="school-tagline">Edit Student</p>
                    </div>
                </div>
            </div>

            <!-- Teacher Info and Logout -->
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include '../includes/teacher_sidebar.php'; ?>
        <main class="main-content">
            <div class="main-container">
            <!-- Breadcrumb -->
            <div style="margin-bottom: 1.5rem;">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb-modern">
                        <li style="display: flex; align-items: center; gap: 0.5rem;">
                            <a href="students.php" style="color: var(--primary-color); text-decoration: none;">
                                <i class="fas fa-users"></i> Students
                            </a>
                        </li>
                        <li style="color: var(--gray-400);">/</li>
                        <li style="color: var(--gray-700); font-weight: 500;">
                            <i class="fas fa-edit"></i> Edit <?php echo htmlspecialchars($student['full_name']); ?>
                        </li>
                    </ol>
                </nav>
            </div>

            <!-- Success/Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" style="margin-bottom: 1.5rem;">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Errors occurred:</strong>
                        <ul style="margin: 0.5rem 0 0 0; padding-left: 1.5rem;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success" style="margin-bottom: 1.5rem;">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Edit Form -->
            <div class="modern-card animate-fade-in-up">
                <div class="card-header-modern">
                    <h2 class="card-title-modern">
                        <i class="fas fa-user-edit"></i>
                        Edit Student Information
                    </h2>
                    <p class="card-subtitle-modern">
                        Update <?php echo htmlspecialchars($student['full_name']); ?>'s details
                    </p>
                </div>
                <div class="card-body-modern">

                <!-- Current Student Info Preview -->
                <div class="panel" style="background: var(--gray-50); border-left: 4px solid var(--primary-500);">
                    <h3 style="margin: 0 0 1rem 0; color: var(--primary-600); display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fas fa-user"></i> Current Student Details
                    </h3>
                    <div class="stats-grid">
                        <div class="info-card">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-user" style="color: var(--primary-600);"></i>
                                <strong>Name</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['full_name']); ?></p>
                        </div>
                        <div class="info-card">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-id-card" style="color: var(--primary-600);"></i>
                                <strong>Admission No</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['admission_no']); ?></p>
                        </div>
                        <div class="info-card">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-school" style="color: var(--primary-600);"></i>
                                <strong>Class</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['class_name']); ?></p>
                        </div>
                        <div class="info-card">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-venus-mars" style="color: var(--primary-600);"></i>
                                <strong>Gender</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['gender']); ?></p>
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="class_id">
                                <i class="fas fa-school"></i> Class *
                            </label>
                            <select id="class_id" name="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach($assigned_classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"
                                        <?php echo $c['id'] == $student['class_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="full_name">
                                <i class="fas fa-user"></i> Full Name *
                            </label>
                            <input type="text" id="full_name" name="full_name" class="form-control"
                                   value="<?php echo htmlspecialchars($student['full_name']); ?>" required
                                   placeholder="Enter full name">
                        </div>

                        <div class="form-group">
                            <label for="admission_no">
                                <i class="fas fa-id-card"></i> Admission Number *
                            </label>
                            <input type="text" id="admission_no" name="admission_no" class="form-control"
                                   value="<?php echo htmlspecialchars($student['admission_no']); ?>" required
                                   placeholder="Enter admission number">
                        </div>

                        <div class="form-group">
                            <label for="gender">
                                <i class="fas fa-venus-mars"></i> Gender
                            </label>
                            <select id="gender" name="gender" class="form-control">
                                <option value="">-- Select Gender --</option>
                                <option value="Male" <?php echo $student['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $student['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo $student['gender'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="dob">
                                <i class="fas fa-birthday-cake"></i> Date of Birth
                            </label>
                            <input type="date" id="dob" name="dob" class="form-control"
                                   value="<?php echo $student['dob'] ? date('Y-m-d', strtotime($student['dob'])) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="phone">
                                <i class="fas fa-phone"></i> Phone Number
                            </label>
                            <input type="tel" id="phone" name="phone" class="form-control"
                                   value="<?php echo htmlspecialchars($student['phone']); ?>"
                                   placeholder="Enter phone number">
                        </div>

                        <div class="form-group">
                            <label for="guardian_name">
                                <i class="fas fa-user-friends"></i> Guardian Name
                            </label>
                            <input type="text" id="guardian_name" name="guardian_name" class="form-control"
                                   value="<?php echo htmlspecialchars($student['guardian_name']); ?>"
                                   placeholder="Enter guardian name">
                        </div>

                        <div class="form-group">
                            <label for="guardian_phone">
                                <i class="fas fa-phone-alt"></i> Guardian Phone
                            </label>
                            <input type="tel" id="guardian_phone" name="guardian_phone" class="form-control"
                                   value="<?php echo htmlspecialchars($student['guardian_phone']); ?>"
                                   placeholder="Enter guardian phone">
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="address">
                                <i class="fas fa-map-marker-alt"></i> Address
                            </label>
                            <textarea id="address" name="address" class="form-control" rows="3"
                                      placeholder="Enter address"><?php echo htmlspecialchars($student['address']); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="student_type">
                                <i class="fas fa-graduation-cap"></i> Student Type
                            </label>
                            <select id="student_type" name="student_type" class="form-control">
                                <option value="fresh" <?php echo $student['student_type'] == 'fresh' ? 'selected' : ''; ?>>Fresh Student</option>
                                <option value="transfer" <?php echo $student['student_type'] == 'transfer' ? 'selected' : ''; ?>>Transfer Student</option>
                            </select>
                        </div>
                    </div>

                    <!-- Important Notes -->
                    <div class="panel" style="background: rgba(245, 158, 11, 0.1); border-left: 4px solid var(--warning-500);">
                        <h3 style="margin: 0 0 1rem 0; color: var(--warning-600); display: flex; align-items: center; gap: 0.75rem;">
                            <i class="fas fa-info-circle"></i> Important Notes
                        </h3>
                        <ul style="margin: 0; padding-left: 1.5rem; color: var(--gray-700);">
                            <li>Changing the class will move the student to a different class</li>
                            <li>Ensure admission number is unique across all students</li>
                            <li>All changes will be logged in the student notes</li>
                        </ul>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-row" style="justify-content: space-between; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--gray-200);">
                        <a href="students.php<?php echo isset($_GET['class_id']) ? '?class_id=' . intval($_GET['class_id']) : ''; ?>" class="btn btn-danger">
                            <i class="fas fa-arrow-left"></i> Back to Students
                        </a>
                        <div class="action-row">
                            <button type="reset" class="btn">
                                <i class="fas fa-undo"></i> Reset Changes
                            </button>
                            <button type="submit" class="btn-gold">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </form>
                </div>
            </div>
        </main>
    </div>

    

<script>
    // Mobile Menu Toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarClose = document.getElementById('sidebarClose');

    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            if (mobileMenuToggle) mobileMenuToggle.classList.remove('active');
        });
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768) {
            if (sidebar && !sidebar.contains(e.target) && mobileMenuToggle && !mobileMenuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
            }
        }
    });

    // Form validation
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--danger-color)';
                    isValid = false;

                    // Add error message
                    if (!field.nextElementSibling?.classList.contains('error-message')) {
                        const errorMsg = document.createElement('div');
                        errorMsg.className = 'error-message';
                        errorMsg.style.color = 'var(--danger-color)';
                        errorMsg.style.fontSize = '0.85rem';
                        errorMsg.style.marginTop = '0.25rem';
                        errorMsg.textContent = 'This field is required';
                        field.parentNode.appendChild(errorMsg);
                    }
                }
            });

            if (!isValid) {
                e.preventDefault();
                // Scroll to first error
                const firstError = this.querySelector('[required]:invalid');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    });

    // Remove error styling when user starts typing
    document.querySelectorAll('[required]').forEach(field => {
        field.addEventListener('input', function() {
            this.style.borderColor = '';
            const errorMsg = this.parentNode.querySelector('.error-message');
            if (errorMsg) errorMsg.remove();
        });
    });
</script>

<style>
    :root {
        --primary-50: #eff6ff;
        --primary-100: #dbeafe;
        --primary-200: #bfdbfe;
        --primary-300: #93c5fd;
        --primary-400: #60a5fa;
        --primary-500: #3b82f6;
        --primary-600: #2563eb;
        --primary-700: #1d4ed8;
        --primary-800: #1e40af;
        --primary-900: #1e3a8a;

        --accent-50: #fdf4ff;
        --accent-100: #fae8ff;
        --accent-200: #f5d0fe;
        --accent-300: #f0abfc;
        --accent-400: #e879f9;
        --accent-500: #d946ef;
        --accent-600: #c026d3;
        --accent-700: #a21caf;
        --accent-800: #86198f;
        --accent-900: #701a75;

        --success-50: #f0fdf4;
        --success-100: #dcfce7;
        --success-500: #22c55e;
        --success-600: #16a34a;
        --success-700: #15803d;

        --error-50: #fef2f2;
        --error-100: #fee2e2;
        --error-500: #ef4444;
        --error-600: #dc2626;

        --warning-50: #fffbeb;
        --warning-100: #fef3c7;
        --warning-500: #f59e0b;
        --warning-600: #d97706;

        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-300: #d1d5db;
        --gray-400: #9ca3af;
        --gray-500: #6b7280;
        --gray-600: #4b5563;
        --gray-700: #374151;
        --gray-800: #1f2937;
        --gray-900: #111827;

        --glass-bg: rgba(255, 255, 255, 0.1);
        --glass-border: rgba(255, 255, 255, 0.2);
        --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.08);
        --shadow-medium: 0 8px 32px rgba(0, 0, 0, 0.12);
        --shadow-strong: 0 16px 48px rgba(0, 0, 0, 0.15);

        --gradient-primary: linear-gradient(135deg, var(--primary-500) 0%, var(--primary-700) 100%);
        --gradient-accent: linear-gradient(135deg, var(--accent-500) 0%, var(--accent-700) 100%);
        --gradient-bg: linear-gradient(135deg, var(--primary-50) 0%, var(--accent-50) 50%, var(--primary-100) 100%);
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: #f5f7fb;
        color: var(--gray-800);
        line-height: 1.6;
        min-height: 100vh;
    }

    .dashboard-header {
        background: #ffffff;
    }

    .dashboard-container .main-content {
        width: 100%;
    }

    .main-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 1.5rem;
    }

    .modern-card {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        box-shadow: var(--shadow-soft);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .card-header-modern {
        padding: 2rem;
        background: var(--gradient-primary);
        color: white;
        position: relative;
    }

    .card-header-modern::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%);
        pointer-events: none;
    }

    .card-title-modern {
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-size: 1.75rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .card-subtitle-modern {
        font-size: 1rem;
        opacity: 0.9;
        position: relative;
        z-index: 1;
    }

    .card-body-modern {
        padding: 2rem;
    }

    .info-card {
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.4);
        border-radius: 14px;
        padding: 1rem;
        box-shadow: var(--shadow-soft);
    }

    .panel {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 18px;
        padding: 1.5rem;
        box-shadow: var(--shadow-soft);
        margin-bottom: 1.5rem;
    }

    .breadcrumb-modern {
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(255, 255, 255, 0.4);
        border-radius: 14px;
        padding: 0.75rem 1rem;
        list-style: none;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        box-shadow: var(--shadow-soft);
        margin: 0;
    }

    .breadcrumb-modern a {
        color: var(--primary-600);
        text-decoration: none;
        font-weight: 600;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .action-row {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
    }

    @media (max-width: 768px) {
        .main-container {
            padding: 1rem;
        }

        .card-header-modern,
        .card-body-modern {
            padding: 1.5rem;
        }

        .panel {
            padding: 1.25rem;
        }

        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }
    }
</style>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
