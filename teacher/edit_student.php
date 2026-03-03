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
    WHERE s.id = ? AND s.school_id = ? AND c.school_id = ?
");
$stmt->execute([$student_id, $current_school_id, $current_school_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: students.php");
    exit;
}

// Verify teacher access
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM students s
    WHERE s.id = ? AND s.school_id = ?
    AND (
        EXISTS (SELECT 1 FROM subject_assignments sa WHERE sa.class_id = s.class_id AND sa.teacher_id = ?)
        OR EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = s.class_id AND ct.teacher_id = ?)
    )
");
$stmt->execute([$student_id, $current_school_id, $teacher_id, $teacher_id]);
if ($stmt->fetchColumn() == 0) {
    header("Location: students.php");
    exit;
}

// Fetch classes assigned to teacher for dropdown
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name
    FROM classes c
    JOIN subject_assignments sa ON c.id = sa.class_id
    WHERE sa.teacher_id = :tid AND c.school_id = :school_id

    UNION

    SELECT DISTINCT c.id, c.class_name
    FROM classes c
    JOIN class_teachers ct ON c.id = ct.class_id
    WHERE ct.teacher_id = :tid2 AND c.school_id = :school_id2

    ORDER BY class_name
");
$stmt->execute([
    'tid' => $teacher_id,
    'school_id' => $current_school_id,
    'tid2' => $teacher_id,
    'school_id2' => $current_school_id,
]);
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
                WHERE id = ? AND school_id = ?
            ");
            $stmt->execute([
                $class_id, $full_name, $admission_no, $gender, $dob ?: null,
                $phone, $guardian_name, $guardian_phone, $address, $student_type, $student_id, $current_school_id
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
                WHERE s.id = ? AND s.school_id = ? AND c.school_id = ?
            ");
            $stmt->execute([$student_id, $current_school_id, $current_school_id]);
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
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <meta name="theme-color" content="#0f172a">
</head>
<body class="landing bg-slate-50">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="h-10 w-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
                <a class="btn btn-outline" href="students.php">Students</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:translate-x-0" data-sidebar>
            <?php include '../includes/teacher_sidebar.php'; ?>
        </aside>
        <main class="space-y-6">
            <div class="main-container">
            <!-- Breadcrumb -->
            <div style="margin-bottom: 1.5rem;">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb-modern">
                        <li style="display: flex; align-items: center; gap: 0.5rem;">
                            <a href="students.php">
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
                        <a href="students.php<?php echo isset($_GET['class_id']) ? '?class_id=' . intval($_GET['class_id']) : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Students
                        </a>
                        <div class="action-row">
                            <button type="reset" class="btn btn-ghost">
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
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const body = document.body;

    const openSidebar = () => {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('opacity-0', 'pointer-events-none');
        overlay.classList.add('opacity-100');
        body.classList.add('nav-open');
    };

    const closeSidebar = () => {
        if (!sidebar || !overlay) return;
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('opacity-0', 'pointer-events-none');
        overlay.classList.remove('opacity-100');
        body.classList.remove('nav-open');
    };

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            if (sidebar.classList.contains('-translate-x-full')) {
                openSidebar();
            } else {
                closeSidebar();
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // Form validation
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--error-500)';
                    isValid = false;

                    // Add error message
                    if (!field.nextElementSibling?.classList.contains('error-message')) {
                        const errorMsg = document.createElement('div');
                        errorMsg.className = 'error-message';
                        errorMsg.style.color = 'var(--error-500)';
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
        --primary-500: #168575;
        --primary-600: #0f6a5c;
        --primary-700: #0c574b;
        --success-500: #0f9f6e;
        --success-600: #0a7f58;
        --error-50: #fff1f2;
        --error-200: #fecdd3;
        --error-500: #e11d48;
        --warning-50: #fffbeb;
        --warning-500: #d97706;
        --warning-600: #b45309;
        --gray-50: #f8fafc;
        --gray-100: #eef2f7;
        --gray-200: #dbe3ee;
        --gray-400: #94a3b8;
        --gray-500: #64748b;
        --gray-600: #475569;
        --gray-700: #334155;
        --gray-900: #0f1f2d;
        --shadow-soft: 0 14px 32px rgba(15, 31, 45, 0.08);
        --gradient-primary: linear-gradient(135deg, #0f6a5c 0%, #168575 52%, #1e9bb3 100%);
    }

    body {
        font-family: 'Manrope', 'Segoe UI', sans-serif;
        color: var(--gray-900);
        line-height: 1.6;
    }

    .main-container {
        max-width: 1120px;
        margin: 0 auto;
        width: 100%;
    }

    .modern-card {
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid rgba(15, 31, 45, 0.08);
        border-radius: 28px;
        box-shadow: var(--shadow-soft);
        overflow: hidden;
    }

    .card-header-modern {
        padding: 1.75rem;
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
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.75rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .card-subtitle-modern {
        color: rgba(255, 255, 255, 0.86);
        position: relative;
        z-index: 1;
    }

    .card-body-modern {
        padding: 1.75rem;
    }

    .alert {
        display: flex;
        gap: 0.85rem;
        align-items: flex-start;
        border-radius: 1.15rem;
        padding: 1rem 1.1rem;
        border: 1px solid transparent;
        background: #fff;
        box-shadow: var(--shadow-soft);
    }

    .alert-error {
        background: var(--error-50);
        border-color: var(--error-200);
        color: #be123c;
    }

    .alert-success {
        background: #ecfdf5;
        border-color: #bbf7d0;
        color: #166534;
    }

    .info-card {
        background: linear-gradient(180deg, #ffffff 0%, #f8fbfd 100%);
        border: 1px solid rgba(15, 31, 45, 0.08);
        border-radius: 1.15rem;
        padding: 1rem 1.05rem;
        box-shadow: 0 10px 24px rgba(15, 31, 45, 0.05);
    }

    .panel {
        background: #ffffff;
        border: 1px solid rgba(15, 31, 45, 0.08);
        border-radius: 1.35rem;
        padding: 1.5rem;
        box-shadow: var(--shadow-soft);
        margin-bottom: 1.5rem;
    }

    .breadcrumb-modern {
        background: rgba(255, 255, 255, 0.92);
        border: 1px solid rgba(15, 31, 45, 0.08);
        border-radius: 999px;
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

    .breadcrumb-modern a:hover {
        color: var(--primary-500);
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.1rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.55rem;
        color: var(--gray-900);
        font-size: 0.92rem;
        font-weight: 700;
    }

    .form-control {
        width: 100%;
        border-radius: 1rem;
        border: 1px solid rgba(15, 31, 45, 0.12);
        background: #fff;
        padding: 0.88rem 1rem;
        color: var(--gray-900);
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary-500);
        box-shadow: 0 0 0 4px rgba(22, 133, 117, 0.12);
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

    .btn-gold {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        border-radius: 999px;
        border: 1px solid transparent;
        padding: 0.75rem 1.15rem;
        font-size: 0.92rem;
        font-weight: 700;
        color: #fff;
        background: linear-gradient(135deg, #d6a548 0%, #f1c25b 100%);
        box-shadow: 0 14px 30px rgba(214, 165, 72, 0.22);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .btn-gold:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 32px rgba(214, 165, 72, 0.28);
    }

    .error-message {
        color: var(--error-500);
        font-size: 0.85rem;
        margin-top: 0.35rem;
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

        .action-row {
            flex-direction: column;
            align-items: stretch;
        }

        .btn-gold,
        .action-row .btn {
            width: 100%;
        }
    }
</style>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
