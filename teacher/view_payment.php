<?php
// clark/view_payment.php
session_start();
require_once '../config/db.php';
require_once '../helpers/payment_helper.php';

// Check if user is authorized (clark, teacher, or principal)
$allowed_roles = ['principal', 'teacher', 'staff'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$paymentHelper = new PaymentHelper();

// Get payment ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: payments.php");
    exit;
}

$paymentId = $_GET['id'];

// Get payment details with student and class info
$stmt = $pdo->prepare("SELECT sp.*, 
                      s.full_name as student_name, s.admission_no, s.phone, s.guardian_name, s.guardian_phone,
                      c.class_name, c.id as class_id,
                      u.full_name as verified_by_name,
                      b.bank_name, b.account_number as bank_account_number
                      FROM student_payments sp
                      JOIN students s ON sp.student_id = s.id
                      JOIN classes c ON sp.class_id = c.id
                      LEFT JOIN users u ON sp.verified_by = u.id
                      LEFT JOIN school_bank_accounts b ON sp.bank_account_id = b.id
                      WHERE sp.id = ?");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    header("Location: payments.php?error=payment_not_found");
    exit;
}

// Get payment attachments
$attachments = $pdo->prepare("SELECT * FROM payment_attachments WHERE payment_id = ? ORDER BY uploaded_at DESC");
$attachments->execute([$paymentId]);
$attachments = $attachments->fetchAll();

