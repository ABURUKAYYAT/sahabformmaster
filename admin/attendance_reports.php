<?php
session_start();
require_once '../config/db.php';
require_once 'helpers.php';
require_once '../includes/functions.php';

// Check if attendance report user is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['principal', 'admin', 'vice_principal'])) {
    header("Location: ../index.php");
    exit;
}

$current_school_id = get_current_school_id();

// Get filter parameters
$teacher_id = $_GET['teacher_id'] ?? 'all';
$report_type = $_GET['report_type'] ?? 'monthly';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$term = $_GET['term'] ?? '1st Term';
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-t');
}
if ($start_date > $end_date) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}
if ($month < 1 || $month > 12) {
    $month = (int)date('m');
}
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

// Get all teachers for dropdown
$teachersQuery = "SELECT id, full_name, email FROM users WHERE school_id = ? AND role IN ('teacher', 'principal') ORDER BY full_name";
$teachersStmt = $pdo->prepare($teachersQuery);
$teachersStmt->execute([$current_school_id]);
$teachers = $teachersStmt->fetchAll();

// Generate report based on type
$reportData = [];
$summary = [
    'total_days' => 0,
    'present_days' => 0,
    'absent_days' => 0,
    'late_days' => 0,
    'agreed_days' => 0,
    'not_agreed_days' => 0,
    'pending_days' => 0,
    'attendance_rate' => 0
];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['generate_report'])) {
    // Build query based on report type
    $query = "SELECT 
                u.id as teacher_id,
                u.full_name,
                u.email,
                DATE(tr.sign_in_time) as attendance_date,
                TIME(tr.sign_in_time) as sign_in_time,
                tr.status,
                tas.expected_arrival,
                CASE 
                    WHEN TIME(tr.sign_in_time) > tas.expected_arrival THEN 1 
                    ELSE 0 
                END as is_late,
                tr.admin_notes
              FROM users u
              LEFT JOIN time_records tr ON u.id = tr.user_id
              LEFT JOIN teacher_attendance_settings tas ON u.id = tas.user_id
              WHERE u.school_id = ? AND u.role IN ('teacher', 'principal')";
    
    $params = [$current_school_id];
    
    // Filter by teacher
    if ($teacher_id !== 'all') {
        $query .= " AND u.id = ?";
        $params[] = $teacher_id;
    }
    
    // Apply date filters based on report type
    switch ($report_type) {
        case 'daily':
            $query .= " AND DATE(tr.sign_in_time) = ?";
            $params[] = date('Y-m-d');
            break;
            
        case 'weekly':
            // Calculate week start and end
            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_end = date('Y-m-d', strtotime('sunday this week'));
            $query .= " AND DATE(tr.sign_in_time) BETWEEN ? AND ?";
            $params[] = $week_start;
            $params[] = $week_end;
            break;
            
        case 'monthly':
            $query .= " AND DATE(tr.sign_in_time) BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
            break;
            
        case 'termly':
            // Define term dates (you may need to adjust this based on your academic calendar)
            $term_dates = getTermDates($term, $year);
            $query .= " AND DATE(tr.sign_in_time) BETWEEN ? AND ?";
            $params[] = $term_dates['start'];
            $params[] = $term_dates['end'];
            break;
            
        case 'yearly':
            $query .= " AND YEAR(tr.sign_in_time) = ?";
            $params[] = $year;
            break;
    }
    
    $query .= " ORDER BY u.full_name, tr.sign_in_time DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rawData = $stmt->fetchAll();
    
    // Process data for display
    $reportData = processReportData($rawData, $report_type);
    
    // Calculate summary
    $summary = calculateSummary($rawData);
}

// Function to get term dates
// function getTermDates($term, $year) {
//     $terms = [
//         '1st Term' => [
//             'start' => $year . '-09-01',
//             'end' => $year . '-12-15'
//         ],
//         '2nd Term' => [
//             'start' => ($year + 1) . '-01-08',
//             'end' => ($year + 1) . '-04-05'
//         ],
//         '3rd Term' => [
//             'start' => ($year + 1) . '-04-23',
//             'end' => ($year + 1) . '-07-20'
//         ]
//     ];
    
//     return $terms[$term] ?? $terms['1st Term'];
// }

