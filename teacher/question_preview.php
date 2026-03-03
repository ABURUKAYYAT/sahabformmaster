<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();
$question_id = intval($_GET['id'] ?? 0);

if ($question_id <= 0) {
    header("Location: questions.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT q.*, s.subject_name, c.class_name, cat.category_name,
           u.full_name as created_by_name, r.full_name as reviewed_by_name
    FROM questions_bank q
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN classes c ON q.class_id = c.id
    LEFT JOIN question_categories cat ON q.category_id = cat.id
    LEFT JOIN users u ON q.created_by = u.id
    LEFT JOIN users r ON q.reviewed_by = r.id
    WHERE q.id = ? AND q.school_id = ?
");
$stmt->execute([$question_id, $current_school_id]);
$question = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$question) {
    header("Location: questions.php");
    exit;
}

$options = [];
if (($question['question_type'] ?? '') === 'mcq') {
    $stmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY option_letter");
    $stmt->execute([$question_id]);
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stmt = $pdo->prepare("SELECT tag_name FROM question_tags WHERE question_id = ?");
$stmt->execute([$question_id]);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT u.*, e.exam_name
    FROM question_usage u
    LEFT JOIN exams e ON u.exam_id = e.id
    WHERE u.question_id = ?
    ORDER BY u.used_at DESC
    LIMIT 5
");
$stmt->execute([$question_id]);
$usage_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_labels = ['draft' => 'Draft', 'reviewed' => 'Reviewed', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$type_labels = ['mcq' => 'Multiple Choice', 'true_false' => 'True/False', 'short_answer' => 'Short Answer', 'essay' => 'Essay', 'fill_blank' => 'Fill in the Blank'];
$difficulty_labels = ['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard', 'very_hard' => 'Very Hard'];
$original_php_self = $_SERVER['PHP_SELF'];
$_SERVER['PHP_SELF'] = 'questions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($question['question_code']); ?> | Question Preview</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [data-sidebar] { overflow: hidden; }
        .sidebar-scroll-shell { height: 100%; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-y: contain; touch-action: pan-y; padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
    </style>
</head>
<body class="landing">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu"><span></span><span></span><span></span></button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="w-10 h-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a class="btn btn-outline" href="questions.php">Question Bank</a>
                <a class="btn btn-primary" href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
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
            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Question Preview</p>
                        <h1 class="mt-2 text-3xl font-display text-ink-900"><?php echo htmlspecialchars($question['question_code']); ?></h1>
                        <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">Review the question exactly as it will appear in the bank, together with metadata, answer structure, and recent usage context.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a class="btn btn-primary" href="questions.php?edit=<?php echo (int)$question['id']; ?>#question-editor"><i class="fas fa-pen"></i><span>Edit Question</span></a>
                        <a class="btn btn-outline" href="generate_paper.php"><i class="fas fa-file-lines"></i><span>Build Paper</span></a>
                    </div>
                </div>
                <div class="mt-5 flex flex-wrap gap-2 text-xs font-semibold">
                    <span class="rounded-full bg-teal-600/10 px-3 py-1 text-teal-700"><?php echo htmlspecialchars($type_labels[$question['question_type']] ?? ucfirst(str_replace('_', ' ', $question['question_type']))); ?></span>
                    <span class="rounded-full bg-amber-500/10 px-3 py-1 text-amber-700"><?php echo htmlspecialchars($difficulty_labels[$question['difficulty_level']] ?? ucfirst(str_replace('_', ' ', $question['difficulty_level']))); ?></span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-700"><?php echo htmlspecialchars($status_labels[$question['status']] ?? ucfirst($question['status'])); ?></span>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_340px]">
                <div class="space-y-6">
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Subject</p><p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($question['subject_name'] ?? 'Not assigned'); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Class</p><p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($question['class_name'] ?? 'Not assigned'); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Marks</p><p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars((string)$question['marks']); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Category</p><p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($question['category_name'] ?? 'Uncategorized'); ?></p></div>
                        </div>
                        <div class="mt-6 rounded-3xl border border-slate-200 bg-slate-50 p-6">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Question Text</p>
                            <div class="mt-4 prose prose-slate max-w-none text-slate-700"><?php echo $question['question_text']; ?></div>
                        </div>
                    </section>

                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-xl font-semibold text-ink-900">Response Layout</h2>
                            <span class="rounded-full bg-teal-600/10 px-3 py-1 text-xs font-semibold text-teal-700"><?php echo htmlspecialchars($type_labels[$question['question_type']] ?? ucfirst(str_replace('_', ' ', $question['question_type']))); ?></span>
                        </div>
                        <?php if (($question['question_type'] ?? '') === 'mcq' && !empty($options)): ?>
                            <div class="mt-5 grid gap-3">
                                <?php foreach ($options as $option): ?>
                                    <div class="grid gap-3 rounded-2xl border <?php echo !empty($option['is_correct']) ? 'border-emerald-300 bg-emerald-50/70' : 'border-slate-200 bg-slate-50'; ?> p-4 md:grid-cols-[auto_1fr_auto] md:items-center">
                                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white font-semibold text-teal-700"><?php echo htmlspecialchars($option['option_letter']); ?></span>
                                        <span class="text-sm text-slate-700"><?php echo htmlspecialchars($option['option_text']); ?></span>
                                        <?php if (!empty($option['is_correct'])): ?><span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Correct Answer</span><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">
                                This question uses a free-response structure. The final student response space will depend on the paper builder and print format selected when generating the exam.
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Recent Usage</h2>
                        <?php if (empty($usage_history)): ?>
                            <p class="mt-4 rounded-2xl bg-slate-50 p-4 text-sm text-slate-500">This question has not yet been used in a recent paper or exam record.</p>
                        <?php else: ?>
                            <div class="mt-4 overflow-x-auto rounded-3xl border border-slate-200">
                                <table class="min-w-full bg-white text-sm">
                                    <thead class="bg-slate-50 text-left uppercase tracking-[0.18em] text-xs text-slate-500">
                                        <tr><th class="px-4 py-4">Exam</th><th class="px-4 py-4">Used On</th><th class="px-4 py-4">Notes</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($usage_history as $usage): ?>
                                            <tr class="border-t border-slate-100">
                                                <td class="px-4 py-4 font-semibold text-ink-900"><?php echo htmlspecialchars($usage['exam_name'] ?? 'General Use'); ?></td>
                                                <td class="px-4 py-4 text-slate-700"><?php echo !empty($usage['used_at']) ? htmlspecialchars(date('d M Y, h:i A', strtotime($usage['used_at']))) : 'N/A'; ?></td>
                                                <td class="px-4 py-4 text-slate-500"><?php echo htmlspecialchars($usage['usage_type'] ?? 'Question bank usage'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Question Details</h2>
                        <div class="mt-4 space-y-4 text-sm">
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Created By</p><p class="mt-1 font-semibold text-ink-900"><?php echo htmlspecialchars($question['created_by_name'] ?? 'Unknown'); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Reviewed By</p><p class="mt-1 font-semibold text-ink-900"><?php echo htmlspecialchars($question['reviewed_by_name'] ?? 'Not reviewed'); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Topic</p><p class="mt-1 font-semibold text-ink-900"><?php echo htmlspecialchars($question['topic'] ?: 'Not set'); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Sub-topic</p><p class="mt-1 font-semibold text-ink-900"><?php echo htmlspecialchars($question['sub_topic'] ?: 'Not set'); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Created On</p><p class="mt-1 font-semibold text-ink-900"><?php echo !empty($question['created_at']) ? htmlspecialchars(date('d M Y, h:i A', strtotime($question['created_at']))) : 'N/A'; ?></p></div>
                        </div>
                    </section>

                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Tags</h2>
                        <?php if (empty($tags)): ?>
                            <p class="mt-4 text-sm text-slate-500">No tags were attached to this question.</p>
                        <?php else: ?>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <?php foreach ($tags as $tag): ?>
                                    <span class="rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700 border border-sky-100">#<?php echo htmlspecialchars($tag['tag_name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <?php $_SERVER['PHP_SELF'] = $original_php_self; ?>
    <?php include '../includes/floating-button.php'; ?>
    <script>
        const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        const sidebar = document.querySelector('[data-sidebar]');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        const body = document.body;
        const openSidebar = () => { if (!sidebar || !overlay) return; sidebar.classList.remove('-translate-x-full'); overlay.classList.remove('opacity-0', 'pointer-events-none'); overlay.classList.add('opacity-100'); body.classList.add('nav-open'); };
        const closeSidebar = () => { if (!sidebar || !overlay) return; sidebar.classList.add('-translate-x-full'); overlay.classList.add('opacity-0', 'pointer-events-none'); overlay.classList.remove('opacity-100'); body.classList.remove('nav-open'); };
        if (sidebarToggle) sidebarToggle.addEventListener('click', () => sidebar.classList.contains('-translate-x-full') ? openSidebar() : closeSidebar());
        if (overlay) overlay.addEventListener('click', closeSidebar);
        if (sidebar) sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebar));
    </script>
</body>
</html>