// Get installments if any
$installments = $pdo->prepare("SELECT * FROM payment_installments WHERE payment_id = ? ORDER BY installment_number");
$installments->execute([$paymentId]);
$installments = $installments->fetchAll();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        if (isset($_POST['verify_payment'])) {
            // Verify payment
            $notes = $_POST['verification_notes'] ?? '';
            
            $stmt = $pdo->prepare("UPDATE student_payments 
                                  SET status = 'completed', 
                                      verified_by = ?, 
                                      verified_at = NOW(),
                                      notes = CONCAT(IFNULL(notes, ''), '\n[Verified by admin: ', ?, ' - ', NOW(), ']')
                                  WHERE id = ?");
            $stmt->execute([$userId, $notes, $paymentId]);
            
            // Update installment status if it's an installment payment
            if ($payment['payment_type'] === 'installment') {
                $stmt = $pdo->prepare("UPDATE payment_installments 
                                      SET status = 'paid', 
                                          payment_date = CURDATE()
                                      WHERE payment_id = ? AND installment_number = ?");
                $stmt->execute([$paymentId, $payment['installment_number']]);
            }
            
            $success = "Payment verified successfully!";
            
        } elseif (isset($_POST['reject_payment'])) {
            // Reject payment
            $rejection_reason = $_POST['rejection_reason'] ?? '';
            
            $stmt = $pdo->prepare("UPDATE student_payments 
                                  SET status = 'cancelled', 
                                      verified_by = ?, 
                                      verified_at = NOW(),
                                      notes = CONCAT(IFNULL(notes, ''), '\n[Rejected by admin: ', ?, ' - ', NOW(), ']')
                                  WHERE id = ?");
            $stmt->execute([$userId, $rejection_reason, $paymentId]);
            
            $success = "Payment rejected!";
            
        } elseif (isset($_POST['add_note'])) {
            // Add admin note
            $note = $_POST['admin_note'] ?? '';
            
            $stmt = $pdo->prepare("UPDATE student_payments 
                                  SET notes = CONCAT(IFNULL(notes, ''), '\n[Admin note: ', ?, ' - ', ?, ' - ', NOW(), ']')
                                  WHERE id = ?");
            $stmt->execute([$note, $_SESSION['full_name'] ?? 'Admin', $paymentId]);
            
            $success = "Note added successfully!";
            
        } elseif (isset($_POST['upload_attachment'])) {
            // Upload attachment
            if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === 0) {
                $uploadDir = '../uploads/payments/admin/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileName = time() . '_admin_' . basename($_FILES['attachment_file']['name']);
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['attachment_file']['tmp_name'], $filePath)) {
                    $stmt = $pdo->prepare("INSERT INTO payment_attachments 
                                          (payment_id, file_name, file_path, uploaded_by, file_type) 
                                          VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$paymentId, $fileName, $filePath, $userId, 'admin_attachment']);
                    
                    $success = "Attachment uploaded successfully!";
                } else {
                    $error = "Failed to upload file.";
                }
            }
        }
        
        $pdo->commit();
        
        // Refresh payment data
        $stmt = $pdo->prepare("SELECT sp.*, 
                              s.full_name as student_name, s.admission_no, s.phone, s.guardian_name, s.guardian_phone,
                              c.class_name, c.id as class_id,
                              u.full_name as verified_by_name,
                              b.bank_name, b.account_number as bank_account_number
                              FROM student_payments sp
                              JOIN students s ON sp.student_id = s.id
                              JOIN classes c ON sp.class_id = c.id
                              LEFT JOIN users u ON sp.verified_by = u.id
                              LEFT JOIN school_bank_accounts b ON sp.bank_account_id = b.id
                              WHERE sp.id = ?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        
        // Refresh attachments
        $attachments = $pdo->prepare("SELECT * FROM payment_attachments WHERE payment_id = ? ORDER BY uploaded_at DESC");
        $attachments->execute([$paymentId]);
        $attachments = $attachments->fetchAll();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get student's other payments for context
$studentPayments = $pdo->prepare("SELECT * FROM student_payments 
                                 WHERE student_id = ? AND id != ? 
                                 ORDER BY payment_date DESC LIMIT 5");
$studentPayments->execute([$payment['student_id'], $paymentId]);
$studentPayments = $studentPayments->fetchAll();

// Get class teacher if any
$classTeacher = $pdo->prepare("SELECT u.full_name FROM class_teachers ct
                              JOIN users u ON ct.teacher_id = u.id
                              WHERE ct.class_id = ? LIMIT 1");
$classTeacher->execute([$payment['class_id']]);
$classTeacher = $classTeacher->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Payment - Clark Portal</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: #34495e; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .info-section { background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #3498db; }
        .info-label { font-weight: bold; color: #2c3e50; margin-bottom: 5px; }
        .info-value { color: #34495e; }
        .status-badge { padding: 5px 10px; border-radius: 3px; font-size: 14px; font-weight: bold; }
        .status-pending { background: #fef9e7; color: #f39c12; }
        .status-completed { background: #eafaf1; color: #27ae60; }
        .status-cancelled { background: #fdedec; color: #e74c3c; }
        .status-partial { background: #e8f4fc; color: #3498db; }
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #3498db; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-secondary { background: #95a5a6; color: white; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table th, .table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .table th { background: #2c3e50; color: white; }
        .attachment-thumb { max-width: 200px; max-height: 150px; border: 1px solid #ddd; border-radius: 4px; }
        .action-buttons { display: flex; gap: 10px; margin-top: 20px; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 10% auto; padding: 20px; width: 90%; max-width: 500px; border-radius: 5px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .close { font-size: 24px; cursor: pointer; }
        .tabs { display: flex; border-bottom: 1px solid #ddd; margin-bottom: 20px; }
        .tab { padding: 10px 20px; cursor: pointer; border: 1px solid transparent; border-bottom: none; }
        .tab.active { background: white; border-color: #ddd #ddd white; border-radius: 5px 5px 0 0; font-weight: bold; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .back-link { display: inline-block; margin-bottom: 15px; color: #3498db; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .amount-highlight { font-size: 36px; font-weight: bold; color: #27ae60; text-align: center; margin: 20px 0; }
        .verification-box { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-top: 20px; }
    </style>
    <script>
        function showTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab
            event.currentTarget.classList.add('active');
        }
        
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function confirmReject() {
            var reason = document.getElementById('rejection_reason').value;
            if (!reason.trim()) {
                alert('Please provide a reason for rejection.');
                return false;
            }
            return confirm('Are you sure you want to reject this payment?');
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</head>
<body>
    <div class="container">
        <a href="payments.php" class="back-link">← Back to Payments List</a>
        
        <div class="header">
            <h1>Payment Details</h1>
            <p>View and verify student payment</p>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Payment Status Banner -->
        <div class="card" style="border-left: 6px solid <?php 
            echo $payment['status'] == 'completed' ? '#27ae60' : 
                   ($payment['status'] == 'pending' ? '#f39c12' : 
                   ($payment['status'] == 'cancelled' ? '#e74c3c' : '#3498db')); ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 style="margin: 0;">Payment #<?php echo htmlspecialchars($payment['receipt_number']); ?></h2>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php echo htmlspecialchars($payment['student_name']); ?> - 
                        <?php echo htmlspecialchars($payment['class_name']); ?> - 
                        <?php echo htmlspecialchars($payment['term']); ?> <?php echo htmlspecialchars($payment['academic_year']); ?>
                    </p>
                </div>
                <div>
                    <span class="status-badge status-<?php echo $payment['status']; ?>">
                        <?php echo strtoupper($payment['status']); ?>
                    </span>
                    <?php if ($payment['verified_by_name']): ?>
                        <p style="margin: 5px 0 0 0; font-size: 14px; color: #666;">
                            Verified by: <?php echo htmlspecialchars($payment['verified_by_name']); ?><br>
                            On: <?php echo date('d/m/Y H:i', strtotime($payment['verified_at'])); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Amount Highlight -->
        <div class="amount-highlight">
            ₦<?php echo number_format($payment['amount_paid'], 2); ?>
            <div style="font-size: 16px; color: #666; font-weight: normal;">
                of ₦<?php echo number_format($payment['total_amount'], 2); ?> total fee
                (Balance: ₦<?php echo number_format($payment['balance'], 2); ?>)
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('details-tab')">Payment Details</div>
            <div class="tab" onclick="showTab('attachments-tab')">Attachments (<?php echo count($attachments); ?>)</div>
            <div class="tab" onclick="showTab('student-tab')">Student Info</div>
            <div class="tab" onclick="showTab('history-tab')">Payment History</div>
            <?php if ($payment['payment_type'] === 'installment'): ?>
                <div class="tab" onclick="showTab('installments-tab')">Installments</div>
            <?php endif; ?>
        </div>
        
        <!-- Details Tab -->
        <div id="details-tab" class="tab-content active">
            <div class="info-grid">
                <!-- Payment Information -->
                <div class="info-section">
                    <h3>Payment Information</h3>
                    <div class="info-grid" style="grid-template-columns: 1fr;">
                        <div>
                            <div class="info-label">Payment Date</div>
                            <div class="info-value"><?php echo date('F j, Y', strtotime($payment['payment_date'])); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Payment Method</div>
                            <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Payment Type</div>
                            <div class="info-value">
                                <?php echo ucfirst($payment['payment_type']); ?>
                                <?php if ($payment['payment_type'] === 'installment'): ?>
                                    (Installment <?php echo $payment['installment_number']; ?> of <?php echo $payment['total_installments']; ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <div class="info-label">Transaction ID</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['transaction_id']); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Due Date</div>
                            <div class="info-value"><?php echo date('F j, Y', strtotime($payment['due_date'])); ?></div>
                        </div>
                        <?php if ($payment['bank_name']): ?>
                            <div>
                                <div class="info-label">Bank Account</div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($payment['bank_name']); ?><br>
                                    <?php echo htmlspecialchars($payment['bank_account_number']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Student Information -->
                <div class="info-section">
                    <h3>Student Information</h3>
                    <div class="info-grid" style="grid-template-columns: 1fr;">
                        <div>
                            <div class="info-label">Student Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['student_name']); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Admission Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['admission_no']); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Class</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['class_name']); ?></div>
                        </div>
                        <?php if ($classTeacher): ?>
                            <div>
                                <div class="info-label">Class Teacher</div>
                                <div class="info-value"><?php echo htmlspecialchars($classTeacher['full_name']); ?></div>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="info-label">Student Phone</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['phone']); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Fee Information -->
                <div class="info-section">
                    <h3>Fee Information</h3>
                    <div class="info-grid" style="grid-template-columns: 1fr;">
                        <div>
                            <div class="info-label">Academic Year</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['academic_year']); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Term</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['term']); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Total Fee</div>
                            <div class="info-value">₦<?php echo number_format($payment['total_amount'], 2); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Amount Paid</div>
                            <div class="info-value">₦<?php echo number_format($payment['amount_paid'], 2); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Balance</div>
                            <div class="info-value">₦<?php echo number_format($payment['balance'], 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Notes Section -->
            <?php if (!empty($payment['notes'])): ?>
                <div class="card">
                    <h3>Payment Notes</h3>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; white-space: pre-line;">
                        <?php echo htmlspecialchars($payment['notes']); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Verification Box (Only for pending payments) -->
            <?php if ($payment['status'] == 'pending'): ?>
                <div class="verification-box">
                    <h3>Payment Verification</h3>
                    <p>Verify or reject this payment after checking the attachments and details.</p>
                    
                    <div class="action-buttons">
                        <button class="btn btn-success" onclick="openModal('verifyModal')">✓ Verify Payment</button>
                        <button class="btn btn-danger" onclick="openModal('rejectModal')">✗ Reject Payment</button>
                        <button class="btn btn-warning" onclick="openModal('noteModal')">📝 Add Note</button>
                        <button class="btn btn-secondary" onclick="openModal('attachmentModal')">📎 Upload Attachment</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Attachments Tab -->
        <div id="attachments-tab" class="tab-content">
            <div class="card">
                <h3>Payment Attachments</h3>
                <p>Files uploaded by student or admin as proof of payment.</p>
                
                <?php if (empty($attachments)): ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No attachments found.</p>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px;">
                        <?php foreach ($attachments as $attachment): ?>
                            <div style="border: 1px solid #ddd; border-radius: 5px; padding: 15px;">
                                <div style="font-weight: bold; margin-bottom: 10px;">
                                    <?php echo htmlspecialchars($attachment['file_name']); ?>
                                </div>
                                
                                <?php 
                                $fileExt = strtolower(pathinfo($attachment['file_name'], PATHINFO_EXTENSION));
                                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];
                                $pdfExtensions = ['pdf'];
                                ?>
                                
                                <?php if (in_array($fileExt, $imageExtensions)): ?>
                                    <img src="<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                         alt="Attachment" class="attachment-thumb" 
                                         onclick="window.open('<?php echo htmlspecialchars($attachment['file_path']); ?>', '_blank')"
                                         style="cursor: pointer;">
                                <?php elseif (in_array($fileExt, $pdfExtensions)): ?>
                                    <div style="background: #e74c3c; color: white; padding: 20px; text-align: center; border-radius: 4px;">
                                        📄 PDF Document
                                    </div>
                                <?php else: ?>
                                    <div style="background: #3498db; color: white; padding: 20px; text-align: center; border-radius: 4px;">
                                        📎 File Attachment
                                    </div>
                                <?php endif; ?>
                                
                                <div style="margin-top: 10px; font-size: 12px; color: #666;">
                                    Uploaded: <?php echo date('d/m/Y H:i', strtotime($attachment['uploaded_at'])); ?><br>
                                    <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                       target="_blank" style="color: #3498db;">View Full File</a> |
                                    <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                       download style="color: #27ae60;">Download</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 20px;">
                    <button class="btn btn-secondary" onclick="openModal('attachmentModal')">
                        📎 Upload New Attachment
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Student Tab -->
        <div id="student-tab" class="tab-content">
            <div class="card">
                <h3>Student Information</h3>
                <div class="info-grid">
                    <div class="info-section">
                        <h4>Contact Information</h4>
                        <div>
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['student_name']); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Admission Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['admission_no']); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['phone']); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Class</div>
                            <div class="info-value"><?php echo htmlspecialchars($payment['class_name']); ?></div>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h4>Guardian Information</h4>
                        <?php if ($payment['guardian_name']): ?>
                            <div>
                                <div class="info-label">Guardian Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($payment['guardian_name']); ?></div>
                            </div>
                            <div>
                                <div class="info-label">Guardian Phone</div>
                                <div class="info-value"><?php echo htmlspecialchars($payment['guardian_phone']); ?></div>
                            </div>
                        <?php else: ?>
                            <p style="color: #666; text-align: center; padding: 20px;">No guardian information available.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Student's Recent Payments -->
                <h3 style="margin-top: 30px;">Student's Recent Payments</h3>
                <?php if (!empty($studentPayments)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Receipt No</th>
                                <th>Term</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentPayments as $otherPayment): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($otherPayment['payment_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($otherPayment['receipt_number']); ?></td>
                                    <td><?php echo htmlspecialchars($otherPayment['term']); ?></td>
                                    <td>₦<?php echo number_format($otherPayment['amount_paid'], 2); ?></td>
                                    <td>
                                        <?php echo ucfirst($otherPayment['payment_type']); ?>
                                        <?php if ($otherPayment['payment_type'] === 'installment'): ?>
                                            (<?php echo $otherPayment['installment_number']; ?>/<?php echo $otherPayment['total_installments']; ?>)
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $otherPayment['status']; ?>">
                                            <?php echo ucfirst($otherPayment['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="view_payment.php?id=<?php echo $otherPayment['id']; ?>" 
                                           class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No other payments found for this student.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- History Tab -->
        <div id="history-tab" class="tab-content">
            <div class="card">
                <h3>Payment History and Audit Trail</h3>
                
                <div class="info-grid">
                    <div class="info-section">
                        <h4>Payment Timeline</h4>
                        <div style="position: relative; padding-left: 20px;">
                            <!-- Created -->
                            <div style="position: relative; margin-bottom: 20px;">
                                <div style="position: absolute; left: -8px; top: 0; width: 16px; height: 16px; 
                                            background: #3498db; border-radius: 50%;"></div>
                                <div>
                                    <div style="font-weight: bold;">Payment Created</div>
                                    <div style="color: #666; font-size: 14px;">
                                        <?php echo date('F j, Y H:i:s', strtotime($payment['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Verified (if applicable) -->
                            <?php if ($payment['verified_at']): ?>
                                <div style="position: relative; margin-bottom: 20px;">
                                    <div style="position: absolute; left: -8px; top: 0; width: 16px; height: 16px; 
                                                background: #27ae60; border-radius: 50%;"></div>
                                    <div>
                                        <div style="font-weight: bold;">Payment Verified</div>
                                        <div style="color: #666; font-size: 14px;">
                                            By: <?php echo htmlspecialchars($payment['verified_by_name']); ?><br>
                                            On: <?php echo date('F j, Y H:i:s', strtotime($payment['verified_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Last Updated -->
                            <?php if ($payment['updated_at'] != $payment['created_at']): ?>
                                <div style="position: relative;">
                                    <div style="position: absolute; left: -8px; top: 0; width: 16px; height: 16px; 
                                                background: #f39c12; border-radius: 50%;"></div>
                                    <div>
                                        <div style="font-weight: bold;">Last Updated</div>
                                        <div style="color: #666; font-size: 14px;">
                                            <?php echo date('F j, Y H:i:s', strtotime($payment['updated_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h4>Payment Statistics</h4>
                        <div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span>Amount Paid:</span>
                                <span style="font-weight: bold;">₦<?php echo number_format($payment['amount_paid'], 2); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span>Total Fee:</span>
                                <span>₦<?php echo number_format($payment['total_amount'], 2); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span>Balance:</span>
                                <span style="color: <?php echo $payment['balance'] > 0 ? '#e74c3c' : '#27ae60'; ?>;">
                                    ₦<?php echo number_format($payment['balance'], 2); ?>
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span>Payment Completion:</span>
                                <span>
                                    <?php 
                                    $completion = ($payment['amount_paid'] / $payment['total_amount']) * 100;
                                    echo round($completion, 1) . '%';
                                    ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Progress Bar -->
                        <div style="margin-top: 20px;">
                            <div style="background: #eee; height: 10px; border-radius: 5px; overflow: hidden;">
                                <div style="background: #27ae60; height: 100%; width: <?php echo $completion; ?>%;"></div>
                            </div>
                            <div style="text-align: center; margin-top: 5px; font-size: 12px; color: #666;">
                                <?php echo round($completion, 1); ?>% of total fee paid
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- System Notes -->
                <?php if (!empty($payment['notes'])): ?>
                    <div style="margin-top: 30px;">
                        <h4>System Notes</h4>
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                            <?php 
                            $notes = explode("\n", $payment['notes']);
                            foreach ($notes as $note):
                                if (trim($note)):
                            ?>
                                <div style="padding: 5px 0; border-bottom: 1px solid #eee; font-size: 14px;">
                                    <?php echo htmlspecialchars($note); ?>
                                </div>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Installments Tab (Only for installment payments) -->
        <?php if ($payment['payment_type'] === 'installment'): ?>
            <div id="installments-tab" class="tab-content">
                <div class="card">
                    <h3>Installment Payment Schedule</h3>
                    <p>Payment plan: <?php echo $payment['total_installments']; ?> installments of 
                       ₦<?php echo number_format($payment['total_amount'] / $payment['total_installments'], 2); ?> each</p>
                    
                    <?php if (!empty($installments)): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Due Date</th>
                                    <th>Amount Due</th>
                                    <th>Amount Paid</th>
                                    <th>Payment Date</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($installments as $installment): ?>
                                    <tr style="<?php echo $installment['installment_number'] == $payment['installment_number'] ? 'background: #e8f4fc;' : ''; ?>">
                                        <td>
                                            <?php echo $installment['installment_number']; ?>
                                            <?php if ($installment['installment_number'] == $payment['installment_number']): ?>
                                                <span style="font-size: 12px; color: #3498db;">(Current)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($installment['due_date'])); ?>
                                            <?php if (date('Y-m-d') > $installment['due_date'] && $installment['status'] != 'paid'): ?>
                                                <span style="color: #e74c3c; font-size: 12px;">(Overdue)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>₦<?php echo number_format($installment['amount_due'], 2); ?></td>
                                        <td>₦<?php echo number_format($installment['amount_paid'], 2); ?></td>
                                        <td>
                                            <?php echo $installment['payment_date'] ? date('d/m/Y', strtotime($installment['payment_date'])) : '-'; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $installment['status']; ?>">
                                                <?php echo ucfirst($installment['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($installment['notes'] ?: '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Installment Summary -->
                        <div style="margin-top: 30px; background: #f8f9fa; padding: 15px; border-radius: 5px;">
                            <h4>Installment Summary</h4>
                            <?php 
                            $totalPaid = array_sum(array_column($installments, 'amount_paid'));
                            $totalDue = array_sum(array_column($installments, 'amount_due'));
                            $remaining = $totalDue - $totalPaid;
                            ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                <div>
                                    <div style="font-weight: bold; color: #2c3e50;">Total Amount Due</div>
                                    <div style="font-size: 24px; font-weight: bold;">₦<?php echo number_format($totalDue, 2); ?></div>
                                </div>
                                <div>
                                    <div style="font-weight: bold; color: #27ae60;">Total Paid</div>
                                    <div style="font-size: 24px; font-weight: bold; color: #27ae60;">
                                        ₦<?php echo number_format($totalPaid, 2); ?>
                                    </div>
                                </div>
                                <div>
                                    <div style="font-weight: bold; color: #e74c3c;">Remaining Balance</div>
                                    <div style="font-size: 24px; font-weight: bold; color: #e74c3c;">
                                        ₦<?php echo number_format($remaining, 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #666; padding: 20px;">No installment schedule found.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Verification Modal -->
    <div id="verifyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Verify Payment</h3>
                <span class="close" onclick="closeModal('verifyModal')">&times;</span>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Verification Notes (Optional)</label>
                    <textarea name="verification_notes" rows="3" placeholder="Add any notes about this verification..."></textarea>
                </div>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    <strong>Note:</strong> Verifying this payment will mark it as completed and update the student's payment record.
                </p>
                <div class="action-buttons">
                    <button type="submit" name="verify_payment" class="btn btn-success">Confirm Verification</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('verifyModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Rejection Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Payment</h3>
                <span class="close" onclick="closeModal('rejectModal')">&times;</span>
            </div>
            <form method="POST" onsubmit="return confirmReject()">
                <div class="form-group">
                    <label>Reason for Rejection *</label>
                    <textarea name="rejection_reason" id="rejection_reason" rows="3" required 
                              placeholder="Please provide a reason for rejecting this payment..."></textarea>
                </div>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    <strong>Warning:</strong> Rejecting this payment will mark it as cancelled. The student will need to make a new payment.
                </p>
                <div class="action-buttons">
                    <button type="submit" name="reject_payment" class="btn btn-danger">Confirm Rejection</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Note Modal -->
    <div id="noteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Admin Note</h3>
                <span class="close" onclick="closeModal('noteModal')">&times;</span>
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Note *</label>
                    <textarea name="admin_note" rows="4" required 
                              placeholder="Add an administrative note about this payment..."></textarea>
                </div>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    Notes are visible to all administrators and are logged in the payment history.
                </p>
                <div class="action-buttons">
                    <button type="submit" name="add_note" class="btn btn-primary">Add Note</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('noteModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Upload Attachment Modal -->
    <div id="attachmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Attachment</h3>
                <span class="close" onclick="closeModal('attachmentModal')">&times;</span>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Select File *</label>
                    <input type="file" name="attachment_file" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" required>
                    <small>Accepted formats: JPG, PNG, PDF, DOC, DOCX (Max 5MB)</small>
                </div>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    Upload supporting documents, correspondence, or other files related to this payment.
                </p>
                <div class="action-buttons">
                    <button type="submit" name="upload_attachment" class="btn btn-primary">Upload File</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('attachmentModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Initialize first tab as active
        document.addEventListener('DOMContentLoaded', function() {
            showTab('details-tab');
        });
    </script>
</body>
</html>