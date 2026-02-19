<?php
// student/get_payment_details.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['student_id']) || !isset($_GET['id'])) {
    die("Unauthorized access");
}

$paymentId = $_GET['id'];
$studentId = $_SESSION['student_id'];
$current_school_id = get_current_school_id();

// Get payment details
$stmt = $pdo->prepare("SELECT sp.*, s.full_name, s.admission_no, 
                       c.class_name, u.full_name as verified_by_name
                       FROM student_payments sp
                       JOIN students s ON sp.student_id = s.id
                       JOIN classes c ON sp.class_id = c.id
                       LEFT JOIN users u ON sp.verified_by = u.id
                       WHERE sp.id = ? AND sp.student_id = ? AND sp.school_id = ?");
$stmt->execute([$paymentId, $studentId, $current_school_id]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Payment not found");
}

// Get fee type labels
$feeTypeLabels = [
    'tuition' => 'Tuition Fee',
    'exam' => 'Examination Fee',
    'sports' => 'Sports Fee',
    'library' => 'Library Fee',
    'development' => 'Development Levy',
    'other' => 'Other Charges'
];

// Get payment attachments
$attachmentsStmt = $pdo->prepare("SELECT * FROM payment_attachments WHERE payment_id = ?");
$attachmentsStmt->execute([$paymentId]);
$attachments = $attachmentsStmt->fetchAll();

// Get payment status labels
$statusLabels = [
    'pending' => 'Pending',
    'verified' => 'Verified',
    'completed' => 'Completed',
    'partial' => 'Partial Payment',
    'rejected' => 'Rejected'
];
?>

<div class="receipt-content">
    <div style="text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px dashed #ddd;">
        <h2 style="color: #2c3e50; margin-bottom: 10px;">PAYMENT RECEIPT</h2>
        <p style="color: #7f8c8d;">Sahab Form Master School</p>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div>
            <h4 style="color: #3498db; margin-bottom: 10px;">Student Information</h4>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($payment['full_name']); ?></p>
            <p><strong>Admission No:</strong> <?php echo htmlspecialchars($payment['admission_no']); ?></p>
            <p><strong>Class:</strong> <?php echo htmlspecialchars($payment['class_name']); ?></p>
        </div>
        
        <div>
            <h4 style="color: #3498db; margin-bottom: 10px;">Payment Details</h4>
            <p><strong>Receipt No:</strong> <?php echo htmlspecialchars($payment['receipt_number']); ?></p>
            <p><strong>Transaction ID:</strong> <?php echo htmlspecialchars($payment['transaction_id']); ?></p>
            <p><strong>Date:</strong> <?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></p>
            <p><strong>Term/Year:</strong> <?php echo htmlspecialchars($payment['term'] . ' ' . $payment['academic_year']); ?></p>
        </div>
    </div>
    
    <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 30px;">
        <h4 style="color: #3498db; margin-bottom: 15px;">Payment Summary</h4>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <div>
                <p><strong>Fee Type:</strong></p>
                <p style="font-size: 18px; font-weight: bold; color: #2c3e50;">
                    <?php echo $feeTypeLabels[$payment['fee_type']] ?? ucfirst($payment['fee_type']); ?>
                </p>
            </div>
            
            <div>
                <p><strong>Total Amount:</strong></p>
                <p style="font-size: 18px; font-weight: bold; color: #e74c3c;">
                    ₦<?php echo number_format($payment['total_amount'], 2); ?>
                </p>
            </div>
            
            <div>
                <p><strong>Amount Paid:</strong></p>
                <p style="font-size: 24px; font-weight: bold; color: #27ae60;">
                    ₦<?php echo number_format($payment['amount_paid'], 2); ?>
                </p>
            </div>
            
            <div>
                <p><strong>Balance:</strong></p>
                <p style="font-size: 18px; font-weight: bold; color: #f39c12;">
                    ₦<?php echo number_format($payment['total_amount'] - $payment['amount_paid'], 2); ?>
                </p>
            </div>
        </div>
        
        <div style="margin-top: 20px;">
            <p><strong>Payment Type:</strong> 
                <?php echo ucfirst($payment['payment_type']); ?>
                <?php if ($payment['payment_type'] == 'installment'): ?>
                    (Installment <?php echo $payment['installment_number']; ?> of <?php echo $payment['total_installments']; ?>)
                <?php endif; ?>
            </p>
            <p><strong>Payment Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></p>
            <p><strong>Due Date:</strong> <?php echo date('d/m/Y', strtotime($payment['due_date'])); ?></p>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div>
            <h4 style="color: #3498db; margin-bottom: 15px;">Status Information</h4>
            <div style="background: <?php 
                switch($payment['status']) {
                    case 'verified': echo '#d4edda'; break;
                    case 'completed': echo '#d4edda'; break;
                    case 'pending_verification': echo '#fff3cd'; break;
                    case 'partial': echo '#cce5ff'; break;
                    default: echo '#f8d7da';
                }
            ?>; padding: 15px; border-radius: 8px; border-left: 4px solid <?php 
                switch($payment['status']) {
                    case 'verified': echo '#28a745'; break;
                    case 'completed': echo '#28a745'; break;
                    case 'pending_verification': echo '#ffc107'; break;
                    case 'partial': echo '#007bff'; break;
                    default: echo '#dc3545';
                }
            ?>;">
                <p style="margin: 0;">
                    <strong>Status:</strong> 
                    <span style="font-weight: bold;">
                        <?php echo $statusLabels[$payment['status']] ?? ucfirst($payment['status']); ?>
                    </span>
                </p>
            </div>
            
            <?php if (!empty($payment['verified_by']) || !empty($payment['verified_at'])): ?>
                <div style="margin-top: 15px; background: #e8f4fc; padding: 15px; border-radius: 8px;">
                    <h5 style="margin-top: 0; color: #17a2b8;">Verification Details</h5>
                    <?php if (!empty($payment['verified_at'])): ?>
                        <p><strong>Verified Date:</strong> <?php echo date('d/m/Y H:i', strtotime($payment['verified_at'])); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($payment['verified_by_name'])): ?>
                        <p><strong>Verified By:</strong> <?php echo htmlspecialchars($payment['verified_by_name']); ?></p>
                    <?php elseif (!empty($payment['verified_by'])): ?>
                        <p><strong>Verified By:</strong> Admin #<?php echo $payment['verified_by']; ?></p>
                    <?php endif; ?>
                    <?php if (!empty($payment['verification_notes'])): ?>
                        <p><strong>Notes:</strong> <?php echo htmlspecialchars($payment['verification_notes']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($attachments)): ?>
            <div>
                <h4 style="color: #3498db; margin-bottom: 15px;">Payment Proof</h4>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                    <?php foreach ($attachments as $attachment): ?>
                        <div style="margin-bottom: 10px;">
                            <p><strong>File:</strong> <?php echo htmlspecialchars($attachment['file_name']); ?></p>
                            <p><strong>Uploaded:</strong> <?php echo date('d/m/Y H:i', strtotime($attachment['uploaded_at'] ?? $attachment['created_at'])); ?></p>
                            <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                               target="_blank" 
                               style="display: inline-block; background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;">
                               <i class="fas fa-eye"></i> View File
                            </a>
                        </div>
                        <hr>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if (!empty($payment['notes'])): ?>
        <div style="margin-bottom: 30px;">
            <h4 style="color: #3498db; margin-bottom: 10px;">Payment Notes</h4>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                <?php echo nl2br(htmlspecialchars($payment['notes'])); ?>
            </div>
        </div>
    <?php endif; ?>
    
    <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px dashed #ddd;">
        <p style="color: #7f8c8d; margin-bottom: 5px;">Thank you for your payment!</p>
        <p style="color: #7f8c8d; font-size: 14px;">This is an official receipt from Sahab Form Master School</p>
        <div class="no-print" style="margin-top: 20px;">
            <button onclick="window.print()" style="background: #3498db; color: white; border: none; padding: 10px 25px; border-radius: 5px; cursor: pointer;">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <button onclick="closeModal()" style="background: #95a5a6; color: white; border: none; padding: 10px 25px; border-radius: 5px; cursor: pointer; margin-left: 10px;">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>
