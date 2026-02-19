<?php
// teacher/receipt.php - PDF Receipt Generation using TCPDF
session_start();
if (isset($_SESSION['role']) && $_SESSION['role'] === 'clerk') {
    header('Location: ../clerk/payments.php');
    exit;
}
header('Location: ../index.php');
exit;
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
$current_school_id = require_school_auth();

if (!isset($_GET['id'])) {
    die("Receipt ID required");
}

$paymentId = $_GET['id'];
$paymentHelper = new PaymentHelper();

// Get payment details - school-filtered
$stmt = $pdo->prepare("SELECT sp.*, s.full_name, s.admission_no, s.phone, s.address,
                       c.class_name, u.full_name as verified_by_name,
                       (SELECT SUM(amount_due) FROM payment_installments WHERE payment_id = sp.id) as total_installments
                       FROM student_payments sp
                       JOIN students s ON sp.student_id = s.id
                       JOIN classes c ON sp.class_id = c.id
                       LEFT JOIN users u ON sp.verified_by = u.id
                       WHERE sp.id = ? AND s.school_id = ?");
$stmt->execute([$paymentId, $current_school_id]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Payment not found");
}

// Get installments if any
$installments = $pdo->prepare("SELECT * FROM payment_installments WHERE payment_id = ? ORDER BY installment_number");
$installments->execute([$paymentId]);
$installments = $installments->fetchAll();

// Get school info
$schoolInfo = $pdo->query("SELECT * FROM school_profile LIMIT 1")->fetch();

// Custom PDF class for receipt
class PaymentReceiptPDF extends TCPDF {

    private $schoolInfo;
    private $payment;

    public function __construct($schoolInfo, $payment) {
        parent::__construct();
        $this->schoolInfo = $schoolInfo;
        $this->payment = $payment;
    }

    // Page header
    public function Header() {
        // Set background gradient for header (simulated with filled rectangle)
        $this->Rect(0, 0, $this->getPageWidth(), 45, 'F', array(), array(59, 130, 246, 108, 117, 226));

        // Add subtle pattern overlay
        $this->SetAlpha(0.3);
        for ($i = 0; $i < $this->getPageWidth(); $i += 10) {
            for ($j = 0; $j < 40; $j += 10) {
                $this->Circle($i + 5, $j + 5, 0.5, 0, 360, 'F', array(), array(255, 255, 255));
            }
        }
        $this->SetAlpha(1);

        // School logo
        if (!empty($this->schoolInfo['school_logo']) && file_exists('../' . $this->schoolInfo['school_logo'])) {
            $this->Image('../' . $this->schoolInfo['school_logo'], 15, 10, 15, 15, '', '', '', false, 300, '', false, false, 0);
        } else {
            // Fallback logo
            $this->Image('../assets/images/nysc.jpg', 15, 10, 15, 15, '', '', '', false, 300, '', false, false, 0);
        }

        // School name
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(35, 12);
        $this->Cell(0, 6, strtoupper($this->schoolInfo['school_name'] ?? 'Sahab Academy'), 0, 1, 'L');

        // School motto
        $this->SetFont('helvetica', 'I', 10);
        $this->SetXY(35, 18);
        $this->Cell(0, 5, $this->schoolInfo['school_motto'] ?? 'Excellence in Education', 0, 1, 'L');

        // School details
        $this->SetFont('helvetica', '', 8);
        $this->SetXY(35, 23);
        $address = $this->schoolInfo['school_address'] ?? '123 School Street, City, State';
        $this->Cell(0, 4, $address, 0, 1, 'L');

        $this->SetXY(35, 27);
        $contact = [];
        if (!empty($this->schoolInfo['school_phone'])) $contact[] = 'Phone: ' . $this->schoolInfo['school_phone'];
        if (!empty($this->schoolInfo['school_email'])) $contact[] = 'Email: ' . $this->schoolInfo['school_email'];
        $this->Cell(0, 4, implode(' | ', $contact), 0, 1, 'L');

        // Receipt title
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(59, 130, 246);
        $this->SetXY(15, 50);
        $this->Cell(0, 8, 'OFFICIAL PAYMENT RECEIPT', 0, 1, 'C');
    }

    // Page footer
    public function Footer() {
        $this->SetY(-30);

        // Signature area
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(0, 0, 0);

        // Left signature
        $this->SetXY(15, -25);
        $this->Cell(60, 4, 'Student/Parent Signature', 0, 0, 'C');

        // Center signature
        $this->SetXY(85, -25);
        $this->Cell(60, 4, 'Cashier/Authorized Signature', 0, 0, 'C');

        // Right signature
        $this->SetXY(155, -25);
        $this->Cell(60, 4, 'Date: ____________________', 0, 1, 'L');

        // Signature lines
        $this->Line(15, -18, 75, -18);
        $this->Line(85, -18, 145, -18);

        // Footer info
        $this->SetFont('helvetica', 'B', 9);
        $this->SetXY(15, -15);
        $this->Cell(0, 5, 'Payment Status: ' . ucfirst($this->payment['status']), 0, 1, 'L');

        $this->SetFont('helvetica', '', 7);
        $this->SetXY(15, -10);
        $this->Cell(0, 3, 'This is a computer-generated receipt and is valid without signature.', 0, 1, 'L');
        $this->Cell(0, 3, 'Please retain this receipt for your records.', 0, 1, 'L');

        // Timestamp
        $this->SetFont('helvetica', 'I', 6);
        $this->SetXY(15, -5);
        $this->Cell(0, 3, 'Generated on: ' . date('F j, Y \a\t H:i:s') . ' | Receipt ID: ' . $this->payment['id'], 0, 1, 'L');

        // Page number
        $this->SetFont('helvetica', 'I', 7);
        $this->Cell(0, 8, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

// Create PDF instance
$pdf = new PaymentReceiptPDF($schoolInfo, $payment);

// Set document information
$pdf->SetCreator('SahabFormMaster School Management System');
$pdf->SetAuthor($schoolInfo['school_name'] ?? 'Sahab Academy');
$pdf->SetTitle('Payment Receipt - ' . $payment['full_name']);
$pdf->SetSubject('Payment receipt for ' . $payment['full_name']);

// Set margins
$pdf->SetMargins(15, 65, 15); // Left, Top, Right
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(35);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 35);

// Add first page
$pdf->AddPage();

// Student Information Section
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 10, 'STUDENT INFORMATION', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9);

// Left column
$pdf->Cell(50, 6, 'Student Name:', 0, 0, 'L');
$pdf->Cell(50, 6, $payment['full_name'], 0, 0, 'L');
$pdf->Cell(30, 6, 'Admission No:', 0, 0, 'L');
$pdf->Cell(0, 6, $payment['admission_no'], 0, 1, 'L');

$pdf->Cell(50, 6, 'Class:', 0, 0, 'L');
$pdf->Cell(50, 6, $payment['class_name'], 0, 0, 'L');
$pdf->Cell(30, 6, 'Phone:', 0, 0, 'L');
$pdf->Cell(0, 6, $payment['phone'] ?? 'N/A', 0, 1, 'L');

$pdf->Ln(5);

// Payment Information Section
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 10, 'PAYMENT INFORMATION', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 6, 'Receipt Number:', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(220, 53, 69);
$pdf->Cell(50, 6, $payment['receipt_number'], 0, 0, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(30, 6, 'Payment Date:', 0, 0, 'L');
$pdf->Cell(0, 6, date('F j, Y', strtotime($payment['payment_date'])), 0, 1, 'L');

$pdf->Cell(50, 6, 'Academic Year:', 0, 0, 'L');
$pdf->Cell(50, 6, $payment['academic_year'] ?? 'N/A', 0, 0, 'L');
$pdf->Cell(30, 6, 'Term:', 0, 0, 'L');
$pdf->Cell(0, 6, $payment['term'] ?? 'N/A', 0, 1, 'L');

if (!empty($payment['transaction_id'])) {
    $pdf->Cell(50, 6, 'Transaction ID:', 0, 0, 'L');
    $pdf->Cell(0, 6, $payment['transaction_id'], 0, 1, 'L');
}

$pdf->Cell(50, 6, 'Payment Method:', 0, 0, 'L');
$pdf->Cell(0, 6, ucfirst(str_replace('_', ' ', $payment['payment_method'])), 0, 1, 'L');

$pdf->Ln(5);

// Amount Section
$pdf->SetFillColor(240, 253, 244);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 12, 'PAYMENT AMOUNT', 1, 1, 'C', 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(70, 10, 'Total Fee Amount:', 1, 0, 'L');
$pdf->Cell(0, 10, '₦' . number_format($payment['total_amount'], 2), 1, 1, 'R');

if ($payment['amount_paid'] > 0) {
    $pdf->Cell(70, 10, 'Amount Paid:', 1, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(34, 197, 94);
    $pdf->Cell(0, 10, '₦' . number_format($payment['amount_paid'], 2), 1, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
}

if ($payment['balance'] != 0) {
    $pdf->Cell(70, 10, 'Outstanding Balance:', 1, 0, 'L');
    $color = $payment['balance'] > 0 ? [239, 68, 68] : [34, 197, 94];
    $pdf->SetTextColor($color[0], $color[1], $color[2]);
    $pdf->Cell(0, 10, '₦' . number_format(abs($payment['balance']), 2) . ($payment['balance'] > 0 ? ' (Due)' : ' (Credit)'), 1, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
}

$pdf->Ln(5);

// Installment Information (if applicable)
if ($payment['payment_type'] === 'installment' && !empty($installments)) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 10, 'INSTALLMENT SCHEDULE', 0, 1, 'L');

    // Table header
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(59, 130, 246);
    $pdf->SetTextColor(255, 255, 255);

    $pdf->Cell(15, 8, 'Inst.#', 1, 0, 'C', 1);
    $pdf->Cell(25, 8, 'Due Date', 1, 0, 'C', 1);
    $pdf->Cell(30, 8, 'Amount Due', 1, 0, 'C', 1);
    $pdf->Cell(30, 8, 'Amount Paid', 1, 0, 'C', 1);
    $pdf->Cell(20, 8, 'Status', 1, 1, 'C', 1);

    // Table data
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);

    foreach ($installments as $installment) {
        $pdf->Cell(15, 6, $installment['installment_number'], 1, 0, 'C');
        $pdf->Cell(25, 6, date('M j, Y', strtotime($installment['due_date'])), 1, 0, 'C');
        $pdf->Cell(30, 6, '₦' . number_format($installment['amount_due'], 2), 1, 0, 'R');
        $pdf->Cell(30, 6, $installment['amount_paid'] > 0 ? '₦' . number_format($installment['amount_paid'], 2) : '-', 1, 0, 'R');

        // Status with color
        if ($installment['status'] === 'paid') {
            $pdf->SetTextColor(34, 197, 94);
        } elseif ($installment['status'] === 'overdue') {
            $pdf->SetTextColor(239, 68, 68);
        } else {
            $pdf->SetTextColor(245, 158, 11);
        }
        $pdf->Cell(20, 6, ucfirst($installment['status']), 1, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    $pdf->Ln(5);
}

// Verification Information
if (!empty($payment['verified_by_name'])) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 6, 'VERIFICATION INFORMATION:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(50, 5, 'Verified By:', 0, 0, 'L');
    $pdf->Cell(0, 5, $payment['verified_by_name'], 0, 1, 'L');
    $pdf->Cell(50, 5, 'Verified At:', 0, 0, 'L');
    $pdf->Cell(0, 5, (!empty($payment['verified_at'])) ? date('F j, Y H:i', strtotime($payment['verified_at'])) : 'Auto-verified', 0, 1, 'L');
    $pdf->Ln(3);
}

// Additional Notes
if (!empty($payment['notes'])) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 6, 'ADDITIONAL NOTES:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->MultiCell(0, 5, $payment['notes'], 0, 'L');
    $pdf->Ln(3);
}

// Generate filename and output
$filename = 'Receipt_' . preg_replace('/[^A-Za-z0-9\-_]/', '_', $payment['receipt_number']) . '_' . date('Ymd_His') . '.pdf';

// Output PDF to browser
$pdf->Output($filename, 'I');
exit;
