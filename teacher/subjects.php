<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: ../index.php');
    exit;
}

$current_school_id = require_school_auth();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$errors = [];

$flash = $_SESSION['subject_flash'] ?? null;
unset($_SESSION['subject_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            $errors[] = 'Subject name is required.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare(
                'INSERT INTO subjects (subject_name, subject_code, description, created_by, school_id, created_at)
                 VALUES (:name, :code, :description, :created_by, :school_id, NOW())'
            );
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'created_by' => $uid,
                'school_id' => $current_school_id,
            ]);

            $_SESSION['subject_flash'] = [
                'type' => 'success',
                'message' => 'Subject created successfully.',
            ];
            header('Location: subjects.php');
            exit;
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($id <= 0) {
            $errors[] = 'Invalid subject selected.';
        }
        if ($name === '') {
            $errors[] = 'Subject name is required.';
        }

        if (empty($errors)) {
            $ownerStmt = $pdo->prepare('SELECT created_by FROM subjects WHERE id = :id AND school_id = :school_id');
            $ownerStmt->execute([
                'id' => $id,
                'school_id' => $current_school_id,
            ]);
            $ownerId = $ownerStmt->fetchColumn();

            if ((int) $ownerId !== $uid) {
                $errors[] = 'You can only edit subjects you created.';
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE subjects
                     SET subject_name = :name, subject_code = :code, description = :description, updated_at = NOW()
                     WHERE id = :id AND school_id = :school_id'
                );
                $updateStmt->execute([
                    'name' => $name,
                    'code' => $code,
                    'description' => $description,
                    'id' => $id,
                    'school_id' => $current_school_id,
                ]);

                $_SESSION['subject_flash'] = [
                    'type' => 'success',
                    'message' => 'Subject updated successfully.',
                ];
                header('Location: subjects.php');
                exit;
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            $errors[] = 'Invalid subject selected.';
        } else {
            $ownerStmt = $pdo->prepare('SELECT created_by FROM subjects WHERE id = :id AND school_id = :school_id');
            $ownerStmt->execute([
                'id' => $id,
                'school_id' => $current_school_id,
            ]);
            $ownerId = $ownerStmt->fetchColumn();

            if ((int) $ownerId !== $uid) {
                $errors[] = 'You can only delete subjects you created.';
            } else {
                $pdo->prepare('DELETE FROM subject_assignments WHERE subject_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM subjects WHERE id = :id AND school_id = :school_id')->execute([
                    'id' => $id,
                    'school_id' => $current_school_id,
                ]);

                $_SESSION['subject_flash'] = [
                    'type' => 'success',
                    'message' => 'Subject deleted successfully.',
                ];
                header('Location: subjects.php');
                exit;
            }
        }
    }
}

$subjectStmt = $pdo->prepare(
    'SELECT s.*,
            CASE WHEN s.created_by = :teacher_id THEN 1 ELSE 0 END AS is_owner
     FROM subjects s
     WHERE s.school_id = :school_id
     ORDER BY s.subject_name ASC'
);
$subjectStmt->execute([
    'teacher_id' => $uid,
    'school_id' => $current_school_id,
]);
$all_subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

$assignmentStmt = $pdo->prepare(
    'SELECT sa.id,
            sa.subject_id,
            sa.class_id,
            c.class_name,
            sub.subject_name,
            sub.subject_code,
            sub.description
     FROM subject_assignments sa
     INNER JOIN classes c ON sa.class_id = c.id
     INNER JOIN subjects sub ON sa.subject_id = sub.id
     WHERE sa.teacher_id = :teacher_id
       AND c.school_id = :class_school_id
       AND sub.school_id = :subject_school_id
     ORDER BY c.class_name ASC, sub.subject_name ASC'
);
$assignmentStmt->execute([
    'teacher_id' => $uid,
    'class_school_id' => $current_school_id,
    'subject_school_id' => $current_school_id,
]);
$assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

$assigned_subject_ids = array_values(array_unique(array_map('intval', array_column($assignments, 'subject_id'))));
$assignment_class_ids = array_values(array_unique(array_map('intval', array_column($assignments, 'class_id'))));

