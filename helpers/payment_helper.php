<?php
// helpers/payment_helper.php
require_once '../config/db.php';

class PaymentHelper { 
    
    /**
     * Generate unique receipt number
     */
    public static function generateReceiptNumber() {
        $config = include('../config/payment_config.php');
        $prefix = $config['receipt_prefix'] . $config['receipt_year_prefix'];
        $lastReceipt = self::getLastReceiptNumber();
        
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
    private static function getLastReceiptNumber() {
        global $pdo;
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
    public static function getFeeBreakdown($classId, $term, $academicYear, $feeType = null) {
        global $pdo;
        
        $query = "SELECT * FROM fee_structure 
                  WHERE class_id = ? AND term = ? AND academic_year = ? AND is_active = 1";
        $params = [$classId, $term, $academicYear];
        
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
    public static function getFullFeeBreakdown($studentId, $classId, $term, $academicYear) {
        global $pdo;
        
        // Get base fee breakdown
        $feeBreakdown = self::getFeeBreakdown($classId, $term, $academicYear);
        
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
        $paymentSummary = self::getStudentPaymentSummary($studentId, $classId, $term, $academicYear);
        
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
    private static function getStudentPaymentSummary($studentId, $classId, $term, $academicYear) {
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
                              AND status IN ('completed', 'partial')");
        
        $stmt->execute([$studentId, $classId, $term, $academicYear]);
        $summary = $stmt->fetch();
        
        // Get installment details
        $installmentStmt = $pdo->prepare("SELECT 
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
                                         AND sp.academic_year = ?
                                         ORDER BY pi.installment_number");
        
        $installmentStmt->execute([$studentId, $classId, $term, $academicYear]);
        $installments = $installmentStmt->fetchAll();
        
        // Calculate pending amount (from pending payments)
        $pendingStmt = $pdo->prepare("SELECT SUM(amount_paid) as total_pending 
                                     FROM student_payments 
                                     WHERE student_id = ? 
                                     AND class_id = ? 
                                     AND term = ? 
                                     AND academic_year = ?
                                     AND status = 'pending'");
        
        $pendingStmt->execute([$studentId, $classId, $term, $academicYear]);
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
    public static function getSchoolBankAccounts() {
        global $pdo;
        $stmt = $pdo->query("SELECT * FROM school_bank_accounts WHERE is_active = 1 ORDER BY is_primary DESC");
        return $stmt->fetchAll();
    }
    
    /**
     * Get student payment history
     */
    public static function getStudentPaymentHistory($studentId) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT sp.*, c.class_name, 
                              (SELECT SUM(amount_paid) FROM payment_installments WHERE payment_id = sp.id) as total_installments_paid
                              FROM student_payments sp
                              JOIN classes c ON sp.class_id = c.id
                              WHERE sp.student_id = ? 
                              ORDER BY sp.payment_date DESC");
        $stmt->execute([$studentId]);
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
    public static function getClassFeeSummary($classId, $academicYear, $term = null) {
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
    public static function getStudentFeeTypes($studentId, $classId, $term, $academicYear) {
        $breakdown = self::getFeeBreakdown($classId, $term, $academicYear);
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
    public static function feeTypeAllowsInstallments($classId, $term, $academicYear, $feeType) {
        global $pdo;
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count 
                              FROM fee_structure 
                              WHERE class_id = ? AND term = ? AND academic_year = ? 
                              AND fee_type = ? AND allow_installments = 1 AND is_active = 1");
        $stmt->execute([$classId, $term, $academicYear, $feeType]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Get due dates for fees
     */
    public static function getFeeDueDates($classId, $term, $academicYear) {
        global $pdo;
        
        $stmt = $pdo->prepare("SELECT 
                              fee_type,
                              description,
                              due_date,
                              amount,
                              late_fee_percentage
                              FROM fee_structure 
                              WHERE class_id = ? AND term = ? AND academic_year = ? 
                              AND is_active = 1 AND due_date IS NOT NULL
                              ORDER BY due_date");
        $stmt->execute([$classId, $term, $academicYear]);
        
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
    public static function calculateTotalLateFee($studentId, $classId, $term, $academicYear) {
        $dueDates = self::getFeeDueDates($classId, $term, $academicYear);
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
                $paymentStmt->execute([$studentId, $classId, $term, $academicYear]);
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