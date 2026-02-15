<?php
// admin/delete_fee.php
session_start();
require_once '../config/db.php';

// Check if user is admin/principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../login.php");
    exit;
}

// Get fee ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage_fees.php?error=invalid_id");
    exit;
}

$feeId = $_GET['id'];
$userId = $_SESSION['user_id'];

// Get fee details with payment information
$stmt = $pdo->prepare("SELECT 
                      fs.*, 
                      c.class_name,
                      (SELECT COUNT(*) FROM student_payments sp WHERE sp.class_id = fs.class_id 
                       AND sp.academic_year = fs.academic_year AND sp.term = fs.term) as total_payments,
                      (SELECT COUNT(DISTINCT student_id) FROM student_payments sp WHERE sp.class_id = fs.class_id 
                       AND sp.academic_year = fs.academic_year AND sp.term = fs.term AND sp.status = 'completed') as paid_students,
                      u.full_name as created_by_name
                      FROM fee_structure fs
                      JOIN classes c ON fs.class_id = c.id
                      LEFT JOIN users u ON fs.created_by = u.id
                      WHERE fs.id = ?");
$stmt->execute([$feeId]);
$fee = $stmt->fetch();

if (!$fee) {
    header("Location: manage_fees.php?error=fee_not_found");
    exit;
}

// Check if this is a POST request (confirmation)
$confirmed = false;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm_delete'])) {
        try {
            $pdo->beginTransaction();
            
            // Log the deletion for audit trail
            $logStmt = $pdo->prepare("INSERT INTO fee_deletion_log 
                                     (fee_id, class_id, academic_year, term, fee_type, amount, 
                                      deleted_by, deleted_at, reason, had_payments) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
            
            $reason = $_POST['deletion_reason'] ?? 'No reason provided';
            $hadPayments = $fee['total_payments'] > 0 ? 1 : 0;
            
            $logStmt->execute([
                $feeId,
                $fee['class_id'],
                $fee['academic_year'],
                $fee['term'],
                $fee['fee_type'],
                $fee['amount'],
                $userId,
                $reason,
                $hadPayments
            ]);
            
            // Check if there are any payments for this fee
            if ($fee['total_payments'] > 0) {
                // If there are payments, we should deactivate instead of delete
                // Or we can archive it depending on your preference
                
                // Option 1: Deactivate (soft delete) - RECOMMENDED
                $updateStmt = $pdo->prepare("UPDATE fee_structure SET is_active = 0 WHERE id = ?");
                $updateStmt->execute([$feeId]);
                
                $action = 'deactivated';
                $message = "Fee structure deactivated (not deleted) because there are existing payments.";
            } else {
                // Option 2: Hard delete (only if no payments)
                $deleteStmt = $pdo->prepare("DELETE FROM fee_structure WHERE id = ?");
                $deleteStmt->execute([$feeId]);
                
                $action = 'deleted';
                $message = "Fee structure permanently deleted.";
            }
            
            $pdo->commit();
            
            // Redirect with success message
            header("Location: manage_fees.php?success=" . urlencode($message) . "&action=" . $action);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    } elseif (isset($_POST['cancel_delete'])) {
        // User cancelled the deletion
        header("Location: manage_fees.php");
        exit;
    }
}

// Calculate potential impact
$impactLevel = 'low';
$impactMessage = '';

if ($fee['total_payments'] > 0) {
    $impactLevel = 'high';
    $impactMessage = "⚠️ <strong>High Impact:</strong> There are " . $fee['total_payments'] . " payment(s) recorded for this fee structure.";
} elseif ($fee['is_active'] == 1) {
    $impactLevel = 'medium';
    $impactMessage = "⚠️ <strong>Medium Impact:</strong> This fee is currently active and visible to students.";
} else {
    $impactLevel = 'low';
    $impactMessage = "✅ <strong>Low Impact:</strong> This fee is inactive and has no recorded payments.";
}

// Get similar fees for reference
$similarFees = $pdo->prepare("SELECT fs.*, c.class_name 
                             FROM fee_structure fs
                             JOIN classes c ON fs.class_id = c.id
                             WHERE fs.class_id = ? AND fs.id != ? AND fs.is_active = 1
                             ORDER BY fs.academic_year DESC, fs.term
                             LIMIT 5");
$similarFees->execute([$fee['class_id'], $feeId]);
$similarFees = $similarFees->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Fee Structure - Admin</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .warning-box { background: #fff3cd; border: 1px solid #ffeaa7; border-left: 4px solid #f39c12; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .danger-box { background: #f8d7da; border: 1px solid #f5c6cb; border-left: 4px solid #e74c3c; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .info-box { background: #e8f4fc; border: 1px solid #b8daff; border-left: 4px solid #3498db; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        .btn-success { background: #27ae60; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .fee-details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .detail-row { display: flex; margin-bottom: 10px; }
        .detail-label { font-weight: bold; width: 150px; color: #2c3e50; }
        .detail-value { color: #34495e; }
        .impact-high { color: #e74c3c; font-weight: bold; }
        .impact-medium { color: #f39c12; font-weight: bold; }
        .impact-low { color: #27ae60; font-weight: bold; }
        .action-buttons { display: flex; gap: 10px; margin-top: 20px; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        .alert-success { background: #d4edda; color: #155724; }
        .back-link { display: inline-block; margin-bottom: 15px; color: #3498db; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table th, .table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .table th { background: #2c3e50; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <a href="manage_fees.php" class="back-link">← Back to Manage Fees</a>
        
        <div class="header">
            <h1>Delete Fee Structure</h1>
            <p>Confirm deletion of fee structure</p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- Warning Box -->
        <div class="<?php echo $impactLevel == 'high' ? 'danger-box' : 'warning-box'; ?>">
            <h3 style="margin-top: 0; color: <?php echo $impactLevel == 'high' ? '#e74c3c' : '#f39c12'; ?>;">
                ⚠️ Delete Fee Structure
            </h3>
            <p>
                <strong>You are about to delete a fee structure.</strong> This action may affect:
            </p>
            <ul>
                <li>Student payment calculations</li>
                <li>Financial reports</li>
                <li>Historical records</li>
                <li>Ongoing payment processes</li>
            </ul>
            <div class="<?php echo 'impact-' . $impactLevel; ?>">
                <?php echo $impactMessage; ?>
            </div>
        </div>
        
        <!-- Fee Details -->
        <div class="card">
            <h2>Fee Structure Details</h2>
            <div class="fee-details">
                <div class="detail-row">
                    <div class="detail-label">Class:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($fee['class_name']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Academic Year:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($fee['academic_year']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Term:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($fee['term']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Fee Type:</div>
                    <div class="detail-value">
                        <?php 
                        $feeTypes = [
                            'tuition' => 'Tuition Fee',
                            'exam' => 'Examination Fee',
                            'sports' => 'Sports Fee',
                            'library' => 'Library Fee',
                            'development' => 'Development Levy',
                            'other' => 'Other Charges'
                        ];
                        echo $feeTypes[$fee['fee_type']] ?? $fee['fee_type'];
                        ?>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Description:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($fee['description']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Amount:</div>
                    <div class="detail-value">₦<?php echo number_format($fee['amount'], 2); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">
                        <span style="color: <?php echo $fee['is_active'] ? 'green' : 'red'; ?>;">
                            <?php echo $fee['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Created By:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($fee['created_by_name']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Created:</div>
                    <div class="detail-value"><?php echo date('F j, Y', strtotime($fee['created_at'])); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Installments Allowed:</div>
                    <div class="detail-value">
                        <?php echo $fee['allow_installments'] ? 'Yes (Max: ' . $fee['max_installments'] . ')' : 'No'; ?>
                    </div>
                </div>
            </div>
            
            <!-- Payment Statistics -->
            <?php if ($fee['total_payments'] > 0): ?>
                <div class="info-box">
                    <h4>⚠️ Payment Statistics (Cannot Hard Delete)</h4>
                    <div class="detail-row">
                        <div class="detail-label">Total Payments:</div>
                        <div class="detail-value"><?php echo $fee['total_payments']; ?> payment(s) recorded</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Students Paid:</div>
                        <div class="detail-value"><?php echo $fee['paid_students']; ?> student(s)</div>
                    </div>
                    <p style="color: #e74c3c; font-weight: bold;">
                        Because there are existing payments, this fee will be <u>deactivated</u> instead of deleted.
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Similar Active Fees -->
        <?php if (!empty($similarFees)): ?>
            <div class="card">
                <h3>Similar Active Fees</h3>
                <p>Other fee structures for <?php echo htmlspecialchars($fee['class_name']); ?>:</p>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Academic Year</th>
                            <th>Term</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($similarFees as $similarFee): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($similarFee['academic_year']); ?></td>
                                <td><?php echo htmlspecialchars($similarFee['term']); ?></td>
                                <td>
                                    <?php echo $feeTypes[$similarFee['fee_type']] ?? $similarFee['fee_type']; ?>
                                </td>
                                <td>₦<?php echo number_format($similarFee['amount'], 2); ?></td>
                                <td>
                                    <span style="color: <?php echo $similarFee['is_active'] ? 'green' : 'red'; ?>;">
                                        <?php echo $similarFee['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <!-- Deletion Form -->
        <div class="card">
            <h2>Confirm Deletion</h2>
            
            <form method="POST">
                <div class="form-group">
                    <label>Reason for Deletion *</label>
                    <textarea name="deletion_reason" rows="4" required 
                              placeholder="Please explain why you are deleting this fee structure..."></textarea>
                    <small>This will be logged for audit purposes.</small>
                </div>
                
                <div class="form-group">
                    <label>Deletion Type</label>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 4px;">
                        <?php if ($fee['total_payments'] > 0): ?>
                            <div style="color: #e74c3c; font-weight: bold;">
                                <input type="radio" id="deactivate" name="delete_type" value="deactivate" checked disabled>
                                <label for="deactivate">Deactivate (Recommended)</label>
                                <p style="margin: 5px 0 0 20px; color: #666;">
                                    Will mark as inactive but keep records for existing payments.
                                </p>
                            </div>
                            <div style="color: #666; margin-top: 10px;">
                                <input type="radio" id="hard_delete" name="delete_type" value="hard_delete" disabled>
                                <label for="hard_delete">Permanent Delete (Not Available)</label>
                                <p style="margin: 5px 0 0 20px; color: #666;">
                                    Cannot delete because there are existing payments.
                                </p>
                            </div>
                        <?php else: ?>
                            <div>
                                <input type="radio" id="deactivate" name="delete_type" value="deactivate" checked>
                                <label for="deactivate">Deactivate</label>
                                <p style="margin: 5px 0 0 20px; color: #666;">
                                    Mark as inactive but keep in database for records.
                                </p>
                            </div>
                            <div style="margin-top: 10px;">
                                <input type="radio" id="hard_delete" name="delete_type" value="hard_delete">
                                <label for="hard_delete">Permanent Delete</label>
                                <p style="margin: 5px 0 0 20px; color: #666;">
                                    Completely remove from database. Cannot be undone.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Confirmation</label>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 4px;">
                        <label>
                            <input type="checkbox" name="confirm_backup" value="1" required>
                            I have verified that no active students are using this fee structure
                        </label><br>
                        <label>
                            <input type="checkbox" name="confirm_irreversible" value="1" required>
                            I understand this action <?php echo $fee['total_payments'] > 0 ? 'will deactivate' : 'may delete'; ?> the fee structure
                        </label><br>
                        <label>
                            <input type="checkbox" name="confirm_responsibility" value="1" required>
                            I take full responsibility for this action
                        </label>
                    </div>
                </div>
                
                <!-- Warning Message -->
                <div class="<?php echo $impactLevel == 'high' ? 'danger-box' : 'warning-box'; ?>">
                    <h4 style="margin-top: 0;">Final Warning</h4>
                    <p>
                        <strong>This action is <?php echo $fee['total_payments'] > 0 ? 'semi-reversible' : 'IRREVERSIBLE'; ?>.</strong>
                        <?php if ($fee['total_payments'] > 0): ?>
                            Deactivated fees can be reactivated later if needed.
                        <?php else: ?>
                            Once deleted, this fee structure cannot be recovered.
                        <?php endif; ?>
                    </p>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" name="confirm_delete" class="btn btn-danger">
                        <?php echo $fee['total_payments'] > 0 ? 'Deactivate Fee' : 'Permanently Delete Fee'; ?>
                    </button>
                    <button type="submit" name="cancel_delete" class="btn btn-secondary">Cancel</button>
                    <a href="edit_fee.php?id=<?php echo $feeId; ?>" class="btn btn-warning">Edit Instead</a>
                </div>
            </form>
        </div>
        
        <!-- Alternative Actions -->
        <div class="card">
            <h3>Alternative Actions</h3>
            <p>Consider these alternatives before deleting:</p>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <div style="text-align: center;">
                    <a href="edit_fee.php?id=<?php echo $feeId; ?>&action=deactivate" class="btn btn-warning" style="width: 100%;">
                        📉 Deactivate Only
                    </a>
                    <p style="font-size: 12px; color: #666; margin-top: 5px;">
                        Hide from students but keep records
                    </p>
                </div>
                <div style="text-align: center;">
                    <a href="edit_fee.php?id=<?php echo $feeId; ?>&action=archive" class="btn btn-secondary" style="width: 100%;">
                        📁 Archive Fee
                    </a>
                    <p style="font-size: 12px; color: #666; margin-top: 5px;">
                        Move to archived fees for future reference
                    </p>
                </div>
                <div style="text-align: center;">
                    <a href="edit_fee.php?id=<?php echo $feeId; ?>&action=clone" class="btn btn-success" style="width: 100%;">
                        🧬 Clone & Modify
                    </a>
                    <p style="font-size: 12px; color: #666; margin-top: 5px;">
                        Create copy with changes instead of deleting
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Warn user before hard delete
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButton = document.querySelector('button[name="confirm_delete"]');
            const hardDeleteRadio = document.getElementById('hard_delete');
            
            if (deleteButton && hardDeleteRadio) {
                deleteButton.addEventListener('click', function(e) {
                    if (hardDeleteRadio.checked) {
                        if (!confirm('⚠️ WARNING: This will PERMANENTLY DELETE the fee structure!\n\nThis action cannot be undone. Are you absolutely sure?')) {
                            e.preventDefault();
                        }
                    }
                });
            }
            
            // Update button text based on selection
            const deleteTypeRadios = document.querySelectorAll('input[name="delete_type"]');
            deleteTypeRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'hard_delete') {
                        deleteButton.textContent = 'Permanently Delete Fee';
                        deleteButton.style.backgroundColor = '#c0392b';
                    } else {
                        deleteButton.textContent = 'Deactivate Fee';
                        deleteButton.style.backgroundColor = '#e74c3c';
                    }
                });
            });
        });
    </script>
</body>
</html>
