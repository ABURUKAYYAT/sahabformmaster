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
    <title>Edit Student | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/teacher-students.css">
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

    <!-- Main Container -->
    <div class="dashboard-container">
                <!-- Main Content -->
        <main class="main-content">
            <!-- Breadcrumb -->
            <div style="margin-bottom: 1.5rem;">
                <nav aria-label="breadcrumb">
                    <ol style="background: var(--gray-50); padding: 0.75rem 1rem; border-radius: var(--border-radius); margin: 0; list-style: none; display: flex; align-items: center; gap: 0.5rem;">
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
            <div class="panel">
                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem;">
                    <div style="width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center; color: white;">
                        <i class="fas fa-user-edit" style="font-size: 1.5rem;"></i>
                    </div>
                    <div>
                        <h2 style="margin: 0 0 0.25rem 0;">Edit Student Information</h2>
                        <p style="margin: 0; color: var(--gray-600);">Update <?php echo htmlspecialchars($student['full_name']); ?>'s details</p>
                    </div>
                </div>

                <!-- Current Student Info Preview -->
                <div style="background: var(--gray-50); padding: 1.5rem; border-radius: var(--border-radius); margin-bottom: 2rem; border-left: 4px solid var(--primary-color);">
                    <h3 style="margin: 0 0 1rem 0; color: var(--primary-color); display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fas fa-user"></i> Current Student Details
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div style="background: white; padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--gray-200);">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-user" style="color: var(--primary-color);"></i>
                                <strong>Name</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['full_name']); ?></p>
                        </div>
                        <div style="background: white; padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--gray-200);">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-id-card" style="color: var(--primary-color);"></i>
                                <strong>Admission No</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['admission_no']); ?></p>
                        </div>
                        <div style="background: white; padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--gray-200);">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-school" style="color: var(--primary-color);"></i>
                                <strong>Class</strong>
                            </div>
                            <p style="margin: 0; color: var(--gray-700);"><?php echo htmlspecialchars($student['class_name']); ?></p>
                        </div>
                        <div style="background: white; padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--gray-200);">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-venus-mars" style="color: var(--primary-color);"></i>
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
                    <div style="background: rgba(245, 158, 11, 0.1); padding: 1.5rem; border-radius: var(--border-radius); margin-top: 2rem; border-left: 4px solid var(--warning-color);">
                        <h3 style="margin: 0 0 1rem 0; color: var(--warning-color); display: flex; align-items: center; gap: 0.75rem;">
                            <i class="fas fa-info-circle"></i> Important Notes
                        </h3>
                        <ul style="margin: 0; padding-left: 1.5rem; color: var(--gray-700);">
                            <li>Changing the class will move the student to a different class</li>
                            <li>Ensure admission number is unique across all students</li>
                            <li>All changes will be logged in the student notes</li>
                        </ul>
                    </div>

                    <!-- Action Buttons -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--gray-200);">
                        <a href="students.php<?php echo isset($_GET['class_id']) ? '?class_id=' . intval($_GET['class_id']) : ''; ?>" class="btn btn-danger">
                            <i class="fas fa-arrow-left"></i> Back to Students
                        </a>
                        <div style="display: flex; gap: 1rem;">
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
</script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
