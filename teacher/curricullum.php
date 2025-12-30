<?php
// teacher/curriculum.php
session_start();
require_once '../config/db.php';

// Only allow teachers to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

// Fetch teacher's assigned classes
$stmt = $pdo->prepare("SELECT DISTINCT c.* FROM classes c 
                       JOIN curriculum cu ON c.id = cu.class_id 
                       WHERE cu.teacher_id = :teacher_id 
                       ORDER BY c.class_name ASC");
$stmt->execute(['teacher_id' => $teacher_id]);
$assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all classes for filter (since teacher can view all curriculum)
$stmt = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all subjects
$stmt = $pdo->query("SELECT id, subject_name FROM subjects ORDER BY subject_name ASC");
$all_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search and filter
$search = trim($_GET['search'] ?? '');
$filter_class = $_GET['filter_class'] ?? '';
$filter_term = $_GET['filter_term'] ?? '';
$filter_week = $_GET['filter_week'] ?? '';
$filter_subject = $_GET['filter_subject'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'active';

// Build query for teacher's curriculum
$query = "SELECT DISTINCT c.*, cl.class_name 
          FROM curriculum c 
          LEFT JOIN classes cl ON c.class_id = cl.id 
          WHERE c.status = :status";
$params = ['status' => $filter_status];

// Add teacher filter only if not viewing all
if ($filter_status === 'active') {
    $query .= " AND (c.teacher_id = :teacher_id OR c.teacher_id IS NULL)";
    $params['teacher_id'] = $teacher_id;
}

if ($search !== '') {
    $query .= " AND (c.subject_name LIKE :search OR c.description LIKE :search OR c.topics LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($filter_class !== '' && $filter_class !== 'all') {
    $query .= " AND c.class_id = :class_id";
    $params['class_id'] = $filter_class;
}

if ($filter_term !== '' && $filter_term !== 'all') {
    $query .= " AND c.term = :term";
    $params['term'] = $filter_term;
}

if ($filter_week !== '' && $filter_week !== 'all') {
    $query .= " AND c.week = :week";
    $params['week'] = $filter_week;
}

if ($filter_subject !== '' && $filter_subject !== 'all') {
    $query .= " AND c.subject_id = :subject_id";
    $params['subject_id'] = $filter_subject;
}

// Get unique terms from curriculum
$stmt = $pdo->query("SELECT DISTINCT term FROM curriculum WHERE term IS NOT NULL AND term != '' ORDER BY 
    CASE term 
        WHEN '1st Term' THEN 1 
        WHEN '2nd Term' THEN 2 
        WHEN '3rd Term' THEN 3 
        ELSE 4 
    END");
$terms = $stmt->fetchAll(PDO::FETCH_COLUMN);

// If no terms found, use default terms
if (empty($terms)) {
    $terms = ['1st Term', '2nd Term', '3rd Term'];
}

// Execute main query
$query .= " ORDER BY c.term, c.week, c.grade_level, c.subject_name";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$curriculums = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to count topics
function countTopics($topics_string) {
    if (empty($topics_string)) return 0;
    $topics = array_filter(array_map('trim', explode("\n", $topics_string)));
    return count($topics);
}

// Function to get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'active': return 'status-active';
        case 'inactive': return 'status-inactive';
        default: return 'status-inactive';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Curriculum | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a6fa5;
            --secondary-color: #166088;
            --accent-color: #4fc3a1;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --border-radius: 8px;
            --box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .teacher-dashboard {
            background: #f5f7fa;
            min-height: 100vh;
        }

        .teacher-header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: var(--box-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .school-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .school-brand img {
            height: 50px;
            border-radius: 8px;
        }

        .school-brand h1 {
            margin: 0;
            color: var(--secondary-color);
            font-size: 1.5rem;
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .teacher-details {
            text-align: right;
        }

        .teacher-details h2 {
            margin: 0;
            color: var(--dark-color);
            font-size: 1.1rem;
        }

        .teacher-details p {
            margin: 0;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .btn {
            padding: 0.5rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .main-container {
            padding: 0 2rem 2rem;
        }

        .welcome-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
        }

        .welcome-text h1 {
            margin: 0 0 0.5rem 0;
            color: var(--secondary-color);
        }

        .welcome-text p {
            margin: 0;
            color: #6c757d;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card i {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin: 0.5rem 0;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.875rem;
        }

        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            margin-bottom: 0;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .curriculum-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .curriculum-grid {
                grid-template-columns: 1fr;
            }
        }

        .curriculum-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: all 0.3s ease;
            position: relative;
        }

        .curriculum-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .curriculum-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
        }

        .curriculum-header h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.25rem;
        }

        .curriculum-header .grade {
            background: rgba(255,255,255,0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            display: inline-block;
        }

        .curriculum-body {
            padding: 1.5rem;
        }

        .curriculum-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #6c757d;
            font-size: 0.875rem;
        }

        .meta-item i {
            color: var(--accent-color);
        }

        .curriculum-description {
            color: #6c757d;
            margin-bottom: 1rem;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .topics-preview {
            max-height: 150px;
            overflow-y: auto;
            margin-bottom: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: var(--border-radius);
            border: 1px solid #dee2e6;
        }

        .topic-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .topic-item:last-child {
            border-bottom: none;
        }

        .topic-item i {
            color: var(--accent-color);
            font-size: 0.75rem;
        }

        .curriculum-actions {
            display: flex;
            gap: 0.5rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border-radius: 4px;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .badge-primary {
            background: #e3f2fd;
            color: var(--primary-color);
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6c757d;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }

        .search-box {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 3rem 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .search-box i {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 1rem;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--secondary-color);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .detail-item {
            margin-bottom: 1rem;
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }

        .detail-value {
            color: #6c757d;
            font-size: 0.95rem;
        }

        .week-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
        }

        .week-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }
    </style>
</head>
<body class="teacher-dashboard">

<header class="teacher-header">
    <div class="school-brand">
        <img src="../assets/images/nysc.jpg" alt="School Logo">
        <h1>SahabFormMaster</h1>
    </div>
    
    <div class="teacher-info">
        <div class="teacher-details">
            <h2><?php echo htmlspecialchars($teacher_name); ?></h2>
            <p>Teacher Dashboard</p>
        </div>
        <div class="header-actions">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="../index.php" class="btn btn-secondary">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</header>

<div class="main-container">
    <div class="welcome-card">
        <div class="welcome-text">
            <h1>Welcome, <?php echo htmlspecialchars(explode(' ', $teacher_name)[0]); ?>!</h1>
            <p>View and manage your assigned curriculum here.</p>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <?php
        $total_curriculum = count($curriculums);
        $assigned_curriculum = array_filter($curriculums, fn($c) => $c['teacher_id'] == $teacher_id);
        $unique_subjects = count(array_unique(array_column($curriculums, 'subject_name')));
        $total_topics = array_sum(array_map('countTopics', array_column($curriculums, 'topics')));
        ?>
        <div class="stat-card">
            <i class="fas fa-book"></i>
            <div class="stat-value"><?php echo $total_curriculum; ?></div>
            <div class="stat-label">Total Curriculum</div>
        </div>
        <div class="stat-card">
            <i class="fas fa-user-check"></i>
            <div class="stat-value"><?php echo count($assigned_curriculum); ?></div>
            <div class="stat-label">Assigned to Me</div>
        </div>
        <div class="stat-card">
            <i class="fas fa-subject"></i>
            <div class="stat-value"><?php echo $unique_subjects; ?></div>
            <div class="stat-label">Subjects</div>
        </div>
        <div class="stat-card">
            <i class="fas fa-list-ol"></i>
            <div class="stat-value"><?php echo $total_topics; ?></div>
            <div class="stat-label">Total Topics</div>
        </div>
    </div>

    <!-- Search and Filters -->
    <section class="filter-section">
        <form method="GET" class="filter-form">
            <div class="search-box">
                <input type="text" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Search curriculum by subject, description, or topics...">
                <i class="fas fa-search"></i>
            </div>

            <div class="filter-grid">
                <div class="filter-group">
                    <label><i class="fas fa-users"></i> Class</label>
                    <select name="filter_class" class="form-control">
                        <option value="all">All Classes</option>
                        <?php foreach ($all_classes as $class): ?>
                            <option value="<?php echo intval($class['id']); ?>" 
                                <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> Term</label>
                    <select name="filter_term" class="form-control">
                        <option value="all">All Terms</option>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?php echo htmlspecialchars($term); ?>" 
                                <?php echo $filter_term === $term ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($term); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-calendar-week"></i> Week</label>
                    <input type="number" name="filter_week" class="week-input" 
                           value="<?php echo htmlspecialchars($filter_week !== 'all' ? $filter_week : ''); ?>" 
                           placeholder="Enter week number" min="0" max="52">
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-book"></i> Subject</label>
                    <select name="filter_subject" class="form-control">
                        <option value="all">All Subjects</option>
                        <?php foreach ($all_subjects as $subject): ?>
                            <option value="<?php echo intval($subject['id']); ?>" 
                                <?php echo $filter_subject == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-circle"></i> Status</label>
                    <select name="filter_status" class="form-control">
                        <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <a href="curriculum.php" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </form>
    </section>

    <!-- Curriculum Grid -->
    <?php if (empty($curriculums)): ?>
        <div class="empty-state">
            <i class="fas fa-book-open"></i>
            <h3>No Curriculum Found</h3>
            <p>No curriculum matches your current filters.</p>
            <a href="curriculum.php" class="btn btn-primary">Clear Filters</a>
        </div>
    <?php else: ?>
        <div class="curriculum-grid">
            <?php foreach ($curriculums as $curriculum): ?>
                <div class="curriculum-card">
                    <div class="curriculum-header">
                        <h3><?php echo htmlspecialchars($curriculum['subject_name']); ?></h3>
                        <div class="grade"><?php echo htmlspecialchars($curriculum['grade_level']); ?></div>
                        <div style="margin-top: 0.5rem;">
                            <span class="status-badge <?php echo getStatusBadgeClass($curriculum['status']); ?>">
                                <?php echo ucfirst($curriculum['status']); ?>
                            </span>
                            <?php if ($curriculum['teacher_id'] == $teacher_id): ?>
                                <span class="badge badge-success" style="margin-left: 0.5rem;">Assigned to Me</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="curriculum-body">
                        <div class="curriculum-meta">
                            <?php if ($curriculum['class_name']): ?>
                                <div class="meta-item">
                                    <i class="fas fa-users"></i>
                                    <span><?php echo htmlspecialchars($curriculum['class_name']); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($curriculum['term']): ?>
                                <div class="meta-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?php echo htmlspecialchars($curriculum['term']); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($curriculum['week'] > 0): ?>
                                <div class="meta-item">
                                    <i class="fas fa-calendar-week"></i>
                                    <span>Week <?php echo intval($curriculum['week']); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($curriculum['duration']): ?>
                                <div class="meta-item">
                                    <i class="fas fa-clock"></i>
                                    <span><?php echo htmlspecialchars($curriculum['duration']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($curriculum['description']): ?>
                            <p class="curriculum-description">
                                <?php echo substr(htmlspecialchars($curriculum['description']), 0, 150); ?>
                                <?php if (strlen($curriculum['description']) > 150): ?>...<?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($curriculum['topics']): ?>
                            <div class="topics-preview">
                                <?php 
                                $topics = array_filter(explode("\n", $curriculum['topics']));
                                $preview_topics = array_slice($topics, 0, 3);
                                foreach ($preview_topics as $topic): ?>
                                    <div class="topic-item">
                                        <i class="fas fa-chevron-right"></i>
                                        <span><?php echo htmlspecialchars(trim($topic)); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($topics) > 3): ?>
                                    <div class="topic-item text-center small-muted">
                                        +<?php echo count($topics) - 3; ?> more topics
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($curriculum['learning_objectives']): ?>
                            <div style="margin-bottom: 1rem;">
                                <strong>Learning Objectives:</strong>
                                <p style="font-size: 0.9rem; color: #6c757d; margin-top: 0.25rem;">
                                    <?php echo substr(htmlspecialchars($curriculum['learning_objectives']), 0, 100); ?>
                                    <?php if (strlen($curriculum['learning_objectives']) > 100): ?>...<?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <div class="curriculum-actions">
                            <button class="btn btn-primary btn-small" 
                                    onclick="viewCurriculumDetails(<?php echo htmlspecialchars(json_encode($curriculum)); ?>)">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                            <button class="btn btn-secondary btn-small" 
                                    onclick="printCurriculumItem(<?php echo intval($curriculum['id']); ?>)">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <?php if ($curriculum['teacher_id'] == $teacher_id): ?>
                                <button class="btn btn-warning btn-small" onclick="createLessonPlan(<?php echo intval($curriculum['id']); ?>)">
                                    <i class="fas fa-plus"></i> Create Lesson
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Curriculum Details Modal -->
<div id="curriculumModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Curriculum Details</h3>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div id="modalContent">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<script>
// View curriculum details
function viewCurriculumDetails(curriculum) {
    const modal = document.getElementById('curriculumModal');
    const modalContent = document.getElementById('modalContent');
    const modalTitle = document.getElementById('modalTitle');
    
    let topicsHtml = '';
    if (curriculum.topics) {
        const topics = curriculum.topics.split('\n').filter(t => t.trim());
        topicsHtml = topics.map(topic => 
            `<div class="topic-item">
                <i class="fas fa-chevron-right"></i>
                <span>${topic.trim()}</span>
            </div>`
        ).join('');
    }
    
    modalContent.innerHTML = `
        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-label">Subject</div>
                <div class="detail-value">${curriculum.subject_name}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Grade Level</div>
                <div class="detail-value">${curriculum.grade_level}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Class</div>
                <div class="detail-value">${curriculum.class_name || 'Not assigned'}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Term</div>
                <div class="detail-value">${curriculum.term || 'Not specified'}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Week</div>
                <div class="detail-value">${curriculum.week || 'Not specified'}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Duration</div>
                <div class="detail-value">${curriculum.duration || 'Not specified'}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Status</div>
                <div class="detail-value">
                    <span class="status-badge ${curriculum.status === 'active' ? 'status-active' : 'status-inactive'}">
                        ${curriculum.status ? curriculum.status.charAt(0).toUpperCase() + curriculum.status.slice(1) : 'N/A'}
                    </span>
                </div>
            </div>
        </div>
        
        ${curriculum.description ? `
            <div class="detail-item">
                <div class="detail-label">Description</div>
                <div class="detail-value">${curriculum.description}</div>
            </div>
        ` : ''}
        
        ${curriculum.learning_objectives ? `
            <div class="detail-item">
                <div class="detail-label">Learning Objectives</div>
                <div class="detail-value">${curriculum.learning_objectives}</div>
            </div>
        ` : ''}
        
        ${curriculum.resources ? `
            <div class="detail-item">
                <div class="detail-label">Resources</div>
                <div class="detail-value">${curriculum.resources}</div>
            </div>
        ` : ''}
        
        ${curriculum.assessment_methods ? `
            <div class="detail-item">
                <div class="detail-label">Assessment Methods</div>
                <div class="detail-value">${curriculum.assessment_methods}</div>
            </div>
        ` : ''}
        
        ${curriculum.prerequisites ? `
            <div class="detail-item">
                <div class="detail-label">Prerequisites</div>
                <div class="detail-value">${curriculum.prerequisites}</div>
            </div>
        ` : ''}
        
        ${topicsHtml ? `
            <div class="detail-item">
                <div class="detail-label">Topics/Modules</div>
                <div class="topics-preview" style="max-height: 300px;">
                    ${topicsHtml}
                </div>
            </div>
        ` : ''}
        
        <div style="margin-top: 2rem; text-align: center;">
            <button class="btn btn-primary" onclick="printCurriculumItem(${curriculum.id})">
                <i class="fas fa-print"></i> Print This Curriculum
            </button>
            <button class="btn btn-secondary" onclick="closeModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    `;
    
    modalTitle.textContent = curriculum.subject_name;
    modal.style.display = 'block';
}

// Close modal
function closeModal() {
    document.getElementById('curriculumModal').style.display = 'none';
}

// Print curriculum item
function printCurriculumItem(id) {
    window.open(`../admin/print_curriculum.php?id=${id}`, '_blank');
}

// Print all curriculum
function printCurriculum() {
    const params = new URLSearchParams(window.location.search);
    window.open(`../admin/print_curriculum.php?${params.toString()}`, '_blank');
}

// Create lesson plan from curriculum
function createLessonPlan(curriculumId) {
    window.location.href = `create_lesson.php?curriculum_id=${curriculumId}`;
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('curriculumModal');
    if (event.target === modal) {
        closeModal();
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeModal();
    }
    if (event.ctrlKey && event.key === 'p') {
        event.preventDefault();
        printCurriculum();
    }
});
</script>
</body>
</html>