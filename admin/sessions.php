<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in and is principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: login.php');
    exit();
}

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_session'])) {
        $session_name = $_POST['session_name'];
        $academic_year = $_POST['academic_year'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $status = $_POST['status'];
        $is_current = isset($_POST['is_current']) ? 1 : 0;

        if ($is_current) {
            $pdo->query("UPDATE sessions SET is_current = 0 WHERE is_current = 1");
        }

        $stmt = $pdo->prepare("INSERT INTO sessions (session_name, academic_year, start_date, end_date, is_current, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$session_name, $academic_year, $start_date, $end_date, $is_current, $status, $_SESSION['user_id']])) {
            $message = "Session added successfully!";
            $message_type = "success";
        } else {
            $message = "Error adding session!";
            $message_type = "danger";
        }
    }

    if (isset($_POST['edit_session'])) {
        $id = $_POST['session_id'];
        $session_name = $_POST['session_name'];
        $academic_year = $_POST['academic_year'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $status = $_POST['status'];
        $is_current = isset($_POST['is_current']) ? 1 : 0;

        if ($is_current) {
            $stmt = $pdo->prepare("UPDATE sessions SET is_current = 0 WHERE is_current = 1 AND id != ?");
            $stmt->execute([$id]);
        }

        $stmt = $pdo->prepare("UPDATE sessions SET session_name = ?, academic_year = ?, start_date = ?, end_date = ?, is_current = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$session_name, $academic_year, $start_date, $end_date, $is_current, $status, $id])) {
            $message = "Session updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating session!";
            $message_type = "danger";
        }
    }

    if (isset($_POST['delete_session'])) {
        $id = $_POST['session_id'];
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = "Session deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting session!";
            $message_type = "danger";
        }
    }

    if (isset($_POST['set_current'])) {
        $id = $_POST['session_id'];
        $pdo->query("UPDATE sessions SET is_current = 0");
        $stmt = $pdo->prepare("UPDATE sessions SET is_current = 1, status = 'active' WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = "Session set as current successfully!";
            $message_type = "success";
        } else {
            $message = "Error setting session as current!";
            $message_type = "danger";
        }
    }
}

// Get all sessions
$sessions = $pdo->query("SELECT s.*, u.full_name as created_by_name FROM sessions s LEFT JOIN users u ON s.created_by = u.id ORDER BY academic_year DESC, start_date DESC")->fetchAll();

$current_session = $pdo->query("SELECT * FROM sessions WHERE is_current = 1 LIMIT 1")->fetch();

