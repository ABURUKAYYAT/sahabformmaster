<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

function diary_format_time_range(?string $start, ?string $end): string
{
    if (!empty($start) && !empty($end)) {
        return date('g:i A', strtotime($start)) . ' - ' . date('g:i A', strtotime($end));
    }

    if (!empty($start)) {
        return date('g:i A', strtotime($start));
    }

    return 'All day';
}

function diary_status_classes(string $status): string
{
    $map = [
        'Upcoming' => 'bg-sky-100 text-sky-700',
        'Ongoing' => 'bg-amber-100 text-amber-700',
        'Completed' => 'bg-emerald-100 text-emerald-700',
        'Cancelled' => 'bg-rose-100 text-rose-700',
    ];

    return $map[$status] ?? 'bg-slate-100 text-slate-600';
}

function diary_type_classes(string $type): string
{
    $map = [
        'Academics' => 'bg-teal-600/10 text-teal-700',
        'Sports' => 'bg-sky-100 text-sky-700',
        'Cultural' => 'bg-fuchsia-100 text-fuchsia-700',
        'Competition' => 'bg-amber-100 text-amber-700',
    ];

    return $map[$type] ?? 'bg-slate-100 text-slate-600';
}

function diary_excerpt(?string $text, int $length = 150): string
{
    $plain = trim(strip_tags((string) $text));
    if ($plain === '') {
        return 'No description provided yet.';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($plain) > $length ? mb_substr($plain, 0, $length - 3) . '...' : $plain;
    }

    return strlen($plain) > $length ? substr($plain, 0, $length - 3) . '...' : $plain;
}

function diary_count_winners(?string $winners): int
{
    if (empty($winners)) {
        return 0;
    }

    $parts = preg_split('/[\r\n,]+/', $winners);
    $parts = array_filter(array_map('trim', $parts));
    return count($parts);
}

$search = trim($_GET['search'] ?? '');
$type_filter = trim($_GET['type'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$selected_activity_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$baseQuery = "SELECT sd.*, u.full_name AS coordinator_name
              FROM school_diary sd
              LEFT JOIN users u ON sd.coordinator_id = u.id
              WHERE sd.school_id = ?";
$params = [$current_school_id];

if ($search !== '') {
    $baseQuery .= " AND (sd.activity_title LIKE ? OR sd.description LIKE ? OR sd.venue LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($type_filter !== '') {
    $baseQuery .= " AND sd.activity_type = ?";
    $params[] = $type_filter;
}

if ($date_from !== '') {
    $baseQuery .= " AND sd.activity_date >= ?";
    $params[] = $date_from;
}

if ($date_to !== '') {
    $baseQuery .= " AND sd.activity_date <= ?";
    $params[] = $date_to;
}

$query = $baseQuery . " ORDER BY sd.activity_date DESC, sd.start_time ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filterState = array_filter([
    'search' => $search !== '' ? $search : null,
    'type' => $type_filter !== '' ? $type_filter : null,
    'date_from' => $date_from !== '' ? $date_from : null,
    'date_to' => $date_to !== '' ? $date_to : null,
], static fn($value) => $value !== null && $value !== '');

$list_url = 'school_diary.php' . (!empty($filterState) ? '?' . http_build_query($filterState) : '');

$selected_activity = null;
$attachments = [];

