<!-- Clerk Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">&times;</button>
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
                        <a href="payments.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'payments.php' ? 'active' : ''; ?>">
                            <i class="fas fa-money-bill-wave nav-icon"></i>
                            <span class="nav-text">Payments</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="fee_structure.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'fee_structure.php' ? 'active' : ''; ?>">
                            <i class="fas fa-receipt nav-icon"></i>
                            <span class="nav-text">Fee Structure</span>
                        </a>
                    </li>
                    <!-- <li class="nav-item">
                        <a href="school_diary.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'school_diary.php' ? 'active' : ''; ?>">
                            <i class="fas fa-book nav-icon"></i>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="schoolfeed.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'schoolfeed.php' ? 'active' : ''; ?>">
                            <i class="fas fa-newspaper nav-icon"></i>
                            <span class="nav-text">School Feeds</span>
                        </a>
                    </li> -->
                </ul>
            </nav>
        </aside>

