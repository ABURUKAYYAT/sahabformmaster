<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Student access using admission_no or user_id
$uid = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;
$errors = [];
$success = '';

if (!$uid && !$admission_no) {
    header('Location: ../index.php');
    exit;
}

$current_school_id = get_current_school_id();

// Resolve student record
$student = null;
if ($admission_no) {
    $stmt = $pdo->prepare('SELECT id, full_name, class_id FROM students WHERE admission_no = :admission_no AND school_id = :school_id LIMIT 1');
    $stmt->execute(['admission_no' => $admission_no, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$student && $uid) {
    $stmt = $pdo->prepare('SELECT id, full_name, class_id FROM students WHERE (user_id = :uid OR id = :uid) AND school_id = :school_id LIMIT 1');
    $stmt->execute(['uid' => $uid, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$student) {
    die('Student record not found.');
}

$student_id = (int) ($student['id'] ?? 0);
$class_id = (int) ($student['class_id'] ?? 0);

// Normalize term input to canonical values used in DB
function normalize_term(string $value): string
{
    $t = trim(strtolower($value));
    $map = [
        '1' => '1st Term',
        'first' => '1st Term',
        '1st' => '1st Term',
        'first term' => '1st Term',
        '1st term' => '1st Term',
        '2' => '2nd Term',
        'second' => '2nd Term',
        '2nd' => '2nd Term',
        'second term' => '2nd Term',
        '2nd term' => '2nd Term',
        '3' => '3rd Term',
        'third' => '3rd Term',
        '3rd' => '3rd Term',
        'third term' => '3rd Term',
        '3rd term' => '3rd Term',
    ];

    return $map[$t] ?? (strlen($t) ? ucfirst($t) : '1st Term');
}

$term = normalize_term((string) ($_GET['term'] ?? $_POST['term'] ?? '1st Term'));

// Resolve academic session filter for results
$session_stmt = $pdo->prepare("
    SELECT DISTINCT TRIM(academic_session) AS academic_session
    FROM results
    WHERE student_id = :student_id
      AND school_id = :school_id
      AND COALESCE(TRIM(academic_session), '') <> ''
    ORDER BY academic_session DESC
");
$session_stmt->execute([
    'student_id' => $student_id,
    'school_id' => $current_school_id,
]);
$academic_sessions = [];
foreach ($session_stmt->fetchAll(PDO::FETCH_COLUMN) as $session_value) {
    $session_value = trim((string) $session_value);
    if ($session_value !== '') {
        $academic_sessions[] = $session_value;
    }
}
$requested_academic_session = trim((string) ($_GET['academic_session'] ?? $_POST['academic_session'] ?? ''));
if (!empty($academic_sessions)) {
    $academic_session = in_array($requested_academic_session, $academic_sessions, true)
        ? $requested_academic_session
        : $academic_sessions[0];
} else {
    $academic_session = '';
}

// Handle complaint submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_complaint') {
        $result_id = (int) ($_POST['result_id'] ?? 0);
        $text = trim((string) ($_POST['complaint_text'] ?? ''));

        if ($result_id <= 0 || $text === '') {
            $errors[] = 'Please select a result and enter your complaint.';
        } else {
            // Ensure result belongs to this student and school
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM results WHERE id = :id AND student_id = :student_id AND school_id = :school_id');
            $stmt->execute([
                'id' => $result_id,
                'student_id' => $student_id,
                'school_id' => $current_school_id,
            ]);

            if ((int) $stmt->fetchColumn() === 0) {
                $errors[] = 'Selected result not found.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO results_complaints (result_id, student_id, complaint_text, status, created_at) VALUES (:result_id, :student_id, :text, 'pending', NOW())");
                $stmt->execute([
                    'result_id' => $result_id,
                    'student_id' => $student_id,
                    'text' => $text,
                ]);
                $success = 'Complaint submitted successfully.';
            }
        }
    }
}

// Fetch student's results for the selected term and academic session
try {
    $stmt = $pdo->prepare("SELECT r.*, sub.subject_name
        FROM results r
        JOIN subjects sub ON r.subject_id = sub.id AND sub.school_id = :school_id_join
        WHERE r.student_id = :student_id
          AND r.school_id = :school_id
          AND LOWER(TRIM(r.term)) = LOWER(TRIM(:term))
          AND (
            (:academic_session_filter = '' AND COALESCE(TRIM(r.academic_session), '') = '')
            OR LOWER(TRIM(COALESCE(r.academic_session, ''))) = LOWER(TRIM(:academic_session_match))
          )
        ORDER BY sub.subject_name");
    $stmt->execute([
        'student_id' => $student_id,
        'school_id_join' => $current_school_id,
        'school_id' => $current_school_id,
        'term' => $term,
        'academic_session_filter' => $academic_session,
        'academic_session_match' => $academic_session,
    ]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = 'Database error while fetching results.';
    error_log('student/myresults.php DB error: ' . $e->getMessage());
    $results = [];
}

// Fetch existing complaints by student
$stmt = $pdo->prepare('SELECT rc.*, sub.subject_name, r.term
    FROM results_complaints rc
    JOIN results r ON rc.result_id = r.id AND r.school_id = :school_id_results
    JOIN subjects sub ON r.subject_id = sub.id AND sub.school_id = :school_id_subjects
    WHERE rc.student_id = :student_id
    ORDER BY rc.created_at DESC');
$stmt->execute([
    'student_id' => $student_id,
    'school_id_results' => $current_school_id,
    'school_id_subjects' => $current_school_id,
]);
$complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get class name
$stmt = $pdo->prepare('SELECT class_name FROM classes WHERE id = :id AND school_id = :school_id');
$stmt->execute(['id' => $class_id, 'school_id' => $current_school_id]);
$class_name = (string) ($stmt->fetchColumn() ?: 'N/A');

// Summary metrics
$total_results = count($results);
$total_score = 0.0;
$pass_count = 0;
$max_score = 0.0;

foreach ($results as $row) {
    $first_ca = (float) ($row['first_ca'] ?? 0);
    $second_ca = (float) ($row['second_ca'] ?? 0);
    $exam = (float) ($row['exam'] ?? 0);
    $grand_total = $first_ca + $second_ca + $exam;
    $total_score += $grand_total;
    if ($grand_total >= 50) {
        $pass_count++;
    }
    if ($grand_total > $max_score) {
        $max_score = $grand_total;
    }
}

$average_score = $total_results > 0 ? round($total_score / $total_results, 1) : 0.0;
$pass_percentage = $total_results > 0 ? round(($pass_count / $total_results) * 100) : 0;

$scoreTone = 'focus';
$scoreMessage = 'Keep improving with consistent effort';
if ($average_score >= 70) {
    $scoreTone = 'excellent';
    $scoreMessage = 'Excellent performance this term';
} elseif ($average_score >= 50) {
    $scoreTone = 'good';
    $scoreMessage = 'Good progress, maintain momentum';
}

$pageTitle = 'My Results | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$extraHead = <<<'HTML'
<style>
    .student-layout{overflow-x:hidden}
    .myresults-page section{padding-top:0;padding-bottom:0}
    .results-card{border-radius:1.5rem;border:1px solid rgba(15,31,45,.08);background:#fff;box-shadow:0 10px 24px rgba(15,31,51,.08)}
    .results-hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#0f1f2d 0%,#0f6a5c 55%,#168575 100%);color:#fff}
    .results-hero::after{content:'';position:absolute;inset:auto -60px -120px auto;width:280px;height:280px;background:radial-gradient(circle,rgba(255,255,255,.25) 0%,rgba(255,255,255,0) 70%)}
    .hero-kicker{font-weight:700;color:rgba(255,255,255,.95);text-shadow:0 1px 2px rgba(2,6,23,.35)}
    .hero-title{font-family:'Manrope',sans-serif;font-weight:800;letter-spacing:.01em;color:#fff;text-shadow:0 2px 6px rgba(2,6,23,.35)}
    .hero-meta{font-family:'Manrope',sans-serif;font-weight:600;color:rgba(255,255,255,.97);text-shadow:0 1px 3px rgba(2,6,23,.35)}
    .metric-tile{border-radius:1rem;padding:1rem;border:1px solid rgba(15,31,45,.08);background:#f8fafc}
    .metric-tile h3{font-size:.75rem;letter-spacing:.08em;text-transform:uppercase;color:#64748b;font-weight:700}
    .metric-tile p{margin-top:.2rem;font-size:1.45rem;line-height:1.2;color:#0f172a;font-weight:700}
    .metric-tile small{display:block;margin-top:.25rem;color:#64748b;font-size:.72rem}

    .score-pill{display:inline-flex;align-items:center;border-radius:999px;padding:.22rem .62rem;font-size:.72rem;font-weight:700;letter-spacing:.03em}
    .score-pill.excellent{background:rgba(16,185,129,.15);color:#047857}
    .score-pill.good{background:rgba(14,165,233,.15);color:#0369a1}
    .score-pill.focus{background:rgba(245,158,11,.15);color:#b45309}

    .table-wrap{overflow-x:auto;border:1px solid rgba(15,31,45,.1);border-radius:1rem}
    .result-table{min-width:860px;width:100%;border-collapse:collapse}
    .result-table th{padding:.9rem .85rem;background:linear-gradient(135deg,#0f6a5c 0%,#168575 100%);color:#f8fafc;text-transform:uppercase;letter-spacing:.06em;font-size:.68rem;text-align:left}
    .result-table td{padding:.92rem .85rem;border-top:1px solid rgba(15,31,45,.08);font-size:.86rem;color:#334155;vertical-align:top}
    .result-table tbody tr:hover{background:#f8fafc}
    .subject-cell{font-weight:700;color:#0f172a;white-space:nowrap}
    .score-cell,.total-cell,.grade-cell{text-align:center}
    .total-cell{font-weight:700;color:#0f766e}

    .grade-badge{display:inline-flex;align-items:center;justify-content:center;min-width:2rem;border-radius:999px;padding:.22rem .58rem;font-size:.72rem;font-weight:700}
    .grade-a{background:#dcfce7;color:#166534}
    .grade-b{background:#dbeafe;color:#1e40af}
    .grade-c{background:#fef3c7;color:#92400e}
    .grade-d{background:#fde68a;color:#78350f}
    .grade-e{background:#fee2e2;color:#991b1b}
    .grade-f{background:#fecaca;color:#7f1d1d}

    .complaint-btn{display:inline-flex;align-items:center;gap:.35rem;border:0;border-radius:.7rem;padding:.48rem .7rem;background:#ef4444;color:#fff;font-size:.74rem;font-weight:700;cursor:pointer;transition:background .15s ease}
    .complaint-btn:hover{background:#dc2626}
    .complaint-form{max-height:0;overflow:hidden;opacity:0;transition:max-height .24s ease,opacity .2s ease;margin-top:.55rem}
    .complaint-form.active{opacity:1}

    .input-box{width:100%;border:1px solid rgba(15,31,45,.18);border-radius:.8rem;padding:.62rem .75rem;background:#fff;font-size:.88rem;color:#0f172a;transition:border-color .15s ease,box-shadow .15s ease}
    .input-box:focus{outline:0;border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.18)}

    .status-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:.2rem .55rem;font-size:.7rem;font-weight:700;text-transform:capitalize}
    .status-pending{background:#fef3c7;color:#92400e}
    .status-processing{background:#e0f2fe;color:#0369a1}
    .status-resolved{background:#dcfce7;color:#166534}
    .controls-grid{display:grid;gap:.75rem}
    .control-btn{min-width:160px}

    .student-sidebar-overlay{position:fixed;inset:0;background:rgba(2,6,23,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:30}
    .sidebar{position:fixed;top:73px;left:0;width:16rem;height:calc(100vh - 73px);background:#fff;border-right:1px solid rgba(15,31,45,.1);box-shadow:0 18px 40px rgba(15,31,51,.12);transform:translateX(-106%);transition:transform .22s ease;z-index:40;overflow-y:auto}
    body.sidebar-open .sidebar{transform:translateX(0)}
    body.sidebar-open .student-sidebar-overlay{opacity:1;pointer-events:auto}
    .sidebar-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid rgba(15,31,45,.08)}
    .sidebar-header h3{margin:0;font-size:1rem;font-weight:700;color:#0f1f2d}
    .sidebar-close{border:0;border-radius:.55rem;padding:.35rem .55rem;background:rgba(15,31,45,.08);color:#334155;font-size:.8rem;line-height:1;cursor:pointer}
    .sidebar-nav{padding:.8rem}
    .nav-list{list-style:none;margin:0;padding:0;display:grid;gap:.2rem}
    .nav-link{display:flex;align-items:center;gap:.65rem;border-radius:.75rem;padding:.62rem .72rem;color:#475569;font-size:.88rem;font-weight:600;text-decoration:none;transition:background-color .15s ease,color .15s ease}
    .nav-link:hover{background:rgba(22,133,117,.1);color:#0f6a5c}
    .nav-link.active{background:rgba(22,133,117,.14);color:#0f6a5c}
    .nav-icon{width:1rem;text-align:center}

    #studentMain{min-width:0}
    @media (min-width:768px){
        #studentMain{padding-left:16rem !important}
        .sidebar{transform:translateX(0);top:73px;height:calc(100vh - 73px)}
        .sidebar-close{display:none}
        .student-sidebar-overlay{display:none}
        .controls-grid{grid-template-columns:1fr 1fr auto auto;align-items:end}
    }
    @media (min-width:1024px){
        .results-card{padding:2rem !important}
        .results-hero{padding:2.25rem !important}
    }
    @media (max-width:767.98px){#studentMain{padding-left:0 !important}}
    @media (max-width:640px){
        .results-card{padding:1rem !important}
        .result-table th,.result-table td{padding:.72rem .65rem}
    }
</style>
HTML;

require_once __DIR__ . '/../includes/student_header.php';
?>

<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="myresults-page space-y-6">
    <section class="results-card results-hero p-6 sm:p-8" data-reveal>
        <div class="relative z-10 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="hero-kicker text-xs uppercase" style="letter-spacing:.14em;">Academic Results</p>
                <h1 class="hero-title mt-2 text-3xl">My Results Dashboard</h1>
                <p class="hero-meta mt-2 max-w-2xl text-sm">
                    <?php echo htmlspecialchars((string) ($student['full_name'] ?? 'Student')); ?>
                    • Class <?php echo htmlspecialchars($class_name); ?>
                    • <?php echo htmlspecialchars($term); ?>
                    • <?php echo htmlspecialchars($academic_session !== '' ? $academic_session : 'Session Not Set'); ?>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="score-pill <?php echo htmlspecialchars($scoreTone); ?>"><?php echo htmlspecialchars($scoreMessage); ?></span>
                <a class="btn btn-outline !border-white/50 !text-white hover:!bg-white/15" href="dashboard.php">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
            </div>
        </div>
    </section>

    <section class="results-card p-6" data-reveal data-reveal-delay="70">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="metric-tile">
                <h3>Average Score</h3>
                <p><?php echo number_format($average_score, 1); ?></p>
                <small>Across <?php echo number_format($total_results); ?> subjects</small>
            </article>
            <article class="metric-tile">
                <h3>Pass Rate</h3>
                <p><?php echo number_format($pass_percentage); ?>%</p>
                <small><?php echo number_format($pass_count); ?> subjects passed</small>
            </article>
            <article class="metric-tile">
                <h3>Highest Score</h3>
                <p><?php echo number_format($max_score, 1); ?></p>
                <small>Top subject performance</small>
            </article>
            <article class="metric-tile">
                <h3>Total Complaints</h3>
                <p><?php echo number_format(count($complaints)); ?></p>
                <small>Submitted review requests</small>
            </article>
        </div>
    </section>

    <section class="results-card p-6" data-reveal data-reveal-delay="100">
        <form id="termForm" method="GET" action="myresults.php" class="controls-grid">
            <div>
                <label for="termSelector" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Select Term</label>
                <select class="input-box" name="term" id="termSelector">
                    <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                    <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                    <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                </select>
            </div>
            <div>
                <label for="sessionSelector" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Academic Session</label>
                <select class="input-box" name="academic_session" id="sessionSelector">
                    <?php if (!empty($academic_sessions)): ?>
                        <?php foreach ($academic_sessions as $session_option): ?>
                            <option value="<?php echo htmlspecialchars($session_option); ?>" <?php echo $academic_session === $session_option ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($session_option); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" selected>Session Not Set</option>
                    <?php endif; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary control-btn">
                <i class="fas fa-search"></i>
                <span>Load Results</span>
            </button>
            <?php if (!empty($results)): ?>
                <button type="button" class="btn btn-outline control-btn" onclick="downloadPDF()">
                    <i class="fas fa-download"></i>
                    <span>Download PDF</span>
                </button>
            <?php endif; ?>
        </form>

        <?php if (!empty($results)): ?>
            <form id="pdfForm" method="POST" action="../teacher/generate-result-pdf.php" class="hidden">
                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                <input type="hidden" name="academic_session" value="<?php echo htmlspecialchars($academic_session); ?>">
            </form>
        <?php endif; ?>
    </section>

    <?php if ($success !== ''): ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <div class="font-semibold"><i class="fas fa-exclamation-circle mr-2"></i>Unable to complete request:</div>
            <ul class="mt-2 list-disc pl-5 space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars((string) $error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="results-card p-6" data-reveal data-reveal-delay="130">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-xl font-semibold text-ink-900">Result Sheet</h2>
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                <?php echo htmlspecialchars($term . ' • ' . ($academic_session !== '' ? $academic_session : 'Session Not Set')); ?>
            </span>
        </div>

        <?php if (empty($results)): ?>
            <div class="rounded-xl border border-dashed border-ink-900/15 bg-mist-50 px-5 py-9 text-center text-slate-600">
                <i class="fas fa-chart-bar text-2xl text-slate-400"></i>
                <p class="mt-3 text-sm font-semibold text-ink-900">No results available</p>
                <p class="mt-1 text-sm">No result records were found for <?php echo htmlspecialchars($term); ?> in <?php echo htmlspecialchars($academic_session !== '' ? $academic_session : 'Session Not Set'); ?>.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="result-table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>1st CA</th>
                            <th>2nd CA</th>
                            <th>Exam</th>
                            <th>Total</th>
                            <th>Grade</th>
                            <th>Remark</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                            <?php
                            $first_ca = (float) ($r['first_ca'] ?? 0);
                            $second_ca = (float) ($r['second_ca'] ?? 0);
                            $exam = (float) ($r['exam'] ?? 0);
                            $grand_total = $first_ca + $second_ca + $exam;

                            if ($grand_total >= 90) {
                                $gradeLetter = 'A';
                                $remark = 'Excellent';
                                $grade_class = 'grade-a';
                            } elseif ($grand_total >= 80) {
                                $gradeLetter = 'B';
                                $remark = 'Very Good';
                                $grade_class = 'grade-b';
                            } elseif ($grand_total >= 70) {
                                $gradeLetter = 'C';
                                $remark = 'Good';
                                $grade_class = 'grade-c';
                            } elseif ($grand_total >= 60) {
                                $gradeLetter = 'D';
                                $remark = 'Fair';
                                $grade_class = 'grade-d';
                            } elseif ($grand_total >= 50) {
                                $gradeLetter = 'E';
                                $remark = 'Pass';
                                $grade_class = 'grade-e';
                            } else {
                                $gradeLetter = 'F';
                                $remark = 'Fail';
                                $grade_class = 'grade-f';
                            }
                            ?>
                            <tr>
                                <td class="subject-cell"><?php echo htmlspecialchars((string) ($r['subject_name'] ?? 'N/A')); ?></td>
                                <td class="score-cell"><?php echo number_format($first_ca, 1); ?></td>
                                <td class="score-cell"><?php echo number_format($second_ca, 1); ?></td>
                                <td class="score-cell"><?php echo number_format($exam, 1); ?></td>
                                <td class="total-cell"><?php echo number_format($grand_total, 1); ?></td>
                                <td class="grade-cell"><span class="grade-badge <?php echo $grade_class; ?>"><?php echo $gradeLetter; ?></span></td>
                                <td><?php echo htmlspecialchars($remark); ?></td>
                                <td>
                                    <button type="button" class="complaint-btn" onclick="toggleComplaintForm(<?php echo (int) $r['id']; ?>)">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <span>Complain</span>
                                    </button>
                                    <div id="complaint-<?php echo (int) $r['id']; ?>" class="complaint-form" aria-hidden="true">
                                        <form method="POST" action="myresults.php?term=<?php echo urlencode($term); ?>&academic_session=<?php echo urlencode($academic_session); ?>" class="mt-2 space-y-2">
                                            <input type="hidden" name="action" value="submit_complaint">
                                            <input type="hidden" name="result_id" value="<?php echo (int) $r['id']; ?>">
                                            <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                                            <input type="hidden" name="academic_session" value="<?php echo htmlspecialchars($academic_session); ?>">
                                            <textarea name="complaint_text" rows="3" class="input-box" placeholder="Describe the concern about this result" required></textarea>
                                            <div class="flex gap-2">
                                                <button type="submit" class="btn btn-primary" style="height:2.25rem;padding-inline:1rem;">Submit</button>
                                                <button type="button" class="btn btn-outline" style="height:2.25rem;padding-inline:1rem;" onclick="toggleComplaintForm(<?php echo (int) $r['id']; ?>)">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="results-card p-6" data-reveal data-reveal-delay="150">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-xl font-semibold text-ink-900">My Complaints</h2>
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?php echo number_format(count($complaints)); ?> total</span>
        </div>

        <?php if (empty($complaints)): ?>
            <div class="rounded-xl border border-dashed border-ink-900/15 bg-mist-50 px-5 py-9 text-center text-slate-600">
                <i class="fas fa-comments text-2xl text-slate-400"></i>
                <p class="mt-3 text-sm font-semibold text-ink-900">No complaints submitted</p>
                <p class="mt-1 text-sm">You can submit a complaint from any result row when needed.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="result-table" style="min-width:940px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Subject</th>
                            <th>Term</th>
                            <th>Complaint</th>
                            <th>Status</th>
                            <th>Response</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $i => $c): ?>
                            <?php
                            $status = strtolower(trim((string) ($c['status'] ?? 'pending')));
                            $statusClass = in_array($status, ['pending', 'processing', 'resolved'], true) ? $status : 'pending';
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td class="subject-cell"><?php echo htmlspecialchars((string) ($c['subject_name'] ?? 'N/A')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($c['term'] ?? '-')); ?></td>
                                <td><?php echo nl2br(htmlspecialchars((string) ($c['complaint_text'] ?? ''))); ?></td>
                                <td><span class="status-badge status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($statusClass)); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($c['teacher_response'] ?? 'Awaiting response...')); ?></td>
                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime((string) ($c['created_at'] ?? 'now')))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
(function () {
    const sidebarOverlay = document.getElementById('studentSidebarOverlay');
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open');
        });
    }

    if (window.matchMedia('(min-width: 768px)').matches) {
        document.body.classList.remove('sidebar-open');
    }
})();

function downloadPDF() {
    const pdfForm = document.getElementById('pdfForm');
    if (pdfForm) {
        pdfForm.submit();
    }
}

function toggleComplaintForm(resultId) {
    const form = document.getElementById('complaint-' + resultId);
    if (!form) {
        return;
    }

    const isOpen = form.classList.contains('active');

    document.querySelectorAll('.complaint-form.active').forEach(function (item) {
        if (item !== form) {
            item.classList.remove('active');
            item.style.maxHeight = '0px';
            item.setAttribute('aria-hidden', 'true');
        }
    });

    if (isOpen) {
        form.classList.remove('active');
        form.style.maxHeight = '0px';
        form.setAttribute('aria-hidden', 'true');
    } else {
        form.classList.add('active');
        form.style.maxHeight = form.scrollHeight + 'px';
        form.setAttribute('aria-hidden', 'false');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>


