<?php
$status_labels = [
    'draft' => 'Draft',
    'reviewed' => 'Reviewed',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];
$type_labels = [
    'mcq' => 'Multiple Choice',
    'true_false' => 'True/False',
    'short_answer' => 'Short Answer',
    'essay' => 'Essay',
    'fill_blank' => 'Fill in the Blank',
];
$difficulty_labels = [
    'easy' => 'Easy',
    'medium' => 'Medium',
    'hard' => 'Hard',
    'very_hard' => 'Very Hard',
];
$filtered_question_count = count($questions);
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

    for ($page = $start; $page <= $end; $page += 1) {
        $pages[] = $page;
    }

    if ($end < $total_pages - 1) {
        $pages[] = '...';
    }

    if ($total_pages > 1) {
        $pages[] = $total_pages;
    }

    return $pages;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Bank | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [data-sidebar] { overflow: hidden; }
        .sidebar-scroll-shell { height: 100%; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-y: contain; touch-action: pan-y; padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
        .preview-modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, 0.6); z-index: 60; }
        .preview-modal.is-open { display: flex; }
        .preview-pane { width: min(860px, 100%); max-height: 90vh; overflow: auto; }

        .question-page {
            overflow-x: hidden;
        }

        .question-page .container,
        .question-page main,
        .question-page section,
        .question-page article,
        .question-page form,
        .question-page label,
        .question-page div {
            min-width: 0;
        }

        .question-page .container {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .question-page .nav-wrap {
            align-items: center;
            flex-wrap: nowrap;
            gap: 0.85rem;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }

        .question-page .nav-wrap > :first-child {
            min-width: 0;
            flex: 1 1 auto;
        }

        .subject-header-actions {
            width: auto;
            display: flex;
            align-items: center;
            flex: 0 0 auto;
            flex-wrap: nowrap;
            gap: 0.75rem;
        }

        .subject-header-actions .btn {
            width: auto;
            justify-content: center;
            padding: 0.5rem 0.85rem;
            font-size: 0.8125rem;
        }

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
        .subject-hero p {
            color: #fff;
        }

        .subject-hero h1 {
            font-size: 1.95rem;
            line-height: 1.15;
        }

        .subject-hero-actions {
            grid-template-columns: 1fr;
        }

        .question-page .subject-hero-actions > a,
        .question-page .subject-header-actions > a {
            min-width: 0;
        }

        .subject-metrics-grid {
            grid-template-columns: 1fr;
        }

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

        .question-library-table-wrapper {
            overflow-x: auto;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .summary-table-wrapper {
            overflow-x: auto;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .question-library-table thead th,
        .summary-table-modern thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
        }

        .table-scroll-hint {
            display: none;
        }

        .pagination-nav {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .pagination-link,
        .pagination-ellipsis {
            display: inline-flex;
            min-width: 2.5rem;
            height: 2.5rem;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 700;
        }

        .pagination-link {
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: #fff;
            color: #334155;
            transition: all 0.2s ease;
        }

        .pagination-link:hover {
            border-color: rgba(13, 148, 136, 0.35);
            color: #0f766e;
            background: rgba(240, 253, 250, 0.9);
        }

        .pagination-link.is-active {
            border-color: #0f766e;
            background: #0f766e;
            color: #fff;
            box-shadow: 0 10px 24px rgba(15, 118, 110, 0.18);
        }

        .pagination-ellipsis {
            color: #94a3b8;
        }

        @media (max-width: 767px) {
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            main > section,
            #question-editor > div,
            #question-editor > div + div > section,
            #question-library,
            .preview-pane {
                border-radius: 1.25rem !important;
            }

            main > section {
                padding: 1rem !important;
            }

            #questionFormBody {
                padding: 1rem !important;
            }

            #question-editor .btn,
            #question-library .btn {
                justify-content: center;
            }

            .filter-form-actions,
            .question-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-form-actions > *,
            .question-toolbar > *,
            .workflow-shortcuts > * {
                width: 100%;
                justify-content: center;
            }

            #question-editor form > .flex.flex-wrap.gap-3,
            #question-library .flex.flex-wrap.items-center.gap-3,
            #question-library .flex.flex-wrap.gap-3,
            .mt-5.flex.flex-wrap.items-center.justify-between.gap-4 {
                flex-direction: column;
                align-items: stretch;
            }

            #question-editor form > .flex.flex-wrap.gap-3 .btn,
            #question-library .flex.flex-wrap.items-center.gap-3 .btn,
            #question-library .flex.flex-wrap.gap-3 .btn,
            .mt-5.flex.flex-wrap.items-center.justify-between.gap-4 .btn {
                width: 100%;
            }

            .question-library-table-wrapper {
                overflow: auto;
                max-height: min(65vh, 30rem);
                border-radius: 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: #fff;
            }

            .summary-table-wrapper {
                overflow: auto;
                max-height: min(58vh, 24rem);
                border-radius: 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: #fff;
            }

            .preview-pane {
                padding: 1rem;
            }

            .question-library-table {
                min-width: 1180px !important;
                border-collapse: separate;
                border-spacing: 0;
            }

            .summary-table-modern {
                min-width: 680px !important;
                border-collapse: separate;
                border-spacing: 0;
            }

            .table-scroll-hint {
                display: flex;
                align-items: center;
                gap: 0.45rem;
                margin-top: 0.75rem;
                font-size: 0.75rem;
                font-weight: 700;
                color: #64748b;
            }

            .pagination-nav {
                width: 100%;
                justify-content: flex-start;
            }

            .option-item {
                grid-template-columns: 1fr;
            }

            .option-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .option-actions button {
                width: 100%;
            }

            .mt-5.flex.flex-wrap.items-center.justify-between.gap-4 > div:first-child,
            .mt-5.flex.flex-wrap.items-center.justify-between.gap-4 > div:last-child {
                width: 100%;
            }

            .mt-5.flex.flex-wrap.items-center.justify-between.gap-4 > div:last-child {
                gap: 0.75rem;
            }
        }

        @media (max-width: 640px) {
            .preview-modal {
                padding: 0.75rem;
            }

            .rounded-3xl,
            .rounded-2xl {
                border-radius: 1rem !important;
            }

            h1.text-3xl,
            h2.text-2xl {
                line-height: 1.2;
            }

            .question-toolbar > * {
                font-size: 0.85rem;
            }
        }

        @media (min-width: 640px) {
            .question-page .container {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
            }

            .subject-hero {
                padding: 1.5rem 2rem;
            }

            .subject-hero-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .subject-metrics-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 768px) {
            .subject-hero h1 {
                font-size: 2.4rem;
            }
        }

        @media (min-width: 1024px) {
            .question-page .nav-wrap {
                gap: 1rem;
                padding-top: 1rem;
                padding-bottom: 1rem;
            }

            .subject-header-actions {
                gap: 0.75rem;
            }

            .subject-header-actions .btn {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }

            .subject-hero {
                padding: 1.75rem 2rem;
            }
        }

        @media (min-width: 1200px) {
            .question-page .container {
                padding-left: 2.75rem;
                padding-right: 2.75rem;
            }
        }

        @media (min-width: 1280px) {
            .subject-metrics-grid {
                grid-template-columns: repeat(5, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body class="landing bg-slate-50 question-page">
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
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Assessment Workspace</p>
                            <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">Question management with clearer structure, stronger presentation, and cleaner workflows.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">Create, refine, and organize assessment items using the same header treatment as subject management, with quick access to authoring, review, and paper-building workflows.</p>
                        </div>
                        <div class="subject-hero-actions grid gap-3 sm:grid-cols-2">
                            <a href="#question-editor" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                <i class="fas fa-plus-circle"></i>
                                <span><?php echo $edit_mode ? 'Continue Editing' : 'Create Question'; ?></span>
                            </a>
                            <a href="generate_paper.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-file-lines"></i>
                                <span>Build Exam Paper</span>
                            </a>
                            <a href="#question-library" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-table-list"></i>
                                <span>Review Library</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="subject-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-teal-600/10 text-teal-700">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Filtered View</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $filtered_question_count; ?></h2>
                        <p class="text-sm text-slate-500">Questions in the current result set</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-sky-600/10 text-sky-700">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Questions</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_questions; ?></h2>
                        <p class="text-sm text-slate-500">School-wide question bank</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-amber-500/10 text-amber-700">
                            <i class="fas fa-list-check"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">MCQ Library</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $mcq_count; ?></h2>
                        <p class="text-sm text-slate-500">Objective questions available</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-emerald-600/10 text-emerald-700">
                            <i class="fas fa-shield-check"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Approved</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $approved_count; ?></h2>
                        <p class="text-sm text-slate-500">Ready for assessment use</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-violet-600/10 text-violet-700">
                            <i class="fas fa-user-pen"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">My Questions</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $my_questions; ?></h2>
                        <p class="text-sm text-slate-500">Items you created in this school</p>
                    </article>
                </div>
            </section>

            <?php if ($errors): ?>
                <section class="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-800 shadow-soft">
                    <h2 class="text-lg font-semibold">Review the highlighted issues</h2>
                    <ul class="mt-2 space-y-1 text-sm"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul>
                </section>
            <?php endif; ?>
            <?php if ($success): ?>
                <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900 shadow-soft"><p class="font-semibold">Update completed</p><p class="mt-1 text-sm"><?php echo htmlspecialchars($success); ?></p></section>
            <?php endif; ?>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]" id="question-editor">
                <div class="rounded-3xl bg-white shadow-lift border border-ink-900/5 overflow-hidden">
                    <div class="flex flex-col gap-4 border-b border-ink-900/5 p-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Question Editor</p>
                            <h2 class="mt-2 text-2xl font-display text-ink-900"><?php echo $edit_mode ? 'Refine Question Details' : 'Add a New Question'; ?></h2>
                        </div>
                        <button type="button" class="btn btn-outline" id="toggleQuestionForm" aria-expanded="true" aria-controls="questionFormBody"><i class="fas fa-eye-slash"></i><span>Hide Form</span></button>
                    </div>
                    <div id="questionFormBody" class="p-6 space-y-5">
                        <form id="questionForm" method="POST" class="space-y-5">
                            <input type="hidden" name="action" value="<?php echo $edit_mode ? 'update_question' : 'create_question'; ?>">
                            <?php if ($edit_mode): ?><input type="hidden" name="question_id" value="<?php echo (int)$edit_question['id']; ?>"><?php endif; ?>

                            <div class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4 space-y-4">
                                <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Structure and Classification</h3>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Question Type</label><select name="question_type" id="questionType" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required onchange="toggleQuestionOptions()"><option value="">Select type</option><option value="mcq" <?php echo ($edit_question['question_type'] ?? '') === 'mcq' ? 'selected' : ''; ?>>Multiple Choice (MCQ)</option><option value="true_false" <?php echo ($edit_question['question_type'] ?? '') === 'true_false' ? 'selected' : ''; ?>>True/False</option><option value="short_answer" <?php echo ($edit_question['question_type'] ?? '') === 'short_answer' ? 'selected' : ''; ?>>Short Answer</option><option value="essay" <?php echo ($edit_question['question_type'] ?? '') === 'essay' ? 'selected' : ''; ?>>Essay</option><option value="fill_blank" <?php echo ($edit_question['question_type'] ?? '') === 'fill_blank' ? 'selected' : ''; ?>>Fill in the Blank</option></select></div>
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Subject</label><select name="subject_id" id="subjectId" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required><option value="">Select subject</option><?php foreach ($subjects as $subject): ?><option value="<?php echo (int)$subject['id']; ?>" <?php echo (string)($edit_question['subject_id'] ?? '') === (string)$subject['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($subject['subject_name']); ?></option><?php endforeach; ?></select></div>
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Class</label><select name="class_id" id="classId" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required><option value="">Select class</option><?php foreach ($classes as $class): ?><option value="<?php echo (int)$class['id']; ?>" <?php echo (string)($edit_question['class_id'] ?? '') === (string)$class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option><?php endforeach; ?></select></div>
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Difficulty</label><select name="difficulty_level" id="difficultyLevel" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required><option value="">Select level</option><option value="easy" <?php echo ($edit_question['difficulty_level'] ?? '') === 'easy' ? 'selected' : ''; ?>>Easy</option><option value="medium" <?php echo ($edit_question['difficulty_level'] ?? '') === 'medium' ? 'selected' : ''; ?>>Medium</option><option value="hard" <?php echo ($edit_question['difficulty_level'] ?? '') === 'hard' ? 'selected' : ''; ?>>Hard</option><option value="very_hard" <?php echo ($edit_question['difficulty_level'] ?? '') === 'very_hard' ? 'selected' : ''; ?>>Very Hard</option></select></div>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Marks</label><input type="number" name="marks" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" min="0.5" max="100" step="0.5" required value="<?php echo htmlspecialchars((string)($edit_question['marks'] ?? '1.00')); ?>"></div>
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Cognitive Level</label><select name="cognitive_level" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700"><option value="">Select level</option><option value="knowledge" <?php echo ($edit_question['cognitive_level'] ?? '') === 'knowledge' ? 'selected' : ''; ?>>Knowledge</option><option value="comprehension" <?php echo ($edit_question['cognitive_level'] ?? '') === 'comprehension' ? 'selected' : ''; ?>>Comprehension</option><option value="application" <?php echo ($edit_question['cognitive_level'] ?? '') === 'application' ? 'selected' : ''; ?>>Application</option><option value="analysis" <?php echo ($edit_question['cognitive_level'] ?? '') === 'analysis' ? 'selected' : ''; ?>>Analysis</option><option value="synthesis" <?php echo ($edit_question['cognitive_level'] ?? '') === 'synthesis' ? 'selected' : ''; ?>>Synthesis</option><option value="evaluation" <?php echo ($edit_question['cognitive_level'] ?? '') === 'evaluation' ? 'selected' : ''; ?>>Evaluation</option></select></div>
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Category</label><select name="category_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700"><option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>" <?php echo (string)($edit_question['category_id'] ?? '') === (string)$category['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['category_name']); ?></option><?php endforeach; ?></select></div>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Topic</label><input type="text" name="topic" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" value="<?php echo htmlspecialchars($edit_question['topic'] ?? ''); ?>"></div>
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Sub-topic</label><input type="text" name="sub_topic" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" value="<?php echo htmlspecialchars($edit_question['sub_topic'] ?? ''); ?>"></div>
                                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Tags</label><input type="text" name="tags" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" value="<?php echo htmlspecialchars($edit_question['tags'] ?? ''); ?>" placeholder="Revision, WAEC, practice"></div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4 space-y-4">
                                <div class="question-toolbar flex flex-wrap gap-2 text-sm font-semibold text-slate-600">
                                    <button type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 hover:border-teal-300 hover:text-teal-700" onclick="formatText('bold')"><i class="fas fa-bold mr-2"></i>Bold</button>
                                    <button type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 hover:border-teal-300 hover:text-teal-700" onclick="formatText('italic')"><i class="fas fa-italic mr-2"></i>Italic</button>
                                    <button type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 hover:border-teal-300 hover:text-teal-700" onclick="formatText('underline')"><i class="fas fa-underline mr-2"></i>Underline</button>
                                    <button type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 hover:border-teal-300 hover:text-teal-700" onclick="insertMath()"><i class="fas fa-square-root-variable mr-2"></i>Math</button>
                                    <button type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 hover:border-teal-300 hover:text-teal-700" onclick="insertImage()"><i class="fas fa-image mr-2"></i>Image</button>
                                    <button type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 hover:border-teal-300 hover:text-teal-700" onclick="insertTable()"><i class="fas fa-table-cells mr-2"></i>Table</button>
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-700">Question Text</label>
                                    <textarea name="question_text" id="questionText" class="min-h-[12rem] w-full rounded-3xl border border-slate-200 bg-white px-4 py-4 text-sm text-slate-700" required><?php echo htmlspecialchars($edit_question['question_text'] ?? ''); ?></textarea>
                                    <p class="mt-3 text-sm text-slate-500">HTML formatting is supported for tables, emphasis, and embedded instructional media.</p>
                                </div>
                            </div>

                            <div id="mcqOptions" class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4 space-y-4" style="display:none;">
                                <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Answer Options</h3><button type="button" class="btn btn-outline" onclick="addOption()"><i class="fas fa-plus"></i><span>Add Option</span></button></div>
                                <div id="optionsContainer" class="space-y-3">
                                    <?php if ($edit_mode && ($edit_question['question_type'] ?? '') === 'mcq' && !empty($edit_question['options'])): ?>
                                        <?php foreach ($edit_question['options'] as $option): ?>
                                            <div class="grid gap-3 rounded-2xl border <?php echo !empty($option['is_correct']) ? 'border-emerald-300 bg-emerald-50/70' : 'border-slate-200 bg-white'; ?> p-4 md:grid-cols-[auto_1fr_auto] md:items-center option-item">
                                                <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-teal-600/10 font-semibold text-teal-700 option-letter"><?php echo htmlspecialchars($option['option_letter']); ?></span>
                                                <input type="text" name="options[]" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" value="<?php echo htmlspecialchars($option['option_text']); ?>">
                                                <div class="flex items-center gap-3 option-actions"><label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600"><input type="radio" name="correct_option" value="<?php echo htmlspecialchars($option['option_letter']); ?>" <?php echo !empty($option['is_correct']) ? 'checked' : ''; ?> onchange="markCorrectOption(this)">Correct</label><button type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600" onclick="removeOption(this)"><i class="fas fa-trash"></i></button></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-3">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><span><?php echo $edit_mode ? 'Update Question' : 'Save Question'; ?></span></button>
                                <button type="button" class="btn btn-outline" onclick="previewQuestion()"><i class="fas fa-eye"></i><span>Preview</span></button>
                                <button type="reset" class="btn btn-outline"><i class="fas fa-rotate-right"></i><span>Reset</span></button>
                                <?php if ($edit_mode): ?><a class="btn btn-outline" href="questions.php"><i class="fas fa-xmark"></i><span>Cancel Edit</span></a><?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Workflow Shortcuts</h2>
                        <div class="workflow-shortcuts mt-4 grid gap-3 text-sm font-semibold">
                            <a href="generate_paper.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700"><span><i class="fas fa-file-export mr-3 text-teal-700"></i>Generate exam paper</span><i class="fas fa-arrow-right"></i></a>
                            <button type="button" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-left text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700" onclick="exportQuestions()"><span><i class="fas fa-file-csv mr-3 text-teal-700"></i>Export filtered library</span><i class="fas fa-download"></i></button>
                            <button type="button" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-left text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700" onclick="printTable()"><span><i class="fas fa-print mr-3 text-teal-700"></i>Print summary table</span><i class="fas fa-arrow-right"></i></button>
                        </div>
                    </section>
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Assessment Standards</h2>
                        <div class="mt-4 space-y-4 text-sm text-slate-600">
                            <p><span class="font-semibold text-ink-900">Balance the paper:</span> Mix recall, application, and higher-order prompts instead of clustering one difficulty band.</p>
                            <p><span class="font-semibold text-ink-900">Align to class level:</span> Keep wording age-appropriate and verify every prompt matches the selected class and subject.</p>
                            <p><span class="font-semibold text-ink-900">Review answer keys:</span> For MCQs, make sure one definitive correct option is marked before export.</p>
                        </div>
                    </section>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Discovery</p>
                        <h2 class="mt-2 text-2xl font-display text-ink-900">Search and Filter</h2>
                        <p class="mt-2 text-sm text-slate-600">Narrow the bank by curriculum area, class, question style, and approval status.</p>
                    </div>
                    <a class="btn btn-outline" href="questions.php"><i class="fas fa-filter-circle-xmark"></i><span>Clear Filters</span></a>
                </div>
                <form method="GET" action="questions.php" class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Subject</label><select name="subject_filter" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700"><option value="">All subjects</option><?php foreach ($subjects as $subject): ?><option value="<?php echo (int)$subject['id']; ?>" <?php echo (string)$subject_filter === (string)$subject['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($subject['subject_name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Class</label><select name="class_filter" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700"><option value="">All classes</option><?php foreach ($classes as $class): ?><option value="<?php echo (int)$class['id']; ?>" <?php echo (string)$class_filter === (string)$class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Difficulty</label><select name="difficulty_filter" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700"><option value="">All levels</option><option value="easy" <?php echo $difficulty_filter === 'easy' ? 'selected' : ''; ?>>Easy</option><option value="medium" <?php echo $difficulty_filter === 'medium' ? 'selected' : ''; ?>>Medium</option><option value="hard" <?php echo $difficulty_filter === 'hard' ? 'selected' : ''; ?>>Hard</option><option value="very_hard" <?php echo $difficulty_filter === 'very_hard' ? 'selected' : ''; ?>>Very Hard</option></select></div>
                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Question Type</label><select name="type_filter" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700"><option value="">All types</option><option value="mcq" <?php echo $type_filter === 'mcq' ? 'selected' : ''; ?>>MCQ</option><option value="true_false" <?php echo $type_filter === 'true_false' ? 'selected' : ''; ?>>True/False</option><option value="short_answer" <?php echo $type_filter === 'short_answer' ? 'selected' : ''; ?>>Short Answer</option><option value="essay" <?php echo $type_filter === 'essay' ? 'selected' : ''; ?>>Essay</option><option value="fill_blank" <?php echo $type_filter === 'fill_blank' ? 'selected' : ''; ?>>Fill in the Blank</option></select></div>
                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Status</label><select name="status_filter" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700"><option value="">All statuses</option><?php foreach ($status_labels as $status_key => $status_text): ?><option value="<?php echo $status_key; ?>" <?php echo $status_filter === $status_key ? 'selected' : ''; ?>><?php echo $status_text; ?></option><?php endforeach; ?></select></div>
                    <div><label class="mb-2 block text-sm font-semibold text-slate-700">Search Text</label><input type="text" name="search_text" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" value="<?php echo htmlspecialchars($_GET['search_text'] ?? ''); ?>" placeholder="Search keywords"></div>
                    <div class="filter-form-actions md:col-span-2 xl:col-span-6 flex flex-wrap gap-3"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i><span>Apply Filters</span></button><a href="questions.php" class="btn btn-outline"><i class="fas fa-rotate-right"></i><span>Reset View</span></a></div>
                </form>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5" id="question-library">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Question Library</p>
                        <h2 class="mt-2 text-2xl font-display text-ink-900">Filtered Question Bank</h2>
                        <p class="mt-2 text-sm text-slate-600">Review, edit, approve, and preview the most recent items that match your filters.</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Filtered Questions</p><p class="text-2xl font-semibold text-ink-900"><?php echo $total_filtered_questions; ?></p></div>
                </div>
                <?php if (empty($questions)): ?>
                    <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500"><i class="fas fa-folder-open text-3xl text-teal-700"></i><p class="mt-4 text-lg font-semibold text-ink-900">No questions match this view</p><p class="mt-2 text-sm">Adjust the filters above or create a new item.</p></div>
                <?php else: ?>
                    <div class="question-library-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10">
                        <table class="question-library-table min-w-[1180px] w-full bg-white text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-4 py-4">Code</th>
                                    <th class="px-4 py-4">Question</th>
                                    <th class="px-4 py-4">Subject / Class</th>
                                    <th class="px-4 py-4">Type</th>
                                    <th class="px-4 py-4">Difficulty</th>
                                    <th class="px-4 py-4">Status</th>
                                    <th class="px-4 py-4">Marks</th>
                                    <th class="px-4 py-4">Correct</th>
                                    <th class="px-4 py-4">Created</th>
                                    <th class="px-4 py-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($questions as $question): ?>
                                    <?php $question_status = strtolower($question['status'] ?? 'draft'); $question_type = strtolower($question['question_type'] ?? 'mcq'); $question_difficulty = strtolower($question['difficulty_level'] ?? 'medium'); ?>
                                    <tr class="border-t border-slate-100 align-top" data-question-id="<?php echo (int)$question['id']; ?>" data-question-code="<?php echo htmlspecialchars($question['question_code']); ?>" data-question-type="<?php echo htmlspecialchars($type_labels[$question_type] ?? ucfirst(str_replace('_', ' ', $question_type))); ?>" data-question-status="<?php echo htmlspecialchars($status_labels[$question_status] ?? ucfirst($question_status)); ?>" data-question-marks="<?php echo htmlspecialchars((string)$question['marks']); ?>" data-question-subject="<?php echo htmlspecialchars($question['subject_name'] ?? ''); ?>" data-question-class="<?php echo htmlspecialchars($question['class_name'] ?? ''); ?>">
                                        <td class="px-4 py-4 font-semibold text-ink-900" data-label="Code"><?php echo htmlspecialchars($question['question_code']); ?></td>
                                        <td class="px-4 py-4" data-label="Question">
                                            <div class="max-w-md">
                                                <p class="font-semibold leading-6 text-ink-900"><?php echo htmlspecialchars(mb_strimwidth(strip_tags($question['question_text']), 0, 140, '...')); ?></p>
                                                <?php if (!empty($question['tags'])): ?>
                                                    <div class="mt-2 flex flex-wrap gap-2">
                                                        <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $question['tags']))), 0, 3) as $tag): ?>
                                                            <span class="rounded-full bg-sky-50 px-2 py-1 text-[11px] font-semibold text-sky-700 border border-sky-100">#<?php echo htmlspecialchars($tag); ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4" data-label="Subject / Class">
                                            <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($question['subject_name'] ?? 'Subject'); ?></p>
                                            <p class="mt-1 text-slate-500"><?php echo htmlspecialchars($question['class_name'] ?? 'Class'); ?></p>
                                        </td>
                                        <td class="px-4 py-4" data-label="Type"><span class="rounded-full bg-teal-600/10 px-3 py-1 text-xs font-semibold text-teal-700"><?php echo htmlspecialchars($type_labels[$question_type] ?? ucfirst(str_replace('_', ' ', $question_type))); ?></span></td>
                                        <td class="px-4 py-4" data-label="Difficulty"><span class="rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-700"><?php echo htmlspecialchars($difficulty_labels[$question_difficulty] ?? ucfirst(str_replace('_', ' ', $question_difficulty))); ?></span></td>
                                        <td class="px-4 py-4" data-label="Status"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"><?php echo htmlspecialchars($status_labels[$question_status] ?? ucfirst($question_status)); ?></span></td>
                                        <td class="px-4 py-4 font-semibold text-ink-900" data-label="Marks"><?php echo htmlspecialchars((string)$question['marks']); ?></td>
                                        <td class="px-4 py-4 text-slate-700" data-label="Correct"><?php echo !empty($question['correct_answer']) ? htmlspecialchars($question['correct_answer']) : 'Not set'; ?></td>
                                        <td class="px-4 py-4 text-slate-500" data-label="Created"><?php echo !empty($question['created_at']) ? htmlspecialchars(date('d M Y', strtotime($question['created_at']))) : 'N/A'; ?></td>
                                        <td class="px-4 py-4" data-label="Actions">
                                            <div class="flex flex-col gap-2 min-w-[180px]">
                                                <div class="flex flex-wrap gap-2">
                                                    <a class="btn btn-outline !px-3 !py-2" href="question_preview.php?id=<?php echo (int)$question['id']; ?>"><i class="fas fa-eye"></i><span>Preview</span></a>
                                                    <a class="btn btn-outline !px-3 !py-2" href="questions.php?edit=<?php echo (int)$question['id']; ?>#question-editor"><i class="fas fa-pen"></i><span>Edit</span></a>
                                                </div>
                                                <form method="POST" class="flex flex-col gap-2">
                                                    <input type="hidden" name="action" value="change_status">
                                                    <input type="hidden" name="question_id" value="<?php echo (int)$question['id']; ?>">
                                                    <select name="status" class="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700">
                                                        <?php foreach ($status_labels as $status_key => $status_text): ?>
                                                            <option value="<?php echo $status_key; ?>" <?php echo $question_status === $status_key ? 'selected' : ''; ?>><?php echo $status_text; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn btn-primary !px-3 !py-2"><i class="fas fa-shield-check"></i><span>Save</span></button>
                                                </form>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="delete_question">
                                                    <input type="hidden" name="question_id" value="<?php echo (int)$question['id']; ?>">
                                                    <button type="submit" class="btn btn-outline !px-3 !py-2 w-full" onclick="return confirm('Delete this question from the bank?');"><i class="fas fa-trash"></i><span>Delete</span></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to review the full table on small screens.</span></p>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-4 text-sm text-slate-500">
                        <div class="flex flex-wrap gap-4">
                            <span><i class="fas fa-database mr-2 text-teal-700"></i>Total filtered: <?php echo $total_filtered_questions; ?></span>
                            <span><i class="fas fa-list mr-2 text-teal-700"></i>Showing <?php echo count($questions); ?> on this page</span>
                        </div>
                        <div class="pagination-nav">
                            <?php
                            $question_pagination_items = $build_pagination_window($question_page, $question_total_pages);
                            $question_prev_params = $_GET;
                            $question_prev_params['question_page'] = max(1, $question_page - 1);
                            $question_prev_url = 'questions.php?' . http_build_query($question_prev_params);
                            $question_next_params = $_GET;
                            $question_next_params['question_page'] = min($question_total_pages, $question_page + 1);
                            $question_next_url = 'questions.php?' . http_build_query($question_next_params);
                            ?>
                            <?php if ($question_page > 1): ?><a href="<?php echo htmlspecialchars($question_prev_url); ?>" class="btn btn-outline"><i class="fas fa-chevron-left"></i><span>Previous</span></a><?php endif; ?>
                            <?php foreach ($question_pagination_items as $question_pagination_item): ?>
                                <?php if ($question_pagination_item === '...'): ?>
                                    <span class="pagination-ellipsis" aria-hidden="true">...</span>
                                <?php else: ?>
                                    <?php $question_page_params = $_GET; $question_page_params['question_page'] = $question_pagination_item; $question_page_url = 'questions.php?' . http_build_query($question_page_params); ?>
                                    <a href="<?php echo htmlspecialchars($question_page_url); ?>" class="pagination-link <?php echo $question_page === $question_pagination_item ? 'is-active' : ''; ?>"<?php echo $question_page === $question_pagination_item ? ' aria-current="page"' : ''; ?>><?php echo $question_pagination_item; ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <span class="font-semibold text-slate-600">Page <?php echo $question_page; ?> of <?php echo $question_total_pages; ?></span>
                            <?php if ($question_page < $question_total_pages): ?><a href="<?php echo htmlspecialchars($question_next_url); ?>" class="btn btn-outline"><span>Next</span><i class="fas fa-chevron-right"></i></a><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Reporting</p>
                        <h2 class="mt-2 text-2xl font-display text-ink-900">Question Bank Summary</h2>
                        <p class="mt-2 text-sm text-slate-600">Monitor question distribution across subjects, classes, and item types.</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Summary Entries</p><p class="text-2xl font-semibold text-ink-900"><?php echo $total_summary_count; ?></p></div>
                </div>
                <?php if (empty($question_summary)): ?>
                    <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500"><p class="text-lg font-semibold text-ink-900">Summary will appear here</p><p class="mt-2 text-sm">Once questions are added, the distribution insights will show here.</p></div>
                <?php else: ?>
                    <div class="summary-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10"><table class="summary-table-modern min-w-[680px] w-full bg-white"><thead><tr class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500"><th class="px-4 py-4">Subject</th><th class="px-4 py-4">Question Type</th><th class="px-4 py-4">Total Questions</th><th class="px-4 py-4">Class</th></tr></thead><tbody><?php foreach ($question_summary as $summary): ?><?php $summary_type = strtolower($summary['question_type'] ?? 'mcq'); ?><tr class="border-t border-slate-100"><td class="px-4 py-4 font-semibold text-ink-900" data-label="Subject"><?php echo htmlspecialchars($summary['subject_name']); ?></td><td class="px-4 py-4" data-label="Question Type"><span class="rounded-full bg-teal-600/10 px-3 py-1 text-xs font-semibold text-teal-700"><?php echo htmlspecialchars($type_labels[$summary_type] ?? ucfirst(str_replace('_', ' ', $summary_type))); ?></span></td><td class="px-4 py-4" data-label="Total Questions"><span class="rounded-full bg-sky-50 px-3 py-1 text-sm font-semibold text-sky-700"><?php echo (int)$summary['question_count']; ?></span></td><td class="px-4 py-4 text-slate-700" data-label="Class"><?php echo htmlspecialchars($summary['class_name']); ?></td></tr><?php endforeach; ?></tbody></table></div>
                    <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to inspect the full reporting table on mobile.</span></p>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-4 text-sm text-slate-500">
                        <div class="flex flex-wrap gap-4"><span><i class="fas fa-database mr-2 text-teal-700"></i>Total entries: <?php echo $total_summary_count; ?></span><span><i class="fas fa-clock mr-2 text-teal-700"></i>Updated: <?php echo date('d M Y, h:i A'); ?></span></div>
                        <div class="pagination-nav"><?php $summary_pagination_items = $build_pagination_window($summary_page, $summary_total_pages); $summary_prev_params = $_GET; $summary_prev_params['summary_page'] = max(1, $summary_page - 1); $summary_prev_url = 'questions.php?' . http_build_query($summary_prev_params); $summary_next_params = $_GET; $summary_next_params['summary_page'] = min($summary_total_pages, $summary_page + 1); $summary_next_url = 'questions.php?' . http_build_query($summary_next_params); ?><?php if ($summary_page > 1): ?><a href="<?php echo htmlspecialchars($summary_prev_url); ?>" class="btn btn-outline"><i class="fas fa-chevron-left"></i><span>Previous</span></a><?php endif; ?><?php foreach ($summary_pagination_items as $summary_pagination_item): ?><?php if ($summary_pagination_item === '...'): ?><span class="pagination-ellipsis" aria-hidden="true">...</span><?php else: ?><?php $summary_page_params = $_GET; $summary_page_params['summary_page'] = $summary_pagination_item; $summary_page_url = 'questions.php?' . http_build_query($summary_page_params); ?><a href="<?php echo htmlspecialchars($summary_page_url); ?>" class="pagination-link <?php echo $summary_page === $summary_pagination_item ? 'is-active' : ''; ?>"<?php echo $summary_page === $summary_pagination_item ? ' aria-current="page"' : ''; ?>><?php echo $summary_pagination_item; ?></a><?php endif; ?><?php endforeach; ?><span class="font-semibold text-slate-600">Page <?php echo $summary_page; ?> of <?php echo $summary_total_pages; ?></span><?php if ($summary_page < $summary_total_pages): ?><a href="<?php echo htmlspecialchars($summary_next_url); ?>" class="btn btn-outline"><span>Next</span><i class="fas fa-chevron-right"></i></a><?php endif; ?></div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <div id="previewModal" class="preview-modal" role="dialog" aria-modal="true" aria-labelledby="previewTitle">
        <div class="preview-pane rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
            <button type="button" class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700" onclick="closePreview()"><i class="fas fa-xmark"></i></button>
            <div id="previewContent"></div>
        </div>
    </div>

    <?php include '../includes/floating-button.php'; ?>
    <script>
        const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        const sidebar = document.querySelector('[data-sidebar]');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        const body = document.body;
        const openSidebar = () => { if (!sidebar || !overlay) return; sidebar.classList.remove('-translate-x-full'); overlay.classList.remove('opacity-0', 'pointer-events-none'); overlay.classList.add('opacity-100'); body.classList.add('nav-open'); };
        const closeSidebarShell = () => { if (!sidebar || !overlay) return; sidebar.classList.add('-translate-x-full'); overlay.classList.add('opacity-0', 'pointer-events-none'); overlay.classList.remove('opacity-100'); body.classList.remove('nav-open'); };
        if (sidebarToggle) { sidebarToggle.addEventListener('click', () => sidebar.classList.contains('-translate-x-full') ? openSidebar() : closeSidebarShell()); }
        if (overlay) { overlay.addEventListener('click', closeSidebarShell); }
        if (sidebar) { sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebarShell)); }

        function toggleQuestionOptions() {
            const questionType = document.getElementById('questionType');
            const mcqOptions = document.getElementById('mcqOptions');
            if (!questionType || !mcqOptions) return;
            if (questionType.value === 'mcq') {
                mcqOptions.style.display = 'block';
                if (document.querySelectorAll('.option-item').length === 0) { addOption(); addOption(); addOption(); addOption(); }
            } else {
                mcqOptions.style.display = 'none';
            }
        }
        let optionCounter = document.querySelectorAll('.option-item').length;
        function addOption() {
            optionCounter += 1;
            const optionLetter = String.fromCharCode(64 + optionCounter);
            const optionItem = document.createElement('div');
            optionItem.className = 'grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 md:grid-cols-[auto_1fr_auto] md:items-center option-item';
            optionItem.innerHTML = `<span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-teal-600/10 font-semibold text-teal-700 option-letter">${optionLetter}</span><input type="text" name="options[]" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="Option ${optionLetter}" required><div class="flex items-center gap-3 option-actions"><label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600"><input type="radio" name="correct_option" value="${optionLetter}" onchange="markCorrectOption(this)">Correct</label><button type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600" onclick="removeOption(this)"><i class="fas fa-trash"></i></button></div>`;
            document.getElementById('optionsContainer').appendChild(optionItem);
        }
        function removeOption(button) { const optionItem = button.closest('.option-item'); if (optionItem) { optionItem.remove(); recalculateOptionLetters(); } }
        function markCorrectOption(radio) { document.querySelectorAll('.option-item').forEach((item) => item.classList.remove('border-emerald-300', 'bg-emerald-50/70')); const optionItem = radio.closest('.option-item'); if (optionItem) optionItem.classList.add('border-emerald-300', 'bg-emerald-50/70'); }
        function recalculateOptionLetters() { const options = document.querySelectorAll('.option-item'); options.forEach((item, index) => { const newLetter = String.fromCharCode(65 + index); item.querySelector('.option-letter').textContent = newLetter; item.querySelector('input[name="options[]"]').placeholder = `Option ${newLetter}`; item.querySelector('input[type="radio"]').value = newLetter; }); optionCounter = options.length; }
        function formatText(command) { const textarea = document.getElementById('questionText'); const start = textarea.selectionStart; const end = textarea.selectionEnd; const selectedText = textarea.value.substring(start, end) || 'text'; let formattedText = selectedText; if (command === 'bold') formattedText = `<strong>${selectedText}</strong>`; if (command === 'italic') formattedText = `<em>${selectedText}</em>`; if (command === 'underline') formattedText = `<u>${selectedText}</u>`; textarea.value = textarea.value.substring(0, start) + formattedText + textarea.value.substring(end); textarea.focus(); textarea.setSelectionRange(start + formattedText.length, start + formattedText.length); }
        function insertMath() { const mathText = prompt('Enter mathematical expression (LaTeX supported):', 'x = \\\\frac{-b \\\\pm \\\\sqrt{b^2 - 4ac}}{2a}'); if (mathText) insertAtCursor(`\\\\[${mathText}\\\\]`); }
        function insertImage() { const imageUrl = prompt('Enter image URL:', 'https://example.com/image.jpg'); if (imageUrl) insertAtCursor(`<img src="${imageUrl}" alt="Question illustration" style="max-width:100%;border-radius:12px;">`); }
        function insertTable() { const rows = parseInt(prompt('Number of rows:', '3'), 10); const cols = parseInt(prompt('Number of columns:', '3'), 10); if (!rows || !cols) return; let tableHTML = '<table border="1" style="width:100%;border-collapse:collapse;">'; for (let i = 0; i < rows; i += 1) { tableHTML += '<tr>'; for (let j = 0; j < cols; j += 1) { tableHTML += `<td style="padding:8px;">Cell ${i + 1}-${j + 1}</td>`; } tableHTML += '</tr>'; } tableHTML += '</table>'; insertAtCursor(tableHTML); }
        function insertAtCursor(content) { const textarea = document.getElementById('questionText'); const start = textarea.selectionStart; textarea.value = textarea.value.substring(0, start) + content + textarea.value.substring(start); textarea.focus(); }
        function getSelectText(selector) { const select = document.querySelector(selector); return (!select || select.selectedIndex < 0) ? 'Not selected' : select.options[select.selectedIndex].text; }
        function previewQuestion() { const questionType = document.getElementById('questionType').value; const questionText = document.getElementById('questionText').value.trim(); const marks = document.querySelector('input[name="marks"]').value || '0'; let previewHTML = `<div class="space-y-5"><div><p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Live Preview</p><h2 id="previewTitle" class="mt-2 text-2xl font-display text-ink-900">Assessment Question Preview</h2></div><div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><div class="rounded-2xl bg-slate-50 p-4 border border-slate-200/70"><p class="text-xs uppercase tracking-wide text-slate-500">Type</p><p class="mt-2 font-semibold text-ink-900">${getSelectText('#questionType')}</p></div><div class="rounded-2xl bg-slate-50 p-4 border border-slate-200/70"><p class="text-xs uppercase tracking-wide text-slate-500">Subject</p><p class="mt-2 font-semibold text-ink-900">${getSelectText('#subjectId')}</p></div><div class="rounded-2xl bg-slate-50 p-4 border border-slate-200/70"><p class="text-xs uppercase tracking-wide text-slate-500">Class</p><p class="mt-2 font-semibold text-ink-900">${getSelectText('#classId')}</p></div><div class="rounded-2xl bg-slate-50 p-4 border border-slate-200/70"><p class="text-xs uppercase tracking-wide text-slate-500">Marks</p><p class="mt-2 font-semibold text-ink-900">${marks}</p></div></div><div class="rounded-3xl border border-slate-200 bg-slate-50 p-5">${questionText || '<p class="text-slate-500">Question text will appear here.</p>'}</div>`; if (questionType === 'mcq') { const options = document.querySelectorAll('input[name="options[]"]'); previewHTML += '<div class="space-y-3"><h3 class="text-lg font-semibold text-ink-900">Options</h3>'; options.forEach((option, index) => { const optionText = option.value.trim(); if (!optionText) return; const letter = String.fromCharCode(65 + index); const isCorrect = document.querySelector(`input[name="correct_option"][value="${letter}"]`)?.checked; previewHTML += `<div class="grid gap-3 rounded-2xl ${isCorrect ? 'border border-emerald-300 bg-emerald-50/70' : 'bg-slate-50'} p-4 md:grid-cols-[auto_1fr_auto] md:items-center"><span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white font-semibold text-teal-700">${letter}</span><span class="text-sm text-slate-700">${optionText}</span>${isCorrect ? '<span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Correct</span>' : '<span></span>'}</div>`; }); previewHTML += '</div>'; } previewHTML += '</div>'; document.getElementById('previewContent').innerHTML = previewHTML; document.getElementById('previewModal').classList.add('is-open'); }
        function closePreview() { document.getElementById('previewModal').classList.remove('is-open'); }
        function exportQuestions() { const cards = Array.from(document.querySelectorAll('[data-question-id]')); if (!cards.length) { alert('No questions are currently available to export.'); return; } const header = ['Question Code', 'Subject', 'Class', 'Type', 'Status', 'Marks']; const rows = cards.map((card) => [card.dataset.questionCode, card.dataset.questionSubject, card.dataset.questionClass, card.dataset.questionType, card.dataset.questionStatus, card.dataset.questionMarks]); const csv = [header, ...rows].map((row) => row.map((value) => `"${String(value || '').replace(/"/g, '""')}"`).join(',')).join('\n'); const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' }); const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = 'question-bank-export.csv'; document.body.appendChild(link); link.click(); document.body.removeChild(link); URL.revokeObjectURL(link.href); }
        function printTable() { const table = document.querySelector('.summary-table-modern'); if (!table) { alert('No summary table is available to print.'); return; } const printWindow = window.open('', '_blank', 'width=1000,height=700'); if (!printWindow) return; printWindow.document.write(`<html><head><title>Question Bank Summary</title><style>body{font-family:Arial,sans-serif;padding:24px;color:#0f172a;}table{width:100%;border-collapse:collapse;margin-top:20px;}th,td{border:1px solid #cbd5e1;padding:12px;text-align:left;}th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.08em;}</style></head><body><h1>Question Bank Summary</h1><p>Generated on ${new Date().toLocaleString()}</p>${table.outerHTML}</body></html>`); printWindow.document.close(); printWindow.focus(); printWindow.print(); }
        document.addEventListener('DOMContentLoaded', () => { toggleQuestionOptions(); const toggleBtn = document.getElementById('toggleQuestionForm'); const formBody = document.getElementById('questionFormBody'); if (toggleBtn && formBody) { const setState = (expanded) => { formBody.style.display = expanded ? 'block' : 'none'; toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false'); toggleBtn.innerHTML = expanded ? '<i class="fas fa-eye-slash"></i><span>Hide Form</span>' : '<i class="fas fa-eye"></i><span>Show Form</span>'; }; setState(true); toggleBtn.addEventListener('click', () => setState(formBody.style.display === 'none')); } window.addEventListener('click', (event) => { const modal = document.getElementById('previewModal'); if (event.target === modal) closePreview(); }); document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closePreview(); }); });
    </script>
</body>
</html>
