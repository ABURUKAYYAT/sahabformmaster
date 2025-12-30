<?php
session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_id = $_SESSION['user_id'];

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_activity'])) {
        // Add new activity
        $stmt = $pdo->prepare("INSERT INTO school_diary (activity_title, activity_type, activity_date, start_time, end_time, venue, organizing_dept, coordinator_id, target_audience, target_classes, description, objectives, resources, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['activity_title'],
            $_POST['activity_type'],
            $_POST['activity_date'],
            $_POST['start_time'],
            $_POST['end_time'],
            $_POST['venue'],
            $_POST['organizing_dept'],
            $_POST['coordinator_id'] ?: null,
            $_POST['target_audience'],
            $_POST['target_classes'] ?: null,
            $_POST['description'],
            $_POST['objectives'],
            $_POST['resources'],
            $_POST['status'],
            $principal_id
        ]);
        
        $diary_id = $pdo->lastInsertId();
        
        // Handle file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $file_name = basename($_FILES['attachments']['name'][$key]);
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $new_file_name = time() . '_' . uniqid() . '.' . $file_ext;
                    $upload_dir = 'uploads/diary_attachments/';
                    
                    // Create directory if not exists
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_path = $upload_dir . $new_file_name;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        // Determine file type
                        $file_type = 'other';
                        if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                            $file_type = 'image';
                        } elseif (in_array($file_ext, ['mp4', 'avi', 'mov', 'wmv'])) {
                            $file_type = 'video';
                        } elseif (in_array($file_ext, ['pdf', 'doc', 'docx', 'txt'])) {
                            $file_type = 'document';
                        }
                        
                        $stmt = $pdo->prepare("INSERT INTO school_diary_attachments (diary_id, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $diary_id,
                            $file_name,
                            $file_path,
                            $file_type,
                            $_FILES['attachments']['size'][$key],
                            $principal_id
                        ]);
                    }
                }
            }
        }
        
        $_SESSION['success'] = 'Activity added successfully!';
        header('Location: school_diary.php');
        exit();
    }
    
    // Handle update
    if (isset($_POST['update_activity'])) {
        $stmt = $pdo->prepare("UPDATE school_diary SET activity_title=?, activity_type=?, activity_date=?, start_time=?, end_time=?, venue=?, organizing_dept=?, coordinator_id=?, target_audience=?, target_classes=?, description=?, objectives=?, resources=?, status=?, participant_count=?, winners_list=?, achievements=?, feedback_summary=? WHERE id=? AND created_by=?");
        $stmt->execute([
            $_POST['activity_title'],
            $_POST['activity_type'],
            $_POST['activity_date'],
            $_POST['start_time'],
            $_POST['end_time'],
            $_POST['venue'],
            $_POST['organizing_dept'],
            $_POST['coordinator_id'] ?: null,
            $_POST['target_audience'],
            $_POST['target_classes'] ?: null,
            $_POST['description'],
            $_POST['objectives'],
            $_POST['resources'],
            $_POST['status'],
            $_POST['participant_count'] ?: 0,
            $_POST['winners_list'],
            $_POST['achievements'],
            $_POST['feedback_summary'],
            $_POST['activity_id'],
            $principal_id
        ]);
        
        $_SESSION['success'] = 'Activity updated successfully!';
        header('Location: school_diary.php');
        exit();
    }
    
    // Handle delete
    if (isset($_POST['delete_activity'])) {
        $stmt = $pdo->prepare("DELETE FROM school_diary WHERE id=? AND created_by=?");
        $stmt->execute([$_POST['activity_id'], $principal_id]);
        
        $_SESSION['success'] = 'Activity deleted successfully!';
        header('Location: school_diary.php');
        exit();
    }
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "SELECT sd.*, u.full_name as coordinator_name 
          FROM school_diary sd 
          LEFT JOIN users u ON sd.coordinator_id = u.id 
          WHERE 1=1";
$params = [];

