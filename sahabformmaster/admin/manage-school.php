<?php
session_start();
require_once '../config/db.php';

// Principal authentication
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'principal') {
    header('Location: login.php');
    exit();
}

// Handle CRUD Operations
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

if ($action === 'update_school_info') {
    // Update school basic information
    $stmt = $pdo->prepare("UPDATE school_info SET 
        school_name = ?, motto = ?, established_year = ?, 
        affiliation_number = ?, udise_code = ?, 
        principal_name = ?, principal_contact = ?, 
        principal_email = ?, address = ?, city = ?, 
        state = ?, pincode = ?, phone = ?, email = ?, 
        website = ?, updated_at = NOW() WHERE id = 1");
    
    if ($stmt->execute([
        $_POST['school_name'], $_POST['motto'], $_POST['established_year'],
        $_POST['affiliation_number'], $_POST['udise_code'],
        $_POST['principal_name'], $_POST['principal_contact'],
        $_POST['principal_email'], $_POST['address'], $_POST['city'],
        $_POST['state'], $_POST['pincode'], $_POST['phone'], $_POST['email'],
        $_POST['website']
    ])) {
        $message = "School information updated successfully!";
        $message_type = "success";
    }
}

if ($action === 'update_timings') {
    // Update school timings
    $stmt = $pdo->prepare("UPDATE school_timings SET 
        working_days = ?, working_hours_start = ?, 
        working_hours_end = ?, office_start_time = ?, 
        office_end_time = ?, break_start = ?, break_end = ?, 
        updated_at = NOW() WHERE id = 1");
    
    if ($stmt->execute([
        $_POST['working_days'], $_POST['working_hours_start'],
        $_POST['working_hours_end'], $_POST['office_start_time'],
        $_POST['office_end_time'], $_POST['break_start'],
        $_POST['break_end']
    ])) {
        $message = "School timings updated successfully!";
        $message_type = "success";
    }
}

// Fetch current school data
$school_info = $pdo->query("SELECT * FROM school_info WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$school_timings = $pdo->query("SELECT * FROM school_timings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT * FROM departments ORDER BY dept_order")->fetchAll(PDO::FETCH_ASSOC);
$academic_year = $pdo->query("SELECT * FROM academic_year WHERE is_current = 1")->fetch(PDO::FETCH_ASSOC);
$holidays = $pdo->query("SELECT * FROM holidays WHERE holiday_date >= CURDATE() ORDER BY holiday_date LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Management Dashboard</title>
    <link rel="stylesheet" href="../assets/css/manage-school.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="logo">
                <i class="fas fa-school"></i>
                <div class="school-info-header">
                    <h1>School Administration System</h1>
                    <p class="school-name"><?php echo htmlspecialchars($school_info['school_name'] ?? 'School Name'); ?></p>
                </div>
            </div>
            <div class="admin-info">
                <i class="fas fa-user-shield"></i>
                <div>
                    <span class="role">Principal</span>
                    <span class="name"><?php echo htmlspecialchars($school_info['principal_name'] ?? 'Principal Name'); ?></span>
                </div>
            </div>
        </header>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card">
                <i class="fas fa-calendar-alt"></i>
                <h3>Academic Year</h3>
                <p><?php echo $academic_year['year_name'] ?? 'Not Set'; ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3>Total Students</h3>
                <p><?php echo getTotalStudents(); ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-chalkboard-teacher"></i>
                <h3>Total Staff</h3>
                <p><?php echo getTotalStaff(); ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-building"></i>
                <h3>Departments</h3>
                <p><?php echo count($departments); ?></p>
            </div>
        </div>

        <!-- Message Alert -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-check-circle"></i>
            <span><?php echo $message; ?></span>
            <button class="alert-close" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-btn active" onclick="openTab(event, 'school-info')">
                <i class="fas fa-info-circle"></i> School Information
            </button>
            <button class="tab-btn" onclick="openTab(event, 'timings')">
                <i class="fas fa-clock"></i> Timings & Calendar
            </button>
            <button class="tab-btn" onclick="openTab(event, 'departments')">
                <i class="fas fa-building"></i> Departments
            </button>
            <button class="tab-btn" onclick="openTab(event, 'academic')">
                <i class="fas fa-graduation-cap"></i> Academic Settings
            </button>
            <button class="tab-btn" onclick="openTab(event, 'system')">
                <i class="fas fa-cogs"></i> System Settings
            </button>
        </div>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- Tab 1: School Information -->
            <div id="school-info" class="tab-pane active">
                <div class="section-header">
                    <h2><i class="fas fa-school"></i> School Basic Information</h2>
                </div>
                
                <form method="POST" class="school-form">
                    <input type="hidden" name="action" value="update_school_info">
                    
                    <div class="form-grid">
                        <!-- School Identity -->
                        <div class="form-group full-width">
                            <label><i class="fas fa-school"></i> School Name *</label>
                            <input type="text" name="school_name" value="<?php echo htmlspecialchars($school_info['school_name'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-quote-left"></i> School Motto</label>
                            <input type="text" name="motto" value="<?php echo htmlspecialchars($school_info['motto'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-calendar-star"></i> Established Year</label>
                            <input type="number" name="established_year" min="1900" max="2099" 
                                   value="<?php echo $school_info['established_year'] ?? date('Y'); ?>">
                        </div>
                        
                        <!-- Official Codes -->
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> Affiliation Number</label>
                            <input type="text" name="affiliation_number" 
                                   value="<?php echo htmlspecialchars($school_info['affiliation_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-barcode"></i> UDISE Code</label>
                            <input type="text" name="udise_code" 
                                   value="<?php echo htmlspecialchars($school_info['udise_code'] ?? ''); ?>">
                        </div>
                        
                        <!-- Principal Information -->
                        <div class="section-divider">
                            <h3><i class="fas fa-user-tie"></i> Principal Information</h3>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Principal Name *</label>
                            <input type="text" name="principal_name" 
                                   value="<?php echo htmlspecialchars($school_info['principal_name'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Principal Contact</label>
                            <input type="tel" name="principal_contact" 
                                   value="<?php echo htmlspecialchars($school_info['principal_contact'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Principal Email</label>
                            <input type="email" name="principal_email" 
                                   value="<?php echo htmlspecialchars($school_info['principal_email'] ?? ''); ?>">
                        </div>
                        
                        <!-- Contact Information -->
                        <div class="section-divider">
                            <h3><i class="fas fa-address-card"></i> Contact Information</h3>
                        </div>
                        
                        <div class="form-group full-width">
                            <label><i class="fas fa-map-marker-alt"></i> Address *</label>
                            <textarea name="address" rows="2" required><?php echo htmlspecialchars($school_info['address'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> City</label>
                            <input type="text" name="city" value="<?php echo htmlspecialchars($school_info['city'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-map"></i> State</label>
                            <input type="text" name="state" value="<?php echo htmlspecialchars($school_info['state'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-mail-bulk"></i> Pincode</label>
                            <input type="text" name="pincode" value="<?php echo htmlspecialchars($school_info['pincode'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-phone-alt"></i> School Phone *</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($school_info['phone'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> School Email *</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($school_info['email'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-globe"></i> Website</label>
                            <input type="url" name="website" value="<?php echo htmlspecialchars($school_info['website'] ?? ''); ?>">
                        </div>
                        
                        <!-- School Logo Upload -->
                        <div class="form-group full-width">
                            <label><i class="fas fa-image"></i> School Logo</label>
                            <div class="logo-upload">
                                <div class="current-logo">
                                    <?php if (!empty($school_info['logo_path'])): ?>
                                    <img src="<?php echo htmlspecialchars($school_info['logo_path']); ?>" alt="School Logo">
                                    <?php else: ?>
                                    <i class="fas fa-school fa-3x"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="upload-controls">
                                    <input type="file" name="logo" accept="image/*">
                                    <small>Max size: 2MB | Formats: JPG, PNG, SVG</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save School Information
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab 2: Timings & Calendar -->
            <div id="timings" class="tab-pane">
                <div class="section-header">
                    <h2><i class="fas fa-clock"></i> School Timings & Calendar</h2>
                </div>
                
                <div class="timings-grid">
                    <!-- Working Hours -->
                    <div class="timings-form">
                        <h3><i class="fas fa-business-time"></i> Working Hours</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_timings">
                            
                            <div class="form-group">
                                <label>Working Days</label>
                                <input type="text" name="working_days" 
                                       value="<?php echo htmlspecialchars($school_timings['working_days'] ?? 'Monday - Friday'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>School Start Time</label>
                                <input type="time" name="working_hours_start" 
                                       value="<?php echo $school_timings['working_hours_start'] ?? '08:00'; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>School End Time</label>
                                <input type="time" name="working_hours_end" 
                                       value="<?php echo $school_timings['working_hours_end'] ?? '14:00'; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Office Start Time</label>
                                <input type="time" name="office_start_time" 
                                       value="<?php echo $school_timings['office_start_time'] ?? '08:00'; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Office End Time</label>
                                <input type="time" name="office_end_time" 
                                       value="<?php echo $school_timings['office_end_time'] ?? '17:00'; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Break Start</label>
                                <input type="time" name="break_start" 
                                       value="<?php echo $school_timings['break_start'] ?? '11:00'; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Break End</label>
                                <input type="time" name="break_end" 
                                       value="<?php echo $school_timings['break_end'] ?? '11:30'; ?>">
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Timings
                            </button>
                        </form>
                    </div>
                    
                    <!-- Holiday Calendar -->
                    <div class="holiday-calendar">
                        <h3><i class="fas fa-calendar-day"></i> Upcoming Holidays</h3>
                        <div class="holiday-list">
                            <?php if (!empty($holidays)): ?>
                                <?php foreach ($holidays as $holiday): ?>
                                <div class="holiday-item">
                                    <div class="holiday-date">
                                        <span class="day"><?php echo date('d', strtotime($holiday['holiday_date'])); ?></span>
                                        <span class="month"><?php echo date('M', strtotime($holiday['holiday_date'])); ?></span>
                                    </div>
                                    <div class="holiday-details">
                                        <h4><?php echo htmlspecialchars($holiday['holiday_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($holiday['description']); ?></p>
                                        <span class="holiday-type"><?php echo $holiday['holiday_type']; ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="no-data">No upcoming holidays scheduled</p>
                            <?php endif; ?>
                        </div>
                        
                        <button class="btn btn-secondary" onclick="openModal('add-holiday-modal')">
                            <i class="fas fa-plus"></i> Add Holiday
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Departments Management -->
            <div id="departments" class="tab-pane">
                <div class="section-header">
                    <h2><i class="fas fa-building"></i> Departments Management</h2>
                    <button class="btn btn-primary" onclick="openModal('add-dept-modal')">
                        <i class="fas fa-plus"></i> Add Department
                    </button>
                </div>
                
                <div class="departments-grid">
                    <?php foreach ($departments as $dept): ?>
                    <div class="department-card">
                        <div class="dept-icon">
                            <i class="fas fa-<?php echo $dept['icon'] ?? 'building'; ?>"></i>
                        </div>
                        <div class="dept-info">
                            <h3><?php echo htmlspecialchars($dept['dept_name']); ?></h3>
                            <p><?php echo htmlspecialchars($dept['description']); ?></p>
                            <div class="dept-stats">
                                <span><i class="fas fa-users"></i> <?php echo getDeptStaffCount($dept['id']); ?> Staff</span>
                                <span><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($dept['room_number']); ?></span>
                            </div>
                        </div>
                        <div class="dept-actions">
                            <button class="btn-action btn-edit" onclick="editDepartment(<?php echo $dept['id']; ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-action btn-delete" 
                                    onclick="deleteDepartment(<?php echo $dept['id']; ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Tab 4: Academic Settings -->
            <div id="academic" class="tab-pane">
                <div class="section-header">
                    <h2><i class="fas fa-graduation-cap"></i> Academic Settings</h2>
                </div>
                
                <div class="academic-grid">
                    <!-- Current Academic Year -->
                    <div class="academic-card">
                        <h3><i class="fas fa-calendar-alt"></i> Current Academic Year</h3>
                        <div class="current-academic-year">
                            <p><strong>Year:</strong> <?php echo $academic_year['year_name'] ?? 'Not Set'; ?></p>
                            <p><strong>Start Date:</strong> <?php echo $academic_year['start_date'] ?? 'Not Set'; ?></p>
                            <p><strong>End Date:</strong> <?php echo $academic_year['end_date'] ?? 'Not Set'; ?></p>
                            <p><strong>Status:</strong> <span class="status-active">Active</span></p>
                        </div>
                        <button class="btn btn-secondary" onclick="openModal('academic-year-modal')">
                            <i class="fas fa-edit"></i> Change Academic Year
                        </button>
                    </div>
                    
                    <!-- Grading System -->
                    <div class="academic-card">
                        <h3><i class="fas fa-chart-bar"></i> Grading System</h3>
                        <div class="grading-system">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Grade</th>
                                        <th>From %</th>
                                        <th>To %</th>
                                        <th>Points</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php echo getGradingSystem(); ?>
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-secondary">
                            <i class="fas fa-edit"></i> Configure Grading
                        </button>
                    </div>
                    
                    <!-- Term Configuration -->
                    <div class="academic-card">
                        <h3><i class="fas fa-calendar-week"></i> Terms & Examinations</h3>
                        <div class="term-list">
                            <ul>
                                <li>Term 1: Apr - Sep (Mid-term Exam)</li>
                                <li>Term 2: Oct - Mar (Final Exam)</li>
                                <li>Pre-board Exams: Jan - Feb</li>
                                <li>Annual Exams: March</li>
                            </ul>
                        </div>
                        <button class="btn btn-secondary">
                            <i class="fas fa-edit"></i> Manage Terms
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tab 5: System Settings -->
            <div id="system" class="tab-pane">
                <div class="section-header">
                    <h2><i class="fas fa-cogs"></i> System Settings</h2>
                </div>
                
                <div class="settings-grid">
                    <!-- System Preferences -->
                    <div class="settings-card">
                        <h3><i class="fas fa-sliders-h"></i> System Preferences</h3>
                        <form class="settings-form">
                            <div class="setting-item">
                                <label>
                                    <input type="checkbox" name="auto_backup" checked>
                                    <span>Daily Auto Backup</span>
                                </label>
                            </div>
                            <div class="setting-item">
                                <label>
                                    <input type="checkbox" name="email_notifications" checked>
                                    <span>Email Notifications</span>
                                </label>
                            </div>
                            <div class="setting-item">
                                <label>
                                    <input type="checkbox" name="sms_alerts">
                                    <span>SMS Alerts</span>
                                </label>
                            </div>
                            <div class="setting-item">
                                <label>
                                    <input type="checkbox" name="maintenance_mode">
                                    <span>Maintenance Mode</span>
                                </label>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Security Settings -->
                    <div class="settings-card">
                        <h3><i class="fas fa-shield-alt"></i> Security Settings</h3>
                        <form class="settings-form">
                            <div class="setting-item">
                                <label>Session Timeout (minutes)</label>
                                <input type="number" value="30" min="5" max="120">
                            </div>
                            <div class="setting-item">
                                <label>Password Expiry (days)</label>
                                <input type="number" value="90" min="30" max="365">
                            </div>
                            <div class="setting-item">
                                <label>Failed Login Attempts</label>
                                <input type="number" value="5" min="3" max="10">
                            </div>
                        </form>
                    </div>
                    
                    <!-- Backup & Restore -->
                    <div class="settings-card">
                        <h3><i class="fas fa-database"></i> Backup & Restore</h3>
                        <div class="backup-actions">
                            <button class="btn btn-primary">
                                <i class="fas fa-download"></i> Create Backup
                            </button>
                            <button class="btn btn-secondary">
                                <i class="fas fa-upload"></i> Restore Backup
                            </button>
                            <button class="btn btn-secondary">
                                <i class="fas fa-history"></i> View Backup History
                            </button>
                        </div>
                        <div class="backup-info">
                            <p><i class="fas fa-info-circle"></i> Last Backup: 2 hours ago</p>
                            <p><i class="fas fa-hdd"></i> Storage Used: 2.4 GB</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="footer">
            <p><i class="fas fa-copyright"></i> <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_info['school_name'] ?? 'School Name'); ?> - All rights reserved</p>
            <p class="version">School Management System v2.1</p>
        </footer>
    </div>

    <!-- JavaScript -->
    <script>
        // Tab Navigation
        function openTab(evt, tabName) {
            const tabContent = document.getElementsByClassName("tab-pane");
            const tabButtons = document.getElementsByClassName("tab-btn");
            
            // Hide all tab content
            for (let i = 0; i < tabContent.length; i++) {
                tabContent[i].style.display = "none";
                tabButtons[i].classList.remove("active");
            }
            
            // Show current tab
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.classList.add("active");
        }
        
        // Initialize first tab
        document.getElementById("school-info").style.display = "block";
        
        // Department Management
        function editDepartment(deptId) {
            // Implement edit department functionality
            console.log("Edit department:", deptId);
        }
        
        function deleteDepartment(deptId) {
            if (confirm("Are you sure you want to delete this department?")) {
                // Implement delete functionality
                console.log("Delete department:", deptId);
            }
        }
        
        // Modal Functions
        function openModal(modalId) {
            // Implement modal opening
            console.log("Open modal:", modalId);
        }
        
        // Auto-hide alert
        setTimeout(() => {
            const alert = document.querySelector('.alert');
            if (alert) {
                alert.style.display = 'none';
            }
        }, 5000);
    </script>
</body>
</html>

<?php
// Helper functions
function getTotalStudents() {
    global $pdo;
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM student_table WHERE status = 'active'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'] ?? 0;
}

function getTotalStaff() {
    global $pdo;
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM staff WHERE is_active = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'] ?? 0;
}

function getDeptStaffCount($deptId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM staff WHERE department_id = ? AND is_active = 1");
    $stmt->execute([$deptId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'] ?? 0;
}

function getGradingSystem() {
    // Return grading system HTML
    return '
    <tr><td>A+</td><td>90</td><td>100</td><td>10</td></tr>
    <tr><td>A</td><td>80</td><td>89</td><td>9</td></tr>
    <tr><td>B+</td><td>70</td><td>79</td><td>8</td></tr>
    <tr><td>B</td><td>60</td><td>69</td><td>7</td></tr>
    <tr><td>C</td><td>50</td><td>59</td><td>6</td></tr>
    ';
}
?>