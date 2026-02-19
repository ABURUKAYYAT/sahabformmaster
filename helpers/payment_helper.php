<?php
// helpers/payment_helper.php
require_once '../config/db.php';

class PaymentHelper { 
    /**
     * Ensure payment schema is ready (creates missing tables/columns safely)
     */
    public static function ensureSchema() {
        global $pdo;

        self::createTableIfMissing('payment_attachments', "
            CREATE TABLE payment_attachments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                payment_id BIGINT UNSIGNED NOT NULL,
                school_id INT UNSIGNED NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_type VARCHAR(100) NULL,
                uploaded_by INT UNSIGNED NULL,
                role VARCHAR(50) NULL,
                uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        self::createTableIfMissing('payment_installments', "
            CREATE TABLE payment_installments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                payment_id BIGINT UNSIGNED NOT NULL,
                installment_number INT NOT NULL,
                due_date DATE NULL,
                amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                payment_date DATE NULL,
                status VARCHAR(50) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        self::createTableIfMissing('school_bank_accounts', "
            CREATE TABLE school_bank_accounts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                school_id INT UNSIGNED NULL,
                bank_name VARCHAR(255) NOT NULL,
                account_name VARCHAR(255) NOT NULL,
                account_number VARCHAR(100) NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        self::createTableIfMissing('fee_structure', "
            CREATE TABLE fee_structure (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                school_id INT UNSIGNED NULL,
                fee_type VARCHAR(500) NOT NULL,
                class_id VARCHAR(500) NOT NULL,
                term VARCHAR(500) NOT NULL,
                academic_year VARCHAR(500) NOT NULL,
                description VARCHAR(1000) NULL,
                due_date DATE NULL,
                allow_installments TINYINT(1) NOT NULL DEFAULT 0,
                max_installments INT NOT NULL DEFAULT 1,
                late_fee_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        self::addColumnIfMissing('fee_structure', 'id', "ALTER TABLE fee_structure ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        self::addColumnIfMissing('fee_structure', 'school_id', "ALTER TABLE fee_structure ADD COLUMN school_id INT UNSIGNED NULL AFTER id");
        self::addColumnIfMissing('fee_structure', 'description', "ALTER TABLE fee_structure ADD COLUMN description VARCHAR(1000) NULL AFTER academic_year");
        self::addColumnIfMissing('fee_structure', 'due_date', "ALTER TABLE fee_structure ADD COLUMN due_date DATE NULL AFTER description");
        self::addColumnIfMissing('fee_structure', 'allow_installments', "ALTER TABLE fee_structure ADD COLUMN allow_installments TINYINT(1) NOT NULL DEFAULT 0 AFTER due_date");
        self::addColumnIfMissing('fee_structure', 'max_installments', "ALTER TABLE fee_structure ADD COLUMN max_installments INT NOT NULL DEFAULT 1 AFTER allow_installments");
        self::addColumnIfMissing('fee_structure', 'late_fee_percentage', "ALTER TABLE fee_structure ADD COLUMN late_fee_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER max_installments");
        self::addColumnIfMissing('fee_structure', 'created_at', "ALTER TABLE fee_structure ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER amount");
        self::addColumnIfMissing('fee_structure', 'updated_at', "ALTER TABLE fee_structure ADD COLUMN updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        self::addColumnIfMissing('student_payments', 'school_id', "ALTER TABLE student_payments ADD COLUMN school_id INT UNSIGNED NULL AFTER id");
        self::addColumnIfMissing('student_payments', 'receipt_number', "ALTER TABLE student_payments ADD COLUMN receipt_number VARCHAR(50) NULL AFTER school_id");
        self::addColumnIfMissing('student_payments', 'transaction_id', "ALTER TABLE student_payments ADD COLUMN transaction_id VARCHAR(100) NULL AFTER receipt_number");
        self::addColumnIfMissing('student_payments', 'payment_type', "ALTER TABLE student_payments ADD COLUMN payment_type VARCHAR(50) NULL AFTER payment_method");
        self::addColumnIfMissing('student_payments', 'fee_type', "ALTER TABLE student_payments ADD COLUMN fee_type VARCHAR(100) NULL AFTER payment_type");
        self::addColumnIfMissing('student_payments', 'bank_account_id', "ALTER TABLE student_payments ADD COLUMN bank_account_id INT UNSIGNED NULL AFTER fee_type");
        self::addColumnIfMissing('student_payments', 'verified_by', "ALTER TABLE student_payments ADD COLUMN verified_by INT UNSIGNED NULL AFTER bank_account_id");
        self::addColumnIfMissing('student_payments', 'verified_at', "ALTER TABLE student_payments ADD COLUMN verified_at DATETIME NULL AFTER verified_by");
        self::addColumnIfMissing('student_payments', 'notes', "ALTER TABLE student_payments ADD COLUMN notes TEXT NULL AFTER verified_at");
        self::addColumnIfMissing('student_payments', 'verification_notes', "ALTER TABLE student_payments ADD COLUMN verification_notes TEXT NULL AFTER notes");
        self::addColumnIfMissing('student_payments', 'total_installments', "ALTER TABLE student_payments ADD COLUMN total_installments INT NULL AFTER verification_notes");
        self::addColumnIfMissing('student_payments', 'installment_number', "ALTER TABLE student_payments ADD COLUMN installment_number INT NULL AFTER total_installments");
        self::addColumnIfMissing('student_payments', 'due_date', "ALTER TABLE student_payments ADD COLUMN due_date DATE NULL AFTER installment_number");

        self::addColumnIfMissing('payment_attachments', 'school_id', "ALTER TABLE payment_attachments ADD COLUMN school_id INT UNSIGNED NULL AFTER payment_id");
        self::addColumnIfMissing('payment_attachments', 'uploaded_at', "ALTER TABLE payment_attachments ADD COLUMN uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    }

    private static function createTableIfMissing($table, $createSql) {
        global $pdo;
        if (!self::tableExists($table)) {
            $pdo->exec($createSql);
        }
    }

    private static function tableExists($table) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT COUNT(*) 
                               FROM information_schema.tables 
                               WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private static function columnExists($table, $column) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT COUNT(*)
                               FROM information_schema.columns
                               WHERE table_schema = DATABASE()
                               AND table_name = ?
                               AND column_name = ?");
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }

    private static function addColumnIfMissing($table, $column, $sql) {
        global $pdo;
        if (self::tableExists($table) && !self::columnExists($table, $column)) {
            $pdo->exec($sql);
        }
    }
    
    /**
     * Generate unique receipt number
     */
    public static function generateReceiptNumber($schoolId = null) {
        $config = include('../config/payment_config.php');
        $prefix = $config['receipt_prefix'] . $config['receipt_year_prefix'];
        $lastReceipt = self::getLastReceiptNumber($schoolId);
        
        if ($lastReceipt) {
            $lastNumber = intval(substr($lastReceipt, -4));
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = $config['receipt_start_number'];
        }
        
        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Get last receipt number from database
     */
    private static function getLastReceiptNumber($schoolId = null) {
        global $pdo;
        if ($schoolId) {
            $stmt = $pdo->prepare("SELECT receipt_number FROM student_payments 
                                   WHERE receipt_number IS NOT NULL AND school_id = ?
                                   ORDER BY id DESC LIMIT 1");
            $stmt->execute([$schoolId]);
            return $stmt->fetchColumn();
        }
        $stmt = $pdo->query("SELECT receipt_number FROM student_payments 
                             WHERE receipt_number IS NOT NULL 
                             ORDER BY id DESC LIMIT 1");
        return $stmt->fetchColumn();
    }
    
    /**
     * Generate transaction ID
     */
    public static function generateTransactionId($studentId) {
        return 'TXN' . date('Ymd') . str_pad($studentId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);
    }
    
    /**
     * Calculate total fee for student
     */
    public static function calculateStudentFee($studentId, $classId, $term, $academicYear) {
        global $pdo;
        
        // Get fee structure for class
        $stmt = $pdo->prepare("SELECT SUM(amount) as total_fee FROM fee_structure 
                              WHERE class_id = ? AND term = ? AND academic_year = ? AND is_active = 1");
        $stmt->execute([$classId, $term, $academicYear]);
        $totalFee = $stmt->fetchColumn() ?: 0;
        
        // Apply waivers/discounts
        $waiver = self::getStudentWaiver($studentId, $term, $academicYear);
        if ($waiver) {
            if ($waiver['percentage'] > 0) {
                $discount = ($totalFee * $waiver['percentage']) / 100;
                $totalFee -= $discount;
            } elseif ($waiver['fixed_amount'] > 0) {
                $totalFee -= $waiver['fixed_amount'];
            }
        }
        
        return max(0, $totalFee);
    }
    
    /**
     * Get detailed fee breakdown by fee type
     */
    public static function getFeeBreakdown($classId, $term, $academicYear, $feeType = null, $schoolId = null) {
        global $pdo;
        
        $query = "SELECT * FROM fee_structure 
                  WHERE class_id = ? AND term = ? AND academic_year = ? AND is_active = 1";
        $params = [$classId, $term, $academicYear];

        if ($schoolId !== null) {
            $query .= " AND school_id = ?";
            $params[] = $schoolId;
        }
        
        if ($feeType) {
            $query .= " AND fee_type = ?";
            $params[] = $feeType;
        }
        
        $query .= " ORDER BY 
                   CASE fee_type 
                       WHEN 'tuition' THEN 1
                       WHEN 'exam' THEN 2
                       WHEN 'sports' THEN 3
                       WHEN 'library' THEN 4
                       WHEN 'development' THEN 5
                       ELSE 6
                   END, amount DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        $breakdown = [];
        $total = 0;
        
        while ($row = $stmt->fetch()) {
            $breakdown[] = [
                'id' => $row['id'],
                'fee_type' => $row['fee_type'],
                'type_label' => self::getFeeTypeLabel($row['fee_type']),
                'description' => $row['description'],
                'amount' => (float)$row['amount'],
                'due_date' => $row['due_date'],
                'allow_installments' => (bool)$row['allow_installments'],
                'max_installments' => (int)$row['max_installments'],
                'late_fee_percentage' => (float)$row['late_fee_percentage'],
                'is_active' => (bool)$row['is_active']
            ];
            $total += (float)$row['amount'];
        }
        
        return [
            'breakdown' => $breakdown,
            'total' => $total,
            'count' => count($breakdown),
            'has_installments' => array_reduce($breakdown, function($carry, $item) {
                return $carry || $item['allow_installments'];
            }, false)
        ];
    }
    
    /**
     * Get full fee breakdown for student with waiver calculations
     */
    public static function getFullFeeBreakdown($studentId, $classId, $term, $academicYear, $schoolId = null) {
        global $pdo;
        
        // Get base fee breakdown
        $feeBreakdown = self::getFeeBreakdown($classId, $term, $academicYear, null, $schoolId);
        
        // Get student waivers/discounts
        $waivers = self::getStudentWaiverDetails($studentId, $term, $academicYear);
        
        // Apply waivers to each fee type
        $adjustedBreakdown = [];
        $totalOriginal = 0;
        $totalDiscount = 0;
        $totalAdjusted = 0;
        
        foreach ($feeBreakdown['breakdown'] as $fee) {
            $originalAmount = $fee['amount'];
            $discountAmount = 0;
            $adjustedAmount = $originalAmount;
            
            // Check if there's a waiver for this fee type
            foreach ($waivers as $waiver) {
                if ($waiver['applies_to_all'] || $waiver['fee_type'] == $fee['fee_type']) {
                    if ($waiver['percentage'] > 0) {
                        $discount = ($originalAmount * $waiver['percentage']) / 100;
                        $discountAmount += $discount;
                        $adjustedAmount -= $discount;
                    }
                    if ($waiver['fixed_amount'] > 0) {
                        // For fixed amount waivers, distribute proportionally
                        $feeProportion = $originalAmount / $feeBreakdown['total'];
                        $discount = $waiver['fixed_amount'] * $feeProportion;
                        $discountAmount += $discount;
                        $adjustedAmount -= $discount;
                    }
                }
            }
            
            // Ensure adjusted amount doesn't go below 0
            $adjustedAmount = max(0, $adjustedAmount);
            
            $adjustedBreakdown[] = [
                'fee_type' => $fee['fee_type'],
                'type_label' => $fee['type_label'],
                'description' => $fee['description'],
                'original_amount' => $originalAmount,
                'discount_amount' => $discountAmount,
                'adjusted_amount' => $adjustedAmount,
                'discount_percentage' => $originalAmount > 0 ? ($discountAmount / $originalAmount) * 100 : 0,
                'allow_installments' => $fee['allow_installments'],
                'max_installments' => $fee['max_installments'],
                'due_date' => $fee['due_date'],
                'late_fee_percentage' => $fee['late_fee_percentage']
            ];
            
            $totalOriginal += $originalAmount;
            $totalDiscount += $discountAmount;
            $totalAdjusted += $adjustedAmount;
        }
        
        // Sort by adjusted amount (highest first)
        usort($adjustedBreakdown, function($a, $b) {
            return $b['adjusted_amount'] <=> $a['adjusted_amount'];
        });
        
        // Calculate payment summary
        $paymentSummary = self::getStudentPaymentSummary($studentId, $classId, $term, $academicYear, $schoolId);
        
        return [
            'breakdown' => $adjustedBreakdown,
            'summary' => [
                'total_original' => $totalOriginal,
                'total_discount' => $totalDiscount,
                'total_adjusted' => $totalAdjusted,
                'total_paid' => $paymentSummary['total_paid'],
                'total_pending' => $paymentSummary['total_pending'],
                'balance' => $totalAdjusted - $paymentSummary['total_paid'],
                'payment_status' => self::getPaymentStatus($totalAdjusted, $paymentSummary['total_paid']),
                'completion_percentage' => $totalAdjusted > 0 ? ($paymentSummary['total_paid'] / $totalAdjusted) * 100 : 100
            ],
            'waivers' => $waivers,
            'payment_installments' => $paymentSummary['installments']
        ];
    }
    
    /**
     * Get fee type label
     */
    private static function getFeeTypeLabel($feeType) {
        $labels = [
            'tuition' => 'Tuition Fee',
            'exam' => 'Examination Fee',
            'sports' => 'Sports Fee',
            'library' => 'Library Fee',
            'development' => 'Development Levy',
            'all' => 'All Fees',
            'other' => 'Other Charges'
        ];
        
        return $labels[$feeType] ?? ucfirst(str_replace('_', ' ', $feeType));
    }
    
    /**
     * Get detailed student waiver information
     */
    private static function getStudentWaiverDetails($studentId, $term, $academicYear) {
        global $pdo;
        
        $stmt = $pdo->prepare("SELECT 
                              fw.*,
                              u.full_name as approved_by_name,
                              ft.fee_type_name,
                              CASE 
                                  WHEN fw.applies_to_all_fees = 1 THEN 1
                                  WHEN fwf.fee_type IS NOT NULL THEN 1
                                  ELSE 0
                              END as applies_to_all,
                              fwf.fee_type
                              FROM fee_waivers fw
                              LEFT JOIN users u ON fw.approved_by = u.id
                              LEFT JOIN fee_waiver_fees fwf ON fw.id = fwf.waiver_id
                              LEFT JOIN fee_types ft ON fwf.fee_type = ft.fee_type_code
                              WHERE fw.student_id = ? 
                              AND fw.term = ? 
                              AND fw.academic_year = ?
                              AND fw.status = 'approved'
                              AND (fw.valid_until IS NULL OR fw.valid_until >= CURDATE())");
        
        $stmt->execute([$studentId, $term, $academicYear]);
        $waivers = $stmt->fetchAll();
        
        // If no specific fee waivers, check for general waivers
        if (empty($waivers)) {
            $stmt = $pdo->prepare("SELECT 
                                  fw.*,
                                  u.full_name as approved_by_name,
                                  1 as applies_to_all,
                                  NULL as fee_type
                                  FROM fee_waivers fw
                                  LEFT JOIN users u ON fw.approved_by = u.id
                                  WHERE fw.student_id = ? 
                                  AND fw.term = ? 
                                  AND fw.academic_year = ?
                                  AND fw.status = 'approved'
                                  AND fw.applies_to_all_fees = 1
                                  AND (fw.valid_until IS NULL OR fw.valid_until >= CURDATE())");
            
            $stmt->execute([$studentId, $term, $academicYear]);
            $waivers = $stmt->fetchAll();
        }
        
        return $waivers;
    }
    
    /**
     * Get student waiver/discount
     */
    private static function getStudentWaiver($studentId, $term, $academicYear) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT percentage, fixed_amount FROM fee_waivers 
                              WHERE student_id = ? AND term = ? AND academic_year = ? 
                              AND status = 'approved' AND (valid_until IS NULL OR valid_until >= CURDATE())
                              LIMIT 1");
        $stmt->execute([$studentId, $term, $academicYear]);
        return $stmt->fetch();
    }
    
    /**
     * Get student payment summary
     */
    private static function getStudentPaymentSummary($studentId, $classId, $term, $academicYear, $schoolId = null) {
        global $pdo;
        
        $stmt = $pdo->prepare("SELECT 
                              SUM(amount_paid) as total_paid,
                              COUNT(*) as payment_count,
                              MAX(payment_date) as last_payment_date,
                              GROUP_CONCAT(DISTINCT payment_method) as payment_methods
                              FROM student_payments 
                              WHERE student_id = ? 
                              AND class_id = ? 
                              AND term = ? 
                              AND academic_year = ?
                              AND status IN ('completed', 'partial', 'verified')");
        
        $params = [$studentId, $classId, $term, $academicYear];
        if ($schoolId !== null) {
            $stmt = $pdo->prepare("SELECT 
                                  SUM(amount_paid) as total_paid,
                                  COUNT(*) as payment_count,
                                  MAX(payment_date) as last_payment_date,
                                  GROUP_CONCAT(DISTINCT payment_method) as payment_methods
                                  FROM student_payments 
                                  WHERE student_id = ? 
                                  AND class_id = ? 
                                  AND term = ? 
                                  AND academic_year = ?
                                  AND school_id = ?
                                  AND status IN ('completed', 'partial', 'verified')");
            $params[] = $schoolId;
        }

        $stmt->execute($params);
        $summary = $stmt->fetch();
        
        // Get installment details
        $installmentParams = [$studentId, $classId, $term, $academicYear];
        $installmentQuery = "SELECT 
                             pi.installment_number,
                             pi.amount_due,
                             pi.amount_paid,
                             pi.due_date,
                             pi.payment_date,
                             pi.status,
                             sp.payment_type,
                             sp.total_installments
                             FROM payment_installments pi
                             JOIN student_payments sp ON pi.payment_id = sp.id
                             WHERE sp.student_id = ?
                             AND sp.class_id = ?
                             AND sp.term = ?
                             AND sp.academic_year = ?";
        if ($schoolId !== null) {
            $installmentQuery .= " AND sp.school_id = ?";
            $installmentParams[] = $schoolId;
        }
        $installmentQuery .= " ORDER BY pi.installment_number";
        $installmentStmt = $pdo->prepare($installmentQuery);
        $installmentStmt->execute($installmentParams);
        $installments = $installmentStmt->fetchAll();
        
        // Calculate pending amount (from pending payments)
        $pendingParams = [$studentId, $classId, $term, $academicYear];
        $pendingQuery = "SELECT SUM(amount_paid) as total_pending 
                         FROM student_payments 
                         WHERE student_id = ? 
                         AND class_id = ? 
                         AND term = ? 
                         AND academic_year = ?
                         AND status = 'pending'";
        if ($schoolId !== null) {
            $pendingQuery .= " AND school_id = ?";
            $pendingParams[] = $schoolId;
        }
        $pendingStmt = $pdo->prepare($pendingQuery);
        $pendingStmt->execute($pendingParams);
        $pending = $pendingStmt->fetchColumn() ?: 0;
        
        return [
            'total_paid' => $summary['total_paid'] ?: 0,
            'total_pending' => $pending,
            'payment_count' => $summary['payment_count'] ?: 0,
            'last_payment_date' => $summary['last_payment_date'],
            'payment_methods' => $summary['payment_methods'] ? explode(',', $summary['payment_methods']) : [],
            'installments' => $installments
        ];
    }
    
    /**
     * Determine payment status
     */
    private static function getPaymentStatus($totalFee, $amountPaid) {
        if ($totalFee == 0) {
            return 'no_fee';
        } elseif ($amountPaid >= $totalFee) {
            return 'fully_paid';
        } elseif ($amountPaid > 0) {
            return 'partially_paid';
        } else {
            return 'not_paid';
        }
    }
    
    /**
     * Create installments
     */
    public static function createInstallments($paymentId, $totalAmount, $installments, $dueDate) {
        global $pdo;
        
        $installmentAmount = round($totalAmount / $installments, 2);
        $installmentsData = [];
        
        for ($i = 1; $i <= $installments; $i++) {
            $installmentDueDate = date('Y-m-d', strtotime($dueDate . " + " . (($i-1) * 30) . " days"));
            
            $stmt = $pdo->prepare("INSERT INTO payment_installments 
                                  (payment_id, installment_number, due_date, amount_due) 
                                  VALUES (?, ?, ?, ?)");
            $stmt->execute([$paymentId, $i, $installmentDueDate, $installmentAmount]);
            
            $installmentsData[] = [
                'number' => $i,
                'due_date' => $installmentDueDate,
                'amount' => $installmentAmount
            ];
        }
        
        return $installmentsData;
    }
    
    /**
     * Calculate late fee
     */
    public static function calculateLateFee($amount, $dueDate, $lateFeePercentage) {
        if (date('Y-m-d') > $dueDate) {
            $daysLate = (strtotime(date('Y-m-d')) - strtotime($dueDate)) / (60 * 60 * 24);
            $lateFee = ($amount * $lateFeePercentage * $daysLate) / 100;
            return round($lateFee, 2);
        }
        return 0;
    }
    
    /**
     * Get school bank accounts
     */
    public static function getSchoolBankAccounts($schoolId = null) {
        global $pdo;
        if ($schoolId !== null) {
            $stmt = $pdo->prepare("SELECT * FROM school_bank_accounts WHERE is_active = 1 AND school_id = ? ORDER BY is_primary DESC");
            $stmt->execute([$schoolId]);
            return $stmt->fetchAll();
        }
        $stmt = $pdo->query("SELECT * FROM school_bank_accounts WHERE is_active = 1 ORDER BY is_primary DESC");
        return $stmt->fetchAll();
    }
    
    /**
     * Get student payment history
     */
    public static function getStudentPaymentHistory($studentId, $schoolId = null) {
        global $pdo;
        $query = "SELECT sp.*, c.class_name, 
                  (SELECT SUM(amount_paid) FROM payment_installments WHERE payment_id = sp.id) as total_installments_paid
                  FROM student_payments sp
                  JOIN classes c ON sp.class_id = c.id
                  WHERE sp.student_id = ?";
        $params = [$studentId];
        if ($schoolId !== null) {
            $query .= " AND sp.school_id = ?";
            $params[] = $schoolId;
        }
        $query .= " ORDER BY sp.payment_date DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Format currency
     */
    public static function formatCurrency($amount) {
        $config = include('../config/payment_config.php');
        return $config['currency'] . number_format($amount, 2);
    }
    
    /**
     * Get fee structure summary for a class
     */
    public static function getClassFeeSummary($classId, $academicYear, $term = null, $schoolId = null) {
        global $pdo;
        
        $query = "SELECT 
                 fee_type,
                 COUNT(*) as fee_count,
                 SUM(amount) as total_amount,
                 MIN(due_date) as earliest_due_date,
                 MAX(due_date) as latest_due_date,
                 SUM(CASE WHEN allow_installments = 1 THEN 1 ELSE 0 END) as installments_allowed_count
                 FROM fee_structure 
                 WHERE class_id = ? AND academic_year = ? AND is_active = 1";
        
        $params = [$classId, $academicYear];
        if ($schoolId !== null) {
            $query .= " AND school_id = ?";
            $params[] = $schoolId;
        }
        
        if ($term) {
            $query .= " AND term = ?";
            $params[] = $term;
        }
        
        $query .= " GROUP BY fee_type ORDER BY total_amount DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        $summary = [];
        $grandTotal = 0;
        
        while ($row = $stmt->fetch()) {
            $summary[] = [
                'fee_type' => $row['fee_type'],
                'type_label' => self::getFeeTypeLabel($row['fee_type']),
                'fee_count' => (int)$row['fee_count'],
                'total_amount' => (float)$row['total_amount'],
                'earliest_due_date' => $row['earliest_due_date'],
                'latest_due_date' => $row['latest_due_date'],
                'installments_allowed' => $row['installments_allowed_count'] > 0
            ];
            $grandTotal += (float)$row['total_amount'];
        }
        
        return [
            'summary' => $summary,
            'grand_total' => $grandTotal,
            'has_installments' => array_reduce($summary, function($carry, $item) {
                return $carry || $item['installments_allowed'];
            }, false)
        ];
    }
    
    /**
     * Get all fee types with their amounts for a student
     */
    public static function getStudentFeeTypes($studentId, $classId, $term, $academicYear, $schoolId = null) {
        $breakdown = self::getFeeBreakdown($classId, $term, $academicYear, null, $schoolId);
        $feeTypes = [];
        
        foreach ($breakdown['breakdown'] as $fee) {
            if (!isset($feeTypes[$fee['fee_type']])) {
                $feeTypes[$fee['fee_type']] = [
                    'label' => $fee['type_label'],
                    'total_amount' => 0,
                    'items' => []
                ];
            }
            
            $feeTypes[$fee['fee_type']]['total_amount'] += $fee['amount'];
            $feeTypes[$fee['fee_type']]['items'][] = [
                'description' => $fee['description'],
                'amount' => $fee['amount'],
                'allow_installments' => $fee['allow_installments']
            ];
        }
        
        return $feeTypes;
    }
    
    /**
     * Check if a fee type allows installments
     */
    public static function feeTypeAllowsInstallments($classId, $term, $academicYear, $feeType, $schoolId = null) {
        global $pdo;
        
        $query = "SELECT COUNT(*) as count 
                  FROM fee_structure 
                  WHERE class_id = ? AND term = ? AND academic_year = ? 
                  AND fee_type = ? AND allow_installments = 1 AND is_active = 1";
        $params = [$classId, $term, $academicYear, $feeType];
        if ($schoolId !== null) {
            $query .= " AND school_id = ?";
            $params[] = $schoolId;
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Get due dates for fees
     */
    public static function getFeeDueDates($classId, $term, $academicYear, $schoolId = null) {
        global $pdo;
        
        $query = "SELECT 
                  fee_type,
                  description,
                  due_date,
                  amount,
                  late_fee_percentage
                  FROM fee_structure 
                  WHERE class_id = ? AND term = ? AND academic_year = ? 
                  AND is_active = 1 AND due_date IS NOT NULL";
        $params = [$classId, $term, $academicYear];
        if ($schoolId !== null) {
            $query .= " AND school_id = ?";
            $params[] = $schoolId;
        }
        $query .= " ORDER BY due_date";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        $dueDates = [];
        $now = new DateTime();
        
        while ($row = $stmt->fetch()) {
            $dueDate = new DateTime($row['due_date']);
            $isOverdue = $dueDate < $now;
            $daysRemaining = $isOverdue ? 0 : $now->diff($dueDate)->days;
            
            $dueDates[] = [
                'fee_type' => $row['fee_type'],
                'type_label' => self::getFeeTypeLabel($row['fee_type']),
                'description' => $row['description'],
                'due_date' => $row['due_date'],
                'formatted_due_date' => date('F j, Y', strtotime($row['due_date'])),
                'amount' => (float)$row['amount'],
                'late_fee_percentage' => (float)$row['late_fee_percentage'],
                'is_overdue' => $isOverdue,
                'days_remaining' => $daysRemaining,
                'late_fee' => $isOverdue ? self::calculateLateFee($row['amount'], $row['due_date'], $row['late_fee_percentage']) : 0
            ];
        }
        
        return $dueDates;
    }
    
    /**
     * Calculate total late fee for a student
     */
    public static function calculateTotalLateFee($studentId, $classId, $term, $academicYear, $schoolId = null) {
        $dueDates = self::getFeeDueDates($classId, $term, $academicYear, $schoolId);
        $totalLateFee = 0;
        
        foreach ($dueDates as $dueDate) {
            if ($dueDate['is_overdue']) {
                require_once '../config/db.php';
                // Check if student has paid this fee
                $paymentStmt = $pdo->prepare("SELECT SUM(amount_paid) as paid_amount 
                                            FROM student_payments 
                                            WHERE student_id = ? AND class_id = ? 
                                            AND term = ? AND academic_year = ?
                                            AND status = 'completed'");
                $paymentParams = [$studentId, $classId, $term, $academicYear];
                $paymentQuery = "SELECT SUM(amount_paid) as paid_amount 
                                 FROM student_payments 
                                 WHERE student_id = ? AND class_id = ? 
                                 AND term = ? AND academic_year = ?
                                 AND status = 'completed'";
                if ($schoolId !== null) {
                    $paymentQuery .= " AND school_id = ?";
                    $paymentParams[] = $schoolId;
                }
                $paymentStmt = $pdo->prepare($paymentQuery);
                $paymentStmt->execute($paymentParams);
                $paidAmount = $paymentStmt->fetchColumn() ?: 0;
                
                // If not fully paid, add late fee
                if ($paidAmount < $dueDate['amount']) {
                    $totalLateFee += $dueDate['late_fee'];
                }
            }
        }
        
        return $totalLateFee;
    }
}
?>
