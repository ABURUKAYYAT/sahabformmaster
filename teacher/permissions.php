<?php
// teacher/permissions.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';


// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}

// School authentication and context
$current_school_id = require_school_auth();

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_type = $_POST['request_type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $duration_hours = $_POST['duration_hours'] ?? '';
    $priority = $_POST['priority'] ?? 'medium';

    // Validate inputs
    if (empty($title) || empty($start_date)) {
        $error = "Title and start date are required!";
    } else {
        try {
            // Handle file upload
            $attachment_path = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/permissions/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_name = time() . '_' . basename($_FILES['attachment']['name']);
                $target_file = $upload_dir . $file_name;

                // Check file type and size
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $max_size = 5 * 1024 * 1024; // 5MB

                if (in_array($_FILES['attachment']['type'], $allowed_types) &&
                    $_FILES['attachment']['size'] <= $max_size) {
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file);
                    $attachment_path = $target_file;
                }
            }

            // Insert permission request
            $stmt = $pdo->prepare("
                INSERT INTO permissions (
                    school_id, staff_id, request_type, title, description,
                    start_date, end_date, duration_hours, priority,
                    attachment_path, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $stmt->execute([
                $current_school_id, $user_id, $request_type, $title, $description,
                $start_date, $end_date ?: null, $duration_hours ?: null,
                $priority, $attachment_path
            ]);

            $message = "Permission request submitted successfully!";

        } catch (PDOException $e) {
            $error = "Error submitting request: " . $e->getMessage();
        }
    }
}


// Handle export (PDF)
if (isset($_GET['export']) && $_GET['export'] === 'permissions_pdf') {
    require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name as approved_by_name
        FROM permissions p
        LEFT JOIN users u ON p.approved_by = u.id
        WHERE p.staff_id = ? AND p.school_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$user_id, $current_school_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get school info
    $schoolStmt = $pdo->prepare("SELECT * FROM school_profile WHERE school_id = ? LIMIT 1");
    $schoolStmt->execute([$current_school_id]);
    $school = $schoolStmt->fetch(PDO::FETCH_ASSOC);
    if (!$school) {
        $schoolStmt = $pdo->query("SELECT * FROM school_profile LIMIT 1");
        $school = $schoolStmt->fetch(PDO::FETCH_ASSOC);
    }

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('SahabFormMaster');
    $pdf->SetAuthor('SahabFormMaster');
    $pdf->SetTitle('Permission Requests');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    $schoolName = $school['school_name'] ?? 'School';
    $schoolAddress = $school['school_address'] ?? '';
    $schoolPhone = $school['school_phone'] ?? '';
    $schoolEmail = $school['school_email'] ?? '';

    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 8, $schoolName, 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    if ($schoolAddress) {
        $pdf->Cell(0, 6, $schoolAddress, 0, 1, 'C');
    }
    if ($schoolPhone || $schoolEmail) {
        $pdf->Cell(0, 6, trim('Phone: ' . $schoolPhone . ' | Email: ' . $schoolEmail), 0, 1, 'C');
    }
    $pdf->Ln(4);

    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 8, 'Permission Requests Report', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Teacher: ' . ($_SESSION['full_name'] ?? 'Teacher'), 0, 1, 'L');
    $pdf->Cell(0, 6, 'Generated: ' . date('M d, Y H:i'), 0, 1, 'L');
    $pdf->Ln(3);

    $table = '<table border="1" cellpadding="4">
';
    $table .= '<tr style="background-color:#f1f5f9;">
';
    $table .= '<th width="10%"><b>ID</b></th>
';
    $table .= '<th width="18%"><b>Type</b></th>
';
    $table .= '<th width="30%"><b>Title</b></th>
';
    $table .= '<th width="14%"><b>Date</b></th>
';
    $table .= '<th width="10%"><b>Priority</b></th>
';
    $table .= '<th width="10%"><b>Status</b></th>
';
    $table .= '<th width="8%"><b>Hours</b></th>
';
    $table .= '</tr>
';

    foreach ($requests as $req) {
        $dateLabel = date('M d, Y', strtotime($req['start_date']));
        if (!empty($req['end_date'])) {
            $dateLabel .= ' - ' . date('M d, Y', strtotime($req['end_date']));
        }
        $hours = $req['duration_hours'] ? $req['duration_hours'] : '-';
        $table .= '<tr>'; 
        $table .= '<td>' . str_pad($req['id'], 5, '0', STR_PAD_LEFT) . '</td>'; 
        $table .= '<td>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $req['request_type']))) . '</td>'; 
        $table .= '<td>' . htmlspecialchars($req['title']) . '</td>'; 
        $table .= '<td>' . htmlspecialchars($dateLabel) . '</td>'; 
        $table .= '<td>' . htmlspecialchars(ucfirst($req['priority'])) . '</td>'; 
        $table .= '<td>' . htmlspecialchars(ucfirst($req['status'])) . '</td>'; 
        $table .= '<td>' . htmlspecialchars($hours) . '</td>'; 
        $table .= '</tr>
';
    }

    $table .= '</table>';
    $pdf->writeHTML($table, true, false, true, false, '');

    $pdf->Output('permission_requests.pdf', 'D');
    exit();
}

