<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only teachers
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();
$teacher_id = intval($_SESSION['user_id']);

// Get errors and success messages from session if they exist
$errors = $_SESSION['add_student_errors'] ?? [];
$success = $_SESSION['add_student_success'] ?? '';
$form_data = $_SESSION['add_student_form_data'] ?? [];

// Clear session data
unset($_SESSION['add_student_errors'], $_SESSION['add_student_success'], $_SESSION['add_student_form_data']);

// Fetch classes assigned to teacher from BOTH subject_assignments AND class_teachers
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name, 'subject' as assignment_type
    FROM classes c
    JOIN subject_assignments sa ON c.id = sa.class_id
    WHERE sa.teacher_id = :tid AND c.school_id = :school_id

    UNION

    SELECT DISTINCT c.id, c.class_name, 'form_master' as assignment_type
    FROM classes c
    JOIN class_teachers ct ON c.id = ct.class_id
    WHERE ct.teacher_id = :tid2 AND c.school_id = :school_id2

    ORDER BY class_name
");
$stmt->execute(['tid'=>$teacher_id, 'school_id'=>$current_school_id, 'tid2'=>$teacher_id, 'school_id2'=>$current_school_id]);
$assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
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
    $errors = [];
    if (empty($full_name)) $errors[] = 'Full name is required.';
    if (empty($admission_no)) $errors[] = 'Admission number is required.';
    if (empty($class_id)) $errors[] = 'Class is required.';

    // Check if admission number already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admission_no = ? AND school_id = ?");
    $stmt->execute([$admission_no, $current_school_id]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = 'Admission number already exists.';
    }

    // Check teacher access to class
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
                        INSERT INTO students (school_id, class_id, full_name, admission_no, gender, dob, phone,
                                             guardian_name, guardian_phone, address, student_type, enrollment_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $current_school_id, $class_id, $full_name, $admission_no, $gender, $dob ?: null,
                        $phone, $guardian_name, $guardian_phone, $address, $student_type
                    ]);

                    $student_id = $pdo->lastInsertId();

                    // Add a note about creation
                    $pdo->prepare("INSERT INTO student_notes (student_id, teacher_id, note_text, created_at) VALUES (?, ?, ?, NOW())")
                        ->execute([$student_id, $teacher_id, "Student added to system."]);

                    $pdo->commit();

                    // Store success message and redirect to students page
                    $_SESSION['add_student_success'] = 'Student added successfully.';
                    header("Location: students.php");
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Error adding student: ' . $e->getMessage();
                }
            } else {
                // Store form data and errors for display
                $_SESSION['add_student_errors'] = $errors;
                $_SESSION['add_student_form_data'] = $_POST;
                header("Location: add_student.php");
                exit;
            }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="pwa-sw" content="../sw.js">
    <title>Add Student | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/offline-status.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f8fafc;
        }

        .form-page-container {
            max-width: 920px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid rgba(15, 31, 45, 0.06);
            border-radius: 1.5rem;
            box-shadow: 0 10px 24px rgba(15, 31, 51, 0.08);
            padding: 2rem;
        }

        .form-header {
            margin-bottom: 2rem;
        }

        .form-header h1 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 2rem;
            font-weight: 600;
            color: #0f1f2d;
            margin-bottom: 0.5rem;
        }

        .form-header p {
            color: #64748b;
            font-size: 1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #0f1f2d;
        }

        .form-control {
            width: 100%;
            border-radius: 0.95rem;
            border: 1px solid rgba(15, 31, 45, 0.12);
            background: #fff;
            padding: 0.85rem 1rem;
            color: #0f1f2d;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.05);
        }

        .form-control:focus {
            outline: none;
            border-color: #168575;
            box-shadow: 0 0 0 4px rgba(22, 133, 117, 0.12);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border-radius: 999px;
            border: 1px solid transparent;
            padding: 0.75rem 1.1rem;
            font-size: 0.9rem;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: #168575;
            border-color: #168575;
            color: #fff;
        }

        .back-button {
            background: #fff;
            border-color: rgba(15, 31, 45, 0.12);
            color: #475569;
        }

        .alert {
            border-radius: 1.25rem;
            border: 1px solid rgba(15, 31, 45, 0.06);
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }

        .alert-error {
            background: #fff1f2;
            border-color: rgba(244, 63, 94, 0.2);
        }

        .alert-success {
            background: #ecfdf5;
            border-color: rgba(16, 185, 129, 0.2);
        }

        @media (max-width: 768px) {
            .form-page-container {
                padding: 1.5rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column-reverse;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
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
                <a class="btn back-button" href="students.php">Students</a>
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
        <!-- Messages -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <p><?php echo htmlspecialchars($success); ?></p>
                <div class="form-actions" style="margin-top: 1rem;">
                    <a href="students.php" class="btn btn-primary">
                        <i class="fas fa-users"></i> View All Students
                    </a>
                    <a href="add_student.php" class="btn back-button">
                        <i class="fas fa-user-plus"></i> Add Another Student
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="form-page-container">
                <div class="form-header">
                    <h1>Add New Student</h1>
                    <p>Fill in the student details below</p>
                </div>

                <form method="POST" id="addStudentForm" data-offline-sync="1">
                    <input type="hidden" name="add_student" value="1">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="class_id">Class *</label>
                            <select id="class_id" name="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach($assigned_classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo isset($form_data['class_id']) && $form_data['class_id'] == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="admission_no">Admission Number *</label>
                            <input type="text" id="admission_no" name="admission_no" class="form-control" value="<?php echo htmlspecialchars($form_data['admission_no'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" class="form-control">
                                <option value="">-- Select --</option>
                                <option value="Male" <?php echo isset($form_data['gender']) && $form_data['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo isset($form_data['gender']) && $form_data['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo isset($form_data['gender']) && $form_data['gender'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="dob">Date of Birth</label>
                            <input type="date" id="dob" name="dob" class="form-control" value="<?php echo htmlspecialchars($form_data['dob'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="guardian_name">Guardian Name</label>
                            <input type="text" id="guardian_name" name="guardian_name" class="form-control" value="<?php echo htmlspecialchars($form_data['guardian_name'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="guardian_phone">Guardian Phone</label>
                            <input type="tel" id="guardian_phone" name="guardian_phone" class="form-control" value="<?php echo htmlspecialchars($form_data['guardian_phone'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" rows="2"><?php echo htmlspecialchars($form_data['address'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="student_type">Student Type</label>
                            <select id="student_type" name="student_type" class="form-control">
                                <option value="fresh" <?php echo isset($form_data['student_type']) && $form_data['student_type'] === 'transfer' ? '' : 'selected'; ?>>Fresh Student</option>
                                <option value="transfer" <?php echo isset($form_data['student_type']) && $form_data['student_type'] === 'transfer' ? 'selected' : ''; ?>>Transfer Student</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="students.php" class="btn back-button">
                            <i class="fas fa-arrow-left"></i> Back to Students
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Add Student
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
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
    </script>

    <script src="../assets/js/offline-core.js" defer></script>
    <?php include '../includes/floating-button.php'; ?>
</body>
</html>
