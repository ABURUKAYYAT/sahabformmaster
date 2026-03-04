<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT Results | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/teacher-workspace.css">
    <link rel="stylesheet" href="../assets/css/teacher-cbt.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="landing bg-slate-50 cbt-page workspace-page">
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
                <a class="btn btn-outline" href="cbt_tests.php">CBT Tests</a>
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
                <div class="workspace-hero p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Assessment Reporting</p>
                            <h1 class="mt-3 font-display text-3xl font-semibold leading-tight sm:text-4xl">CBT results with the same clean workspace structure.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">Review student performance, compare submission coverage, and keep digital assessment reporting aligned with the question bank and CBT authoring flow.</p>
                        </div>
                        <div class="workspace-hero-actions grid gap-3 sm:grid-cols-2">
                            <a href="cbt_tests.php" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                <i class="fas fa-arrow-left"></i>
                                <span>Back to Tests</span>
                            </a>
                            <a href="#results-table" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-table-list"></i>
                                <span>Review Scores</span>
                            </a>
                            <a href="cbt_tests.php?action=create" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-plus-circle"></i>
                                <span>Create Test</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="workspace-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-file-circle-check"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Submissions</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $attempt_count; ?></h2>
                        <p class="text-sm text-slate-500">Completed CBT attempts</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-sky-600/10 text-sky-700"><i class="fas fa-users"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Students</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $student_count; ?></h2>
                        <p class="text-sm text-slate-500">Distinct learners represented</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-amber-500/10 text-amber-700"><i class="fas fa-laptop-file"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Tests Covered</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $test_count; ?></h2>
                        <p class="text-sm text-slate-500">Assessments with submissions</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-emerald-600/10 text-emerald-700"><i class="fas fa-chart-line"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Average Score</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $average_percent; ?>%</h2>
                        <p class="text-sm text-slate-500">Across all submitted attempts</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-violet-600/10 text-violet-700"><i class="fas fa-trophy"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Top Result</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $highest_percent; ?>%</h2>
                        <p class="text-sm text-slate-500"><?php echo htmlspecialchars($highest_score_label); ?></p>
                    </article>
                </div>
            </section>

            <div id="cbt-offline-status" style="display:none;"></div>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_340px]">
                <div class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift" id="results-table">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Score Register</p>
                            <h2 class="mt-2 font-display text-2xl text-ink-900">Submitted CBT Attempts</h2>
                            <p class="mt-2 text-sm text-slate-600">Each row captures the student, test context, raw score, percentage, and submission time.</p>
                        </div>
                        <div class="rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Latest Submission</p>
                            <p class="text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($format_datetime($latest_submission)); ?></p>
                        </div>
                    </div>

                    <?php if (empty($attempts)): ?>
                        <div class="cbt-empty-state mt-6">
                            <i class="fas fa-chart-column text-3xl text-teal-700"></i>
                            <p class="mt-4 text-lg font-semibold text-ink-900">No submitted CBT attempts yet</p>
                            <p class="mt-2 text-sm text-slate-500">Results will appear here once students complete published CBT tests.</p>
                        </div>
                    <?php else: ?>
                        <div class="workspace-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10">
                            <table class="workspace-table min-w-[980px] w-full bg-white text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                    <tr>
                                        <th class="px-4 py-4">Student</th>
                                        <th class="px-4 py-4">Test</th>
                                        <th class="px-4 py-4">Class / Subject</th>
                                        <th class="px-4 py-4">Score</th>
                                        <th class="px-4 py-4">Percent</th>
                                        <th class="px-4 py-4">Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attempts as $attempt): ?>
                                        <tr class="border-t border-slate-100 align-top">
                                            <td class="px-4 py-4">
                                                <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($attempt['full_name']); ?></p>
                                            </td>
                                            <td class="px-4 py-4">
                                                <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($attempt['title']); ?></p>
                                            </td>
                                            <td class="px-4 py-4">
                                                <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($attempt['class_name']); ?></p>
                                                <p class="mt-1 text-slate-500"><?php echo htmlspecialchars($attempt['subject_name']); ?></p>
                                            </td>
                                            <td class="px-4 py-4 font-semibold text-ink-900"><?php echo (int)$attempt['score']; ?> / <?php echo (int)$attempt['total_questions']; ?></td>
                                            <td class="px-4 py-4">
                                                <span class="cbt-score-badge <?php echo htmlspecialchars($get_score_badge_class((float)$attempt['percent'])); ?>"><?php echo htmlspecialchars((string)$attempt['percent']); ?>%</span>
                                            </td>
                                            <td class="px-4 py-4 text-slate-600"><?php echo htmlspecialchars($format_datetime($attempt['submitted_at'] ?? null)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to inspect the full results table on mobile.</span></p>
                    <?php endif; ?>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                        <h2 class="text-xl font-semibold text-ink-900">Reporting Notes</h2>
                        <div class="cbt-note-list mt-4">
                            <p><strong>Track uptake:</strong> Compare the number of submitted attempts with the class size after publishing a CBT.</p>
                            <p><strong>Spot outliers:</strong> Use the percentage column to identify very low or unusually high scores for review.</p>
                            <p><strong>Refine future tests:</strong> Revisit the question bank when a pattern suggests an assessment was too easy or too difficult.</p>
                        </div>
                    </section>

                    <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                        <h2 class="text-xl font-semibold text-ink-900">Quick Links</h2>
                        <div class="mt-4 grid gap-3 text-sm font-semibold">
                            <a href="cbt_tests.php" class="cbt-anchor-card">
                                <span><i class="fas fa-laptop-file mr-3 text-teal-700"></i>Manage CBT tests</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                            <a href="questions.php" class="cbt-anchor-card">
                                <span><i class="fas fa-database mr-3 text-teal-700"></i>Review question bank</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <?php include '../includes/floating-button.php'; ?>

    <script src="../assets/js/cbt-offline-sync.js"></script>
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

        CBTOfflineSync.init({
            queueKey: 'cbt_teacher_offline_queue_v1',
            formSelector: 'form[data-offline-sync=\"true\"]',
            statusElementId: 'cbt-offline-status',
            statusPrefix: 'Teacher CBT Sync:',
            swPath: '../cbt-sw.js'
        });
    </script>
</body>
</html>
