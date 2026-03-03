<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();
$paper_id = intval($_GET['id'] ?? 0);

if ($paper_id <= 0) {
    header("Location: generate_paper.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT ep.*, s.subject_name, c.class_name, u.full_name as teacher_name,
           ep.pdf_file_path, gp.file_path
    FROM exam_papers ep
    LEFT JOIN subjects s ON ep.subject_id = s.id
    LEFT JOIN classes c ON ep.class_id = c.id
    LEFT JOIN users u ON ep.created_by = u.id
    LEFT JOIN generated_papers gp ON ep.id = gp.paper_id
    WHERE ep.id = ?
    ORDER BY gp.generation_date DESC
    LIMIT 1
");
$stmt->execute([$paper_id]);
$paper = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$paper) {
    header("Location: generate_paper.php");
    exit;
}

$pdf_path = !empty($paper['pdf_file_path']) ? $paper['pdf_file_path'] : $paper['file_path'];
$pdf_exists = $pdf_path && file_exists($pdf_path);
$original_php_self = $_SERVER['PHP_SELF'];
$_SERVER['PHP_SELF'] = 'questions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($paper['paper_title']); ?> | Paper Viewer</title>
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
                <a class="btn btn-outline" href="generate_paper.php">Paper Builder</a>
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
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Paper Viewer</p>
                        <h1 class="mt-2 text-3xl font-display text-ink-900"><?php echo htmlspecialchars($paper['paper_title']); ?></h1>
                        <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">Review the generated paper, confirm its exam metadata, and download or print the final output from a mobile-friendly teacher workspace.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <?php if ($pdf_exists): ?>
                            <a class="btn btn-primary" href="<?php echo htmlspecialchars($pdf_path); ?>" download="<?php echo htmlspecialchars(basename($pdf_path)); ?>"><i class="fas fa-download"></i><span>Download PDF</span></a>
                        <?php endif; ?>
                        <a class="btn btn-outline" href="generate_pdf.php?paper_id=<?php echo (int)$paper_id; ?>" target="_blank" rel="noopener"><i class="fas fa-rotate"></i><span>Regenerate PDF</span></a>
                    </div>
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Paper Code</p><p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($paper['paper_code']); ?></p></div>
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Subject</p><p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($paper['subject_name'] ?? 'Not set'); ?></p></div>
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Class</p><p class="mt-2 font-semibold text-ink-900"><?php echo htmlspecialchars($paper['class_name'] ?? 'Not set'); ?></p></div>
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Time</p><p class="mt-2 font-semibold text-ink-900"><?php echo (int)$paper['time_allotted']; ?> minutes</p></div>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_340px]">
                <div class="space-y-6">
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-xl font-semibold text-ink-900">Generated Paper</h2>
                                <p class="mt-1 text-sm text-slate-500">Use the embedded view for a quick inspection before downloading or printing.</p>
                            </div>
                            <?php if ($pdf_exists): ?>
                                <button type="button" class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i><span>Print Paper</span></button>
                            <?php endif; ?>
                        </div>

                        <?php if ($pdf_exists): ?>
                            <div class="mt-5 overflow-hidden rounded-3xl border border-slate-200 bg-slate-50">
                                <iframe src="<?php echo htmlspecialchars($pdf_path); ?>#toolbar=0&navpanes=0" title="Exam Paper PDF" class="h-[70vh] w-full bg-white"></iframe>
                            </div>
                        <?php else: ?>
                            <div class="mt-5 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center">
                                <i class="fas fa-file-pdf text-4xl text-slate-400"></i>
                                <h3 class="mt-4 text-lg font-semibold text-ink-900">PDF not generated yet</h3>
                                <p class="mt-2 text-sm text-slate-500">Generate the PDF to preview the finished paper here.</p>
                                <a class="btn btn-primary mt-5" href="generate_pdf.php?paper_id=<?php echo (int)$paper_id; ?>" target="_blank" rel="noopener"><i class="fas fa-wand-magic-sparkles"></i><span>Generate PDF Now</span></a>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Paper Snapshot</h2>
                        <div class="mt-4 space-y-4 text-sm">
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Exam Type</p><p class="mt-1 font-semibold text-ink-900"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $paper['exam_type'] ?? 'Exam'))); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Total Marks</p><p class="mt-1 font-semibold text-ink-900"><?php echo htmlspecialchars((string)$paper['total_marks']); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Prepared By</p><p class="mt-1 font-semibold text-ink-900"><?php echo htmlspecialchars($paper['teacher_name'] ?? 'Teacher'); ?></p></div>
                            <div><p class="text-xs uppercase tracking-wide text-slate-500">Term</p><p class="mt-1 font-semibold text-ink-900"><?php echo htmlspecialchars($paper['term'] ?: 'Not set'); ?></p></div>
                        </div>
                    </section>

                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Quick Actions</h2>
                        <div class="mt-4 grid gap-3 text-sm font-semibold">
                            <a href="generate_paper.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700"><span><i class="fas fa-arrow-left mr-3 text-teal-700"></i>Back to paper builder</span><i class="fas fa-arrow-right"></i></a>
                            <a href="generate_paper_pdf.php?paper_id=<?php echo (int)$paper_id; ?>" target="_blank" rel="noopener" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700"><span><i class="fas fa-file-arrow-down mr-3 text-teal-700"></i>Open standalone PDF</span><i class="fas fa-arrow-right"></i></a>
                            <a href="questions.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700"><span><i class="fas fa-layer-group mr-3 text-teal-700"></i>Return to question bank</span><i class="fas fa-arrow-right"></i></a>
                        </div>
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

        if (window.location.hash === '#print' || window.location.hash === '#autoprint') {
            setTimeout(() => window.print(), 1000);
        }
    </script>
</body>
</html>
