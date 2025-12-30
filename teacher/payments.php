<?php
// clark/payments.php
session_start();
require_once '../config/db.php';
require_once '../helpers/payment_helper.php';

// Check if user is clerk/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['principal', 'teacher', 'staff'])) {
    header('Location: ../login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$paymentHelper = new PaymentHelper();

// Handle actions
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'verify':
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("UPDATE student_payments SET status = 'completed', 
                                      verified_by = ?, verified_at = NOW() 
                                      WHERE id = ?");
                $stmt->execute([$userId, $_GET['id']]);
                header('Location: payments.php?verified=1');
                exit();
            }
            break;
            
        case 'reject':
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("UPDATE student_payments SET status = 'cancelled', 
                                      verified_by = ?, verified_at = NOW() 
                                      WHERE id = ?");
                $stmt->execute([$userId, $_GET['id']]);
                header('Location: payments.php?rejected=1');
                exit();
            }
            break;
    }
}

// Search filters
$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$classFilter = $_GET['class_id'] ?? '';

// Build query
$query = "SELECT sp.*, s.full_name as student_name, s.admission_no, c.class_name, u.full_name as verified_by_name
          FROM student_payments sp
          JOIN students s ON sp.student_id = s.id
          JOIN classes c ON sp.class_id = c.id
          LEFT JOIN users u ON sp.verified_by = u.id
          WHERE 1=1";
          
$params = [];

if ($searchTerm) {
    $query .= " AND (s.full_name LIKE ? OR s.admission_no LIKE ? OR sp.receipt_number LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

if ($statusFilter) {
    $query .= " AND sp.status = ?";
    $params[] = $statusFilter;
}

if ($classFilter) {
    $query .= " AND sp.class_id = ?";
    $params[] = $classFilter;
}

$query .= " ORDER BY sp.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Get classes for filter
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();

// Statistics
$totalCollected = $pdo->query("SELECT SUM(amount_paid) FROM student_payments WHERE status = 'completed'")->fetchColumn();
$pendingPayments = $pdo->query("SELECT COUNT(*) FROM student_payments WHERE status = 'pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management - Clark</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: #34495e; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .stat-card h3 { margin-top: 0; color: #2c3e50; }
        .stat-card .amount { font-size: 24px; font-weight: bold; color: #27ae60; }
        .filters { background: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .filters form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .btn { padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-primary { background: #3498db; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .table-container { background: white; padding: 20px; border-radius: 5px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #2c3e50; color: white; position: sticky; top: 0; }
        tr:hover { background: #f5f5f5; }
        .status-badge { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
        .status-pending { background: #fef9e7; color: #f39c12; }
        .status-completed { background: #eafaf1; color: #27ae60; }
        .status-cancelled { background: #fdedec; color: #e74c3c; }
        .action-buttons { display: flex; gap: 5px; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 10% auto; padding: 20px; width: 80%; max-width: 500px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
         <a href="index.php" class="btn btn-success">Back to Dashboard</a>
                                   <br>
                                   <br>
        <div class="header">
            <h1>Payment Management System</h1>
            <p>Welcome, Clark | Manage student payments and receipts</p>
        </div>
        
        <?php if (isset($_GET['verified'])): ?>
            <div style="background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                Payment verified successfully!
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats">
            <div class="stat-card">
                <h3>Total Collected</h3>
                <div class="amount"><?php echo $paymentHelper->formatCurrency($totalCollected); ?></div>
            </div>
            <div class="stat-card">
                <h3>Pending Payments</h3>
                <div class="amount"><?php echo $pendingPayments; ?></div>
            </div>
            <div class="stat-card">
                <h3>Today's Collection</h3>
                <div class="amount">
                    <?php 
                    $today = $pdo->query("SELECT SUM(amount_paid) FROM student_payments 
                                         WHERE DATE(payment_date) = CURDATE() AND status = 'completed'")->fetchColumn();
                    echo $paymentHelper->formatCurrency($today);
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>This Month</h3>
                <div class="amount">
                    <?php 
                    $month = $pdo->query("SELECT SUM(amount_paid) FROM student_payments 
                                         WHERE MONTH(payment_date) = MONTH(CURDATE()) 
                                         AND YEAR(payment_date) = YEAR(CURDATE())
                                         AND status = 'completed'")->fetchColumn();
                    echo $paymentHelper->formatCurrency($month);
                    ?>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <form method="GET">
                <div>
                    <input type="text" name="search" placeholder="Search by name, admission no, receipt..." 
                           value="<?php echo htmlspecialchars($searchTerm); ?>">
                </div>
                <div>
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $statusFilter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div>
                    <select name="class_id">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" 
                                <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="payments.php" class="btn">Clear</a>
                </div>
            </form>
        </div>
        
        <!-- Payments Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Receipt No</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Verified By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($payment['receipt_number']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($payment['student_name']); ?><br>
                                <small><?php echo htmlspecialchars($payment['admission_no']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($payment['class_name']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                            <td><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></td>
                            <td>
                                <?php 
                                echo ucfirst($payment['payment_type']);
                                if ($payment['payment_type'] == 'installment') {
                                    echo ' (' . $payment['installment_number'] . '/' . $payment['total_installments'] . ')';
                                }
                                ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $payment['status']; ?>">
                                    <?php echo ucfirst($payment['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                echo $payment['verified_by_name'] ?: 'Not verified';
                                if ($payment['verified_at']) {
                                    echo '<br><small>' . date('d/m/Y H:i', strtotime($payment['verified_at'])) . '</small>';
                                }
                                ?>
                            </td>
                            <td class="action-buttons">
                                <a href="view_payment.php?id=<?php echo $payment['id']; ?>" class="btn btn-primary">View</a>
                                <?php if ($payment['status'] == 'pending'): ?>
                                    <a href="?action=verify&id=<?php echo $payment['id']; ?>" class="btn btn-success">Verify</a>
                                    <a href="?action=reject&id=<?php echo $payment['id']; ?>" class="btn btn-danger" 
                                       onclick="return confirm('Reject this payment?')">Reject</a>
                                <?php endif; ?>
                                <a href="receipt.php?id=<?php echo $payment['id']; ?>" target="_blank" class="btn btn-warning">Receipt</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>