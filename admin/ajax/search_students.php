<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'principal', 'vice_principal'])) {
    echo '<div class="alert alert-danger">Access denied.</div>';
    exit;
}

$current_school_id = get_current_school_id();
$search_term = $_POST['search'] ?? '';

if (empty($search_term)) {
    echo '<div class="alert alert-warning">Please enter a search term.</div>';
    exit;
}

// Search students
$search_sql = "SELECT s.id, s.full_name, s.admission_no, c.class_name 
              FROM students s 
              JOIN classes c ON s.class_id = c.id 
              WHERE s.school_id = :school_id 
              AND (s.full_name LIKE :search OR s.admission_no LIKE :search2)
              ORDER BY s.full_name 
              LIMIT 20";
$search_stmt = $pdo->prepare($search_sql);
$search_stmt->execute([
    ':school_id' => $current_school_id,
    ':search' => "%$search_term%",
    ':search2' => "%$search_term%"
]);
$students = $search_stmt->fetchAll();

if (empty($students)) {
    echo '<div class="alert alert-info">No students found matching "' . htmlspecialchars($search_term) . '".</div>';
    exit;
}
?>

<div class="table-responsive">
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Student</th>
                <th>Admission No</th>
                <th>Class</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($students as $student): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="student-avatar me-2">
                                <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($student['full_name']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
                    <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="viewStudentProfile(<?php echo $student['id']; ?>)">
                            <i class="fas fa-eye me-1"></i>View Profile
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
.student-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4361ee, #3a56d4);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1rem;
}
</style>
