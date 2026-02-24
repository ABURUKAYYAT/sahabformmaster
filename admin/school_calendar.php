<?php
session_start();
require_once '../config/db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') { header("Location: ../index.php"); exit; }
$principal_name = $_SESSION['full_name'];
$school_id = $_SESSION['school_id'] ?? null;

function ensure_calendar_events_table(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            school_id INT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            event_date DATE NOT NULL,
            event_type ENUM('academic', 'holiday', 'exam', 'sports', 'ceremony', 'meeting', 'other') NOT NULL DEFAULT 'academic',
            description TEXT NULL,
            location VARCHAR(255) NULL,
            event_time TIME NULL,
            is_all_day TINYINT(1) NOT NULL DEFAULT 0,
            color VARCHAR(20) NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_calendar_school_date (school_id, event_date),
            INDEX idx_calendar_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

try {
    ensure_calendar_events_table($pdo);

    $current_month = date('m');
    $current_year = date('Y');
    $stmt = $pdo->prepare("SELECT * FROM calendar_events WHERE MONTH(event_date) = ? AND YEAR(event_date) = ? AND (school_id = ? OR school_id IS NULL) ORDER BY event_date ASC");
    $stmt->execute([$current_month, $current_year, $school_id]);
    $monthly_events = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT * FROM calendar_events WHERE event_date >= CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND (school_id = ? OR school_id IS NULL) ORDER BY event_date ASC LIMIT 5");
    $stmt->execute([$school_id]);
    $upcoming_events = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT event_type, COUNT(*) as count FROM calendar_events WHERE (school_id = ? OR school_id IS NULL) GROUP BY event_type");
    $stmt->execute([$school_id]);
    $event_types = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    error_log("Calendar error: " . $e->getMessage());
    $monthly_events = $upcoming_events = $event_types = [];
}
function renderCalendar($month, $year, $events) {
    $first_day = mktime(0, 0, 0, $month, 1, $year);
    $days_in_month = date('t', $first_day);
    $start_day = date('w', $first_day);
    $today = date('Y-m-d');
    $month_name = date('F Y', $first_day);
    $events_by_date = [];
    foreach ($events as $event) { $date = $event['event_date']; if (!isset($events_by_date[$date])) $events_by_date[$date] = []; $events_by_date[$date][] = $event; }
    $prev_m = $month == 1 ? 12 : $month - 1; $prev_y = $month == 1 ? $year - 1 : $year;
    $next_m = $month == 12 ? 1 : $month + 1; $next_y = $month == 12 ? $year + 1 : $year;
    $html = '<div class="calendar-header"><h2 class="calendar-title"><i class="fas fa-calendar-alt"></i> School Calendar</h2>';
    $html .= '<div class="calendar-nav"><button class="calendar-nav-btn" onclick="changeMonth('.$prev_m.','.$prev_y.')"><i class="fas fa-chevron-left"></i></button>';
    $html .= '<span class="current-month" id="currentMonth">'.$month_name.'</span>';
    $html .= '<button class="calendar-nav-btn" onclick="changeMonth('.$next_m.','.$next_y.')"><i class="fas fa-chevron-right"></i></button></div></div>';
    $html .= '<div class="calendar-grid">';
    foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d) $html .= '<div class="calendar-day-header">'.$d.'</div>';
    for ($i = 0; $i < $start_day; $i++) $html .= '<div class="calendar-day other-month"><span class="calendar-day-number"></span></div>';
    for ($day = 1; $day <= $days_in_month; $day++) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $is_today = $date_str === $today;
        $html .= '<div class="calendar-day'.($is_today ? ' today' : '').'" onclick="addEventOnDate(\''.$date_str.'\')">';
        $html .= '<span class="calendar-day-number">'.$day.'</span>';
        if (isset($events_by_date[$date_str])) {
            foreach ($events_by_date[$date_str] as $e) {
                $html .= '<div class="event-item event-'.$e['event_type'].'" onclick="event.stopPropagation(); viewEvent('.$e['id'].')" title="'.htmlspecialchars($e['title']).'">'.htmlspecialchars($e['title']).'</div>';
            }
        }
        $html .= '</div>';
    }
    $total = $start_day + $days_in_month;
    for ($i = 0; $i < 42 - $total; $i++) $html .= '<div class="calendar-day other-month"><span class="calendar-day-number"></span></div>';
    $html .= '</div>';
    return $html;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Calendar | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {--p:#2563eb;--pd:#1d4ed8;--s:#38bdf8;--suc:#10b981;--warning:#f59e0b;--e:#ef4444;--i:#3b82f6;--g100:#f3f4f6;--g200:#e5e7eb;--g500:#6b7280;--g900:#111827;--white:#fff;}
        .main-content {padding: 2rem;}
        .calendar-container,.upcoming-events,.quick-actions-calendar {background:var(--white);border-radius:16px;box-shadow:0 1px 2px 0 rgb(0 0 0/0.05);padding:1.5rem;margin-bottom:1.5rem;}
        .calendar-header {display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;}
        .calendar-title {font-family:'Poppins',sans-serif;font-size:1.25rem;font-weight:700;color:var(--g900);margin:0;}
        .calendar-nav {display:flex;gap:0.5rem;align-items:center;}
        .calendar-nav-btn {padding:0.4rem 0.75rem;background:var(--p);color:white;border:none;border-radius:8px;cursor:pointer;transition:0.2s;}
        .calendar-nav-btn:hover{background:var(--pd);}
        .current-month {font-size:1rem;font-weight:600;color:var(--g500);min-width:120px;text-align:center;}
        .calendar-grid {display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--g200);border-radius:12px;overflow:hidden;}
        .calendar-day-header {background:var(--p);color:white;padding:0.5rem;text-align:center;font-weight:600;font-size:0.75rem;text-transform:uppercase;}
        .calendar-day {background:var(--white);min-height:80px;padding:0.35rem;cursor:pointer;transition:0.2s;}
        .calendar-day:hover{background:var(--g100);}
        .calendar-day.other-month{background:var(--g100);color:var(--g500);}
        .calendar-day-number{font-size:0.85rem;font-weight:600;margin-bottom:0.25rem;display:block;color:var(--g900);}
        .calendar-day.other-month .calendar-day-number{color:var(--g500);}
        .calendar-day.today{background:linear-gradient(135deg,var(--p),var(--s));color:white;}
        .calendar-day.today .calendar-day-number{color:white;}
        .event-item{background:var(--p);color:white;padding:0.15rem 0.25rem;border-radius:4px;font-size:0.6rem;margin-bottom:0.15rem;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .event-item:hover{opacity:0.85;}
        .event-item.event-holiday{background:var(--e);}
        .event-item.event-exam{background:var(--warning);}
        .event-item.event-sports{background:var(--suc);}
        .event-item.event-ceremony{background:var(--i);}
        .event-item.event-meeting{background:#8b5cf6;}
        .event-item.event-other{background:var(--g500);}
        .section-title{font-family:'Poppins',sans-serif;font-size:1.1rem;font-weight:600;color:var(--g900);margin-bottom:1rem;}
        .events-list{display:flex;flex-direction:column;gap:0.75rem;}
        .event-card{display:flex;align-items:flex-start;gap:0.75rem;padding:0.75rem;background:var(--g100);border-radius:12px;border-left:4px solid var(--p);}
        .event-card.type-holiday{border-left-color:var(--e);}
        .event-card.type-exam{border-left-color:var(--warning);}
        .event-card.type-sports{border-left-color:var(--suc);}
        .event-card.type-ceremony{border-left-color:var(--i);}
        .event-card.type-meeting{border-left-color:#8b5cf6;}
        .event-card.type-other{border-left-color:var(--g500);}
        .event-card.type-academic{border-left-color:var(--p);}
        .event-date{background:var(--p);color:white;padding:0.35rem;border-radius:6px;text-align:center;min-width:45px;}
        .event-card.type-holiday .event-date{background:var(--e);}
        .event-card.type-exam .event-date{background:var(--warning);}
        .event-card.type-sports .event-date{background:var(--suc);}
        .event-card.type-ceremony .event-date{background:var(--i);}
        .event-card.type-meeting .event-date{background:#8b5cf6;}
        .event-card.type-other .event-date{background:var(--g500);}
        .event-card.type-academic .event-date{background:var(--p);}
        .event-date .day{font-size:1.1rem;font-weight:700;display:block;}
        .event-date .month{font-size:0.6rem;text-transform:uppercase;}
        .event-details{flex:1;}
        .event-details h4{font-family:'Poppins',sans-serif;font-size:0.9rem;font-weight:600;color:var(--g900);margin:0 0 0.25rem 0;}
        .event-details p{color:var(--g500);font-size:0.8rem;margin:0;}
        .event-actions{display:flex;gap:0.25rem;}
        .event-action-btn{background:none;border:none;cursor:pointer;color:var(--g500);padding:0.2rem;font-size:0.8rem;}
        .event-action-btn:hover{color:var(--p);}
        .event-action-btn.delete:hover{color:var(--e);}
        .quick-actions-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:0.5rem;}
        .quick-action-card{display:flex;flex-direction:column;align-items:center;gap:0.35rem;padding:1rem 0.5rem;border-radius:12px;color:white;cursor:pointer;transition:0.2s;}
        .quick-action-card:hover{transform:translateY(-2px);}
        .quick-action-card.academic{background:linear-gradient(135deg,#4f46e5,#7c3aed);}
        .quick-action-card.holiday{background:linear-gradient(135deg,#ef4444,#dc2626);}
        .quick-action-card.exam{background:linear-gradient(135deg,#f59e0b,#d97706);}
        .quick-action-card.sports{background:linear-gradient(135deg,#10b981,#059669);}
        .quick-action-card i{font-size:1.5rem;}
        .quick-action-card span{font-size:0.8rem;font-weight:600;}
        .stats-calendar{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:1rem;margin-bottom:1.5rem;}
        .stat-card{background:var(--white);border-radius:12px;padding:1rem;box-shadow:0 1px 2px 0 rgb(0 0 0/0.05);border:1px solid var(--g200);}
        .stat-card:hover{box-shadow:0 4px 6px -1px rgb(0 0 0/0.1);}
        .stat-icon{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--p),var(--s));display:flex;align-items:center;justify-content:center;color:white;font-size:1rem;margin-bottom:0.5rem;}
        .stat-icon.holiday{background:linear-gradient(135deg,var(--e),#dc2626);}
        .stat-icon.exam{background:linear-gradient(135deg,var(--warning),#d97706);}
        .stat-icon.sports{background:linear-gradient(135deg,var(--suc),#059669);}
        .stat-icon.ceremony{background:linear-gradient(135deg,var(--i),#2563eb);}
        .stat-info h3{font-family:'Poppins',sans-serif;font-size:1.1rem;font-weight:600;color:var(--g900);margin:0;}
        .stat-info p{color:var(--g500);font-size:0.8rem;margin:0;}
        .calendar-legend{display:flex;flex-wrap:wrap;gap:0.75rem;justify-content:center;margin-top:1rem;}
        .legend-item{display:flex;align-items:center;gap:0.35rem;font-size:0.75rem;color:var(--g500);}
        .legend-color{width:12px;height:12px;border-radius:50%;}
        .legend-color.holiday{background:var(--e);}
        .legend-color.exam{background:var(--warning);}
        .legend-color.sport{background:var(--suc);}
        .legend-color.ceremony{background:var(--i);}
        .legend-color.meeting{background:#8b5cf6;}
        .legend-color.event{background:var(--p);}
        .legend-color.other{background:var(--g500);}
        .modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;justify-content:center;align-items:center;}
        .modal.active{display:flex;}
        .modal-content{background:white;border-radius:16px;max-width:450px;width:90%;max-height:90vh;overflow-y:auto;}
        .modal-header{display:flex;justify-content:space-between;align-items:center;padding:1rem;border-bottom:1px solid var(--g200);}
        .modal-header h2{font-family:'Poppins',sans-serif;font-size:1.1rem;font-weight:600;color:var(--g900);margin:0;}
        .close-btn{background:none;border:none;font-size:1.25rem;cursor:pointer;color:var(--g500);}
        .modal-body{padding:1rem;}
        .modal-footer{display:flex;justify-content:flex-end;gap:0.5rem;padding:1rem;border-top:1px solid var(--g200);}
        .form-group{margin-bottom:1rem;}
        .form-label{display:block;font-weight:500;color:var(--g500);margin-bottom:0.35rem;font-size:0.9rem;}
        .form-control{width:100%;padding:0.6rem;border:1px solid var(--g200);border-radius:8px;font-size:0.9rem;transition:0.2s;}
        .form-control:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 3px rgba(79,70,229,0.1);}
        .btn{padding:0.6rem 1.25rem;border:none;border-radius:8px;font-weight:500;cursor:pointer;transition:0.2s;}
        .btn-primary{background:var(--p);color:white;}
        .btn-primary:hover{background:var(--pd);}
        .btn-secondary{background:var(--g200);color:var(--g500);}
        .btn-secondary:hover{background:var(--g100);}
        .btn-danger{background:var(--e);color:white;}
        .toast{position:fixed;bottom:2rem;right:2rem;background:var(--g900);color:white;padding:0.75rem 1.25rem;border-radius:8px;box-shadow:0 10px 15px -3px rgb(0 0 0/0.1);z-index:2000;opacity:0;transform:translateX(100px);transition:0.3s;}
        .toast.show{opacity:1;transform:translateX(0);}
        .toast.success{background:var(--suc);}
        .toast.error{background:var(--e);}
        .form-row{display:flex;gap:0.75rem;}
        .form-row .form-group{flex:1;}
        @media(max-width:768px){.main-content{padding:1rem;}.calendar-container,.upcoming-events,.quick-actions-calendar{padding:1rem;}.calendar-day{min-height:60px;}.event-item{font-size:0.55rem;padding:0.1rem 0.2rem;}}
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
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>

            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt logout-icon"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✖</button>
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
                        <a href="manage_class.php" class="nav-link">
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
                        <a href="support.php" class="nav-link">
                            <span class="nav-icon">🛟</span>
                            <span class="nav-text">Support</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subscription.php" class="nav-link">
                            <span class="nav-icon">💳</span>
                            <span class="nav-text">Subscription</span>
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
                            <span class="nav-icon">📆</span>
                            <span class="nav-text">School Sessions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_calendar.php" class="nav-link active">
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
                    <h2>📅 School Calendar</h2>
                    <p>Manage events, schedules, and school activities</p>
                </div>
            </div>

            <div class="calendar-container">
            <div id="calendarContent">
                <?php echo renderCalendar($current_month, $current_year, $monthly_events); ?>
            </div>
        </div>

        <div class="upcoming-events">
            <h3 class="section-title">Upcoming Events</h3>
            <div class="events-list">
                <?php if (empty($upcoming_events)): ?>
                    <div style="text-align:center;color:#6b7280;padding:2rem;">No upcoming events</div>
                <?php else: ?>
                    <?php foreach ($upcoming_events as $event): ?>
                        <?php 
                        $event_date = new DateTime($event['event_date']);
                        $is_today = $event_date->format('Y-m-d') === date('Y-m-d');
                        ?>
                        <div class="event-card type-<?php echo htmlspecialchars($event['event_type']); ?>">
                            <div class="event-date">
                                <span class="day"><?php echo $event_date->format('d'); ?></span>
                                <span class="month"><?php echo $event_date->format('M'); ?></span>
                            </div>
                            <div class="event-details">
                                <h4><?php echo htmlspecialchars($event['title']); ?></h4>
                                <p><?php echo htmlspecialchars($event['description'] ?? 'No description'); ?></p>
                                <?php if ($event['location']): ?>
                                    <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location']); ?></p>
                                <?php endif; ?>
                                <?php if ($event['event_time'] && !$event['is_all_day']): ?>
                                    <p><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($event['event_time'])); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="event-actions">
                                <button class="event-action-btn" onclick="viewEvent(<?php echo $event['id']; ?>)" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="event-action-btn" onclick="editEvent(<?php echo $event['id']; ?>)" title="Edit Event">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="event-action-btn delete" onclick="deleteEvent(<?php echo $event['id']; ?>)" title="Delete Event">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="quick-actions-calendar">
            <h3 class="section-title">Quick Actions</h3>
            <div class="quick-actions-grid">
                <div class="quick-action-card academic" onclick="addEventWithType('academic')">
                    <i class="fas fa-book"></i>
                    <span>Academic</span>
                </div>
                <div class="quick-action-card holiday" onclick="addEventWithType('holiday')">
                    <i class="fas fa-umbrella-beach"></i>
                    <span>Holiday</span>
                </div>
                <div class="quick-action-card exam" onclick="addEventWithType('exam')">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Exam</span>
                </div>
                <div class="quick-action-card sports" onclick="addEventWithType('sports')">
                    <i class="fas fa-futbol"></i>
                    <span>Sports</span>
                </div>
                <div class="quick-action-card ceremony" onclick="addEventWithType('ceremony')">
                    <i class="fas fa-gift"></i>
                    <span>Ceremony</span>
                </div>
                <div class="quick-action-card meeting" onclick="addEventWithType('meeting')">
                    <i class="fas fa-users"></i>
                    <span>Meeting</span>
                </div>
            </div>
            <div class="calendar-legend">
                <div class="legend-item"><div class="legend-color holiday"></div> Holiday</div>
                <div class="legend-item"><div class="legend-color exam"></div> Exam</div>
                <div class="legend-item"><div class="legend-color sport"></div> Sports</div>
                <div class="legend-item"><div class="legend-color ceremony"></div> Ceremony</div>
                <div class="legend-item"><div class="legend-color meeting"></div> Meeting</div>
                <div class="legend-item"><div class="legend-color event"></div> Academic</div>
                <div class="legend-item"><div class="legend-color other"></div> Other</div>
            </div>
        </div>
        </main>
    </div>

    <!-- Add/Edit Event Modal -->
    <div class="modal" id="eventModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add Event</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="eventForm">
                    <input type="hidden" id="eventId" name="id">
                    <div class="form-group">
                        <label class="form-label" for="eventTitle">Event Title</label>
                        <input type="text" id="eventTitle" name="title" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="eventDate">Date</label>
                            <input type="date" id="eventDate" name="event_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="eventType">Event Type</label>
                            <select id="eventType" name="event_type" class="form-control" required>
                                <option value="academic">Academic</option>
                                <option value="holiday">Holiday</option>
                                <option value="exam">Exam</option>
                                <option value="sports">Sports</option>
                                <option value="ceremony">Ceremony</option>
                                <option value="meeting">Meeting</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="eventTime">Time</label>
                            <input type="time" id="eventTime" name="event_time" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="isAllDay">All Day</label>
                            <input type="checkbox" id="isAllDay" name="is_all_day" value="1" style="width: auto; margin-left: 10px;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="eventLocation">Location</label>
                        <input type="text" id="eventLocation" name="location" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="eventDescription">Description</label>
                        <textarea id="eventDescription" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="eventColor">Custom Color</label>
                        <input type="color" id="eventColor" name="color" class="form-control" style="height: 40px; padding: 0; border: none;">
                        <small style="color: #6b7280;">Leave empty to use default color for event type</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveEvent()">Save Event</button>
            </div>
        </div>
    </div>

    <!-- View Event Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="viewModalTitle">Event Details</h2>
                <button class="close-btn" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- Event details will be populated here -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeViewModal()">Close</button>
                <button class="btn btn-primary" onclick="editCurrentEvent()">Edit</button>
                <button class="btn btn-danger" onclick="deleteCurrentEvent()">Delete</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <script>
        let currentMonth = <?php echo $current_month; ?>;
        let currentYear = <?php echo $current_year; ?>;
        let currentViewEventId = null;

        // Initialize calendar
        document.addEventListener('DOMContentLoaded', function() {
            // Set default date to today for new events
            const today = new Date();
            const todayString = today.toISOString().split('T')[0];
            document.getElementById('eventDate').value = todayString;
            
            // Handle all-day checkbox
            const isAllDayCheckbox = document.getElementById('isAllDay');
            const eventTimeInput = document.getElementById('eventTime');
            
            isAllDayCheckbox.addEventListener('change', function() {
                eventTimeInput.disabled = this.checked;
                if (this.checked) {
                    eventTimeInput.value = '';
                }
            });
        });

        // Calendar Navigation
        function changeMonth(month, year) {
            currentMonth = month;
            currentYear = year;
            loadCalendar(month, year);
        }

        async function loadCalendar(month, year) {
            try {
                const response = await fetch(`ajax/get_calendar_events.php?month=${month}&year=${year}`);
                const data = await response.json();
                
                if (data.success) {
                    // Update current month/year display
                    const monthName = new Date(year, month - 1).toLocaleString('default', { month: 'long' });
                    document.getElementById('currentMonth').textContent = `${monthName} ${year}`;
                    
                    // Re-render calendar with new events
                    renderCalendarWithEvents(month, year, data.events);
                } else {
                    showToast('Failed to load calendar events', 'error');
                }
            } catch (error) {
                console.error('Error loading calendar:', error);
                showToast('Error loading calendar', 'error');
            }
        }

        function renderCalendarWithEvents(month, year, events) {
            // This would need to be implemented as a JavaScript version of the PHP renderCalendar function
            // For now, we'll reload the page with new parameters
            window.location.href = `school_calendar.php?month=${month}&year=${year}`;
        }

        // Event Management
        function addEventOnDate(dateString) {
            const date = new Date(dateString);
            const dateInput = document.getElementById('eventDate');
            dateInput.value = dateString;
            document.getElementById('modalTitle').textContent = 'Add Event';
            document.getElementById('eventId').value = '';
            document.getElementById('eventForm').reset();
            document.getElementById('eventDate').value = dateString;
            showModal();
        }

        function addEventWithType(type) {
            document.getElementById('modalTitle').textContent = 'Add ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Event';
            document.getElementById('eventType').value = type;
            document.getElementById('eventForm').reset();
            const today = new Date();
            const todayString = today.toISOString().split('T')[0];
            document.getElementById('eventDate').value = todayString;
            showModal();
        }

        function viewEvent(eventId) {
            currentViewEventId = eventId;
            fetch(`ajax/get_calendar_events.php`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const event = data.events.find(e => e.id === eventId);
                        if (event) {
                            showViewModal(event);
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching event:', error);
                    showToast('Error loading event', 'error');
                });
        }

        function editEvent(eventId) {
            fetch(`ajax/get_calendar_events.php`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const event = data.events.find(e => e.id === eventId);
                        if (event) {
                            populateEditForm(event);
                            showModal();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching event:', error);
                    showToast('Error loading event', 'error');
                });
        }

        function populateEditForm(event) {
            document.getElementById('modalTitle').textContent = 'Edit Event';
            document.getElementById('eventId').value = event.id;
            document.getElementById('eventTitle').value = event.title;
            document.getElementById('eventDate').value = event.start.split('T')[0];
            document.getElementById('eventType').value = event.type;
            document.getElementById('eventDescription').value = event.description || '';
            document.getElementById('eventLocation').value = event.location || '';
            document.getElementById('eventColor').value = event.color || '';
            
            if (event.allDay) {
                document.getElementById('isAllDay').checked = true;
                document.getElementById('eventTime').disabled = true;
                document.getElementById('eventTime').value = '';
            } else {
                document.getElementById('isAllDay').checked = false;
                document.getElementById('eventTime').disabled = false;
                document.getElementById('eventTime').value = event.start.split('T')[1] || '';
            }
        }

        async function saveEvent() {
            const form = document.getElementById('eventForm');
            const formData = new FormData(form);
            
            // Handle all-day events
            const isAllDay = document.getElementById('isAllDay').checked;
            if (isAllDay) {
                formData.set('event_time', '');
            }

            try {
                const response = await fetch('ajax/save_calendar_event.php', {
                    method: 'POST',
                    body: JSON.stringify(Object.fromEntries(formData))
                });

                const data = await response.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal();
                    // Reload calendar to show updated events
                    loadCalendar(currentMonth, currentYear);
                } else {
                    showToast(data.message || 'Failed to save event', 'error');
                }
            } catch (error) {
                console.error('Error saving event:', error);
                showToast('Error saving event', 'error');
            }
        }

        async function deleteEvent(eventId) {
            if (!confirm('Are you sure you want to delete this event?')) {
                return;
            }

            try {
                const response = await fetch('ajax/delete_calendar_event.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: eventId })
                });

                const data = await response.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    // Reload calendar to show updated events
                    loadCalendar(currentMonth, currentYear);
                } else {
                    showToast(data.message || 'Failed to delete event', 'error');
                }
            } catch (error) {
                console.error('Error deleting event:', error);
                showToast('Error deleting event', 'error');
            }
        }

        // Modal Functions
        function showModal() {
            document.getElementById('eventModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('eventModal').classList.remove('active');
        }

        function showViewModal(event) {
            const modal = document.getElementById('viewModal');
            const title = document.getElementById('viewModalTitle');
            const body = document.getElementById('viewModalBody');
            
            title.textContent = event.title;
            
            const date = new Date(event.start);
            const formattedDate = date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            let timeHtml = '';
            if (!event.allDay) {
                const time = new Date(event.start);
                timeHtml = `<p><i class="fas fa-clock"></i> ${time.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</p>`;
            }
            
            body.innerHTML = `
                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                    <div style="width: 12px; height: 12px; border-radius: 50%; background-color: ${event.color};"></div>
                    <span style="font-weight: 600; color: #374151;">${event.type.toUpperCase()}</span>
                </div>
                <p><i class="fas fa-calendar"></i> ${formattedDate}</p>
                ${timeHtml}
                ${event.location ? `<p><i class="fas fa-map-marker-alt"></i> ${event.location}</p>` : ''}
                ${event.description ? `<p><i class="fas fa-align-left"></i> ${event.description}</p>` : '<p><i class="fas fa-align-left"></i> No description</p>'}
            `;
            
            modal.classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
            currentViewEventId = null;
        }

        function editCurrentEvent() {
            if (currentViewEventId) {
                editEvent(currentViewEventId);
                closeViewModal();
            }
        }

        function deleteCurrentEvent() {
            if (currentViewEventId) {
                deleteEvent(currentViewEventId);
                closeViewModal();
            }
        }

        // Toast Notifications
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type}`;
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const eventModal = document.getElementById('eventModal');
            const viewModal = document.getElementById('viewModal');
            
            if (event.target === eventModal) {
                closeModal();
            }
            if (event.target === viewModal) {
                closeViewModal();
            }
        });

        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        if (mobileMenuToggle && sidebar) {
            mobileMenuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                mobileMenuToggle.classList.toggle('active');
            });

            if (sidebarClose) {
                sidebarClose.addEventListener('click', () => {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                });
            }

            document.addEventListener('click', (e) => {
                if (window.innerWidth <= 1024) {
                    if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                        sidebar.classList.remove('active');
                        mobileMenuToggle.classList.remove('active');
                    }
                }
            });
        }
    </script>
</body>
</html>



