<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$uid = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;
$fallback_student_id = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
$errors = [];
$evaluations = [];

if (!$uid && !$admission_no && $fallback_student_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$current_school_id = get_current_school_id();

function term_label(string $raw): string
{
    $value = strtolower(trim($raw));
    $map = [
        '1' => 'Term 1', '1st' => 'Term 1', '1st term' => 'Term 1', 'first term' => 'Term 1',
        '2' => 'Term 2', '2nd' => 'Term 2', '2nd term' => 'Term 2', 'second term' => 'Term 2',
        '3' => 'Term 3', '3rd' => 'Term 3', '3rd term' => 'Term 3', 'third term' => 'Term 3',
    ];

    return $map[$value] ?? (trim($raw) !== '' ? ucfirst(trim($raw)) : 'Term N/A');
}

function normalize_rating(string $raw): string
{
    $value = strtolower(str_replace('_', '-', trim($raw)));
    $allowed = ['excellent', 'very-good', 'good', 'needs-improvement'];
    return in_array($value, $allowed, true) ? $value : 'good';
}

function rating_text(string $value): string
{
    $labels = [
        'excellent' => 'Excellent',
        'very-good' => 'Very Good',
        'good' => 'Good',
        'needs-improvement' => 'Needs Improvement',
    ];

    return $labels[$value] ?? ucfirst(str_replace('-', ' ', $value));
}

$student = null;

if ($admission_no) {
    $stmt = $pdo->prepare('SELECT id, full_name, class_id, admission_no FROM students WHERE admission_no = :admission_no AND school_id = :school_id LIMIT 1');
    $stmt->execute(['admission_no' => $admission_no, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$student && $uid) {
    $stmt = $pdo->prepare('SELECT id, full_name, class_id, admission_no FROM students WHERE (user_id = :uid OR id = :uid) AND school_id = :school_id LIMIT 1');
    $stmt->execute(['uid' => $uid, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$student && $fallback_student_id > 0) {
    $stmt = $pdo->prepare('SELECT id, full_name, class_id, admission_no FROM students WHERE id = :id AND school_id = :school_id LIMIT 1');
    $stmt->execute(['id' => $fallback_student_id, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$student) {
    header('Location: ../index.php?error=student_not_found');
    exit;
}

$student_id = (int) ($student['id'] ?? 0);
$class_id = (int) ($student['class_id'] ?? 0);
$student_name = trim((string) ($student['full_name'] ?? 'Student'));
if ($student_name === '') {
    $student_name = 'Student';
}
$admission_no = trim((string) ($student['admission_no'] ?? ''));

$class_name = 'N/A';
if ($class_id > 0) {
    $class_stmt = $pdo->prepare('SELECT class_name FROM classes WHERE id = :id AND school_id = :school_id LIMIT 1');
    $class_stmt->execute(['id' => $class_id, 'school_id' => $current_school_id]);
    $class_name = (string) ($class_stmt->fetchColumn() ?: 'N/A');
}

try {
    $stmt = $pdo->prepare('SELECT e.*, COALESCE(u.full_name, "Teacher") AS teacher_name FROM evaluations e LEFT JOIN users u ON e.teacher_id = u.id WHERE e.student_id = :student_id AND (e.school_id = :school_id OR e.school_id IS NULL) ORDER BY e.academic_year DESC, e.term DESC, e.created_at DESC, e.id DESC');
    $stmt->execute(['student_id' => $student_id, 'school_id' => $current_school_id]);
    $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = 'Unable to load your evaluations right now.';
    error_log('student/my-evaluations.php DB error: ' . $e->getMessage());
}

$rating_fields = ['academic', 'non_academic', 'cognitive', 'psychomotor', 'affective'];
$available_terms = [];
$available_years = [];
$now = time();

foreach ($evaluations as &$evaluation) {
    foreach ($rating_fields as $field) {
        $evaluation[$field] = normalize_rating((string) ($evaluation[$field] ?? ''));
    }

    $term = term_label((string) ($evaluation['term'] ?? ''));
    $year = trim((string) ($evaluation['academic_year'] ?? ''));
    $created_ts = strtotime((string) ($evaluation['created_at'] ?? ''));
    $updated_ts = strtotime((string) ($evaluation['updated_at'] ?? ''));
    $activity_ts = $updated_ts !== false ? $updated_ts : $created_ts;

    $evaluation['term_label'] = $term;
    $evaluation['year_label'] = $year !== '' ? $year : 'Year not set';
    $evaluation['activity_label'] = $activity_ts !== false ? date('M d, Y', $activity_ts) : 'No date';
    $evaluation['has_excellent'] = in_array('excellent', [$evaluation['academic'], $evaluation['non_academic'], $evaluation['cognitive'], $evaluation['psychomotor'], $evaluation['affective']], true) ? 1 : 0;
    $evaluation['needs_support'] = in_array('needs-improvement', [$evaluation['academic'], $evaluation['non_academic'], $evaluation['cognitive'], $evaluation['psychomotor'], $evaluation['affective']], true) ? 1 : 0;
    $evaluation['is_recent'] = ($activity_ts !== false && ($now - $activity_ts) <= (45 * 86400)) ? 1 : 0;
    $evaluation['search_blob'] = strtolower(trim(implode(' ', [$term, $year, (string) ($evaluation['teacher_name'] ?? ''), (string) ($evaluation['comments'] ?? ''), rating_text((string) $evaluation['academic']), rating_text((string) $evaluation['non_academic']), rating_text((string) $evaluation['cognitive']), rating_text((string) $evaluation['psychomotor']), rating_text((string) $evaluation['affective'])])));

    $available_terms[] = $term;
    if ($year !== '') {
        $available_years[] = $year;
    }
}
unset($evaluation);

$available_terms = array_values(array_unique($available_terms));
$available_years = array_values(array_unique($available_years));
usort($available_years, static function (string $a, string $b): int {
    return strcasecmp($b, $a);
});

$total_evaluations = count($evaluations);
$excellent_count = count(array_filter($evaluations, static function (array $item): bool {
    return (int) ($item['has_excellent'] ?? 0) === 1;
}));
$needs_support_count = count(array_filter($evaluations, static function (array $item): bool {
    return (int) ($item['needs_support'] ?? 0) === 1;
}));

$positive_ratings = 0;
foreach ($evaluations as $evaluation) {
    foreach ($rating_fields as $field) {
        if (in_array((string) ($evaluation[$field] ?? ''), ['excellent', 'very-good'], true)) {
            $positive_ratings++;
        }
    }
}

$total_ratings = max(1, $total_evaluations * count($rating_fields));
$overall_progress = $total_evaluations > 0 ? (int) round(($positive_ratings / $total_ratings) * 100) : 0;
$current_term = $total_evaluations > 0 ? (string) ($evaluations[0]['term_label'] ?? 'N/A') : 'N/A';
$latest_year = $total_evaluations > 0 ? (string) ($evaluations[0]['year_label'] ?? 'Year not set') : 'Year not set';

$pageTitle = 'My Evaluations | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$css_version = @filemtime(__DIR__ . '/../assets/css/student-evaluations.css') ?: time();
$extraHead = '<link rel="stylesheet" href="../assets/css/student-evaluations.css?v=' . rawurlencode((string) $css_version) . '">';

require_once __DIR__ . '/../includes/student_header.php';
?>

<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-evaluations-page space-y-6">
    <section class="evaluation-panel overflow-hidden p-0" data-reveal>
        <div class="evaluation-hero p-6 sm:p-8 text-white">
            <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs uppercase tracking-[0.32em] text-white/75">Assessment Workspace</p>
                    <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">My evaluations with clearer structure and stronger visibility.</h1>
                    <p class="mt-3 max-w-2xl text-sm text-white/85 sm:text-base">
                        <?php echo htmlspecialchars($student_name); ?><?php echo $admission_no !== '' ? ' (' . htmlspecialchars($admission_no) . ')' : ''; ?> in <?php echo htmlspecialchars($class_name); ?>.
                        Review teacher ratings and comments with the same visual language used in the teacher subject workspace.
                    </p>
                </div>
                <div class="evaluation-hero-actions grid gap-3 sm:grid-cols-2">
                    <a href="#evaluation-library" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95"><i class="fas fa-table-list"></i><span>Browse Records</span></a>
                    <a href="myresults.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/30 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15"><i class="fas fa-chart-line"></i><span>Open Results</span></a>
                </div>
            </div>
        </div>
        <div class="evaluation-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
            <article class="evaluation-metric-card">
                <div class="evaluation-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-clipboard-list"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Total Evaluations</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($total_evaluations); ?></h2>
                <p class="text-sm text-slate-500">All published records</p>
            </article>
            <article class="evaluation-metric-card">
                <div class="evaluation-metric-icon bg-emerald-600/10 text-emerald-700"><i class="fas fa-star"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Excellent Signals</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($excellent_count); ?></h2>
                <p class="text-sm text-slate-500">At least one excellent rating</p>
            </article>
            <article class="evaluation-metric-card">
                <div class="evaluation-metric-icon bg-rose-500/10 text-rose-700"><i class="fas fa-triangle-exclamation"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Needs Attention</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($needs_support_count); ?></h2>
                <p class="text-sm text-slate-500">Contains improvement flags</p>
            </article>
            <article class="evaluation-metric-card">
                <div class="evaluation-metric-icon bg-sky-600/10 text-sky-700"><i class="fas fa-calendar-alt"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Current Term</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($current_term); ?></h2>
                <p class="text-sm text-slate-500"><?php echo htmlspecialchars($latest_year); ?></p>
            </article>
            <article class="evaluation-metric-card">
                <div class="evaluation-metric-icon bg-amber-500/10 text-amber-700"><i class="fas fa-chart-pie"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Progress Score</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($overall_progress); ?>%</h2>
                <p class="text-sm text-slate-500">Positive rating strength</p>
            </article>
        </div>
    </section>

    <?php if (!empty($errors)): ?>
        <section class="space-y-3" data-reveal data-reveal-delay="50">
            <?php foreach ($errors as $error): ?>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                    <div class="flex items-start gap-3"><i class="fas fa-circle-exclamation mt-0.5"></i><span><?php echo htmlspecialchars((string) $error); ?></span></div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section id="evaluation-library" class="evaluation-panel rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft sm:p-6" data-reveal data-reveal-delay="80">
        <div class="evaluation-toolbar flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.28em] text-slate-500">Evaluation Directory</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900">Browse and filter your evaluation records</h2>
                <p class="mt-2 max-w-2xl text-sm text-slate-600">Use search, term, year, and status signals to review your evaluation history without leaving the page.</p>
            </div>
            <div class="evaluation-toolbar-controls flex flex-col gap-3 sm:flex-row">
                <label class="relative min-w-0 sm:min-w-[280px]"><span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-search"></i></span><input type="search" data-evaluation-search placeholder="Search by term, rating, teacher, or comment" class="w-full rounded-2xl border border-ink-900/10 bg-white px-11 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"></label>
                <select data-evaluation-term class="w-full rounded-2xl border border-ink-900/10 bg-white px-4 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100 sm:w-[170px]"><option value="all">All Terms</option><?php foreach ($available_terms as $term_option): ?><option value="<?php echo htmlspecialchars(strtolower($term_option)); ?>"><?php echo htmlspecialchars($term_option); ?></option><?php endforeach; ?></select>
                <select data-evaluation-year class="w-full rounded-2xl border border-ink-900/10 bg-white px-4 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100 sm:w-[190px]"><option value="all">All Years</option><?php foreach ($available_years as $year_option): ?><option value="<?php echo htmlspecialchars(strtolower($year_option)); ?>"><?php echo htmlspecialchars($year_option); ?></option><?php endforeach; ?></select>
                <div class="flex items-center justify-between rounded-2xl border border-ink-900/10 bg-mist-50 px-4 py-3 text-sm font-semibold text-slate-600 sm:min-w-[130px]"><span class="inline-flex items-center gap-2"><i class="fas fa-filter text-teal-700"></i><span>Visible</span></span><span data-visible-count><?php echo number_format($total_evaluations); ?></span></div>
            </div>
        </div>

        <div class="mt-5 flex gap-2 overflow-x-auto pb-1" data-evaluation-chip-group>
            <button type="button" class="evaluation-filter-chip is-active" data-chip="all"><i class="fas fa-layer-group"></i><span>All Records</span></button>
            <button type="button" class="evaluation-filter-chip" data-chip="excellent"><i class="fas fa-star"></i><span>Excellent Signals</span></button>
            <button type="button" class="evaluation-filter-chip" data-chip="needs-support"><i class="fas fa-triangle-exclamation"></i><span>Needs Attention</span></button>
            <button type="button" class="evaluation-filter-chip" data-chip="recent"><i class="fas fa-clock"></i><span>Recent (45 days)</span></button>
        </div>

        <?php if (empty($evaluations)): ?>
            <div class="evaluation-empty-state mt-6"><span class="evaluation-empty-icon"><i class="fas fa-clipboard-list"></i></span><h3 class="text-lg font-semibold text-ink-900">No evaluations published yet</h3><p class="mt-2 max-w-md text-sm text-slate-500">Your teacher has not submitted an evaluation for your profile yet. Once available, records will appear automatically.</p></div>
        <?php else: ?>
            <div class="mt-6 hidden overflow-hidden rounded-3xl border border-ink-900/5 xl:block">
                <div class="evaluation-table-wrap">
                    <table class="evaluation-table min-w-full divide-y divide-ink-900/5">
                        <thead class="bg-mist-50">
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500"><th class="px-5 py-4 font-semibold">Session</th><th class="px-4 py-4 font-semibold">Academic</th><th class="px-4 py-4 font-semibold">Non-Academic</th><th class="px-4 py-4 font-semibold">Cognitive</th><th class="px-4 py-4 font-semibold">Psychomotor</th><th class="px-4 py-4 font-semibold">Affective</th><th class="px-5 py-4 font-semibold">Teacher</th><th class="px-5 py-4 font-semibold">Updated</th><th class="px-5 py-4 font-semibold">Action</th></tr>
                        </thead>
                        <tbody class="divide-y divide-ink-900/5 bg-white">
                            <?php foreach ($evaluations as $evaluation): ?>
                                <?php $evaluation_id = (int) ($evaluation['id'] ?? 0); $term = (string) ($evaluation['term_label'] ?? 'Term N/A'); $year = (string) ($evaluation['year_label'] ?? 'Year not set'); ?>
                                <tr data-evaluation-item data-evaluation-primary="1" data-term="<?php echo htmlspecialchars(strtolower($term)); ?>" data-year="<?php echo htmlspecialchars(strtolower($year)); ?>" data-has-excellent="<?php echo (int) ($evaluation['has_excellent'] ?? 0); ?>" data-needs-support="<?php echo (int) ($evaluation['needs_support'] ?? 0); ?>" data-recent="<?php echo (int) ($evaluation['is_recent'] ?? 0); ?>" data-search="<?php echo htmlspecialchars((string) ($evaluation['search_blob'] ?? '')); ?>" class="evaluation-table-row align-top">
                                    <td class="px-5 py-4"><div class="space-y-1"><span class="session-pill"><?php echo htmlspecialchars($term); ?></span><p class="text-xs text-slate-500"><?php echo htmlspecialchars($year); ?></p></div></td>
                                    <td class="px-4 py-4"><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['academic']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['academic'])); ?></span></td>
                                    <td class="px-4 py-4"><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['non_academic']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['non_academic'])); ?></span></td>
                                    <td class="px-4 py-4"><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['cognitive']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['cognitive'])); ?></span></td>
                                    <td class="px-4 py-4"><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['psychomotor']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['psychomotor'])); ?></span></td>
                                    <td class="px-4 py-4"><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['affective']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['affective'])); ?></span></td>
                                    <td class="px-5 py-4 text-sm font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($evaluation['teacher_name'] ?? 'Teacher')); ?></td>
                                    <td class="px-5 py-4 text-sm text-slate-600"><?php echo htmlspecialchars((string) ($evaluation['activity_label'] ?? 'No date')); ?></td>
                                    <td class="px-5 py-4"><div class="flex flex-wrap gap-2"><button type="button" class="evaluation-action-pill evaluation-action-secondary" data-evaluation-id="<?php echo $evaluation_id; ?>"><i class="fas fa-expand"></i><span>View</span></button><a href="print-evaluation.php?id=<?php echo $evaluation_id; ?>" target="_blank" rel="noopener" class="evaluation-action-pill evaluation-action-primary"><i class="fas fa-print"></i><span>Print</span></a></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-6 grid gap-4 xl:hidden">
                <?php foreach ($evaluations as $evaluation): ?>
                    <?php
                    $evaluation_id = (int) ($evaluation['id'] ?? 0);
                    $term = (string) ($evaluation['term_label'] ?? 'Term N/A');
                    $year = (string) ($evaluation['year_label'] ?? 'Year not set');
                    $preview = trim((string) ($evaluation['comments'] ?? ''));
                    $preview = $preview !== '' ? mb_substr($preview, 0, 150) : '';
                    ?>
                    <article data-evaluation-item data-term="<?php echo htmlspecialchars(strtolower($term)); ?>" data-year="<?php echo htmlspecialchars(strtolower($year)); ?>" data-has-excellent="<?php echo (int) ($evaluation['has_excellent'] ?? 0); ?>" data-needs-support="<?php echo (int) ($evaluation['needs_support'] ?? 0); ?>" data-recent="<?php echo (int) ($evaluation['is_recent'] ?? 0); ?>" data-search="<?php echo htmlspecialchars((string) ($evaluation['search_blob'] ?? '')); ?>" class="rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0"><span class="session-pill"><?php echo htmlspecialchars($term); ?></span><p class="mt-2 text-sm text-slate-500"><?php echo htmlspecialchars($year); ?></p></div>
                            <span class="rounded-full bg-mist-50 px-3 py-1 text-xs font-semibold text-slate-600"><?php echo htmlspecialchars((string) ($evaluation['activity_label'] ?? 'No date')); ?></span>
                        </div>
                        <div class="mt-4 rounded-2xl border border-ink-900/10 bg-mist-50 px-4 py-3">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Evaluated by</p>
                            <p class="mt-1 text-sm font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($evaluation['teacher_name'] ?? 'Teacher')); ?></p>
                        </div>
                        <div class="mt-4 grid gap-2 sm:grid-cols-2">
                            <div class="mobile-rating-item"><span class="mobile-rating-label">Academic</span><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['academic']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['academic'])); ?></span></div>
                            <div class="mobile-rating-item"><span class="mobile-rating-label">Non-Academic</span><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['non_academic']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['non_academic'])); ?></span></div>
                            <div class="mobile-rating-item"><span class="mobile-rating-label">Cognitive</span><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['cognitive']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['cognitive'])); ?></span></div>
                            <div class="mobile-rating-item"><span class="mobile-rating-label">Psychomotor</span><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['psychomotor']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['psychomotor'])); ?></span></div>
                            <div class="mobile-rating-item sm:col-span-2"><span class="mobile-rating-label">Affective</span><span class="rating-pill rating-<?php echo htmlspecialchars((string) $evaluation['affective']); ?>"><?php echo htmlspecialchars(rating_text((string) $evaluation['affective'])); ?></span></div>
                        </div>
                        <?php if ($preview !== ''): ?>
                            <p class="mt-4 text-sm leading-6 text-slate-600"><?php echo htmlspecialchars($preview); ?><?php echo mb_strlen((string) ($evaluation['comments'] ?? '')) > 150 ? '...' : ''; ?></p>
                        <?php endif; ?>
                        <div class="mt-5 flex flex-wrap gap-2">
                            <button type="button" class="evaluation-action-pill evaluation-action-secondary" data-evaluation-id="<?php echo $evaluation_id; ?>"><i class="fas fa-expand"></i><span>View Details</span></button>
                            <a href="print-evaluation.php?id=<?php echo $evaluation_id; ?>" target="_blank" rel="noopener" class="evaluation-action-pill evaluation-action-primary"><i class="fas fa-print"></i><span>Print</span></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="evaluation-empty-state mt-6 hidden" data-filter-empty>
                <span class="evaluation-empty-icon"><i class="fas fa-filter-circle-xmark"></i></span>
                <h3 class="text-lg font-semibold text-ink-900">No records match this filter</h3>
                <p class="mt-2 max-w-md text-sm text-slate-500">Try a different term, year, or search query.</p>
            </div>
        <?php endif; ?>
    </section>
