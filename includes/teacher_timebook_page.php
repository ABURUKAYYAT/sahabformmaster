<?php
$status_labels = [
    'pending' => 'Pending Review',
    'agreed' => 'Agreed',
    'not_agreed' => 'Not Agreed',
];
$status_class_map = [
    'pending' => 'status-pending',
    'agreed' => 'status-agreed',
    'not_agreed' => 'status-not-agreed',
];

$today_status = strtolower(trim((string)($todayRecord['status'] ?? 'pending')));
if ($today_status === '' || !isset($status_labels[$today_status])) {
    $today_status = 'pending';
}

$expected_arrival_raw = trim((string)($user['expected_arrival'] ?? ''));
$expected_arrival_display = '--:--';
if ($expected_arrival_raw !== '') {
    $expected_arrival_time = strtotime($expected_arrival_raw);
    $expected_arrival_display = $expected_arrival_time ? date('H:i', $expected_arrival_time) : $expected_arrival_raw;
}

$flash_messages = [];
if (isset($_SESSION['success'])) {
    $flash_messages[] = ['type' => 'success', 'text' => (string)$_SESSION['success']];
    unset($_SESSION['success']);
}
if (isset($_SESSION['message'])) {
    $flash_messages[] = ['type' => 'info', 'text' => (string)$_SESSION['message']];
    unset($_SESSION['message']);
}
if (isset($_SESSION['error'])) {
    $flash_messages[] = ['type' => 'error', 'text' => (string)$_SESSION['error']];
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Timebook | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [data-sidebar] {
            overflow: hidden;
        }

        .sidebar-scroll-shell {
            height: 100%;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-y: contain;
            touch-action: pan-y;
            padding-bottom: max(1rem, env(safe-area-inset-bottom));
        }

        .timebook-page {
            overflow-x: hidden;
        }

        .timebook-page .container,
        .timebook-page main,
        .timebook-page section,
        .timebook-page article,
        .timebook-page form,
        .timebook-page label,
        .timebook-page div {
            min-width: 0;
        }

        .timebook-page .container {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .timebook-page .nav-wrap {
            align-items: center;
            flex-wrap: nowrap;
            gap: 0.85rem;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }

        .timebook-page .nav-wrap > :first-child {
            min-width: 0;
            flex: 1 1 auto;
        }

        .timebook-header-actions {
            width: auto;
            display: flex;
            align-items: center;
            flex: 0 0 auto;
            flex-wrap: nowrap;
            gap: 0.75rem;
        }

        .timebook-header-actions .btn {
            width: auto;
            justify-content: center;
            padding: 0.5rem 0.85rem;
            font-size: 0.8125rem;
        }

        .timebook-hero {
            padding: 1.25rem;
            background:
                radial-gradient(circle at top right, rgba(248, 198, 102, 0.26), transparent 28%),
                linear-gradient(135deg, #0f766e 0%, #0f766e 18%, #0f172a 100%);
        }

        .timebook-hero,
        .timebook-hero h1,
        .timebook-hero h2,
        .timebook-hero p {
            color: #fff;
        }

        .timebook-hero h1 {
            font-size: 1.95rem;
            line-height: 1.15;
        }

        .timebook-hero-actions {
            grid-template-columns: 1fr;
        }

        .timebook-page .timebook-hero-actions > a,
        .timebook-page .timebook-header-actions > a {
            min-width: 0;
        }

        .timebook-metrics-grid {
            grid-template-columns: 1fr;
        }

        .timebook-metric-card {
            display: grid;
            gap: 0.45rem;
            border: 1px solid rgba(15, 31, 45, 0.06);
            border-radius: 1.35rem;
            background: #f8fbfb;
            padding: 1rem;
            box-shadow: 0 10px 24px rgba(15, 31, 45, 0.06);
        }

        .timebook-metric-icon {
            display: inline-flex;
            width: 3rem;
            height: 3rem;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            margin-bottom: 0.25rem;
        }

        .time-display {
            font-family: 'Fraunces', Georgia, serif;
            letter-spacing: 0.08em;
            line-height: 1;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            padding: 0.4rem 0.8rem;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.14);
            color: #b45309;
        }

        .status-agreed {
            background: rgba(16, 185, 129, 0.14);
            color: #047857;
        }

        .status-not-agreed {
            background: rgba(239, 68, 68, 0.14);
            color: #b91c1c;
        }

        .flash-card {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            border-radius: 1rem;
            border: 1px solid transparent;
            padding: 1rem 1.1rem;
            box-shadow: 0 10px 24px rgba(15, 31, 45, 0.08);
        }

        .flash-card.success {
            border-color: rgba(16, 185, 129, 0.25);
            background: rgba(236, 253, 245, 0.95);
            color: #065f46;
        }

        .flash-card.info {
            border-color: rgba(14, 165, 233, 0.25);
            background: rgba(240, 249, 255, 0.95);
            color: #0c4a6e;
        }

        .flash-card.error {
            border-color: rgba(239, 68, 68, 0.25);
            background: rgba(254, 242, 242, 0.95);
            color: #991b1b;
        }

        .timebook-table-wrapper {
            overflow-x: auto;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .timebook-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
        }

        .table-scroll-hint {
            display: none;
        }

        .notes-trigger {
            border: 1px solid rgba(15, 106, 92, 0.25);
            border-radius: 999px;
            background: rgba(240, 253, 250, 0.95);
            color: #0f766e;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.35rem 0.75rem;
            transition: all 0.2s ease;
        }

        .notes-trigger:hover {
            border-color: rgba(15, 106, 92, 0.45);
            background: rgba(204, 251, 241, 0.9);
        }

        .notes-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.6);
            z-index: 70;
        }

        .notes-modal.is-open {
            display: flex;
        }

        .notes-dialog {
            width: min(620px, 100%);
            max-height: 90vh;
            overflow: auto;
        }

        @media (max-width: 767px) {
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            main > section,
            .notes-dialog {
                border-radius: 1.25rem !important;
            }

            main > section {
                padding: 1rem !important;
            }

            .timebook-table-wrapper {
                overflow: auto;
                max-height: min(68vh, 30rem);
                border-radius: 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: #fff;
            }

            .timebook-table {
                min-width: 860px !important;
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

            .timebook-header-actions {
                gap: 0.5rem;
            }

            .timebook-header-actions .btn {
                padding: 0.5rem 0.7rem;
                font-size: 0.78rem;
            }

            .timebook-header-actions .btn span,
            .timebook-hero-actions .btn span {
                white-space: nowrap;
            }
        }

        @media (max-width: 640px) {
            .notes-modal {
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
        }

        @media (min-width: 640px) {
            .timebook-page .container {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
            }

            .timebook-hero {
                padding: 1.5rem 2rem;
            }

            .timebook-hero-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .timebook-metrics-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 768px) {
            .timebook-hero h1 {
                font-size: 2.4rem;
            }
        }

        @media (min-width: 1024px) {
            .timebook-page .nav-wrap {
                gap: 1rem;
                padding-top: 1rem;
                padding-bottom: 1rem;
            }

            .timebook-header-actions {
                gap: 0.75rem;
            }

            .timebook-header-actions .btn {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }

            .timebook-hero {
                padding: 1.75rem 2rem;
            }
        }

        @media (min-width: 1200px) {
            .timebook-page .container {
                padding-left: 2.75rem;
                padding-right: 2.75rem;
            }
        }

        @media (min-width: 1280px) {
            .timebook-metrics-grid {
                grid-template-columns: repeat(5, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body class="landing bg-slate-50 timebook-page">
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
            <div class="timebook-header-actions flex items-center gap-3">
                <span class="hidden text-sm text-slate-600 md:block">Welcome, <?php echo htmlspecialchars($user['full_name'] ?? 'Teacher'); ?></span>
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
                <div class="timebook-hero p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Attendance Workspace</p>
                            <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">Daily sign-in with clearer status tracking and faster attendance review context.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">Use the same professional workspace pattern as the Question Bank page to manage your timebook records, monitor review outcomes, and keep attendance updates consistent.</p>
                        </div>
                        <div class="timebook-hero-actions grid gap-3 sm:grid-cols-2">
                            <a href="#today-attendance" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                <i class="fas fa-fingerprint"></i>
                                <span>Today's Sign-in</span>
                            </a>
                            <a href="#attendance-records" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-table-list"></i>
                                <span>View Records</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="timebook-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="timebook-metric-card">
                        <div class="timebook-metric-icon bg-teal-600/10 text-teal-700">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Days Worked</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo (int)($stats['total_days'] ?? 0); ?></h2>
                        <p class="text-sm text-slate-500">Sign-ins this month</p>
                    </article>
                    <article class="timebook-metric-card">
                        <div class="timebook-metric-icon bg-emerald-100 text-emerald-700">
                            <i class="fas fa-shield-check"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Agreed Days</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo (int)($stats['agreed_days'] ?? 0); ?></h2>
                        <p class="text-sm text-slate-500">Reviewed and approved</p>
                    </article>
                    <article class="timebook-metric-card">
                        <div class="timebook-metric-icon bg-amber-500/10 text-amber-700">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Pending</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo (int)($stats['pending_days'] ?? 0); ?></h2>
                        <p class="text-sm text-slate-500">Awaiting admin review</p>
                    </article>
                    <article class="timebook-metric-card">
                        <div class="timebook-metric-icon bg-rose-100 text-rose-700">
                            <i class="fas fa-circle-xmark"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Not Agreed</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo (int)($stats['not_agreed_days'] ?? 0); ?></h2>
                        <p class="text-sm text-slate-500">Need correction follow-up</p>
                    </article>
                    <article class="timebook-metric-card">
                        <div class="timebook-metric-icon bg-sky-100 text-sky-700">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Today's Status</p>
                        <h2 class="mt-1 text-lg font-semibold text-ink-900"><?php echo htmlspecialchars($status_labels[$today_status]); ?></h2>
                        <p class="text-sm text-slate-500">Expected arrival: <?php echo htmlspecialchars($expected_arrival_display); ?></p>
                    </article>
                </div>
            </section>

            <?php if (!empty($flash_messages)): ?>
                <section class="space-y-3">
                    <?php foreach ($flash_messages as $flash): ?>
                        <div class="flash-card <?php echo htmlspecialchars($flash['type']); ?>">
                            <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-circle-check' : ($flash['type'] === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info'); ?> mt-0.5"></i>
                            <p class="text-sm font-semibold"><?php echo htmlspecialchars($flash['text']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_340px]" id="today-attendance">
                <div class="rounded-3xl bg-white shadow-lift border border-ink-900/5 overflow-hidden">
                    <div class="flex flex-col gap-4 border-b border-ink-900/5 p-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Live Check-in</p>
                            <h2 class="mt-2 text-2xl font-display text-ink-900">Today's Attendance</h2>
                        </div>
                        <div class="rounded-2xl bg-mist-50 px-4 py-3 border border-ink-900/5 text-right">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Current Time</p>
                            <p id="currentTime" class="time-display mt-1 text-xl font-semibold text-ink-900"><?php echo date('H:i:s'); ?></p>
                        </div>
                    </div>
                    <div class="p-6 space-y-5">
                        <?php if ($todayRecord): ?>
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">
                                <p class="text-sm font-semibold">You signed in at <?php echo date('H:i:s', strtotime($todayRecord['sign_in_time'])); ?>.</p>
                                <div class="mt-3">
                                    <?php
                                    $record_status = strtolower(trim((string)($todayRecord['status'] ?? 'pending')));
                                    if ($record_status === '' || !isset($status_labels[$record_status])) {
                                        $record_status = 'pending';
                                    }
                                    $today_status_class = $status_class_map[$record_status] ?? 'status-pending';
                                    ?>
                                    <span class="status-pill <?php echo $today_status_class; ?>"><?php echo htmlspecialchars($status_labels[$record_status]); ?></span>
                                </div>
                            </div>

                            <?php if (!empty($todayRecord['notes'])): ?>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Your Note</p>
                                    <p class="mt-2 text-sm text-slate-700"><?php echo nl2br(htmlspecialchars($todayRecord['notes'])); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($todayRecord['admin_notes'])): ?>
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
                                    <p class="text-xs font-semibold uppercase tracking-wide">Admin Notes</p>
                                    <p class="mt-2 text-sm"><?php echo nl2br(htmlspecialchars($todayRecord['admin_notes'])); ?></p>
                                </div>
                            <?php endif; ?>
                        <?php elseif (!$signin_enabled): ?>
                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
                                <p class="text-sm font-semibold">Teacher sign-in is temporarily disabled.</p>
                                <p class="mt-2 text-sm">Please contact your school administrator to enable timebook check-in for this school.</p>
                            </div>
                        <?php else: ?>
                            <form method="POST" id="signInForm" class="space-y-4">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="sign_in" value="1">
                                <div>
                                    <label for="notesInput" class="mb-2 block text-sm font-semibold text-slate-700">Notes (optional)</label>
                                    <textarea id="notesInput" class="min-h-[110px] w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" name="notes" rows="3" placeholder="Add any note relevant to today's sign-in."></textarea>
                                </div>
                                <button type="submit" id="signInButton" class="btn btn-primary">
                                    <i class="fas fa-fingerprint"></i>
                                    <span>Sign In Now</span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Related Pages</h2>
                        <div class="mt-4 grid gap-3 text-sm font-semibold">
                            <a href="class_attendance.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                <span><i class="fas fa-calendar-check mr-3 text-teal-700"></i>Class Attendance</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                            <a href="permissions.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                <span><i class="fas fa-key mr-3 text-teal-700"></i>Permissions</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                            <a href="index.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                <span><i class="fas fa-gauge-high mr-3 text-teal-700"></i>Teacher Dashboard</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </section>

                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Attendance Guidance</h2>
                        <div class="mt-4 space-y-4 text-sm text-slate-600">
                            <p><span class="font-semibold text-ink-900">Sign in once daily:</span> your first check-in creates the official record for that day.</p>
                            <p><span class="font-semibold text-ink-900">Use useful notes:</span> include context only when needed to support admin review.</p>
                            <p><span class="font-semibold text-ink-900">Track status quickly:</span> check the records table for pending and reviewed decisions.</p>
                        </div>
                    </section>
                </div>
            </section>
            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5" id="attendance-records">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Monthly Records</p>
                        <h2 class="mt-2 text-2xl font-display text-ink-900">Recent Attendance Entries</h2>
                        <p class="mt-2 text-sm text-slate-600">Review this month's sign-ins and any admin feedback attached to each entry.</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-3 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Records This Month</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo count($monthRecords); ?></p>
                    </div>
                </div>

                <?php if (empty($monthRecords)): ?>
                    <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                        <i class="fas fa-folder-open text-3xl text-teal-700"></i>
                        <p class="mt-4 text-lg font-semibold text-ink-900">No attendance records yet</p>
                        <p class="mt-2 text-sm">Your sign-in history for this month will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="timebook-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10">
                        <table class="timebook-table min-w-[900px] w-full bg-white text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-4 py-4">Date</th>
                                    <th class="px-4 py-4">Sign In Time</th>
                                    <th class="px-4 py-4">Expected Arrival</th>
                                    <th class="px-4 py-4">Status</th>
                                    <th class="px-4 py-4">Admin Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthRecords as $record): ?>
                                    <?php
                                    $record_status = strtolower(trim((string)($record['status'] ?? 'pending')));
                                    if ($record_status === '' || !isset($status_labels[$record_status])) {
                                        $record_status = 'pending';
                                    }
                                    $record_status_class = $status_class_map[$record_status] ?? 'status-pending';
                                    ?>
                                    <tr class="border-t border-slate-100 align-top">
                                        <td class="px-4 py-4 font-semibold text-ink-900"><?php echo date('M j, Y', strtotime($record['sign_in_time'])); ?></td>
                                        <td class="px-4 py-4 text-slate-700"><?php echo date('H:i:s', strtotime($record['sign_in_time'])); ?></td>
                                        <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($expected_arrival_display); ?></td>
                                        <td class="px-4 py-4">
                                            <span class="status-pill <?php echo $record_status_class; ?>"><?php echo htmlspecialchars($status_labels[$record_status]); ?></span>
                                        </td>
                                        <td class="px-4 py-4">
                                            <?php if (!empty($record['admin_notes'])): ?>
                                                <button type="button" class="notes-trigger" data-notes="<?php echo htmlspecialchars($record['admin_notes']); ?>">
                                                    <i class="fas fa-sticky-note"></i>
                                                    <span>View Notes</span>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-slate-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways to review the full table on smaller screens.</span></p>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <div id="notesModal" class="notes-modal" role="dialog" aria-modal="true" aria-labelledby="notesTitle" aria-hidden="true">
        <div class="notes-dialog rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Admin Review</p>
                    <h2 id="notesTitle" class="mt-2 text-2xl font-display text-ink-900">Attendance Note</h2>
                </div>
                <button type="button" id="closeNotesModal" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p id="notesModalContent" class="text-sm leading-7 text-slate-700"></p>
            </div>
            <div class="mt-5 text-right">
                <button type="button" id="closeNotesModalFooter" class="btn btn-outline">
                    <i class="fas fa-check"></i>
                    <span>Close</span>
                </button>
            </div>
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

        const currentTimeEl = document.getElementById('currentTime');
        const serverStartTime = new Date('<?php echo date('Y-m-d\\TH:i:sP'); ?>');
        let serverTime = new Date(serverStartTime.getTime());

        const formatTime = (date) => {
            const h = String(date.getHours()).padStart(2, '0');
            const m = String(date.getMinutes()).padStart(2, '0');
            const s = String(date.getSeconds()).padStart(2, '0');
            return `${h}:${m}:${s}`;
        };

        const updateClock = () => {
            if (currentTimeEl) {
                currentTimeEl.textContent = formatTime(serverTime);
            }
            serverTime = new Date(serverTime.getTime() + 1000);
        };

        updateClock();
        setInterval(updateClock, 1000);

        const signInForm = document.getElementById('signInForm');
        const signInButton = document.getElementById('signInButton');
        if (signInForm && signInButton) {
            signInForm.addEventListener('submit', () => {
                signInButton.disabled = true;
                signInButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Signing In...</span>';
            });
        }

        const notesInput = document.getElementById('notesInput');
        if (notesInput) {
            notesInput.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = `${this.scrollHeight}px`;
            });
        }

        const notesModal = document.getElementById('notesModal');
        const notesModalContent = document.getElementById('notesModalContent');
        const closeNotesButtons = [
            document.getElementById('closeNotesModal'),
            document.getElementById('closeNotesModalFooter')
        ].filter(Boolean);

        const openNotesModal = (notes) => {
            if (!notesModal || !notesModalContent) return;
            notesModalContent.textContent = notes || 'No notes available.';
            notesModal.classList.add('is-open');
            notesModal.setAttribute('aria-hidden', 'false');
        };

        const closeNotesModal = () => {
            if (!notesModal) return;
            notesModal.classList.remove('is-open');
            notesModal.setAttribute('aria-hidden', 'true');
        };

        document.querySelectorAll('[data-notes]').forEach((button) => {
            button.addEventListener('click', () => {
                openNotesModal(button.getAttribute('data-notes'));
            });
        });

        closeNotesButtons.forEach((button) => {
            button.addEventListener('click', closeNotesModal);
        });

        if (notesModal) {
            notesModal.addEventListener('click', (event) => {
                if (event.target === notesModal) {
                    closeNotesModal();
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeNotesModal();
            }
        });
    </script>
</body>
</html>
