<?php
// Include TCPDF library
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

// Custom PDF class extending TCPDF
class PaymentReceiptPDF extends TCPDF {
    // Page header
    public function Header() {
        // Add watermark
        $this->SetFont('helvetica', 'B', 50);
        $this->SetTextColor(220, 220, 220);
        $this->Rotate(45, 105, 200);
        $this->Text(35, 190, 'SAHAB ACADEMY');
        $this->Rotate(0);

        // Reset text color
        $this->SetTextColor(0, 0, 0);
    }

    // Page footer
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Generated on: ' . date('F d, Y H:i:s'), 0, false, 'C', 0, '', 0, false, 'T', 'M');
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
    }
}

session_start();
require_once '../config/db.php';
require_once '../helpers/payment_helper.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$studentId = $_SESSION['student_id'];
$paymentHelper = new PaymentHelper();

// Get payment ID from URL
$paymentId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$paymentId) {
    die("Payment ID not provided");
}

// Get payment details
$stmt = $pdo->prepare("SELECT sp.*, s.full_name, s.admission_no, s.guardian_name, s.guardian_phone,
                      c.class_name, spb.bank_name, spb.account_name, spb.account_number
                      FROM student_payments sp
                      JOIN students s ON sp.student_id = s.id
                      JOIN classes c ON sp.class_id = c.id
                      LEFT JOIN school_bank_accounts spb ON sp.bank_account_id = spb.id
                      WHERE sp.id = ? AND sp.student_id = ?");
$stmt->execute([$paymentId, $studentId]);
$paymentDetails = $stmt->fetch();

if (!$paymentDetails) {
    die("Payment not found or access denied");
}

// Get school information
$schoolInfoStmt = $pdo->query("SELECT * FROM school_profile LIMIT 1");
$schoolInfo = $schoolInfoStmt->fetch();

// Get student details
$stmt = $pdo->prepare("SELECT s.*, c.class_name FROM students s
                      JOIN classes c ON s.class_id = c.id
                      WHERE s.id = ?");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

// Create new PDF document
$pdf = new PaymentReceiptPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator($schoolInfo['school_name'] ?? 'School');
$pdf->SetAuthor($schoolInfo['school_name'] ?? 'School');
$pdf->SetTitle('Payment Receipt - ' . $paymentDetails['receipt_number']);
$pdf->SetSubject('Official Payment Receipt');
$pdf->SetKeywords('payment, receipt, school, fees');

// Set default header data
$pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Set font
$pdf->SetFont('helvetica', '', 10);

// Add a page
$pdf->AddPage();

// Set some HTML content
$html = '
<style>
    .receipt-container {
        font-family: Arial, sans-serif;
        max-width: 800px;
        margin: 0 auto;
        padding: 20px;
        border: 2px solid #000;
    }

    .receipt-header {
        text-align: center;
        margin-bottom: 30px;
        border-bottom: 3px double #000;
        padding-bottom: 20px;
    }

    .receipt-header h2 {
        font-size: 24px;
        margin-bottom: 10px;
        color: #000;
        font-weight: bold;
    }

    .receipt-header h3 {
        font-size: 18px;
        margin-bottom: 10px;
        color: #333;
        font-weight: bold;
    }

    .receipt-header p {
        font-size: 12px;
        color: #666;
        margin: 5px 0;
    }

    .receipt-details {
        margin: 25px 0;
    }

    .receipt-details table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        font-size: 12px;
    }

    .receipt-details th,
    .receipt-details td {
        border: 1px solid #000;
        padding: 8px;
        text-align: left;
    }

    .receipt-details th {
        background-color: #f2f2f2;
        font-weight: bold;
        font-size: 11px;
    }

    .receipt-total {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin: 20px 0;
        border: 1px solid #ddd;
    }

    .receipt-total .total-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        font-size: 13px;
    }

    .receipt-total .final-total {
        font-size: 16px;
        font-weight: bold;
        border-top: 2px solid #000;
        padding-top: 8px;
        margin-top: 8px;
    }

    .receipt-footer {
        margin-top: 40px;
        text-align: center;
    }

    .signature-section {
        margin-top: 50px;
        display: flex;
        justify-content: space-between;
    }

    .signature-line {
        border-top: 1px solid #000;
        width: 180px;
        text-align: center;
        padding-top: 8px;
        font-size: 11px;
    }

    .watermark {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-45deg);
        font-size: 60px;
        color: rgba(0,0,0,0.08);
        z-index: 1000;
        pointer-events: none;
        font-weight: bold;
    }

    .status-box {
        margin: 25px 0;
        padding: 12px;
        background: #f8f9fa;
        border-radius: 5px;
        text-align: center;
        border: 1px solid #ddd;
    }

    .status-box h4 {
        margin: 0;
        color: #2c3e50;
        font-size: 14px;
    }

    .bank-details {
        margin: 15px 0;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
        background: #f9f9f9;
    }

    .bank-details h4 {
        margin-bottom: 8px;
        color: #2c3e50;
        font-size: 13px;
    }

    .bank-details p {
        margin: 3px 0;
        font-size: 11px;
        color: #555;
    }

    .important-notice {
        border-top: 2px solid #000;
        padding-top: 12px;
        margin-top: 25px;
        font-size: 10px;
        color: #666;
        line-height: 1.4;
    }

    .important-notice p {
        margin: 5px 0;
    }
