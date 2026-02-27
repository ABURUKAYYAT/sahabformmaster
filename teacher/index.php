<?php
// teacher/index.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if teacher is logged in and get school_id
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$teacher_name = $_SESSION['full_name'];
$current_school_id = require_school_auth();

// Get dynamic stats for the teacher
$teacher_id = $_SESSION['user_id'];

// Get teacher's class count
$query = "SELECT COUNT(*) as class_count FROM class_teachers ct JOIN classes c ON ct.class_id = c.id WHERE ct.teacher_id = ? AND c.school_id = ?";
$class_stmt = $pdo->prepare($query);
$class_stmt->execute([$teacher_id, $current_school_id]);
$class_count = $class_stmt->fetch()['class_count'];

// Get student's count for teacher's classes
$query = "
    SELECT COUNT(DISTINCT s.id) as student_count
    FROM students s
    JOIN class_teachers ct ON s.class_id = ct.class_id
    JOIN classes c ON s.class_id = c.id
    WHERE ct.teacher_id = ? AND s.school_id = ? AND c.school_id = ?
";
$student_stmt = $pdo->prepare($query);
$student_stmt->execute([$teacher_id, $current_school_id, $current_school_id]);
$student_count = $student_stmt->fetch()['student_count'];

// Get lesson plans count
$query = "SELECT COUNT(*) as lesson_count FROM lesson_plans WHERE teacher_id = ? AND school_id = ?";
$lesson_stmt = $pdo->prepare($query);
$lesson_stmt->execute([$teacher_id, $current_school_id]);
$lesson_count = $lesson_stmt->fetch()['lesson_count'];

// Get subjects count
$query = "
    SELECT COUNT(DISTINCT sa.subject_id) as subject_count
    FROM subject_assignments sa
    JOIN class_teachers ct ON sa.class_id = ct.class_id
    JOIN classes c ON sa.class_id = c.id
    JOIN subjects sub ON sa.subject_id = sub.id
    WHERE ct.teacher_id = ? AND c.school_id = ? AND sub.school_id = ?
";
$subject_stmt = $pdo->prepare($query);
$subject_stmt->execute([$teacher_id, $current_school_id, $current_school_id]);
$subject_count = $subject_stmt->fetch()['subject_count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="landing">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="w-10 h-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                <a class="btn btn-outline" href="../index.php">Home</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 lg:grid-cols-[280px_1fr] py-8">
        <aside class="fixed inset-y-0 left-0 z-40 w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:translate-x-0" data-sidebar>
            <div class="p-6 border-b border-ink-900/10">
                <h2 class="text-lg font-semibold text-ink-900">Navigation</h2>
                <p class="text-sm text-slate-500">Teacher workspace</p>
            </div>
            <nav class="p-4 space-y-1 text-sm">
                <a href="index.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold bg-teal-600/10 text-teal-700">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="schoolfeed.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-newspaper"></i>
                    <span>School Feeds</span>
                </a>
                <a href="school_diary.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-book"></i>
                    <span>School Diary</span>
                </a>
                <a href="students.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-users"></i>
                    <span>Students</span>
                </a>
                <a href="results.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-chart-line"></i>
                    <span>Results</span>
                </a>
                <a href="subjects.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-book-open"></i>
                    <span>Subjects</span>
                </a>
                <a href="questions.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-question-circle"></i>
                    <span>Question Bank</span>
                </a>
                <a href="lesson-plan.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Lesson Plans</span>
                </a>
                <a href="curricullum.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-graduation-cap"></i>
                    <span>Curriculum</span>
                </a>
                <a href="teacher_class_activities.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-tasks"></i>
                    <span>Class Activities</span>
                </a>
                <a href="cbt_tests.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-laptop-code"></i>
                    <span>CBT Tests</span>
                </a>
                <a href="student-evaluation.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-star"></i>
                    <span>Evaluations</span>
                </a>
                <a href="class_attendance.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-calendar-check"></i>
                    <span>Attendance</span>
                </a>
                <a href="timebook.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-clock"></i>
                    <span>Time Book</span>
                </a>
                <a href="permissions.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-key"></i>
                    <span>Permissions</span>
                </a>
                <a href="payments.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Payments</span>
                </a>
            </nav>
        </aside>

        <main class="space-y-6">
            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 class="text-3xl font-display text-ink-900">Welcome back, <?php echo htmlspecialchars($teacher_name); ?></h1>
                        <p class="text-slate-600">Here is a quick snapshot of your classes and activities today.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Students</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo $student_count; ?></p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Classes</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo $class_count; ?></p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Subjects</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo $subject_count; ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <section>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xl font-semibold text-ink-900">Your Workspace</h2>
                    <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="lesson-plan.php">New lesson plan</a>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-users"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">My Students</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $student_count; ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="students.php">Manage students</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">My Classes</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $class_count; ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="students.php">View classes</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-clipboard-list"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Lesson Plans</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $lesson_count; ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="lesson-plan.php">Create new</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-book-open"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Subjects</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $subject_count; ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="subjects.php">View subjects</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-chart-line"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Results</p>
                                <p class="text-2xl font-semibold text-ink-900">Pending</p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="results.php">Enter results</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-question-circle"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Question Bank</p>
                                <p class="text-2xl font-semibold text-ink-900">Active</p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="questions.php">View questions</a>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 lg:grid-cols-[1.2fr_1fr]">
                <div class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-ink-900">Quick Actions</h2>
                        <span class="text-xs uppercase tracking-wide text-slate-500">Frequently used</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <a href="lesson-plan.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-plus-circle text-teal-700"></i>
                            Create lesson plan
                        </a>
                        <a href="class_attendance.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-calendar-check text-teal-700"></i>
                            Mark attendance
                        </a>
                        <a href="results.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-chart-bar text-teal-700"></i>
                            Enter results
                        </a>
                        <a href="teacher_class_activities.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-tasks text-teal-700"></i>
                            Class activities
                        </a>
                        <a href="permissions.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-key text-teal-700"></i>
                            Request permission
                        </a>
                        <a href="timebook.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-clock text-teal-700"></i>
                            Time book
                        </a>
                    </div>
                </div>

                <div class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-ink-900">Recent Activity</h2>
                        <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="#">View all</a>
                    </div>
                    <div class="space-y-4">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-check"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-ink-900">Lesson plan approved for Mathematics</p>
                                <p class="text-xs text-slate-500">Today, 10:30 AM</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/10 text-amber-500">
                                <i class="fas fa-chart-line"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-ink-900">Results submitted for Biology class</p>
                                <p class="text-xs text-slate-500">Yesterday, 3:45 PM</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-calendar-check"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-ink-900">Attendance marked for JSS 1A</p>
                                <p class="text-xs text-slate-500">2 days ago</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-slate-500/10 text-slate-600">
                                <i class="fas fa-question-circle"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-ink-900">New questions added to Mathematics bank</p>
                                <p class="text-xs text-slate-500">3 days ago</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <?php include '../includes/floating-button.php'; ?>

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

        if (sidebar) {
            sidebar.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', closeSidebar);
            });
        }
    </script>
</body>
</html>
