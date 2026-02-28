<?php
// student/dashboard.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if student is logged in and get school_id
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];
$current_school_id = get_current_school_id();

// Get student details and stats
$query = "SELECT * FROM students WHERE id = ? AND school_id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$student_id, $current_school_id]);
$student = $stmt->fetch();

// If student doesn't belong to user's school, logout
if (!$student) {
    session_destroy();
    header("Location: index.php?error=access_denied");
    exit;
}

// Get attendance stats
$query = "
    SELECT
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
        ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as attendance_rate
    FROM attendance
    WHERE student_id = ? AND school_id = ?
";
$attendance_stmt = $pdo->prepare($query);
$attendance_stmt->execute([$student_id, $current_school_id]);
$attendance = $attendance_stmt->fetch();

// Get results count
$query = "SELECT COUNT(*) as results_count FROM results WHERE student_id = ? AND school_id = ?";
$results_stmt = $pdo->prepare($query);
$results_stmt->execute([$student_id, $current_school_id]);
$results_count = $results_stmt->fetch()['results_count'];

// Get pending activities
$query = "
    SELECT COUNT(*) as pending_activities
    FROM student_submissions ss
    JOIN class_activities ca ON ss.activity_id = ca.id
    WHERE ss.student_id = ? AND ss.status = 'pending' AND ca.school_id = ?