// Get principal name for header
$principal_name = $_SESSION['full_name'] ?? 'Principal';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Management | School System</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
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
        <?php include '../includes/admin_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">

    <!-- Main Content -->
    <div class="container-fluid main-container">
        <!-- Page Header -->
        <div class="content-header">
            <div class="welcome-section">
                <h2>Academic Session Management</h2>
                <p>Manage all academic sessions, set current sessions, and track academic periods</p>
            </div>
            <div class="header-stats">
                <div class="quick-stat">
                    <span class="quick-stat-value"><?php echo count($sessions); ?></span>
                    <span class="quick-stat-label">Sessions</span>
                </div>
            </div>
        </div>

        <!-- Message Alert -->
        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show d-flex align-items-center" role="alert">
            <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <div><?= $message ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Current Session Banner -->
        <?php if ($current_session): ?>
        <div class="current-session-banner">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <div class="current-badge"><i class="fas fa-star me-1"></i> CURRENT ACTIVE SESSION</div>
                    <h3 class="mb-2"><?= htmlspecialchars($current_session['session_name']) ?></h3>
                    <p class="mb-3">Academic Year: <?= htmlspecialchars($current_session['academic_year']) ?></p>
                    <div class="session-dates">
                        <i class="far fa-calendar-alt me-2"></i>
                        <?= date('M d, Y', strtotime($current_session['start_date'])) ?> - <?= date('M d, Y', strtotime($current_session['end_date'])) ?>
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                    <span class="badge-current"><i class="fas fa-check me-1"></i> ACTIVE</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="dashboard-cards">
            <div class="card card-gradient-1">
                <div class="card-icon-wrapper">
                    <div class="card-icon">📅</div>
                </div>
                <div class="card-content">
                    <h3>Total Sessions</h3>
                    <p class="card-value"><?= count($sessions) ?></p>
                    <div class="card-footer">
                        <span class="card-badge">All Time</span>
                        <a href="#sessionsTable" class="card-link">View All →</a>
                    </div>
                </div>
            </div>

            <div class="card card-gradient-2">
                <div class="card-icon-wrapper">
                    <div class="card-icon">▶️</div>
                </div>
                <div class="card-content">
                    <h3>Active Sessions</h3>
                    <p class="card-value"><?= array_reduce($sessions, function($carry, $item) {
                        return $carry + ($item['status'] == 'active' ? 1 : 0);
                    }, 0) ?></p>
                    <div class="card-footer">
                        <span class="card-badge">Currently Running</span>
                        <a href="#sessionsTable" class="card-link">Manage →</a>
                    </div>
                </div>
            </div>

            <div class="card card-gradient-3">
                <div class="card-icon-wrapper">
                    <div class="card-icon">⏰</div>
                </div>
                <div class="card-content">
                    <h3>Upcoming Sessions</h3>
                    <p class="card-value"><?= array_reduce($sessions, function($carry, $item) {
                        return $carry + ($item['status'] == 'upcoming' ? 1 : 0);
                    }, 0) ?></p>
                    <div class="card-footer">
                        <span class="card-badge">Scheduled</span>
                        <a href="#sessionsTable" class="card-link">Prepare →</a>
                    </div>
                </div>
            </div>

            <div class="card card-gradient-4">
                <div class="card-icon-wrapper">
                    <div class="card-icon">✅</div>
                </div>
                <div class="card-content">
                    <h3>Completed Sessions</h3>
                    <p class="card-value"><?= array_reduce($sessions, function($carry, $item) {
                        return $carry + ($item['status'] == 'completed' ? 1 : 0);
                    }, 0) ?></p>
                    <div class="card-footer">
                        <span class="card-badge">Finished</span>
                        <a href="#sessionsTable" class="card-link">Review →</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Floating Action Button for Mobile -->
        <button class="btn btn-danger btn-small" data-bs-toggle="modal" data-bs-target="#addSessionModal">
            <i class="fas fa-plus"></i>Create New Sessions
        </button>
            <br>
            <br>
        <!-- Sessions Table Card -->
        <div class="table-card">
            <!-- <div class="table-header d-flex justify-content-between align-items-center">
                <h5><i class="fas fa-list me-2"></i>All Academic Sessions</h5>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" class="form-control" id="sessionSearch" placeholder="Search sessions...">
                </div>
            </div> -->
            <div class="table-responsive">
                <?php if (empty($sessions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h4>No Sessions Found</h4>
                        <p>Get started by creating your first academic session</p>
                        <button type="button" class="btn btn-primary px-4" data-bs-toggle="modal" data-bs-target="#addSessionModal">
                            <i class="fas fa-plus me-2"></i> Create Session
                        </button>
                    </div>
                <?php else: ?>
                    <table class="students-table" id="sessionsTable">
                        <thead>
                            <tr>
                                <th>Session Name</th>
                                <th>Academic Year</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Current</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $session):
                                $isCurrent = $session['is_current'];
                            ?>
                            <tr class="<?= $isCurrent ? 'current-row' : '' ?>" data-search="<?= strtolower(htmlspecialchars($session['session_name'] . ' ' . $session['academic_year'])) ?>">
                                <td data-label="Session Name">
                                    <strong><?= htmlspecialchars($session['session_name']) ?></strong>
                                </td>
                                <td data-label="Academic Year"><?= htmlspecialchars($session['academic_year']) ?></td>
                                <td data-label="Duration">
                                    <div class="session-dates small">
                                        <i class="far fa-calendar me-1"></i>
                                        <?= date('M d, Y', strtotime($session['start_date'])) ?> -
                                        <?= date('M d, Y', strtotime($session['end_date'])) ?>
                                    </div>
                                </td>
                                <td data-label="Status">
                                    <?php
                                    $status_class = '';
                                    switch ($session['status']) {
                                        case 'active': $status_class = 'badge-approved'; break;
                                        case 'upcoming': $status_class = 'badge-pending'; break;
                                        case 'completed': $status_class = 'badge-success'; break;
                                        case 'cancelled': $status_class = 'badge-danger'; break;
                                    }
                                    ?>
                                    <span class="badge <?= $status_class ?>">
                                        <?= ucfirst($session['status']) ?>
                                    </span>
                                </td>
                                <td data-label="Current">
                                    <?php if ($isCurrent): ?>
                                        <span class="badge badge-success"><i class="fas fa-check me-1"></i> Current</span>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
                                            <button type="submit" name="set_current" class="btn btn-primary btn-small">
                                                <i class="fas fa-exchange-alt me-1"></i> Set Current
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Created By">
                                    <div class="d-flex align-items-center">
                                        <div class="me-2" style="width: 30px; height: 30px; background: rgba(67, 97, 238, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-user small"></i>
                                        </div>
                                        <?= htmlspecialchars($session['created_by_name'] ?? 'N/A') ?>
                                    </div>
                                </td>
                                <td data-label="Actions">
                                    <div class="action-buttons">
                                        <button class="btn btn-primary btn-small" data-bs-toggle="modal" data-bs-target="#editSessionModal"
                                                data-id="<?= $session['id'] ?>"
                                                data-name="<?= htmlspecialchars($session['session_name']) ?>"
                                                data-year="<?= htmlspecialchars($session['academic_year']) ?>"
                                                data-start="<?= $session['start_date'] ?>"
                                                data-end="<?= $session['end_date'] ?>"
                                                data-status="<?= $session['status'] ?>"
                                                data-current="<?= $session['is_current'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-small" data-bs-toggle="modal" data-bs-target="#deleteSessionModal"
                                                data-id="<?= $session['id'] ?>"
                                                data-name="<?= htmlspecialchars($session['session_name']) ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        
    </div>

        </main>
    </div>

    

    <!-- Modals (Same as before but with new styling) -->
    <!-- Add Session Modal -->
    <div class="modal fade" id="addSessionModal" tabindex="-1" aria-labelledby="addSessionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addSessionModalLabel"><i class="fas fa-plus-circle me-2"></i>Create New Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="session_name" class="form-label">Session Name *</label>
                            <input type="text" class="form-control" id="session_name" name="session_name" required
                                   placeholder="e.g., First Term 2025/2026">
                        </div>
                        <div class="mb-3">
                            <label for="academic_year" class="form-label">Academic Year *</label>
                            <input type="text" class="form-control" id="academic_year" name="academic_year" required
                                   placeholder="e.g., 2025/2026">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="start_date" class="form-label">Start Date *</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label for="end_date" class="form-label">End Date *</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="status" class="form-label">Status *</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="upcoming">Upcoming</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_current" name="is_current">
                            <label class="form-check-label" for="is_current">
                                Set as Current Session
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_session" class="btn btn-primary px-4">
                            <i class="fas fa-save me-2"></i> Create Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Session Modal -->
    <div class="modal fade" id="editSessionModal" tabindex="-1" aria-labelledby="editSessionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editSessionModalLabel"><i class="fas fa-edit me-2"></i>Edit Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="session_id" id="edit_session_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_session_name" class="form-label">Session Name *</label>
                            <input type="text" class="form-control" id="edit_session_name" name="session_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_academic_year" class="form-label">Academic Year *</label>
                            <input type="text" class="form-control" id="edit_academic_year" name="academic_year" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="edit_start_date" class="form-label">Start Date *</label>
                                <input type="date" class="form-control" id="edit_start_date" name="start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_end_date" class="form-label">End Date *</label>
                                <input type="date" class="form-control" id="edit_end_date" name="end_date" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status *</label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <option value="upcoming">Upcoming</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="edit_is_current" name="is_current">
                            <label class="form-check-label" for="edit_is_current">
                                Set as Current Session
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_session" class="btn btn-primary px-4">
                            <i class="fas fa-save me-2"></i> Update Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Session Modal -->
    <div class="modal fade" id="deleteSessionModal" tabindex="-1" aria-labelledby="deleteSessionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteSessionModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Delete Session</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="session_id" id="delete_session_id">
                    <div class="modal-body">
                        <div class="text-center mb-4">
                            <div class="mb-3" style="font-size: 4rem; color: #dc3545;">
                                <i class="fas fa-trash-alt"></i>
                            </div>
                            <h5>Are you sure?</h5>
                            <p class="mb-0">You are about to delete the session:</p>
                            <h6 class="mt-2 text-danger" id="delete_session_name"></h6>
                            <p class="text-muted mt-3"><i class="fas fa-info-circle me-1"></i> This action cannot be undone.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_session" class="btn btn-danger px-4">
                            <i class="fas fa-trash me-2"></i> Delete Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap & jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <script>
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

    <script>
    $(document).ready(function() {
        // Edit Modal Handler
        $('#editSessionModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var modal = $(this);

            modal.find('#edit_session_id').val(button.data('id'));
            modal.find('#edit_session_name').val(button.data('name'));
            modal.find('#edit_academic_year').val(button.data('year'));
            modal.find('#edit_start_date').val(button.data('start'));
            modal.find('#edit_end_date').val(button.data('end'));
            modal.find('#edit_status').val(button.data('status'));

            if (button.data('current') == 1) {
                modal.find('#edit_is_current').prop('checked', true);
            } else {
                modal.find('#edit_is_current').prop('checked', false);
            }
        });

        // Delete Modal Handler
        $('#deleteSessionModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var modal = $(this);

            modal.find('#delete_session_id').val(button.data('id'));
            modal.find('#delete_session_name').text(button.data('name'));
        });

        // Search functionality
        $('#sessionSearch').on('keyup', function() {
            var value = $(this).val().toLowerCase();
            $('#sessionsTable tbody tr').filter(function() {
                $(this).toggle($(this).data('search').indexOf(value) > -1);
            });
        });

        // Auto-fill today's date for new session
        var today = new Date().toISOString().split('T')[0];
        $('#start_date').val(today);

        // Set end date to 90 days from start
        $('#start_date').change(function() {
            var startDate = new Date($(this).val());
            var endDate = new Date(startDate);
            endDate.setDate(endDate.getDate() + 90);
            $('#end_date').val(endDate.toISOString().split('T')[0]);
        });

        // Auto-format academic year
        $('#academic_year, #edit_academic_year').on('blur', function() {
            var value = $(this).val();
            if (value.match(/^\d{4}$/)) {
                var nextYear = parseInt(value) + 1;
                $(this).val(value + '/' + nextYear);
            }
        });

        // Date validation
        $('form').on('submit', function() {
            var start = new Date($('#start_date').val());
            var end = new Date($('#end_date').val());

            if (end <= start) {
                alert('End date must be after start date!');
                return false;
            }
            return true;
        });

        // Add data-label attributes for mobile table
        if ($(window).width() < 768) {
            $('#sessionsTable thead th').each(function(i) {
                var label = $(this).text();
                $('#sessionsTable tbody td:nth-child(' + (i + 1) + ')').attr('data-label', label);
            });
        }

        // Re-apply data-labels on window resize
        $(window).resize(function() {
            if ($(window).width() < 768) {
                $('#sessionsTable thead th').each(function(i) {
                    var label = $(this).text();
                    $('#sessionsTable tbody td:nth-child(' + (i + 1) + ')').attr('data-label', label);
                });
            }
        });
    });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