if ($selected_activity_id > 0) {
    $detailStmt = $pdo->prepare("
        SELECT sd.*, u.full_name AS coordinator_name
        FROM school_diary sd
        LEFT JOIN users u ON sd.coordinator_id = u.id
        WHERE sd.id = ? AND sd.school_id = ?
    ");
    $detailStmt->execute([$selected_activity_id, $current_school_id]);
    $selected_activity = $detailStmt->fetch(PDO::FETCH_ASSOC);

    if (!$selected_activity) {
        header("Location: {$list_url}");
        exit;
    }

    $attachmentsStmt = $pdo->prepare("SELECT * FROM school_diary_attachments WHERE diary_id = ? AND school_id = ? ORDER BY id DESC");
    $attachmentsStmt->execute([$selected_activity_id, $current_school_id]);
    $attachments = $attachmentsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['pdf'])) {
        require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $schoolName = get_school_display_name();

        $pdf->SetCreator($schoolName);
        $pdf->SetAuthor($schoolName);
        $pdf->SetTitle('Activity Details - ' . $selected_activity['activity_title']);
        $pdf->SetSubject('School Activity Report');
        $pdf->SetHeaderData('', 0, 'SahabFormMaster - Activity Details', '', [22, 133, 117], [255, 255, 255]);
        $pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN]);
        $pdf->setFooterFont([PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA]);
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 15, 'Activity Details', 0, 1, 'C', 0, '', 0, false, 'M', 'M');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetFillColor(22, 133, 117);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 10, $selected_activity['activity_title'], 0, 1, 'L', 1);
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(247, 250, 252);

        $basicInfo = [
            'Type' => $selected_activity['activity_type'],
            'Date' => date('F j, Y', strtotime($selected_activity['activity_date'])),
            'Time' => diary_format_time_range($selected_activity['start_time'], $selected_activity['end_time']),
            'Venue' => $selected_activity['venue'] ?: 'Not specified',
            'Coordinator' => $selected_activity['coordinator_name'] ?: 'Not assigned',
            'Target Audience' => $selected_activity['target_audience'] ?: 'General',
            'Status' => $selected_activity['status'],
        ];

        foreach ($basicInfo as $label => $value) {
            $pdf->Cell(44, 8, $label . ':', 1, 0, 'L', 1);
            $pdf->Cell(0, 8, $value, 1, 1, 'L', 1);
        }

        $sections = [
            'Description' => $selected_activity['description'] ?? '',
            'Objectives' => $selected_activity['objectives'] ?? '',
            'Resources Required' => $selected_activity['resources'] ?? '',
        ];

        foreach ($sections as $title => $content) {
            if (trim($content) === '') {
                continue;
            }

            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetFillColor(2, 132, 199);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 8, $title, 0, 1, 'L', 1);
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell(0, 6, $content, 0, 'L', false, 1, '', '', true, 0, false, false, 0, 'T', false);
        }

        if (($selected_activity['status'] ?? '') === 'Completed') {
            $completionItems = [
                'Participants' => $selected_activity['participant_count'] ?? '',
                'Winners' => $selected_activity['winners_list'] ?? '',
                'Achievements' => $selected_activity['achievements'] ?? '',
                'Feedback Summary' => $selected_activity['feedback_summary'] ?? '',
            ];

            $hasCompletionContent = false;
            foreach ($completionItems as $item) {
                if (trim((string) $item) !== '') {
                    $hasCompletionContent = true;
                    break;
                }
            }

            if ($hasCompletionContent) {
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'B', 12);
                $pdf->SetFillColor(4, 120, 87);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell(0, 8, 'Completion Details', 0, 1, 'L', 1);
                $pdf->Ln(2);
                $pdf->SetTextColor(0, 0, 0);

                foreach ($completionItems as $label => $value) {
                    if (trim((string) $value) === '') {
                        continue;
                    }
                    $pdf->SetFont('helvetica', 'B', 11);
                    $pdf->Cell(0, 7, $label, 0, 1, 'L', 0);
                    $pdf->SetFont('helvetica', '', 11);
                    $pdf->MultiCell(0, 6, (string) $value, 0, 'L', false, 1, '', '', true, 0, false, false, 0, 'T', false);
                    $pdf->Ln(1);
                }
            }
        }

        if (!empty($attachments)) {
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetFillColor(124, 58, 237);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 8, 'Attachments', 0, 1, 'L', 1);
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);

            foreach ($attachments as $attachment) {
                $label = '- ' . $attachment['file_name'] . ' (' . strtoupper($attachment['file_type']) . ')';
                $pdf->Cell(0, 6, $label, 0, 1, 'L', 0);
            }
        }

        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 5, 'Generated by SahabFormMaster on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C', 0);
        $pdf->Output('activity_details_' . $selected_activity_id . '.pdf', 'D');
        exit;
    }

    $updateStmt = $pdo->prepare("UPDATE school_diary SET view_count = view_count + 1 WHERE id = ? AND school_id = ?");
    $updateStmt->execute([$selected_activity_id, $current_school_id]);
    $selected_activity['view_count'] = ((int) ($selected_activity['view_count'] ?? 0)) + 1;
}

$today = date('Y-m-d');
$upcoming_count = count(array_filter($activities, static function ($activity) use ($today) {
    return ($activity['activity_date'] ?? '') >= $today && ($activity['status'] ?? '') !== 'Completed' && ($activity['status'] ?? '') !== 'Cancelled';
}));
$completed_count = count(array_filter($activities, static fn($activity) => ($activity['status'] ?? '') === 'Completed'));
$this_month_count = count(array_filter($activities, static function ($activity) {
    return date('Y-m', strtotime($activity['activity_date'])) === date('Y-m');
}));

$activity_types = ['Academics', 'Sports', 'Cultural', 'Competition'];
foreach ($activities as $activity) {
    if (!in_array($activity['activity_type'], $activity_types, true) && !empty($activity['activity_type'])) {
        $activity_types[] = $activity['activity_type'];
    }
}