";
$activities_stmt = $pdo->prepare($query);
$activities_stmt->execute([$student_id, $current_school_id]);
$pending_activities = $activities_stmt->fetch()['pending_activities'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="landing">
    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="../index.php" aria-label="iSchool home">
                <span class="brand-mark">iS</span>
                <span class="brand-text">iSchool</span>
            </a>

            <button class="nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="studentNav">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav class="site-nav" id="studentNav">
                <a href="dashboard.php" class="is-active">Dashboard</a>
                <a href="myresults.php">Results</a>
                <a href="student_class_activities.php">Activities</a>
                <a href="attendance.php">Attendance</a>
                <a href="payment.php">Fees</a>
                <a href="schoolfeed.php">School Feed</a>
                <div class="nav-actions-mobile">
                    <a class="btn btn-ghost" href="mysubjects.php"><i class="fas fa-book-open"></i><span>My Subjects</span></a>
                    <a class="btn btn-outline" href="my-evaluations.php"><i class="fas fa-star"></i><span>Evaluations</span></a>
                    <a class="btn btn-primary" href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
                </div>
            </nav>

            <div class="nav-actions">
                <div class="text-right">
                    <span class="text-xs uppercase tracking-wide text-slate-500">Student</span>
                    <div class="font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($student_name ?? '')); ?></div>
                    <div class="text-xs text-slate-500">Admission <?php echo htmlspecialchars((string) ($admission_number ?? '')); ?></div>
                </div>
                <a class="btn btn-ghost" href="mysubjects.php"><i class="fas fa-book-open"></i><span>My Subjects</span></a>
                <a class="btn btn-outline" href="my-evaluations.php"><i class="fas fa-star"></i><span>Evaluations</span></a>
                <a class="btn btn-primary" href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>
    </header>

    <main>
        <section class="hero hero--split container">
            <div data-reveal>
                <p class="text-sm uppercase tracking-wide text-teal-700 font-semibold mb-2">Student Dashboard</p>
                <h1>Welcome back, <?php echo htmlspecialchars((string) ($student_name ?? '')); ?>.</h1>
                <p>Here is your academic overview for <?php echo htmlspecialchars(get_school_display_name()); ?>. Stay on track with attendance, results, and activities.</p>
                <div class="hero-actions">
                    <a class="btn btn-primary" href="myresults.php"><i class="fas fa-chart-line"></i><span>View Results</span></a>
                    <a class="btn btn-outline" href="student_class_activities.php"><i class="fas fa-tasks"></i><span>View Activities</span></a>
                </div>
                <div class="hero-highlights">
                    <span><i class="fas fa-calendar-check me-1"></i>Attendance tracking</span>
                    <span><i class="fas fa-book-open me-1"></i>Subject resources</span>
                    <span><i class="fas fa-star me-1"></i>Continuous evaluation</span>
                </div>
            </div>
            <div class="hero-visual bg-grid" data-reveal data-reveal-delay="120">
                <div class="dashboard-mock">
                    <div class="mock-card">
                        <div class="mock-title">Student Snapshot</div>
                        <div class="flex items-center gap-3 mt-3">
                            <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School logo" class="w-10 h-10 rounded-xl object-cover">
                            <div>
                                <div class="font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></div>
                                <div class="text-sm text-slate-500">Admission <?php echo htmlspecialchars((string) ($admission_number ?? '')); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mock-card">
                        <div class="mock-title">Attendance Rate</div>
                        <p class="text-3xl font-semibold text-ink-900"><?php echo $attendance['attendance_rate'] ?? 0; ?>%</p>
                        <p class="text-sm text-slate-500">Present <?php echo $attendance['present_days'] ?? 0; ?> of <?php echo $attendance['total_days'] ?? 0; ?> days</p>
                    </div>
                    <div class="mock-card">
                        <div class="mock-title">Pending Activities</div>
                        <p class="text-3xl font-semibold text-ink-900"><?php echo $pending_activities; ?></p>
                        <p class="text-sm text-slate-500">Assignments waiting for submission</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="container" data-reveal>
            <div class="stats">
                <div class="stat-card">
                    <h3><?php echo $attendance['attendance_rate'] ?? 0; ?>%</h3>
                    <p>Attendance rate</p>
                    <p class="text-xs text-slate-500">Present <?php echo $attendance['present_days'] ?? 0; ?> of <?php echo $attendance['total_days'] ?? 0; ?> days</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $results_count; ?></h3>
                    <p>Result entries</p>
                    <p class="text-xs text-slate-500">View term reports and transcripts</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $pending_activities; ?></h3>
                    <p>Pending activities</p>
                    <p class="text-xs text-slate-500">Assignments and classwork due</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $attendance['total_days'] ?? 0; ?></h3>
                    <p>Total school days</p>
                    <p class="text-xs text-slate-500">Attendance records this session</p>
                </div>
            </div>
        </section>

        <section class="container" id="tools">
            <h2 class="section-title" data-reveal>Academic tools built for your growth.</h2>
            <p class="section-subtitle" data-reveal data-reveal-delay="80">Quickly jump into the core areas that keep you updated and prepared each week.</p>
            <div class="feature-grid" data-reveal data-reveal-delay="120">
                <div class="feature-card">
                    <div class="flex items-center gap-2 text-teal-700 font-semibold">
                        <i class="fas fa-chart-line"></i>
                        <span>Results</span>
                    </div>
                    <p>Review term performance, report cards, and teacher remarks.</p>
                    <a class="btn btn-outline" href="myresults.php">Open results</a>
                </div>
                <div class="feature-card">
                    <div class="flex items-center gap-2 text-teal-700 font-semibold">
                        <i class="fas fa-tasks"></i>
                        <span>Class Activities</span>
                    </div>
                    <p>Submit assignments, view tasks, and track deadlines.</p>
                    <a class="btn btn-outline" href="student_class_activities.php">Go to activities</a>
                </div>
                <div class="feature-card">
                    <div class="flex items-center gap-2 text-teal-700 font-semibold">
                        <i class="fas fa-calendar-check"></i>
                        <span>Attendance</span>
                    </div>
                    <p>Monitor presence history and attendance summaries.</p>
                    <a class="btn btn-outline" href="attendance.php">View attendance</a>
                </div>
                <div class="feature-card">
                    <div class="flex items-center gap-2 text-teal-700 font-semibold">
                        <i class="fas fa-book-open"></i>
                        <span>My Subjects</span>
                    </div>
                    <p>Explore subject materials, teachers, and class schedules.</p>
                    <a class="btn btn-outline" href="mysubjects.php">View subjects</a>
                </div>
                <div class="feature-card">
                    <div class="flex items-center gap-2 text-teal-700 font-semibold">
                        <i class="fas fa-star"></i>
                        <span>Evaluations</span>
                    </div>
                    <p>Check continuous assessments and evaluation feedback.</p>
                    <a class="btn btn-outline" href="my-evaluations.php">See evaluations</a>
                </div>
                <div class="feature-card">
                    <div class="flex items-center gap-2 text-teal-700 font-semibold">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>School Fees</span>
                    </div>
                    <p>Track payment status and download receipts securely.</p>
                    <a class="btn btn-outline" href="payment.php">Manage fees</a>
                </div>
            </div>
        </section>

        <section class="container">
            <h2 class="section-title" data-reveal>Quick actions for daily needs.</h2>
            <p class="section-subtitle" data-reveal data-reveal-delay="80">Shortcuts to what students check most often during the term.</p>
            <div class="resource-grid" data-reveal data-reveal-delay="120">
                <div class="resource-card">
                    <strong>School Feeds</strong>
                    <p>Get announcements, timetable shifts, and school updates.</p>
                    <a class="btn btn-ghost" href="schoolfeed.php">Read news</a>
                </div>
                <div class="resource-card">
                    <strong>School Diary</strong>
                    <p>Stay on top of events, exams, and term schedules.</p>
                    <a class="btn btn-ghost" href="school_diary.php">Open diary</a>
                </div>
                <div class="resource-card">
                    <strong>CBT Tests</strong>
                    <p>Prepare for online assessments and practice tests.</p>
                    <a class="btn btn-ghost" href="cbt_tests.php">Start practice</a>
                </div>
                <div class="resource-card">
                    <strong>Photo Album</strong>
                    <p>View class photos and event memories.</p>
                    <a class="btn btn-ghost" href="photo_album.php">View gallery</a>
                </div>
            </div>
        </section>

        <article class="container story-wrap" id="activity">
            <div class="story-panel" data-reveal>
                <h2 class="section-title">Recent activity</h2>
                <p>Highlights from the last few updates in your classes.</p>
                <div class="space-y-4 mt-4">
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-xl bg-teal-600/10 flex items-center justify-center text-teal-700">
                            <i class="fas fa-check"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-ink-900">Results published for Mathematics Term 1</div>
                            <div class="text-sm text-slate-500">Today, 9:00 AM</div>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-xl bg-amber-500/10 flex items-center justify-center text-amber-500">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-ink-900">New assignment posted in English Studies</div>
                            <div class="text-sm text-slate-500">Yesterday, 2:30 PM</div>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-xl bg-slate-500/10 flex items-center justify-center text-slate-600">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-ink-900">Attendance recorded for Science Class</div>
                            <div class="text-sm text-slate-500">2 days ago</div>
                        </div>
                    </div>
                </div>
            </div>
            <aside class="proof-panel" data-reveal data-reveal-delay="120">
                <p class="quote">Stay focused on the activities that move your grades forward.</p>
                <p><strong>Upcoming reminder</strong></p>
                <p>Complete pending class activities and check your attendance before Friday.</p>
                <div class="hero-actions mt-4">
                    <a class="btn btn-primary" href="student_class_activities.php">Complete tasks</a>
                    <a class="btn btn-outline" href="attendance.php">Check attendance</a>
                </div>
            </aside>
        </article>

        <section class="container" data-reveal>
            <div class="cta-band">
                <div>
                    <h2 class="section-title">Need support or guidance?</h2>
                    <p>Reach out to your class teacher or review the school diary for the latest updates.</p>
                </div>
                <a class="btn btn-primary" href="school_diary.php">View school diary</a>
            </div>
        </section>
    </main>

    <script src="../assets/js/landing.js"></script>
</body>
</html>

