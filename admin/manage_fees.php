<?php
// admin/manage_fees.php
session_start();
require_once '../config/db.php';

// Check if user is admin/principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../login.php");
    exit;
}

// Handle form submission for adding new fee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_fee'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO fee_structure 
                              (class_id, academic_year, term, fee_type, description, 
                               amount, due_date, allow_installments, max_installments, created_by) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $_POST['class_id'],
            $_POST['academic_year'],
            $_POST['term'],
            $_POST['fee_type'],
            $_POST['description'],
            $_POST['amount'],
            $_POST['due_date'],
            isset($_POST['allow_installments']) ? 1 : 0,
            $_POST['max_installments'] ?? 1,
            $_SESSION['user_id']
        ]);
        
        $success = "Fee structure added successfully!";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get all classes
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();

// Get fee structure
$fees = $pdo->query("SELECT fs.*, c.class_name 
                     FROM fee_structure fs 
                     JOIN classes c ON fs.class_id = c.id 
                     ORDER BY fs.academic_year DESC, fs.term, c.class_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Fee Structure</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { background: #3498db; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: #2980b9; }
        .btn-success { background: #27ae60; }
        .btn-danger { background: #e74c3c; }
        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table th, .table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .table th { background: #2c3e50; color: white; }
        .alert { padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-danger { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Manage School Fee Structure</h1>
            <p>Add and manage fees for different classes and terms</p>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
                      
        <!-- Existing Fee Structure -->
        <div class="card">
            <h2>Existing Fee Structure</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Academic Year</th>
                        <th>Term</th>
                        <th>Fee Type</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Installments</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fees)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; color: #666;">
                                No fee structure found. Please add fees using the form above.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($fees as $fee): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fee['class_name']); ?></td>
                                <td><?php echo htmlspecialchars($fee['academic_year']); ?></td>
                                <td><?php echo htmlspecialchars($fee['term']); ?></td>
                                <td>
                                    <?php 
                                    $feeTypes = [
                                        'tuition' => 'Tuition',
                                        'exam' => 'Exam',
                                        'sports' => 'Sports',
                                        'library' => 'Library',
                                        'development' => 'Development',
                                        'other' => 'Other'
                                    ];
                                    echo $feeTypes[$fee['fee_type']] ?? $fee['fee_type'];
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($fee['description']); ?></td>
                                <td>₦<?php echo number_format($fee['amount'], 2); ?></td>
                                <td><?php echo $fee['due_date'] ? date('d/m/Y', strtotime($fee['due_date'])) : '-'; ?></td>
                                <td>
                                    <?php if ($fee['allow_installments']): ?>
                                        Yes (Max: <?php echo $fee['max_installments']; ?>)
                                    <?php else: ?>
                                        No
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="color: <?php echo $fee['is_active'] ? 'green' : 'red'; ?>;">
                                        <?php echo $fee['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit_fee.php?id=<?php echo $fee['id']; ?>" class="btn">Edit</a>
                                    <a href="delete_fee.php?id=<?php echo $fee['id']; ?>" 
                                       class="btn btn-danger" 
                                       onclick="return confirm('Delete this fee structure?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Add New Fee Form -->
        <div class="card">
            <h2>Add New Fee Structure</h2>
            <form method="POST">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    <div class="form-group">
                        <label>Class *</label>
                        <select name="class_id" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Academic Year *</label>
                        <input type="text" name="academic_year" value="<?php echo date('Y') . '/' . (date('Y') + 1); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Term *</label>
                        <select name="term" required>
                            <option value="1st Term">1st Term</option>
                            <option value="2nd Term">2nd Term</option>
                            <option value="3rd Term">3rd Term</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Fee Type *</label>
                        <select name="fee_type" required>
                            <option value="tuition">Tuition Fee</option>
                            <option value="exam">Examination Fee</option>
                            <option value="sports">Sports Fee</option>
                            <option value="library">Library Fee</option>
                            <option value="development">Development Levy</option>
                            <option value="other">Other Charges</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description" placeholder="e.g., Term 1 Tuition Fee">
                    </div>
                    
                    <div class="form-group">
                        <label>Amount (₦) *</label>
                        <input type="number" name="amount" min="0" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="allow_installments" value="1">
                            Allow Installments
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label>Max Installments (if allowed)</label>
                        <select name="max_installments">
                            <option value="1">1 (Full Payment)</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" name="add_fee" class="btn btn-success">Add Fee Structure</button>
            </form>
        </div>

        <!-- Quick Add Multiple Classes -->
        <div class="card">
            <h2>Quick Add Fees for Multiple Classes</h2>
            <form method="POST" action="bulk_add_fees.php">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div class="form-group">
                        <label>Select Classes</label>
                        <select name="class_ids[]" multiple size="5" required style="height: 150px;">
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Hold Ctrl to select multiple classes</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" value="<?php echo date('Y') . '/' . (date('Y') + 1); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Term</label>
                        <select name="term" required>
                            <option value="1st Term">1st Term</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Fee Type</label>
                        <select name="fee_type" required>
                            <option value="tuition">Tuition Fee</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Base Amount (₦)</label>
                        <input type="number" name="base_amount" min="0" step="1000" value="100000" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Increment per Level (₦)</label>
                        <input type="number" name="increment" min="0" step="1000" value="5000">
                        <small>Add this amount for each higher class level</small>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-success">Add Fees to Selected Classes</button>
            </form>
        </div>
    </div>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
