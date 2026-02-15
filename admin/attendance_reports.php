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

// Function to get term dates (guarded to avoid redeclare)
if (!function_exists('getTermDates')) {
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
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            background: white;
            box-shadow: none;
        }
        
        .nav-link {
            color: #475569;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(67, 97, 238, 0.1);
            color: #1e293b;
        }
        
        .content-wrapper {
            padding: 0;
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

        .filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .date-range {
            font-size: 0.85rem;
            color: #64748b;
        }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Admin Portal</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Admin</p>
                    <span class="principal-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon">🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📰</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link">
                            <span class="nav-icon">📔</span>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students Registration</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students-evaluations.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Students Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_class.php" class="nav-link active">
                            <span class="nav-icon">🎓</span>
                            <span class="nav-text">Manage Classes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage-school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <span class="nav-icon">📖</span>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">👤</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🚶</span>
                            <span class="nav-text">Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_timebook.php" class="nav-link">
                            <span class="nav-icon">⏰</span>
                            <span class="nav-text">Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_attendance.php" class="nav-link">
                            <span class="nav-icon">📋</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments_dashboard.php" class="nav-link">
                            <span class="nav-icon">💰</span>
                            <span class="nav-text">School Fees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="sessions.php" class="nav-link">
                            <span class="nav-icon">📅</span>
                            <span class="nav-text">School Sessions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_calendar.php" class="nav-link">
                            <span class="nav-icon">🗓️</span>
                            <span class="nav-text">School Calendar</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="applicants.php" class="nav-link">
                            <span class="nav-icon">📄</span>
                            <span class="nav-text">Applicants</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>📊 Attendance Reports</h2>
                    <p>Generate and analyze teacher attendance reports</p>
                </div>
                <div>
                    <button class="btn success" onclick="exportReport()">
                        <i class="fas fa-file-export"></i> Export Report
                    </button>
                </div>
            </div>
                    
                        <!-- Welcome Message -->
                        <section class="panel">
                            <div class="panel-body" style="text-align:center; padding: 40px;">
                                <i class="fas fa-chart-line" style="font-size: 3rem; color: #4361ee; margin-bottom: 12px;"></i>
                                <h3>Welcome to Attendance Reports</h3>
                                <p style="color:#64748b; margin-bottom: 16px;">Select your report criteria and click "Generate Report" to view attendance data.</p>
                                <div class="alert alert-info" style="max-width: 640px; margin: 0 auto; text-align:left;">
                                    <h6><i class="fas fa-info-circle"></i> Report Types Available:</h6>
                                    <ul style="margin: 8px 0 0 16px;">
                                        <li><strong>Daily:</strong> Today's attendance report</li>
                                        <li><strong>Weekly:</strong> This week's attendance summary</li>
                                        <li><strong>Monthly:</strong> Custom date range attendance</li>
                                        <li><strong>Termly:</strong> Academic term attendance</li>
                                        <li><strong>Yearly:</strong> Annual attendance overview</li>
                                    </ul>
                                </div>
                            </div>
                        </section>
            <!-- Filter Section -->
            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-filter"></i> Generate Report</h2>
                </div>
                <div class="panel-body">
                    <form method="GET" id="reportForm" class="form-inline">
                        <div class="filter-tabs">
                            <a class="btn small <?php echo $report_type === 'daily' ? 'primary' : 'secondary'; ?>" href="#" onclick="setReportType('daily')">Daily</a>
                            <a class="btn small <?php echo $report_type === 'weekly' ? 'primary' : 'secondary'; ?>" href="#" onclick="setReportType('weekly')">Weekly</a>
                            <a class="btn small <?php echo $report_type === 'monthly' ? 'primary' : 'secondary'; ?>" href="#" onclick="setReportType('monthly')">Monthly</a>
                            <a class="btn small <?php echo $report_type === 'termly' ? 'primary' : 'secondary'; ?>" href="#" onclick="setReportType('termly')">Termly</a>
                            <a class="btn small <?php echo $report_type === 'yearly' ? 'primary' : 'secondary'; ?>" href="#" onclick="setReportType('yearly')">Yearly</a>
                        </div>

                        <select class="form-control" name="teacher_id" id="teacherSelect">
                            <option value="all">All Teachers</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>" <?php echo $teacher_id == $teacher['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($teacher['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div id="monthlyFields" style="display: <?php echo $report_type === 'monthly' ? 'block' : 'none'; ?>;">
                            <input type="text" class="form-control datepicker" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" placeholder="Start Date">
                            <input type="text" class="form-control datepicker" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" placeholder="End Date">
                        </div>

                        <div id="termlyFields" style="display: <?php echo $report_type === 'termly' ? 'block' : 'none'; ?>;">
                            <select class="form-control" name="term">
                                <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                                <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                                <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                            </select>
                            <select class="form-control" name="year">
                                <?php for ($y = date('Y') - 5; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div id="yearlyFields" style="display: <?php echo $report_type === 'yearly' ? 'block' : 'none'; ?>;">
                            <select class="form-control" name="year">
                                <?php for ($y = date('Y') - 5; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <input type="hidden" name="report_type" id="reportType" value="<?php echo $report_type; ?>">
                        <button type="submit" name="generate_report" class="btn primary">
                            <i class="fas fa-chart-bar"></i> Generate Report
                        </button>
                        <button type="button" class="btn secondary" onclick="resetFilters()">
                            <i class="fas fa-redo"></i> Reset Filters
                        </button>
                    </form>
                </div>
            </section>
                    
                    <?php if (!empty($reportData)): ?>
                        <!-- Summary Cards -->
                        <div class="stats-container">
                            <div class="stat-card">
                                <i class="fas fa-calendar-alt"></i>
                                <h3>Total Days</h3>
                                <div class="count"><?php echo $summary['total_days']; ?></div>
                                <p class="stat-description">Recorded days</p>
                            </div>
                            <div class="stat-card">
                                <i class="fas fa-check-circle"></i>
                                <h3>Present Days</h3>
                                <div class="count"><?php echo $summary['present_days']; ?></div>
                                <p class="stat-description">On time/Agreed</p>
                            </div>
                            <div class="stat-card">
                                <i class="fas fa-times-circle"></i>
                                <h3>Absent Days</h3>
                                <div class="count"><?php echo $summary['absent_days']; ?></div>
                                <p class="stat-description">Not present</p>
                            </div>
                            <div class="stat-card">
                                <i class="fas fa-chart-line"></i>
                                <h3>Attendance Rate</h3>
                                <div class="count"><?php echo $summary['attendance_rate']; ?>%</div>
                                <p class="stat-description">Overall rate</p>
                            </div>
                        </div>
                        
                        <!-- Detailed Report -->
                        <section class="panel">
                            <div class="panel-header">
                                <h2><i class="fas fa-list"></i> Attendance Details</h2>
                                <span class="date-range">
                                    <i class="far fa-calendar-alt"></i>
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
                            <div class="panel-body">
                                <div class="table-wrap">
                                    <table class="table" id="attendanceTable">
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
                                                    <strong><?php echo htmlspecialchars($teacher['full_name']); ?></strong>
                                                    <small style="color:#64748b; display:block;"><?php echo htmlspecialchars($teacher['email']); ?></small>
                                                </td>
                                                <td><?php echo substr($teacher['expected_arrival'], 0, 5); ?></td>
                                                <td><span class="badge badge-success"><?php echo $teacher['summary']['present_days']; ?></span></td>
                                                <td><span class="badge badge-warning"><?php echo $teacher['summary']['absent_days']; ?></span></td>
                                                <td><span class="badge"><?php echo $teacher['summary']['late_days']; ?></span></td>
                                                <td><span class="badge badge-success"><?php echo $teacher['summary']['agreed_days']; ?></span></td>
                                                <td><span class="badge badge-warning"><?php echo $teacher['summary']['not_agreed_days']; ?></span></td>
                                                <td><span class="badge"><?php echo $teacher['summary']['pending_days']; ?></span></td>
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
                                                    <button class="btn small secondary" onclick="viewTeacherDetails(<?php echo $teacher['teacher_id']; ?>)">
                                                        <i class="fas fa-eye"></i> View Details
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        </section>
                        
                        <!-- Individual Teacher Details (Hidden by default) -->
                        <?php foreach ($reportData as $teacher): ?>
                            <section class="panel teacher-details" id="teacherDetails-<?php echo $teacher['teacher_id']; ?>" style="display: none;">
                                <div class="panel-header">
                                    <h2><i class="fas fa-user"></i> Daily Attendance for <?php echo htmlspecialchars($teacher['full_name']); ?></h2>
                                    <button class="btn small secondary" onclick="hideTeacherDetails(<?php echo $teacher['teacher_id']; ?>)">
                                        <i class="fas fa-times"></i> Close
                                    </button>
                                </div>
                                <div class="panel-body">
                                
                                <?php if (!empty($teacher['daily_records'])): ?>
                                    <div class="table-wrap">
                                        <table class="table">
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
                                    <div style="text-align:center; padding: 24px; color: #64748b;">
                                        <i class="fas fa-clipboard-list" style="font-size: 2rem; margin-bottom: 8px;"></i>
                                        <p>No attendance records found for this period.</p>
                                    </div>
                                <?php endif; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                        
                    <?php elseif (isset($_GET['generate_report'])): ?>
                        <!-- No Data Message -->
                        <section class="panel">
                            <div class="panel-body" style="text-align:center; padding: 40px;">
                                <i class="fas fa-chart-pie" style="font-size: 3rem; color: #94a3b8; margin-bottom: 12px;"></i>
                                <h3>No Attendance Data Found</h3>
                                <p style="color:#64748b;">No attendance records match your selected criteria.</p>
                            </div>
                        </section>
                    <?php else: ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

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