// Function to process report data
function processReportData($data, $report_type) {
    $processed = [];
    
    foreach ($data as $row) {
        $teacher_id = $row['teacher_id'];
        
        if (!isset($processed[$teacher_id])) {
            $processed[$teacher_id] = [
                'teacher_id' => $teacher_id,
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'expected_arrival' => $row['expected_arrival'] ?? '08:00:00',
                'daily_records' => [],
                'summary' => [
                    'total_days' => 0,
                    'present_days' => 0,
                    'absent_days' => 0,
                    'late_days' => 0,
                    'agreed_days' => 0,
                    'not_agreed_days' => 0,
                    'pending_days' => 0
                ]
            ];
        }
        
        if ($row['attendance_date']) {
            $processed[$teacher_id]['daily_records'][] = [
                'date' => $row['attendance_date'],
                'sign_in_time' => $row['sign_in_time'],
                'status' => $row['status'],
                'is_late' => $row['is_late'],
                'admin_notes' => $row['admin_notes']
            ];
            
            // Update summary
            $processed[$teacher_id]['summary']['total_days']++;
            
            if ($row['status'] === 'agreed') {
                $processed[$teacher_id]['summary']['agreed_days']++;
                $processed[$teacher_id]['summary']['present_days']++;
            } elseif ($row['status'] === 'not_agreed') {
                $processed[$teacher_id]['summary']['not_agreed_days']++;
            } else {
                $processed[$teacher_id]['summary']['pending_days']++;
            }
            
            if ($row['is_late']) {
                $processed[$teacher_id]['summary']['late_days']++;
            }
        } else {
            // No attendance record for this day
            $processed[$teacher_id]['summary']['absent_days']++;
        }
    }
    
    return $processed;
}

