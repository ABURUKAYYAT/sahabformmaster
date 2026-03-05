<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Requests | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/teacher-workspace.css">
    <link rel="stylesheet" href="../assets/css/teacher-permissions.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="landing bg-slate-50 workspace-page permissions-page">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu"><span></span><span></span><span></span></button>
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
                <a class="btn btn-primary" href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto"><?php include '../includes/teacher_sidebar.php'; ?></div>
        </aside>

        <main class="space-y-6">
            <section class="overflow-hidden rounded-3xl border border-ink-900/5 shadow-lift">
                <div class="workspace-hero p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Staff Workflow</p>
                            <h1 class="mt-3 font-display text-3xl font-semibold leading-tight sm:text-4xl">Permission requests in a cleaner workflow.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">Submit requests, monitor approval status, and track outcomes from one consistent workspace.</p>
                        </div>
                        <div class="workspace-hero-actions grid gap-3 sm:grid-cols-2">
                            <button type="button" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95" onclick="openRequestModal()"><i class="fas fa-plus-circle"></i><span>New Request</span></button>
                            <button type="button" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15" onclick="exportRequests()"><i class="fas fa-file-arrow-down"></i><span>Export PDF</span></button>
                            <a href="#permission-requests" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15"><i class="fas fa-table-list"></i><span>View Requests</span></a>
                        </div>
                    </div>
                </div>
                <div class="workspace-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="workspace-metric-card"><div class="workspace-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-clipboard-list"></i></div><p class="text-xs uppercase tracking-wide text-slate-500">Total Requests</p><h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $totalRequests; ?></h2><p class="text-sm text-slate-500">All permission entries submitted</p></article>
                    <article class="workspace-metric-card"><div class="workspace-metric-icon bg-amber-500/10 text-amber-700"><i class="fas fa-clock"></i></div><p class="text-xs uppercase tracking-wide text-slate-500">Pending</p><h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $pendingRequests; ?></h2><p class="text-sm text-slate-500">Waiting for review decision</p></article>
                    <article class="workspace-metric-card"><div class="workspace-metric-icon bg-emerald-600/10 text-emerald-700"><i class="fas fa-circle-check"></i></div><p class="text-xs uppercase tracking-wide text-slate-500">Approved</p><h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $approvedRequests; ?></h2><p class="text-sm text-slate-500">Cleared by school management</p></article>
                    <article class="workspace-metric-card"><div class="workspace-metric-icon bg-rose-600/10 text-rose-700"><i class="fas fa-circle-xmark"></i></div><p class="text-xs uppercase tracking-wide text-slate-500">Rejected</p><h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $rejectedRequests; ?></h2><p class="text-sm text-slate-500">Requests that need revision</p></article>
                    <article class="workspace-metric-card"><div class="workspace-metric-icon bg-slate-500/10 text-slate-700"><i class="fas fa-ban"></i></div><p class="text-xs uppercase tracking-wide text-slate-500">Cancelled</p><h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $cancelledRequests; ?></h2><p class="text-sm text-slate-500">Withdrawn before review</p></article>
                </div>
            </section>

            <?php if ($error): ?><section class="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-800 shadow-soft"><p class="font-semibold">Action needs attention</p><p class="mt-1 text-sm"><?php echo htmlspecialchars($error); ?></p></section><?php endif; ?>
            <?php if ($message): ?><section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900 shadow-soft"><p class="font-semibold">Update completed</p><p class="mt-1 text-sm"><?php echo htmlspecialchars($message); ?></p></section><?php endif; ?>

            <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-soft">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div><p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Filters</p><h2 class="mt-2 text-2xl font-display text-ink-900">Quick Status View</h2><p class="mt-2 text-sm text-slate-600">Use quick filters to focus on one status without reloading this page.</p></div>
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3"><p class="text-xs uppercase tracking-wide text-slate-500">Visible Rows</p><p class="text-2xl font-semibold text-ink-900" id="visibleRequestCount"><?php echo count($requests); ?></p></div>
                </div>
                <div class="permission-filter-row mt-6">
                    <button type="button" class="btn btn-outline permission-filter-btn is-active" data-filter="all">All</button>
                    <button type="button" class="btn btn-outline permission-filter-btn" data-filter="pending">Pending</button>
                    <button type="button" class="btn btn-outline permission-filter-btn" data-filter="approved">Approved</button>
                    <button type="button" class="btn btn-outline permission-filter-btn" data-filter="rejected">Rejected</button>
                    <button type="button" class="btn btn-outline permission-filter-btn" data-filter="cancelled">Cancelled</button>
                </div>
            </section>

            <section class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift" id="permission-requests">
                <div><p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Request Register</p><h2 class="mt-2 text-2xl font-display text-ink-900">My Permission Requests</h2><p class="mt-2 text-sm text-slate-600">Review details, track approvals, and cancel pending entries when needed.</p></div>
                <div class="workspace-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10">
                    <table class="workspace-table permissions-table min-w-[1080px] w-full bg-white text-sm">
                        <thead><tr class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500"><th class="px-4 py-4">ID</th><th class="px-4 py-4">Type</th><th class="px-4 py-4">Title</th><th class="px-4 py-4">Date</th><th class="px-4 py-4">Duration</th><th class="px-4 py-4">Priority</th><th class="px-4 py-4">Status</th><th class="px-4 py-4">Approved By</th><th class="px-4 py-4">Actions</th></tr></thead>
                        <tbody>
                            <?php if (empty($requests)): ?>
                                <tr><td colspan="9" class="permission-empty-cell px-4 py-10 text-center text-slate-500"><i class="fas fa-clipboard-list text-xl text-slate-400"></i><p class="mt-3 text-lg font-semibold text-ink-900">No permission requests found</p><p class="mt-1 text-sm text-slate-500">Use "New Request" to submit your first entry.</p></td></tr>
                            <?php else: ?>
                                <?php foreach ($requests as $request): ?>
                                    <?php
                                    $status_key = strtolower((string) ($request['status'] ?? 'pending'));
                                    $priority_key = strtolower((string) ($request['priority'] ?? 'medium'));
                                    $status_class = $status_badge_classes[$status_key] ?? 'status-pending';
                                    $priority_class = $priority_badge_classes[$priority_key] ?? 'priority-medium';
                                    $dateLabel = date('M d, Y', strtotime((string) $request['start_date']));
                                    if (!empty($request['end_date'])) { $dateLabel .= ' - ' . date('M d, Y', strtotime((string) $request['end_date'])); }
                                    $request_type_label = ucfirst(str_replace('_', ' ', (string) $request['request_type']));
                                    $duration_label = $request['duration_hours'] ? $request['duration_hours'] . ' hours' : 'Full day';
                                    ?>
                                    <tr class="request-row border-t border-slate-100" data-id="<?php echo (int) $request['id']; ?>" data-status-key="<?php echo htmlspecialchars($status_key); ?>" data-type="<?php echo htmlspecialchars($request_type_label, ENT_QUOTES, 'UTF-8'); ?>" data-title="<?php echo htmlspecialchars((string) $request['title'], ENT_QUOTES, 'UTF-8'); ?>" data-date="<?php echo htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8'); ?>" data-duration="<?php echo htmlspecialchars($duration_label, ENT_QUOTES, 'UTF-8'); ?>" data-priority="<?php echo htmlspecialchars(ucfirst($priority_key), ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars(ucfirst($status_key), ENT_QUOTES, 'UTF-8'); ?>" data-approved-by="<?php echo htmlspecialchars((string) ($request['approved_by_name'] ?: 'Not approved'), ENT_QUOTES, 'UTF-8'); ?>" data-description="<?php echo htmlspecialchars((string) ($request['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-rejection-reason="<?php echo htmlspecialchars((string) ($request['rejection_reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-approved-at="<?php echo htmlspecialchars((string) ($request['approved_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-attachment-path="<?php echo htmlspecialchars((string) ($request['attachment_path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <td class="px-4 py-4 font-semibold text-ink-900">#<?php echo str_pad((string) $request['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                        <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($request_type_label); ?></td>
                                        <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars((string) $request['title']); ?></td>
                                        <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($dateLabel); ?></td>
                                        <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars($duration_label); ?></td>
                                        <td class="px-4 py-4"><span class="permission-badge <?php echo htmlspecialchars($priority_class); ?>"><?php echo htmlspecialchars(ucfirst($priority_key)); ?></span></td>
                                        <td class="px-4 py-4"><span class="permission-badge <?php echo htmlspecialchars($status_class); ?>"><?php echo htmlspecialchars(ucfirst($status_key)); ?></span></td>
                                        <td class="px-4 py-4 text-slate-700"><?php echo htmlspecialchars((string) ($request['approved_by_name'] ?: 'Not approved')); ?><?php if (!empty($request['approved_at'])): ?><p class="mt-1 text-xs text-slate-500"><?php echo date('M d, Y', strtotime((string) $request['approved_at'])); ?></p><?php endif; ?></td>
                                        <td class="px-4 py-4 action-cell"><div class="flex min-w-[190px] flex-col gap-2"><button type="button" class="btn btn-outline !px-3 !py-2" onclick="viewRequestDetails(<?php echo (int) $request['id']; ?>)"><i class="fas fa-eye"></i><span>View</span></button><?php if ($status_key === 'pending'): ?><button type="button" class="btn btn-outline !px-3 !py-2 permission-cancel-btn" onclick="cancelRequest(<?php echo (int) $request['id']; ?>)"><i class="fas fa-times"></i><span>Cancel</span></button><?php endif; ?></div></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to inspect all columns on smaller screens.</span></p>
            </section>
        </main>
    </div>

    <div id="requestModal" class="workspace-modal" role="dialog" aria-modal="true" aria-labelledby="requestModalTitle">
        <div class="workspace-modal-card rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift">
            <div class="flex items-start justify-between gap-4">
                <div><p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">New Entry</p><h3 id="requestModalTitle" class="mt-2 text-2xl font-display text-ink-900">Create Permission Request</h3></div>
                <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700 hover:bg-slate-200" onclick="closeRequestModal()" aria-label="Close request modal"><i class="fas fa-xmark"></i></button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="mt-6 space-y-5">
                <div class="permission-form-grid">
                    <div><label class="permission-label">Request Type *</label><select name="request_type" class="permission-input" required><option value="">Select type</option><option value="sick_leave">Sick Leave</option><option value="personal_leave">Personal Leave</option><option value="medical_appointment">Medical Appointment</option><option value="emergency">Emergency</option><option value="other">Other</option></select></div>
                    <div><label class="permission-label">Priority *</label><select name="priority" class="permission-input" required><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select></div>
                    <div class="md:col-span-2"><label class="permission-label">Title *</label><input type="text" name="title" class="permission-input" placeholder="Brief title for your request" required></div>
                    <div class="md:col-span-2"><label class="permission-label">Description *</label><textarea name="description" class="permission-input permission-textarea" rows="4" placeholder="Provide details about your request..." required></textarea></div>
                    <div><label class="permission-label">Start Date & Time *</label><input type="datetime-local" name="start_date" class="permission-input" required></div>
                    <div><label class="permission-label">End Date & Time (Optional)</label><input type="datetime-local" name="end_date" class="permission-input"></div>
                    <div><label class="permission-label">Duration (hours)</label><input type="number" name="duration_hours" class="permission-input" min="1" placeholder="Leave empty for full day"></div>
                    <div><label class="permission-label">Attachment (Optional)</label><input type="file" name="attachment" class="permission-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif"><p id="permissionFileName" class="permission-file-note"></p></div>
                </div>
                <div class="flex flex-wrap justify-end gap-3"><button type="button" class="btn btn-outline" onclick="closeRequestModal()"><i class="fas fa-xmark"></i><span>Cancel</span></button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i><span>Submit Request</span></button></div>
            </form>
        </div>
    </div>

    <div id="detailsModal" class="workspace-modal" role="dialog" aria-modal="true" aria-labelledby="detailsModalTitle">
        <div class="workspace-modal-card rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift">
            <div class="flex items-start justify-between gap-4">
                <div><p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Details</p><h3 id="detailsModalTitle" class="mt-2 text-2xl font-display text-ink-900">Permission Request Details</h3></div>
                <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700 hover:bg-slate-200" onclick="closeDetailsModal()" aria-label="Close details modal"><i class="fas fa-xmark"></i></button>
            </div>
            <div id="detailsContent" class="permission-details-stack mt-6"></div>
            <div class="mt-6 flex justify-end"><button type="button" class="btn btn-outline" onclick="closeDetailsModal()"><i class="fas fa-arrow-left"></i><span>Close</span></button></div>
        </div>
    </div>

    <?php include '../includes/floating-button.php'; ?>
    <script src="../assets/js/teacher-permissions.js"></script>
</body>
</html>
