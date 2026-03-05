<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$uid = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;
$fallback_student_id = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
$student_name = trim((string) ($_SESSION['student_name'] ?? $_SESSION['full_name'] ?? 'Student'));
$errors = [];
$info_message = '';
$subjects = [];

if (!$uid && !$admission_no && $fallback_student_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$current_school_id = get_current_school_id();
$student = null;

if ($admission_no) {
    $stmt = $pdo->prepare('SELECT id, full_name, class_id, admission_no FROM students WHERE admission_no = :admission_no AND school_id = :school_id LIMIT 1');
    $stmt->execute([
        'admission_no' => $admission_no,
        'school_id' => $current_school_id,
    ]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$student && $uid) {
    $stmt = $pdo->prepare('SELECT id, full_name, class_id, admission_no FROM students WHERE (user_id = :uid OR id = :uid) AND school_id = :school_id LIMIT 1');
    $stmt->execute([
        'uid' => $uid,
        'school_id' => $current_school_id,
    ]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$student && $fallback_student_id > 0) {
    $stmt = $pdo->prepare('SELECT id, full_name, class_id, admission_no FROM students WHERE id = :id AND school_id = :school_id LIMIT 1');
    $stmt->execute([
        'id' => $fallback_student_id,
        'school_id' => $current_school_id,
    ]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$student) {
    header('Location: ../index.php?error=student_not_found');
    exit;
}

$student_id = (int) ($student['id'] ?? 0);
$class_id = (int) ($student['class_id'] ?? 0);
$student_name = trim((string) ($student['full_name'] ?? $student_name));
if ($student_name === '') {
    $student_name = 'Student';
}
$admission_no = trim((string) ($student['admission_no'] ?? $admission_no ?? ''));

if ($class_id <= 0) {
    $errors[] = 'Your profile is missing a class assignment. Please contact administration.';
}

$class_name = 'N/A';
if ($class_id > 0) {
    $class_stmt = $pdo->prepare('SELECT class_name FROM classes WHERE id = :id AND school_id = :school_id LIMIT 1');
    $class_stmt->execute([
        'id' => $class_id,
        'school_id' => $current_school_id,
    ]);
    $class_name = (string) ($class_stmt->fetchColumn() ?: 'N/A');
}

if (empty($errors) && $class_id > 0) {
    try {
        $subject_stmt = $pdo->prepare(
            'SELECT
                s.id AS subject_id,
                s.subject_name,
                s.subject_code,
                s.description AS subject_description,
                sa.teacher_id,
                u.full_name AS teacher_name,
                u.email AS teacher_email,
                sa.assigned_at
             FROM subject_assignments sa
             INNER JOIN subjects s ON sa.subject_id = s.id AND s.school_id = :school_id
             LEFT JOIN users u ON sa.teacher_id = u.id
             WHERE sa.class_id = :class_id
             ORDER BY s.subject_name ASC'
        );

        $subject_stmt->execute([
            'class_id' => $class_id,
            'school_id' => $current_school_id,
        ]);
        $subjects = $subject_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subjects)) {
            $info_message = 'No subjects have been assigned to your class yet.';
        }
    } catch (PDOException $e) {
        $errors[] = 'Unable to load your subjects at the moment.';
        error_log('student/mysubjects.php DB error: ' . $e->getMessage());
    }
}

$total_subjects = count($subjects);
$assigned_teachers = count(array_filter($subjects, static function (array $subject): bool {
    return trim((string) ($subject['teacher_name'] ?? '')) !== '';
}));
$subjects_with_codes = count(array_filter($subjects, static function (array $subject): bool {
    return trim((string) ($subject['subject_code'] ?? '')) !== '';
}));
$subjects_with_description = count(array_filter($subjects, static function (array $subject): bool {
    return trim((string) ($subject['subject_description'] ?? '')) !== '';
}));

$pageTitle = 'My Subjects | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$stylesheetVersion = @filemtime(__DIR__ . '/../assets/css/student-subjects.css') ?: time();
$extraHead = '<link rel="stylesheet" href="../assets/css/student-subjects.css?v=' . rawurlencode((string) $stylesheetVersion) . '">';

require_once __DIR__ . '/../includes/student_header.php';
?>

<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-subjects-page space-y-6">
    <section class="subject-panel overflow-hidden p-0" data-reveal>
        <div class="subject-hero p-6 sm:p-8 text-white">
            <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs uppercase tracking-[0.32em] text-white/75">Academic Structure</p>
                    <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">My subjects with clearer structure and better visibility.</h1>
                    <p class="mt-3 max-w-2xl text-sm text-white/85 sm:text-base">
                        <?php echo htmlspecialchars($student_name); ?>
                        in <?php echo htmlspecialchars($class_name); ?>.
                        Track subject allocation, teacher coverage, and contact details in one workspace.
                    </p>
                </div>
                <div class="subject-hero-actions grid gap-3 sm:grid-cols-2">
                    <a href="#subject-directory" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                        <i class="fas fa-table-list"></i>
                        <span>Browse Subjects</span>
                    </a>
                    <a href="myresults.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/30 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                        <i class="fas fa-chart-line"></i>
                        <span>View Results</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="subject-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-4">
            <article class="subject-metric-card">
                <div class="subject-metric-icon bg-teal-600/10 text-teal-700">
                    <i class="fas fa-book-open"></i>
                </div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Total Subjects</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($total_subjects); ?></h2>
                <p class="text-sm text-slate-500">Allocated to your class</p>
            </article>
            <article class="subject-metric-card">
                <div class="subject-metric-icon bg-sky-600/10 text-sky-700">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Assigned Teachers</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($assigned_teachers); ?></h2>
                <p class="text-sm text-slate-500">With active teacher records</p>
            </article>
            <article class="subject-metric-card">
                <div class="subject-metric-icon bg-emerald-600/10 text-emerald-700">
                    <i class="fas fa-hashtag"></i>
                </div>
                <p class="text-xs uppercase tracking-wide text-slate-500">With Subject Codes</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($subjects_with_codes); ?></h2>
                <p class="text-sm text-slate-500">Ready for formal reporting</p>
            </article>
            <article class="subject-metric-card">
                <div class="subject-metric-icon bg-amber-500/10 text-amber-600">
                    <i class="fas fa-file-lines"></i>
                </div>
                <p class="text-xs uppercase tracking-wide text-slate-500">With Descriptions</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($subjects_with_description); ?></h2>
                <p class="text-sm text-slate-500">Subjects with learning context</p>
            </article>
        </div>
    </section>

    <?php if (!empty($errors)): ?>
        <section class="space-y-3" data-reveal data-reveal-delay="60">
            <?php foreach ($errors as $error): ?>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-circle-exclamation mt-0.5"></i>
                        <span><?php echo htmlspecialchars((string) $error); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($info_message !== ''): ?>
        <section data-reveal data-reveal-delay="70">
            <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-medium text-sky-700">
                <div class="flex items-start gap-3">
                    <i class="fas fa-circle-info mt-0.5"></i>
                    <span><?php echo htmlspecialchars($info_message); ?></span>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section id="subject-directory" class="subject-panel rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft sm:p-6" data-reveal data-reveal-delay="90">
        <div class="subject-toolbar flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.28em] text-slate-500">Subject Directory</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900">Browse and filter your class subject list</h2>
                <p class="mt-2 max-w-2xl text-sm text-slate-600">Search by subject name, code, teacher, or description. This layout mirrors the teacher workspace so navigation remains familiar.</p>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row">
                <label class="relative min-w-0 sm:min-w-[280px]">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                        <i class="fas fa-search"></i>
                    </span>
                    <input type="search" data-subject-search placeholder="Search by subject, code, teacher, or description" class="w-full rounded-2xl border border-ink-900/10 bg-white px-11 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                </label>
                <div class="flex items-center justify-between rounded-2xl border border-ink-900/10 bg-mist-50 px-4 py-3 text-sm font-semibold text-slate-600">
                    <span class="inline-flex items-center gap-2">
                        <i class="fas fa-filter text-teal-700"></i>
                        <span>Visible</span>
                    </span>
                    <span data-visible-count><?php echo number_format($total_subjects); ?></span>
                </div>
            </div>
        </div>

        <div class="mt-5 flex gap-2 overflow-x-auto pb-1" data-filter-group>
            <button type="button" class="subject-filter-chip is-active" data-filter="all">
                <i class="fas fa-layer-group"></i>
                <span>All Subjects</span>
            </button>
            <button type="button" class="subject-filter-chip" data-filter="with-teacher">
                <i class="fas fa-user-check"></i>
                <span>With Teacher</span>
            </button>
            <button type="button" class="subject-filter-chip" data-filter="without-teacher">
                <i class="fas fa-user-clock"></i>
                <span>Without Teacher</span>
            </button>
            <button type="button" class="subject-filter-chip" data-filter="with-code">
                <i class="fas fa-hashtag"></i>
                <span>With Code</span>
            </button>
        </div>

        <?php if (empty($subjects)): ?>
            <div class="subject-empty-state mt-6">
                <span class="subject-empty-icon">
                    <i class="fas fa-book-open-reader"></i>
                </span>
                <h3 class="text-lg font-semibold text-ink-900">No subjects available</h3>
                <p class="mt-2 max-w-md text-sm text-slate-500">Your class does not have subject assignments yet. Once assignments are published, they will appear here automatically.</p>
            </div>
        <?php else: ?>
            <div class="mt-6 hidden overflow-hidden rounded-3xl border border-ink-900/5 xl:block">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-900/5">
                        <thead class="bg-mist-50">
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-5 py-4 font-semibold">Subject</th>
                                <th class="px-5 py-4 font-semibold">Code</th>
                                <th class="px-5 py-4 font-semibold">Teacher</th>
                                <th class="px-5 py-4 font-semibold">Contact</th>
                                <th class="px-5 py-4 font-semibold">Status</th>
                                <th class="px-5 py-4 font-semibold">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-900/5 bg-white">
                            <?php foreach ($subjects as $subject): ?>
                                <?php
                                $subject_name = trim((string) ($subject['subject_name'] ?? 'Untitled Subject'));
                                $subject_code = trim((string) ($subject['subject_code'] ?? ''));
                                $subject_description = trim((string) ($subject['subject_description'] ?? ''));
                                $teacher_name = trim((string) ($subject['teacher_name'] ?? ''));
                                $teacher_email = trim((string) ($subject['teacher_email'] ?? ''));
                                $has_teacher = $teacher_name !== '';
                                $has_code = $subject_code !== '';
                                $search_blob = strtolower(trim($subject_name . ' ' . $subject_code . ' ' . $teacher_name . ' ' . $teacher_email . ' ' . $subject_description));
                                ?>
                                <tr
                                    data-subject-item
                                    data-subject-primary="1"
                                    data-filter-with-teacher="<?php echo $has_teacher ? '1' : '0'; ?>"
                                    data-filter-with-code="<?php echo $has_code ? '1' : '0'; ?>"
                                    data-search="<?php echo htmlspecialchars($search_blob); ?>"
                                    class="subject-table-row align-top"
                                >
                                    <td class="px-5 py-4">
                                        <div class="flex items-start gap-3">
                                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-teal-600/10 text-teal-700">
                                                <i class="fas fa-book-open"></i>
                                            </span>
                                            <div>
                                                <p class="text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($subject_name); ?></p>
                                                <p class="mt-1 text-xs text-slate-500">ID #<?php echo (int) ($subject['subject_id'] ?? 0); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($subject_code !== '' ? $subject_code : 'Not set'); ?></td>
                                    <td class="px-5 py-4 text-sm text-ink-900">
                                        <?php if ($has_teacher): ?>
                                            <div class="space-y-1">
                                                <p class="font-semibold"><?php echo htmlspecialchars($teacher_name); ?></p>
                                                <?php if ($teacher_email !== ''): ?>
                                                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars($teacher_email); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-500">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($teacher_email !== ''): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($teacher_email); ?>" class="subject-action-link">
                                                <i class="fas fa-envelope"></i>
                                                <span>Contact</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-sm text-slate-400">No email</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="subject-pill <?php echo $has_teacher ? 'subject-pill-success' : 'subject-pill-warning'; ?>">
                                            <i class="fas <?php echo $has_teacher ? 'fa-circle-check' : 'fa-clock'; ?>"></i>
                                            <span><?php echo $has_teacher ? 'Teacher Assigned' : 'Pending Teacher'; ?></span>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-slate-600"><?php echo htmlspecialchars($subject_description !== '' ? $subject_description : 'No description added yet.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6 grid gap-4 xl:hidden">
                <?php foreach ($subjects as $subject): ?>
                    <?php
                    $subject_name = trim((string) ($subject['subject_name'] ?? 'Untitled Subject'));
                    $subject_code = trim((string) ($subject['subject_code'] ?? ''));
                    $subject_description = trim((string) ($subject['subject_description'] ?? ''));
                    $teacher_name = trim((string) ($subject['teacher_name'] ?? ''));
                    $teacher_email = trim((string) ($subject['teacher_email'] ?? ''));
                    $has_teacher = $teacher_name !== '';
                    $has_code = $subject_code !== '';
                    $search_blob = strtolower(trim($subject_name . ' ' . $subject_code . ' ' . $teacher_name . ' ' . $teacher_email . ' ' . $subject_description));
                    $assigned_at_label = '';
                    $assigned_at_raw = trim((string) ($subject['assigned_at'] ?? ''));
                    if ($assigned_at_raw !== '') {
                        $assigned_at_ts = strtotime($assigned_at_raw);
                        if ($assigned_at_ts !== false) {
                            $assigned_at_label = date('M d, Y', $assigned_at_ts);
                        }
                    }
                    ?>
                    <article
                        data-subject-item
                        data-filter-with-teacher="<?php echo $has_teacher ? '1' : '0'; ?>"
                        data-filter-with-code="<?php echo $has_code ? '1' : '0'; ?>"
                        data-search="<?php echo htmlspecialchars($search_blob); ?>"
                        class="rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-teal-600/10 text-teal-700">
                                    <i class="fas fa-book-open"></i>
                                </span>
                                <div class="min-w-0">
                                    <h3 class="truncate text-lg font-semibold text-ink-900"><?php echo htmlspecialchars($subject_name); ?></h3>
                                    <p class="mt-1 text-sm text-slate-500"><?php echo htmlspecialchars($subject_code !== '' ? $subject_code : 'Code not set'); ?></p>
                                </div>
                            </div>
                            <span class="rounded-full bg-mist-50 px-3 py-1 text-xs font-semibold text-slate-600">#<?php echo (int) ($subject['subject_id'] ?? 0); ?></span>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <span class="subject-pill <?php echo $has_teacher ? 'subject-pill-success' : 'subject-pill-warning'; ?>">
                                <i class="fas <?php echo $has_teacher ? 'fa-circle-check' : 'fa-clock'; ?>"></i>
                                <span><?php echo $has_teacher ? 'Teacher Assigned' : 'Pending Teacher'; ?></span>
                            </span>
                            <span class="subject-pill <?php echo $has_code ? 'subject-pill-info' : 'subject-pill-muted'; ?>">
                                <i class="fas <?php echo $has_code ? 'fa-hashtag' : 'fa-circle-minus'; ?>"></i>
                                <span><?php echo $has_code ? 'Code Set' : 'Code Missing'; ?></span>
                            </span>
                        </div>

                        <div class="mt-4 rounded-2xl border border-ink-900/10 bg-mist-50 px-4 py-3">
                            <?php if ($has_teacher): ?>
                                <p class="text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($teacher_name); ?></p>
                                <p class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars($teacher_email !== '' ? $teacher_email : 'No email on file'); ?></p>
                            <?php else: ?>
                                <p class="text-sm text-slate-500">No teacher is currently assigned to this subject.</p>
                            <?php endif; ?>
                        </div>

                        <p class="mt-4 text-sm text-slate-600"><?php echo htmlspecialchars($subject_description !== '' ? $subject_description : 'No description added yet.'); ?></p>

                        <div class="mt-5 flex flex-wrap gap-3">
                            <?php if ($teacher_email !== ''): ?>
                                <a href="mailto:<?php echo htmlspecialchars($teacher_email); ?>" class="subject-action-link">
                                    <i class="fas fa-envelope"></i>
                                    <span>Contact Teacher</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($assigned_at_label !== ''): ?>
                                <span class="text-xs text-slate-500">
                                    Assigned <?php echo htmlspecialchars($assigned_at_label); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

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

    var searchInput = document.querySelector('[data-subject-search]');
    var filterGroup = document.querySelector('[data-filter-group]');
    var subjectItems = Array.prototype.slice.call(document.querySelectorAll('[data-subject-item]'));
    var visibleCount = document.querySelector('[data-visible-count]');
    var activeFilter = 'all';

    var matchesFilter = function (item, filter) {
        if (filter === 'with-teacher') {
            return item.dataset.filterWithTeacher === '1';
        }
        if (filter === 'without-teacher') {
            return item.dataset.filterWithTeacher !== '1';
        }
        if (filter === 'with-code') {
            return item.dataset.filterWithCode === '1';
        }
        return true;
    };

    var runFilters = function () {
        var term = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
        var visible = 0;

        subjectItems.forEach(function (item) {
            var matchesSearch = !term || (item.dataset.search || '').indexOf(term) !== -1;
            var show = matchesSearch && matchesFilter(item, activeFilter);
            item.classList.toggle('hidden', !show);
            if (show && item.dataset.subjectPrimary === '1') {
                visible += 1;
            }
        });

        if (visibleCount) {
            visibleCount.textContent = String(visible);
        }
    };

    if (searchInput) {
        searchInput.addEventListener('input', runFilters);
    }

    if (filterGroup) {
        Array.prototype.slice.call(filterGroup.querySelectorAll('[data-filter]')).forEach(function (button) {
            button.addEventListener('click', function () {
                activeFilter = button.dataset.filter || 'all';
                Array.prototype.slice.call(filterGroup.querySelectorAll('[data-filter]')).forEach(function (chip) {
                    chip.classList.remove('is-active');
                });
                button.classList.add('is-active');
                runFilters();
            });
        });
    }

    runFilters();
})();
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