$calendarActivities = [];
foreach ($activities as $activity) {
    $calendarActivities[] = [
        'id' => (int) $activity['id'],
        'title' => $activity['activity_title'],
        'date' => $activity['activity_date'],
        'time' => diary_format_time_range($activity['start_time'] ?? null, $activity['end_time'] ?? null),
        'venue' => $activity['venue'] ?: 'Venue to be confirmed',
        'type' => $activity['activity_type'],
        'status' => $activity['status'],
        'coordinator' => $activity['coordinator_name'] ?: 'Not assigned',
        'description' => diary_excerpt($activity['description'] ?? '', 140),
        'detailUrl' => 'school_diary.php?' . http_build_query(array_merge($filterState, ['id' => $activity['id'], 'view' => 'details'])),
        'pdfUrl' => 'school_diary.php?' . http_build_query(array_merge($filterState, ['id' => $activity['id'], 'pdf' => 1])),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Diary | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <meta name="theme-color" content="#0f172a">
</head>
<body class="landing bg-slate-50">
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
            <div class="flex items-center gap-3">
                <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                <a class="btn btn-outline" href="../index.php">Home</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:translate-x-0" data-sidebar>
            <?php include '../includes/teacher_sidebar.php'; ?>
        </aside>

        <main class="space-y-6">
            <section class="overflow-hidden rounded-3xl border border-ink-900/5 shadow-lift">
                <div class="bg-gradient-to-r from-teal-700 via-emerald-600 to-sky-600 p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="mb-1 text-xs uppercase tracking-wide text-white/80">School planning desk</p>
                            <h1 class="text-3xl font-display font-semibold leading-tight sm:text-4xl">School Diary</h1>
                            <p class="mt-2 max-w-2xl text-white/80">Track academic events, school programs, competitions, and co-curricular activities in one responsive workspace.</p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <a href="#diary-filters" class="inline-flex items-center gap-2 rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold transition hover:bg-white/20">
                                <i class="fas fa-sliders-h"></i>
                                Filter diary
                            </a>
                            <a href="#calendar-panel" class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-teal-900 shadow-soft transition hover:bg-white/90">
                                <i class="fas fa-calendar-alt"></i>
                                Open calendar
                            </a>
                        </div>
                    </div>
                </div>
                <div class="grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-4">
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4 shadow-soft">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Activities</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo count($activities); ?></p>
                    </div>
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4 shadow-soft">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Upcoming</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo $upcoming_count; ?></p>
                    </div>
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4 shadow-soft">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Completed</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo $completed_count; ?></p>
                    </div>
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4 shadow-soft">
                        <p class="text-xs uppercase tracking-wide text-slate-500">This Month</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo $this_month_count; ?></p>
                    </div>
                </div>
            </section>

            <?php if ($selected_activity): ?>
                <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="space-y-3">
                            <a href="<?php echo htmlspecialchars($list_url); ?>" class="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600">
                                <i class="fas fa-arrow-left"></i>
                                Back to diary
                            </a>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo diary_type_classes($selected_activity['activity_type']); ?>">
                                    <i class="fas fa-tag"></i>
                                    <?php echo htmlspecialchars($selected_activity['activity_type']); ?>
                                </span>
                                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo diary_status_classes($selected_activity['status']); ?>">
                                    <i class="fas fa-signal"></i>
                                    <?php echo htmlspecialchars($selected_activity['status']); ?>
                                </span>
                            </div>
                            <h2 class="text-3xl font-display text-ink-900"><?php echo htmlspecialchars($selected_activity['activity_title']); ?></h2>
                            <p class="max-w-3xl text-slate-600"><?php echo diary_excerpt($selected_activity['description'] ?? '', 220); ?></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="school_diary.php?<?php echo htmlspecialchars(http_build_query(array_merge($filterState, ['id' => $selected_activity['id'], 'pdf' => 1]))); ?>" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-ink-900/10 bg-white px-4 py-2 text-sm font-semibold text-slate-600 transition hover:border-teal-600/40 hover:bg-teal-600/10 hover:text-teal-700">
                                <i class="fas fa-download"></i>
                                PDF report
                            </a>
                            <a href="#calendar-panel" class="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-600">
                                <i class="fas fa-calendar-day"></i>
                                Find in calendar
                            </a>
                        </div>
                    </div>
                </section>

                <section class="grid gap-6 lg:grid-cols-[1.55fr_1fr]">
                    <div class="space-y-6">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-ink-900/5 bg-white p-5 shadow-soft">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                        <i class="fas fa-calendar-alt"></i>
                                    </span>
                                    <div>
                                        <p class="text-sm text-slate-500">Activity Date</p>
                                        <p class="text-lg font-semibold text-ink-900"><?php echo date('D, M j, Y', strtotime($selected_activity['activity_date'])); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-ink-900/5 bg-white p-5 shadow-soft">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-sky-100 text-sky-700">
                                        <i class="fas fa-clock"></i>
                                    </span>
                                    <div>
                                        <p class="text-sm text-slate-500">Time Window</p>
                                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(diary_format_time_range($selected_activity['start_time'], $selected_activity['end_time'])); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-ink-900/5 bg-white p-5 shadow-soft">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                                        <i class="fas fa-users"></i>
                                    </span>
                                    <div>
                                        <p class="text-sm text-slate-500">Audience</p>
                                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars($selected_activity['target_audience'] ?: 'General audience'); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-ink-900/5 bg-white p-5 shadow-soft">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
                                        <i class="fas fa-paperclip"></i>
                                    </span>
                                    <div>
                                        <p class="text-sm text-slate-500">Attachments</p>
                                        <p class="text-lg font-semibold text-ink-900"><?php echo count($attachments); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                            <h3 class="mb-4 text-xl font-semibold text-ink-900">Activity Overview</h3>
                            <div class="space-y-5">
                                <?php if (!empty($selected_activity['description'])): ?>
                                    <div>
                                        <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Description</h4>
                                        <div class="rounded-2xl bg-mist-50 p-4 text-sm leading-7 text-slate-700"><?php echo nl2br(htmlspecialchars($selected_activity['description'])); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($selected_activity['objectives'])): ?>
                                    <div>
                                        <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Objectives</h4>
                                        <div class="rounded-2xl bg-mist-50 p-4 text-sm leading-7 text-slate-700"><?php echo nl2br(htmlspecialchars($selected_activity['objectives'])); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($selected_activity['resources'])): ?>
                                    <div>
                                        <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Resources Required</h4>
                                        <div class="rounded-2xl bg-mist-50 p-4 text-sm leading-7 text-slate-700"><?php echo nl2br(htmlspecialchars($selected_activity['resources'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (($selected_activity['status'] ?? '') === 'Completed' && (!empty($selected_activity['participant_count']) || !empty($selected_activity['winners_list']) || !empty($selected_activity['achievements']) || !empty($selected_activity['feedback_summary']))): ?>
                            <div class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                                <h3 class="mb-4 text-xl font-semibold text-ink-900">Completion Notes</h3>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <?php if (!empty($selected_activity['participant_count'])): ?>
                                        <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4">
                                            <p class="text-xs uppercase tracking-wide text-slate-500">Participants</p>
                                            <p class="mt-2 text-2xl font-semibold text-ink-900"><?php echo (int) $selected_activity['participant_count']; ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($selected_activity['winners_list'])): ?>
                                        <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4">
                                            <p class="text-xs uppercase tracking-wide text-slate-500">Recognized Winners</p>
                                            <p class="mt-2 text-2xl font-semibold text-ink-900"><?php echo diary_count_winners($selected_activity['winners_list']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-5 space-y-4">
                                    <?php if (!empty($selected_activity['winners_list'])): ?>
                                        <div>
                                            <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Winners and Awards</h4>
                                            <div class="rounded-2xl bg-mist-50 p-4 text-sm leading-7 text-slate-700"><?php echo nl2br(htmlspecialchars($selected_activity['winners_list'])); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($selected_activity['achievements'])): ?>
                                        <div>
                                            <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Achievements</h4>
                                            <div class="rounded-2xl bg-mist-50 p-4 text-sm leading-7 text-slate-700"><?php echo nl2br(htmlspecialchars($selected_activity['achievements'])); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($selected_activity['feedback_summary'])): ?>
                                        <div>
                                            <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Feedback Summary</h4>
                                            <div class="rounded-2xl bg-mist-50 p-4 text-sm leading-7 text-slate-700"><?php echo nl2br(htmlspecialchars($selected_activity['feedback_summary'])); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($attachments)): ?>
                            <div class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                                <div class="mb-4 flex items-center justify-between">
                                    <h3 class="text-xl font-semibold text-ink-900">Attachments</h3>
                                    <span class="text-xs uppercase tracking-wide text-slate-500"><?php echo count($attachments); ?> files</span>
                                </div>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <?php foreach ($attachments as $attachment): ?>
                                        <?php $isImage = strtolower((string) $attachment['file_type']) === 'image'; ?>
                                        <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank" class="overflow-hidden rounded-2xl border border-ink-900/5 bg-white shadow-soft transition hover:-translate-y-1 hover:shadow-lift">
                                            <div class="h-40 bg-sky-50">
                                                <?php if ($isImage): ?>
                                                    <img src="<?php echo htmlspecialchars($attachment['file_path']); ?>" alt="<?php echo htmlspecialchars($attachment['file_name']); ?>" class="h-full w-full object-cover">
                                                <?php else: ?>
                                                    <div class="flex h-full items-center justify-center text-slate-400">
                                                        <i class="fas fa-file-lines text-4xl"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="p-4">
                                                <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($attachment['file_name']); ?></p>
                                                <p class="mt-1 text-sm text-slate-500"><?php echo strtoupper(htmlspecialchars((string) $attachment['file_type'])); ?> attachment</p>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <aside class="space-y-4">
                        <div class="rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft">
                            <h3 class="mb-4 text-lg font-semibold text-ink-900">Diary Snapshot</h3>
                            <div class="space-y-3 text-sm text-slate-600">
                                <div class="flex items-start justify-between gap-4">
                                    <span class="font-semibold text-ink-900">Venue</span>
                                    <span class="text-right"><?php echo htmlspecialchars($selected_activity['venue'] ?: 'Not specified'); ?></span>
                                </div>
                                <div class="flex items-start justify-between gap-4">
                                    <span class="font-semibold text-ink-900">Coordinator</span>
                                    <span class="text-right"><?php echo htmlspecialchars($selected_activity['coordinator_name'] ?: 'Not assigned'); ?></span>
                                </div>
                                <?php if (!empty($selected_activity['organizing_dept'])): ?>
                                    <div class="flex items-start justify-between gap-4">
                                        <span class="font-semibold text-ink-900">Department</span>
                                        <span class="text-right"><?php echo htmlspecialchars($selected_activity['organizing_dept']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($selected_activity['target_classes'])): ?>
                                    <div class="flex items-start justify-between gap-4">
                                        <span class="font-semibold text-ink-900">Target Classes</span>
                                        <span class="text-right"><?php echo htmlspecialchars($selected_activity['target_classes']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft">
                            <h3 class="mb-4 text-lg font-semibold text-ink-900">Record Metadata</h3>
                            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                                <div class="rounded-2xl bg-mist-50 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">Views</p>
                                    <p class="mt-2 text-xl font-semibold text-ink-900"><?php echo (int) $selected_activity['view_count']; ?></p>
                                </div>
                                <div class="rounded-2xl bg-mist-50 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">Created</p>
                                    <p class="mt-2 text-sm font-semibold text-ink-900"><?php echo date('M j, Y', strtotime($selected_activity['created_at'])); ?></p>
                                </div>
                                <div class="rounded-2xl bg-mist-50 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">Updated</p>
                                    <p class="mt-2 text-sm font-semibold text-ink-900"><?php echo date('M j, Y', strtotime($selected_activity['updated_at'] ?: $selected_activity['created_at'])); ?></p>
                                </div>
                            </div>
                        </div>
                    </aside>
                </section>
            <?php endif; ?>

            <section id="diary-filters" class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-ink-900">Diary Explorer</h2>
                        <p class="text-sm text-slate-500">Refine activities by type, date window, and keywords.</p>
                    </div>
                    <?php if (!empty($filterState)): ?>
                        <a href="school_diary.php" class="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600">
                            <i class="fas fa-rotate-left"></i>
                            Clear filters
                        </a>
                    <?php endif; ?>
                </div>

                <form method="GET" class="grid gap-4 md:grid-cols-2 xl:grid-cols-[1.6fr_1fr_1fr_1fr_auto]">
                    <label class="grid gap-2">
                        <span class="text-sm font-semibold text-ink-900">Search activities</span>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-search"></i></span>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Title, description, or venue" class="w-full rounded-xl border border-ink-900/10 bg-white px-10 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                    </label>

                    <label class="grid gap-2">
                        <span class="text-sm font-semibold text-ink-900">Activity type</span>
                        <select name="type" class="rounded-xl border border-ink-900/10 bg-white px-4 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            <option value="">All types</option>
                            <?php foreach ($activity_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $type_filter === $type ? 'selected' : ''; ?>><?php echo htmlspecialchars($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="grid gap-2">
                        <span class="text-sm font-semibold text-ink-900">From date</span>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="rounded-xl border border-ink-900/10 bg-white px-4 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                    </label>

                    <label class="grid gap-2">
                        <span class="text-sm font-semibold text-ink-900">To date</span>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="rounded-xl border border-ink-900/10 bg-white px-4 py-3 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                    </label>

                    <div class="flex items-end gap-2">
                        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-3 text-sm font-semibold text-white transition hover:bg-teal-600">
                            <i class="fas fa-filter"></i>
                            Apply
                        </button>
                    </div>
                </form>
            </section>

            <section class="space-y-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-ink-900">Activity Schedule</h2>
                        <p class="text-sm text-slate-500"><?php echo count($activities); ?> activities match the current diary view.</p>
                    </div>
                    <div class="inline-flex rounded-full border border-ink-900/10 bg-white p-1 shadow-soft">
                        <button type="button" class="view-toggle-btn inline-flex items-center gap-2 rounded-full bg-teal-700 px-4 py-2 text-sm font-semibold text-white" data-view-target="list-panel">
                            <i class="fas fa-list"></i>
                            List
                        </button>
                        <button type="button" class="view-toggle-btn inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold text-slate-600 transition hover:text-teal-700" data-view-target="calendar-panel">
                            <i class="fas fa-calendar-alt"></i>
                            Calendar
                        </button>
                    </div>
                </div>

                <div id="list-panel" class="view-panel">
                    <?php if (empty($activities)): ?>
                        <div class="rounded-3xl border border-dashed border-ink-900/15 bg-white p-10 text-center shadow-soft">
                            <div class="mx-auto mb-4 inline-flex h-14 w-14 items-center justify-center rounded-full bg-teal-50 text-teal-700">
                                <i class="fas fa-calendar-xmark text-xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-ink-900">No activities found</h3>
                            <p class="mt-2 text-sm text-slate-500">Adjust the filters or return later when new school diary entries are published.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid gap-4 xl:grid-cols-2">
                            <?php foreach ($activities as $activity): ?>
                                <article class="rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft transition hover:-translate-y-1 hover:shadow-lift">
                                    <div class="flex flex-col gap-4">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="space-y-2">
                                                <div class="flex flex-wrap gap-2">
                                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo diary_type_classes($activity['activity_type']); ?>">
                                                        <i class="fas fa-tag"></i>
                                                        <?php echo htmlspecialchars($activity['activity_type']); ?>
                                                    </span>
                                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo diary_status_classes($activity['status']); ?>">
                                                        <i class="fas fa-signal"></i>
                                                        <?php echo htmlspecialchars($activity['status']); ?>
                                                    </span>
                                                </div>
                                                <h3 class="text-xl font-semibold text-ink-900"><?php echo htmlspecialchars($activity['activity_title']); ?></h3>
                                            </div>
                                            <span class="rounded-2xl bg-mist-50 px-3 py-2 text-right">
                                                <span class="block text-xs uppercase tracking-wide text-slate-500">Date</span>
                                                <span class="text-sm font-semibold text-ink-900"><?php echo date('M j, Y', strtotime($activity['activity_date'])); ?></span>
                                            </span>
                                        </div>

                                        <p class="text-sm leading-7 text-slate-600"><?php echo htmlspecialchars(diary_excerpt($activity['description'] ?? '', 170)); ?></p>

                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <div class="rounded-2xl bg-mist-50 p-4">
                                                <p class="text-xs uppercase tracking-wide text-slate-500">Venue</p>
                                                <p class="mt-1 text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($activity['venue'] ?: 'Venue to be confirmed'); ?></p>
                                            </div>
                                            <div class="rounded-2xl bg-mist-50 p-4">
                                                <p class="text-xs uppercase tracking-wide text-slate-500">Time</p>
                                                <p class="mt-1 text-sm font-semibold text-ink-900"><?php echo htmlspecialchars(diary_format_time_range($activity['start_time'], $activity['end_time'])); ?></p>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-ink-900/5 pt-4">
                                            <div class="flex flex-wrap items-center gap-3 text-sm text-slate-500">
                                                <span class="inline-flex items-center gap-2"><i class="fas fa-user"></i><?php echo htmlspecialchars($activity['coordinator_name'] ?: 'Not assigned'); ?></span>
                                                <span class="inline-flex items-center gap-2"><i class="fas fa-eye"></i><?php echo (int) ($activity['view_count'] ?? 0); ?></span>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <a href="school_diary.php?<?php echo htmlspecialchars(http_build_query(array_merge($filterState, ['id' => $activity['id'], 'view' => 'details']))); ?>" class="inline-flex items-center gap-2 rounded-xl border border-ink-900/10 bg-white px-4 py-2 text-sm font-semibold text-slate-600 transition hover:border-teal-600/40 hover:bg-teal-600/10 hover:text-teal-700">
                                                    <i class="fas fa-eye"></i>
                                                    View details
                                                </a>
                                                <a href="school_diary.php?<?php echo htmlspecialchars(http_build_query(array_merge($filterState, ['id' => $activity['id'], 'pdf' => 1]))); ?>" target="_blank" class="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-600">
                                                    <i class="fas fa-download"></i>
                                                    PDF
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="calendar-panel" class="view-panel hidden rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft">
                    <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 class="text-xl font-semibold text-ink-900">Diary Calendar</h3>
                            <p class="text-sm text-slate-500">Browse the school diary month by month and open quick activity summaries.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-ink-900/10 bg-white text-slate-600 transition hover:border-teal-600/40 hover:bg-teal-600/10 hover:text-teal-700" id="prevMonthBtn">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button type="button" class="inline-flex items-center rounded-xl border border-ink-900/10 bg-white px-4 py-2 text-sm font-semibold text-slate-600 transition hover:border-teal-600/40 hover:bg-teal-600/10 hover:text-teal-700" id="todayBtn">Today</button>
                            <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-ink-900/10 bg-white text-slate-600 transition hover:border-teal-600/40 hover:bg-teal-600/10 hover:text-teal-700" id="nextMonthBtn">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h4 id="calendarMonthLabel" class="text-2xl font-display text-ink-900"></h4>
                        <div class="flex flex-wrap gap-2 text-xs font-semibold">
                            <?php foreach ($activity_types as $type): ?>
                                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 <?php echo diary_type_classes($type); ?>">
                                    <i class="fas fa-circle text-[8px]"></i>
                                    <?php echo htmlspecialchars($type); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <div>Sun</div>
                        <div>Mon</div>
                        <div>Tue</div>
                        <div>Wed</div>
                        <div>Thu</div>
                        <div>Fri</div>
                        <div>Sat</div>
                    </div>
                    <div id="calendarGrid" class="mt-3 grid grid-cols-7 gap-2"></div>
                </div>
            </section>
        </main>
    </div>

    <div id="activityModal" class="pointer-events-none fixed inset-0 z-50 flex items-end justify-center bg-black/40 px-4 opacity-0 transition-opacity sm:items-center">
        <div class="w-full max-w-2xl translate-y-6 rounded-3xl border border-ink-900/5 bg-white shadow-lift transition-transform duration-200" id="activityModalPanel">
            <div class="flex items-start justify-between gap-4 border-b border-ink-900/10 p-6">
                <div class="space-y-2">
                    <div id="modalBadges" class="flex flex-wrap gap-2"></div>
                    <h3 id="modalTitle" class="text-2xl font-display text-ink-900"></h3>
                    <p id="modalDescription" class="text-sm leading-7 text-slate-600"></p>
                </div>
                <button type="button" id="activityModalClose" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-ink-900/10 bg-white text-slate-600 transition hover:border-teal-600/40 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="grid gap-4 p-6 sm:grid-cols-2">
                <div class="rounded-2xl bg-mist-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Date</p>
                    <p id="modalDate" class="mt-2 text-sm font-semibold text-ink-900"></p>
                </div>
                <div class="rounded-2xl bg-mist-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Time</p>
                    <p id="modalTime" class="mt-2 text-sm font-semibold text-ink-900"></p>
                </div>
                <div class="rounded-2xl bg-mist-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Venue</p>
                    <p id="modalVenue" class="mt-2 text-sm font-semibold text-ink-900"></p>
                </div>
                <div class="rounded-2xl bg-mist-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Coordinator</p>
                    <p id="modalCoordinator" class="mt-2 text-sm font-semibold text-ink-900"></p>
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-ink-900/10 p-6">
                <a href="#" id="modalDetailLink" class="inline-flex items-center gap-2 rounded-xl border border-ink-900/10 bg-white px-4 py-2 text-sm font-semibold text-slate-600 transition hover:border-teal-600/40 hover:bg-teal-600/10 hover:text-teal-700">
                    <i class="fas fa-eye"></i>
                    View details
                </a>
                <a href="#" id="modalPdfLink" target="_blank" class="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-600">
                    <i class="fas fa-download"></i>
                    Download PDF
                </a>
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

        const viewButtons = document.querySelectorAll('.view-toggle-btn');
        const panels = document.querySelectorAll('.view-panel');

        const activatePanel = (targetId) => {
            panels.forEach((panel) => {
                panel.classList.toggle('hidden', panel.id !== targetId);
            });

            viewButtons.forEach((button) => {
                const isActive = button.dataset.viewTarget === targetId;
                button.classList.toggle('bg-teal-700', isActive);
                button.classList.toggle('text-white', isActive);
                button.classList.toggle('text-slate-600', !isActive);
            });
        };

        viewButtons.forEach((button) => {
            button.addEventListener('click', () => activatePanel(button.dataset.viewTarget));
        });

        const calendarActivities = <?php echo json_encode($calendarActivities, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const calendarGrid = document.getElementById('calendarGrid');
        const calendarMonthLabel = document.getElementById('calendarMonthLabel');
        const prevMonthBtn = document.getElementById('prevMonthBtn');
        const nextMonthBtn = document.getElementById('nextMonthBtn');
        const todayBtn = document.getElementById('todayBtn');
        let currentDate = new Date();

        const typeClassMap = {
            Academics: 'bg-teal-600/10 text-teal-700',
            Sports: 'bg-sky-100 text-sky-700',
            Cultural: 'bg-fuchsia-100 text-fuchsia-700',
            Competition: 'bg-amber-100 text-amber-700'
        };

        const statusClassMap = {
            Upcoming: 'bg-sky-100 text-sky-700',
            Ongoing: 'bg-amber-100 text-amber-700',
            Completed: 'bg-emerald-100 text-emerald-700',
            Cancelled: 'bg-rose-100 text-rose-700'
        };

        const escapeHtml = (value) => String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        const formatReadableDate = (value) => {
            const date = new Date(value + 'T00:00:00');
            return date.toLocaleDateString(undefined, {
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        };

        const renderCalendar = () => {
            if (!calendarGrid || !calendarMonthLabel) return;

            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const offset = firstDay.getDay();
            const totalDays = lastDay.getDate();
            const today = new Date();

            calendarMonthLabel.textContent = currentDate.toLocaleDateString(undefined, {
                month: 'long',
                year: 'numeric'
            });

            let html = '';
            for (let i = 0; i < offset; i++) {
                html += '<div class="min-h-[120px] rounded-2xl border border-dashed border-ink-900/10 bg-slate-50"></div>';
            }

            for (let day = 1; day <= totalDays; day++) {
                const isoDate = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const dayActivities = calendarActivities.filter((activity) => activity.date === isoDate);
                const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;

                html += `<div class="min-h-[120px] rounded-2xl border border-ink-900/5 bg-white p-2 shadow-soft ${isToday ? 'ring-2 ring-teal-100' : ''}">`;
                html += `<div class="mb-2 flex items-center justify-between"><span class="text-sm font-semibold text-ink-900">${day}</span>${isToday ? '<span class="rounded-full bg-teal-600/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-teal-700">Today</span>' : ''}</div>`;

                if (dayActivities.length === 0) {
                    html += '<div class="text-xs text-slate-400">No activities</div>';
                } else {
                    dayActivities.slice(0, 3).forEach((activity) => {
                        const badgeClass = typeClassMap[activity.type] || 'bg-slate-100 text-slate-600';
                        html += `<button type="button" class="calendar-activity mb-2 w-full rounded-xl px-2 py-2 text-left text-xs font-semibold ${badgeClass}" data-activity-id="${activity.id}">${escapeHtml(activity.title)}</button>`;
                    });

                    if (dayActivities.length > 3) {
                        html += `<div class="text-[11px] font-semibold text-slate-500">+${dayActivities.length - 3} more</div>`;
                    }
                }

                html += '</div>';
            }

            calendarGrid.innerHTML = html;
        };

        if (prevMonthBtn) {
            prevMonthBtn.addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() - 1);
                renderCalendar();
            });
        }

        if (nextMonthBtn) {
            nextMonthBtn.addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() + 1);
                renderCalendar();
            });
        }

        if (todayBtn) {
            todayBtn.addEventListener('click', () => {
                currentDate = new Date();
                renderCalendar();
            });
        }

        const activityModal = document.getElementById('activityModal');
        const activityModalPanel = document.getElementById('activityModalPanel');
        const activityModalClose = document.getElementById('activityModalClose');

        const openActivityModal = (activity) => {
            if (!activityModal || !activity) return;

            const typeClass = typeClassMap[activity.type] || 'bg-slate-100 text-slate-600';
            const statusClass = statusClassMap[activity.status] || 'bg-slate-100 text-slate-600';

            document.getElementById('modalBadges').innerHTML = `
                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ${typeClass}">
                    <i class="fas fa-tag"></i>${escapeHtml(activity.type)}
                </span>
                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ${statusClass}">
                    <i class="fas fa-signal"></i>${escapeHtml(activity.status)}
                </span>
            `;
            document.getElementById('modalTitle').textContent = activity.title;
            document.getElementById('modalDescription').textContent = activity.description;
            document.getElementById('modalDate').textContent = formatReadableDate(activity.date);
            document.getElementById('modalTime').textContent = activity.time;
            document.getElementById('modalVenue').textContent = activity.venue;
            document.getElementById('modalCoordinator').textContent = activity.coordinator;
            document.getElementById('modalDetailLink').href = activity.detailUrl;
            document.getElementById('modalPdfLink').href = activity.pdfUrl;

            activityModal.classList.remove('pointer-events-none', 'opacity-0');
            activityModal.classList.add('opacity-100');
            activityModalPanel.classList.remove('translate-y-6');
            body.classList.add('nav-open');
        };

        const closeActivityModal = () => {
            if (!activityModal) return;
            activityModal.classList.add('pointer-events-none', 'opacity-0');
            activityModal.classList.remove('opacity-100');
            activityModalPanel.classList.add('translate-y-6');
            body.classList.remove('nav-open');
        };

        if (activityModalClose) {
            activityModalClose.addEventListener('click', closeActivityModal);
        }

        if (activityModal) {
            activityModal.addEventListener('click', (event) => {
                if (event.target === activityModal) {
                    closeActivityModal();
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeActivityModal();
            }
        });

        document.addEventListener('click', (event) => {
            const button = event.target.closest('.calendar-activity');
            if (!button) return;

            const activityId = Number(button.dataset.activityId);
            const activity = calendarActivities.find((item) => item.id === activityId);
            openActivityModal(activity);
        });

        renderCalendar();
    </script>
</body>
</html>
