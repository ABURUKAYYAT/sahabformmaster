<?php
session_start();
require_once '../config/db.php';

// Check if principal is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_id = $_SESSION['user_id'];

// Get activity ID from URL
if (!isset($_GET['id'])) {
    header("Location: school_diary.php");
    exit;
}

$activity_id = $_GET['id'];

// Fetch activity details
$stmt = $pdo->prepare("SELECT * FROM school_diary WHERE id = ? AND created_by = ?");
$stmt->execute([$activity_id, $principal_id]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    $_SESSION['error'] = 'Activity not found or you don\'t have permission to edit it.';
    header("Location: school_diary.php");
    exit;
}

// Get all teachers for coordinator dropdown
$teachers_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name");
$teachers_stmt->execute();
$teachers = $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $activity_id,
        $principal_id
    ]);
    
    $_SESSION['success'] = 'Activity updated successfully!';
    header('Location: school_diary.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Activity - School Diary</title>
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
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
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
                <h2 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Edit Activity</h2>
            </div>
            
            <form method="POST" class="p-4">
                <input type="hidden" name="activity_id" value="<?= $activity['id'] ?>">
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required">Activity Title</label>
                        <input type="text" class="form-control" name="activity_title" value="<?= htmlspecialchars($activity['activity_title']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Activity Type</label>
                        <select class="form-select" name="activity_type" required>
                            <option value="">Select Type</option>
                            <option value="Academics" <?= $activity['activity_type'] == 'Academics' ? 'selected' : '' ?>>Academics</option>
                            <option value="Sports" <?= $activity['activity_type'] == 'Sports' ? 'selected' : '' ?>>Sports</option>
                            <option value="Cultural" <?= $activity['activity_type'] == 'Cultural' ? 'selected' : '' ?>>Cultural</option>
                            <option value="Competition" <?= $activity['activity_type'] == 'Competition' ? 'selected' : '' ?>>Competition</option>
                            <option value="Workshop" <?= $activity['activity_type'] == 'Workshop' ? 'selected' : '' ?>>Workshop</option>
                            <option value="Celebration" <?= $activity['activity_type'] == 'Celebration' ? 'selected' : '' ?>>Celebration</option>
                            <option value="Assembly" <?= $activity['activity_type'] == 'Assembly' ? 'selected' : '' ?>>Assembly</option>
                            <option value="Examination" <?= $activity['activity_type'] == 'Examination' ? 'selected' : '' ?>>Examination</option>
                            <option value="Holiday" <?= $activity['activity_type'] == 'Holiday' ? 'selected' : '' ?>>Holiday</option>
                            <option value="Other" <?= $activity['activity_type'] == 'Other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Activity Date</label>
                        <input type="date" class="form-control" name="activity_date" value="<?= $activity['activity_date'] ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Start Time</label>
                        <input type="time" class="form-control" name="start_time" value="<?= $activity['start_time'] ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Time</label>
                        <input type="time" class="form-control" name="end_time" value="<?= $activity['end_time'] ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Venue</label>
                        <input type="text" class="form-control" name="venue" value="<?= htmlspecialchars($activity['venue']) ?>" placeholder="e.g., School Auditorium">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Organizing Department</label>
                        <input type="text" class="form-control" name="organizing_dept" value="<?= htmlspecialchars($activity['organizing_dept']) ?>" placeholder="e.g., Sports Department">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Coordinator</label>
                        <select class="form-select" name="coordinator_id">
                            <option value="">Select Coordinator</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= $teacher['id'] ?>" <?= $activity['coordinator_id'] == $teacher['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($teacher['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Target Audience</label>
                        <select class="form-select" name="target_audience" required onchange="toggleClassSelection(this.value)">
                            <option value="">Select Audience</option>
                            <option value="All" <?= $activity['target_audience'] == 'All' ? 'selected' : '' ?>>All School</option>
                            <option value="Primary Only" <?= $activity['target_audience'] == 'Primary Only' ? 'selected' : '' ?>>Primary Only</option>
                            <option value="Secondary Only" <?= $activity['target_audience'] == 'Secondary Only' ? 'selected' : '' ?>>Secondary Only</option>
                            <option value="Teachers" <?= $activity['target_audience'] == 'Teachers' ? 'selected' : '' ?>>Teachers Only</option>
                            <option value="Specific Classes" <?= $activity['target_audience'] == 'Specific Classes' ? 'selected' : '' ?>>Specific Classes</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="classSelection" style="display: <?= $activity['target_audience'] == 'Specific Classes' ? 'block' : 'none' ?>;">
                        <label class="form-label">Select Classes</label>
                        <select class="form-select" name="target_classes[]" multiple>
                            <?php
                            $classes_stmt = $pdo->query("SELECT class_name FROM classes ORDER BY class_name");
                            $selected_classes = $activity['target_classes'] ? explode(',', $activity['target_classes']) : [];
                            while ($class = $classes_stmt->fetch(PDO::FETCH_ASSOC)):
                            ?>
                                <option value="<?= $class['class_name'] ?>" <?= in_array($class['class_name'], $selected_classes) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="text-muted">Hold Ctrl to select multiple classes</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label required">Description</label>
                        <textarea class="form-control" name="description" rows="3" required><?= htmlspecialchars($activity['description']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Objectives</label>
                        <textarea class="form-control" name="objectives" rows="2"><?= htmlspecialchars($activity['objectives']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Resources Required</label>
                        <textarea class="form-control" name="resources" rows="2"><?= htmlspecialchars($activity['resources']) ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Status</label>
                        <select class="form-select" name="status" required>
                            <option value="">Select Status</option>
                            <option value="Upcoming" <?= $activity['status'] == 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="Ongoing" <?= $activity['status'] == 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="Completed" <?= $activity['status'] == 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="Cancelled" <?= $activity['status'] == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Participant Count</label>
                        <input type="number" class="form-control" name="participant_count" value="<?= $activity['participant_count'] ?>" min="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Winners List</label>
                        <textarea class="form-control" name="winners_list" rows="2"><?= htmlspecialchars($activity['winners_list']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Achievements</label>
                        <textarea class="form-control" name="achievements" rows="2"><?= htmlspecialchars($activity['achievements']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Feedback Summary</label>
                        <textarea class="form-control" name="feedback_summary" rows="2"><?= htmlspecialchars($activity['feedback_summary']) ?></textarea>
                    </div>
                </div>
                
                <div class="mt-4 pt-3 border-top">
                    <a href="school_diary.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Update Activity
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
