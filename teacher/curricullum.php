<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: ../index.php');
    exit;
}

$current_school_id = require_school_auth();
$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

$stmt = $pdo->prepare(
    "SELECT DISTINCT c.* FROM classes c
     JOIN curriculum cu ON c.id = cu.class_id
     WHERE cu.teacher_id = :teacher_id
     ORDER BY c.class_name ASC"
);
$stmt->execute(['teacher_id' => $teacher_id]);
$assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$stmt->execute([$current_school_id]);
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name ASC");
$stmt->execute([$current_school_id]);
$all_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$search = trim($_GET['search'] ?? '');
$filter_class = $_GET['filter_class'] ?? '';
$filter_term = $_GET['filter_term'] ?? '';
$filter_week = $_GET['filter_week'] ?? '';
$filter_subject = $_GET['filter_subject'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'active';
$filter_assigned = $_GET['filter_assigned'] ?? 'mine';

$per_page = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));

$base_query = "FROM curriculum c
    LEFT JOIN classes cl ON c.class_id = cl.id AND cl.school_id = :school_id_classes
    WHERE c.school_id = :school_id_filter";
$params = [
    'school_id_classes' => $current_school_id,
    'school_id_filter' => $current_school_id,
];

if ($filter_status !== 'all') {
    $base_query .= " AND c.status = :status";
    $params['status'] = $filter_status;
}

if ($filter_assigned === 'mine') {
    $base_query .= " AND c.teacher_id = :teacher_id";
    $params['teacher_id'] = $teacher_id;
}

if ($search !== '') {
    $base_query .= " AND (c.subject_name LIKE :search OR c.description LIKE :search OR c.topics LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($filter_class !== '' && $filter_class !== 'all') {
    $base_query .= " AND c.class_id = :class_id";
    $params['class_id'] = $filter_class;
}

if ($filter_term !== '' && $filter_term !== 'all') {
    $base_query .= " AND c.term = :term";
    $params['term'] = $filter_term;
}

if ($filter_week !== '' && $filter_week !== 'all') {
    $base_query .= " AND c.week = :week";
    $params['week'] = $filter_week;
}

if ($filter_subject !== '' && $filter_subject !== 'all') {
    $base_query .= " AND c.subject_id = :subject_id";
    $params['subject_id'] = $filter_subject;
}

$stmt = $pdo->prepare(
    "SELECT DISTINCT term FROM curriculum
     WHERE term IS NOT NULL AND term != '' AND school_id = ?
     ORDER BY CASE term
        WHEN '1st Term' THEN 1
        WHEN '2nd Term' THEN 2
        WHEN '3rd Term' THEN 3
        ELSE 4
     END"
);
$stmt->execute([$current_school_id]);
$terms = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($terms)) {
    $terms = ['1st Term', '2nd Term', '3rd Term'];
}

$count_query = "SELECT COUNT(*) " . $base_query;
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_rows = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$query = "SELECT DISTINCT c.*, cl.class_name " . $base_query .
    " ORDER BY c.term, c.week, c.status, c.grade_level, c.subject_name LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$curriculums = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pagination_params = $_GET;
unset($pagination_params['page']);
$start_item = $total_rows > 0 ? ($offset + 1) : 0;
$end_item = $total_rows > 0 ? min($offset + count($curriculums), $total_rows) : 0;
$prev_page = max(1, $page - 1);
$next_page = min($total_pages, $page + 1);

function countTopics($topics_string)
{
    if (empty($topics_string)) {
        return 0;
    }

    $topics = array_filter(array_map('trim', explode("\n", $topics_string)));
    return count($topics);
}

$build_pagination_window = static function (int $current_page, int $total_pages): array {
    if ($total_pages <= 1) {
        return [1];
    }

    $pages = [1];
    $start = max(2, $current_page - 1);
    $end = min($total_pages - 1, $current_page + 1);

    if ($start > 2) {
        $pages[] = '...';
    }

    for ($window_page = $start; $window_page <= $end; $window_page += 1) {
        $pages[] = $window_page;
    }

    if ($end < $total_pages - 1) {
        $pages[] = '...';
    }

    if ($total_pages > 1) {
        $pages[] = $total_pages;
    }

    return $pages;
};

$status_labels = [
    'active' => 'Active',
    'inactive' => 'Inactive',
];

$status_badges = [
    'active' => 'bg-emerald-50 text-emerald-700 border border-emerald-100',
    'inactive' => 'bg-slate-100 text-slate-700 border border-slate-200',
];