$total_subjects = count($all_subjects);
$owned_subjects = count(array_filter($all_subjects, static function ($subject) {
    return (int) ($subject['is_owner'] ?? 0) === 1;
}));
$assigned_subjects = count($assigned_subject_ids);
$active_classes = count($assignment_class_ids);
$unassigned_subjects = max($total_subjects - $assigned_subjects, 0);
$subject_stylesheet_version = @filemtime(__DIR__ . '/../assets/css/teacher-subjects.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Management | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/teacher-subjects.css?v=<?php echo $subject_stylesheet_version; ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <meta name="theme-color" content="#0f172a">
</head>
<body class="landing bg-slate-50 subject-page">
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
            <div class="subject-header-actions flex items-center gap-3">
                <span class="hidden text-sm text-slate-600 md:block">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                <a class="btn btn-outline" href="index.php">Dashboard</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto">
                <?php include '../includes/teacher_sidebar.php'; ?>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="overflow-hidden rounded-3xl border border-ink-900/5 shadow-lift">
                <div class="subject-hero p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Academic Structure</p>
                            <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">Subject management with clearer structure, stronger presentation, and cleaner workflows.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">Organize your teaching portfolio, review class allocations, and maintain a professional subject catalogue that fits the rest of the teacher workspace.</p>
                        </div>
                        <div class="subject-hero-actions grid gap-3 sm:grid-cols-2">
                            <a href="#create-subject" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                <i class="fas fa-plus-circle"></i>
                                <span>Add Subject</span>
                            </a>
                            <a href="#subject-directory" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-table-list"></i>
                                <span>Browse Directory</span>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="subject-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-teal-600/10 text-teal-700">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Subjects</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_subjects; ?></h2>
                        <p class="text-sm text-slate-500">School-wide catalogue</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-sky-600/10 text-sky-700">
                            <i class="fas fa-pen-ruler"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">My Subjects</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $owned_subjects; ?></h2>
                        <p class="text-sm text-slate-500">Created by you</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-emerald-600/10 text-emerald-700">
                            <i class="fas fa-diagram-project"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Assigned Subjects</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $assigned_subjects; ?></h2>
                        <p class="text-sm text-slate-500">Linked to your classes</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-amber-500/10 text-amber-600">
                            <i class="fas fa-chalkboard"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Active Classes</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $active_classes; ?></h2>
                        <p class="text-sm text-slate-500">Receiving allocations</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-rose-500/10 text-rose-600">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Unassigned</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $unassigned_subjects; ?></h2>
                        <p class="text-sm text-slate-500">Available for allocation</p>
                    </article>
                </div>
            </section>

            <?php if ($flash || !empty($errors)): ?>
                <section class="space-y-3">
                    <?php if ($flash): ?>
                        <div class="rounded-2xl border px-4 py-3 text-sm font-medium <?php echo ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700'; ?>">
                            <div class="flex items-start gap-3">
                                <i class="fas <?php echo ($flash['type'] ?? '') === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> mt-0.5"></i>
                                <span><?php echo htmlspecialchars($flash['message'] ?? 'Update completed.'); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($errors as $error): ?>
                        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                            <div class="flex items-start gap-3">
                                <i class="fas fa-circle-exclamation mt-0.5"></i>
                                <span><?php echo htmlspecialchars($error); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <section class="subject-content-grid grid gap-6 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)]">
                <article id="create-subject" class="rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft sm:p-6">
                    <div class="flex items-start gap-4">
                        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-teal-600/10 text-teal-700">
                            <i class="fas fa-circle-plus text-lg"></i>
                        </span>
                        <div>
                            <p class="text-xs uppercase tracking-[0.28em] text-slate-500">Create Subject</p>
                            <h2 class="mt-1 text-2xl font-semibold text-ink-900">Add a new teaching subject</h2>
                            <p class="mt-2 text-sm text-slate-600">Keep the catalogue standardized with clear names, compact codes, and concise descriptions for easier assignment and reporting.</p>
                        </div>
                    </div>

                    <form method="POST" class="mt-6 space-y-4">
                        <input type="hidden" name="action" value="create">

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="space-y-2">
                                <span class="text-sm font-semibold text-ink-900">Subject Name</span>
                                <input type="text" name="name" placeholder="e.g. Basic Science" required class="w-full rounded-2xl border border-ink-900/10 bg-white px-4 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </label>
                            <label class="space-y-2">
                                <span class="text-sm font-semibold text-ink-900">Subject Code</span>
                                <input type="text" name="code" placeholder="e.g. BSC 101" class="w-full rounded-2xl border border-ink-900/10 bg-white px-4 py-3 text-sm uppercase text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </label>
                        </div>

                        <label class="block space-y-2">
                            <span class="text-sm font-semibold text-ink-900">Description</span>
                            <textarea name="description" rows="4" placeholder="Short overview of the learning area, syllabus focus, or internal notes." class="w-full rounded-2xl border border-ink-900/10 bg-white px-4 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"></textarea>
                        </label>

                        <div class="flex flex-col gap-3 border-t border-ink-900/5 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-slate-500">Only subjects created by you can be edited or removed later.</p>
                            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-teal-600 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-teal-700">
                                <i class="fas fa-floppy-disk"></i>
                                <span>Create Subject</span>
                            </button>
                        </div>
                    </form>
                </article>

                <article class="rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft sm:p-6">
                    <div class="flex items-start gap-4">
                        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-sky-600/10 text-sky-700">
                            <i class="fas fa-graduation-cap text-lg"></i>
                        </span>
                        <div>
                            <p class="text-xs uppercase tracking-[0.28em] text-slate-500">Assignment Overview</p>
                            <h2 class="mt-1 text-2xl font-semibold text-ink-900">Your active teaching allocations</h2>
                            <p class="mt-2 text-sm text-slate-600">A quick view of where your subjects are currently deployed, so planning and coverage are easier to manage.</p>
                        </div>
                    </div>

                    <?php if (empty($assignments)): ?>
                        <div class="subject-empty-state mt-6">
                            <span class="subject-empty-icon">
                                <i class="fas fa-diagram-project"></i>
                            </span>
                            <h3 class="text-lg font-semibold text-ink-900">No subject allocations yet</h3>
                            <p class="mt-2 max-w-md text-sm text-slate-500">Your administrator has not assigned subjects to your classes yet. Once allocations are made, they will appear here automatically.</p>
                        </div>
                    <?php else: ?>
                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <?php foreach ($assignments as $assignment): ?>
                                <article class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4 shadow-soft">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-xs uppercase tracking-wide text-slate-500"><?php echo htmlspecialchars($assignment['class_name']); ?></p>
                                            <h3 class="mt-1 text-lg font-semibold text-ink-900"><?php echo htmlspecialchars($assignment['subject_name']); ?></h3>
                                        </div>
                                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white text-teal-700 shadow-soft">
                                            <i class="fas fa-book-open"></i>
                                        </span>
                                    </div>
                                    <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold">
                                        <span class="rounded-full bg-white px-3 py-1 text-slate-600 shadow-soft">
                                            <i class="fas fa-hashtag mr-1 text-slate-400"></i>
                                            <?php echo htmlspecialchars($assignment['subject_code'] ?: 'No code'); ?>
                                        </span>
                                        <span class="rounded-full bg-teal-600/10 px-3 py-1 text-teal-700">
                                            <i class="fas fa-school mr-1"></i>
                                            Assigned
                                        </span>
                                    </div>
                                    <p class="mt-4 text-sm text-slate-600"><?php echo htmlspecialchars($assignment['description'] ?: 'No subject description has been added yet.'); ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section id="subject-directory" class="rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft sm:p-6">
                <div class="subject-toolbar flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.28em] text-slate-500">Subject Directory</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900">Browse, search, and maintain the subject catalogue</h2>
                        <p class="mt-2 max-w-2xl text-sm text-slate-600">The directory below combines discovery, status visibility, and ownership controls in one view for both desktop and mobile screens.</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <label class="relative min-w-0 sm:min-w-[260px]">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="search" data-subject-search placeholder="Search by name, code, or description" class="w-full rounded-2xl border border-ink-900/10 bg-white px-11 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </label>
                        <div class="flex items-center justify-between rounded-2xl border border-ink-900/10 bg-mist-50 px-4 py-3 text-sm font-semibold text-slate-600">
                            <span class="inline-flex items-center gap-2">
                                <i class="fas fa-filter text-teal-700"></i>
                                <span>Visible</span>
                            </span>
                            <span data-visible-count><?php echo $total_subjects; ?></span>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex gap-2 overflow-x-auto pb-1" data-filter-group>
                    <button type="button" class="subject-filter-chip is-active" data-filter="all">
                        <i class="fas fa-layer-group"></i>
                        <span>All Subjects</span>
                    </button>
                    <button type="button" class="subject-filter-chip" data-filter="owned">
                        <i class="fas fa-user-pen"></i>
                        <span>Created by Me</span>
                    </button>
                    <button type="button" class="subject-filter-chip" data-filter="assigned">
                        <i class="fas fa-circle-check"></i>
                        <span>Assigned</span>
                    </button>
                    <button type="button" class="subject-filter-chip" data-filter="unassigned">
                        <i class="fas fa-clock"></i>
                        <span>Unassigned</span>
                    </button>
                </div>

                <?php if (empty($all_subjects)): ?>
                    <div class="subject-empty-state mt-6">
                        <span class="subject-empty-icon">
                            <i class="fas fa-book-open-reader"></i>
                        </span>
                        <h3 class="text-lg font-semibold text-ink-900">No subjects found</h3>
                        <p class="mt-2 max-w-md text-sm text-slate-500">Your school has not created any subjects yet. Use the form above to create the first one.</p>
                    </div>
                <?php else: ?>
                    <div class="mt-6 hidden overflow-hidden rounded-3xl border border-ink-900/5 xl:block">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-ink-900/5">
                                <thead class="bg-mist-50">
                                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th class="px-5 py-4 font-semibold">Subject</th>
                                        <th class="px-5 py-4 font-semibold">Code</th>
                                        <th class="px-5 py-4 font-semibold">Ownership</th>
                                        <th class="px-5 py-4 font-semibold">Status</th>
                                        <th class="px-5 py-4 font-semibold">Description</th>
                                        <th class="px-5 py-4 font-semibold text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-ink-900/5 bg-white">
                                    <?php foreach ($all_subjects as $subject): ?>
                                        <?php
                                        $isOwner = (int) ($subject['is_owner'] ?? 0) === 1;
                                        $isAssigned = in_array((int) $subject['id'], $assigned_subject_ids, true);
                                        $description = trim((string) ($subject['description'] ?? ''));
                                        ?>
                                        <tr
                                            data-subject-item
                                            data-subject-primary="1"
                                            data-filter-owned="<?php echo $isOwner ? '1' : '0'; ?>"
                                            data-filter-assigned="<?php echo $isAssigned ? '1' : '0'; ?>"
                                            data-search="<?php echo htmlspecialchars(strtolower(trim(($subject['subject_name'] ?? '') . ' ' . ($subject['subject_code'] ?? '') . ' ' . $description))); ?>"
                                            class="subject-table-row align-top"
                                        >
                                            <td class="px-5 py-4">
                                                <div class="flex items-start gap-3">
                                                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-teal-600/10 text-teal-700">
                                                        <i class="fas fa-book-open"></i>
                                                    </span>
                                                    <div>
                                                        <p class="text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($subject['subject_name']); ?></p>
                                                        <p class="mt-1 text-xs text-slate-500">ID #<?php echo (int) $subject['id']; ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4 text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($subject['subject_code'] ?: 'Not set'); ?></td>
                                            <td class="px-5 py-4">
                                                <span class="subject-pill <?php echo $isOwner ? 'subject-pill-info' : 'subject-pill-muted'; ?>">
                                                    <i class="fas <?php echo $isOwner ? 'fa-user-check' : 'fa-users'; ?>"></i>
                                                    <span><?php echo $isOwner ? 'My Subject' : 'School Subject'; ?></span>
                                                </span>
                                            </td>
                                            <td class="px-5 py-4">
                                                <span class="subject-pill <?php echo $isAssigned ? 'subject-pill-success' : 'subject-pill-warning'; ?>">
                                                    <i class="fas <?php echo $isAssigned ? 'fa-circle-check' : 'fa-clock'; ?>"></i>
                                                    <span><?php echo $isAssigned ? 'Assigned' : 'Awaiting Assignment'; ?></span>
                                                </span>
                                            </td>
                                            <td class="px-5 py-4 text-sm text-slate-600"><?php echo htmlspecialchars($description !== '' ? $description : 'No description added yet.'); ?></td>
                                            <td class="px-5 py-4">
                                                <div class="flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        class="inline-flex items-center gap-2 rounded-xl border border-ink-900/10 px-3 py-2 text-sm font-semibold text-ink-900 transition hover:border-teal-200 hover:bg-teal-50 <?php echo $isOwner ? '' : 'cursor-not-allowed opacity-50'; ?>"
                                                        data-edit-subject
                                                        data-subject-id="<?php echo (int) $subject['id']; ?>"
                                                        data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>"
                                                        data-subject-code="<?php echo htmlspecialchars($subject['subject_code'] ?? ''); ?>"
                                                        data-subject-description="<?php echo htmlspecialchars($description); ?>"
                                                        <?php echo $isOwner ? '' : 'disabled'; ?>
                                                        title="<?php echo $isOwner ? 'Edit subject' : 'Only subjects created by you can be edited'; ?>"
                                                    >
                                                        <i class="fas fa-pen"></i>
                                                        <span>Edit</span>
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('Delete this subject and its assignments? This action cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $subject['id']; ?>">
                                                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 <?php echo $isOwner ? '' : 'cursor-not-allowed opacity-50'; ?>" <?php echo $isOwner ? '' : 'disabled'; ?> title="<?php echo $isOwner ? 'Delete subject' : 'Only subjects created by you can be deleted'; ?>">
                                                            <i class="fas fa-trash"></i>
                                                            <span>Delete</span>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4 xl:hidden">
                        <?php foreach ($all_subjects as $subject): ?>
                            <?php
                            $isOwner = (int) ($subject['is_owner'] ?? 0) === 1;
                            $isAssigned = in_array((int) $subject['id'], $assigned_subject_ids, true);
                            $description = trim((string) ($subject['description'] ?? ''));
                            ?>
                            <article
                                data-subject-item
                                data-filter-owned="<?php echo $isOwner ? '1' : '0'; ?>"
                                data-filter-assigned="<?php echo $isAssigned ? '1' : '0'; ?>"
                                data-search="<?php echo htmlspecialchars(strtolower(trim(($subject['subject_name'] ?? '') . ' ' . ($subject['subject_code'] ?? '') . ' ' . $description))); ?>"
                                class="rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-teal-600/10 text-teal-700">
                                            <i class="fas fa-book-open"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="truncate text-lg font-semibold text-ink-900"><?php echo htmlspecialchars($subject['subject_name']); ?></h3>
                                            <p class="mt-1 text-sm text-slate-500"><?php echo htmlspecialchars($subject['subject_code'] ?: 'Code not set'); ?></p>
                                        </div>
                                    </div>
                                    <span class="rounded-full bg-mist-50 px-3 py-1 text-xs font-semibold text-slate-600">#<?php echo (int) $subject['id']; ?></span>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <span class="subject-pill <?php echo $isOwner ? 'subject-pill-info' : 'subject-pill-muted'; ?>">
                                        <i class="fas <?php echo $isOwner ? 'fa-user-check' : 'fa-users'; ?>"></i>
                                        <span><?php echo $isOwner ? 'My Subject' : 'School Subject'; ?></span>
                                    </span>
                                    <span class="subject-pill <?php echo $isAssigned ? 'subject-pill-success' : 'subject-pill-warning'; ?>">
                                        <i class="fas <?php echo $isAssigned ? 'fa-circle-check' : 'fa-clock'; ?>"></i>
                                        <span><?php echo $isAssigned ? 'Assigned' : 'Awaiting Assignment'; ?></span>
                                    </span>
                                </div>

                                <p class="mt-4 text-sm text-slate-600"><?php echo htmlspecialchars($description !== '' ? $description : 'No description added yet.'); ?></p>

                                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center gap-2 rounded-2xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 transition hover:border-teal-200 hover:bg-teal-50 <?php echo $isOwner ? '' : 'cursor-not-allowed opacity-50'; ?>"
                                        data-edit-subject
                                        data-subject-id="<?php echo (int) $subject['id']; ?>"
                                        data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>"
                                        data-subject-code="<?php echo htmlspecialchars($subject['subject_code'] ?? ''); ?>"
                                        data-subject-description="<?php echo htmlspecialchars($description); ?>"
                                        <?php echo $isOwner ? '' : 'disabled'; ?>
                                        title="<?php echo $isOwner ? 'Edit subject' : 'Only subjects created by you can be edited'; ?>"
                                    >
                                        <i class="fas fa-pen"></i>
                                        <span>Edit Subject</span>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Delete this subject and its assignments? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int) $subject['id']; ?>">
                                        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-rose-200 px-4 py-3 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 <?php echo $isOwner ? '' : 'cursor-not-allowed opacity-50'; ?>" <?php echo $isOwner ? '' : 'disabled'; ?> title="<?php echo $isOwner ? 'Delete subject' : 'Only subjects created by you can be deleted'; ?>">
                                            <i class="fas fa-trash"></i>
                                            <span>Delete Subject</span>
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <div id="editModal" class="subject-modal" aria-hidden="true">
        <div class="subject-modal-backdrop" data-close-modal></div>
        <div class="subject-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
            <div class="flex items-start justify-between gap-4 border-b border-ink-900/5 px-5 py-4 sm:px-6">
                <div>
                    <p class="text-xs uppercase tracking-[0.28em] text-slate-500">Update Subject</p>
                    <h2 id="editModalTitle" class="mt-1 text-2xl font-semibold text-ink-900">Edit subject details</h2>
                </div>
                <button type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-ink-900/10 text-slate-500 transition hover:bg-slate-100" data-close-modal aria-label="Close dialog">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form method="POST" class="space-y-4 px-5 py-5 sm:px-6 sm:py-6">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">

                <div class="grid gap-4 md:grid-cols-2">
                    <label class="space-y-2">
                        <span class="text-sm font-semibold text-ink-900">Subject Name</span>
                        <input type="text" id="edit_name" name="name" required class="w-full rounded-2xl border border-ink-900/10 bg-white px-4 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                    </label>
                    <label class="space-y-2">
                        <span class="text-sm font-semibold text-ink-900">Subject Code</span>
                        <input type="text" id="edit_code" name="code" class="w-full rounded-2xl border border-ink-900/10 bg-white px-4 py-3 text-sm uppercase text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                    </label>
                </div>

                <label class="block space-y-2">
                    <span class="text-sm font-semibold text-ink-900">Description</span>
                    <textarea id="edit_desc" name="description" rows="4" class="w-full rounded-2xl border border-ink-900/10 bg-white px-4 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"></textarea>
                </label>

                <div class="flex flex-col gap-3 border-t border-ink-900/5 pt-4 sm:flex-row sm:justify-end">
                    <button type="button" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-ink-900/10 px-5 py-3 text-sm font-semibold text-ink-900 transition hover:bg-slate-50" data-close-modal>
                        <i class="fas fa-xmark"></i>
                        <span>Cancel</span>
                    </button>
                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-teal-600 px-5 py-3 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-teal-700">
                        <i class="fas fa-floppy-disk"></i>
                        <span>Save Changes</span>
                    </button>
                </div>
            </form>
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

        const modal = document.getElementById('editModal');
        const editButtons = document.querySelectorAll('[data-edit-subject]');
        const closeModalButtons = document.querySelectorAll('[data-close-modal]');

        const openModal = (button) => {
            if (!modal || !button) return;
            document.getElementById('edit_id').value = button.dataset.subjectId || '';
            document.getElementById('edit_name').value = button.dataset.subjectName || '';
            document.getElementById('edit_code').value = button.dataset.subjectCode || '';
            document.getElementById('edit_desc').value = button.dataset.subjectDescription || '';
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        };

        const closeModal = () => {
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        };

        editButtons.forEach((button) => {
            button.addEventListener('click', () => openModal(button));
        });

        closeModalButtons.forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeModal();
                closeSidebar();
            }
        });

        const searchInput = document.querySelector('[data-subject-search]');
        const filterGroup = document.querySelector('[data-filter-group]');
        const subjectItems = Array.from(document.querySelectorAll('[data-subject-item]'));
        const visibleCount = document.querySelector('[data-visible-count]');
        let activeFilter = 'all';

        const matchesFilter = (item, filter) => {
            if (filter === 'owned') return item.dataset.filterOwned === '1';
            if (filter === 'assigned') return item.dataset.filterAssigned === '1';
            if (filter === 'unassigned') return item.dataset.filterAssigned !== '1';
            return true;
        };

        const runFilters = () => {
            const term = (searchInput?.value || '').trim().toLowerCase();
            let visible = 0;

            subjectItems.forEach((item) => {
                const matchesSearch = !term || (item.dataset.search || '').includes(term);
                const show = matchesSearch && matchesFilter(item, activeFilter);
                item.classList.toggle('hidden', !show);
                if (show && item.dataset.subjectPrimary === '1') visible += 1;
            });

            if (visibleCount) {
                visibleCount.textContent = String(visible);
            }
        };

        if (searchInput) {
            searchInput.addEventListener('input', runFilters);
        }

        if (filterGroup) {
            filterGroup.querySelectorAll('[data-filter]').forEach((button) => {
                button.addEventListener('click', () => {
                    activeFilter = button.dataset.filter || 'all';
                    filterGroup.querySelectorAll('[data-filter]').forEach((chip) => chip.classList.remove('is-active'));
                    button.classList.add('is-active');
                    runFilters();
                });
            });
        }

        runFilters();
    </script>
</body>
</html>
