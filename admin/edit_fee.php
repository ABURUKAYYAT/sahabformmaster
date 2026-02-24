<?php
// admin/edit_fee.php
session_start();
require_once '../config/db.php';

// Check if user is admin/principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../login.php");
    exit;
}

// Get fee ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage_fees.php");
    exit;
}

$feeId = $_GET['id'];
$userId = $_SESSION['user_id'];

// Get fee details
$stmt = $pdo->prepare("SELECT fs.*, c.class_name 
                      FROM fee_structure fs 
                      JOIN classes c ON fs.class_id = c.id 
                      WHERE fs.id = ?");
$stmt->execute([$feeId]);
$fee = $stmt->fetch();

if (!$fee) {
    header("Location: manage_fees.php");
    exit;
}

// Get all classes for dropdown
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();

// Define fee statuses and their workflow
$feeStatuses = [
    'draft' => ['label' => 'Draft', 'color' => '#95a5a6', 'can_change_to' => ['pending_approval', 'cancelled']],
    'pending_approval' => ['label' => 'Pending Approval', 'color' => '#f39c12', 'can_change_to' => ['active', 'rejected', 'draft']],
    'active' => ['label' => 'Active', 'color' => '#27ae60', 'can_change_to' => ['paused', 'archived', 'expired']],
    'paused' => ['label' => 'Paused', 'color' => '#e74c3c', 'can_change_to' => ['active', 'archived']],
    'rejected' => ['label' => 'Rejected', 'color' => '#c0392b', 'can_change_to' => ['draft']],
    'archived' => ['label' => 'Archived', 'color' => '#7f8c8d', 'can_change_to' => []],
    'expired' => ['label' => 'Expired', 'color' => '#34495e', 'can_change_to' => ['archived']],
    'cancelled' => ['label' => 'Cancelled', 'color' => '#2c3e50', 'can_change_to' => []]
];

// Get status change history
$statusHistory = $pdo->prepare("SELECT * FROM fee_status_history 
                               WHERE fee_id = ? 
                               ORDER BY created_at DESC");
$statusHistory->execute([$feeId]);
$history = $statusHistory->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_fee'])) {
        try {
            $stmt = $pdo->prepare("UPDATE fee_structure 
                                  SET class_id = ?, academic_year = ?, term = ?, fee_type = ?, 
                                      description = ?, amount = ?, due_date = ?, 
                                      allow_installments = ?, max_installments = ?, is_active = ?,
                                      updated_at = NOW()
                                  WHERE id = ?");
            
            $stmt->execute([
                $_POST['class_id'],
                $_POST['academic_year'],
                $_POST['term'],
                $_POST['fee_type'],
                $_POST['description'],
                $_POST['amount'],
                $_POST['due_date'] ?: NULL,
                isset($_POST['allow_installments']) ? 1 : 0,
                $_POST['max_installments'] ?? 1,
                isset($_POST['is_active']) ? 1 : 0,
                $feeId
            ]);
            
            $success = "Fee structure updated successfully!";
            
            // Refresh fee data
            $stmt = $pdo->prepare("SELECT fs.*, c.class_name 
                                  FROM fee_structure fs 
                                  JOIN classes c ON fs.class_id = c.id 
                                  WHERE fs.id = ?");
            $stmt->execute([$feeId]);
            $fee = $stmt->fetch();
            
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_fee'])) {
        // Delete fee
        try {
            $stmt = $pdo->prepare("DELETE FROM fee_structure WHERE id = ?");
            $stmt->execute([$feeId]);
            
            header("Location: manage_fees.php?deleted=1");
            exit;
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_status'])) {
        // Update fee status with history tracking
        $newStatus = $_POST['new_status'];
        $statusReason = $_POST['status_reason'] ?? '';
        
        // Validate status transition
        $currentStatus = $fee['status'] ?? 'draft';
        $validTransitions = $feeStatuses[$currentStatus]['can_change_to'] ?? [];
        
        if (!in_array($newStatus, $validTransitions)) {
            $error = "Invalid status transition from '" . $feeStatuses[$currentStatus]['label'] . "' to '" . $feeStatuses[$newStatus]['label'] . "'";
        } else {
            try {
                // Begin transaction
                $pdo->beginTransaction();
                
                // Update fee status
                $stmt = $pdo->prepare("UPDATE fee_structure 
                                      SET status = ?, updated_at = NOW() 
                                      WHERE id = ?");
                $stmt->execute([$newStatus, $feeId]);
                
                // Log status change
                $stmt = $pdo->prepare("INSERT INTO fee_status_history 
                                      (fee_id, old_status, new_status, changed_by, reason, created_at) 
                                      VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$feeId, $currentStatus, $newStatus, $userId, $statusReason]);
                
                // Commit transaction
                $pdo->commit();
                
                $success = "Fee status updated to '" . $feeStatuses[$newStatus]['label'] . "' successfully!";
                
                // Refresh fee data
                $stmt = $pdo->prepare("SELECT fs.*, c.class_name 
                                      FROM fee_structure fs 
                                      JOIN classes c ON fs.class_id = c.id 
                                      WHERE fs.id = ?");
                $stmt->execute([$feeId]);
                $fee = $stmt->fetch();
                
                // Refresh status history
                $statusHistory->execute([$feeId]);
                $history = $statusHistory->fetchAll();
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error updating status: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['duplicate_fee'])) {
        // Duplicate fee for other academic year or term
        try {
            $stmt = $pdo->prepare("INSERT INTO fee_structure 
                                  (class_id, academic_year, term, fee_type, description, 
                                   amount, due_date, allow_installments, max_installments, 
                                   status, created_by, created_at)
                                  SELECT class_id, ?, term, fee_type, CONCAT(description, ' (Copied)'), 
                                         amount, NULL, allow_installments, max_installments,
                                         'draft', ?, NOW()
                                  FROM fee_structure 
                                  WHERE id = ?");
            
            $newAcademicYear = $_POST['new_academic_year'] ?: $fee['academic_year'];
            
            $stmt->execute([
                $newAcademicYear,
                $_SESSION['user_id'],
                $feeId
            ]);
            
            $newFeeId = $pdo->lastInsertId();
            $success = "Fee structure duplicated successfully! New fee ID: " . $newFeeId;
            
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } elseif (isset($_POST['archive_fee'])) {
        // Archive fee (move to archive table)
        try {
            // First, check if archive table exists
            $pdo->query("CREATE TABLE IF NOT EXISTS fee_structure_archive LIKE fee_structure");
            
            // Copy to archive
            $stmt = $pdo->prepare("INSERT INTO fee_structure_archive 
                                  SELECT *, NOW() as archived_at, ? as archived_by 
                                  FROM fee_structure WHERE id = ?");
            $stmt->execute([$userId, $feeId]);
            
            // Then deactivate original
            $stmt = $pdo->prepare("UPDATE fee_structure SET status = 'archived' WHERE id = ?");
            $stmt->execute([$feeId]);
            
            $success = "Fee archived successfully!";
            
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get payment statistics for this fee
$paymentStats = $pdo->prepare("SELECT 
                              COUNT(DISTINCT sp.student_id) as total_students,
                              COUNT(DISTINCT CASE WHEN sp.status = 'completed' THEN sp.student_id END) as paid_students,
                              SUM(CASE WHEN sp.status = 'completed' THEN sp.amount_paid ELSE 0 END) as total_collected,
                              AVG(CASE WHEN sp.status = 'completed' THEN sp.amount_paid ELSE NULL END) as avg_payment
                              FROM student_payments sp
                              WHERE sp.class_id = ? 
                              AND sp.academic_year = ? 
                              AND sp.term = ?");
$paymentStats->execute([$fee['class_id'], $fee['academic_year'], $fee['term']]);
$stats = $paymentStats->fetch();

// Get fee types for dropdown
$feeTypes = [
    'tuition' => 'Tuition Fee',
    'exam' => 'Examination Fee',
    'sports' => 'Sports Fee',
    'library' => 'Library Fee',
    'development' => 'Development Levy',
    'other' => 'Other Charges'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Fee Structure</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #3498db; }
        .stat-value { font-size: 24px; font-weight: bold; color: #2c3e50; }
        .stat-label { color: #7f8c8d; font-size: 14px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-info { background: #17a2b8; color: white; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; }
        .tabs { display: flex; border-bottom: 1px solid #ddd; margin-bottom: 20px; }
        .tab { padding: 10px 20px; cursor: pointer; border: 1px solid transparent; border-bottom: none; }
        .tab.active { background: white; border-color: #ddd #ddd white; border-radius: 5px 5px 0 0; font-weight: bold; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .back-link { display: inline-block; margin-bottom: 15px; color: #3498db; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 14px; font-weight: bold; color: white; }
        .status-options { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin: 20px 0; }
        .status-option { padding: 15px; border: 2px solid #ddd; border-radius: 5px; cursor: pointer; text-align: center; }
        .status-option:hover { background: #f8f9fa; }
        .status-option.selected { border-color: #3498db; background: #e3f2fd; }
        .history-item { padding: 15px; border-bottom: 1px solid #eee; }
        .history-item:last-child { border-bottom: none; }
        .history-date { color: #7f8c8d; font-size: 12px; }
        .history-reason { margin-top: 5px; padding: 8px; background: #f8f9fa; border-radius: 3px; }
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
        
        function confirmDelete() {
            return confirm('Are you sure you want to delete this fee structure?\n\nWarning: This action cannot be undone and may affect existing payments.');
        }
        
        function toggleInstallmentOptions() {
            var allowInstallments = document.getElementById('allow_installments').checked;
            var maxInstallments = document.getElementById('max_installments');
            maxInstallments.disabled = !allowInstallments;
        }
        
        function selectStatus(status) {
            document.querySelectorAll('.status-option').forEach(option => {
                option.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            document.getElementById('new_status').value = status;
        }
    </script>
</head>
<body>
    <div class="container">
        <a href="manage_fees.php" class="back-link">← Back to Manage Fees</a>
        
        <div class="header">
            <h1>Edit Fee Structure</h1>
            <p>Editing fee for: <?php echo htmlspecialchars($fee['class_name']); ?> - <?php echo htmlspecialchars($fee['academic_year']); ?> - <?php echo htmlspecialchars($fee['term']); ?></p>
            <div style="margin-top: 10px;">
                <span class="status-badge" style="background: <?php echo $feeStatuses[$fee['status']]['color'] ?? '#95a5a6'; ?>">
                    <?php echo $feeStatuses[$fee['status']]['label'] ?? ucfirst($fee['status']); ?>
                </span>
            </div>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">₦<?php echo number_format($fee['amount'], 2); ?></div>
                <div class="stat-label">Fee Amount</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_students'] ?? 0; ?></div>
                <div class="stat-label">Total Students in Class</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['paid_students'] ?? 0; ?></div>
                <div class="stat-label">Students Paid</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">₦<?php echo number_format($stats['total_collected'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Collected</div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('edit-tab')">Edit Fee</div>
            <div class="tab" onclick="showTab('status-tab')">Change Status</div>
            <div class="tab" onclick="showTab('duplicate-tab')">Duplicate</div>
            <div class="tab" onclick="showTab('history-tab')">History</div>
        </div>
        
        <!-- Edit Tab -->
        <div id="edit-tab" class="tab-content active">
            <div class="card">
                <h2>Edit Fee Details</h2>
                <form method="POST">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                        <div class="form-group">
                            <label>Class *</label>
                            <select name="class_id" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" 
                                        <?php echo $class['id'] == $fee['class_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Academic Year *</label>
                            <input type="text" name="academic_year" value="<?php echo htmlspecialchars($fee['academic_year']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Term *</label>
                            <select name="term" required>
                                <option value="1st Term" <?php echo $fee['term'] == '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                                <option value="2nd Term" <?php echo $fee['term'] == '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                                <option value="3rd Term" <?php echo $fee['term'] == '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Fee Type *</label>
                            <select name="fee_type" required>
                                <?php foreach ($feeTypes as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" 
                                        <?php echo $fee['fee_type'] == $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Description</label>
                            <input type="text" name="description" value="<?php echo htmlspecialchars($fee['description']); ?>" 
                                   placeholder="e.g., Term 1 Tuition Fee">
                        </div>
                        
                        <div class="form-group">
                            <label>Amount (₦) *</label>
                            <input type="number" name="amount" min="0" step="0.01" 
                                   value="<?php echo $fee['amount']; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" name="due_date" 
                                   value="<?php echo $fee['due_date'] ? date('Y-m-d', strtotime($fee['due_date'])) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="allow_installments" id="allow_installments" 
                                       value="1" <?php echo $fee['allow_installments'] ? 'checked' : ''; ?> 
                                       onchange="toggleInstallmentOptions()">
                                Allow Installments
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label>Max Installments (if allowed)</label>
                            <select name="max_installments" id="max_installments" 
                                    <?php echo !$fee['allow_installments'] ? 'disabled' : ''; ?>>
                                <option value="1" <?php echo $fee['max_installments'] == 1 ? 'selected' : ''; ?>>1 (Full Payment)</option>
                                <option value="2" <?php echo $fee['max_installments'] == 2 ? 'selected' : ''; ?>>2</option>
                                <option value="3" <?php echo $fee['max_installments'] == 3 ? 'selected' : ''; ?>>3</option>
                                <option value="4" <?php echo $fee['max_installments'] == 4 ? 'selected' : ''; ?>>4</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_active" value="1" 
                                       <?php echo $fee['is_active'] ? 'checked' : ''; ?>>
                                Active (Students can see and pay this fee)
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="update_fee" class="btn btn-success">Update Fee</button>
                        <button type="submit" name="delete_fee" class="btn btn-danger" onclick="return confirmDelete()">Delete Fee</button>
                        <a href="manage_fees.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
            
            <!-- Warning if fee has existing payments -->
            <?php if ($stats && $stats['paid_students'] > 0): ?>
                <div class="alert alert-warning">
                    <strong>Warning:</strong> This fee already has <?php echo $stats['paid_students']; ?> student(s) who have made payments. 
                    Changing the amount may affect payment calculations. Consider creating a new fee structure instead.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Status Tab -->
        <div id="status-tab" class="tab-content">
            <div class="card">
                <h2>Change Fee Status</h2>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                    <h3>Current Status</h3>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span class="status-badge" style="background: <?php echo $feeStatuses[$fee['status']]['color'] ?? '#95a5a6'; ?>; font-size: 16px; padding: 8px 20px;">
                            <?php echo $feeStatuses[$fee['status']]['label'] ?? ucfirst($fee['status']); ?>
                        </span>
                        <div>
                            <p><strong>Fee:</strong> <?php echo htmlspecialchars($fee['class_name']); ?> - <?php echo htmlspecialchars($fee['academic_year']); ?> - <?php echo htmlspecialchars($fee['term']); ?></p>
                            <p><strong>Amount:</strong> ₦<?php echo number_format($fee['amount'], 2); ?></p>
                        </div>
                    </div>
                </div>
                
                <?php 
                // Get valid status transitions
                $currentStatus = $fee['status'] ?? 'draft';
                $validTransitions = $feeStatuses[$currentStatus]['can_change_to'] ?? [];
                ?>
                
                <?php if (!empty($validTransitions)): ?>
                    <form method="POST">
                        <h3>Select New Status</h3>
                        <div class="status-options">
                            <?php foreach ($validTransitions as $status): ?>
                                <?php if (isset($feeStatuses[$status])): ?>
                                    <div class="status-option" onclick="selectStatus('<?php echo $status; ?>')" 
                                         style="border-color: <?php echo $feeStatuses[$status]['color']; ?>;">
                                        <span class="status-badge" style="background: <?php echo $feeStatuses[$status]['color']; ?>; margin-bottom: 8px;">
                                            <?php echo $feeStatuses[$status]['label']; ?>
                                        </span>
                                        <p style="font-size: 12px; color: #666; margin: 5px 0 0 0;">
                                            <?php 
                                            $descriptions = [
                                                'draft' => 'Initial draft state',
                                                'pending_approval' => 'Awaiting approval',
                                                'active' => 'Visible to students',
                                                'paused' => 'Temporarily disabled',
                                                'rejected' => 'Not approved',
                                                'archived' => 'Moved to archive',
                                                'expired' => 'Past due date',
                                                'cancelled' => 'Cancelled permanently'
                                            ];
                                            echo $descriptions[$status] ?? '';
                                            ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" name="new_status" id="new_status" value="">
                        
                        <div class="form-group">
                            <label>Reason for Status Change (Optional)</label>
                            <textarea name="status_reason" rows="3" placeholder="Enter reason for status change..."></textarea>
                            <small>This will be recorded in the status history.</small>
                        </div>
                        
                        <?php if ($stats && $stats['paid_students'] > 0 && in_array('active', $validTransitions)): ?>
                            <div class="alert alert-info">
                                <strong>Note:</strong> This fee has <?php echo $stats['paid_students']; ?> student(s) who have already made payments.
                                Changing to active status will make it visible to all students.
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-actions">
                            <button type="submit" name="update_status" class="btn btn-primary" id="updateStatusBtn" disabled>Update Status</button>
                            <a href="#edit-tab" class="btn btn-secondary" onclick="showTab('edit-tab')">Back to Edit</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info">
                        <strong>No Status Transitions Available</strong>
                        <p>The current status "<?php echo $feeStatuses[$currentStatus]['label']; ?>" cannot be changed to any other status.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Status History -->
                <h3 style="margin-top: 30px;">Status History</h3>
                <?php if (!empty($history)): ?>
                    <div style="background: white; border: 1px solid #eee; border-radius: 5px;">
                        <?php foreach ($history as $record): ?>
                            <div class="history-item">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <span class="status-badge" style="background: <?php echo $feeStatuses[$record['old_status']]['color'] ?? '#95a5a6'; ?>; font-size: 12px;">
                                            <?php echo $feeStatuses[$record['old_status']]['label'] ?? ucfirst($record['old_status']); ?>
                                        </span>
                                        <span style="margin: 0 10px;">→</span>
                                        <span class="status-badge" style="background: <?php echo $feeStatuses[$record['new_status']]['color'] ?? '#95a5a6'; ?>; font-size: 12px;">
                                            <?php echo $feeStatuses[$record['new_status']]['label'] ?? ucfirst($record['new_status']); ?>
                                        </span>
                                    </div>
                                    <div class="history-date">
                                        <?php echo date('d/m/Y H:i', strtotime($record['created_at'])); ?>
                                    </div>
                                </div>
                                <?php if (!empty($record['reason'])): ?>
                                    <div class="history-reason">
                                        <strong>Reason:</strong> <?php echo htmlspecialchars($record['reason']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($record['changed_by'])): ?>
                                    <div style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">
                                        Changed by: User #<?php echo $record['changed_by']; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color: #666; text-align: center; padding: 20px;">
                        No status history available.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Duplicate Tab -->
        <div id="duplicate-tab" class="tab-content">
            <div class="card">
                <h2>Duplicate Fee Structure</h2>
                <p>Create a copy of this fee for a different academic year or term.</p>
                
                <form method="POST">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label>Source Fee Details (Read-only)</label>
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 4px;">
                                <p><strong>Class:</strong> <?php echo htmlspecialchars($fee['class_name']); ?></p>
                                <p><strong>Term:</strong> <?php echo htmlspecialchars($fee['term']); ?></p>
                                <p><strong>Type:</strong> <?php echo $feeTypes[$fee['fee_type']] ?? $fee['fee_type']; ?></p>
                                <p><strong>Amount:</strong> ₦<?php echo number_format($fee['amount'], 2); ?></p>
                                <p><strong>Status:</strong> 
                                    <span class="status-badge" style="background: <?php echo $feeStatuses[$fee['status']]['color'] ?? '#95a5a6'; ?>; font-size: 12px;">
                                        <?php echo $feeStatuses[$fee['status']]['label'] ?? ucfirst($fee['status']); ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>New Academic Year *</label>
                            <input type="text" name="new_academic_year" 
                                   value="<?php echo date('Y') + 1 . '/' . (date('Y') + 2); ?>" required>
                            <small>Format: YYYY/YYYY e.g., 2026/2027</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Copy Options</label>
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 4px;">
                                <label>
                                    <input type="checkbox" name="copy_installment_settings" checked> 
                                    Copy installment settings
                                </label><br>
                                <label>
                                    <input type="checkbox" name="copy_description" checked> 
                                    Copy description
                                </label><br>
                                <label>
                                    <input type="checkbox" name="copy_amount" checked> 
                                    Copy amount
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="duplicate_fee" class="btn btn-primary">Duplicate Fee</button>
                        <a href="#edit-tab" class="btn btn-secondary" onclick="showTab('edit-tab')">Back to Edit</a>
                    </div>
                </form>
                
                <hr style="margin: 30px 0;">
                
                <h3>Duplicate for Other Classes</h3>
                <p>Apply this fee structure to multiple classes at once:</p>
                
                <form method="POST" action="bulk_duplicate_fee.php">
                    <input type="hidden" name="source_fee_id" value="<?php echo $feeId; ?>">
                    
                    <div class="form-group">
                        <label>Select Classes to Apply This Fee To</label>
                        <select name="target_class_ids[]" multiple size="5" style="height: 150px; width: 100%;">
                            <?php foreach ($classes as $class): ?>
                                <?php if ($class['id'] != $fee['class_id']): ?>
                                    <option value="<?php echo $class['id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small>Hold Ctrl to select multiple classes</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Adjustment Percentage (%)</label>
                        <input type="number" name="adjustment_percentage" min="-100" max="100" step="1" value="0">
                        <small>Positive increases fee, negative decreases. 0 = same amount</small>
                    </div>
                    
                    <button type="submit" class="btn btn-warning">Apply to Selected Classes</button>
                </form>
            </div>
        </div>
        
        <!-- History Tab (Rest of the original history tab remains the same) -->
        <div id="history-tab" class="tab-content">
            <!-- ... rest of the original history tab content ... -->
            <!-- This section remains unchanged from the original -->
        </div>
    </div>
    
    <script>
        // Initialize installment options on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleInstallmentOptions();
            
            // Enable/disable status update button based on selection
            const statusOptions = document.querySelectorAll('.status-option');
            const updateBtn = document.getElementById('updateStatusBtn');
            
            statusOptions.forEach(option => {
                option.addEventListener('click', function() {
                    updateBtn.disabled = false;
                });
            });
        });
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
