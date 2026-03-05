<?php
// student/payment_details.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$studentId = $_SESSION['student_id'];
$current_school_id = get_current_school_id();
$paymentHelper = new PaymentHelper();

// Get payment ID from URL
$paymentId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$paymentId) {
    header("Location: payment.php");
    exit;
}

// Fetch payment details with school isolation
$stmt = $pdo->prepare("
    SELECT sp.*, s.full_name, s.admission_no, s.class_id, c.class_name
    FROM student_payments sp
    JOIN students s ON sp.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE sp.id = ? AND sp.student_id = ? AND s.school_id = ?
");
$stmt->execute([$paymentId, $studentId, $current_school_id]);
$payment = $stmt->fetch();

if (!$payment) {
    header("Location: payment.php");
    exit;
}

// Fetch school information
$stmt = $pdo->prepare("
    SELECT school_name, school_code, address, phone, email, logo, motto, principal_name
    FROM schools
    WHERE id = ?
");
$stmt->execute([$current_school_id]);
$school = $stmt->fetch();

// Handle PDF download
if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
    if ($payment['status'] !== 'completed' || empty($payment['receipt_number'])) {
        header("Location: payment_details.php?id=" . $paymentId . "&error=receipt_unavailable");
        exit;
    }
    generatePaymentReceiptPDF($payment, $paymentHelper, $school);
    exit;
}

function generatePaymentReceiptPDF($payment, $paymentHelper, $school) {
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
$pdf->SetCreator($school['school_name'] ?? 'School');
    $pdf->SetAuthor($school['school_name']);
    $pdf->SetTitle('Payment Receipt - ' . $payment['receipt_number']);
    $pdf->SetSubject('School Fee Payment Receipt');

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(15, 15, 15);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Add a page
    $pdf->AddPage();

    // School Header with Logo
    if (!empty($school['logo']) && file_exists('../' . $school['logo'])) {
        // Add school logo
        $pdf->Image('../' . $school['logo'], 15, 15, 30, 30, '', '', '', false, 300, '', false, false, 0, false, false, false);
        $pdf->SetXY(50, 15);
    } else {
        $pdf->SetXY(15, 15);
    }

    // School name and motto
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, $school['school_name'], 0, 1, 'L');

    if (!empty($school['motto'])) {
        $pdf->SetFont('helvetica', 'I', 12);
        $pdf->Cell(0, 8, '"' . $school['motto'] . '"', 0, 1, 'L');
    }

    // School contact information
    $pdf->SetFont('helvetica', '', 10);
    $contactInfo = [];
    if (!empty($school['address'])) $contactInfo[] = $school['address'];
    if (!empty($school['phone'])) $contactInfo[] = 'Tel: ' . $school['phone'];
    if (!empty($school['email'])) $contactInfo[] = 'Email: ' . $school['email'];

    if (!empty($contactInfo)) {
        $pdf->Cell(0, 6, implode(' | ', $contactInfo), 0, 1, 'L');
    }

    $pdf->Ln(15);

    // Receipt title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 15, 'PAYMENT RECEIPT', 0, 1, 'C');
    $pdf->Ln(5);

    // Receipt title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 15, 'PAYMENT RECEIPT', 0, 1, 'C');
    $pdf->Ln(5);

    // Receipt number and date
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(95, 10, 'Receipt No: ' . $payment['receipt_number'], 1, 0, 'L');
    $pdf->Cell(95, 10, 'Date: ' . date('d/m/Y', strtotime($payment['payment_date'])), 1, 1, 'L');

    $pdf->Ln(5);

    // Student information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Student Information', 1, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);

    $pdf->Cell(50, 8, 'Full Name:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['full_name'], 1, 1, 'L');

    $pdf->Cell(50, 8, 'Admission No:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['admission_no'], 1, 1, 'L');

    $pdf->Cell(50, 8, 'Class:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['class_name'], 1, 1, 'L');

    $pdf->Ln(5);

    // Payment details
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Payment Details', 1, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);

    $pdf->Cell(50, 8, 'Academic Year:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['academic_year'], 1, 1, 'L');

    $pdf->Cell(50, 8, 'Term:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['term'], 1, 1, 'L');

    $pdf->Cell(50, 8, 'Payment Method:', 1, 0, 'L');
    $pdf->Cell(0, 8, ucwords(str_replace('_', ' ', $payment['payment_method'])), 1, 1, 'L');

    $pdf->Cell(50, 8, 'Payment Type:', 1, 0, 'L');
    $pdf->Cell(0, 8, ucwords(str_replace('_', ' ', $payment['payment_type'])), 1, 1, 'L');

    $pdf->Cell(50, 8, 'Amount Paid:', 1, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, $paymentHelper->formatCurrency($payment['amount_paid']), 1, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);

    $pdf->Cell(50, 8, 'Status:', 1, 0, 'L');
    $status = ucfirst(str_replace('_', ' ', $payment['status']));
    $pdf->Cell(0, 8, $status, 1, 1, 'L');

    if (!empty($payment['notes'])) {
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Notes', 1, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->MultiCell(0, 8, $payment['notes'], 1, 'L');
    }

    $pdf->Ln(15);

    // Footer
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 10, 'This is a computer-generated receipt. Thank you for your payment.', 0, 1, 'C');

    // Output PDF
    $pdf->Output('payment_receipt_' . $payment['receipt_number'] . '.pdf', 'D');
}

$student_name = $_SESSION['student_name'] ?? ($payment['full_name'] ?? 'Student');
$admission_number = $_SESSION['admission_no'] ?? ($payment['admission_no'] ?? '');
$statusKey = strtolower(trim((string) ($payment['status'] ?? 'pending')));
$statusLabel = ucfirst(str_replace('_', ' ', $statusKey));
$statusClassMap = [
    'pending' => 'bg-amber-100 text-amber-700',
    'verified' => 'bg-sky-100 text-sky-700',
    'partial' => 'bg-indigo-100 text-indigo-700',
    'completed' => 'bg-emerald-100 text-emerald-700',
    'rejected' => 'bg-rose-100 text-rose-700',
];
$statusClass = $statusClassMap[$statusKey] ?? 'bg-slate-100 text-slate-700';
$receiptReference = (string) ($payment['receipt_number'] ?: ($payment['transaction_id'] ?: 'N/A'));
$paymentDateDisplay = !empty($payment['payment_date']) ? date('d/m/Y H:i', strtotime((string) $payment['payment_date'])) : 'N/A';
$showReceiptUnavailable = isset($_GET['error']) && $_GET['error'] === 'receipt_unavailable';

$pageTitle = 'Payment Details | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$extraHead = <<<'EXTRA'
<link rel="manifest" href="../manifest.json">
<link rel="stylesheet" href="../assets/css/mobile-navigation.css">
<link rel="stylesheet" href="../assets/css/offline-status.css">
<style>
    .student-layout{overflow-x:hidden}
    .student-payment-page section{padding-top:.35rem;padding-bottom:.35rem}
    .dashboard-card{border-radius:1.5rem;border:1px solid rgba(15,31,45,.06);background:#fff;box-shadow:0 10px 24px rgba(15,31,51,.08);padding:1.65rem !important}
    .student-sidebar-overlay{position:fixed;inset:0;background:rgba(2,6,23,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:30}
    .sidebar{position:fixed;top:73px;left:0;width:16rem;height:calc(100vh - 73px);background:#fff;border-right:1px solid rgba(15,31,45,.1);box-shadow:0 18px 40px rgba(15,31,51,.12);transform:translateX(-106%);transition:transform .22s ease;z-index:40;overflow-y:auto}
    body.sidebar-open .sidebar{transform:translateX(0)} body.sidebar-open .student-sidebar-overlay{opacity:1;pointer-events:auto}
    .sidebar-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid rgba(15,31,45,.08)}
    .sidebar-header h3{margin:0;font-size:1rem;font-weight:700;color:#0f1f2d}
    .sidebar-close{border:0;border-radius:.55rem;padding:.35rem .55rem;background:rgba(15,31,45,.08);color:#334155;font-size:.8rem;line-height:1;cursor:pointer}
    .sidebar-nav{padding:.8rem}.nav-list{list-style:none;margin:0;padding:0;display:grid;gap:.2rem}
    .nav-link{display:flex;align-items:center;gap:.65rem;border-radius:.75rem;padding:.62rem .72rem;color:#475569;font-size:.88rem;font-weight:600;text-decoration:none;transition:background-color .15s ease,color .15s ease}
    .nav-link:hover{background:rgba(22,133,117,.1);color:#0f6a5c}.nav-link.active{background:rgba(22,133,117,.14);color:#0f6a5c}.nav-icon{width:1rem;text-align:center}
    #studentMain{min-width:0}
    .detail-grid{display:grid;gap:1rem}
    .detail-row{display:flex;justify-content:space-between;align-items:flex-start;gap:.8rem;padding:.78rem .2rem;border-bottom:1px solid rgba(15,31,45,.09)}
    .detail-row:last-child{border-bottom:none}
    .detail-label{font-size:.84rem;font-weight:600;color:#475569}
    .detail-value{font-size:.92rem;font-weight:600;color:#0f172a;text-align:right}
    .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:.25rem .62rem;font-size:.7rem;font-weight:700}
    .amount-value{font-size:1.55rem;font-weight:700;color:#059669}
    .hero-meta-card{border-radius:.95rem;border:1px solid rgba(15,31,45,.09);background:#f8fafc;padding:.9rem}
    .hero-meta-label{font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b}
    .hero-meta-value{margin-top:.3rem;font-size:1.05rem;font-weight:700;color:#0f172a}
    .school-summary{display:flex;align-items:center;gap:1rem}
    .school-logo-card{height:4.8rem;width:4.8rem;border-radius:.9rem;overflow:hidden;display:flex;align-items:center;justify-content:center;background:rgba(15,118,110,.12);color:#0f766e}
    .school-logo-card img{height:100%;width:100%;object-fit:cover}
    .note-box{margin-top:1rem;padding:1rem;border-radius:.8rem;border-left:4px solid #0f766e;background:#f8fafc}
    .action-wrap{display:flex;flex-wrap:wrap;gap:.75rem;justify-content:center}
    .btn-success{background:#059669;color:#fff}
    .btn-success:hover{background:#047857}
    @media (min-width:768px){#studentMain{padding-left:16rem !important}.sidebar{transform:translateX(0);top:73px;height:calc(100vh - 73px);padding-top:0}.sidebar-close{display:none}.student-sidebar-overlay{display:none}}
    @media (max-width:767.98px){#studentMain{padding-left:0 !important}.detail-value{text-align:left}}
    @media (max-width:640px){.student-payment-page .dashboard-card{padding:1.2rem !important}}
</style>
EXTRA;

require __DIR__ . '/../includes/student_header.php';
?>
<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-payment-page space-y-6">
    <section class="dashboard-card p-6 sm:p-8" data-reveal>
        <div class="flex flex-col gap-5 xl:grid xl:grid-cols-[1.6fr_1fr_1fr_auto] xl:items-end">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Payment Receipt Workspace</p>
                <h1 class="mt-2 text-3xl font-display text-ink-900">Payment Details</h1>
                <p class="mt-2 text-sm text-slate-600">Review your payment record and download your official receipt when payment is completed.</p>
            </div>
            <div class="hero-meta-card">
                <p class="hero-meta-label">Reference</p>
                <p class="hero-meta-value"><?php echo htmlspecialchars($receiptReference); ?></p>
            </div>
            <div class="hero-meta-card">
                <p class="hero-meta-label">Status</p>
                <p class="hero-meta-value"><span class="status-pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a class="btn btn-outline" href="payment.php"><i class="fas fa-arrow-left"></i><span>Back to Payments</span></a>
                <?php if ($payment['status'] === 'completed' && !empty($payment['receipt_number'])): ?>
                    <a href="?id=<?php echo $paymentId; ?>&download=pdf" class="btn btn-success"><i class="fas fa-download"></i><span>Download PDF</span></a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($showReceiptUnavailable): ?>
        <section class="dashboard-card p-4 border border-rose-200 bg-rose-50/70" data-reveal data-reveal-delay="30">
            <div class="flex items-start gap-3 text-rose-800">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rose-100"><i class="fas fa-exclamation-triangle"></i></span>
                <div>
                    <p class="text-sm font-semibold">Receipt Not Available</p>
                    <p class="text-sm">The PDF receipt is only available after payment is marked completed and assigned a receipt number.</p>
                </div>
            </div>
        </section>
    <?php endif; ?>
    <?php if (!empty($school)): ?>
        <section class="dashboard-card p-6" data-reveal data-reveal-delay="60">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-ink-900">School Information</h2>
                <p class="text-xs text-slate-500">Institution profile tied to this receipt.</p>
            </div>
            <div class="school-summary mb-4">
                <div class="school-logo-card">
                    <?php if (!empty($school['logo']) && file_exists('../' . $school['logo'])): ?>
                        <img src="../<?php echo htmlspecialchars((string) $school['logo']); ?>" alt="School Logo">
                    <?php else: ?>
                        <i class="fas fa-school text-2xl"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars((string) $school['school_name']); ?></p>
                    <?php if (!empty($school['motto'])): ?>
                        <p class="text-sm italic text-slate-600">"<?php echo htmlspecialchars((string) $school['motto']); ?>"</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <?php if (!empty($school['address'])): ?><div class="hero-meta-card"><p class="hero-meta-label">Address</p><p class="text-sm text-ink-900 mt-1"><?php echo htmlspecialchars((string) $school['address']); ?></p></div><?php endif; ?>
                <?php if (!empty($school['phone'])): ?><div class="hero-meta-card"><p class="hero-meta-label">Phone</p><p class="text-sm text-ink-900 mt-1"><?php echo htmlspecialchars((string) $school['phone']); ?></p></div><?php endif; ?>
                <?php if (!empty($school['email'])): ?><div class="hero-meta-card"><p class="hero-meta-label">Email</p><p class="text-sm text-ink-900 mt-1"><?php echo htmlspecialchars((string) $school['email']); ?></p></div><?php endif; ?>
                <?php if (!empty($school['principal_name'])): ?><div class="hero-meta-card"><p class="hero-meta-label">Principal</p><p class="text-sm text-ink-900 mt-1"><?php echo htmlspecialchars((string) $school['principal_name']); ?></p></div><?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="grid gap-4 xl:grid-cols-2" data-reveal data-reveal-delay="90">
        <article class="dashboard-card p-6">
            <h2 class="text-lg font-semibold text-ink-900 mb-3">Student Information</h2>
            <div class="detail-grid">
                <div class="detail-row"><span class="detail-label">Full Name</span><span class="detail-value"><?php echo htmlspecialchars((string) $payment['full_name']); ?></span></div>
                <div class="detail-row"><span class="detail-label">Admission Number</span><span class="detail-value"><?php echo htmlspecialchars((string) $payment['admission_no']); ?></span></div>
                <div class="detail-row"><span class="detail-label">Class</span><span class="detail-value"><?php echo htmlspecialchars((string) $payment['class_name']); ?></span></div>
            </div>
        </article>
        <article class="dashboard-card p-6">
            <h2 class="text-lg font-semibold text-ink-900 mb-3">Payment Information</h2>
            <div class="detail-grid">
                <div class="detail-row"><span class="detail-label">Academic Year</span><span class="detail-value"><?php echo htmlspecialchars((string) ($payment['academic_year'] ?? 'N/A')); ?></span></div>
                <div class="detail-row"><span class="detail-label">Term</span><span class="detail-value"><?php echo htmlspecialchars((string) ($payment['term'] ?? 'N/A')); ?></span></div>
                <div class="detail-row"><span class="detail-label">Payment Method</span><span class="detail-value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($payment['payment_method'] ?? '')))); ?></span></div>
                <div class="detail-row"><span class="detail-label">Payment Type</span><span class="detail-value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($payment['payment_type'] ?? '')))); ?></span></div>
                <div class="detail-row"><span class="detail-label">Payment Date</span><span class="detail-value"><?php echo htmlspecialchars($paymentDateDisplay); ?></span></div>
                <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value"><span class="status-pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></span></div>
            </div>
        </article>
    </section>

    <section class="dashboard-card p-6" data-reveal data-reveal-delay="120">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-ink-900">Payment Amount</h2>
                <p class="text-sm text-slate-600">Amount recorded for this transaction.</p>
            </div>
            <p class="amount-value"><?php echo $paymentHelper->formatCurrency((float) ($payment['amount_paid'] ?? 0)); ?></p>
        </div>
        <?php if (!empty($payment['notes'])): ?>
            <div class="note-box">
                <p class="text-sm font-semibold text-ink-900">Notes</p>
                <p class="text-sm text-slate-700 mt-1"><?php echo nl2br(htmlspecialchars((string) $payment['notes'])); ?></p>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-card p-6" data-reveal data-reveal-delay="150">
        <div class="action-wrap">
            <a href="payment.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i><span>Back to Payments</span></a>
            <?php if ($payment['status'] === 'completed' && !empty($payment['receipt_number'])): ?>
                <a href="?id=<?php echo $paymentId; ?>&download=pdf" class="btn btn-success"><i class="fas fa-download"></i><span>Download PDF Receipt</span></a>
            <?php endif; ?>
        </div>
    </section>
</main>

<script>
const sidebarOverlay = document.getElementById('studentSidebarOverlay');
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
}
if (window.matchMedia('(min-width: 768px)').matches) {
    document.body.classList.remove('sidebar-open');
}
</script>
<script src="../assets/js/offline-core.js" defer></script>
<?php include __DIR__ . '/../includes/floating-button.php'; ?>
<?php require __DIR__ . '/../includes/student_footer.php'; ?>
