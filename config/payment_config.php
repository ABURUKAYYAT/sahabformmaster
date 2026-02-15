<?php
// config/payment_config.php
return [
    // Payment Settings
    'currency' => '₦',
    'currency_code' => 'NGN',
    'default_payment_method' => 'bank_transfer',
    
    // Installment Settings
    'max_installments' => 4,
    'installment_interval_days' => 30,
    'min_installment_amount' => 5000,
    
    // Receipt Settings
    'receipt_prefix' => 'REC',
    'receipt_year_prefix' => date('y'),
    'receipt_start_number' => 1001,
    
    // Bank Settings
    'banks' => [
        'access' => 'Access Bank',
        'firstbank' => 'First Bank',
        'zenith' => 'Zenith Bank',
        'gtbank' => 'GTBank',
        'uba' => 'UBA',
        'fidelity' => 'Fidelity Bank',
        'ecobank' => 'Ecobank',
        'stanbic' => 'Stanbic IBTC'
    ],
    
    // Fee Types
    'fee_types' => [
        'tuition' => 'Tuition Fee',
        'exam' => 'Examination Fee',
        'sports' => 'Sports Fee',
        'library' => 'Library Fee',
        'development' => 'Development Levy',
        'other' => 'Other Charges'
    ]
];
?>