</style>

<div class="receipt-container">
    <!-- Watermark -->
    <div class="watermark">' . htmlspecialchars($schoolInfo['school_name'] ?? 'SAHAB ACADEMY') . '</div>

    <!-- Receipt Header -->
    <div class="receipt-header">
        <h2>' . htmlspecialchars($schoolInfo['school_name'] ?? 'SAHAB ACADEMY') . '</h2>
        <h3>OFFICIAL PAYMENT RECEIPT</h3>
        <p>' . htmlspecialchars($schoolInfo['school_address'] ?? 'School Address') . '</p>
        <p>Tel: ' . htmlspecialchars($schoolInfo['school_phone'] ?? 'N/A') . ' | Email: ' . htmlspecialchars($schoolInfo['school_email'] ?? 'N/A') . '</p>
    </div>

    <!-- Receipt Details -->
    <div class="receipt-details">
        <table>
            <tr>
                <th colspan="4" style="background: #f2f2f2; text-align: center;">RECEIPT INFORMATION</th>
            </tr>
            <tr>
                <td><strong>Receipt Number:</strong></td>
                <td>' . htmlspecialchars($paymentDetails['receipt_number']) . '</td>
                <td><strong>Date Issued:</strong></td>
                <td>' . date('F d, Y', strtotime($paymentDetails['payment_date'])) . '</td>
            </tr>
            <tr>
                <td><strong>Transaction ID:</strong></td>
                <td colspan="3">' . htmlspecialchars($paymentDetails['transaction_id']) . '</td>
            </tr>
        </table>

        <table style="margin-top: 15px;">
            <tr>
                <th colspan="4" style="background: #f2f2f2; text-align: center;">STUDENT INFORMATION</th>
            </tr>
            <tr>
                <td><strong>Student Name:</strong></td>
                <td>' . htmlspecialchars($student['full_name']) . '</td>
                <td><strong>Admission No:</strong></td>
                <td>' . htmlspecialchars($student['admission_no']) . '</td>
            </tr>
            <tr>
                <td><strong>Class:</strong></td>
                <td>' . htmlspecialchars($student['class_name']) . '</td>
                <td><strong>Guardian:</strong></td>
                <td>' . htmlspecialchars($student['guardian_name']) . '</td>
            </tr>
            <tr>
                <td><strong>Guardian Phone:</strong></td>
                <td colspan="3">' . htmlspecialchars($student['guardian_phone']) . '</td>
            </tr>
        </table>

        <table style="margin-top: 15px;">
            <tr>
                <th colspan="4" style="background: #f2f2f2; text-align: center;">PAYMENT DETAILS</th>
            </tr>
            <tr>
                <td><strong>Academic Year:</strong></td>
                <td>' . htmlspecialchars($paymentDetails['academic_year']) . '</td>
                <td><strong>Term:</strong></td>
                <td>' . htmlspecialchars($paymentDetails['term']) . '</td>
            </tr>
            <tr>
                <td><strong>Payment Method:</strong></td>
                <td>' . ucwords(str_replace('_', ' ', $paymentDetails['payment_method'])) . '</td>
                <td><strong>Payment Type:</strong></td>
                <td>' . ucfirst($paymentDetails['payment_type']) . '</td>
            </tr>';

