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
                            <i class="fas fa-tachometer-alt nav-icon"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="schoolfeed.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'schoolfeed.php' ? 'active' : ''; ?>">
                            <i class="fas fa-newspaper nav-icon"></i>
                            <span class="nav-text">School Feeds</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'school_diary.php' ? 'active' : ''; ?>">
                            <i class="fas fa-book nav-icon"></i>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'students.php' ? 'active' : ''; ?>">
                            <i class="fas fa-users nav-icon"></i>
                            <span class="nav-text">Students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="results.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'results.php' ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line nav-icon"></i>
                            <span class="nav-text">Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'subjects.php' ? 'active' : ''; ?>">
                            <i class="fas fa-book-open nav-icon"></i>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="questions.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'questions.php' ? 'active' : ''; ?>">
                            <i class="fas fa-question-circle nav-icon"></i>
                            <span class="nav-text">Questions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plan.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'lesson-plan.php' ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-list nav-icon"></i>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="curricullum.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'curricullum.php' ? 'active' : ''; ?>">
                            <i class="fas fa-graduation-cap nav-icon"></i>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="content_coverage.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'content_coverage.php' ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-check nav-icon"></i>
                            <span class="nav-text">Content Coverage</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="teacher_class_activities.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'teacher_class_activities.php' ? 'active' : ''; ?>">
                            <i class="fas fa-tasks nav-icon"></i>
                            <span class="nav-text">Class Activities</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="student-evaluation.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'student-evaluation.php' ? 'active' : ''; ?>">
                            <i class="fas fa-star nav-icon"></i>
                            <span class="nav-text">Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="class_attendance.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'class_attendance.php' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check nav-icon"></i>
                            <span class="nav-text">Attendance</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="timebook.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'timebook.php' ? 'active' : ''; ?>">
                            <i class="fas fa-clock nav-icon"></i>
                            <span class="nav-text">Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'permissions.php' ? 'active' : ''; ?>">
                            <i class="fas fa-key nav-icon"></i>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>
