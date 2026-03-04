<?php
$total_evaluations = (int)($overall_stats['total_evaluations'] ?? 0);
$total_students = (int)($overall_stats['total_students'] ?? 0);
$total_classes = (int)($overall_stats['total_classes'] ?? 0);
$excellent_count = (int)($overall_stats['excellent_count'] ?? 0);
$needs_improvement_count = (int)($overall_stats['needs_improvement_count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Evaluations | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/teacher-workspace.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .evaluation-page .workspace-hero-actions {
            grid-template-columns: 1fr;
        }

        .evaluation-page .workspace-table {
            min-width: 1320px;
        }

        .rating-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            border: 1px solid transparent;
            padding: 0.35rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1.1;
            white-space: nowrap;
        }

        .rating-pill.excellent {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }

        .rating-pill.very-good {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .rating-pill.good {
            background: #fffbeb;
            border-color: #fde68a;
            color: #b45309;
        }

        .rating-pill.needs-improvement {
            background: #fef2f2;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .workspace-detail-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .workspace-detail-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 1.25rem;
            background: #f8fafc;
            padding: 1rem;
        }

        .workspace-detail-card p + p {
            margin-top: 0.5rem;
        }

        .workspace-stack {
            display: grid;
            gap: 1rem;
        }

        .evaluation-empty-state {
            border: 1px dashed rgba(148, 163, 184, 0.8);
            border-radius: 1.75rem;
            background: #f8fafc;
            padding: 3rem 1.5rem;
            text-align: center;
        }

        @media (max-width: 767px) {
            .workspace-detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="landing bg-slate-50 workspace-page evaluation-page">
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
                <div class="workspace-hero p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Assessment Workspace</p>
                            <h1 class="mt-3 font-display text-3xl font-semibold leading-tight sm:text-4xl">Student evaluation workflows, reporting rhythm, and review patterns.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">Capture ratings across academic and behavioural domains, filter records with the same workspace conventions, and send the same scoped dataset into printable and export-ready reports.</p>
                        </div>
                        <div class="workspace-hero-actions grid gap-3 sm:grid-cols-2">
                            <a href="#evaluation-form" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                <i class="fas fa-plus-circle"></i>
                                <span>Add Evaluation</span>
                            </a>
                            <a href="#evaluation-library" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-table-list"></i>
                                <span>Review Records</span>
                            </a>
                            <button type="button" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15" onclick="printEvaluations()">
                                <i class="fas fa-print"></i>
                                <span>Print Report</span>
                            </button>
                            <button type="button" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15" onclick="openModal('exportModal')">
                                <i class="fas fa-file-export"></i>
                                <span>Export Data</span>
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
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_filtered_evaluations; ?></h2>
                        <p class="text-sm text-slate-500">Records that match the current filters</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-sky-600/10 text-sky-700">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Evaluations</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_evaluations; ?></h2>
                        <p class="text-sm text-slate-500">All evaluation records you have entered</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-amber-500/10 text-amber-700">
                            <i class="fas fa-users"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Students Covered</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_students; ?></h2>
                        <p class="text-sm text-slate-500">Learners with at least one evaluation</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-emerald-600/10 text-emerald-700">
                            <i class="fas fa-star"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Excellent Signals</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $excellent_count; ?></h2>
                        <p class="text-sm text-slate-500">Records containing at least one excellent rating</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-rose-600/10 text-rose-700">
                            <i class="fas fa-triangle-exclamation"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Needs Attention</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $needs_improvement_count; ?></h2>
                        <p class="text-sm text-slate-500">Records carrying a needs-improvement flag</p>
                    </article>
                </div>
            </section>

            <?php if ($flash_error !== ''): ?>
                <section class="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-800 shadow-soft">
                    <h2 class="text-lg font-semibold">Review the highlighted issues</h2>
                    <p class="mt-2 text-sm"><?php echo htmlspecialchars($flash_error); ?></p>
                </section>
            <?php endif; ?>

            <?php if ($flash_success !== ''): ?>
                <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900 shadow-soft">
                    <p class="font-semibold">Update completed</p>
                    <p class="mt-1 text-sm"><?php echo htmlspecialchars($flash_success); ?></p>
                </section>
            <?php endif; ?>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]" id="evaluation-form">
                <div class="overflow-hidden rounded-3xl border border-ink-900/5 bg-white shadow-lift">
                    <div class="flex flex-col gap-4 border-b border-ink-900/5 p-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Evaluation Editor</p>
                            <h2 class="mt-2 font-display text-2xl text-ink-900">Add a new evaluation record</h2>
                        </div>
                        <button type="button" class="btn btn-outline" id="toggleEvaluationForm" aria-expanded="true" aria-controls="evaluationFormBody">
                            <i class="fas fa-eye-slash"></i>
                            <span>Hide Form</span>
                        </button>
                    </div>
                    <div id="evaluationFormBody" class="space-y-5 p-6">
                        <?php if (empty($students)): ?>
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-600">
                                No students are currently available in your assigned classes. Once students are assigned to classes you teach, the evaluation form will unlock.
                            </div>
                        <?php else: ?>
                            <form method="POST" class="space-y-5">
                                <input type="hidden" name="add_evaluation" value="1">

                                <div class="space-y-4 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4">
                                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Student and session details</h3>
                                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                        <div class="xl:col-span-2">
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Student</label>
                                            <select class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" name="student_id" data-student-select required>
                                                <option value="">Select student</option>
                                                <?php foreach ($students as $student): ?>
                                                    <option value="<?php echo (int)$student['id']; ?>">
                                                        <?php echo htmlspecialchars($student['full_name'] . ' - ' . $student['class_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Class</label>
                                            <select class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" name="class_id" data-class-select required>
                                                <option value="">Select class</option>
                                                <?php foreach ($class_options as $class_id => $class_name): ?>
                                                    <option value="<?php echo (int)$class_id; ?>"><?php echo htmlspecialchars($class_name); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="mt-2 text-xs text-slate-500">This field auto-fills from the selected student.</p>
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Term</label>
                                            <select class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" name="term" required>
                                                <?php foreach ($term_options as $term_value => $term_label): ?>
                                                    <option value="<?php echo htmlspecialchars($term_value); ?>"><?php echo htmlspecialchars($term_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Academic Year</label>
                                            <input type="number" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" name="year" min="2000" max="2099" value="<?php echo date('Y'); ?>" required>
                                        </div>
                                        <div class="rounded-2xl border border-ink-900/10 bg-white px-4 py-4 text-sm text-slate-600">
                                            Use this form for fresh entries. Existing records can be refined from the table below with the same modal review flow used across the workspace.
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-4 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4">
                                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Performance domains</h3>
                                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                        <?php
                                        $rating_fields = [
                                            'academic' => 'Academic Performance',
                                            'non_academic' => 'Non-Academic Activities',
                                            'cognitive' => 'Cognitive Domain',
                                            'psychomotor' => 'Psychomotor Domain',
                                            'affective' => 'Affective Domain',
                                        ];
                                        foreach ($rating_fields as $field_name => $field_label):
                                        ?>
                                            <div>
                                                <label class="mb-2 block text-sm font-semibold text-slate-700"><?php echo htmlspecialchars($field_label); ?></label>
                                                <select class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" name="<?php echo htmlspecialchars($field_name); ?>" required>
                                                    <?php foreach ($rating_options as $rating_value => $rating_label): ?>
                                                        <option value="<?php echo htmlspecialchars($rating_value); ?>"><?php echo htmlspecialchars($rating_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="space-y-3 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4">
                                    <label class="block text-sm font-semibold text-slate-700">Comments and recommendations</label>
                                    <textarea class="min-h-[10rem] w-full rounded-3xl border border-slate-200 bg-white px-4 py-4 text-sm text-slate-700" name="comments" placeholder="Capture strengths, intervention notes, and next-step recommendations."></textarea>
                                    <p class="text-sm text-slate-500">Keep comments clear enough to support reporting, export, and parent-facing follow-up.</p>
                                </div>

                                <div class="flex flex-wrap gap-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i>
                                        <span>Save Evaluation</span>
                                    </button>
                                    <button type="reset" class="btn btn-outline">
                                        <i class="fas fa-rotate-right"></i>
                                        <span>Reset Form</span>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="space-y-6">
                    <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                        <h2 class="text-xl font-semibold text-ink-900">Workflow Shortcuts</h2>
                        <div class="mt-4 grid gap-3 text-sm font-semibold">
                            <button type="button" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-left text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700" onclick="printEvaluations()">
                                <span><i class="fas fa-print mr-3 text-teal-700"></i>Generate printable PDF</span>
                                <i class="fas fa-arrow-right"></i>
                            </button>
                            <button type="button" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-left text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700" onclick="openModal('exportModal')">
                                <span><i class="fas fa-file-export mr-3 text-teal-700"></i>Export filtered records</span>
                                <i class="fas fa-download"></i>
                            </button>
                            <a href="#evaluation-library" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                <span><i class="fas fa-table-list mr-3 text-teal-700"></i>Jump to evaluation table</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </section>

                    <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Current Snapshot</p>
                        <div class="mt-4 grid gap-4">
                            <div class="rounded-2xl border border-ink-900/10 bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Classes With Records</p>
                                <p class="mt-2 text-3xl font-semibold text-ink-900"><?php echo $total_classes; ?></p>
                                <p class="mt-2 text-sm text-slate-500">Active class groups represented in your evaluation history.</p>
                            </div>
                            <div class="rounded-2xl border border-ink-900/10 bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Active Filters</p>
                                <p class="mt-2 text-3xl font-semibold text-ink-900"><?php echo $active_filter_count; ?></p>
                                <p class="mt-2 text-sm text-slate-500">
                                    <?php if ($recent_update !== ''): ?>
                                        Latest visible update: <?php echo htmlspecialchars($recent_update); ?>
                                    <?php else: ?>
                                        Apply search, class, term, year, or rating filters to tighten the working set.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="rounded-2xl border border-ink-900/10 bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Accessible Students</p>
                                <p class="mt-2 text-3xl font-semibold text-ink-900"><?php echo count($students); ?></p>
                                <p class="mt-2 text-sm text-slate-500">Students available for evaluation in your assigned classes.</p>
                            </div>
                        </div>
                    </section>
                </div>
            </section>

            <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift" id="evaluation-library">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Reporting</p>
                        <h2 class="mt-2 font-display text-2xl text-ink-900">Evaluation record library</h2>
                        <p class="mt-2 text-sm text-slate-600">Browse, filter, and maintain the same scoped records that feed your report and export actions.</p>
                    </div>
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Visible On This Page</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo $page_total; ?></p>
                    </div>
                </div>

                <form method="GET" class="mt-6 space-y-4 rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                        <div class="xl:col-span-2">
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Search student or comment</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="Name, admission number, or comment">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Class</label>
                            <select name="class_filter" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                <option value="">All classes</option>
                                <?php foreach ($class_options as $class_id => $class_name): ?>
                                    <option value="<?php echo (int)$class_id; ?>" <?php echo (string)$class_filter === (string)$class_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($class_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Term</label>
                            <select name="term_filter" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                <option value="">All terms</option>
                                <?php foreach ($term_options as $term_value => $term_label): ?>
                                    <option value="<?php echo htmlspecialchars($term_value); ?>" <?php echo $term_filter === $term_value ? 'selected' : ''; ?>><?php echo htmlspecialchars($term_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Academic Year</label>
                            <input type="number" name="year_filter" min="2000" max="2099" value="<?php echo htmlspecialchars($year_filter); ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="2026">
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto]">
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Rating flag</label>
                            <select name="rating_filter" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                <option value="">Any rating</option>
                                <?php foreach ($rating_options as $rating_value => $rating_label): ?>
                                    <option value="<?php echo htmlspecialchars($rating_value); ?>" <?php echo $rating_filter === $rating_value ? 'selected' : ''; ?>><?php echo htmlspecialchars($rating_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex flex-wrap items-end gap-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i>
                                <span>Apply Filters</span>
                            </button>
                            <a href="student-evaluation.php#evaluation-library" class="btn btn-outline">
                                <i class="fas fa-xmark"></i>
                                <span>Reset</span>
                            </a>
                        </div>
                    </div>
                </form>

                <?php if (empty($evaluations)): ?>
                    <div class="evaluation-empty-state mt-6">
                        <p class="text-lg font-semibold text-ink-900">No evaluations match the current view</p>
                        <p class="mt-2 text-sm text-slate-500">Adjust the filters or add a new evaluation record to populate this table.</p>
                    </div>
                <?php else: ?>
                    <div class="workspace-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10">
                        <table class="workspace-table w-full bg-white">
                            <thead>
                                <tr class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                    <th class="px-4 py-4">Student</th>
                                    <th class="px-4 py-4">Class</th>
                                    <th class="px-4 py-4">Session</th>
                                    <th class="px-4 py-4">Academic</th>
                                    <th class="px-4 py-4">Non-Academic</th>
                                    <th class="px-4 py-4">Cognitive</th>
                                    <th class="px-4 py-4">Psychomotor</th>
                                    <th class="px-4 py-4">Affective</th>
                                    <th class="px-4 py-4">Comments</th>
                                    <th class="px-4 py-4">Updated</th>
                                    <th class="px-4 py-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evaluations as $evaluation): ?>
                                    <?php
                                    $updated_label = $evaluation['updated_at'] ?: $evaluation['created_at'];
                                    $comments_preview = trim((string)($evaluation['comments'] ?? ''));
                                    $comments_preview = $comments_preview !== '' ? mb_strimwidth($comments_preview, 0, 90, '...') : 'No comments recorded';
                                    ?>
                                    <tr class="border-t border-slate-100 align-top">
                                        <td class="px-4 py-4">
                                            <div class="font-semibold text-ink-900"><?php echo htmlspecialchars($evaluation['full_name']); ?></div>
                                            <div class="mt-1 text-xs text-slate-500">Admission No: <?php echo htmlspecialchars((string)$evaluation['admission_no']); ?></div>
                                        </td>
                                        <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($evaluation['class_name']); ?></td>
                                        <td class="px-4 py-4">
                                            <div class="rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700"><?php echo htmlspecialchars($term_options[(string)$evaluation['term']] ?? ('Term ' . $evaluation['term'])); ?></div>
                                            <div class="mt-2 text-sm text-slate-500"><?php echo htmlspecialchars((string)$evaluation['academic_year']); ?></div>
                                        </td>
                                        <td class="px-4 py-4"><span class="rating-pill <?php echo htmlspecialchars((string)$evaluation['academic']); ?>"><?php echo htmlspecialchars($rating_options[(string)$evaluation['academic']] ?? ucfirst(str_replace('-', ' ', (string)$evaluation['academic']))); ?></span></td>
                                        <td class="px-4 py-4"><span class="rating-pill <?php echo htmlspecialchars((string)$evaluation['non_academic']); ?>"><?php echo htmlspecialchars($rating_options[(string)$evaluation['non_academic']] ?? ucfirst(str_replace('-', ' ', (string)$evaluation['non_academic']))); ?></span></td>
                                        <td class="px-4 py-4"><span class="rating-pill <?php echo htmlspecialchars((string)$evaluation['cognitive']); ?>"><?php echo htmlspecialchars($rating_options[(string)$evaluation['cognitive']] ?? ucfirst(str_replace('-', ' ', (string)$evaluation['cognitive']))); ?></span></td>
                                        <td class="px-4 py-4"><span class="rating-pill <?php echo htmlspecialchars((string)$evaluation['psychomotor']); ?>"><?php echo htmlspecialchars($rating_options[(string)$evaluation['psychomotor']] ?? ucfirst(str_replace('-', ' ', (string)$evaluation['psychomotor']))); ?></span></td>
                                        <td class="px-4 py-4"><span class="rating-pill <?php echo htmlspecialchars((string)$evaluation['affective']); ?>"><?php echo htmlspecialchars($rating_options[(string)$evaluation['affective']] ?? ucfirst(str_replace('-', ' ', (string)$evaluation['affective']))); ?></span></td>
                                        <td class="px-4 py-4 text-sm text-slate-600"><?php echo htmlspecialchars($comments_preview); ?></td>
                                        <td class="px-4 py-4 text-sm text-slate-500"><?php echo $updated_label ? htmlspecialchars(date('d M Y, h:i A', strtotime($updated_label))) : 'N/A'; ?></td>
                                        <td class="px-4 py-4">
                                            <div class="flex min-w-[190px] flex-col gap-2">
                                                <button type="button" class="btn btn-outline !px-3 !py-2" onclick="viewEvaluation(<?php echo (int)$evaluation['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                    <span>View</span>
                                                </button>
                                                <button type="button" class="btn btn-outline !px-3 !py-2" onclick="editEvaluation(<?php echo (int)$evaluation['id']; ?>)">
                                                    <i class="fas fa-pen"></i>
                                                    <span>Edit</span>
                                                </button>
                                                <button type="button" class="btn btn-outline !px-3 !py-2" onclick="deleteEvaluation(<?php echo (int)$evaluation['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                    <span>Delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to review the full evaluation table on small screens.</span></p>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-4 text-sm text-slate-500">
                        <div class="flex flex-wrap gap-4">
                            <span><i class="fas fa-database mr-2 text-teal-700"></i>Total filtered: <?php echo $total_filtered_evaluations; ?></span>
                            <span><i class="fas fa-list mr-2 text-teal-700"></i>Showing <?php echo $page_total; ?> on this page</span>
                        </div>
                        <div class="pagination-nav">
                            <?php
                            $previous_params = $current_query_without_page;
                            $previous_params['page'] = max(1, $page - 1);
                            $next_params = $current_query_without_page;
                            $next_params['page'] = min($total_pages, $page + 1);
                            ?>
                            <?php if ($page > 1): ?>
                                <a href="<?php echo htmlspecialchars('student-evaluation.php?' . http_build_query($previous_params)); ?>" class="btn btn-outline">
                                    <i class="fas fa-chevron-left"></i>
                                    <span>Previous</span>
                                </a>
                            <?php endif; ?>
                            <?php foreach ($pagination_items as $pagination_item): ?>
                                <?php if ($pagination_item === '...'): ?>
                                    <span class="pagination-ellipsis" aria-hidden="true">...</span>
                                <?php else: ?>
                                    <?php $page_params = $current_query_without_page; $page_params['page'] = $pagination_item; ?>
                                    <a href="<?php echo htmlspecialchars('student-evaluation.php?' . http_build_query($page_params)); ?>" class="pagination-link <?php echo $page === $pagination_item ? 'is-active' : ''; ?>"<?php echo $page === $pagination_item ? ' aria-current="page"' : ''; ?>>
                                        <?php echo $pagination_item; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <span class="font-semibold text-slate-600">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo htmlspecialchars('student-evaluation.php?' . http_build_query($next_params)); ?>" class="btn btn-outline">
                                    <span>Next</span>
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <div id="viewModal" class="workspace-modal" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
        <div class="workspace-modal-card rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Evaluation Preview</p>
                    <h2 id="viewModalTitle" class="mt-2 font-display text-2xl text-ink-900">Evaluation details</h2>
                </div>
                <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700" onclick="closeModal('viewModal')">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div id="viewModalBody" class="workspace-stack"></div>
        </div>
    </div>

    <div id="editModal" class="workspace-modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="workspace-modal-card rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift">
            <form method="POST">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Evaluation Editor</p>
                        <h2 id="editModalTitle" class="mt-2 font-display text-2xl text-ink-900">Refine evaluation details</h2>
                    </div>
                    <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700" onclick="closeModal('editModal')">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
                <div id="editModalBody" class="workspace-stack"></div>
                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <span>Update Evaluation</span>
                    </button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">
                        <i class="fas fa-xmark"></i>
                        <span>Cancel</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="exportModal" class="workspace-modal" role="dialog" aria-modal="true" aria-labelledby="exportModalTitle">
        <div class="workspace-modal-card rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Export Workflow</p>
                    <h2 id="exportModalTitle" class="mt-2 font-display text-2xl text-ink-900">Choose an export package</h2>
                </div>
                <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700" onclick="closeModal('exportModal')">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="grid gap-3">
                <button type="button" class="flex items-start justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-left hover:border-teal-600/30 hover:bg-teal-600/10" onclick="startExport('full')">
                    <span>
                        <span class="block font-semibold text-ink-900">Full data export</span>
                        <span class="mt-1 block text-sm text-slate-500">Complete evaluation records with all visible filters applied.</span>
                    </span>
                    <i class="fas fa-file-pdf text-teal-700"></i>
                </button>
                <button type="button" class="flex items-start justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-left hover:border-teal-600/30 hover:bg-teal-600/10" onclick="startExport('summary')">
                    <span>
                        <span class="block font-semibold text-ink-900">Summary report</span>
                        <span class="mt-1 block text-sm text-slate-500">Headline counts and scoped class summaries for the current view.</span>
                    </span>
                    <i class="fas fa-chart-pie text-teal-700"></i>
                </button>
                <button type="button" class="flex items-start justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-left hover:border-teal-600/30 hover:bg-teal-600/10" onclick="startExport('analytics')">
                    <span>
                        <span class="block font-semibold text-ink-900">Analytics package</span>
                        <span class="mt-1 block text-sm text-slate-500">Distribution and recommendation pages generated from the same filter set.</span>
                    </span>
                    <i class="fas fa-chart-column text-teal-700"></i>
                </button>
            </div>
        </div>
    </div>

    <?php include '../includes/floating-button.php'; ?>

    <script>
        const evaluations = <?php echo json_encode($evaluations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const students = <?php echo json_encode($students_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const ratingLabels = <?php echo json_encode($rating_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

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
            sidebar.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', closeSidebarShell);
            });
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (character) => {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                };
                return map[character] || character;
            });
        }

        function nl2brSafe(value) {
            return escapeHtml(value).replace(/\n/g, '<br>');
        }

        function formatRating(value) {
            return ratingLabels[value] || String(value || '').replace(/-/g, ' ');
        }

        function ratingBadge(value) {
            return `<span class="rating-pill ${escapeHtml(value)}">${escapeHtml(formatRating(value))}</span>`;
        }

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('is-open');
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('is-open');
            }
        }

        function currentReportQuery() {
            const params = new URLSearchParams(window.location.search);
            return params.toString();
        }

        function printEvaluations() {
            const query = currentReportQuery();
            window.location.href = `generate-evaluation-pdf.php${query ? `?${query}` : ''}`;
        }

        function startExport(type) {
            const params = new URLSearchParams(window.location.search);
            params.set('type', type);
            window.location.href = `export-evaluations-pdf.php?${params.toString()}`;
        }

        function buildRatingSelect(name, label, currentValue) {
            const optionsHtml = Object.entries(ratingLabels).map(([value, text]) => {
                const selected = currentValue === value ? 'selected' : '';
                return `<option value="${escapeHtml(value)}" ${selected}>${escapeHtml(text)}</option>`;
            }).join('');

            return `
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700">${escapeHtml(label)}</label>
                    <select class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" name="${escapeHtml(name)}" required>
                        ${optionsHtml}
                    </select>
                </div>
            `;
        }

        function viewEvaluation(evaluationId) {
            const evaluation = evaluations.find((item) => Number(item.id) === Number(evaluationId));
            if (!evaluation) {
                return;
            }

            document.getElementById('viewModalBody').innerHTML = `
                <div class="workspace-detail-grid">
                    <div class="workspace-detail-card">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Student Information</p>
                        <p class="mt-3 text-sm text-slate-700"><strong class="text-ink-900">Name:</strong> ${escapeHtml(evaluation.full_name)}</p>
                        <p class="text-sm text-slate-700"><strong class="text-ink-900">Class:</strong> ${escapeHtml(evaluation.class_name)}</p>
                        <p class="text-sm text-slate-700"><strong class="text-ink-900">Admission No:</strong> ${escapeHtml(evaluation.admission_no)}</p>
                        <p class="text-sm text-slate-700"><strong class="text-ink-900">Session:</strong> Term ${escapeHtml(evaluation.term)} / ${escapeHtml(evaluation.academic_year)}</p>
                    </div>
                    <div class="workspace-detail-card">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Domain Ratings</p>
                        <div class="mt-3 grid gap-3">
                            <div class="flex items-center justify-between gap-3 text-sm text-slate-700"><span>Academic</span>${ratingBadge(evaluation.academic)}</div>
                            <div class="flex items-center justify-between gap-3 text-sm text-slate-700"><span>Non-Academic</span>${ratingBadge(evaluation.non_academic)}</div>
                            <div class="flex items-center justify-between gap-3 text-sm text-slate-700"><span>Cognitive</span>${ratingBadge(evaluation.cognitive)}</div>
                            <div class="flex items-center justify-between gap-3 text-sm text-slate-700"><span>Psychomotor</span>${ratingBadge(evaluation.psychomotor)}</div>
                            <div class="flex items-center justify-between gap-3 text-sm text-slate-700"><span>Affective</span>${ratingBadge(evaluation.affective)}</div>
                        </div>
                    </div>
                </div>
                <div class="workspace-detail-card">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Comments and Recommendations</p>
                    <p class="mt-3 text-sm leading-6 text-slate-700">${evaluation.comments ? nl2brSafe(evaluation.comments) : 'No comments recorded.'}</p>
                </div>
            `;

            openModal('viewModal');
        }

        function editEvaluation(evaluationId) {
            const evaluation = evaluations.find((item) => Number(item.id) === Number(evaluationId));
            if (!evaluation) {
                return;
            }

            document.getElementById('editModalBody').innerHTML = `
                <input type="hidden" name="evaluation_id" value="${escapeHtml(evaluation.id)}">
                <input type="hidden" name="update_evaluation" value="1">
                <div class="workspace-detail-card">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Record Context</p>
                    <div class="mt-3 grid gap-4 md:grid-cols-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Student</p>
                            <p class="mt-2 text-sm font-semibold text-ink-900">${escapeHtml(evaluation.full_name)}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Class</p>
                            <p class="mt-2 text-sm font-semibold text-ink-900">${escapeHtml(evaluation.class_name)}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Session</p>
                            <p class="mt-2 text-sm font-semibold text-ink-900">Term ${escapeHtml(evaluation.term)} / ${escapeHtml(evaluation.academic_year)}</p>
                        </div>
                    </div>
                </div>
                <div class="workspace-detail-card">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Domain Ratings</p>
                    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        ${buildRatingSelect('academic', 'Academic Performance', evaluation.academic)}
                        ${buildRatingSelect('non_academic', 'Non-Academic Activities', evaluation.non_academic)}
                        ${buildRatingSelect('cognitive', 'Cognitive Domain', evaluation.cognitive)}
                        ${buildRatingSelect('psychomotor', 'Psychomotor Domain', evaluation.psychomotor)}
                        ${buildRatingSelect('affective', 'Affective Domain', evaluation.affective)}
                    </div>
                </div>
                <div class="workspace-detail-card">
                    <label class="block text-sm font-semibold text-slate-700">Comments and Recommendations</label>
                    <textarea class="mt-3 min-h-[10rem] w-full rounded-3xl border border-slate-200 bg-white px-4 py-4 text-sm text-slate-700" name="comments">${escapeHtml(evaluation.comments || '')}</textarea>
                </div>
            `;

            openModal('editModal');
        }

        function deleteEvaluation(evaluationId) {
            if (!window.confirm('Delete this evaluation record? This action cannot be undone.')) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'post';
            form.innerHTML = `
                <input type="hidden" name="delete_evaluation" value="1">
                <input type="hidden" name="evaluation_id" value="${escapeHtml(evaluationId)}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function syncClassFromStudent() {
            const studentSelect = document.querySelector('[data-student-select]');
            const classSelect = document.querySelector('[data-class-select]');
            if (!studentSelect || !classSelect) {
                return;
            }

            const selectedStudent = students.find((student) => Number(student.id) === Number(studentSelect.value));
            if (selectedStudent) {
                classSelect.value = String(selectedStudent.class_id);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const toggleButton = document.getElementById('toggleEvaluationForm');
            const formBody = document.getElementById('evaluationFormBody');
            const studentSelect = document.querySelector('[data-student-select]');

            if (toggleButton && formBody) {
                const setState = (expanded) => {
                    formBody.style.display = expanded ? 'block' : 'none';
                    toggleButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    toggleButton.innerHTML = expanded
                        ? '<i class="fas fa-eye-slash"></i><span>Hide Form</span>'
                        : '<i class="fas fa-eye"></i><span>Show Form</span>';
                };

                setState(true);
                toggleButton.addEventListener('click', () => {
                    setState(formBody.style.display === 'none');
                });
            }

            if (studentSelect) {
                studentSelect.addEventListener('change', syncClassFromStudent);
                syncClassFromStudent();
            }

            document.querySelectorAll('.workspace-modal').forEach((modal) => {
                modal.addEventListener('click', (event) => {
                    if (event.target === modal) {
                        modal.classList.remove('is-open');
                    }
                });
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    document.querySelectorAll('.workspace-modal.is-open').forEach((modal) => {
                        modal.classList.remove('is-open');
                    });
                }
            });
        });
    </script>
</body>
</html>
