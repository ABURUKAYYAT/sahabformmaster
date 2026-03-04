<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Activities | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [data-sidebar] { overflow: hidden; }
        .sidebar-scroll-shell { height: 100%; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-y: contain; touch-action: pan-y; padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
        .activity-page { overflow-x: hidden; }
        .activity-page .container,
        .activity-page main,
        .activity-page section,
        .activity-page article,
        .activity-page form,
        .activity-page div { min-width: 0; }
        .activity-page .container { padding-left: 1rem; padding-right: 1rem; }
        .activity-page .nav-wrap { align-items: center; flex-wrap: nowrap; gap: 0.85rem; padding-top: 0.85rem; padding-bottom: 0.85rem; }
        .activity-page .nav-wrap > :first-child { min-width: 0; flex: 1 1 auto; }
        .subject-header-actions { width: auto; display: flex; align-items: center; flex: 0 0 auto; flex-wrap: nowrap; gap: 0.75rem; }
        .subject-header-actions .btn { width: auto; justify-content: center; padding: 0.5rem 0.85rem; font-size: 0.8125rem; }
        .subject-hero {
            padding: 1.25rem;
            background:
                radial-gradient(circle at top right, rgba(248, 198, 102, 0.26), transparent 28%),
                linear-gradient(135deg, #0f766e 0%, #0f766e 18%, #0f172a 100%);
        }
        .subject-hero,
        .subject-hero h1,
        .subject-hero h2,
        .subject-hero h3,
        .subject-hero p { color: #fff; }
        .subject-hero h1 { font-size: 1.95rem; line-height: 1.15; }
        .subject-hero-actions { grid-template-columns: 1fr; }
        .subject-metrics-grid { grid-template-columns: 1fr; }
        .subject-metric-card {
            display: grid;
            gap: 0.45rem;
            border: 1px solid rgba(15, 31, 45, 0.06);
            border-radius: 1.35rem;
            background: #f8fbfb;
            padding: 1rem;
            box-shadow: 0 10px 24px rgba(15, 31, 45, 0.06);
        }
        .subject-metric-icon {
            display: inline-flex;
            width: 3rem;
            height: 3rem;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            margin-bottom: 0.25rem;
        }
        .activity-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            padding: 1rem;
            border: 1px solid rgba(15, 23, 42, 0.06);
            border-radius: 1.5rem;
            background: #fff;
            box-shadow: 0 18px 44px rgba(15, 23, 42, 0.08);
        }
        .activity-tab {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.85rem 1.15rem;
            border-radius: 9999px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            background: #fff;
            color: #475569;
            font-size: 0.95rem;
            font-weight: 700;
            transition: all 0.2s ease;
        }
        .activity-tab:hover { border-color: rgba(13, 148, 136, 0.28); color: #0f766e; background: rgba(240, 253, 250, 0.9); }
        .activity-tab.is-active { border-color: #0f766e; background: #0f766e; color: #fff; box-shadow: 0 16px 32px rgba(15, 118, 110, 0.2); }
        .table-shell { overflow-x: auto; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .table-shell thead th { position: sticky; top: 0; z-index: 2; background: #f8fafc; }
        .table-scroll-hint { display: none; }
        .info-tile { border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 1rem; background: #f8fafc; padding: 1rem; }
        .read-only-surface { min-height: 3.5rem; border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 1rem; background: #f8fafc; padding: 0.95rem 1rem; color: #334155; }
        .activity-list-grid { display: grid; gap: 1rem; }
        .activity-list-card {
            display: grid;
            gap: 1rem;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 1.5rem;
            background: #fff;
            padding: 1.25rem;
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.06);
        }
        .progress-track { height: 0.6rem; overflow: hidden; border-radius: 9999px; background: #e2e8f0; }
        .progress-bar { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0f766e, #14b8a6); }
        .chart-shell { position: relative; min-height: 300px; }
        @media (max-width: 767px) {
            .activity-page .container { padding-left: 1rem; padding-right: 1rem; }
            main > section,
            .table-shell,
            .activity-tabs { border-radius: 1.25rem !important; }
            .activity-tabs { flex-direction: column; align-items: stretch; }
            .activity-tab { justify-content: center; }
            .table-shell { max-height: min(65vh, 30rem); border-radius: 1rem; border: 1px solid rgba(15, 23, 42, 0.08); background: #fff; }
            .table-scroll-hint { display: flex; align-items: center; gap: 0.45rem; margin-top: 0.75rem; font-size: 0.75rem; font-weight: 700; color: #64748b; }
        }
        @media (min-width: 640px) {
            .activity-page .container { padding-left: 1.25rem; padding-right: 1.25rem; }
            .subject-hero { padding: 1.5rem 2rem; }
            .subject-hero-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .subject-metrics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 768px) { .subject-hero h1 { font-size: 2.4rem; } }
        @media (min-width: 1024px) {
            .activity-page .nav-wrap { gap: 1rem; padding-top: 1rem; padding-bottom: 1rem; }
            .subject-header-actions { gap: 0.75rem; }
            .subject-header-actions .btn { padding: 0.5rem 1rem; font-size: 0.875rem; }
            .subject-hero { padding: 1.75rem 2rem; }
        }
        @media (min-width: 1200px) { .activity-page .container { padding-left: 2.75rem; padding-right: 2.75rem; } }
        @media (min-width: 1280px) { .subject-metrics-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
    </style>
</head>
<body class="landing bg-slate-50 question-page activity-page">
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

    <div class="container grid gap-6 lg:grid-cols-[280px_1fr] py-8">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto">
                <?php include '../includes/teacher_sidebar.php'; ?>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="overflow-hidden rounded-3xl border border-ink-900/5 shadow-lift">
                <div class="subject-hero p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Learning Workflow</p>
                            <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">Class activity planning, distribution, and grading in the same teacher workspace.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">This page now follows the same structure as the question bank: the same shell, the same spacing rhythm, and the same content hierarchy for creating work, reviewing submissions, and tracking outcomes.</p>
                        </div>
                        <div class="subject-hero-actions grid gap-3 sm:grid-cols-2">
                            <a href="teacher_class_activities.php?action=create" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                <i class="fas fa-plus-circle"></i>
                                <span>Create Activity</span>
                            </a>
                            <a href="teacher_class_activities.php?action=activities" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-table-list"></i>
                                <span>Manage Activities</span>
                            </a>
                            <a href="teacher_class_activities.php?action=submissions" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-paper-plane"></i>
                                <span>Review Submissions</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="subject-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-tasks"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Activities</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $hero_totals['total_activities']; ?></h2>
                        <p class="text-sm text-slate-500">Items you have created in this school</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-emerald-600/10 text-emerald-700"><i class="fas fa-eye"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Published</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $hero_totals['published_activities']; ?></h2>
                        <p class="text-sm text-slate-500">Visible to students right now</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-amber-500/10 text-amber-700"><i class="fas fa-hourglass-half"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Pending Review</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $hero_totals['pending_review']; ?></h2>
                        <p class="text-sm text-slate-500">Submitted or late responses awaiting grading</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-sky-600/10 text-sky-700"><i class="fas fa-check-circle"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Graded</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $hero_totals['graded_total']; ?></h2>
                        <p class="text-sm text-slate-500">Submission records already reviewed</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-violet-600/10 text-violet-700"><i class="fas fa-calendar-week"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Due In 7 Days</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $hero_totals['upcoming_due']; ?></h2>
                        <p class="text-sm text-slate-500">Upcoming deadlines to keep on your radar</p>
                    </article>
                </div>
            </section>

            <?php if ($errors): ?>
                <section class="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-800 shadow-soft">
                    <h2 class="text-lg font-semibold">Review the highlighted issues</h2>
                    <ul class="mt-2 space-y-1 text-sm">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900 shadow-soft">
                    <p class="font-semibold">Update completed</p>
                    <p class="mt-1 text-sm"><?php echo htmlspecialchars($success_message); ?></p>
                </section>
            <?php endif; ?>

            <?php if (in_array($action, ['create', 'edit'], true)): ?>
                <?php if ($action === 'edit' && !$activity_record): ?>
                    <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                        <div class="rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                            <i class="fas fa-circle-exclamation text-3xl text-amber-600"></i>
                            <p class="mt-4 text-lg font-semibold text-ink-900">The selected activity could not be loaded</p>
                            <p class="mt-2 text-sm">Return to your activity list and choose another item to edit.</p>
                            <div class="mt-6 flex flex-wrap justify-center gap-3">
                                <a class="btn btn-outline" href="teacher_class_activities.php?action=activities"><i class="fas fa-arrow-left"></i><span>Back to Activities</span></a>
                                <a class="btn btn-primary" href="teacher_class_activities.php?action=create"><i class="fas fa-plus-circle"></i><span>Create New Activity</span></a>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <?php $form_activity = $activity_record ?? []; ?>
                    <?php $form_type_meta = $get_activity_meta($form_activity['activity_type'] ?? 'classwork'); ?>
                    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]">
                        <div class="rounded-3xl bg-white shadow-lift border border-ink-900/5 overflow-hidden">
                            <div class="flex flex-col gap-4 border-b border-ink-900/5 p-6 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700"><?php echo $action === 'create' ? 'Activity Editor' : 'Activity Update'; ?></p>
                                    <h2 class="mt-2 text-2xl font-display text-ink-900"><?php echo $action === 'create' ? 'Create a New Class Activity' : 'Edit Activity Details'; ?></h2>
                                </div>
                                <span class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-semibold <?php echo $form_type_meta['pill']; ?>">
                                    <i class="fas <?php echo htmlspecialchars($form_type_meta['icon']); ?>"></i>
                                    <span><?php echo htmlspecialchars($form_type_meta['label']); ?></span>
                                </span>
                            </div>
                            <div class="p-6 space-y-5">
                                <div class="flex flex-wrap gap-3">
                                    <a class="btn btn-outline" href="teacher_class_activities.php?action=activities"><i class="fas fa-arrow-left"></i><span>Back to Activities</span></a>
                                </div>

                                <form method="POST" class="space-y-5">
                                    <div class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4 space-y-4">
                                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Structure and Audience</h3>
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div>
                                                <label class="mb-2 block text-sm font-semibold text-slate-700">Activity Type</label>
                                                <select name="activity_type" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required>
                                                    <option value="">Select activity type</option>
                                                    <?php foreach ($activity_type_labels as $type_key => $type_label): ?>
                                                        <option value="<?php echo htmlspecialchars($type_key); ?>" <?php echo ($form_activity['activity_type'] ?? '') === $type_key ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($type_label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-2 block text-sm font-semibold text-slate-700">Activity Title</label>
                                                <input type="text" name="title" value="<?php echo htmlspecialchars($form_activity['title'] ?? ''); ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="Enter activity title" required>
                                            </div>
                                        </div>
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div>
                                                <label class="mb-2 block text-sm font-semibold text-slate-700">Subject</label>
                                                <select name="subject_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required>
                                                    <option value="">Select subject</option>
                                                    <?php foreach ($assigned_subjects as $subject): ?>
                                                        <option value="<?php echo (int) $subject['id']; ?>" <?php echo (string) ($form_activity['subject_id'] ?? '') === (string) $subject['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-2 block text-sm font-semibold text-slate-700">Class</label>
                                                <select name="class_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required>
                                                    <option value="">Select class</option>
                                                    <?php foreach ($assigned_classes as $class): ?>
                                                        <option value="<?php echo (int) $class['id']; ?>" <?php echo (string) ($form_activity['class_id'] ?? '') === (string) $class['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4 space-y-4">
                                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Student Guidance</h3>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Description</label>
                                            <textarea name="description" rows="3" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-4 text-sm text-slate-700" placeholder="Brief description for the activity"><?php echo htmlspecialchars($form_activity['description'] ?? ''); ?></textarea>
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Detailed Instructions</label>
                                            <textarea name="instructions" rows="6" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-4 text-sm text-slate-700" placeholder="Provide clear instructions for students" required><?php echo htmlspecialchars($form_activity['instructions'] ?? ''); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4 space-y-4">
                                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Timing and Publication</h3>
                                        <div class="grid gap-4 md:grid-cols-3">
                                            <div>
                                                <label class="mb-2 block text-sm font-semibold text-slate-700">Due Date and Time</label>
                                                <?php
                                                $due_date_value = '';
                                                if (!empty($form_activity['due_date'])) {
                                                    $timestamp = strtotime((string) $form_activity['due_date']);
                                                    if ($timestamp) {
                                                        $due_date_value = date('Y-m-d\TH:i', $timestamp);
                                                    }
                                                }
                                                ?>
                                                <input type="datetime-local" name="due_date" value="<?php echo htmlspecialchars($due_date_value); ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                            </div>
                                            <div>
                                                <label class="mb-2 block text-sm font-semibold text-slate-700">Total Marks</label>
                                                <input type="number" name="total_marks" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($form_activity['total_marks'] ?? 100)); ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="100">
                                            </div>
                                            <div>
                                                <label class="mb-2 block text-sm font-semibold text-slate-700">Publication Status</label>
                                                <select name="status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                                    <option value="draft" <?php echo ($form_activity['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                                    <option value="published" <?php echo ($form_activity['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap gap-3">
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><span><?php echo $action === 'create' ? 'Save Activity' : 'Update Activity'; ?></span></button>
                                        <a class="btn btn-outline" href="teacher_class_activities.php?action=activities"><i class="fas fa-xmark"></i><span>Cancel</span></a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                                <h2 class="text-xl font-semibold text-ink-900">Publishing Checklist</h2>
                                <div class="mt-4 space-y-4 text-sm text-slate-600">
                                    <p><span class="font-semibold text-ink-900">Clarify the task:</span> Write instructions that tell students exactly what to submit and how it will be graded.</p>
                                    <p><span class="font-semibold text-ink-900">Check the due date:</span> Only publish with a deadline when students actually need time tracking for the task.</p>
                                    <p><span class="font-semibold text-ink-900">Match the audience:</span> Confirm the selected class and subject before saving or publishing.</p>
                                </div>
                            </section>
                            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                                <h2 class="text-xl font-semibold text-ink-900">Assigned Coverage</h2>
                                <div class="mt-4 space-y-4 text-sm text-slate-600">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Subjects</p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <?php foreach ($assigned_subjects as $subject): ?>
                                                <span class="rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700 border border-sky-100"><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Classes</p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <?php foreach ($assigned_classes as $class): ?>
                                                <span class="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-700 border border-teal-100"><?php echo htmlspecialchars($class['class_name']); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </section>
                <?php endif; ?>
            <?php elseif ($action === 'grade'): ?>
                <?php if (!$grade_submission): ?>
                    <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                        <div class="rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                            <i class="fas fa-file-circle-question text-3xl text-amber-600"></i>
                            <p class="mt-4 text-lg font-semibold text-ink-900">Submission details are unavailable</p>
                            <p class="mt-2 text-sm">Return to the submissions list and choose another record to review.</p>
                            <div class="mt-6">
                                <a class="btn btn-outline" href="teacher_class_activities.php?action=submissions<?php echo $activity_id > 0 ? '&id=' . (int) $activity_id : ''; ?>"><i class="fas fa-arrow-left"></i><span>Back to Submissions</span></a>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <?php $grade_type_meta = $get_activity_meta($grade_submission['activity_type'] ?? ''); ?>
                    <?php $grade_status_meta = $get_status_meta($grade_submission['status'] ?? 'pending'); ?>
                    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_360px]">
                        <div class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5 space-y-6">
                            <div class="flex flex-col gap-4 border-b border-ink-900/5 pb-6 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Submission Review</p>
                                    <h2 class="mt-2 text-2xl font-display text-ink-900"><?php echo htmlspecialchars($grade_submission['activity_title']); ?></h2>
                                    <p class="mt-2 text-sm text-slate-600">Inspect the student response, supporting context, and grading status before saving marks.</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-semibold <?php echo $grade_type_meta['pill']; ?>">
                                        <i class="fas <?php echo htmlspecialchars($grade_type_meta['icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($grade_type_meta['label']); ?></span>
                                    </span>
                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-semibold <?php echo $grade_status_meta['pill']; ?>">
                                        <i class="fas <?php echo htmlspecialchars($grade_status_meta['icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($grade_status_meta['label']); ?></span>
                                    </span>
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="info-tile">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">Student</p>
                                    <p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($grade_submission['full_name']); ?></p>
                                    <p class="mt-1 text-sm text-slate-500"><?php echo htmlspecialchars($grade_submission['admission_no']); ?></p>
                                </div>
                                <div class="info-tile">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">Submitted On</p>
                                    <p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($format_datetime($grade_submission['submitted_at'] ?? null)); ?></p>
                                    <p class="mt-1 text-sm text-slate-500">Maximum marks: <?php echo htmlspecialchars($format_number($grade_submission['total_marks'] ?? 0)); ?></p>
                                </div>
                            </div>

                            <div>
                                <p class="mb-2 text-sm font-semibold text-slate-700">Activity Instructions</p>
                                <div class="read-only-surface min-h-[8rem]"><?php echo nl2br(htmlspecialchars($grade_submission['instructions'] ?? '')); ?></div>
                            </div>

                            <div>
                                <p class="mb-2 text-sm font-semibold text-slate-700">Student Submission</p>
                                <div class="read-only-surface min-h-[10rem] bg-white">
                                    <?php if (!empty($grade_submission['submission_text'])): ?>
                                        <?php echo nl2br(htmlspecialchars($grade_submission['submission_text'])); ?>
                                    <?php else: ?>
                                        <span class="text-slate-500">No text was submitted for this activity.</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($grade_submission['attachment_path'])): ?>
                                <div class="flex flex-wrap gap-3">
                                    <a class="btn btn-outline" href="<?php echo htmlspecialchars($grade_submission['attachment_path']); ?>" target="_blank" rel="noopener">
                                        <i class="fas fa-download"></i>
                                        <span>Open Attachment</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                            <div class="border-b border-ink-900/5 pb-6">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Scoring</p>
                                <h2 class="mt-2 text-2xl font-display text-ink-900">Grade Submission</h2>
                                <p class="mt-2 text-sm text-slate-600">Save marks and written feedback for this learner.</p>
                            </div>
                            <form method="POST" class="mt-6 space-y-5">
                                <input type="hidden" name="submission_id" value="<?php echo (int) ($grade_submission['id'] ?? 0); ?>">
                                <input type="hidden" name="activity_id" value="<?php echo (int) $activity_id; ?>">

                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-700">Marks</label>
                                    <input type="number" name="marks" min="0" max="<?php echo htmlspecialchars((string) ($grade_submission['total_marks'] ?? 0)); ?>" step="0.01" value="<?php echo htmlspecialchars((string) ($grade_submission['marks_obtained'] ?? '')); ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required>
                                    <p class="mt-2 text-xs text-slate-500">Maximum available: <?php echo htmlspecialchars($format_number($grade_submission['total_marks'] ?? 0)); ?></p>
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-700">Feedback</label>
                                    <textarea name="feedback" rows="8" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-4 text-sm text-slate-700" placeholder="Provide clear, constructive feedback for the student"><?php echo htmlspecialchars($grade_submission['feedback'] ?? ''); ?></textarea>
                                </div>

                                <div class="flex flex-wrap gap-3">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><span>Save Grade</span></button>
                                    <a class="btn btn-outline" href="teacher_class_activities.php?action=submissions&id=<?php echo (int) $activity_id; ?>"><i class="fas fa-arrow-left"></i><span>Back to Submissions</span></a>
                                </div>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>
            <?php else: ?>
                <section class="activity-tabs">
                    <?php
                    $nav_items = [
                        'dashboard' => ['label' => 'Dashboard', 'icon' => 'fa-tachometer-alt'],
                        'activities' => ['label' => 'My Activities', 'icon' => 'fa-tasks'],
                        'submissions' => ['label' => 'Submissions', 'icon' => 'fa-paper-plane'],
                        'reports' => ['label' => 'Reports', 'icon' => 'fa-chart-column'],
                    ];
                    foreach ($nav_items as $nav_key => $nav_item):
                        $is_active = $action === $nav_key;
                    ?>
                        <a href="teacher_class_activities.php?action=<?php echo htmlspecialchars($nav_key); ?>" class="activity-tab <?php echo $is_active ? 'is-active' : ''; ?>">
                            <i class="fas <?php echo htmlspecialchars($nav_item['icon']); ?>"></i>
                            <span><?php echo htmlspecialchars($nav_item['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </section>

                <?php if ($action === 'dashboard'): ?>
                    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.3fr)_360px]">
                        <div class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Overview</p>
                                    <h2 class="mt-2 text-2xl font-display text-ink-900">Recent Activities</h2>
                                    <p class="mt-2 text-sm text-slate-600">Your latest published and draft work, with quick access to edit and submission review.</p>
                                </div>
                                <a class="btn btn-outline" href="teacher_class_activities.php?action=activities"><i class="fas fa-table-list"></i><span>View All</span></a>
                            </div>
                            <?php if (empty($dashboard_recent_activities)): ?>
                                <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                                    <i class="fas fa-inbox text-3xl text-teal-700"></i>
                                    <p class="mt-4 text-lg font-semibold text-ink-900">No activities created yet</p>
                                    <p class="mt-2 text-sm">Create your first activity to start tracking student work.</p>
                                </div>
                            <?php else: ?>
                                <div class="activity-list-grid mt-6">
                                    <?php foreach ($dashboard_recent_activities as $activity): ?>
                                        <?php
                                        $type_meta = $get_activity_meta($activity['activity_type'] ?? '');
                                        $status_meta = $get_status_meta($activity['status'] ?? '');
                                        ?>
                                        <article class="activity-list-card">
                                            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                                                <div>
                                                    <h3 class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars($activity['title']); ?></h3>
                                                    <p class="mt-2 text-sm text-slate-600"><?php echo htmlspecialchars($activity['subject_name']); ?> · <?php echo htmlspecialchars($activity['class_name']); ?></p>
                                                </div>
                                                <div class="flex flex-wrap gap-2">
                                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-semibold <?php echo $type_meta['pill']; ?>">
                                                        <i class="fas <?php echo htmlspecialchars($type_meta['icon']); ?>"></i>
                                                        <span><?php echo htmlspecialchars($type_meta['label']); ?></span>
                                                    </span>
                                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-semibold <?php echo $status_meta['pill']; ?>">
                                                        <i class="fas <?php echo htmlspecialchars($status_meta['icon']); ?>"></i>
                                                        <span><?php echo htmlspecialchars($status_meta['label']); ?></span>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="grid gap-3 md:grid-cols-2">
                                                <div class="info-tile">
                                                    <p class="text-xs uppercase tracking-wide text-slate-500">Due Date</p>
                                                    <p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($format_datetime($activity['due_date'] ?? null)); ?></p>
                                                </div>
                                                <div class="info-tile">
                                                    <p class="text-xs uppercase tracking-wide text-slate-500">Marks</p>
                                                    <p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($format_number($activity['total_marks'] ?? 0)); ?></p>
                                                </div>
                                            </div>
                                            <div class="flex flex-wrap gap-3">
                                                <a class="btn btn-outline" href="teacher_class_activities.php?action=submissions&id=<?php echo (int) $activity['id']; ?>"><i class="fas fa-eye"></i><span>View Submissions</span></a>
                                                <a class="btn btn-primary" href="teacher_class_activities.php?action=edit&id=<?php echo (int) $activity['id']; ?>"><i class="fas fa-pen"></i><span>Edit Activity</span></a>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="space-y-6">
                            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                                <h2 class="text-xl font-semibold text-ink-900">Quick Actions</h2>
                                <div class="mt-4 grid gap-3 text-sm font-semibold">
                                    <a href="teacher_class_activities.php?action=create" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                        <span><i class="fas fa-plus-circle mr-3 text-teal-700"></i>Create a new activity</span>
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                    <a href="teacher_class_activities.php?action=submissions" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                        <span><i class="fas fa-paper-plane mr-3 text-teal-700"></i>Review submissions</span>
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                    <a href="teacher_class_activities.php?action=reports" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                        <span><i class="fas fa-chart-column mr-3 text-teal-700"></i>Open reports</span>
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </section>

                            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                                <h2 class="text-xl font-semibold text-ink-900">Recent Submission Flow</h2>
                                <?php if (empty($dashboard_recent_submissions)): ?>
                                    <div class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">
                                        Submission activity will appear here once students start responding.
                                    </div>
                                <?php else: ?>
                                    <div class="mt-4 space-y-3">
                                        <?php foreach ($dashboard_recent_submissions as $submission): ?>
                                            <?php $submission_status_meta = $get_status_meta($submission['status'] ?? 'pending'); ?>
                                            <article class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4">
                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($submission['student_name']); ?></p>
                                                        <p class="mt-1 text-sm text-slate-500"><?php echo htmlspecialchars($submission['activity_title']); ?></p>
                                                    </div>
                                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo $submission_status_meta['pill']; ?>">
                                                        <i class="fas <?php echo htmlspecialchars($submission_status_meta['icon']); ?>"></i>
                                                        <span><?php echo htmlspecialchars($submission_status_meta['label']); ?></span>
                                                    </span>
                                                </div>
                                                <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                                    <span><i class="fas fa-users mr-2 text-teal-700"></i><?php echo htmlspecialchars($submission['class_name'] ?? 'Class not set'); ?></span>
                                                    <span><i class="fas fa-calendar-check mr-2 text-teal-700"></i><?php echo htmlspecialchars($format_datetime($submission['submitted_at'] ?? null)); ?></span>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>
                    </section>
                <?php elseif ($action === 'activities'): ?>
                    <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Activity Library</p>
                                <h2 class="mt-2 text-2xl font-display text-ink-900">My Activities</h2>
                                <p class="mt-2 text-sm text-slate-600">Filter by type and status, then move directly into editing or submission review.</p>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <select id="filterType" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                    <option value="">All Types</option>
                                    <?php foreach ($activity_type_labels as $type_key => $type_label): ?>
                                        <option value="<?php echo htmlspecialchars($type_key); ?>"><?php echo htmlspecialchars($type_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="filterStatus" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                    <option value="">All Statuses</option>
                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>
                                    <option value="closed">Closed</option>
                                </select>
                                <a class="btn btn-primary" href="teacher_class_activities.php?action=create"><i class="fas fa-plus-circle"></i><span>Create Activity</span></a>
                            </div>
                        </div>

                        <?php if (empty($activities)): ?>
                            <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                                <i class="fas fa-folder-open text-3xl text-teal-700"></i>
                                <p class="mt-4 text-lg font-semibold text-ink-900">No activities available yet</p>
                                <p class="mt-2 text-sm">Create an activity to start building your teacher workflow.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-shell mt-6 rounded-3xl border border-ink-900/10">
                                <table class="min-w-[1180px] w-full bg-white text-sm" id="activitiesTable">
                                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                        <tr>
                                            <th class="px-4 py-4">Title</th>
                                            <th class="px-4 py-4">Type</th>
                                            <th class="px-4 py-4">Subject / Class</th>
                                            <th class="px-4 py-4">Due Date</th>
                                            <th class="px-4 py-4">Status</th>
                                            <th class="px-4 py-4">Progress</th>
                                            <th class="px-4 py-4">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activities as $activity): ?>
                                            <?php
                                            $type_meta = $get_activity_meta($activity['activity_type'] ?? '');
                                            $status_meta = $get_status_meta($activity['status'] ?? '');
                                            $total_submissions = (int) ($activity['total_submissions'] ?? 0);
                                            $graded_submissions = (int) ($activity['graded_submissions'] ?? 0);
                                            $submission_rate = $total_submissions > 0 ? (int) round(($graded_submissions / $total_submissions) * 100) : 0;
                                            ?>
                                            <tr class="border-t border-slate-100 align-top" data-type="<?php echo htmlspecialchars((string) ($activity['activity_type'] ?? '')); ?>" data-status="<?php echo htmlspecialchars((string) ($activity['status'] ?? '')); ?>">
                                                <td class="px-4 py-4">
                                                    <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($activity['title']); ?></p>
                                                    <p class="mt-1 text-xs text-slate-500">Marks: <?php echo htmlspecialchars($format_number($activity['total_marks'] ?? 0)); ?></p>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo $type_meta['pill']; ?>">
                                                        <i class="fas <?php echo htmlspecialchars($type_meta['icon']); ?>"></i>
                                                        <span><?php echo htmlspecialchars($type_meta['label']); ?></span>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($activity['subject_name']); ?></p>
                                                    <p class="mt-1 text-slate-500"><?php echo htmlspecialchars($activity['class_name']); ?></p>
                                                </td>
                                                <td class="px-4 py-4 text-slate-700">
                                                    <p><?php echo htmlspecialchars($format_datetime($activity['due_date'] ?? null)); ?></p>
                                                    <?php if ($is_overdue($activity['due_date'] ?? null)): ?>
                                                        <p class="mt-2 inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 border border-rose-100">
                                                            <i class="fas fa-triangle-exclamation"></i>
                                                            <span>Overdue</span>
                                                        </p>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo $status_meta['pill']; ?>">
                                                        <i class="fas <?php echo htmlspecialchars($status_meta['icon']); ?>"></i>
                                                        <span><?php echo htmlspecialchars($status_meta['label']); ?></span>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div class="min-w-[180px]">
                                                        <div class="progress-track">
                                                            <div class="progress-bar" style="width: <?php echo $submission_rate; ?>%;"></div>
                                                        </div>
                                                        <p class="mt-2 text-xs text-slate-500"><?php echo $graded_submissions; ?>/<?php echo $total_submissions; ?> graded</p>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div class="flex flex-wrap gap-2 min-w-[220px]">
                                                        <a class="btn btn-outline !px-3 !py-2" href="teacher_class_activities.php?action=submissions&id=<?php echo (int) $activity['id']; ?>"><i class="fas fa-eye"></i><span>View</span></a>
                                                        <a class="btn btn-primary !px-3 !py-2" href="teacher_class_activities.php?action=edit&id=<?php echo (int) $activity['id']; ?>"><i class="fas fa-pen"></i><span>Edit</span></a>
                                                        <button type="button" class="btn btn-outline !px-3 !py-2" onclick="deleteActivity(<?php echo (int) $activity['id']; ?>)"><i class="fas fa-trash"></i><span>Delete</span></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to review the full table on small screens.</span></p>
                        <?php endif; ?>
                    </section>
                <?php elseif ($action === 'submissions'): ?>
                    <?php if ($activity_id > 0): ?>
                        <?php if (!$selected_activity): ?>
                            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                                <div class="rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                                    <i class="fas fa-circle-exclamation text-3xl text-amber-600"></i>
                                    <p class="mt-4 text-lg font-semibold text-ink-900">The selected activity could not be found</p>
                                    <p class="mt-2 text-sm">Return to the full submissions view and choose another activity.</p>
                                </div>
                            </section>
                        <?php else: ?>
                            <?php
                            $selected_type_meta = $get_activity_meta($selected_activity['activity_type'] ?? '');
                            $selected_status_meta = $get_status_meta($selected_activity['status'] ?? '');
                            ?>
                            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                                <div class="flex flex-col gap-4 border-b border-ink-900/5 pb-6 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Submission Focus</p>
                                        <h2 class="mt-2 text-2xl font-display text-ink-900"><?php echo htmlspecialchars($selected_activity['title']); ?></h2>
                                        <p class="mt-2 text-sm text-slate-600"><?php echo htmlspecialchars($selected_activity['subject_name']); ?> · <?php echo htmlspecialchars($selected_activity['class_name']); ?></p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-semibold <?php echo $selected_type_meta['pill']; ?>">
                                            <i class="fas <?php echo htmlspecialchars($selected_type_meta['icon']); ?>"></i>
                                            <span><?php echo htmlspecialchars($selected_type_meta['label']); ?></span>
                                        </span>
                                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-semibold <?php echo $selected_status_meta['pill']; ?>">
                                            <i class="fas <?php echo htmlspecialchars($selected_status_meta['icon']); ?>"></i>
                                            <span><?php echo htmlspecialchars($selected_status_meta['label']); ?></span>
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-6 grid gap-4 md:grid-cols-3">
                                    <div class="info-tile">
                                        <p class="text-xs uppercase tracking-wide text-slate-500">Due Date</p>
                                        <p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($format_datetime($selected_activity['due_date'] ?? null)); ?></p>
                                    </div>
                                    <div class="info-tile">
                                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Marks</p>
                                        <p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($format_number($selected_activity['total_marks'] ?? 0)); ?></p>
                                    </div>
                                    <div class="info-tile">
                                        <p class="text-xs uppercase tracking-wide text-slate-500">Submissions</p>
                                        <p class="mt-2 font-semibold text-ink-900"><?php echo count($selected_activity_submissions); ?></p>
                                    </div>
                                </div>

                                <div class="mt-6">
                                    <p class="mb-2 text-sm font-semibold text-slate-700">Activity Instructions</p>
                                    <div class="read-only-surface min-h-[8rem]"><?php echo nl2br(htmlspecialchars($selected_activity['instructions'] ?? '')); ?></div>
                                </div>
                            </section>

                            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Review Queue</p>
                                        <h2 class="mt-2 text-2xl font-display text-ink-900">Student Submissions</h2>
                                        <p class="mt-2 text-sm text-slate-600">Open each submission to review marks, feedback, and attached work.</p>
                                    </div>
                                    <a class="btn btn-outline" href="teacher_class_activities.php?action=submissions"><i class="fas fa-arrow-left"></i><span>All Submissions</span></a>
                                </div>

                                <?php if (empty($selected_activity_submissions)): ?>
                                    <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                                        <i class="fas fa-paper-plane text-3xl text-teal-700"></i>
                                        <p class="mt-4 text-lg font-semibold text-ink-900">No submissions yet</p>
                                        <p class="mt-2 text-sm">Student responses for this activity will appear here once they submit.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-shell mt-6 rounded-3xl border border-ink-900/10">
                                        <table class="min-w-[980px] w-full bg-white text-sm">
                                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                                <tr>
                                                    <th class="px-4 py-4">Student</th>
                                                    <th class="px-4 py-4">Submitted On</th>
                                                    <th class="px-4 py-4">Status</th>
                                                    <th class="px-4 py-4">Marks</th>
                                                    <th class="px-4 py-4">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($selected_activity_submissions as $submission): ?>
                                                    <?php $submission_status_meta = $get_status_meta($submission['status'] ?? 'pending'); ?>
                                                    <tr class="border-t border-slate-100 align-top">
                                                        <td class="px-4 py-4">
                                                            <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($submission['full_name']); ?></p>
                                                            <p class="mt-1 text-slate-500"><?php echo htmlspecialchars($submission['admission_no']); ?></p>
                                                        </td>
                                                        <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($format_datetime($submission['submitted_at'] ?? null)); ?></td>
                                                        <td class="px-4 py-4">
                                                            <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo $submission_status_meta['pill']; ?>">
                                                                <i class="fas <?php echo htmlspecialchars($submission_status_meta['icon']); ?>"></i>
                                                                <span><?php echo htmlspecialchars($submission_status_meta['label']); ?></span>
                                                            </span>
                                                        </td>
                                                        <td class="px-4 py-4 text-slate-700">
                                                            <?php if ($submission['marks_obtained'] !== null): ?>
                                                                <?php echo htmlspecialchars($format_number($submission['marks_obtained'])); ?>/<?php echo htmlspecialchars($format_number($selected_activity['total_marks'] ?? 0)); ?>
                                                            <?php else: ?>
                                                                Not graded
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="px-4 py-4">
                                                            <a class="btn btn-primary !px-3 !py-2" href="teacher_class_activities.php?action=grade&id=<?php echo (int) $activity_id; ?>&submission_id=<?php echo (int) $submission['id']; ?>"><i class="fas fa-pen"></i><span>Grade</span></a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways to inspect the full submissions table on smaller screens.</span></p>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
                    <?php else: ?>
                        <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Submission Overview</p>
                                    <h2 class="mt-2 text-2xl font-display text-ink-900">All Submissions</h2>
                                    <p class="mt-2 text-sm text-slate-600">Browse the most recent submission records across your activities.</p>
                                </div>
                            </div>

                            <?php if (empty($all_submissions)): ?>
                                <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                                    <i class="fas fa-folder-open text-3xl text-teal-700"></i>
                                    <p class="mt-4 text-lg font-semibold text-ink-900">No submissions available</p>
                                    <p class="mt-2 text-sm">Once learners begin responding, their work will appear in this table.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-shell mt-6 rounded-3xl border border-ink-900/10">
                                    <table class="min-w-[1180px] w-full bg-white text-sm">
                                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                            <tr>
                                                <th class="px-4 py-4">Activity</th>
                                                <th class="px-4 py-4">Student</th>
                                                <th class="px-4 py-4">Class</th>
                                                <th class="px-4 py-4">Submitted</th>
                                                <th class="px-4 py-4">Status</th>
                                                <th class="px-4 py-4">Marks</th>
                                                <th class="px-4 py-4">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($all_submissions as $submission): ?>
                                                <?php
                                                $submission_type_meta = $get_activity_meta($submission['activity_type'] ?? '');
                                                $submission_status_meta = $get_status_meta($submission['status'] ?? 'pending');
                                                ?>
                                                <tr class="border-t border-slate-100 align-top">
                                                    <td class="px-4 py-4">
                                                        <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($submission['activity_title']); ?></p>
                                                        <p class="mt-2 inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo $submission_type_meta['pill']; ?>">
                                                            <i class="fas <?php echo htmlspecialchars($submission_type_meta['icon']); ?>"></i>
                                                            <span><?php echo htmlspecialchars($submission_type_meta['label']); ?></span>
                                                        </p>
                                                    </td>
                                                    <td class="px-4 py-4">
                                                        <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($submission['student_name']); ?></p>
                                                        <p class="mt-1 text-slate-500"><?php echo htmlspecialchars($submission['admission_no']); ?></p>
                                                    </td>
                                                    <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($submission['class_name']); ?></td>
                                                    <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($format_datetime($submission['submitted_at'] ?? null)); ?></td>
                                                    <td class="px-4 py-4">
                                                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo $submission_status_meta['pill']; ?>">
                                                            <i class="fas <?php echo htmlspecialchars($submission_status_meta['icon']); ?>"></i>
                                                            <span><?php echo htmlspecialchars($submission_status_meta['label']); ?></span>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-4 text-slate-700">
                                                        <?php if ($submission['marks_obtained'] !== null): ?>
                                                            <?php echo htmlspecialchars($format_number($submission['marks_obtained'])); ?>/<?php echo htmlspecialchars($format_number($submission['total_marks'] ?? 0)); ?>
                                                        <?php else: ?>
                                                            Not graded
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-4">
                                                        <a class="btn btn-outline !px-3 !py-2" href="teacher_class_activities.php?action=submissions&id=<?php echo (int) $submission['activity_id']; ?>"><i class="fas fa-eye"></i><span>View Activity</span></a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to inspect the full table on mobile.</span></p>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                <?php elseif ($action === 'reports'): ?>
                    <section class="grid gap-4 md:grid-cols-3">
                        <article class="subject-metric-card">
                            <div class="subject-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-layer-group"></i></div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Reported Activities</p>
                            <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo (int) $report_summary['activity_count']; ?></h2>
                            <p class="text-sm text-slate-500">Activities with reportable submission data</p>
                        </article>
                        <article class="subject-metric-card">
                            <div class="subject-metric-icon bg-sky-600/10 text-sky-700"><i class="fas fa-chart-line"></i></div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Average Score</p>
                            <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($report_summary['average_score']); ?></h2>
                            <p class="text-sm text-slate-500">Mean score across graded activity records</p>
                        </article>
                        <article class="subject-metric-card">
                            <div class="subject-metric-icon bg-violet-600/10 text-violet-700"><i class="fas fa-percentage"></i></div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Avg Submission Rate</p>
                            <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo (int) $report_summary['average_submission_rate']; ?>%</h2>
                            <p class="text-sm text-slate-500">Average learner participation across activities</p>
                        </article>
                    </section>

                    <section class="grid gap-6 xl:grid-cols-2">
                        <div class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                            <div class="flex flex-col gap-2">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Performance</p>
                                <h2 class="text-2xl font-display text-ink-900">Average Score by Activity Type</h2>
                            </div>
                            <div class="chart-shell mt-6">
                                <canvas id="typePerformanceChart"></canvas>
                            </div>
                        </div>
                        <div class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                            <div class="flex flex-col gap-2">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Status Mix</p>
                                <h2 class="text-2xl font-display text-ink-900">Submission Status Distribution</h2>
                            </div>
                            <div class="chart-shell mt-6">
                                <canvas id="submissionStatusChart"></canvas>
                            </div>
                        </div>
                    </section>
                    <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Reporting</p>
                                <h2 class="mt-2 text-2xl font-display text-ink-900">Detailed Activity Report</h2>
                                <p class="mt-2 text-sm text-slate-600">Track class coverage, student participation, and graded performance per activity.</p>
                            </div>
                        </div>

                        <?php if (empty($reports)): ?>
                            <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                                <i class="fas fa-chart-column text-3xl text-teal-700"></i>
                                <p class="mt-4 text-lg font-semibold text-ink-900">No reporting data yet</p>
                                <p class="mt-2 text-sm">Reports will populate here once activities begin receiving student submissions.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-shell mt-6 rounded-3xl border border-ink-900/10">
                                <table class="min-w-[1280px] w-full bg-white text-sm">
                                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                        <tr>
                                            <th class="px-4 py-4">Activity</th>
                                            <th class="px-4 py-4">Type</th>
                                            <th class="px-4 py-4">Subject / Class</th>
                                            <th class="px-4 py-4">Students</th>
                                            <th class="px-4 py-4">Submitted</th>
                                            <th class="px-4 py-4">Graded</th>
                                            <th class="px-4 py-4">Avg Score</th>
                                            <th class="px-4 py-4">Submission Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reports as $report): ?>
                                            <?php $report_type_meta = $get_activity_meta($report['activity_type'] ?? ''); ?>
                                            <tr class="border-t border-slate-100 align-top">
                                                <td class="px-4 py-4"><p class="font-semibold text-ink-900"><?php echo htmlspecialchars($report['title']); ?></p></td>
                                                <td class="px-4 py-4">
                                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo $report_type_meta['pill']; ?>">
                                                        <i class="fas <?php echo htmlspecialchars($report_type_meta['icon']); ?>"></i>
                                                        <span><?php echo htmlspecialchars($report_type_meta['label']); ?></span>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($report['subject_name']); ?></p>
                                                    <p class="mt-1 text-slate-500"><?php echo htmlspecialchars($report['class_name']); ?></p>
                                                </td>
                                                <td class="px-4 py-4 text-slate-700"><?php echo (int) ($report['total_students'] ?? 0); ?></td>
                                                <td class="px-4 py-4 text-slate-700"><?php echo (int) ($report['submitted_count'] ?? 0); ?></td>
                                                <td class="px-4 py-4 text-slate-700"><?php echo (int) ($report['graded_count'] ?? 0); ?></td>
                                                <td class="px-4 py-4 text-slate-700"><?php echo $report['avg_score'] !== null ? htmlspecialchars($format_number($report['avg_score'])) : 'N/A'; ?></td>
                                                <td class="px-4 py-4">
                                                    <div class="min-w-[160px]">
                                                        <div class="progress-track">
                                                            <div class="progress-bar" style="width: <?php echo (int) ($report['submission_rate'] ?? 0); ?>%;"></div>
                                                        </div>
                                                        <p class="mt-2 text-xs text-slate-500"><?php echo (int) ($report['submission_rate'] ?? 0); ?>%</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to inspect the full reporting table on mobile.</span></p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <?php include '../includes/floating-button.php'; ?>

    <?php if ($action === 'reports'): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>
    <script>
        const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        const sidebar = document.querySelector('[data-sidebar]');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        const body = document.body;

        const openSidebar = () => {
            if (!sidebar || !overlay) {
                return;
            }
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('opacity-0', 'pointer-events-none');
            overlay.classList.add('opacity-100');
            body.classList.add('nav-open');
        };

        const closeSidebarShell = () => {
            if (!sidebar || !overlay) {
                return;
            }
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

        document.addEventListener('DOMContentLoaded', () => {
            const filterType = document.getElementById('filterType');
            const filterStatus = document.getElementById('filterStatus');
            const activitiesTable = document.getElementById('activitiesTable');

            if (filterType && filterStatus && activitiesTable) {
                const filterTable = () => {
                    const typeValue = filterType.value;
                    const statusValue = filterStatus.value;
                    const rows = activitiesTable.querySelectorAll('tbody tr');

                    rows.forEach((row) => {
                        const rowType = row.getAttribute('data-type');
                        const rowStatus = row.getAttribute('data-status');
                        const typeMatch = !typeValue || rowType === typeValue;
                        const statusMatch = !statusValue || rowStatus === statusValue;
                        row.style.display = typeMatch && statusMatch ? '' : 'none';
                    });
                };

                filterType.addEventListener('change', filterTable);
                filterStatus.addEventListener('change', filterTable);
            }

            <?php if ($action === 'reports'): ?>
            if (window.Chart) {
                const typeChartCanvas = document.getElementById('typePerformanceChart');
                const submissionStatusCanvas = document.getElementById('submissionStatusChart');
                const typeChartData = <?php echo json_encode($report_type_chart, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                const statusChartData = <?php echo json_encode($report_status_chart, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

                if (typeChartCanvas) {
                    new Chart(typeChartCanvas, {
                        type: 'bar',
                        data: {
                            labels: typeChartData.labels,
                            datasets: [{
                                label: 'Average Score',
                                data: typeChartData.values,
                                backgroundColor: ['#0f766e', '#2563eb', '#f59e0b', '#7c3aed', '#059669'],
                                borderRadius: 12
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, suggestedMax: 100 } }
                        }
                    });
                }

                if (submissionStatusCanvas) {
                    new Chart(submissionStatusCanvas, {
                        type: 'doughnut',
                        data: {
                            labels: statusChartData.labels,
                            datasets: [{
                                data: statusChartData.values,
                                backgroundColor: ['#0f766e', '#0ea5e9', '#f59e0b', '#fb7185'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom' } }
                        }
                    });
                }
            }
            <?php endif; ?>
        });

        function deleteActivity(id) {
            if (!confirm('Delete this activity and its submission records?')) {
                return;
            }
            window.location.href = 'teacher_class_activities.php?action=delete&id=' + id;
        }
    </script>
</body>
</html>
