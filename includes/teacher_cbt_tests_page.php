<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT Tests | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
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
                            <h1 class="mt-3 font-display text-3xl font-semibold leading-tight sm:text-4xl"><?php echo htmlspecialchars($page_title); ?></h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base"><?php echo htmlspecialchars($page_summary); ?></p>
                        </div>
                        <div class="workspace-hero-actions grid gap-3 sm:grid-cols-2">
                            <?php if ($is_editor): ?>
                                <a href="cbt_tests.php" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                    <i class="fas fa-table-list"></i>
                                    <span>Test Registry</span>
                                </a>
                                <a href="#test-questions" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                    <i class="fas fa-list-check"></i>
                                    <span><?php echo $action === 'create' ? 'Next: Save Test' : 'Manage Questions'; ?></span>
                                </a>
                                <a href="cbt_results.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                    <i class="fas fa-chart-column"></i>
                                    <span>View Results</span>
                                </a>
                            <?php else: ?>
                                <a href="cbt_tests.php?action=create" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>Create Test</span>
                                </a>
                                <a href="#test-registry" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                    <i class="fas fa-laptop-file"></i>
                                    <span>Review Tests</span>
                                </a>
                                <a href="cbt_results.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                    <i class="fas fa-chart-column"></i>
                                    <span>View Results</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="workspace-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-laptop-file"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Tests</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_tests; ?></h2>
                        <p class="text-sm text-slate-500">Published, draft, and closed CBTs</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-emerald-600/10 text-emerald-700"><i class="fas fa-bullhorn"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Published</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $published_tests; ?></h2>
                        <p class="text-sm text-slate-500">Live tests visible to students</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-amber-500/10 text-amber-700"><i class="fas fa-pen-ruler"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Drafts</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $draft_tests; ?></h2>
                        <p class="text-sm text-slate-500">Tests still being refined</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-sky-600/10 text-sky-700"><i class="fas fa-list-check"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Question Load</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_test_questions; ?></h2>
                        <p class="text-sm text-slate-500">Questions across all CBT tests</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-violet-600/10 text-violet-700"><i class="fas fa-square-poll-vertical"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Submissions</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_submissions; ?></h2>
                        <p class="text-sm text-slate-500">Completed student attempts recorded</p>
                    </article>
                </div>
            </section>

            <?php if ($message): ?>
                <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900 shadow-soft">
                    <p class="font-semibold">Update completed</p>
                    <p class="mt-1 text-sm"><?php echo htmlspecialchars($message); ?></p>
                </section>
            <?php endif; ?>

            <?php if ($error): ?>
                <section class="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-800 shadow-soft">
                    <p class="font-semibold">Action needs attention</p>
                    <p class="mt-1 text-sm"><?php echo htmlspecialchars($error); ?></p>
                </section>
            <?php endif; ?>

            <div id="cbt-offline-status" style="display:none;"></div>

            <?php if ($is_editor): ?>
                <?php $current_status = strtolower((string)($test['status'] ?? 'draft')); ?>
                <section class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_340px]" id="test-editor">
                    <div class="cbt-panel overflow-hidden">
                        <div class="cbt-panel-header">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Test Editor</p>
                                <h2 class="mt-2 font-display text-2xl text-ink-900"><?php echo $action === 'create' ? 'Set Up a New CBT Test' : 'Refine Test Details'; ?></h2>
                                <p class="mt-2 text-sm text-slate-600">Keep the metadata, class, subject, and schedule aligned before you add or import questions.</p>
                            </div>
                            <?php if (!empty($test['id'])): ?>
                                <span class="cbt-status-badge <?php echo htmlspecialchars($get_status_badge_class($current_status)); ?>">
                                    <i class="fas fa-circle text-[0.55rem]"></i>
                                    <span><?php echo htmlspecialchars($status_labels[$current_status] ?? ucfirst($current_status)); ?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="cbt-panel-body space-y-5">
                            <form method="POST" class="space-y-5">
                                <input type="hidden" name="save_test" value="1">

                                <div class="cbt-panel-muted space-y-4">
                                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Identity and Assignment</h3>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Test Title</label>
                                            <input type="text" class="cbt-input" name="title" value="<?php echo htmlspecialchars($test['title'] ?? ''); ?>" required>
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Duration (minutes)</label>
                                            <input type="number" class="cbt-input" name="duration_minutes" min="5" max="300" value="<?php echo htmlspecialchars((string)($test['duration_minutes'] ?? 30)); ?>">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Class</label>
                                            <select class="cbt-input" name="class_id" required>
                                                <option value="">Select class</option>
                                                <?php foreach ($classes as $class_option): ?>
                                                    <option value="<?php echo (int)$class_option['id']; ?>" <?php echo (string)($test['class_id'] ?? '') === (string)$class_option['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($class_option['class_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Subject</label>
                                            <select class="cbt-input" name="subject_id" required>
                                                <option value="">Select subject</option>
                                                <?php foreach ($subjects as $subject_option): ?>
                                                    <option value="<?php echo (int)$subject_option['id']; ?>" <?php echo (string)($test['subject_id'] ?? '') === (string)$subject_option['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($subject_option['subject_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="cbt-panel-muted space-y-4">
                                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Schedule and Visibility</h3>
                                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Starts At</label>
                                            <input type="datetime-local" class="cbt-input" name="starts_at" value="<?php echo !empty($test['starts_at']) ? date('Y-m-d\TH:i', strtotime($test['starts_at'])) : ''; ?>">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Ends At</label>
                                            <input type="datetime-local" class="cbt-input" name="ends_at" value="<?php echo !empty($test['ends_at']) ? date('Y-m-d\TH:i', strtotime($test['ends_at'])) : ''; ?>">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Status</label>
                                            <select class="cbt-input" name="status">
                                                <?php foreach ($status_labels as $status_value => $status_label): ?>
                                                    <option value="<?php echo htmlspecialchars($status_value); ?>" <?php echo $current_status === $status_value ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($status_label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="mt-2 text-sm text-slate-500">Only published tests are available in the student CBT interface.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-3">
                                    <a href="cbt_tests.php" class="btn btn-outline">
                                        <i class="fas fa-arrow-left"></i>
                                        <span>Back to Tests</span>
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i>
                                        <span><?php echo $action === 'create' ? 'Save and Continue' : 'Save Changes'; ?></span>
                                    </button>
                                </div>
                            </form>

                            <?php if ($action === 'edit' && !empty($test['id'])): ?>
                                <div class="flex flex-wrap gap-3 border-t border-ink-900/5 pt-5">
                                    <?php if ($current_status !== 'published'): ?>
                                        <form method="POST" class="inline-flex">
                                            <input type="hidden" name="set_test_status" value="1">
                                            <input type="hidden" name="target_test_id" value="<?php echo (int)$test['id']; ?>">
                                            <input type="hidden" name="target_status" value="published">
                                            <input type="hidden" name="redirect_mode" value="edit">
                                            <button type="submit" class="btn btn-primary" <?php echo $current_question_count <= 0 ? 'disabled title="Add at least one question before publishing"' : ''; ?>>
                                                <i class="fas fa-bullhorn"></i>
                                                <span>Publish Now</span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" class="inline-flex">
                                            <input type="hidden" name="set_test_status" value="1">
                                            <input type="hidden" name="target_test_id" value="<?php echo (int)$test['id']; ?>">
                                            <input type="hidden" name="target_status" value="draft">
                                            <input type="hidden" name="redirect_mode" value="edit">
                                            <button type="submit" class="btn btn-outline">
                                                <i class="fas fa-eye-slash"></i>
                                                <span>Move to Draft</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($current_status !== 'closed'): ?>
                                        <form method="POST" class="inline-flex">
                                            <input type="hidden" name="set_test_status" value="1">
                                            <input type="hidden" name="target_test_id" value="<?php echo (int)$test['id']; ?>">
                                            <input type="hidden" name="target_status" value="closed">
                                            <input type="hidden" name="redirect_mode" value="edit">
                                            <button type="submit" class="btn btn-outline">
                                                <i class="fas fa-lock"></i>
                                                <span>Close Test</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                            <h2 class="text-xl font-semibold text-ink-900">Current Snapshot</h2>
                            <div class="cbt-stat-list mt-4">
                                <div class="cbt-stat-row"><span class="text-slate-500">Question count</span><strong class="text-ink-900"><?php echo $current_question_count; ?></strong></div>
                                <div class="cbt-stat-row"><span class="text-slate-500">Question bank matches</span><strong class="text-ink-900"><?php echo $bank_question_count; ?></strong></div>
                                <div class="cbt-stat-row"><span class="text-slate-500">Submissions</span><strong class="text-ink-900"><?php echo $current_test_submission_count; ?></strong></div>
                                <div class="cbt-stat-row"><span class="text-slate-500">Duration</span><strong class="text-ink-900"><?php echo (int)($test['duration_minutes'] ?? 30); ?> mins</strong></div>
                                <div class="cbt-stat-row"><span class="text-slate-500">Schedule</span><strong class="text-right text-ink-900"><?php echo htmlspecialchars($format_datetime($test['starts_at'] ?? null, 'd M Y')); ?></strong></div>
                            </div>
                        </section>

                        <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                            <h2 class="text-xl font-semibold text-ink-900">Publishing Checklist</h2>
                            <div class="cbt-note-list mt-4">
                                <p><strong>Confirm timing:</strong> Set the start and end window if the test should only run within a fixed session.</p>
                                <p><strong>Validate question flow:</strong> Make sure each item has four complete options and one clearly marked correct answer.</p>
                                <p><strong>Publish deliberately:</strong> Leave the test in draft until the question load and duration match the class level.</p>
                            </div>
                        </section>

                        <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                            <h2 class="text-xl font-semibold text-ink-900">Workflow Shortcuts</h2>
                            <div class="mt-4 grid gap-3 text-sm font-semibold">
                                <a href="questions.php" class="cbt-anchor-card"><span><i class="fas fa-database mr-3 text-teal-700"></i>Open question bank</span><i class="fas fa-arrow-right"></i></a>
                                <a href="cbt_results.php" class="cbt-anchor-card"><span><i class="fas fa-chart-column mr-3 text-teal-700"></i>Review submitted results</span><i class="fas fa-arrow-right"></i></a>
                            </div>
                        </section>
                    </div>
                </section>

                <?php if ($action === 'edit' && $test_id > 0 && $test): ?>
                    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.08fr)_minmax(0,0.92fr)]" id="test-questions">
                        <div class="cbt-panel">
                            <div class="cbt-panel-header">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Authoring</p>
                                    <h2 class="mt-2 font-display text-2xl text-ink-900">Add Question Manually</h2>
                                    <p class="mt-2 text-sm text-slate-600">Write a new question directly into this CBT without leaving the page.</p>
                                </div>
                            </div>
                            <div class="cbt-panel-body">
                                <form method="POST" class="space-y-4">
                                    <input type="hidden" name="add_question" value="1">
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Question Text</label>
                                        <textarea class="cbt-input" name="question_text" rows="4" required></textarea>
                                    </div>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div><label class="mb-2 block text-sm font-semibold text-slate-700">Option A</label><input type="text" class="cbt-input" name="option_a" required></div>
                                        <div><label class="mb-2 block text-sm font-semibold text-slate-700">Option B</label><input type="text" class="cbt-input" name="option_b" required></div>
                                        <div><label class="mb-2 block text-sm font-semibold text-slate-700">Option C</label><input type="text" class="cbt-input" name="option_c" required></div>
                                        <div><label class="mb-2 block text-sm font-semibold text-slate-700">Option D</label><input type="text" class="cbt-input" name="option_d" required></div>
                                    </div>
                                    <div class="grid gap-4 md:grid-cols-[minmax(0,220px)_1fr]">
                                        <div>
                                            <label class="mb-2 block text-sm font-semibold text-slate-700">Correct Option</label>
                                            <select class="cbt-input" name="correct_option">
                                                <option value="A">Option A</option>
                                                <option value="B">Option B</option>
                                                <option value="C">Option C</option>
                                                <option value="D">Option D</option>
                                            </select>
                                        </div>
                                        <div class="rounded-2xl border border-ink-900/8 bg-slate-50 px-4 py-4 text-sm text-slate-600">Use concise question stems and avoid ambiguous distractors. Every manual entry should be ready for immediate student delivery.</div>
                                    </div>
                                    <div class="flex flex-wrap gap-3">
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i><span>Add Question</span></button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="cbt-panel">
                            <div class="cbt-panel-header">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Import</p>
                                    <h2 class="mt-2 font-display text-2xl text-ink-900">Bring in Question Bank Items</h2>
                                    <p class="mt-2 text-sm text-slate-600">Only multiple-choice questions matching the selected class and subject are listed here.</p>
                                </div>
                                <div class="rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">Available</p>
                                    <p class="text-2xl font-semibold text-ink-900"><?php echo $bank_question_count; ?></p>
                                </div>
                            </div>
                            <div class="cbt-panel-body">
                                <?php if ($bank_question_count === 0): ?>
                                    <div class="cbt-empty-state">
                                        <i class="fas fa-folder-open text-3xl text-teal-700"></i>
                                        <p class="mt-4 text-lg font-semibold text-ink-900">No matching bank questions</p>
                                        <p class="mt-2 text-sm text-slate-500">Add MCQ items in the question bank for this class and subject, then return to import them here.</p>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" class="space-y-4">
                                        <input type="hidden" name="import_questions" value="1">
                                        <div class="cbt-selection-list">
                                            <?php foreach ($bank_questions as $bank_question): ?>
                                                <div class="cbt-selection-item">
                                                    <label>
                                                        <input type="checkbox" name="question_ids[]" value="<?php echo (int)$bank_question['id']; ?>">
                                                        <span>
                                                            <strong class="block text-sm text-ink-900"><?php echo htmlspecialchars(mb_strimwidth(strip_tags($bank_question['question_text']), 0, 170, '...')); ?></strong>
                                                            <span class="mt-2 inline-flex rounded-full bg-amber-500/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-amber-700"><?php echo htmlspecialchars($bank_question['difficulty_level']); ?></span>
                                                        </span>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="flex flex-wrap gap-3">
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i><span>Import Selected</span></button>
                                            <a href="questions.php" class="btn btn-outline"><i class="fas fa-arrow-up-right-from-square"></i><span>Open Question Bank</span></a>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                    <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Question Set</p>
                                <h2 class="mt-2 font-display text-2xl text-ink-900">Test Questions</h2>
                                <p class="mt-2 text-sm text-slate-600">Review the sequence, answer keys, and remove any item that should not appear in the assessment.</p>
                            </div>
                            <div class="rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Current Items</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $current_question_count; ?></p>
                            </div>
                        </div>

                        <?php if ($current_question_count === 0): ?>
                            <div class="cbt-empty-state mt-6">
                                <i class="fas fa-circle-question text-3xl text-teal-700"></i>
                                <p class="mt-4 text-lg font-semibold text-ink-900">No questions added yet</p>
                                <p class="mt-2 text-sm text-slate-500">Use the manual authoring form or import from the question bank to build this CBT.</p>
                            </div>
                        <?php else: ?>
                            <div class="cbt-question-stack mt-6">
                                <?php foreach ($questions as $index => $question): ?>
                                    <article class="cbt-question-card">
                                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                            <div class="max-w-3xl">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="inline-flex rounded-full bg-teal-600/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-teal-700">Question <?php echo $index + 1; ?></span>
                                                    <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Correct: <?php echo htmlspecialchars($question['correct_option']); ?></span>
                                                </div>
                                                <h3 class="mt-4 text-lg font-semibold leading-7 text-ink-900"><?php echo nl2br(htmlspecialchars($question['question_text'])); ?></h3>
                                            </div>
                                            <a href="cbt_tests.php?action=edit&id=<?php echo (int)$test_id; ?>&delete_q=<?php echo (int)$question['id']; ?>" class="btn btn-outline" onclick="return confirm('Delete question?');">
                                                <i class="fas fa-trash"></i>
                                                <span>Delete</span>
                                            </a>
                                        </div>
                                        <div class="cbt-option-grid">
                                            <?php foreach (['A', 'B', 'C', 'D'] as $option_letter): ?>
                                                <?php $option_key = 'option_' . strtolower($option_letter); $is_correct_option = strtoupper((string)$question['correct_option']) === $option_letter; ?>
                                                <div class="cbt-option-card <?php echo $is_correct_option ? 'is-correct' : ''; ?>">
                                                    <span class="cbt-option-letter"><?php echo $option_letter; ?></span>
                                                    <span class="text-sm text-slate-700"><?php echo htmlspecialchars($question[$option_key] ?? ''); ?></span>
                                                    <span class="text-xs font-semibold uppercase tracking-wide <?php echo $is_correct_option ? 'text-emerald-700' : 'text-slate-400'; ?>"><?php echo $is_correct_option ? 'Correct' : 'Option'; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                    <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift" id="test-questions">
                        <div class="cbt-empty-state">
                            <i class="fas fa-arrow-up-right-dots text-3xl text-teal-700"></i>
                            <p class="mt-4 text-lg font-semibold text-ink-900">Save the test to continue</p>
                            <p class="mt-2 text-sm text-slate-500">Once the test header is saved, you can add questions manually or import them from the question bank.</p>
                        </div>
                    </section>
                <?php endif; ?>
            <?php else: ?>
                <section class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_340px]">
                    <div class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Registry</p>
                                <h2 class="mt-2 font-display text-2xl text-ink-900">CBT Test Overview</h2>
                                <p class="mt-2 text-sm text-slate-600">Track each test by schedule, question load, submission volume, and publication status.</p>
                            </div>
                            <a href="cbt_tests.php?action=create" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i>
                                <span>New Test</span>
                            </a>
                        </div>

                        <?php if (empty($tests)): ?>
                            <div class="cbt-empty-state mt-6">
                                <i class="fas fa-laptop-file text-3xl text-teal-700"></i>
                                <p class="mt-4 text-lg font-semibold text-ink-900">No CBT tests created yet</p>
                                <p class="mt-2 text-sm text-slate-500">Create the first CBT test to start scheduling digital assessments for your assigned classes.</p>
                            </div>
                        <?php else: ?>
                            <div class="workspace-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10" id="test-registry">
                                <table class="workspace-table min-w-[1080px] w-full bg-white text-sm">
                                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                        <tr>
                                            <th class="px-4 py-4">Title</th>
                                            <th class="px-4 py-4">Class / Subject</th>
                                            <th class="px-4 py-4">Schedule</th>
                                            <th class="px-4 py-4">Duration</th>
                                            <th class="px-4 py-4">Questions</th>
                                            <th class="px-4 py-4">Submissions</th>
                                            <th class="px-4 py-4">Status</th>
                                            <th class="px-4 py-4">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tests as $listed_test): ?>
                                            <?php $listed_status = strtolower((string)($listed_test['status'] ?? 'draft')); ?>
                                            <tr class="border-t border-slate-100 align-top">
                                                <td class="px-4 py-4">
                                                    <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($listed_test['title']); ?></p>
                                                    <p class="mt-1 text-xs text-slate-500">Created <?php echo htmlspecialchars($format_datetime($listed_test['created_at'] ?? null, 'd M Y')); ?></p>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($listed_test['class_name']); ?></p>
                                                    <p class="mt-1 text-slate-500"><?php echo htmlspecialchars($listed_test['subject_name']); ?></p>
                                                </td>
                                                <td class="px-4 py-4 text-slate-600">
                                                    <?php if (!empty($listed_test['starts_at'])): ?>
                                                        <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($format_datetime($listed_test['starts_at'])); ?></p>
                                                        <p class="mt-1 text-xs text-slate-500">Ends <?php echo htmlspecialchars($format_datetime($listed_test['ends_at'] ?? null)); ?></p>
                                                    <?php else: ?>
                                                        <span class="text-slate-400">No fixed schedule</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-4 font-semibold text-ink-900"><?php echo (int)$listed_test['duration_minutes']; ?> mins</td>
                                                <td class="px-4 py-4"><span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700"><?php echo (int)$listed_test['question_count']; ?> items</span></td>
                                                <td class="px-4 py-4"><span class="inline-flex rounded-full bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-700"><?php echo (int)$listed_test['submitted_count']; ?> submitted</span></td>
                                                <td class="px-4 py-4">
                                                    <span class="cbt-status-badge <?php echo htmlspecialchars($get_status_badge_class($listed_status)); ?>">
                                                        <i class="fas fa-circle text-[0.55rem]"></i>
                                                        <span><?php echo htmlspecialchars($status_labels[$listed_status] ?? ucfirst($listed_status)); ?></span>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div class="cbt-table-actions min-w-[210px]">
                                                        <a href="cbt_tests.php?action=edit&id=<?php echo (int)$listed_test['id']; ?>" class="btn btn-outline !px-3 !py-2"><i class="fas fa-pen"></i><span>Edit</span></a>
                                                        <?php if ($listed_status !== 'published'): ?>
                                                            <form method="POST" class="cbt-inline-form">
                                                                <input type="hidden" name="set_test_status" value="1">
                                                                <input type="hidden" name="target_test_id" value="<?php echo (int)$listed_test['id']; ?>">
                                                                <input type="hidden" name="target_status" value="published">
                                                                <input type="hidden" name="redirect_mode" value="list">
                                                                <button type="submit" class="btn btn-primary !px-3 !py-2" <?php echo (int)$listed_test['question_count'] <= 0 ? 'disabled title="Add questions before publishing"' : ''; ?>>
                                                                    <i class="fas fa-bullhorn"></i>
                                                                    <span>Publish</span>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="POST" class="cbt-inline-form">
                                                                <input type="hidden" name="set_test_status" value="1">
                                                                <input type="hidden" name="target_test_id" value="<?php echo (int)$listed_test['id']; ?>">
                                                                <input type="hidden" name="target_status" value="draft">
                                                                <input type="hidden" name="redirect_mode" value="list">
                                                                <button type="submit" class="btn btn-outline !px-3 !py-2"><i class="fas fa-eye-slash"></i><span>Draft</span></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to review the full CBT registry on small screens.</span></p>
                        <?php endif; ?>
                    </div>

                    <div class="space-y-6">
                        <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                            <h2 class="text-xl font-semibold text-ink-900">Status Distribution</h2>
                            <div class="cbt-stat-list mt-4">
                                <div class="cbt-stat-row"><span class="text-slate-500">Published tests</span><strong class="text-ink-900"><?php echo $published_tests; ?></strong></div>
                                <div class="cbt-stat-row"><span class="text-slate-500">Draft tests</span><strong class="text-ink-900"><?php echo $draft_tests; ?></strong></div>
                                <div class="cbt-stat-row"><span class="text-slate-500">Closed tests</span><strong class="text-ink-900"><?php echo $closed_tests; ?></strong></div>
                                <div class="cbt-stat-row"><span class="text-slate-500">Scheduled tests</span><strong class="text-ink-900"><?php echo $scheduled_tests; ?></strong></div>
                            </div>
                        </section>

                        <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                            <h2 class="text-xl font-semibold text-ink-900">Publishing Guidance</h2>
                            <div class="cbt-note-list mt-4">
                                <p><strong>Draft first:</strong> Keep each new CBT in draft until the class, subject, and question count are final.</p>
                                <p><strong>Use the bank deliberately:</strong> Import only questions with clear distractors and proven answer keys.</p>
                                <p><strong>Monitor uptake:</strong> Use the results page to check completion volume after a test goes live.</p>
                            </div>
                        </section>
                    </div>
                </section>
            <?php endif; ?>
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
            formSelector: 'form[method=\"POST\"], form[method=\"post\"]',
            statusElementId: 'cbt-offline-status',
            statusPrefix: 'Teacher CBT Sync:',
            swPath: '../cbt-sw.js'
        });
    </script>
</body>
</html>