if ($paymentDetails['payment_type'] == 'installment') {
    $html .= '
            <tr>
                <td><strong>Installment:</strong></td>
                <td colspan="3">' . $paymentDetails['installment_number'] . ' of ' . $paymentDetails['total_installments'] . '</td>
            </tr>';
}

$html .= '
        </table>

        <!-- Payment Breakdown -->
        <table style="margin-top: 15px;">
            <tr>
                <th colspan="2" style="background: #f2f2f2; text-align: center;">PAYMENT BREAKDOWN</th>
            </tr>
            <tr>
                <td><strong>Total Fee:</strong></td>
                <td style="text-align: right;">' . $paymentHelper->formatCurrency($paymentDetails['total_amount']) . '</td>
            </tr>
            <tr>
                <td><strong>Amount Paid:</strong></td>
                <td style="text-align: right; color: #27ae60; font-weight: bold;">
                    ' . $paymentHelper->formatCurrency($paymentDetails['amount_paid']) . '
                </td>
            </tr>
            <tr>
                <td><strong>Balance Due:</strong></td>
                <td style="text-align: right; color: #e74c3c; font-weight: bold;">
                    ' . $paymentHelper->formatCurrency($paymentDetails['total_amount'] - $paymentDetails['amount_paid']) . '
                </td>
            </tr>
        </table>
    </div>

    <!-- Payment Status -->
    <div class="status-box">
        <h4 style="margin: 0; color: #2c3e50;">
            Payment Status:
            <span style="color: ';

switch($paymentDetails['status']) {
    case 'completed':
        $html .= '#27ae60';
        break;
    case 'partial':
        $html .= '#3498db';
        break;
    default:
        $html .= '#f39c12';
        break;
}

$html .= ';">' . strtoupper(str_replace('_', ' ', $paymentDetails['status'])) . '</span>
        </h4>';

if ($paymentDetails['status'] == 'partial') {
    $html .= '
        <p style="margin: 8px 0 0 0; color: #e74c3c; font-size: 11px;">
            <strong>Next installment due: ' . date('F d, Y', strtotime($paymentDetails['due_date'])) . '</strong>
        </p>';
}

$html .= '
    </div>';

// Bank Details
if (!empty($paymentDetails['bank_name'])) {
    $html .= '
    <div class="bank-details">
        <h4>Bank Payment Details</h4>
        <p><strong>Bank:</strong> ' . htmlspecialchars($paymentDetails['bank_name']) . '</p>
        <p><strong>Account Name:</strong> ' . htmlspecialchars($paymentDetails['account_name']) . '</p>
        <p><strong>Account Number:</strong> ' . htmlspecialchars($paymentDetails['account_number']) . '</p>
    </div>';
}

$html .= '
    <!-- Receipt Footer -->
    <div class="receipt-footer">
        <div style="margin: 30px 0; text-align: center;">
            <p><strong>Payment Verified and Approved By:</strong></p>
            <div class="signature-section">
                <div class="signature-line">
                    <p>_________________________</p>
                    <p>Student Signature</p>
                </div>
                <div class="signature-line">
                    <p>_________________________</p>
                    <p>School Authority</p>
                </div>
            </div>
        </div>

        <div class="important-notice">
            <p><strong>Important Notice:</strong></p>
            <p>1. This receipt must be presented for verification purposes.</p>
            <p>2. Keep this receipt in a safe place for future reference.</p>
            <p>3. For any discrepancies, contact the school accounts office within 7 days.</p>
            <p>4. Receipt generated on: ' . date('F d, Y h:i A') . '</p>
        </div>
    </div>
</div>';

// Print text using writeHTMLCell()
$pdf->writeHTML($html, true, false, true, false, '');

// Close and output PDF document
$filename = 'Payment_Receipt_' . $paymentDetails['receipt_number'] . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');
exit;
?>