$totals_stmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN teacher_id = :teacher_id THEN 1 ELSE 0 END) AS my_count,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count
     FROM curriculum
     WHERE school_id = :school_id"
);
$totals_stmt->execute([
    'teacher_id' => $teacher_id,
    'school_id' => $current_school_id,
]);
$overall_totals = $totals_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$filtered_subject_count = count(array_unique(array_filter(array_column($curriculums, 'subject_name'))));
$page_topic_total = array_sum(array_map('countTopics', array_column($curriculums, 'topics')));
$assigned_on_page = count(array_filter($curriculums, static fn($curriculum) => (int) $curriculum['teacher_id'] === (int) $teacher_id));
$pagination_items = $build_pagination_window($page, $total_pages);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Management | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/teacher-workspace.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="landing bg-slate-50 workspace-page">
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
            <div class="workspace-header-actions flex items-center gap-3">
                <span class="hidden text-sm text-slate-600 md:block">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                <a class="btn btn-outline" href="questions.php">Question Bank</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto">
                <?php include '../includes/teacher_sidebar.php'; ?>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="overflow-hidden rounded-3xl border border-ink-900/5 shadow-lift">
                <div class="workspace-hero p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Curriculum Workspace</p>
                            <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">Curriculum planning with the same structured workspace language used across question management.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">Review subject outlines, track assigned curriculum, and move between teaching workflows without leaving the shared teacher shell.</p>
                        </div>
                        <div class="workspace-hero-actions grid gap-3 sm:grid-cols-2">
                            <a href="#curriculum-library" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                <i class="fas fa-table-list"></i>
                                <span>Review Curriculum</span>
                            </a>
                            <a href="content_coverage.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-clipboard-check"></i>
                                <span>Open Coverage</span>
                            </a>
                            <button type="button" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15" onclick="exportCurriculum()">
                                <i class="fas fa-download"></i>
                                <span>Export Current View</span>
                            </button>
                            <button type="button" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15" onclick="printCurriculum()">
                                <i class="fas fa-print"></i>
                                <span>Print Current View</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="workspace-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-teal-600/10 text-teal-700">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Filtered View</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_rows; ?></h2>
                        <p class="text-sm text-slate-500">Items matching the current filters</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-sky-600/10 text-sky-700">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">School Total</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo (int) ($overall_totals['total_count'] ?? 0); ?></h2>
                        <p class="text-sm text-slate-500">Curriculum records for this school</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-emerald-600/10 text-emerald-700">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Assigned to Me</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo (int) ($overall_totals['my_count'] ?? 0); ?></h2>
                        <p class="text-sm text-slate-500">Curriculum items under your name</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-amber-500/10 text-amber-700">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Assigned Classes</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo count($assigned_classes); ?></h2>
                        <p class="text-sm text-slate-500">Classes linked to your curriculum</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-violet-600/10 text-violet-700">
                            <i class="fas fa-list-ol"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Topics on Page</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $page_topic_total; ?></h2>
                        <p class="text-sm text-slate-500">Topic lines in the visible page results</p>
                    </article>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]">
                <div class="rounded-3xl bg-white shadow-lift border border-ink-900/5 overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-ink-900/5 p-6 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Discovery</p>
                            <h2 class="mt-2 text-2xl font-display text-ink-900">Search and Filter</h2>
                            <p class="mt-2 text-sm text-slate-600">Narrow the library by class, term, week, subject, assignment ownership, and status.</p>
                        </div>
                        <a class="btn btn-outline" href="curricullum.php">
                            <i class="fas fa-filter-circle-xmark"></i>
                            <span>Clear Filters</span>
                        </a>
                    </div>
                    <form method="GET" action="curricullum.php" class="grid gap-4 p-6 md:grid-cols-2 xl:grid-cols-3">
                        <div class="md:col-span-2 xl:col-span-3">
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Search</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by subject, description, or topics" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Class</label>
                            <select name="filter_class" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                <option value="all">All Classes</option>
                                <?php foreach ($all_classes as $class): ?>
                                    <option value="<?php echo (int) $class['id']; ?>" <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Term</label>
                            <select name="filter_term" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                <option value="all">All Terms</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo htmlspecialchars($term); ?>" <?php echo $filter_term === $term ? 'selected' : ''; ?>><?php echo htmlspecialchars($term); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Week</label>
                            <input type="number" name="filter_week" min="0" max="52" value="<?php echo htmlspecialchars($filter_week !== 'all' ? $filter_week : ''); ?>" placeholder="Enter week number" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Subject</label>
                            <select name="filter_subject" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                <option value="all">All Subjects</option>
                                <?php foreach ($all_subjects as $subject): ?>
                                    <option value="<?php echo (int) $subject['id']; ?>" <?php echo $filter_subject == $subject['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Status</label>
                            <select name="filter_status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Assignment</label>
                            <select name="filter_assigned" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                <option value="all" <?php echo $filter_assigned === 'all' ? 'selected' : ''; ?>>All Curriculum</option>
                                <option value="mine" <?php echo $filter_assigned === 'mine' ? 'selected' : ''; ?>>Assigned to Me</option>
                            </select>
                        </div>
                        <div class="md:col-span-2 xl:col-span-3 flex flex-wrap gap-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i>
                                <span>Apply Filters</span>
                            </button>
                            <button type="button" class="btn btn-outline" onclick="exportCurriculum()">
                                <i class="fas fa-file-export"></i>
                                <span>Export Result</span>
                            </button>
                            <button type="button" class="btn btn-outline" onclick="printCurriculum()">
                                <i class="fas fa-print"></i>
                                <span>Print Result</span>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Workflow Shortcuts</h2>
                        <div class="mt-4 grid gap-3 text-sm font-semibold">
                            <a href="content_coverage.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                <span><i class="fas fa-clipboard-check mr-3 text-teal-700"></i>Update content coverage</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                            <button type="button" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-left text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700" onclick="viewAnalytics()">
                                <span><i class="fas fa-chart-line mr-3 text-teal-700"></i>Open curriculum analytics</span>
                                <i class="fas fa-arrow-right"></i>
                            </button>
                            <a href="questions.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                <span><i class="fas fa-question-circle mr-3 text-teal-700"></i>Move to question bank</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </section>

                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Review Notes</h2>
                        <div class="mt-4 space-y-4 text-sm text-slate-600">
                            <p><span class="font-semibold text-ink-900">Keep the filters purposeful:</span> use class and term first, then narrow by topic text or ownership when auditing coverage.</p>
                            <p><span class="font-semibold text-ink-900">Check assignment ownership:</span> the “Assigned to Me” signal helps separate your direct workload from shared school curriculum records.</p>
                            <p><span class="font-semibold text-ink-900">Cross-reference coverage:</span> pair curriculum review with the coverage page to verify the planned topics are actually being taught.</p>
                        </div>
                    </section>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5" id="curriculum-library">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Library</p>
                        <h2 class="mt-2 text-2xl font-display text-ink-900">Curriculum Overview</h2>
                        <p class="mt-2 text-sm text-slate-600">Inspect the visible set, review structure by class and term, and open full details without leaving the page.</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-3 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Visible Items</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo count($curriculums); ?></p>
                    </div>
                </div>

                <?php if (empty($curriculums)): ?>
                    <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                        <i class="fas fa-folder-open text-3xl text-teal-700"></i>
                        <p class="mt-4 text-lg font-semibold text-ink-900">No curriculum matches this view</p>
                        <p class="mt-2 text-sm">Adjust the filters above or reset the current query to restore the full list.</p>
                    </div>
                <?php else: ?>
                    <div class="workspace-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10">
                        <table class="workspace-table min-w-[980px] w-full bg-white text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-4 py-4">Subject</th>
                                    <th class="px-4 py-4">Class / Term</th>
                                    <th class="px-4 py-4">Description</th>
                                    <th class="px-4 py-4">Topics</th>
                                    <th class="px-4 py-4">Status</th>
                                    <th class="px-4 py-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($curriculums as $curriculum): ?>
                                    <?php
                                    $status_key = strtolower($curriculum['status'] ?? 'inactive');
                                    $status_label = $status_labels[$status_key] ?? ucfirst($status_key);
                                    $status_badge = $status_badges[$status_key] ?? 'bg-slate-100 text-slate-700 border border-slate-200';
                                    $topics_count = countTopics($curriculum['topics']);
                                    ?>
                                    <tr class="border-t border-slate-100 align-top">
                                        <td class="px-4 py-4" data-label="Subject">
                                            <div class="max-w-xs">
                                                <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($curriculum['subject_name']); ?></p>
                                                <p class="mt-1 text-slate-500"><?php echo htmlspecialchars($curriculum['grade_level'] ?: 'No grade level'); ?></p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4" data-label="Class / Term">
                                            <div class="space-y-2">
                                                <?php if (!empty($curriculum['class_name'])): ?><span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700 border border-sky-100"><?php echo htmlspecialchars($curriculum['class_name']); ?></span><?php endif; ?>
                                                <?php if (!empty($curriculum['term'])): ?><div class="text-slate-600">Term: <?php echo htmlspecialchars($curriculum['term']); ?></div><?php endif; ?>
                                                <div class="text-slate-500">Week: <?php echo $curriculum['week'] > 0 ? (int) $curriculum['week'] : 'Not set'; ?></div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4" data-label="Description"><div class="max-w-md"><p class="leading-6 text-slate-700"><?php echo htmlspecialchars(mb_strimwidth((string) ($curriculum['description'] ?? ''), 0, 140, '...')); ?></p></div></td>
                                        <td class="px-4 py-4" data-label="Topics">
                                            <div class="space-y-2">
                                                <span class="inline-flex rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-700"><?php echo $topics_count; ?> topic<?php echo $topics_count === 1 ? '' : 's'; ?></span>
                                                <p class="text-xs text-slate-500">Visible subjects: <?php echo $filtered_subject_count; ?></p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4" data-label="Status">
                                            <div class="space-y-2">
                                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo $status_badge; ?>"><?php echo htmlspecialchars($status_label); ?></span>
                                                <?php if ((int) $curriculum['teacher_id'] === (int) $teacher_id): ?><p class="text-xs font-semibold text-emerald-700">Assigned to Me</p><?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4" data-label="Actions">
                                            <div class="flex min-w-[190px] flex-col gap-2">
                                                <button type="button" class="btn btn-outline !px-3 !py-2" onclick='viewCurriculumDetails(<?php echo htmlspecialchars(json_encode($curriculum), ENT_QUOTES, 'UTF-8'); ?>)'><i class="fas fa-eye"></i><span>View Details</span></button>
                                                <button type="button" class="btn btn-primary !px-3 !py-2" onclick="printCurriculumItem(<?php echo (int) $curriculum['id']; ?>)"><i class="fas fa-print"></i><span>Print Item</span></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to review the full curriculum table on small screens.</span></p>
                <?php endif; ?>
            </section>
            <?php if (!empty($curriculums)): ?>
                <div class="flex flex-wrap items-center justify-between gap-4 text-sm text-slate-500">
                    <div class="flex flex-wrap gap-4">
                        <span><i class="fas fa-database mr-2 text-teal-700"></i>Total filtered: <?php echo $total_rows; ?></span>
                        <span><i class="fas fa-user-check mr-2 text-teal-700"></i>Assigned on page: <?php echo $assigned_on_page; ?></span>
                        <span><i class="fas fa-layer-group mr-2 text-teal-700"></i>Showing <?php echo count($curriculums); ?> on this page</span>
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-nav">
                            <?php
                            $prev_params = $pagination_params;
                            $prev_params['page'] = $prev_page;
                            $prev_url = 'curricullum.php?' . http_build_query($prev_params);
                            $next_params = $pagination_params;
                            $next_params['page'] = $next_page;
                            $next_url = 'curricullum.php?' . http_build_query($next_params);
                            ?>
                            <?php if ($page > 1): ?>
                                <a href="<?php echo htmlspecialchars($prev_url); ?>" class="btn btn-outline"><i class="fas fa-chevron-left"></i><span>Previous</span></a>
                            <?php endif; ?>
                            <?php foreach ($pagination_items as $pagination_item): ?>
                                <?php if ($pagination_item === '...'): ?>
                                    <span class="pagination-ellipsis" aria-hidden="true">...</span>
                                <?php else: ?>
                                    <?php $page_params = $pagination_params; $page_params['page'] = $pagination_item; $page_url = 'curricullum.php?' . http_build_query($page_params); ?>
                                    <a href="<?php echo htmlspecialchars($page_url); ?>" class="pagination-link <?php echo $page === $pagination_item ? 'is-active' : ''; ?>"<?php echo $page === $pagination_item ? ' aria-current="page"' : ''; ?>><?php echo $pagination_item; ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <span class="font-semibold text-slate-600">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo htmlspecialchars($next_url); ?>" class="btn btn-outline"><span>Next</span><i class="fas fa-chevron-right"></i></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-slate-500">Showing <?php echo $start_item; ?>-<?php echo $end_item; ?> of <?php echo $total_rows; ?> curriculum records.</p>
            <?php endif; ?>
        </main>
    </div>

    <div id="curriculumModal" class="workspace-modal" role="dialog" aria-modal="true" aria-labelledby="curriculumModalTitle">
        <div class="workspace-modal-card rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
            <button type="button" class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700" onclick="closeModal()">
                <i class="fas fa-xmark"></i>
            </button>
            <div id="modalContent"></div>
        </div>
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

        const closeSidebarShell = () => {
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
                    closeSidebarShell();
                }
            });
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebarShell);
        }

        if (sidebar) {
            sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebarShell));
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatMultilineText(value) {
            return escapeHtml(value).replace(/\n/g, '<br>');
        }

        function viewCurriculumDetails(curriculum) {
            const modal = document.getElementById('curriculumModal');
            const modalContent = document.getElementById('modalContent');
            let topicsSection = '';

            if (curriculum.topics) {
                const topics = curriculum.topics.split('\n').filter((topic) => topic.trim());
                topicsSection = `
                    <section class="mt-6 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-5">
                        <h3 class="text-lg font-semibold text-ink-900">Topics or Modules</h3>
                        <ul class="mt-4 space-y-2 text-sm text-slate-600">
                            ${topics.map((topic) => `<li class="flex gap-3"><i class="fas fa-chevron-right mt-1 text-teal-700"></i><span>${escapeHtml(topic.trim())}</span></li>`).join('')}
                        </ul>
                    </section>
                `;
            }

            modalContent.innerHTML = `
                <div class="flex flex-col gap-4 border-b border-ink-900/5 pb-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Curriculum Details</p>
                    <h2 id="curriculumModalTitle" class="text-2xl font-display text-ink-900">${escapeHtml(curriculum.subject_name || 'Curriculum Details')}</h2>
                    <p class="text-sm text-slate-500">Review the full context of this curriculum item before printing or tracking delivery.</p>
                </div>
                <section class="mt-6 grid gap-4 md:grid-cols-2">
                    <article class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Grade Level</p><p class="mt-2 text-sm font-semibold text-ink-900">${escapeHtml(curriculum.grade_level || 'Not specified')}</p></article>
                    <article class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Class</p><p class="mt-2 text-sm font-semibold text-ink-900">${escapeHtml(curriculum.class_name || 'Not assigned')}</p></article>
                    <article class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Term</p><p class="mt-2 text-sm font-semibold text-ink-900">${escapeHtml(curriculum.term || 'Not specified')}</p></article>
                    <article class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Week</p><p class="mt-2 text-sm font-semibold text-ink-900">${escapeHtml(curriculum.week || 'Not specified')}</p></article>
                    <article class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Duration</p><p class="mt-2 text-sm font-semibold text-ink-900">${escapeHtml(curriculum.duration || 'Not specified')}</p></article>
                    <article class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</p><p class="mt-2 text-sm font-semibold text-ink-900">${escapeHtml(curriculum.status ? curriculum.status.charAt(0).toUpperCase() + curriculum.status.slice(1) : 'N/A')}</p></article>
                </section>
                ${curriculum.description ? `<section class="mt-6 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-5"><h3 class="text-lg font-semibold text-ink-900">Description</h3><p class="mt-3 text-sm leading-6 text-slate-600">${formatMultilineText(curriculum.description)}</p></section>` : ''}
                ${curriculum.learning_objectives ? `<section class="mt-6 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-5"><h3 class="text-lg font-semibold text-ink-900">Learning Objectives</h3><p class="mt-3 text-sm leading-6 text-slate-600">${formatMultilineText(curriculum.learning_objectives)}</p></section>` : ''}
                ${curriculum.resources ? `<section class="mt-6 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-5"><h3 class="text-lg font-semibold text-ink-900">Resources</h3><p class="mt-3 text-sm leading-6 text-slate-600">${formatMultilineText(curriculum.resources)}</p></section>` : ''}
                ${curriculum.assessment_methods ? `<section class="mt-6 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-5"><h3 class="text-lg font-semibold text-ink-900">Assessment Methods</h3><p class="mt-3 text-sm leading-6 text-slate-600">${formatMultilineText(curriculum.assessment_methods)}</p></section>` : ''}
                ${curriculum.prerequisites ? `<section class="mt-6 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-5"><h3 class="text-lg font-semibold text-ink-900">Prerequisites</h3><p class="mt-3 text-sm leading-6 text-slate-600">${formatMultilineText(curriculum.prerequisites)}</p></section>` : ''}
                ${topicsSection}
            `;

            modal.classList.add('is-open');
        }

        function closeModal() {
            document.getElementById('curriculumModal').classList.remove('is-open');
        }

        function printCurriculumItem(id) {
            window.open(`../admin/print_curriculum.php?id=${id}`, '_blank');
        }

        function printCurriculum() {
            const params = new URLSearchParams(window.location.search);
            window.open(`../admin/print_curriculum.php?${params.toString()}`, '_blank');
        }

        function exportCurriculum() {
            const params = new URLSearchParams(window.location.search);
            window.open(`../admin/export_curriculum.php?${params.toString()}`, '_blank');
        }

        function viewAnalytics() {
            window.location.href = 'curriculum-analytics.php';
        }

        document.getElementById('curriculumModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>
