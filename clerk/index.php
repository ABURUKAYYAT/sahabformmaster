<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'clerk') {
    header('Location: ../index.php');
    exit;
}

$current_school_id = require_school_auth();
PaymentHelper::ensureSchema();

$clerk_name = $_SESSION['full_name'] ?? 'Clerk';

/**
 * Normalize payment statuses to fixed dashboard buckets.
 */
function normalize_payment_status(string $status): string
{
    $status_key = strtolower(trim($status));
    if ($status_key === 'verified') {
        return 'verified';
    }
    if ($status_key === 'partial') {
        return 'partial';
    }
    if ($status_key === 'completed') {
        return 'completed';
    }
    if ($status_key === 'rejected' || $status_key === 'failed' || $status_key === 'cancelled') {
        return 'rejected';
    }
    if ($status_key === 'pending' || $status_key === '' || $status_key === 'submitted') {
        return 'pending';
    }

    return 'other';
}

function money_format_ngn(float $amount): string
{
    return 'NGN ' . number_format($amount, 2);
}

$status_counts = [
    'pending' => 0,
    'verified' => 0,
    'partial' => 0,
    'completed' => 0,
    'rejected' => 0,
    'other' => 0,
];

$status_amounts = [
    'pending' => 0.0,
    'verified' => 0.0,
    'partial' => 0.0,
    'completed' => 0.0,
    'rejected' => 0.0,
    'other' => 0.0,
];