</main>

<div id="evaluationDetailModal" class="evaluation-modal" aria-hidden="true">
    <div class="evaluation-modal-backdrop" data-modal-close></div>
    <div class="evaluation-modal-card" role="dialog" aria-modal="true" aria-labelledby="evaluationDetailTitle">
        <div class="evaluation-modal-header">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-teal-700">Evaluation Details</p>
                <h2 id="evaluationDetailTitle" class="mt-2 text-2xl font-semibold text-ink-900">Record Summary</h2>
            </div>
            <button type="button" class="evaluation-modal-close" data-modal-close aria-label="Close details"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="evaluation-modal-body space-y-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="evaluation-modal-info"><p class="evaluation-modal-label">Student</p><p class="evaluation-modal-value"><?php echo htmlspecialchars($student_name); ?></p></div>
                <div class="evaluation-modal-info"><p class="evaluation-modal-label">Class</p><p class="evaluation-modal-value"><?php echo htmlspecialchars($class_name); ?></p></div>
                <div class="evaluation-modal-info"><p class="evaluation-modal-label">Session</p><p id="modalSession" class="evaluation-modal-value">-</p></div>
                <div class="evaluation-modal-info"><p class="evaluation-modal-label">Teacher</p><p id="modalTeacher" class="evaluation-modal-value">-</p></div>
                <div class="evaluation-modal-info"><p class="evaluation-modal-label">Updated</p><p id="modalUpdated" class="evaluation-modal-value">-</p></div>
                <div class="evaluation-modal-info"><p class="evaluation-modal-label">Admission No</p><p class="evaluation-modal-value"><?php echo htmlspecialchars($admission_no !== '' ? $admission_no : 'N/A'); ?></p></div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="evaluation-modal-rating-row"><span class="evaluation-modal-rating-label">Academic</span><span id="modalAcademic"></span></div>
                <div class="evaluation-modal-rating-row"><span class="evaluation-modal-rating-label">Non-Academic</span><span id="modalNonAcademic"></span></div>
                <div class="evaluation-modal-rating-row"><span class="evaluation-modal-rating-label">Cognitive</span><span id="modalCognitive"></span></div>
                <div class="evaluation-modal-rating-row"><span class="evaluation-modal-rating-label">Psychomotor</span><span id="modalPsychomotor"></span></div>
                <div class="evaluation-modal-rating-row sm:col-span-2"><span class="evaluation-modal-rating-label">Affective</span><span id="modalAffective"></span></div>
            </div>
            <div class="rounded-2xl border border-ink-900/10 bg-slate-50 px-4 py-4">
                <p class="text-sm font-semibold text-ink-900">Teacher Comments</p>
                <p id="modalComments" class="mt-2 text-sm leading-6 text-slate-700">No comments were provided for this evaluation.</p>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var sidebarOverlay = document.getElementById('studentSidebarOverlay');
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open');
        });
    }

    if (window.matchMedia('(min-width: 768px)').matches) {
        document.body.classList.remove('sidebar-open');
    }

    var records = <?php
        echo json_encode(array_map(static function (array $item): array {
            return [
                'id' => (int) ($item['id'] ?? 0),
                'session' => (string) (($item['term_label'] ?? 'Term N/A') . ' | ' . ($item['year_label'] ?? 'Year not set')),
                'teacher' => (string) ($item['teacher_name'] ?? 'Teacher'),
                'updated' => (string) ($item['activity_label'] ?? 'No date'),
                'academic' => (string) ($item['academic'] ?? 'good'),
                'non_academic' => (string) ($item['non_academic'] ?? 'good'),
                'cognitive' => (string) ($item['cognitive'] ?? 'good'),
                'psychomotor' => (string) ($item['psychomotor'] ?? 'good'),
                'affective' => (string) ($item['affective'] ?? 'good'),
                'comments' => (string) ($item['comments'] ?? ''),
            ];
        }, $evaluations), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    ?>;

    var ratingLabels = {
        'excellent': 'Excellent',
        'very-good': 'Very Good',
        'good': 'Good',
        'needs-improvement': 'Needs Improvement'
    };

    function escapeHtml(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function badge(value) {
        var key = String(value || '').trim().toLowerCase().replace(/_/g, '-');
        if (!ratingLabels[key]) {
            key = 'good';
        }
        return '<span class="rating-pill rating-' + key + '">' + escapeHtml(ratingLabels[key]) + '</span>';
    }

    var byId = {};
    records.forEach(function (record) {
        byId[String(record.id)] = record;
    });

    var modal = document.getElementById('evaluationDetailModal');
    var modalSession = document.getElementById('modalSession');
    var modalTeacher = document.getElementById('modalTeacher');
    var modalUpdated = document.getElementById('modalUpdated');
    var modalAcademic = document.getElementById('modalAcademic');
    var modalNonAcademic = document.getElementById('modalNonAcademic');
    var modalCognitive = document.getElementById('modalCognitive');
    var modalPsychomotor = document.getElementById('modalPsychomotor');
    var modalAffective = document.getElementById('modalAffective');
    var modalComments = document.getElementById('modalComments');

    function closeModal() {
        if (!modal) {
            return;
        }
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }

    function openModal(id) {
        var record = byId[String(id)];
        if (!record || !modal) {
            return;
        }

        modalSession.textContent = record.session;
        modalTeacher.textContent = record.teacher;
        modalUpdated.textContent = record.updated;
        modalAcademic.innerHTML = badge(record.academic);
        modalNonAcademic.innerHTML = badge(record.non_academic);
        modalCognitive.innerHTML = badge(record.cognitive);
        modalPsychomotor.innerHTML = badge(record.psychomotor);
        modalAffective.innerHTML = badge(record.affective);
        modalComments.innerHTML = record.comments ? escapeHtml(record.comments).replace(/\n/g, '<br>') : 'No comments were provided for this evaluation.';

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }

    Array.prototype.slice.call(document.querySelectorAll('[data-evaluation-id]')).forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(button.getAttribute('data-evaluation-id'));
        });
    });

    Array.prototype.slice.call(document.querySelectorAll('[data-modal-close]')).forEach(function (closeButton) {
        closeButton.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    var searchInput = document.querySelector('[data-evaluation-search]');
    var termFilter = document.querySelector('[data-evaluation-term]');
    var yearFilter = document.querySelector('[data-evaluation-year]');
    var chipGroup = document.querySelector('[data-evaluation-chip-group]');
    var visibleCount = document.querySelector('[data-visible-count]');
    var emptyState = document.querySelector('[data-filter-empty]');
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-evaluation-item]'));
    var activeChip = 'all';

    function chipMatch(item, chip) {
        if (chip === 'excellent') {
            return item.dataset.hasExcellent === '1';
        }
        if (chip === 'needs-support') {
            return item.dataset.needsSupport === '1';
        }
        if (chip === 'recent') {
            return item.dataset.recent === '1';
        }
        return true;
    }

    function applyFilters() {
        var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var term = termFilter ? termFilter.value : 'all';
        var year = yearFilter ? yearFilter.value : 'all';
        var visible = 0;

        items.forEach(function (item) {
            var show = (query === '' || (item.dataset.search || '').indexOf(query) !== -1)
                && (term === 'all' || (item.dataset.term || '') === term)
                && (year === 'all' || (item.dataset.year || '') === year)
                && chipMatch(item, activeChip);

            item.classList.toggle('hidden', !show);
            if (show && item.dataset.evaluationPrimary === '1') {
                visible += 1;
            }
        });

        if (visibleCount) {
            visibleCount.textContent = String(visible);
        }

        if (emptyState) {
            emptyState.classList.toggle('hidden', visible !== 0);
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }
    if (termFilter) {
        termFilter.addEventListener('change', applyFilters);
    }
    if (yearFilter) {
        yearFilter.addEventListener('change', applyFilters);
    }

    if (chipGroup) {
        Array.prototype.slice.call(chipGroup.querySelectorAll('[data-chip]')).forEach(function (chip) {
            chip.addEventListener('click', function () {
                activeChip = chip.getAttribute('data-chip') || 'all';
                Array.prototype.slice.call(chipGroup.querySelectorAll('[data-chip]')).forEach(function (item) {
                    item.classList.remove('is-active');
                });
                chip.classList.add('is-active');
                applyFilters();
            });
        });
    }

    applyFilters();
})();
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
