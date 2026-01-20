<?php
// Unified Mobile Navigation Component
// This component provides a standardized mobile navigation menu for all user roles

// Detect user role and set appropriate navigation
$user_role = $_SESSION['role'] ?? '';
$current_page = basename($_SERVER['PHP_SELF']);

// Determine navigation items based on role
$nav_items = [];

if ($user_role === 'principal') {
    // Admin/Principal Navigation
    $nav_items = [
        ['url' => 'index.php', 'icon' => 'fas fa-chart-line', 'text' => 'Dashboard'],
        ['url' => 'schoolnews.php', 'icon' => 'fas fa-newspaper', 'text' => 'School News'],
        ['url' => 'school_diary.php', 'icon' => 'fas fa-book', 'text' => 'School Diary'],
        ['url' => 'students.php', 'icon' => 'fas fa-users', 'text' => 'Students Registration'],
        ['url' => 'students-evaluations.php', 'icon' => 'fas fa-star', 'text' => 'Students Evaluations'],
        ['url' => 'manage_class.php', 'icon' => 'fas fa-graduation-cap', 'text' => 'Manage Classes'],
        ['url' => 'manage_results.php', 'icon' => 'fas fa-chart-bar', 'text' => 'Manage Results'],
        ['url' => 'lesson-plans.php', 'icon' => 'fas fa-edit', 'text' => 'Lesson Plans'],
        ['url' => 'manage_curriculum.php', 'icon' => 'fas fa-book-open', 'text' => 'Curriculum'],
        ['url' => 'content_coverage.php', 'icon' => 'fas fa-check', 'text' => 'Content Coverage'],
        ['url' => 'manage-school.php', 'icon' => 'fas fa-cog', 'text' => 'Manage School'],
        ['url' => 'subjects.php', 'icon' => 'fas fa-list', 'text' => 'Subjects'],
        ['url' => 'manage_user.php', 'icon' => 'fas fa-users-cog', 'text' => 'Manage Users'],
        ['url' => 'visitors.php', 'icon' => 'fas fa-user-friends', 'text' => 'Visitors'],
        ['url' => 'manage_timebook.php', 'icon' => 'fas fa-clock', 'text' => 'Teachers Time Book'],
        ['url' => 'permissions.php', 'icon' => 'fas fa-key', 'text' => 'Permissions'],
        ['url' => 'manage_attendance.php', 'icon' => 'fas fa-calendar-check', 'text' => 'Attendance Register'],
        ['url' => 'payments_dashboard.php', 'icon' => 'fas fa-money-bill-wave', 'text' => 'School Fees'],
        ['url' => 'sessions.php', 'icon' => 'fas fa-calendar-alt', 'text' => 'School Sessions'],
        ['url' => 'school_calendar.php', 'icon' => 'fas fa-calendar', 'text' => 'School Calendar'],
        ['url' => 'applicants.php', 'icon' => 'fas fa-file-alt', 'text' => 'Applicants']
    ];
} elseif ($user_role === 'teacher') {
    // Teacher Navigation
    $nav_items = [
        ['url' => 'index.php', 'icon' => 'fas fa-tachometer-alt', 'text' => 'Dashboard'],
        ['url' => 'schoolfeed.php', 'icon' => 'fas fa-newspaper', 'text' => 'School Feeds'],
        ['url' => 'school_diary.php', 'icon' => 'fas fa-book', 'text' => 'School Diary'],
        ['url' => 'students.php', 'icon' => 'fas fa-users', 'text' => 'Students'],
        ['url' => 'results.php', 'icon' => 'fas fa-chart-line', 'text' => 'Results'],
        ['url' => 'subjects.php', 'icon' => 'fas fa-book-open', 'text' => 'Subjects'],
        ['url' => 'questions.php', 'icon' => 'fas fa-question-circle', 'text' => 'Questions'],
        ['url' => 'lesson-plan.php', 'icon' => 'fas fa-clipboard-list', 'text' => 'Lesson Plans'],
        ['url' => 'curricullum.php', 'icon' => 'fas fa-graduation-cap', 'text' => 'Curriculum'],
        ['url' => 'teacher_class_activities.php', 'icon' => 'fas fa-tasks', 'text' => 'Class Activities'],
        ['url' => 'student-evaluation.php', 'icon' => 'fas fa-star', 'text' => 'Evaluations'],
        ['url' => 'class_attendance.php', 'icon' => 'fas fa-calendar-check', 'text' => 'Attendance'],
        ['url' => 'timebook.php', 'icon' => 'fas fa-clock', 'text' => 'Time Book'],
        ['url' => 'permissions.php', 'icon' => 'fas fa-key', 'text' => 'Permissions'],
        ['url' => 'payments.php', 'icon' => 'fas fa-money-bill-wave', 'text' => 'Payments']
    ];
} elseif ($user_role === 'student') {
    // Student Navigation
    $nav_items = [
        ['url' => 'dashboard.php', 'icon' => 'fas fa-tachometer-alt', 'text' => 'Dashboard'],
        ['url' => 'myresults.php', 'icon' => 'fas fa-chart-line', 'text' => 'My Results'],
        ['url' => 'student_class_activities.php', 'icon' => 'fas fa-tasks', 'text' => 'Class Activities'],
        ['url' => 'mysubjects.php', 'icon' => 'fas fa-book-open', 'text' => 'My Subjects'],
        ['url' => 'my-evaluations.php', 'icon' => 'fas fa-star', 'text' => 'My Evaluations'],
        ['url' => 'attendance.php', 'icon' => 'fas fa-calendar-check', 'text' => 'Attendance'],
        ['url' => 'payment.php', 'icon' => 'fas fa-money-bill-wave', 'text' => 'School Fees'],
        ['url' => 'schoolfeed.php', 'icon' => 'fas fa-newspaper', 'text' => 'School Feeds'],
        ['url' => 'school_diary.php', 'icon' => 'fas fa-book', 'text' => 'School Diary'],
        ['url' => 'photo_album.php', 'icon' => 'fas fa-images', 'text' => 'Photo Album']
    ];
}
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
            <?php foreach ($nav_items as $item): ?>
                <li class="nav-item">
                    <a href="<?php echo htmlspecialchars($item['url']); ?>" class="mobile-nav-link <?php echo $current_page === $item['url'] ? 'active' : ''; ?>">
                        <i class="<?php echo htmlspecialchars($item['icon']); ?> nav-icon"></i>
                        <span class="nav-text"><?php echo htmlspecialchars($item['text']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
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
