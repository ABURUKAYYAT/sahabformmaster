<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'clerk') {
    header('Location: ../index.php');
    exit;
}

$current_school_id = require_school_auth();
PaymentHelper::ensureSchema();

$message = '';
$error = '';
$bankMessage = '';
$bankError = '';
$clerk_name = $_SESSION['full_name'] ?? 'Clerk';

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $fee_type = trim($_POST['fee_type'] ?? '');
    $class_id = trim($_POST['class_id'] ?? '');
    $term = trim($_POST['term'] ?? '');
    $academic_year = trim($_POST['academic_year'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $due_date = $_POST['due_date'] ?: null;
    $allow_installments = isset($_POST['allow_installments']) ? 1 : 0;
    $max_installments = (int)($_POST['max_installments'] ?? 1);
    $late_fee_percentage = (float)($_POST['late_fee_percentage'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE fee_structure
                                  SET fee_type = ?, class_id = ?, term = ?, academic_year = ?, description = ?, amount = ?,
                                      due_date = ?, allow_installments = ?, max_installments = ?, late_fee_percentage = ?, is_active = ?
                                  WHERE id = ? AND school_id = ?");
            $stmt->execute([$fee_type, $class_id, $term, $academic_year, $description, $amount, $due_date, $allow_installments, $max_installments, $late_fee_percentage, $is_active, $id, $current_school_id]);
            $message = 'Fee updated successfully.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO fee_structure
                                  (school_id, fee_type, class_id, term, academic_year, description, amount, due_date,
                                   allow_installments, max_installments, late_fee_percentage, is_active)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$current_school_id, $fee_type, $class_id, $term, $academic_year, $description, $amount, $due_date, $allow_installments, $max_installments, $late_fee_percentage, $is_active]);
            $message = 'Fee created successfully.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM fee_structure WHERE id = ? AND school_id = ?");
        $stmt->execute([$id, $current_school_id]);
        $message = 'Fee deleted successfully.';
    }
}

// Handle bank account create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bank_save') {
    $bankId = isset($_POST['bank_id']) ? (int)$_POST['bank_id'] : 0;
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $is_primary = isset($_POST['is_primary']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($bank_name === '' || $account_name === '' || $account_number === '') {
        $bankError = 'Bank name, account name, and account number are required.';
    } else {
        try {
            if ($is_primary) {
                $pdo->prepare("UPDATE school_bank_accounts SET is_primary = 0 WHERE school_id = ?")->execute([$current_school_id]);
            }

            if ($bankId > 0) {
                $stmt = $pdo->prepare("UPDATE school_bank_accounts
                                      SET bank_name = ?, account_name = ?, account_number = ?, is_primary = ?, is_active = ?
                                      WHERE id = ? AND school_id = ?");
                $stmt->execute([$bank_name, $account_name, $account_number, $is_primary, $is_active, $bankId, $current_school_id]);
                $bankMessage = 'Bank account updated successfully.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO school_bank_accounts
                                      (school_id, bank_name, account_name, account_number, is_primary, is_active)
                                      VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$current_school_id, $bank_name, $account_name, $account_number, $is_primary, $is_active]);
                $bankMessage = 'Bank account added successfully.';
            }
        } catch (Exception $e) {
            $bankError = $e->getMessage();
        }
    }
}

// Handle bank account delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bank_delete') {
    $bankId = (int)($_POST['bank_id'] ?? 0);
    if ($bankId) {
        $stmt = $pdo->prepare("DELETE FROM school_bank_accounts WHERE id = ? AND school_id = ?");
        $stmt->execute([$bankId, $current_school_id]);
        $bankMessage = 'Bank account deleted successfully.';
    }
}

// Fetch record for edit
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editFee = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM fee_structure WHERE id = ? AND school_id = ?");
    $stmt->execute([$editId, $current_school_id]);
    $editFee = $stmt->fetch();
}

// Fetch bank account for edit
$editBankId = isset($_GET['edit_bank']) ? (int)$_GET['edit_bank'] : 0;
$editBank = null;
if ($editBankId) {
    $stmt = $pdo->prepare("SELECT * FROM school_bank_accounts WHERE id = ? AND school_id = ?");
    $stmt->execute([$editBankId, $current_school_id]);
    $editBank = $stmt->fetch();
}

$classesStmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$classesStmt->execute([$current_school_id]);
$classes = $classesStmt->fetchAll();