$status_stmt = $pdo->prepare("
    SELECT
        COALESCE(NULLIF(LOWER(TRIM(status)), ''), 'pending') AS status_key,
        COUNT(*) AS total_count,
        SUM(COALESCE(amount_paid, 0)) AS total_amount
    FROM student_payments
    WHERE school_id = ?
    GROUP BY status_key
");
$status_stmt->execute([$current_school_id]);
$status_rows = $status_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($status_rows as $row) {
    $bucket = normalize_payment_status((string) ($row['status_key'] ?? ''));
    $status_counts[$bucket] += (int) ($row['total_count'] ?? 0);
    $status_amounts[$bucket] += (float) ($row['total_amount'] ?? 0);
}

$total_payment_records = array_sum($status_counts);
$verified_total_count = $status_counts['verified'] + $status_counts['partial'] + $status_counts['completed'];
$verified_total_amount = $status_amounts['verified'] + $status_amounts['partial'] + $status_amounts['completed'];
$pending_total_count = $status_counts['pending'];
$pending_total_amount = $status_amounts['pending'];
$rejected_total_count = $status_counts['rejected'];
$completion_rate = $total_payment_records > 0 ? round(($status_counts['completed'] / $total_payment_records) * 100, 1) : 0;
$clearance_rate = $total_payment_records > 0 ? round(($verified_total_count / $total_payment_records) * 100, 1) : 0;
$average_verified_payment = $verified_total_count > 0 ? round($verified_total_amount / $verified_total_count, 2) : 0;

$students_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ?");
$students_stmt->execute([$current_school_id]);
$student_count = (int) $students_stmt->fetchColumn();

$classes_stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ?");
$classes_stmt->execute([$current_school_id]);
$class_count = (int) $classes_stmt->fetchColumn();

$fees_stmt = $pdo->prepare("SELECT COUNT(*) FROM fee_structure WHERE school_id = ? AND is_active = 1");
$fees_stmt->execute([$current_school_id]);
$fee_count = (int) $fees_stmt->fetchColumn();

$monthly_stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(payment_date, '%Y-%m') AS month_key,
        COUNT(*) AS submission_count,
        SUM(
            CASE
                WHEN COALESCE(NULLIF(LOWER(TRIM(status)), ''), 'pending') IN ('verified', 'partial', 'completed')
                THEN COALESCE(amount_paid, 0)
                ELSE 0
            END
        ) AS cleared_amount,
        SUM(
            CASE
                WHEN COALESCE(NULLIF(LOWER(TRIM(status)), ''), 'pending') = 'pending'
                THEN COALESCE(amount_paid, 0)
                ELSE 0
            END
        ) AS pending_amount
    FROM student_payments
    WHERE school_id = ?
      AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
");
$monthly_stmt->execute([$current_school_id]);
$monthly_rows = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

$monthly_index = [];
foreach ($monthly_rows as $row) {
    $monthly_index[$row['month_key']] = [
        'submissions' => (int) ($row['submission_count'] ?? 0),
        'cleared' => round((float) ($row['cleared_amount'] ?? 0), 2),
        'pending' => round((float) ($row['pending_amount'] ?? 0), 2),
    ];
}

$monthly_labels = [];
$monthly_submission_series = [];
$monthly_cleared_series = [];
$monthly_pending_series = [];
$month_cursor = new DateTime('first day of this month');
$month_cursor->modify('-5 months');
for ($i = 0; $i < 6; $i++) {
    $month_key = $month_cursor->format('Y-m');
    $monthly_labels[] = $month_cursor->format('M Y');
    $monthly_submission_series[] = $monthly_index[$month_key]['submissions'] ?? 0;
    $monthly_cleared_series[] = $monthly_index[$month_key]['cleared'] ?? 0;
    $monthly_pending_series[] = $monthly_index[$month_key]['pending'] ?? 0;
    $month_cursor->modify('+1 month');
}

$method_stmt = $pdo->prepare("
    SELECT
        COALESCE(NULLIF(LOWER(TRIM(payment_method)), ''), 'unknown') AS method_key,
        COUNT(*) AS total_count
    FROM student_payments
    WHERE school_id = ?
    GROUP BY method_key
    ORDER BY total_count DESC
    LIMIT 6
");
$method_stmt->execute([$current_school_id]);
$method_rows = $method_stmt->fetchAll(PDO::FETCH_ASSOC);

$method_labels = [];
$method_values = [];
foreach ($method_rows as $row) {
    $method_key = (string) ($row['method_key'] ?? 'unknown');
    $method_labels[] = ucwords(str_replace(['_', '-'], ' ', $method_key));
    $method_values[] = (int) ($row['total_count'] ?? 0);
}

$fee_stmt = $pdo->prepare("
    SELECT
        COALESCE(NULLIF(LOWER(TRIM(fee_type)), ''), 'other') AS fee_key,
        SUM(
            CASE
                WHEN COALESCE(NULLIF(LOWER(TRIM(status)), ''), 'pending') IN ('verified', 'partial', 'completed')
                THEN COALESCE(amount_paid, 0)
                ELSE 0
            END
        ) AS collected_amount
    FROM student_payments
    WHERE school_id = ?
    GROUP BY fee_key
    ORDER BY collected_amount DESC
    LIMIT 6
");
$fee_stmt->execute([$current_school_id]);
$fee_rows = $fee_stmt->fetchAll(PDO::FETCH_ASSOC);

$fee_labels = [];
$fee_values = [];
foreach ($fee_rows as $row) {
    $fee_key = (string) ($row['fee_key'] ?? 'other');
    $fee_labels[] = ucwords(str_replace(['_', '-'], ' ', $fee_key));
    $fee_values[] = round((float) ($row['collected_amount'] ?? 0), 2);
}

$recent_stmt = $pdo->prepare("
    SELECT
        sp.id,
        COALESCE(NULLIF(LOWER(TRIM(sp.status)), ''), 'pending') AS status_key,
        sp.amount_paid,
        sp.payment_date,
        sp.receipt_number,
        s.full_name AS student_name,
        c.class_name
    FROM student_payments sp
    LEFT JOIN students s ON sp.student_id = s.id
    LEFT JOIN classes c ON c.id = sp.class_id AND c.school_id = sp.school_id
    WHERE sp.school_id = ?
    ORDER BY sp.payment_date DESC, sp.id DESC
    LIMIT 6
");
$recent_stmt->execute([$current_school_id]);
$recent_payments = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

$analytics_payload = [
    'status' => [
        'labels' => ['Pending', 'Verified', 'Partial', 'Completed', 'Rejected', 'Other'],
        'values' => [
            $status_counts['pending'],
            $status_counts['verified'],
            $status_counts['partial'],
            $status_counts['completed'],
            $status_counts['rejected'],
            $status_counts['other'],
        ],
    ],
    'monthly' => [
        'labels' => $monthly_labels,
        'submissions' => $monthly_submission_series,
        'cleared' => $monthly_cleared_series,
        'pending' => $monthly_pending_series,
    ],
    'methods' => [
        'labels' => $method_labels,
        'values' => $method_values,
    ],
    'fees' => [
        'labels' => $fee_labels,
        'values' => $fee_values,
    ],
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clerk Dashboard | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .clerk-dashboard {
            overflow-x: hidden;
        }

        .clerk-dashboard .dashboard-shell {
            z-index: auto;
        }

        body.nav-open {
            overflow: hidden;
        }

        [data-sidebar] {
            overflow: hidden;
        }

        [data-sidebar-overlay] {
            z-index: 35;
        }

        .mobile-money-value {
            line-height: 1.25;
            word-break: break-word;
        }

        .recent-activity-line {
            word-break: break-word;
        }

        .sidebar-scroll-shell {
            height: 100%;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-y: contain;
            touch-action: pan-y;
            padding-bottom: max(1rem, env(safe-area-inset-bottom));
        }

        .analytics-chart-wrap {
            position: relative;
            height: 18rem;
        }

        @media (max-width: 1024px) {
            .clerk-dashboard .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }

        @media (max-width: 640px) {
            .clerk-dashboard main {
                padding-top: 0;
                padding-bottom: 0;
                gap: 1rem;
            }

            .clerk-dashboard section {
                border-radius: 1.1rem;
                padding: 1rem;
            }

            .clerk-dashboard .nav-wrap {
                gap: 0.65rem;
            }

            .clerk-dashboard .workspace-header-actions {
                margin-left: auto;
                gap: 0.45rem;
            }

            .clerk-dashboard .workspace-header-actions .btn {
                padding: 0.45rem 0.7rem;
                font-size: 0.75rem;
                line-height: 1.05;
            }

            .clerk-dashboard .workspace-header-actions .btn-outline {
                display: none;
            }

            .clerk-dashboard .workspace-header-actions .btn span {
                display: none;
            }

            .clerk-dashboard .workspace-header-actions .btn i {
                margin: 0;
            }

            .clerk-dashboard .rounded-2xl {
                border-radius: 0.95rem;
            }

            .clerk-dashboard h1.text-3xl {
                font-size: 1.55rem;
                line-height: 1.25;
            }

            .clerk-dashboard h2.text-2xl {
                font-size: 1.35rem;
                line-height: 1.3;
            }

            .clerk-dashboard [data-sidebar] {
                width: min(18rem, 86vw);
            }

            .analytics-chart-wrap {
                height: 14rem;
            }
        }
    </style>
</head>
<body class="landing clerk-dashboard">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="w-10 h-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Clerk Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="workspace-header-actions flex items-center gap-3">
                <span class="hidden text-sm text-slate-600 md:block">Welcome, <?php echo htmlspecialchars($clerk_name); ?></span>
                <a class="btn btn-outline" href="payments.php">Payments</a>
                <a class="btn btn-primary" href="logout.php" aria-label="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container dashboard-shell grid gap-6 lg:grid-cols-[280px_1fr] py-8">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto">
                <div class="p-6 border-b border-ink-900/10">
                    <h2 class="text-lg font-semibold text-ink-900">Navigation</h2>
                    <p class="text-sm text-slate-500">Clerk workspace</p>
                </div>
                <nav class="p-4 space-y-1 text-sm">
                    <a href="index.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold bg-teal-600/10 text-teal-700">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="payments.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-money-check-alt"></i>
                        <span>Payments</span>
                    </a>
                    <a href="fee_structure.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-layer-group"></i>
                        <span>Fee Structure</span>
                    </a>
                    <a href="receipt.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-receipt"></i>
                        <span>Receipts</span>
                    </a>
                </nav>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 class="text-3xl font-display text-ink-900">Welcome back, <?php echo htmlspecialchars($clerk_name); ?></h1>
                        <p class="text-slate-600">School payment operations at a glance, with real-time tracking and analytics.</p>
                    </div>
                    <div class="grid w-full gap-3 sm:grid-cols-3 lg:w-auto">
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Students</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($student_count); ?></p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Classes</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($class_count); ?></p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Active Fees</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($fee_count); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-display text-ink-900">Payment Analytics</h2>
                        <p class="text-sm text-slate-600">Status quality, collection trend, and payment channel mix for this school.</p>
                    </div>
                    <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php">Open payment workspace</a>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-6">
                    <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Verified Collection</p>
                        <p class="text-2xl font-semibold text-ink-900 mobile-money-value"><?php echo htmlspecialchars(money_format_ngn($verified_total_amount)); ?></p>
                        <p class="text-xs text-slate-500 mt-1">Completed, verified, and partial payments</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Clearance Rate</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($clearance_rate, 1); ?>%</p>
                        <p class="text-xs text-slate-500 mt-1">Share of records no longer pending</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Pending Queue</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($pending_total_count); ?></p>
                        <p class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars(money_format_ngn($pending_total_amount)); ?> awaiting action</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Average Ticket</p>
                        <p class="text-2xl font-semibold text-ink-900 mobile-money-value"><?php echo htmlspecialchars(money_format_ngn($average_verified_payment)); ?></p>
                        <p class="text-xs text-slate-500 mt-1">Average amount per cleared payment</p>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <article class="rounded-2xl border border-ink-900/10 bg-white p-4">
                        <h3 class="text-base font-semibold text-ink-900">Payment Status Mix</h3>
                        <p class="text-xs text-slate-500 mt-1 mb-3">How payment records are currently distributed by workflow status.</p>
                        <div class="analytics-chart-wrap">
                            <canvas id="paymentStatusChart" class="h-full w-full"></canvas>
                            <p id="paymentStatusChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No payment status data available yet.</p>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-ink-900/10 bg-white p-4">
                        <h3 class="text-base font-semibold text-ink-900">Collection Trend (6 Months)</h3>
                        <p class="text-xs text-slate-500 mt-1 mb-3">Monthly submission volume with cleared and pending amount trends.</p>
                        <div class="analytics-chart-wrap">
                            <canvas id="collectionTrendChart" class="h-full w-full"></canvas>
                            <p id="collectionTrendChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No payments found in this period.</p>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-ink-900/10 bg-white p-4">
                        <h3 class="text-base font-semibold text-ink-900">Payment Methods</h3>
                        <p class="text-xs text-slate-500 mt-1 mb-3">Top channels currently used by students and guardians.</p>
                        <div class="analytics-chart-wrap">
                            <canvas id="paymentMethodChart" class="h-full w-full"></canvas>
                            <p id="paymentMethodChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No payment method data available yet.</p>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-ink-900/10 bg-white p-4">
                        <h3 class="text-base font-semibold text-ink-900">Collected Amount by Fee Type</h3>
                        <p class="text-xs text-slate-500 mt-1 mb-3">Where the cleared collections are concentrated across fee categories.</p>
                        <div class="analytics-chart-wrap">
                            <canvas id="feeTypeCollectionChart" class="h-full w-full"></canvas>
                            <p id="feeTypeCollectionChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No fee-type collection data available yet.</p>
                        </div>
                    </article>
                </div>
            </section>

            <section>
                <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-xl font-semibold text-ink-900">Clerk Workspace</h2>
                    <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php">Review pending</a>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-hourglass-half"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Pending</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($status_counts['pending']); ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php?status=pending">Process queue</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-user-check"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Verified</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($status_counts['verified']); ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php?status=verified">View verified</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-check-circle"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Completed</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($status_counts['completed']); ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php?status=completed">View completed</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Partial</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($status_counts['partial']); ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php?status=partial">Resolve balances</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-red-500/10 text-red-600">
                                <i class="fas fa-ban"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Rejected</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($rejected_total_count); ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php?status=rejected">Follow up</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-percentage"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Completion Rate</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($completion_rate, 1); ?>%</p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php">Improve closure</a>
                    </div>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-lg font-semibold text-ink-900">Quick Actions</h2>
                    <span class="text-xs uppercase tracking-wide text-slate-500">Frequently used</span>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <a href="payments.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                        <i class="fas fa-money-check-alt text-teal-700"></i>
                        Verify payments
                    </a>
                    <a href="payments.php?status=pending" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                        <i class="fas fa-clock text-teal-700"></i>
                        Process pending
                    </a>
                    <a href="fee_structure.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                        <i class="fas fa-layer-group text-teal-700"></i>
                        Manage fee structure
                    </a>
                    <a href="payments.php?status=completed" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                        <i class="fas fa-receipt text-teal-700"></i>
                        Generate receipts
                    </a>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-lg font-semibold text-ink-900">Recent Payment Activity</h2>
                    <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php">View all</a>
                </div>
                <div class="space-y-4">
                    <?php if (empty($recent_payments)): ?>
                        <p class="text-sm text-slate-500">No payment activity found yet.</p>
                    <?php else: ?>
                        <?php foreach ($recent_payments as $payment): ?>
                            <?php
                            $status = normalize_payment_status((string) ($payment['status_key'] ?? ''));
                            $icon_bg = 'bg-slate-500/10 text-slate-600';
                            $icon = 'fa-file-invoice-dollar';
                            if ($status === 'completed') {
                                $icon_bg = 'bg-teal-600/10 text-teal-700';
                                $icon = 'fa-check-circle';
                            } elseif ($status === 'verified') {
                                $icon_bg = 'bg-sky-500/10 text-sky-700';
                                $icon = 'fa-user-check';
                            } elseif ($status === 'partial') {
                                $icon_bg = 'bg-amber-500/10 text-amber-600';
                                $icon = 'fa-file-invoice-dollar';
                            } elseif ($status === 'rejected') {
                                $icon_bg = 'bg-red-500/10 text-red-600';
                                $icon = 'fa-ban';
                            } elseif ($status === 'pending') {
                                $icon_bg = 'bg-amber-500/10 text-amber-600';
                                $icon = 'fa-hourglass-half';
                            }
                            ?>
                            <div class="flex items-start gap-3">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl <?php echo $icon_bg; ?>">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink-900">
                                        <?php echo htmlspecialchars($payment['student_name'] ?: 'Unknown Student'); ?>
                                        <span class="text-slate-500 font-medium">
                                            (<?php echo htmlspecialchars($payment['class_name'] ?: 'No Class'); ?>)
                                        </span>
                                    </p>
                                    <p class="text-xs text-slate-500 recent-activity-line">
                                        <?php echo htmlspecialchars(ucfirst($status)); ?> payment of <?php echo htmlspecialchars(money_format_ngn((float) ($payment['amount_paid'] ?? 0))); ?>
                                        <?php if (!empty($payment['receipt_number'])): ?>
                                            | Receipt: <?php echo htmlspecialchars($payment['receipt_number']); ?>
                                        <?php endif; ?>
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        <?php echo !empty($payment['payment_date']) ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $payment['payment_date']))) : 'No date'; ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <?php include '../includes/floating-button.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        const analyticsData = <?php echo json_encode($analytics_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

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

        const toggleChartState = (canvasId, emptyId, hasData) => {
            const canvas = document.getElementById(canvasId);
            const emptyState = document.getElementById(emptyId);
            if (!canvas || !emptyState) {
                return;
            }

            if (hasData) {
                canvas.classList.remove('hidden');
                emptyState.classList.add('hidden');
                emptyState.classList.remove('flex');
                return;
            }

            canvas.classList.add('hidden');
            emptyState.classList.remove('hidden');
            emptyState.classList.add('flex');
        };

        const initializeAnalyticsCharts = () => {
            if (typeof Chart === 'undefined') {
                return;
            }

            Chart.defaults.color = '#475569';
            Chart.defaults.font.family = "'Manrope', sans-serif";
            const gridColor = 'rgba(148, 163, 184, 0.25)';

            const statusValues = (analyticsData.status?.values || []).map((value) => Number(value || 0));
            const hasStatusData = statusValues.some((value) => value > 0);
            toggleChartState('paymentStatusChart', 'paymentStatusChartEmpty', hasStatusData);
            if (hasStatusData) {
                const ctx = document.getElementById('paymentStatusChart');
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: analyticsData.status?.labels || [],
                        datasets: [{
                            data: statusValues,
                            backgroundColor: ['#f59e0b', '#0ea5e9', '#a855f7', '#14b8a6', '#ef4444', '#64748b'],
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 12,
                                    boxHeight: 12,
                                },
                            },
                        },
                    },
                });
            }

            const monthlyLabels = analyticsData.monthly?.labels || [];
            const monthlySubmissions = (analyticsData.monthly?.submissions || []).map((value) => Number(value || 0));
            const monthlyCleared = (analyticsData.monthly?.cleared || []).map((value) => Number(value || 0));
            const monthlyPending = (analyticsData.monthly?.pending || []).map((value) => Number(value || 0));
            const hasMonthlyData = [...monthlySubmissions, ...monthlyCleared, ...monthlyPending].some((value) => value > 0);
            toggleChartState('collectionTrendChart', 'collectionTrendChartEmpty', hasMonthlyData);
            if (hasMonthlyData) {
                const ctx = document.getElementById('collectionTrendChart');
                new Chart(ctx, {
                    data: {
                        labels: monthlyLabels,
                        datasets: [
                            {
                                type: 'bar',
                                label: 'Cleared Amount',
                                data: monthlyCleared,
                                yAxisID: 'yAmount',
                                backgroundColor: 'rgba(20, 184, 166, 0.35)',
                                borderColor: '#0f766e',
                                borderWidth: 1,
                                borderRadius: 8,
                                maxBarThickness: 44,
                            },
                            {
                                type: 'bar',
                                label: 'Pending Amount',
                                data: monthlyPending,
                                yAxisID: 'yAmount',
                                backgroundColor: 'rgba(245, 158, 11, 0.32)',
                                borderColor: '#d97706',
                                borderWidth: 1,
                                borderRadius: 8,
                                maxBarThickness: 44,
                            },
                            {
                                type: 'line',
                                label: 'Submissions',
                                data: monthlySubmissions,
                                yAxisID: 'yCount',
                                borderColor: '#334155',
                                backgroundColor: 'rgba(51, 65, 85, 0.1)',
                                borderWidth: 2,
                                tension: 0.3,
                                fill: true,
                                pointRadius: 4,
                                pointHoverRadius: 5,
                            },
                        ],
                    },
                    options: {
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: {
                            yAmount: {
                                type: 'linear',
                                position: 'left',
                                beginAtZero: true,
                                grid: {
                                    color: gridColor,
                                },
                                ticks: {
                                    callback: (value) => `NGN ${Number(value).toLocaleString()}`,
                                },
                            },
                            yCount: {
                                type: 'linear',
                                position: 'right',
                                beginAtZero: true,
                                grid: {
                                    drawOnChartArea: false,
                                },
                                ticks: {
                                    precision: 0,
                                },
                            },
                            x: {
                                grid: {
                                    display: false,
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                            },
                        },
                    },
                });
            }

            const methodLabels = analyticsData.methods?.labels || [];
            const methodValues = (analyticsData.methods?.values || []).map((value) => Number(value || 0));
            const hasMethodData = methodValues.some((value) => value > 0);
            toggleChartState('paymentMethodChart', 'paymentMethodChartEmpty', hasMethodData);
            if (hasMethodData) {
                const ctx = document.getElementById('paymentMethodChart');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: methodLabels,
                        datasets: [{
                            label: 'Payments',
                            data: methodValues,
                            backgroundColor: '#0ea5e9',
                            borderRadius: 8,
                            maxBarThickness: 48,
                        }],
                    },
                    options: {
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                grid: {
                                    display: false,
                                },
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0,
                                },
                                grid: {
                                    color: gridColor,
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                display: false,
                            },
                        },
                    },
                });
            }

            const feeLabels = analyticsData.fees?.labels || [];
            const feeValues = (analyticsData.fees?.values || []).map((value) => Number(value || 0));
            const hasFeeData = feeValues.some((value) => value > 0);
            toggleChartState('feeTypeCollectionChart', 'feeTypeCollectionChartEmpty', hasFeeData);
            if (hasFeeData) {
                const ctx = document.getElementById('feeTypeCollectionChart');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: feeLabels,
                        datasets: [{
                            label: 'Collected Amount',
                            data: feeValues,
                            backgroundColor: ['#14b8a6', '#0ea5e9', '#f59e0b', '#a855f7', '#22c55e', '#64748b'],
                            borderRadius: 8,
                            maxBarThickness: 22,
                        }],
                    },
                    options: {
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: {
                                beginAtZero: true,
                                grid: {
                                    color: gridColor,
                                },
                                ticks: {
                                    callback: (value) => `NGN ${Number(value).toLocaleString()}`,
                                },
                            },
                            y: {
                                grid: {
                                    display: false,
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                display: false,
                            },
                        },
                    },
                });
            }
        };

        initializeAnalyticsCharts();
    </script>
</body>
</html>