// Fetch teacher's permission requests
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name as approved_by_name
        FROM permissions p
        LEFT JOIN users u ON p.approved_by = u.id
        WHERE p.staff_id = ? AND p.school_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$user_id, $current_school_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching requests: " . $e->getMessage();
    $requests = [];
}

// Get statistics
try {
    $totalRequests = count($requests);
    $pendingRequests = count(array_filter($requests, function($r) { return $r['status'] === 'pending'; }));
    $approvedRequests = count(array_filter($requests, function($r) { return $r['status'] === 'approved'; }));
    $rejectedRequests = count(array_filter($requests, function($r) { return $r['status'] === 'rejected'; }));
} catch (Exception $e) {
    $totalRequests = $pendingRequests = $approvedRequests = $rejectedRequests = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Requests | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f5f7fb; }
        .dashboard-container .main-content { width: 100%; }
        .main-container { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
        .page-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .table small { color: #6b7280; }
    </style>
</head>
<body>
    <!-- Mobile Navigation Component -->
    <?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Teacher Portal</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include '../includes/teacher_sidebar.php'; ?>
        <main class="main-content">
            <div class="main-container">
                <div class="content-header">
                    <div class="welcome-section">
                        <h2>Permission Requests</h2>
                        <p>Submit and track your permission requests.</p>
                    </div>
                    <div class="page-actions">
                        <button class="btn primary" onclick="openRequestModal()">
                            <i class="fas fa-plus"></i> New Request
                        </button>
                        <button class="btn secondary" onclick="exportRequests()">
                            <i class="fas fa-download"></i> Export Report
                        </button>
                    </div>
                </div>

                <div class="stats-container">
                    <div class="stat-card">
                        <i class="fas fa-list"></i>
                        <h3>Total Requests</h3>
                        <div class="count"><?php echo $totalRequests; ?></div>
                        <p class="stat-description">All requests</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-clock"></i>
                        <h3>Pending</h3>
                        <div class="count"><?php echo $pendingRequests; ?></div>
                        <p class="stat-description">Awaiting review</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-check-circle"></i>
                        <h3>Approved</h3>
                        <div class="count"><?php echo $approvedRequests; ?></div>
                        <p class="stat-description">Approved requests</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-times-circle"></i>
                        <h3>Rejected</h3>
                        <div class="count"><?php echo $rejectedRequests; ?></div>
                        <p class="stat-description">Rejected requests</p>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <section class="panel">
                    <div class="panel-header">
                        <h2><i class="fas fa-filter"></i> Quick Filters</h2>
                    </div>
                    <div class="page-actions">
                        <button class="btn secondary" onclick="filterByStatus('all')">All</button>
                        <button class="btn secondary" onclick="filterByStatus('pending')">Pending</button>
                        <button class="btn secondary" onclick="filterByStatus('approved')">Approved</button>
                        <button class="btn secondary" onclick="filterByStatus('rejected')">Rejected</button>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <h2><i class="fas fa-table"></i> My Requests</h2>
                    </div>

                    <div class="table-wrap">
                        <table class="table requests-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <th>Date</th>
                                    <th>Duration</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Approved By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($requests)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center">
                                            <i class="fas fa-clipboard-list"></i> No permission requests found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($requests as $request): ?>
                                        <?php
                                            $priorityClass = $request['priority'] === 'high' ? 'badge-warning' : ($request['priority'] === 'low' ? 'badge-success' : 'badge-info');
                                            $statusClass = $request['status'] === 'approved' ? 'badge-success' : ($request['status'] === 'rejected' ? 'badge-info' : 'badge-warning');
                                            $dateLabel = date('M d, Y', strtotime($request['start_date']));
                                            if (!empty($request['end_date'])) {
                                                $dateLabel .= ' - ' . date('M d, Y', strtotime($request['end_date']));
                                            }
                                        ?>
                                        <tr data-id="<?php echo $request['id']; ?>"
                                            data-type="<?php echo htmlspecialchars(str_replace('_', ' ', $request['request_type'])); ?>"
                                            data-title="<?php echo htmlspecialchars($request['title']); ?>"
                                            data-date="<?php echo htmlspecialchars($dateLabel); ?>"
                                            data-duration="<?php echo $request['duration_hours'] ? $request['duration_hours'] . ' hours' : 'Full day'; ?>"
                                            data-priority="<?php echo htmlspecialchars(ucfirst($request['priority'])); ?>"
                                            data-status="<?php echo htmlspecialchars(ucfirst($request['status'])); ?>"
                                            data-approved-by="<?php echo htmlspecialchars($request['approved_by_name'] ?: 'Not approved'); ?>">
                                            <td>#<?php echo str_pad($request['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?></td>
                                            <td><?php echo htmlspecialchars($request['title']); ?></td>
                                            <td><?php echo $dateLabel; ?></td>
                                            <td><?php echo $request['duration_hours'] ? $request['duration_hours'] . ' hours' : 'Full day'; ?></td>
                                            <td><span class="badge <?php echo $priorityClass; ?>"><?php echo ucfirst($request['priority']); ?></span></td>
                                            <td><span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($request['status']); ?></span></td>
                                            <td>
                                                <?php echo $request['approved_by_name'] ?: '?'; ?>
                                                <?php if (!empty($request['approved_at'])): ?>
                                                    <br><small><?php echo date('M d', strtotime($request['approved_at'])); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="actions">
                                                <button class="btn small warning" onclick="viewRequestDetails(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <button class="btn small danger" onclick="cancelRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <!-- New Request Modal -->
    <div class="modal-overlay" id="requestModal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> New Permission Request</h3>
                <button class="modal-close" onclick="closeRequestModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Request Type *</label>
                        <select name="request_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="sick_leave">Sick Leave</option>
                            <option value="personal_leave">Personal Leave</option>
                            <option value="medical_appointment">Medical Appointment</option>
                            <option value="emergency">Emergency</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority *</label>
                        <select name="priority" class="form-control" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="title" class="form-control" placeholder="Brief title for your request" required>
                    </div>
                    <div class="form-group">
                        <label>Description *</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Provide details about your request..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Start Date & Time *</label>
                        <input type="datetime-local" name="start_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>End Date & Time (Optional)</label>
                        <input type="datetime-local" name="end_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Duration (hours, leave empty for full day)</label>
                        <input type="number" name="duration_hours" class="form-control" min="1" placeholder="e.g. 4">
                    </div>
                    <div class="form-group">
                        <label>Attachment (Optional)</label>
                        <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
                        <small id="file-name"></small>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn secondary" onclick="closeRequestModal()">Cancel</button>
                    <button type="submit" class="btn primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal-overlay" id="detailsModal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Request Details</h3>
                <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" id="detailsContent"></div>
            <div class="modal-actions">
                <button type="button" class="btn secondary" onclick="closeDetailsModal()">Close</button>
            </div>
        </div>
    </div>

<script>
        // Modal functions
        function openRequestModal() {
            document.getElementById('requestModal').style.display = 'flex';
        }

        function closeRequestModal() {
            document.getElementById('requestModal').style.display = 'none';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // File upload display
        document.querySelector('input[type="file"]').addEventListener('change', function(e) {
            const fileName = document.getElementById('file-name');
            if (this.files.length > 0) {
                fileName.textContent = 'Selected: ' + this.files[0].name;
                fileName.style.color = 'var(--success-600)';
            } else {
                fileName.textContent = '';
            }
        });

        // View request details
        function viewRequestDetails(requestId) {
            const row = document.querySelector(`.requests-table tbody tr[data-id="${requestId}"]`);
            if (!row) return;

            const requestData = {
                id: requestId,
                type: row.dataset.type || '- ',
                title: row.dataset.title || '- ',
                date: row.dataset.date || '- ',
                duration: row.dataset.duration || '- ',
                priority: row.dataset.priority || '- ',
                status: row.dataset.status || '- ',
                approved_by: row.dataset.approvedBy || 'Not approved yet'
            };

            const contentHtml = `
                <div style="line-height: 1.6;">
                    <h4 style="margin-bottom: 1rem;">${requestData.title}</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                        <div><strong>Type:</strong> ${requestData.type}</div>
                        <div><strong>Date:</strong> ${requestData.date}</div>
                        <div><strong>Duration:</strong> ${requestData.duration}</div>
                        <div><strong>Priority:</strong> ${requestData.priority}</div>
                        <div><strong>Status:</strong> ${requestData.status}</div>
                        <div><strong>Approved By:</strong> ${requestData.approved_by}</div>
                    </div>
                </div>
            `;
            document.getElementById('detailsContent').innerHTML = contentHtml;
            document.getElementById('detailsModal').style.display = 'flex';
        }

        // Cancel request
        function cancelRequest(requestId) {
            if (confirm('Are you sure you want to cancel this request?')) {
                window.location.href = `cancel_permission.php?id=${requestId}`;
            }
        }

        // Quick actions
        function exportRequests() {
            window.location.href = 'permissions.php?export=permissions_pdf';
        }

        function filterByStatus(status) {
            const rows = document.querySelectorAll('.requests-table tbody tr');
            rows.forEach(row => {
                const rowStatus = (row.dataset.status || '').toLowerCase();
                if (status === 'all' || rowStatus.includes(status)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }


        // Close modals when clicking outside
        document.getElementById('requestModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRequestModal();
            }
        });

        document.getElementById('detailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailsModal();
            }
        });

        </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