// Function to calculate overall summary
function calculateSummary($data) {
    $summary = [
        'total_days' => 0,
        'present_days' => 0,
        'absent_days' => 0,
        'late_days' => 0,
        'agreed_days' => 0,
        'not_agreed_days' => 0,
        'pending_days' => 0
    ];
    
    $uniqueDays = [];
    $teacherDays = [];
    
    foreach ($data as $row) {
        if ($row['attendance_date']) {
            $dayKey = $row['attendance_date'];
            $teacherKey = $row['teacher_id'] . '-' . $dayKey;
            
            // Count unique days
            if (!in_array($dayKey, $uniqueDays)) {
                $uniqueDays[] = $dayKey;
                $summary['total_days']++;
            }
            
            // Count teacher presence for this day
            if (!in_array($teacherKey, $teacherDays)) {
                $teacherDays[] = $teacherKey;
                
                if ($row['status'] === 'agreed') {
                    $summary['present_days']++;
                    $summary['agreed_days']++;
                } elseif ($row['status'] === 'not_agreed') {
                    $summary['not_agreed_days']++;
                } else {
                    $summary['pending_days']++;
                }
                
                if ($row['is_late']) {
                    $summary['late_days']++;
                }
            }
        }
    }
    
    // Calculate attendance rate
    if ($summary['total_days'] > 0) {
        $summary['attendance_rate'] = round(($summary['present_days'] / $summary['total_days']) * 100, 2);
    }
    
    return $summary;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --success-color: #06d6a0;
            --warning-color: #ffd166;
            --danger-color: #ef476f;
            --info-color: #118ab2;
            --light-color: #f8f9fa;
            --dark-color: #212529;
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            background: linear-gradient(180deg, var(--primary-color), #3a56d4);
            min-height: 100vh;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .sidebar-brand {
            padding: 20px;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 5px 10px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .content-wrapper {
            padding: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .filter-tabs .nav-link {
            color: var(--dark-color);
            border: 1px solid #dee2e6;
            margin: 0 5px;
            padding: 8px 20px;
        }
        
        .filter-tabs .nav-link.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .teacher-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .attendance-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .status-present {
            background-color: rgba(6, 214, 160, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }
        
        .status-absent {
            background-color: rgba(239, 71, 111, 0.1);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }
        
        .status-late {
            background-color: rgba(255, 209, 102, 0.1);
            color: #e6b400;
            border: 1px solid #e6b400;
        }
        
        .status-pending {
            background-color: rgba(149, 149, 149, 0.1);
            color: #6c757d;
            border: 1px solid #6c757d;
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .date-range {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            
            .content-wrapper {
                padding: 15px;
            }
            
            .filter-tabs .nav-link {
                padding: 6px 12px;
                font-size: 0.9rem;
            }
        }
        
        .btn-export {
            background: var(--success-color);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            transition: all 0.3s;
        }
        
        .btn-export:hover {
            background: #05c493;
            transform: translateY(-2px);
        }
        
        .progress-bar-custom {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
        }
        
        .table-custom {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .table-custom th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #495057;
        }
        
        .table-custom td {
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-2 col-md-3 sidebar d-none d-md-block">
            
                <nav class="nav flex-column mt-4">
                    <a href="index.php" class="nav-link">
                        <i class="fas fa-home me-2"></i>Dashboard
                    </a>
                    <a href="manage_timebook.php" class="nav-link">
                        <i class="fas fa-calendar-check me-2"></i>Timebook
                    </a>
                
                </nav>
            </div>
            
            <!-- Mobile Header -->
            <div class="d-md-none bg-white shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-bar text-primary me-2"></i>Attendance Reports</h5>
                    <button class="btn btn-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-10 col-md-9 ms-sm-auto">
                <div class="content-wrapper">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="h4 fw-bold text-dark">Attendance Reports</h2>
                            <p class="text-muted">Generate and analyze teacher attendance reports</p>
                        </div>
                        <div>
                            <button class="btn btn-success btn-export" onclick="exportReport()">
                                <i class="fas fa-file-export me-2"></i>Export Report
                            </button>
                        </div>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="report-card">
                        <h5 class="mb-4">Generate Report</h5>
                        <form method="GET" id="reportForm">
                            <div class="row g-3">
                                <!-- Report Type Tabs -->
                                <div class="col-12">
                                    <ul class="nav nav-pills filter-tabs mb-4">
                                        <li class="nav-item">
                                            <a class="nav-link <?php echo $report_type === 'daily' ? 'active' : ''; ?>" 
                                               href="#" onclick="setReportType('daily')">Daily</a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link <?php echo $report_type === 'weekly' ? 'active' : ''; ?>" 
                                               href="#" onclick="setReportType('weekly')">Weekly</a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link <?php echo $report_type === 'monthly' ? 'active' : ''; ?>" 
                                               href="#" onclick="setReportType('monthly')">Monthly</a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link <?php echo $report_type === 'termly' ? 'active' : ''; ?>" 
                                               href="#" onclick="setReportType('termly')">Termly</a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link <?php echo $report_type === 'yearly' ? 'active' : ''; ?>" 
                                               href="#" onclick="setReportType('yearly')">Yearly</a>
                                        </li>
                                    </ul>
                                </div>
                                
                                <!-- Teacher Selection -->
                                <div class="col-md-4">
                                    <label class="form-label">Select Teacher</label>
                                    <select class="form-select" name="teacher_id" id="teacherSelect">
                                        <option value="all">All Teachers</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>" 
                                                <?php echo $teacher_id == $teacher['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($teacher['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Dynamic Date Fields -->
                                <div class="col-md-4" id="monthlyFields" style="display: <?php echo $report_type === 'monthly' ? 'block' : 'none'; ?>;">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label">Start Date</label>
                                            <input type="text" class="form-control datepicker" name="start_date" 
                                                   value="<?php echo htmlspecialchars($start_date); ?>">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">End Date</label>
                                            <input type="text" class="form-control datepicker" name="end_date" 
                                                   value="<?php echo htmlspecialchars($end_date); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-4" id="termlyFields" style="display: <?php echo $report_type === 'termly' ? 'block' : 'none'; ?>;">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label">Term</label>
                                            <select class="form-select" name="term">
                                                <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                                                <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                                                <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">Year</label>
                                            <select class="form-select" name="year">
                                                <?php for ($y = date('Y') - 5; $y <= date('Y') + 1; $y++): ?>
                                                    <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                                        <?php echo $y; ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-4" id="yearlyFields" style="display: <?php echo $report_type === 'yearly' ? 'block' : 'none'; ?>;">
                                    <label class="form-label">Year</label>
                                    <select class="form-select" name="year">
                                        <?php for ($y = date('Y') - 5; $y <= date('Y') + 1; $y++): ?>
                                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                                <?php echo $y; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                
                                <!-- Hidden Report Type Field -->
                                <input type="hidden" name="report_type" id="reportType" value="<?php echo $report_type; ?>">
                                
                                <div class="col-12 mt-3">
                                    <button type="submit" name="generate_report" class="btn btn-primary">
                                        <i class="fas fa-chart-bar me-2"></i>Generate Report
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()">
                                        <i class="fas fa-redo me-2"></i>Reset Filters
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <?php if (!empty($reportData)): ?>
                        <!-- Summary Cards -->
                        <div class="row mb-4">
                            <div class="col-xl-3 col-md-6 mb-3">
                                <div class="stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Total Days</h6>
                                            <h3 class="mb-0"><?php echo $summary['total_days']; ?></h3>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(67, 97, 238, 0.1); color: var(--primary-color);">
                                            <i class="fas fa-calendar-alt"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6 mb-3">
                                <div class="stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Present Days</h6>
                                            <h3 class="mb-0 text-success"><?php echo $summary['present_days']; ?></h3>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(6, 214, 160, 0.1); color: var(--success-color);">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6 mb-3">
                                <div class="stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Absent Days</h6>
                                            <h3 class="mb-0 text-danger"><?php echo $summary['absent_days']; ?></h3>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(239, 71, 111, 0.1); color: var(--danger-color);">
                                            <i class="fas fa-times-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6 mb-3">
                                <div class="stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Attendance Rate</h6>
                                            <h3 class="mb-0 text-info"><?php echo $summary['attendance_rate']; ?>%</h3>
                                        </div>
                                        <div class="stat-icon" style="background: rgba(17, 138, 178, 0.1); color: var(--info-color);">
                                            <i class="fas fa-chart-line"></i>
                                        </div>
                                    </div>
                                    <div class="progress-bar-custom mt-2">
                                        <div class="progress-fill" style="width: <?php echo $summary['attendance_rate']; ?>%; background: var(--info-color);"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Detailed Report -->
                        <div class="report-card">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5>Attendance Details</h5>
                                <span class="date-range">
                                    <i class="far fa-calendar-alt me-1"></i>
                                    <?php 
                                    switch ($report_type) {
                                        case 'daily':
                                            echo date('F j, Y');
                                            break;
                                        case 'weekly':
                                            echo 'Week of ' . date('F j, Y', strtotime('monday this week'));
                                            break;
                                        case 'monthly':
                                            echo date('F j, Y', strtotime($start_date)) . ' - ' . date('F j, Y', strtotime($end_date));
                                            break;
                                        case 'termly':
                                            echo $term . ' ' . $year;
                                            break;
                                        case 'yearly':
                                            echo 'Year ' . $year;
                                            break;
                                    }
                                    ?>
                                </span>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover table-custom" id="attendanceTable">
                                    <thead>
                                        <tr>
                                            <th>Teacher</th>
                                            <th>Expected Arrival</th>
                                            <th>Present Days</th>
                                            <th>Absent Days</th>
                                            <th>Late Days</th>
                                            <th>Agreed Days</th>
                                            <th>Not Agreed Days</th>
                                            <th>Pending Days</th>
                                            <th>Attendance Rate</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reportData as $teacher): ?>
                                            <?php 
                                            $teacherRate = $teacher['summary']['total_days'] > 0 
                                                ? round(($teacher['summary']['present_days'] / $teacher['summary']['total_days']) * 100, 2) 
                                                : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-light rounded-circle p-2 me-3">
                                                            <i class="fas fa-user text-primary"></i>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-1"><?php echo htmlspecialchars($teacher['full_name']); ?></h6>
                                                            <small class="text-muted"><?php echo htmlspecialchars($teacher['email']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo substr($teacher['expected_arrival'], 0, 5); ?></td>
                                                <td><span class="badge bg-success"><?php echo $teacher['summary']['present_days']; ?></span></td>
                                                <td><span class="badge bg-danger"><?php echo $teacher['summary']['absent_days']; ?></span></td>
                                                <td><span class="badge bg-warning"><?php echo $teacher['summary']['late_days']; ?></span></td>
                                                <td><span class="badge bg-success"><?php echo $teacher['summary']['agreed_days']; ?></span></td>
                                                <td><span class="badge bg-danger"><?php echo $teacher['summary']['not_agreed_days']; ?></span></td>
                                                <td><span class="badge bg-secondary"><?php echo $teacher['summary']['pending_days']; ?></span></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span class="me-2"><?php echo $teacherRate; ?>%</span>
                                                        <div class="progress-bar-custom" style="width: 80px;">
                                                            <div class="progress-fill" style="width: <?php echo min($teacherRate, 100); ?>%; 
                                                                background: <?php echo $teacherRate >= 80 ? 'var(--success-color)' : ($teacherRate >= 60 ? 'var(--warning-color)' : 'var(--danger-color)'); ?>;">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" 
                                                            onclick="viewTeacherDetails(<?php echo $teacher['teacher_id']; ?>)">
                                                        <i class="fas fa-eye me-1"></i>View Details
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Individual Teacher Details (Hidden by default) -->
                        <?php foreach ($reportData as $teacher): ?>
                            <div class="teacher-card teacher-details" id="teacherDetails-<?php echo $teacher['teacher_id']; ?>" style="display: none;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="mb-0">Daily Attendance for <?php echo htmlspecialchars($teacher['full_name']); ?></h6>
                                    <button class="btn btn-sm btn-outline-secondary" 
                                            onclick="hideTeacherDetails(<?php echo $teacher['teacher_id']; ?>)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                
                                <?php if (!empty($teacher['daily_records'])): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Sign In Time</th>
                                                    <th>Expected Time</th>
                                                    <th>Status</th>
                                                    <th>Late</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($teacher['daily_records'] as $record): ?>
                                                    <tr>
                                                        <td><?php echo date('M j, Y', strtotime($record['date'])); ?></td>
                                                        <td><?php echo substr($record['sign_in_time'], 0, 5); ?></td>
                                                        <td><?php echo substr($teacher['expected_arrival'], 0, 5); ?></td>
                                                        <td>
                                                            <span class="attendance-status status-<?php echo $record['status']; ?>">
                                                                <?php 
                                                                $statusLabels = [
                                                                    'agreed' => 'Agreed',
                                                                    'not_agreed' => 'Not Agreed',
                                                                    'pending' => 'Pending'
                                                                ];
                                                                echo $statusLabels[$record['status']] ?? $record['status'];
                                                                ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($record['is_late']): ?>
                                                                <span class="badge bg-warning">Late</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success">On Time</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($record['admin_notes'])): ?>
                                                                <small class="text-muted"><?php echo htmlspecialchars($record['admin_notes']); ?></small>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-clipboard-list fa-2x text-muted mb-3"></i>
                                        <p class="text-muted">No attendance records found for this period.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                    <?php elseif (isset($_GET['generate_report'])): ?>
                        <!-- No Data Message -->
                        <div class="report-card">
                            <div class="text-center py-5">
                                <i class="fas fa-chart-pie fa-3x text-muted mb-3"></i>
                                <h5>No Attendance Data Found</h5>
                                <p class="text-muted">No attendance records match your selected criteria.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Welcome Message -->
                        <div class="report-card">
                            <div class="text-center py-5">
                                <i class="fas fa-chart-line fa-4x text-primary mb-4"></i>
                                <h3>Welcome to Attendance Reports</h3>
                                <p class="text-muted mb-4">Select your report criteria and click "Generate Report" to view attendance data.</p>
                                <div class="row justify-content-center">
                                    <div class="col-md-8">
                                        <div class="alert alert-info">
                                            <h6><i class="fas fa-info-circle me-2"></i>Report Types Available:</h6>
                                            <ul class="mb-0">
                                                <li><strong>Daily:</strong> Today's attendance report</li>
                                                <li><strong>Weekly:</strong> This week's attendance summary</li>
                                                <li><strong>Monthly:</strong> Custom date range attendance</li>
                                                <li><strong>Termly:</strong> Academic term attendance</li>
                                                <li><strong>Yearly:</strong> Annual attendance overview</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize datepicker
            $('.datepicker').datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true
            });
            
            // Initialize DataTable if table exists
            if ($('#attendanceTable').length) {
                $('#attendanceTable').DataTable({
                    pageLength: 10,
                    responsive: true,
                    order: [[0, 'asc']]
                });
            }
            
            // Show/hide date fields based on report type
            updateDateFields();
        });
        
        function setReportType(type) {
            $('#reportType').val(type);
            updateDateFields();
            
            // Update active tab
            $('.filter-tabs .nav-link').removeClass('active');
            $(`.filter-tabs .nav-link[onclick="setReportType('${type}')"]`).addClass('active');
        }
        
        function updateDateFields() {
            const type = $('#reportType').val();
            
            // Hide all date fields
            $('#monthlyFields, #termlyFields, #yearlyFields').hide();
            
            // Show relevant fields
            switch(type) {
                case 'monthly':
                    $('#monthlyFields').show();
                    break;
                case 'termly':
                    $('#termlyFields').show();
                    break;
                case 'yearly':
                    $('#yearlyFields').show();
                    break;
            }
        }
        
        function viewTeacherDetails(teacherId) {
            // Hide all teacher details first
            $('.teacher-details').hide();
            
            // Show selected teacher details
            $(`#teacherDetails-${teacherId}`).show();
            
            // Scroll to the details
            $('html, body').animate({
                scrollTop: $(`#teacherDetails-${teacherId}`).offset().top - 100
            }, 500);
        }
        
        function hideTeacherDetails(teacherId) {
            $(`#teacherDetails-${teacherId}`).hide();
        }
        
        function resetFilters() {
            window.location.href = 'attendance_reports.php';
        }
        
        function exportReport() {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            
            // Add export parameter
            params.set('export', 'pdf');
            
            // Redirect to export page
            window.location.href = 'export_report.php?' + params.toString();
        }
    </script>
</body>
</html>
