<?php
// principal/payments_dashboard.php
session_start();
require_once '../config/db.php';
require_once '../helpers/payment_helper.php';

// Check if user is principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: ../login.php');
    exit();
}

$paymentHelper = new PaymentHelper();

// Get statistics
$stats = [];

// Total collection
$stats['total_collected'] = $pdo->query("SELECT SUM(amount_paid) FROM student_payments WHERE status = 'completed'")->fetchColumn();

// Collection by term
$stats['term_collection'] = $pdo->query("SELECT term, academic_year, SUM(amount_paid) as total 
                                         FROM student_payments 
                                         WHERE status = 'completed' 
                                         GROUP BY term, academic_year 
                                         ORDER BY academic_year DESC, term")->fetchAll();

// Collection by class
$stats['class_collection'] = $pdo->query("SELECT c.class_name, SUM(sp.amount_paid) as total 
                                          FROM student_payments sp
                                          JOIN classes c ON sp.class_id = c.id
                                          WHERE sp.status = 'completed'
                                          GROUP BY sp.class_id 
                                          ORDER BY total DESC")->fetchAll();

// Defaulters
$stats['defaulters'] = $pdo->query("SELECT s.full_name, s.admission_no, c.class_name, 
                                   (SELECT SUM(amount_paid) FROM student_payments sp2 
                                    WHERE sp2.student_id = s.id AND sp2.status = 'completed') as total_paid,
                                   (SELECT SUM(fs.amount) FROM fee_structure fs 
                                    WHERE fs.class_id = s.class_id AND fs.is_active = 1) as total_fee
                                   FROM students s
                                   JOIN classes c ON s.class_id = c.id
                                   WHERE s.is_active = 1
                                   HAVING total_paid < total_fee OR total_paid IS NULL
                                   LIMIT 20")->fetchAll();

// Recent payments
$stats['recent_payments'] = $pdo->query("SELECT sp.*, s.full_name, c.class_name 
                                         FROM student_payments sp
                                         JOIN students s ON sp.student_id = s.id
                                         JOIN classes c ON sp.class_id = c.id
                                         ORDER BY sp.created_at DESC LIMIT 10")->fetchAll();

// Payment methods distribution
$stats['payment_methods'] = $pdo->query("SELECT payment_method, COUNT(*) as count, SUM(amount_paid) as total 
                                         FROM student_payments 
                                         WHERE status = 'completed' 
                                         GROUP BY payment_method")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Principal Payment Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .stat-card h3 { margin-top: 0; color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        .chart-container { height: 300px; }
        .table-container { background: white; padding: 20px; border-radius: 5px; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #34495e; color: white; }
        .highlight { background: #e8f4fc; font-weight: bold; }
        .export-btn { background: #27ae60; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; float: right; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Principal Payment Dashboard</h1>
            <p>Financial Overview and Analytics</p>
        </div>
        
        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Collection</h3>
                <div style="font-size: 32px; font-weight: bold; color: #27ae60;">
                    <?php echo $paymentHelper->formatCurrency($stats['total_collected']); ?>
                </div>
                <p>All time payments collected</p>
            </div>
            <a href="manage_fees.php">manage school fees</a>
            <div class="stat-card">
                <h3>Active Students</h3>
                <div style="font-size: 32px; font-weight: bold; color: #3498db;">
                    <?php echo $pdo->query("SELECT COUNT(*) FROM students WHERE is_active = 1")->fetchColumn(); ?>
                </div>
                <p>Currently enrolled students</p>
            </div>
            
            <div class="stat-card">
                <h3>Payment Completion Rate</h3>
                <div style="font-size: 32px; font-weight: bold; color: #9b59b6;">
                    <?php 
                    $totalStudents = $pdo->query("SELECT COUNT(*) FROM students WHERE is_active = 1")->fetchColumn();
                    $paidStudents = $pdo->query("SELECT COUNT(DISTINCT student_id) FROM student_payments WHERE status = 'completed'")->fetchColumn();
                    $rate = $totalStudents > 0 ? round(($paidStudents / $totalStudents) * 100, 1) : 0;
                    echo $rate . '%';
                    ?>
                </div>
                <p>Students with completed payments</p>
            </div>
        </div>
        
        <!-- Charts and Tables -->
        <div class="stats-grid">
            <!-- Payment Methods -->
            <div class="stat-card">
                <h3>Payment Methods Distribution</h3>
                <div class="chart-container">
                    <canvas id="paymentMethodsChart"></canvas>
                </div>
            </div>
            
            <!-- Term Collection -->
            <div class="stat-card">
                <h3>Collection by Term</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Term</th>
                            <th>Academic Year</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['term_collection'] as $term): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($term['term']); ?></td>
                                <td><?php echo htmlspecialchars($term['academic_year']); ?></td>
                                <td><?php echo $paymentHelper->formatCurrency($term['total']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Class Collection -->
        <div class="table-container">
            <h3>Collection by Class</h3>
            <table>
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Total Collected</th>
                        <th>No. of Payments</th>
                        <th>Average Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['class_collection'] as $class): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                            <td><?php echo $paymentHelper->formatCurrency($class['total']); ?></td>
                            <td>
                                <?php 
                                $count = $pdo->query("SELECT COUNT(*) FROM student_payments 
                                                     WHERE class_id = (SELECT id FROM classes WHERE class_name = '{$class['class_name']}')
                                                     AND status = 'completed'")->fetchColumn();
                                echo $count;
                                ?>
                            </td>
                            <td>
                                <?php 
                                $avg = $count > 0 ? $class['total'] / $count : 0;
                                echo $paymentHelper->formatCurrency($avg);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Defaulters List -->
        <div class="table-container">
            <h3>Top Defaulters (Unpaid Balances)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Admission No</th>
                        <th>Class</th>
                        <th>Paid</th>
                        <th>Total Fee</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['defaulters'] as $defaulter): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($defaulter['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($defaulter['admission_no']); ?></td>
                            <td><?php echo htmlspecialchars($defaulter['class_name']); ?></td>
                            <td><?php echo $paymentHelper->formatCurrency($defaulter['total_paid'] ?: 0); ?></td>
                            <td><?php echo $paymentHelper->formatCurrency($defaulter['total_fee'] ?: 0); ?></td>
                            <td style="color: red; font-weight: bold;">
                                <?php 
                                $balance = ($defaulter['total_fee'] ?: 0) - ($defaulter['total_paid'] ?: 0);
                                echo $paymentHelper->formatCurrency($balance);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Recent Payments -->
        <div class="table-container">
            <h3>Recent Payments</h3>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['recent_payments'] as $payment): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($payment['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($payment['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($payment['class_name']); ?></td>
                            <td><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                            <td>
                                <span style="color: <?php echo $payment['status'] == 'completed' ? 'green' : 'orange'; ?>;">
                                    <?php echo ucfirst($payment['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // Payment Methods Chart
        const methodsCtx = document.getElementById('paymentMethodsChart').getContext('2d');
        const methodsChart = new Chart(methodsCtx, {
            type: 'doughnut',
            data: {
                labels: [
                    <?php foreach ($stats['payment_methods'] as $method): ?>
                        '<?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    data: [
                        <?php foreach ($stats['payment_methods'] as $method): ?>
                            <?php echo $method['count']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        '#3498db', '#27ae60', '#e74c3c', '#f39c12', '#9b59b6', '#1abc9c'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>