$feesStmt = $pdo->prepare("SELECT fs.*, c.class_name
                          FROM fee_structure fs
                          JOIN classes c ON fs.class_id = c.id
                          WHERE fs.school_id = ?
                          ORDER BY fs.academic_year DESC, fs.term DESC, c.class_name ASC");
$feesStmt->execute([$current_school_id]);
$fees = $feesStmt->fetchAll();

$feeTypeOptions = include('../config/payment_config.php');
$feeTypeOptions = $feeTypeOptions['fee_types'] ?? [];

$bankStmt = $pdo->prepare("SELECT * FROM school_bank_accounts WHERE school_id = ? ORDER BY is_primary DESC, bank_name ASC");
$bankStmt->execute([$current_school_id]);
$bankAccounts = $bankStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Structure - Clerk</title>
    <link rel="stylesheet" href="../assets/css/education-theme-main.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --clerk-surface: #ffffff;
            --clerk-ink: #0f172a;
            --clerk-muted: #64748b;
            --clerk-border: #e2e8f0;
            --clerk-accent: #0ea5e9;
            --clerk-accent-strong: #2563eb;
            --clerk-radius: 12px;
        }
        .page-container { padding: 24px; }
        .card { background: var(--clerk-surface); border-radius: var(--clerk-radius); padding: 16px; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06); margin-bottom: 16px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .form-grid input,
        .form-grid select,
        .form-grid textarea {
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--clerk-border);
            background: #f8fafc;
            color: var(--clerk-ink);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .form-grid input:focus,
        .form-grid select:focus,
        .form-grid textarea:focus {
            border-color: var(--clerk-accent);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
            background: #ffffff;
        }
        .table-container { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 720px; }
        th, td { padding: 12px; border-bottom: 1px solid var(--clerk-border); text-align: left; }
        .btn { padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.85rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-link { background: transparent; color: #2563eb; text-decoration: none; }
        .notice { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
        .notice.success { background: #dcfce7; color: #166534; }
        .notice.error { background: #fee2e2; color: #991b1b; }
        .btn-logout.clerk-logout { background: #dc2626; }
        .btn-logout.clerk-logout:hover { background: #b91c1c; }
        @media (max-width: 900px) {
            .form-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-container { padding: 16px; }
            .card { padding: 14px; }
            table { min-width: 640px; font-size: 0.9rem; }
            th, td { padding: 10px; }
        }
        @media (max-width: 520px) {
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
<?php include '../includes/mobile_navigation.php'; ?>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-left">
            <div class="school-logo-container">
                <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                <div class="school-info">
                    <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                    <p class="school-tagline">Clerk Portal</p>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="teacher-info">
                <p class="teacher-label">Clerk</p>
                <span class="teacher-name"><?php echo htmlspecialchars($clerk_name); ?></span>
            </div>
            <a href="logout.php" class="btn-logout clerk-logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <?php include '../includes/clerk_sidebar.php'; ?>
    <main class="main-content">
        <div class="page-container">
            <h2>Fee Structure</h2>

            <?php if ($message): ?>
                <div class="notice success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
                <h4><?php echo $editFee ? 'Edit Fee Item' : 'Add Fee Item'; ?></h4>
                <form method="POST">
                    <input type="hidden" name="action" value="save">
                    <?php if ($editFee): ?>
                        <input type="hidden" name="id" value="<?php echo $editFee['id']; ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div>
                            <label>Fee Type</label>
                            <select name="fee_type" required>
                                <?php foreach ($feeTypeOptions as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $editFee && $editFee['fee_type'] === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Class</label>
                            <select name="class_id" required>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $editFee && (string)$editFee['class_id'] === (string)$class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Term</label>
                            <select name="term" required>
                                <?php foreach (['1st Term','2nd Term','3rd Term'] as $term): ?>
                                    <option value="<?php echo $term; ?>" <?php echo $editFee && $editFee['term'] === $term ? 'selected' : ''; ?>>
                                        <?php echo $term; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Academic Year</label>
                            <input type="text" name="academic_year" value="<?php echo htmlspecialchars($editFee['academic_year'] ?? ''); ?>" placeholder="2025/2026" required>
                        </div>
                        <div>
                            <label>Description</label>
                            <input type="text" name="description" value="<?php echo htmlspecialchars($editFee['description'] ?? ''); ?>">
                        </div>
                        <div>
                            <label>Amount</label>
                            <input type="number" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($editFee['amount'] ?? ''); ?>" required>
                        </div>
                        <div>
                            <label>Due Date</label>
                            <input type="date" name="due_date" value="<?php echo htmlspecialchars($editFee['due_date'] ?? ''); ?>">
                        </div>
                        <div>
                            <label>Late Fee %</label>
                            <input type="number" name="late_fee_percentage" step="0.01" min="0" value="<?php echo htmlspecialchars($editFee['late_fee_percentage'] ?? '0'); ?>">
                        </div>
                        <div>
                            <label>Max Installments</label>
                            <input type="number" name="max_installments" min="1" value="<?php echo htmlspecialchars($editFee['max_installments'] ?? '1'); ?>">
                        </div>
                        <div style="display:flex; gap:12px; align-items:center;">
                            <label><input type="checkbox" name="allow_installments" <?php echo !empty($editFee['allow_installments']) ? 'checked' : ''; ?>> Allow Installments</label>
                            <label><input type="checkbox" name="is_active" <?php echo !empty($editFee['is_active']) || !$editFee ? 'checked' : ''; ?>> Active</label>
                        </div>
                    </div>
                    <div style="margin-top: 12px;">
                        <button class="btn btn-primary" type="submit"><?php echo $editFee ? 'Update' : 'Create'; ?></button>
                        <?php if ($editFee): ?>
                            <a class="btn btn-link" href="fee_structure.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <h4><?php echo $editBank ? 'Edit Bank Account' : 'Add Bank Account'; ?></h4>

                <?php if ($bankMessage): ?>
                    <div class="notice success"><?php echo htmlspecialchars($bankMessage); ?></div>
                <?php endif; ?>
                <?php if ($bankError): ?>
                    <div class="notice error"><?php echo htmlspecialchars($bankError); ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="action" value="bank_save">
                    <?php if ($editBank): ?>
                        <input type="hidden" name="bank_id" value="<?php echo $editBank['id']; ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div>
                            <label>Bank Name</label>
                            <input type="text" name="bank_name" value="<?php echo htmlspecialchars($editBank['bank_name'] ?? ''); ?>" required>
                        </div>
                        <div>
                            <label>Account Name</label>
                            <input type="text" name="account_name" value="<?php echo htmlspecialchars($editBank['account_name'] ?? ''); ?>" required>
                        </div>
                        <div>
                            <label>Account Number</label>
                            <input type="text" name="account_number" value="<?php echo htmlspecialchars($editBank['account_number'] ?? ''); ?>" required>
                        </div>
                        <div style="display:flex; gap:12px; align-items:center;">
                            <label><input type="checkbox" name="is_primary" <?php echo !empty($editBank['is_primary']) ? 'checked' : ''; ?>> Primary</label>
                            <label><input type="checkbox" name="is_active" <?php echo !empty($editBank['is_active']) || !$editBank ? 'checked' : ''; ?>> Active</label>
                        </div>
                    </div>
                    <div style="margin-top: 12px;">
                        <button class="btn btn-primary" type="submit"><?php echo $editBank ? 'Update' : 'Create'; ?></button>
                        <?php if ($editBank): ?>
                            <a class="btn btn-link" href="fee_structure.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <h4>School Bank Accounts</h4>
                <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Bank</th>
                            <th>Account Name</th>
                            <th>Account Number</th>
                            <th>Primary</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bankAccounts)): ?>
                            <tr><td colspan="6">No bank accounts added.</td></tr>
                        <?php else: ?>
                            <?php foreach ($bankAccounts as $account): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($account['bank_name']); ?></td>
                                    <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                    <td><?php echo htmlspecialchars($account['account_number']); ?></td>
                                    <td><?php echo !empty($account['is_primary']) ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo !empty($account['is_active']) ? 'Active' : 'Inactive'; ?></td>
                                    <td>
                                        <a class="btn btn-link" href="fee_structure.php?edit_bank=<?php echo $account['id']; ?>">Edit</a>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="bank_delete">
                                            <input type="hidden" name="bank_id" value="<?php echo $account['id']; ?>">
                                            <button class="btn btn-danger" type="submit" onclick="return confirm('Delete this bank account?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="card">
                <h4>Fee Items</h4>
                <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Class</th>
                            <th>Term</th>
                            <th>Year</th>
                            <th>Amount</th>
                            <th>Installments</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fees)): ?>
                            <tr><td colspan="8">No fee items found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($fees as $fee): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($feeTypeOptions[$fee['fee_type']] ?? $fee['fee_type']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['term']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['academic_year']); ?></td>
                                    <td><?php echo number_format($fee['amount'], 2); ?></td>
                                    <td><?php echo $fee['allow_installments'] ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo $fee['is_active'] ? 'Active' : 'Inactive'; ?></td>
                                    <td>
                                        <a class="btn btn-link" href="fee_structure.php?edit=<?php echo $fee['id']; ?>">Edit</a>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $fee['id']; ?>">
                                            <button class="btn btn-danger" type="submit" onclick="return confirm('Delete this fee item?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </main>
</div>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
