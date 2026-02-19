<!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'schoolnews.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📰</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'school_diary.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📔</span>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'students.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students Registration</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="evaluations-crud.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'evaluations-crud.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Evaluations CRUD</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students-evaluations.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'students-evaluations.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Legacy Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_class.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_class.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">🎓</span>
                            <span class="nav-text">Manage Classes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_results.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_results.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'lesson-plans.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_curriculum.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="content_coverage.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'content_coverage.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">✅</span>
                            <span class="nav-text">Content Coverage</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage-school.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage-school.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'subjects.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📖</span>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_user.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">👤</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'visitors.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">🚶</span>
                            <span class="nav-text">Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_timebook.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_timebook.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">⏰</span>
                            <span class="nav-text">Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'permissions.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_attendance.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_attendance.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📋</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
<li class="nav-item">
                        <a href="sessions.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'sessions.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📅</span>
                            <span class="nav-text">School Sessions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_calendar.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'school_calendar.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">🗓️</span>
                            <span class="nav-text">School Calendar</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="applicants.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'applicants.php' ? 'active' : ''; ?>">
                            <span class="nav-icon">📄</span>
                            <span class="nav-text">Applicants</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

