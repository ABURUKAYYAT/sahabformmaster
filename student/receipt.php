 <?php
// receipt.php
session_start();
require_once '../config/db.php';
require_once '../helpers/payment_helper.php';

if (!isset($_GET['id'])) {
    die("Receipt ID required");
}

$paymentId = $_GET['id'];
$current_school_id = get_current_school_id();
$paymentHelper = new PaymentHelper();

// Get payment details
$stmt = $pdo->prepare("SELECT sp.*, s.full_name, s.admission_no, s.phone, s.address,
                       c.class_name, u.full_name as verified_by_name,
                       (SELECT SUM(amount_due) FROM payment_installments WHERE payment_id = sp.id AND school_id = ?) as total_installments
                       FROM student_payments sp
                       JOIN students s ON sp.student_id = s.id AND s.school_id = ?
                       JOIN classes c ON sp.class_id = c.id AND c.school_id = ?
                       LEFT JOIN users u ON sp.verified_by = u.id
                       WHERE sp.id = ? AND sp.school_id = ?");
$stmt->execute([$current_school_id, $current_school_id, $current_school_id, $paymentId, $current_school_id]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Payment not found");
}

// Get installments if any
$installments = $pdo->prepare("SELECT * FROM payment_installments WHERE payment_id = ? AND school_id = ? ORDER BY installment_number");
$installments->execute([$paymentId, $current_school_id]);
$installments = $installments->fetchAll();

// Get school info
$schoolInfo = $pdo->query("SELECT * FROM school_profile LIMIT 1")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f4f4f4; }
        .receipt-container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .header { text-align: center; border-bottom: 3px double #333; padding-bottom: 20px; margin-bottom: 30px; }
        .school-name { font-size: 28px; font-weight: bold; color: #2c3e50; margin-bottom: 5px; }
        .school-motto { font-style: italic; color: #7f8c8d; margin-bottom: 10px; }
        .receipt-title { font-size: 24px; color: #27ae60; margin: 20px 0; }
        .details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .detail-group { margin-bottom: 15px; }
        .detail-label { font-weight: bold; color: #34495e; margin-bottom: 5px; }
        .detail-value { color: #2c3e50; }
        .amount-section { background: #f8f9fa; padding: 20px; border-radius: 5px; margin: 30px 0; text-align: center; }
        .amount-total { font-size: 36px; font-weight: bold; color: #27ae60; }
        .table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .table th, .table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .table th { background: #2c3e50; color: white; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #7f8c8d; }
        .signature-area { margin-top: 40px; display: flex; justify-content: space-between; }
        .signature-box { width: 200px; text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 50px; padding-top: 5px; }
        .print-btn { display: block; margin: 20px auto; padding: 10px 30px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer; }
        @media print {
            .print-btn { display: none; }
            body { background: white; }
            .receipt-container { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="header">
            <?php if ($schoolInfo && !empty($schoolInfo['school_logo'])): ?>
                <img src="../assets/images/ echo htmlspecialchars($schoolInfo['school_logo']); ?>" alt="School Logo" style="max-height: 80px; margin-bottom: 10px;">
            <?php endif; ?>
            <div class="school-name"><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'Sahab Academy'); ?></div>
            <div class="school-motto"><?php echo htmlspecialchars($schoolInfo['school_motto'] ?? 'Excellence in Education'); ?></div>
            <div><?php echo htmlspecialchars($schoolInfo['school_address'] ?? '123 School Street, City, State'); ?></div>
            <div>Phone: <?php echo htmlspecialchars($schoolInfo['school_phone'] ?? '123-456-7890'); ?> | Email: <?php echo htmlspecialchars($schoolInfo['school_email'] ?? 'info@sahabacademy.com'); ?></div>
        </div>
        
        <div class="receipt-title">OFFICIAL PAYMENT RECEIPT</div>
        
        <div class="details-grid">
            <div>
                <div class="detail-group">
                    <div class="detail-label">Receipt Number</div>
                    <div class="detail-value" style="font-size: 18px; font-weight: bold; color: #e74c3c;">
                        <?php echo htmlspecialchars($payment['receipt_number']); ?>
                    </div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Student Name</div>
                    <div class="detail-value"><?php echo htmlspecialchars($payment['full_name']); ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Admission Number</div>
                    <div class="detail-value"><?php echo htmlspecialchars($payment['admission_no']); ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Class</div>
                    <div class="detail-value"><?php echo htmlspecialchars($payment['class_name']); ?></div>
                </div>
            </div>
            
            <div>
                <div class="detail-group">
                    <div class="detail-label">Payment Date</div>
                    <div class="detail-value"><?php echo date('F j, Y', strtotime($payment['payment_date'])); ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Academic Year</div>
                    <div class="detail-value"><?php echo htmlspecialchars($payment['academic_year']); ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Term</div>
                    <div class="detail-value"><?php echo htmlspecialchars($payment['term']); ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Payment Method</div>
                    <div class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">Transaction ID</div>
                    <div class="detail-value"><?php echo htmlspecialchars($payment['transaction_id']); ?></div>
                </div>
            </div>
        </div>
        
        <div class="amount-section">
            <div>Amount Paid</div>
            <div class="amount-total"><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></div>
            <div>Total Fee: <?php echo $paymentHelper->formatCurrency($payment['total_amount']); ?> | 
                 Balance: <?php echo $paymentHelper->formatCurrency($payment['balance']); ?></div>
        </div>
        
        <?php if ($payment['payment_type'] === 'installment' && !empty($installments)): ?>
            <h3>Installment Schedule</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Installment</th>
                        <th>Due Date</th>
                        <th>Amount Due</th>
                        <th>Amount Paid</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($installments as $installment): ?>
                        <tr>
                            <td>#<?php echo $installment['installment_number']; ?></td>
                            <td><?php echo date('d/m/Y', strtotime($installment['due_date'])); ?></td>
                            <td><?php echo $paymentHelper->formatCurrency($installment['amount_due']); ?></td>
                            <td><?php echo $paymentHelper->formatCurrency($installment['amount_paid']); ?></td>
                            <td>
                                <span style="color: <?php 
                                    echo $installment['status'] == 'paid' ? 'green' : 
                                           ($installment['status'] == 'overdue' ? 'red' : 'orange');
                                ?>;">
                                    <?php echo ucfirst($installment['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div class="signature-area">
            <div class="signature-box">
                <div>Student/Parent Signature</div>
                <div class="signature-line"></div>
            </div>
            
            <div class="signature-box">
                <div>Cashier/Authorized Signature</div>
                <div class="signature-line"></div>
                <div><?php echo htmlspecialchars($payment['verified_by_name'] ?: 'Not Verified'); ?></div>
            </div>
        </div>
        
        <div class="footer">
            <p><strong>Payment Status:</strong> <?php echo ucfirst($payment['status']); ?></p>
            <p>This is a computer-generated receipt. No signature required.</p>
            <p>Generated on: <?php echo date('F j, Y H:i:s'); ?></p>
        </div>
    </div>
    
    <button class="print-btn" onclick="window.print()">Print Receipt</button>
    
    <script>
        // Auto-print if requested
        if (window.location.search.includes('print=1')) {
            window.print();
        }
    </script>
</body>
</html>
