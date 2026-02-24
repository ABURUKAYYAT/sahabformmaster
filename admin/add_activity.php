<?php
session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_id = $_SESSION['user_id'];

// Get all teachers for coordinator dropdown
$teachers_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name");
$teachers_stmt->execute();
$teachers = $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Activity - School Diary</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .form-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .form-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px 10px 0 0;
            padding: 1.5rem;
        }
        .required::after {
            content: " *";
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="form-container">
            <div class="form-header">
                <h2 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add New Activity</h2>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required">Activity Title</label>
                        <input type="text" class="form-control" name="activity_title" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Activity Type</label>
                        <select class="form-select" name="activity_type" required>
                            <option value="">Select Type</option>
                            <option value="Academics">Academics</option>
                            <option value="Sports">Sports</option>
                            <option value="Cultural">Cultural</option>
                            <option value="Competition">Competition</option>
                            <option value="Workshop">Workshop</option>
                            <option value="Celebration">Celebration</option>
                            <option value="Assembly">Assembly</option>
                            <option value="Examination">Examination</option>
                            <option value="Holiday">Holiday</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Activity Date</label>
                        <input type="date" class="form-control" name="activity_date" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Start Time</label>
                        <input type="time" class="form-control" name="start_time">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Time</label>
                        <input type="time" class="form-control" name="end_time">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Venue</label>
                        <input type="text" class="form-control" name="venue" placeholder="e.g., School Auditorium">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Organizing Department</label>
                        <input type="text" class="form-control" name="organizing_dept" placeholder="e.g., Sports Department">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Coordinator</label>
                        <select class="form-select" name="coordinator_id">
                            <option value="">Select Coordinator</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Target Audience</label>
                        <select class="form-select" name="target_audience" required onchange="toggleClassSelection(this.value)">
                            <option value="">Select Audience</option>
                            <option value="All">All School</option>
                            <option value="Primary Only">Primary Only</option>
                            <option value="Secondary Only">Secondary Only</option>
                            <option value="Teachers">Teachers Only</option>
                            <option value="Specific Classes">Specific Classes</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="classSelection" style="display: none;">
                        <label class="form-label">Select Classes</label>
                        <select class="form-select" name="target_classes[]" multiple>
                            <?php
                            $classes_stmt = $pdo->query("SELECT class_name FROM classes ORDER BY class_name");
                            while ($class = $classes_stmt->fetch(PDO::FETCH_ASSOC)):
                            ?>
                                <option value="<?= $class['class_name'] ?>"><?= htmlspecialchars($class['class_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                        <small class="text-muted">Hold Ctrl to select multiple classes</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label required">Description</label>
                        <textarea class="form-control" name="description" rows="3" required></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Objectives</label>
                        <textarea class="form-control" name="objectives" rows="2" placeholder="What are the goals of this activity?"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Resources Required</label>
                        <textarea class="form-control" name="resources" rows="2" placeholder="Equipment, materials, budget, etc."></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Status</label>
                        <select class="form-select" name="status" required>
                            <option value="">Select Status</option>
                            <option value="Upcoming">Upcoming</option>
                            <option value="Ongoing">Ongoing</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Attachments (Photos/Videos/Documents)</label>
                        <input type="file" class="form-control" name="attachments[]" multiple accept="image/*,video/*,.pdf,.doc,.docx,.txt">
                        <small class="text-muted">You can upload multiple files (Max 10MB each)</small>
                    </div>
                </div>
                
                <div class="mt-4 pt-3 border-top">
                    <a href="school_diary.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Add Activity
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleClassSelection(value) {
            const classSelection = document.getElementById('classSelection');
            classSelection.style.display = value === 'Specific Classes' ? 'block' : 'none';
        }
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