if ($search) {
    $query .= " AND (sd.activity_title LIKE ? OR sd.description LIKE ? OR sd.venue LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($type_filter) {
    $query .= " AND sd.activity_type = ?";
    $params[] = $type_filter;
}

if ($status_filter) {
    $query .= " AND sd.status = ?";
    $params[] = $status_filter;
}

if ($date_from) {
    $query .= " AND sd.activity_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND sd.activity_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY sd.activity_date DESC, sd.start_time ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all teachers for coordinator dropdown
$teachers_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name");
$teachers_stmt->execute();
$teachers = $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Diary - Principal Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .diary-card {
            transition: transform 0.2s;
            border: 1px solid #e0e0e0;
        }
        .diary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .status-upcoming { border-left: 4px solid #28a745; }
        .status-ongoing { border-left: 4px solid #17a2b8; }
        .status-completed { border-left: 4px solid #007bff; }
        .status-cancelled { border-left: 4px solid #dc3545; }
        .attachment-thumb {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 5px;
            margin: 2px;
        }
        .video-thumb {
            position: relative;
        }
        .video-thumb::after {
            content: '▶';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 20px;
            text-shadow: 0 0 5px rgba(0,0,0,0.7);
        }
        @media (max-width: 768px) {
            .filter-form .row > div {
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    
    <div class="container-fluid mt-4">
        <!-- Success Message -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0"><i class="bi bi-journal-text"></i> School Diary Management</h4>
                        <a href="add_activity.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Add New Activity
                        </a>
                    </div>
                    
                    <!-- Search and Filter Form -->
                    <div class="card-body border-bottom">
                        <form method="GET" class="filter-form">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <input type="text" class="form-control" name="search" placeholder="Search activities..." value="<?= htmlspecialchars($search) ?>">
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select" name="type">
                                        <option value="">All Types</option>
                                        <option value="Academics" <?= $type_filter == 'Academics' ? 'selected' : '' ?>>Academics</option>
                                        <option value="Sports" <?= $type_filter == 'Sports' ? 'selected' : '' ?>>Sports</option>
                                        <option value="Cultural" <?= $type_filter == 'Cultural' ? 'selected' : '' ?>>Cultural</option>
                                        <option value="Competition" <?= $type_filter == 'Competition' ? 'selected' : '' ?>>Competition</option>
                                        <option value="Workshop" <?= $type_filter == 'Workshop' ? 'selected' : '' ?>>Workshop</option>
                                        <option value="Celebration" <?= $type_filter == 'Celebration' ? 'selected' : '' ?>>Celebration</option>
                                        <option value="Assembly" <?= $type_filter == 'Assembly' ? 'selected' : '' ?>>Assembly</option>
                                        <option value="Examination" <?= $type_filter == 'Examination' ? 'selected' : '' ?>>Examination</option>
                                        <option value="Holiday" <?= $type_filter == 'Holiday' ? 'selected' : '' ?>>Holiday</option>
                                        <option value="Other" <?= $type_filter == 'Other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select" name="status">
                                        <option value="">All Status</option>
                                        <option value="Upcoming" <?= $status_filter == 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
                                        <option value="Ongoing" <?= $status_filter == 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                        <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?>>Completed</option>
                                        <option value="Cancelled" <?= $status_filter == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>" placeholder="From Date">
                                </div>
                                <div class="col-md-2">
                                    <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>" placeholder="To Date">
                                </div>
                                <div class="col-md-1">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-filter"></i> Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Activities List -->
                    <div class="card-body">
                        <?php if (empty($activities)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-journal-x" style="font-size: 48px; color: #ccc;"></i>
                                <p class="text-muted mt-3">No activities found. Add your first activity!</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($activities as $activity): ?>
                                    <?php 
                                    // Get attachments for this activity
                                    $attachments_stmt = $pdo->prepare("SELECT * FROM school_diary_attachments WHERE diary_id = ?");
                                    $attachments_stmt->execute([$activity['id']]);
                                    $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    // Get images for preview
                                    $images = array_filter($attachments, fn($a) => $a['file_type'] == 'image');
                                    ?>
                                    
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card h-100 diary-card status-<?= strtolower($activity['status']) ?>">
                                            <div class="card-body">
                                                <!-- Activity Images Preview -->
                                                <?php if (!empty($images)): ?>
                                                    <div class="mb-3">
                                                        <div class="row g-1">
                                                            <?php foreach (array_slice($images, 0, 3) as $img): ?>
                                                                <div class="col-4">
                                                                    <img src="<?= $img['file_path'] ?>" alt="<?= htmlspecialchars($img['file_name']) ?>" 
                                                                         class="img-fluid attachment-thumb" 
                                                                         data-bs-toggle="modal" 
                                                                         data-bs-target="#imageModal"
                                                                         data-src="<?= $img['file_path'] ?>"
                                                                         style="cursor: pointer;">
                                                                </div>
                                                            <?php endforeach; ?>
                                                            <?php if (count($images) > 3): ?>
                                                                <div class="col-4">
                                                                    <div class="attachment-thumb bg-light d-flex align-items-center justify-content-center">
                                                                        <span class="text-muted">+<?= count($images) - 3 ?></span>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <!-- Activity Details -->
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h5 class="card-title mb-0"><?= htmlspecialchars($activity['activity_title']) ?></h5>
                                                    <span class="badge bg-<?= 
                                                        $activity['status'] == 'Upcoming' ? 'success' : 
                                                        ($activity['status'] == 'Ongoing' ? 'info' : 
                                                        ($activity['status'] == 'Completed' ? 'primary' : 'danger')) 
                                                    ?>">
                                                        <?= $activity['status'] ?>
                                                    </span>
                                                </div>
                                                
                                                <p class="text-muted mb-2">
                                                    <i class="bi bi-calendar-date"></i> 
                                                    <?= date('F j, Y', strtotime($activity['activity_date'])) ?>
                                                    <?php if ($activity['start_time']): ?>
                                                        <i class="bi bi-clock ms-2"></i> <?= date('h:i A', strtotime($activity['start_time'])) ?>
                                                    <?php endif; ?>
                                                </p>
                                                
                                                <p class="mb-2">
                                                    <i class="bi bi-geo-alt"></i> 
                                                    <small><?= htmlspecialchars($activity['venue'] ?: 'Venue not specified') ?></small>
                                                </p>
                                                
                                                <p class="mb-2">
                                                    <i class="bi bi-person"></i> 
                                                    <small>Coordinator: <?= htmlspecialchars($activity['coordinator_name'] ?: 'Not assigned') ?></small>
                                                </p>
                                                
                                                <p class="card-text mb-3">
                                                    <?= strlen($activity['description']) > 100 ? 
                                                        substr(htmlspecialchars($activity['description']), 0, 100) . '...' : 
                                                        htmlspecialchars($activity['description']) ?>
                                                </p>
                                                
                                                <!-- Action Buttons -->
                                                <div class="d-flex justify-content-between">
                                                    <a href="get_activity_details.php?id=<?= $activity['id'] ?>" class="btn btn-sm btn-outline-info">
                                                        <i class="bi bi-info-circle"></i> Details
                                                    </a>
                                                    <div>
                                                        <a href="edit_activity.php?id=<?= $activity['id'] ?>" class="btn btn-sm btn-outline-warning">
                                                            <i class="bi bi-pencil-square"></i> Edit
                                                        </a>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this activity?')">
                                                            <input type="hidden" name="activity_id" value="<?= $activity['id'] ?>">
                                                            <button type="submit" name="delete_activity" class="btn btn-sm btn-outline-danger">
                                                                <i class="bi bi-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Image Preview Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <img src="" alt="Preview" class="img-fluid" id="modalImage">
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Image preview in modal
        document.addEventListener('DOMContentLoaded', function() {
            const imageModal = document.getElementById('imageModal');
            imageModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const imageSrc = button.getAttribute('data-src');
                const modalImage = document.getElementById('modalImage');
                modalImage.src = imageSrc;
            });
        });
    </script>
</body>
</html>