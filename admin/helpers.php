<?php
// helpers.php

function getTermDates($term, $year) {
    $terms = [
        '1st Term' => [
            'start' => $year . '-09-01',
            'end' => $year . '-12-15'
        ],
        '2nd Term' => [
            'start' => ($year + 1) . '-01-08',
            'end' => ($year + 1) . '-04-05'
        ],
        '3rd Term' => [
            'start' => ($year + 1) . '-04-23',
            'end' => ($year + 1) . '-07-20'
        ]
    ];
    
    return $terms[$term] ?? $terms['1st Term'];
}

function getAttendanceSummary($pdo, $teacher_id, $start_date, $end_date) {
    $query = "SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'agreed' THEN 1 ELSE 0 END) as agreed_days,
                SUM(CASE WHEN status = 'not_agreed' THEN 1 ELSE 0 END) as not_agreed_days,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_days,
                SUM(CASE WHEN TIME(sign_in_time) > tas.expected_arrival THEN 1 ELSE 0 END) as late_days
              FROM time_records tr
              JOIN teacher_attendance_settings tas ON tr.user_id = tas.user_id
              WHERE tr.user_id = ? 
                AND DATE(tr.sign_in_time) BETWEEN ? AND ?";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$teacher_id, $start_date, $end_date]);
    return $stmt->fetch();
}

// ... other helper functions ...
