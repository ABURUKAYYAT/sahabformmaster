<!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt nav-icon"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="myresults.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'myresults.php' ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line nav-icon"></i>
                            <span class="nav-text">My Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="student_class_activities.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'student_class_activities.php' ? 'active' : ''; ?>">
                            <i class="fas fa-tasks nav-icon"></i>
                            <span class="nav-text">Class Activities</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="mysubjects.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'mysubjects.php' ? 'active' : ''; ?>">
                            <i class="fas fa-book-open nav-icon"></i>
                            <span class="nav-text">My Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="my-evaluations.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'my-evaluations.php' ? 'active' : ''; ?>">
                            <i class="fas fa-star nav-icon"></i>
                            <span class="nav-text">My Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="attendance.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'attendance.php' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check nav-icon"></i>
                            <span class="nav-text">Attendance</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payment.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'payment.php' ? 'active' : ''; ?>">
                            <i class="fas fa-money-bill-wave nav-icon"></i>
                            <span class="nav-text">School Fees</span>
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
                        <a href="photo_album.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'photo_album.php' ? 'active' : ''; ?>">
                            <i class="fas fa-images nav-icon"></i>
                            <span class="nav-text">Photo Album</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>
