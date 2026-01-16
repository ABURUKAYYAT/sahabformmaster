<?php
// Mobile Navigation Component
// This component provides a standardized mobile navigation menu for all admin pages
?>
<!-- Mobile Menu Toggle -->
<button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<!-- Mobile Navigation Dropdown -->
<div class="mobile-nav-dropdown" id="mobileNavDropdown">
    <div class="mobile-nav-header">
        <h3>Navigation</h3>
        <button class="mobile-nav-close" id="mobileNavClose" aria-label="Close Menu">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <nav class="mobile-nav-menu">
        <ul class="nav-list">
            <li class="nav-item">
                <a href="index.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line nav-icon"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="schoolnews.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'schoolnews.php' ? 'active' : ''; ?>">
                    <i class="fas fa-newspaper nav-icon"></i>
                    <span class="nav-text">School News</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="school_diary.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'school_diary.php' ? 'active' : ''; ?>">
                    <i class="fas fa-book nav-icon"></i>
                    <span class="nav-text">School Diary</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="students.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'students.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users nav-icon"></i>
                    <span class="nav-text">Students Registration</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="students-evaluations.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'students-evaluations.php' ? 'active' : ''; ?>">
                    <i class="fas fa-star nav-icon"></i>
                    <span class="nav-text">Students Evaluations</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="manage_class.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_class.php' ? 'active' : ''; ?>">
                    <i class="fas fa-graduation-cap nav-icon"></i>
                    <span class="nav-text">Manage Classes</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="manage_results.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_results.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar nav-icon"></i>
                    <span class="nav-text">Manage Results</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="lesson-plans.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'lesson-plans.php' ? 'active' : ''; ?>">
                    <i class="fas fa-edit nav-icon"></i>
                    <span class="nav-text">Lesson Plans</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="manage_curriculum.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_curriculum.php' ? 'active' : ''; ?>">
                    <i class="fas fa-book-open nav-icon"></i>
                    <span class="nav-text">Curriculum</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="manage-school.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage-school.php' ? 'active' : ''; ?>">
                    <i class="fas fa-cog nav-icon"></i>
                    <span class="nav-text">Manage School</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="subjects.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'subjects.php' ? 'active' : ''; ?>">
                    <i class="fas fa-list nav-icon"></i>
                    <span class="nav-text">Subjects</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="manage_user.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_user.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog nav-icon"></i>
                    <span class="nav-text">Manage Users</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="visitors.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'visitors.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-friends nav-icon"></i>
                    <span class="nav-text">Visitors</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="manage_timebook.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_timebook.php' ? 'active' : ''; ?>">
                    <i class="fas fa-clock nav-icon"></i>
                    <span class="nav-text">Teachers Time Book</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="permissions.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'permissions.php' ? 'active' : ''; ?>">
                    <i class="fas fa-key nav-icon"></i>
                    <span class="nav-text">Permissions</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="manage_attendance.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_attendance.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check nav-icon"></i>
                    <span class="nav-text">Attendance Register</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="payments_dashboard.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'payments_dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave nav-icon"></i>
                    <span class="nav-text">School Fees</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="sessions.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'sessions.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt nav-icon"></i>
                    <span class="nav-text">School Sessions</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="school_calendar.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'school_calendar.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar nav-icon"></i>
                    <span class="nav-text">School Calendar</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="applicants.php" class="mobile-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'applicants.php' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt nav-icon"></i>
                    <span class="nav-text">Applicants</span>
                </a>
            </li>
        </ul>
    </nav>
</div>

<!-- Mobile Navigation JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile Navigation Elements
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mobileNavDropdown = document.getElementById('mobileNavDropdown');
    const mobileNavClose = document.getElementById('mobileNavClose');
    const sidebar = document.getElementById('sidebar');

    // Toggle mobile navigation
    mobileMenuToggle.addEventListener('click', function() {
        mobileNavDropdown.classList.toggle('active');
        mobileMenuToggle.classList.toggle('active');
        document.body.classList.toggle('mobile-nav-open');

        // Also toggle sidebar for mobile devices
        if (sidebar) {
            sidebar.classList.toggle('active');
        }
    });

    // Close mobile navigation
    mobileNavClose.addEventListener('click', function() {
        mobileNavDropdown.classList.remove('active');
        mobileMenuToggle.classList.remove('active');
        document.body.classList.remove('mobile-nav-open');

        // Also close sidebar
        if (sidebar) {
            sidebar.classList.remove('active');
        }
    });

    // Close when clicking outside
    document.addEventListener('click', function(e) {
        if (!mobileNavDropdown.contains(e.target) && !mobileMenuToggle.contains(e.target) && (!sidebar || !sidebar.contains(e.target))) {
            mobileNavDropdown.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
            document.body.classList.remove('mobile-nav-open');

            // Also close sidebar
            if (sidebar) {
                sidebar.classList.remove('active');
            }
        }
    });

    // Close when pressing escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && (mobileNavDropdown.classList.contains('active') || (sidebar && sidebar.classList.contains('active')))) {
            mobileNavDropdown.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
            document.body.classList.remove('mobile-nav-open');

            // Also close sidebar
            if (sidebar) {
                sidebar.classList.remove('active');
            }
        }
    });

    // Smooth scrolling for mobile links
    document.querySelectorAll('.mobile-nav-link[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
                // Close mobile menu after clicking a link
                mobileNavDropdown.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
                document.body.classList.remove('mobile-nav-open');

                // Also close sidebar
                if (sidebar) {
                    sidebar.classList.remove('active');
                }
            }
        });
    });

    // Add click handlers for sidebar close button if it exists
    const sidebarClose = document.getElementById('sidebarClose');
    if (sidebarClose) {
        sidebarClose.addEventListener('click', function() {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
            document.body.classList.remove('mobile-nav-open');
        });
    }
});
</script>
