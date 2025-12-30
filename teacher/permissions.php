<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}

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
                    staff_id, request_type, title, description, 
                    start_date, end_date, duration_hours, priority, 
                    attachment_path, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            
            $stmt->execute([
                $user_id, $request_type, $title, $description,
                $start_date, $end_date ?: null, $duration_hours ?: null,
                $priority, $attachment_path
            ]);
            
            $message = "Permission request submitted successfully!";
            
        } catch (PDOException $e) {
            $error = "Error submitting request: " . $e->getMessage();
        }
    }
}

// Fetch teacher's permission requests
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name as approved_by_name 
        FROM permissions p
        LEFT JOIN users u ON p.approved_by = u.id
        WHERE p.staff_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching requests: " . $e->getMessage();
    $requests = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Permission - Sahab Form Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --border-color: #dee2e6;
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, #34495e 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .sidebar {
            background: white;
            min-height: calc(100vh - 56px);
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
            position: sticky;
            top: 56px;
        }
        
        .sidebar .nav-link {
            color: #495057;
            padding: 12px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background-color: #e9ecef;
            border-left-color: var(--secondary-color);
            color: var(--secondary-color);
        }
        
        .main-content {
            padding: 20px;
        }
        
        .card-custom {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .card-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-approved { background-color: #d4edda; color: #155724; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        .status-cancelled { background-color: #e2e3e5; color: #383d41; }
        
        .priority-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
        }
        
        .priority-low { background-color: #d1ecf1; color: #0c5460; }
        .priority-medium { background-color: #fff3cd; color: #856404; }
        .priority-high { background-color: #f8d7da; color: #721c24; }
        .priority-urgent { background-color: #f5c6cb; color: #721c24; font-weight: bold; }
        
        .btn-custom {
            background: linear-gradient(135deg, var(--secondary-color) 0%, #2980b9 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .btn-custom:hover {
            background: linear-gradient(135deg, #2980b9 0%, #1c5d8a 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .table-responsive {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #34495e 100%);
            color: white;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }
        
        .file-upload {
            border: 2px dashed #ddd;
            border-radius: 5px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.3s;
        }
        
        .file-upload:hover {
            border-color: var(--secondary-color);
        }
        
        .file-upload input[type="file"] {
            display: none;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                margin-bottom: 20px;
            }
            
            .table th, .table td {
                font-size: 0.9rem;
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
          <a href="index.php" class="btn btn-outline-light btn-sm">
                    Dashboard
                </a>
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-school me-2"></i>Sahab Form Master
            </a>
            <!-- <div class="d-flex align-items-center">
                <span class="text-light me-3"><?php echo $_SESSION['full_name'] ?? 'Teacher'; ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div> -->
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <!-- <div class="col-lg-2 col-md-3 sidebar">
                <div class="d-flex flex-column flex-shrink-0 p-3">
                    <ul class="nav nav-pills flex-column mb-auto">
                        <li class="nav-item">
                            <a href="teacher_dashboard.php" class="nav-link">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="teacher_permission.php" class="nav-link active">
                                <i class="fas fa-clipboard-check me-2"></i> Permission Requests
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="teacher_lessons.php" class="nav-link">
                                <i class="fas fa-book me-2"></i> Lesson Plans
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="teacher_profile.php" class="nav-link">
                                <i class="fas fa-user me-2"></i> Profile
                            </a>
                        </li>
                    </ul>
                </div>
            </div> -->

            <!-- Main Content -->
            <div class="col-lg-10 col-md-9 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="fw-bold text-primary">
                        <i class="fas fa-clipboard-check me-2"></i>Permission Requests
                    </h3>
                    <button class="btn btn-custom" data-bs-toggle="modal" data-bs-target="#requestModal">
                        <i class="fas fa-plus me-2"></i>New Request
                    </button>
                </div>

                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Permission Requests Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
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
                                    <td colspan="9" class="text-center py-4">
                                        <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                                        <p class="text-muted">No permission requests found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td>#<?php echo str_pad($request['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?></td>
                                        <td><?php echo htmlspecialchars($request['title']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($request['start_date'])); ?></td>
                                        <td>
                                            <?php if ($request['duration_hours']): ?>
                                                <?php echo $request['duration_hours']; ?> hours
                                            <?php else: ?>
                                                Full day
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                                                <?php echo ucfirst($request['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $request['status']; ?>">
                                                <?php echo ucfirst($request['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $request['approved_by_name'] ?: '—'; ?>
                                            <?php if ($request['approved_at']): ?>
                                                <br><small class="text-muted"><?php echo date('M d', strtotime($request['approved_at'])); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-info view-details" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#detailsModal"
                                                    data-request='<?php echo htmlspecialchars(json_encode($request), ENT_QUOTES, 'UTF-8'); ?>'>
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($request['status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-outline-danger cancel-request" 
                                                        data-id="<?php echo $request['id']; ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Request Modal -->
    <div class="modal fade" id="requestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New Permission Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Request Type *</label>
                                <select class="form-select" name="request_type" required>
                                    <option value="">Select type</option>
                                    <option value="leave">Leave</option>
                                    <option value="early_departure">Early Departure</option>
                                    <option value="late_arrival">Late Arrival</option>
                                    <option value="personal_work">Personal Work</option>
                                    <option value="training">Training/Workshop</option>
                                    <option value="emergency">Emergency</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority *</label>
                                <select class="form-select" name="priority" required>
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Title *</label>
                            <input type="text" class="form-control" name="title" placeholder="Brief title for your request" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" rows="3" 
                                      placeholder="Provide details about your request..." required></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date & Time *</label>
                                <input type="datetime-local" class="form-control" name="start_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date & Time (Optional)</label>
                                <input type="datetime-local" class="form-control" name="end_date">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Duration (hours, leave empty for full day)</label>
                            <input type="number" class="form-control" name="duration_hours" 
                                   step="0.5" min="0.5" max="24" placeholder="e.g., 2.5">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Attachment (Optional)</label>
                            <div class="file-upload">
                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                <p class="mb-2">Click to upload supporting document</p>
                                <p class="text-muted small">Max size: 5MB | Formats: JPG, PNG, PDF, DOC</p>
                                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                            </div>
                            <div id="file-name" class="mt-2 small text-muted"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-custom">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="detailsContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // File upload display
        document.querySelector('input[type="file"]').addEventListener('change', function(e) {
            const fileName = document.getElementById('file-name');
            if (this.files.length > 0) {
                fileName.textContent = 'Selected: ' + this.files[0].name;
                fileName.className = 'mt-2 small text-success';
            } else {
                fileName.textContent = '';
            }
        });

        // View details
        document.querySelectorAll('.view-details').forEach(button => {
            button.addEventListener('click', function() {
                const request = JSON.parse(this.getAttribute('data-request'));
                const content = `
                    <h6>${request.title}</h6>
                    <p><strong>Type:</strong> ${request.request_type.replace('_', ' ')}</p>
                    <p><strong>Description:</strong> ${request.description || 'N/A'}</p>
                    <p><strong>Start:</strong> ${new Date(request.start_date).toLocaleString()}</p>
                    ${request.end_date ? `<p><strong>End:</strong> ${new Date(request.end_date).toLocaleString()}</p>` : ''}
                    ${request.duration_hours ? `<p><strong>Duration:</strong> ${request.duration_hours} hours</p>` : ''}
                    <p><strong>Priority:</strong> <span class="priority-badge priority-${request.priority}">${request.priority}</span></p>
                    <p><strong>Status:</strong> <span class="status-badge status-${request.status}">${request.status}</span></p>
                    <p><strong>Submitted:</strong> ${new Date(request.created_at).toLocaleString()}</p>
                    ${request.approved_by_name ? `<p><strong>Approved By:</strong> ${request.approved_by_name} on ${new Date(request.approved_at).toLocaleDateString()}</p>` : ''}
                    ${request.rejection_reason ? `<p><strong>Rejection Reason:</strong> ${request.rejection_reason}</p>` : ''}
                    ${request.attachment_path ? `<p><strong>Attachment:</strong> <a href="${request.attachment_path}" target="_blank">View File</a></p>` : ''}
                `;
                document.getElementById('detailsContent').innerHTML = content;
            });
        });

        // Cancel request
        document.querySelectorAll('.cancel-request').forEach(button => {
            button.addEventListener('click', function() {
                if (confirm('Are you sure you want to cancel this request?')) {
                    const requestId = this.getAttribute('data-id');
                    window.location.href = `cancel_permission.php?id=${requestId}`;
                }
            });
        });
    </script>
</body>
</html>