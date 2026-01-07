<?php

session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_name = $_SESSION['full_name'];

// Fetch calendar events
try {
    // Get current month events
    $current_month = date('m');
    $current_year = date('Y');

    $stmt = $pdo->prepare("
        SELECT * FROM calendar_events
        WHERE MONTH(event_date) = ? AND YEAR(event_date) = ?
        ORDER BY event_date ASC
    ");
    $stmt->execute([$current_month, $current_year]);
    $monthly_events = $stmt->fetchAll();

    // Get upcoming events (next 30 days)
    $stmt = $pdo->prepare("
        SELECT * FROM calendar_events
        WHERE event_date >= CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY event_date ASC
        LIMIT 5
    ");
    $stmt->execute();
    $upcoming_events = $stmt->fetchAll();

    // Count events by type
    $stmt = $pdo->query("SELECT event_type, COUNT(*) as count FROM calendar_events GROUP BY event_type");
    $event_types = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

} catch (PDOException $e) {
    error_log("Calendar error: " . $e->getMessage());
    $monthly_events = [];
    $upcoming_events = [];
    $event_types = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Calendar | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.11.3/main.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.11.3/main.min.js"></script>
    <style>
        .calendar-container {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-sm);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-100);
        }

        .calendar-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0;
        }

        .calendar-nav {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .calendar-nav-btn {
            padding: 0.5rem 1rem;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius-md);
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .calendar-nav-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .current-month {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-700);
            min-width: 200px;
            text-align: center;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--gray-200);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
        }

        .calendar-day-header {
            background: var(--primary-color);
            color: white;
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .calendar-day {
            background: var(--white);
            min-height: 120px;
            padding: 0.5rem;
            position: relative;
            transition: var(--transition-fast);
        }

        .calendar-day:hover {
            background: var(--gray-50);
        }

        .calendar-day.other-month {
            background: var(--gray-50);
            color: var(--gray-400);
        }

        .calendar-day-number {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }

        .calendar-day.other-month .calendar-day-number {
            color: var(--gray-400);
        }

        .calendar-day.today {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .calendar-day.today .calendar-day-number {
            color: white;
        }

        .event-item {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius-sm);
            font-size: 0.75rem;
            margin-bottom: 0.25rem;
            cursor: pointer;
            transition: var(--transition-fast);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .event-item:hover {
            opacity: 0.8;
            transform: translateY(-1px);
        }

        .event-holiday { background: var(--error-color); }
        .event-exam { background: var(--warning-color); }
        .event-sport { background: var(--success-color); }
        .event-ceremony { background: var(--info-color); }

        .upcoming-events {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-sm);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .events-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .event-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--gray-50);
            border-radius: var(--border-radius-lg);
            border-left: 4px solid var(--primary-color);
            transition: var(--transition-normal);
        }

        .event-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .event-date {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem;
            border-radius: var(--border-radius-md);
            text-align: center;
            min-width: 60px;
        }

        .event-date .day {
            font-size: 1.25rem;
            font-weight: 700;
            display: block;
            line-height: 1;
        }

        .event-date .month {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .event-details h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .event-details p {
            color: var(--gray-600);
            font-size: 0.9rem;
            margin: 0;
        }

        .quick-actions-calendar {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-sm);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .quick-action-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            padding: 2rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-sm);
            text-decoration: none;
            color: var(--white);
            transition: var(--transition-normal);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .quick-action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .quick-action-card:hover::before {
            left: 100%;
        }

        .quick-action-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-color);
        }

        .quick-action-card i {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
        }

        .quick-action-card span {
            font-weight: 600;
            font-size: 0.95rem;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .stats-calendar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            transition: var(--transition-normal);
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        .stat-info h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0 0 0.25rem 0;
        }

        .stat-info p {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
        }

        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            margin-top: 1rem;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--gray-600);
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 50%;
        }

        .legend-color.holiday { background: var(--error-color); }
        .legend-color.exam { background: var(--warning-color); }
        .legend-color.sport { background: var(--success-color); }
        .legend-color.ceremony { background: var(--info-color); }
        .legend-color.event { background: var(--primary-color); }

        @media (max-width: 768px) {
            .calendar-container {
                padding: 1rem;
            }

            .calendar-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .calendar-nav {
                justify-content: center;
            }

            .calendar-grid {
                grid-template-columns: repeat(7, 1fr);
                font-size: 0.8rem;
            }

            .calendar-day {
                min-height: 80px;
                padding: 0.25rem;
            }

            .calendar-day-number {
                font-size: 0.9rem;
            }

            .event-item {
                font-size: 0.65rem;
                padding: 0.2rem 0.4rem;
            }

            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .quick-action-card {
                padding: 1.5rem 1rem;
            }

            .stats-calendar {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
        }
    </style>
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>

            <!-- Principal Info and Logout -->
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon">🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
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

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>School Calendar 🗓️</h2>
                    <p>Manage academic events, holidays, and school activities</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo count($monthly_events); ?></span>
                        <span class="quick-stat-label">Events This Month</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo count($upcoming_events); ?></span>
                        <span class="quick-stat-label">Upcoming Events</span>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions-calendar">
                <div class="section-header">
                    <h3>⚡ Quick Actions</h3>
                    <span class="section-badge">Calendar Management</span>
                </div>
                <div class="quick-actions-grid">
                    <a href="#" class="quick-action-card" onclick="openAddEventModal()">
                        <i class="fas fa-plus"></i>
                        <span>Add New Event</span>
                    </a>
                    <a href="#" class="quick-action-card" onclick="exportCalendar()">
                        <i class="fas fa-download"></i>
                        <span>Export Calendar</span>
                    </a>
                    <a href="#" class="quick-action-card" onclick="viewCalendarSettings()">
                        <i class="fas fa-cog"></i>
                        <span>Calendar Settings</span>
                    </a>
                    <a href="#" class="quick-action-card" onclick="shareCalendar()">
                        <i class="fas fa-share"></i>
                        <span>Share Calendar</span>
                    </a>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-calendar">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo isset($event_types['academic']) ? $event_types['academic'] : 0; ?></h3>
                            <p>Academic Events</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon">
                            <i class="fas fa-umbrella-beach"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo isset($event_types['holiday']) ? $event_types['holiday'] : 0; ?></h3>
                            <p>Holidays</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo isset($event_types['sports']) ? $event_types['sports'] : 0; ?></h3>
                            <p>Sports Events</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo isset($event_types['ceremony']) ? $event_types['ceremony'] : 0; ?></h3>
                            <p>Ceremonies</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Calendar Container -->
            <div class="calendar-container">
                <div class="calendar-header">
                    <h2 class="calendar-title">Monthly Calendar View</h2>
                    <div class="calendar-nav">
                        <button class="calendar-nav-btn" onclick="previousMonth()">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <div class="current-month" id="currentMonthYear">
                            <?php echo date('F Y'); ?>
                        </div>
                        <button class="calendar-nav-btn" onclick="nextMonth()">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>

                <div class="calendar-grid" id="calendarGrid">
                    <!-- Calendar will be generated by JavaScript -->
                </div>

                <div class="calendar-legend">
                    <div class="legend-item">
                        <div class="legend-color event"></div>
                        <span>General Events</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color holiday"></div>
                        <span>Holidays</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color exam"></div>
                        <span>Examinations</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color sport"></div>
                        <span>Sports</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color ceremony"></div>
                        <span>Ceremonies</span>
                    </div>
                </div>
            </div>

            <!-- Upcoming Events -->
            <div class="upcoming-events">
                <div class="section-header">
                    <h3>📅 Upcoming Events</h3>
                    <span class="section-badge">Next 30 Days</span>
                </div>

                <div class="events-list">
                    <?php if (!empty($upcoming_events)): ?>
                        <?php foreach ($upcoming_events as $event): ?>
                            <div class="event-card">
                                <div class="event-date">
                                    <span class="day"><?php echo date('d', strtotime($event['event_date'])); ?></span>
                                    <span class="month"><?php echo date('M', strtotime($event['event_date'])); ?></span>
                                </div>
                                <div class="event-details">
                                    <h4><?php echo htmlspecialchars($event['title']); ?></h4>
                                    <p><?php echo htmlspecialchars($event['description'] ?? ''); ?></p>
                                    <small style="color: var(--gray-500);">
                                        <i class="fas fa-tag"></i> <?php echo ucfirst($event['event_type']); ?>
                                        <?php if ($event['location']): ?>
                                            | <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="event-card" style="justify-content: center; padding: 2rem;">
                            <div class="event-details" style="text-align: center;">
                                <i class="fas fa-calendar-times" style="font-size: 3rem; color: var(--gray-400); margin-bottom: 1rem;"></i>
                                <h4>No Upcoming Events</h4>
                                <p style="color: var(--gray-500);">All caught up! Add new events to stay organized.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>About SahabFormMaster</h4>
                    <p>A comprehensive school management system designed for academic excellence and efficient administration.</p>
                </div>
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="manage-school.php">School Settings</a></li>
                        <li><a href="manage_user.php">User Management</a></li>
                        <li><a href="#">Support & Help</a></li>
                        <li><a href="#">Documentation</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact Information</h4>
                    <p>📧 admin@sahabformmaster.com</p>
                    <p>📱 +234 808 683 5607</p>
                    <p>🌐 www.sahabformmaster.com</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 SahabFormMaster. All rights reserved.</p>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <span>•</span>
                    <a href="#">Terms of Service</a>
                    <span>•</span>
                    <span>Version 2.0</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- Add Event Modal -->
    <div id="addEventModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus"></i> Add New Event</h2>
                <button class="close-btn" onclick="closeAddEventModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addEventForm">
                    <div class="form-group">
                        <label class="form-label">Event Title</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Event Date</label>
                        <input type="date" class="form-control" name="event_date" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Event Type</label>
                        <select class="form-control" name="event_type" required>
                            <option value="">Select Type</option>
                            <option value="academic">Academic</option>
                            <option value="holiday">Holiday</option>
                            <option value="exam">Examination</option>
                            <option value="sports">Sports</option>
                            <option value="ceremony">Ceremony</option>
                            <option value="meeting">Meeting</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Location (Optional)</label>
                        <input type="text" class="form-control" name="location">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description (Optional)</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Time (Optional)</label>
                        <input type="time" class="form-control" name="event_time">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddEventModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAddEvent()">Add Event</button>
            </div>
        </div>
    </div>

    <script>
        // Calendar variables
        let currentDate = new Date();

        // Mobile Menu Toggle
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

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        // Calendar Functions
        function generateCalendar() {
            const calendarGrid = document.getElementById('calendarGrid');
            const currentMonthYear = document.getElementById('currentMonthYear');

            // Update month/year display
            currentMonthYear.textContent = currentDate.toLocaleDateString('en-US', {
                month: 'long',
                year: 'numeric'
            });

            // Clear existing calendar
            calendarGrid.innerHTML = '';

            // Add day headers
            const daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            daysOfWeek.forEach(day => {
                const headerCell = document.createElement('div');
                headerCell.className = 'calendar-day-header';
                headerCell.textContent = day;
                calendarGrid.appendChild(headerCell);
            });

            // Get calendar data
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();

            // First day of the month
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const startDate = new Date(firstDay);
            startDate.setDate(startDate.getDate() - firstDay.getDay());

            // Generate calendar cells
            for (let i = 0; i < 42; i++) {
                const cellDate = new Date(startDate);
                cellDate.setDate(startDate.getDate() + i);

                const dayCell = document.createElement('div');
                dayCell.className = 'calendar-day';

                // Check if it's the current month
                if (cellDate.getMonth() !== month) {
                    dayCell.classList.add('other-month');
                }

                // Check if it's today
                const today = new Date();
                if (cellDate.toDateString() === today.toDateString()) {
                    dayCell.classList.add('today');
                }

                // Add day number
                const dayNumber = document.createElement('div');
                dayNumber.className = 'calendar-day-number';
                dayNumber.textContent = cellDate.getDate();
                dayCell.appendChild(dayNumber);

                // Sample events (in a real app, this would come from the database)
                const events = getEventsForDate(cellDate);
                events.forEach(event => {
                    const eventItem = document.createElement('div');
                    eventItem.className = `event-item event-${event.type}`;
                    eventItem.textContent = event.title;
                    eventItem.onclick = () => showEventDetails(event);
                    dayCell.appendChild(eventItem);
                });

                calendarGrid.appendChild(dayCell);
            }
        }

        function getEventsForDate(date) {
            // Sample events data - in a real app, this would fetch from the database
            const sampleEvents = [
                {
                    date: new Date(2025, 0, 15), // January 15, 2025
                    title: 'School Reopening',
                    type: 'ceremony',
                    description: 'New academic year begins'
                },
                {
                    date: new Date(2025, 0, 20), // January 20, 2025
                    title: 'Mid-term Exams',
                    type: 'exam',
                    description: 'First term examinations'
                },
                {
                    date: new Date(2025, 0, 25), // January 25, 2025
                    title: 'Sports Day',
                    type: 'sport',
                    description: 'Annual inter-house sports competition'
                },
                {
                    date: new Date(2025, 1, 10), // February 10, 2025
                    title: 'Valentine Holiday',
                    type: 'holiday',
                    description: 'Public holiday'
                }
            ];

            return sampleEvents.filter(event =>
                event.date.toDateString() === date.toDateString()
            );
        }

        function showEventDetails(event) {
            alert(`Event: ${event.title}\nType: ${event.type}\nDescription: ${event.description}`);
        }

        function previousMonth() {
            currentDate.setMonth(currentDate.getMonth() - 1);
            generateCalendar();
        }

        function nextMonth() {
            currentDate.setMonth(currentDate.getMonth() + 1);
            generateCalendar();
        }

        // Modal functions
        function openAddEventModal() {
            document.getElementById('addEventModal').style.display = 'block';
        }

        function closeAddEventModal() {
            document.getElementById('addEventModal').style.display = 'none';
        }

        function submitAddEvent() {
            const form = document.getElementById('addEventForm');
            const formData = new FormData(form);

            // In a real app, this would send data to the server
            alert('Event added successfully! (This is a demo)');
            closeAddEventModal();
            form.reset();
        }

        function exportCalendar() {
            alert('Calendar export feature coming soon!');
        }

        function viewCalendarSettings() {
            alert('Calendar settings feature coming soon!');
        }

        function shareCalendar() {
            alert('Calendar sharing feature coming soon!');
        }

        // Initialize calendar on page load
        document.addEventListener('DOMContentLoaded', function() {
            generateCalendar();
        });

        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Add active class on scroll
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>