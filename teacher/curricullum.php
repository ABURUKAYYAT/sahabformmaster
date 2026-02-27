<?php
// teacher/curriculum.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow teachers to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// Get current school context
$current_school_id = require_school_auth();

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

// Fetch teacher's assigned classes
$stmt = $pdo->prepare("SELECT DISTINCT c.* FROM classes c
                       JOIN curriculum cu ON c.id = cu.class_id
                       WHERE cu.teacher_id = :teacher_id
                       ORDER BY c.class_name ASC");
$stmt->execute(['teacher_id' => $teacher_id]);
$assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all classes for filter (school-filtered)
$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$stmt->execute([$current_school_id]);
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all subjects (school-filtered)
$stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name ASC");
$stmt->execute([$current_school_id]);
$all_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search and filter
$search = trim($_GET['search'] ?? '');
$filter_class = $_GET['filter_class'] ?? '';
$filter_term = $_GET['filter_term'] ?? '';
$filter_week = $_GET['filter_week'] ?? '';
$filter_subject = $_GET['filter_subject'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'active';
$filter_assigned = $_GET['filter_assigned'] ?? 'mine';

// Pagination
$per_page = 10;
$page = max(1, intval($_GET['page'] ?? 1));

// Build query for teacher's curriculum (school-filtered)
$base_query = "FROM curriculum c
          LEFT JOIN classes cl ON c.class_id = cl.id AND cl.school_id = :school_id_classes
          WHERE c.school_id = :school_id_filter";
$params = [
    'school_id_classes' => $current_school_id,
    'school_id_filter' => $current_school_id,
];

// Status filter (skip when 'all')
if ($filter_status !== 'all') {
    $base_query .= " AND c.status = :status";
    $params['status'] = $filter_status;
}

// Optional filter: only items assigned to this teacher
if ($filter_assigned === 'mine') {
    $base_query .= " AND c.teacher_id = :teacher_id";
    $params['teacher_id'] = $teacher_id;
}

if ($search !== '') {
    $base_query .= " AND (c.subject_name LIKE :search OR c.description LIKE :search OR c.topics LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($filter_class !== '' && $filter_class !== 'all') {
    $base_query .= " AND c.class_id = :class_id";
    $params['class_id'] = $filter_class;
}

if ($filter_term !== '' && $filter_term !== 'all') {
    $base_query .= " AND c.term = :term";
    $params['term'] = $filter_term;
}

if ($filter_week !== '' && $filter_week !== 'all') {
    $base_query .= " AND c.week = :week";
    $params['week'] = $filter_week;
}

if ($filter_subject !== '' && $filter_subject !== 'all') {
    $base_query .= " AND c.subject_id = :subject_id";
    $params['subject_id'] = $filter_subject;
}

// Get unique terms from curriculum (school-filtered)
$stmt = $pdo->prepare("SELECT DISTINCT term FROM curriculum WHERE term IS NOT NULL AND term != '' AND school_id = ? ORDER BY
    CASE term
        WHEN '1st Term' THEN 1
        WHEN '2nd Term' THEN 2
        WHEN '3rd Term' THEN 3
        ELSE 4
    END");
$stmt->execute([$current_school_id]);
$terms = $stmt->fetchAll(PDO::FETCH_COLUMN);

// If no terms found, use default terms
if (empty($terms)) {
    $terms = ['1st Term', '2nd Term', '3rd Term'];
}

// Count for pagination
$count_query = "SELECT COUNT(*) " . $base_query;
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_rows = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

// Execute main query
$query = "SELECT DISTINCT c.*, cl.class_name " . $base_query .
         " ORDER BY c.term, c.week, c.status, c.grade_level, c.subject_name LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$curriculums = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pagination_params = $_GET;
unset($pagination_params['page']);
$start_item = $total_rows > 0 ? ($offset + 1) : 0;
$end_item = $total_rows > 0 ? min($offset + count($curriculums), $total_rows) : 0;
$prev_page = max(1, $page - 1);
$next_page = min($total_pages, $page + 1);

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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Management | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f5f7fb;
        }

        .dashboard-container .main-content {
            width: 100%;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .content-header,
        .controls-modern,
        .actions-modern,
        .curriculum-table-container,
        .empty-state-modern,
        .modal-content-modern {
            background: #ffffff;
            border: 1px solid #cfe1ff;
            border-radius: 12px;
            box-shadow: none;
        }

        .card-header-modern {
            background: #1d4ed8;
            color: #ffffff;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
        }

        .card-title-modern {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .stats-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .stat-card-modern {
            background: #ffffff;
            border: 1px solid #cfe1ff;
            border-radius: 12px;
            padding: 1.25rem;
        }

        .stat-icon-modern {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #1d4ed8;
            color: #fff;
            margin-bottom: 0.75rem;
        }

        .stat-value-modern {
            font-size: 1.75rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-label-modern {
            color: #64748b;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .controls-modern,
        .actions-modern {
            padding: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .form-row-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-label-modern {
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.4rem;
            display: block;
        }

        .form-input-modern {
            width: 100%;
            border: 1px solid #cfe1ff;
            border-radius: 10px;
            padding: 0.65rem 0.85rem;
        }

        .btn-modern-primary,
        .btn-modern-secondary {
            padding: 0.6rem 1rem;
            border-radius: 999px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-modern-primary {
            background: #1d4ed8;
            color: #fff;
            border: 1px solid #1d4ed8;
        }

        .btn-modern-secondary {
            background: #fff;
            color: #1d4ed8;
            border: 1px solid #1d4ed8;
        }

        .actions-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 0.75rem;
        }

        .action-btn-modern {
            border: 1px solid #cfe1ff;
            border-radius: 12px;
            padding: 0.9rem;
            text-decoration: none;
            color: #1e293b;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: center;
        }

        .action-icon-modern {
            color: #1d4ed8;
        }

        .table-header-modern {
            padding: 1rem 1.25rem;
            background: #1d4ed8;
            color: #fff;
            border-radius: 12px 12px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .curriculum-table-modern th {
            background: #f1f5ff;
            color: #1e3a8a;
        }

        .meta-tag-modern {
            background: #e0ecff;
            color: #1e40af;
        }

        .status-badge-modern {
            border-radius: 999px;
            padding: 0.25rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active-modern { background: #dcfce7; color: #166534; }
        .status-inactive-modern { background: #fee2e2; color: #991b1b; }

        .btn-small-modern {
            border-radius: 8px;
            padding: 0.4rem 0.7rem;
        }

        .btn-view-modern { background: #2563eb; color: #fff; }
        .btn-edit-modern { background: #0ea5e9; color: #fff; }

        .modal-modern {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 1050;
        }

        .modal-content-modern {
            position: absolute;
            inset: 50% auto auto 50%;
            transform: translate(-50%, -50%);
            max-width: 820px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            padding: 1.5rem;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.25);
            border-radius: 16px;
        }

        .modal-header-modern {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.75rem;
            margin-bottom: 1rem;
        }

        .modal-title-modern {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
        }

        .modal-close-modern {
            background: #f1f5ff;
            border: 1px solid #cfe1ff;
            color: #1d4ed8;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .modal-close-modern:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(29, 78, 216, 0.12);
        }

        .modal-actions-modern {
            border-top: 1px solid #e2e8f0;
            padding-top: 0.75rem;
            margin-top: 1rem;
            display: flex;
            justify-content: flex-end;
        }

        .detail-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 0.75rem;
        }

        .detail-item-modern {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.75rem 0.9rem;
        }

        .detail-label-modern {
            font-size: 0.75rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .detail-value-modern {
            color: #0f172a;
            font-weight: 600;
            margin-top: 0.25rem;
        }

        .topics-preview-modern {
            background: #ffffff;
            border: 1px dashed #cfe1ff;
            border-radius: 12px;
            padding: 0.75rem;
            max-height: 240px;
            overflow-y: auto;
        }

        .topic-item-modern {
            display: flex;
            gap: 0.5rem;
            padding: 0.4rem 0;
            border-bottom: 1px solid #eef2ff;
        }

        .topic-item-modern:last-child {
            border-bottom: none;
        }

        .topic-icon-modern {
            color: #1d4ed8;
            margin-top: 0.2rem;
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 1rem;
            }

            .form-row-modern {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/mobile_navigation.php'; ?>

    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">Curriculum Management</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($teacher_name); ?></span>
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
        <?php
        $total_curriculum = count($curriculums);
        $assigned_curriculum = array_filter($curriculums, fn($c) => $c['teacher_id'] == $teacher_id);
        $unique_subjects = count(array_unique(array_column($curriculums, 'subject_name')));
        $total_topics = array_sum(array_map('countTopics', array_column($curriculums, 'topics')));
        ?>

        <div class="content-header">
            <div class="welcome-section">
                <h2>Curriculum Management</h2>
                <p>Efficiently manage and track curriculum content for your assigned classes</p>
            </div>
            <div class="header-stats">
                <div class="quick-stat">
                    <span class="quick-stat-value"><?php echo $total_curriculum; ?></span>
                    <span class="quick-stat-label">Total Curriculum</span>
                </div>
                <div class="quick-stat">
                    <span class="quick-stat-value"><?php echo count($assigned_curriculum); ?></span>
                    <span class="quick-stat-label">Assigned to Me</span>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-modern">
            <div class="stat-card-modern stat-curriculum animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-value-modern"><?php echo $total_curriculum; ?></div>
                <div class="stat-label-modern">Total Curriculum</div>
            </div>

            <div class="stat-card-modern stat-assigned animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value-modern"><?php echo count($assigned_curriculum); ?></div>
                <div class="stat-label-modern">Assigned to Me</div>
            </div>

            <div class="stat-card-modern stat-subjects animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-flask"></i>
                </div>
                <div class="stat-value-modern"><?php echo $unique_subjects; ?></div>
                <div class="stat-label-modern">Subjects</div>
            </div>

            <div class="stat-card-modern stat-topics animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-list-ol"></i>
                </div>
                <div class="stat-value-modern"><?php echo $total_topics; ?></div>
                <div class="stat-label-modern">Total Topics</div>
            </div>
        </div>

        <!-- Controls Section -->
        <div class="controls-modern animate-fade-in-up">
            <form method="GET" class="filter-form">
                <div class="form-row-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Search</label>
                        <input type="text" class="form-input-modern" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by subject, description, or topics">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Class</label>
                        <select class="form-input-modern" name="filter_class">
                            <option value="all">All Classes</option>
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo intval($class['id']); ?>"
                                    <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Term</label>
                        <select class="form-input-modern" name="filter_term">
                            <option value="all">All Terms</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo htmlspecialchars($term); ?>"
                                    <?php echo $filter_term === $term ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($term); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Week</label>
                        <input type="number" class="form-input-modern" name="filter_week"
                               value="<?php echo htmlspecialchars($filter_week !== 'all' ? $filter_week : ''); ?>"
                               placeholder="Enter week number" min="0" max="52">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Subject</label>
                        <select class="form-input-modern" name="filter_subject">
                            <option value="all">All Subjects</option>
                            <?php foreach ($all_subjects as $subject): ?>
                                <option value="<?php echo intval($subject['id']); ?>"
                                    <?php echo $filter_subject == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Status</label>
                        <select class="form-input-modern" name="filter_status">
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Assignment</label>
                        <select class="form-input-modern" name="filter_assigned">
                            <option value="all" <?php echo $filter_assigned === 'all' ? 'selected' : ''; ?>>All Curriculum</option>
                            <option value="mine" <?php echo $filter_assigned === 'mine' ? 'selected' : ''; ?>>Assigned to Me</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="submit" class="btn-modern-primary">
                        <i class="fas fa-filter"></i>
                        <span>Apply Filters</span>
                    </button>
                    <a href="curricullum.php" class="btn-modern-secondary">
                        <i class="fas fa-redo"></i>
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </div>

        <!-- Quick Actions -->
        <div class="actions-modern animate-fade-in-up">
            <div class="actions-grid-modern">
                <a href="content_coverage.php" class="action-btn-modern">
                    <i class="fas fa-clipboard-check action-icon-modern"></i>
                    <span class="action-text-modern">Content Coverage</span>
                </a>
                <button class="action-btn-modern" onclick="exportCurriculum()">
                    <i class="fas fa-download action-icon-modern"></i>
                    <span class="action-text-modern">Export Report</span>
                </button>
                <button class="action-btn-modern" onclick="viewAnalytics()">
                    <i class="fas fa-chart-bar action-icon-modern"></i>
                    <span class="action-text-modern">View Analytics</span>
                </button>
            </div>
        </div>

        <!-- Curriculum Table -->
        <?php if (empty($curriculums)): ?>
            <div class="empty-state-modern animate-fade-in-up">
                <i class="fas fa-book-open empty-icon-modern"></i>
                <h3 class="empty-title-modern">No Curriculum Found</h3>
                <p class="empty-text-modern">No curriculum matches your current filters. Try adjusting your search criteria.</p>
                <a href="curricullum.php" class="btn-modern-primary">Clear Filters</a>
            </div>
        <?php else: ?>
            <div class="curriculum-table-container animate-fade-in-up">
                <div class="table-header-modern">
                    <div class="table-title-modern">
                        <i class="fas fa-clipboard-list"></i>
                        Curriculum Overview
                    </div>
                    <div class="curriculum-count-modern">
                        <?php echo count($curriculums); ?> Items
                    </div>
                </div>

                <div class="table-wrapper-modern">
                    <table class="curriculum-table-modern">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Class & Term</th>
                                <th>Description</th>
                                <th>Topics</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($curriculums as $curriculum): ?>
                                <tr>
                                    <td>
                                        <div class="curriculum-subject-modern">
                                            <?php echo htmlspecialchars($curriculum['subject_name']); ?>
                                        </div>
                                        <div class="curriculum-grade-modern">
                                            <?php echo htmlspecialchars($curriculum['grade_level']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="curriculum-meta-modern">
                                            <?php if ($curriculum['class_name']): ?>
                                                <span class="meta-tag-modern">
                                                    <i class="fas fa-users"></i> <?php echo htmlspecialchars($curriculum['class_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($curriculum['term']): ?>
                                                <span class="meta-tag-modern">
                                                    <i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($curriculum['term']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($curriculum['week'] > 0): ?>
                                                <span class="meta-tag-modern">
                                                    <i class="fas fa-calendar-week"></i> Week <?php echo intval($curriculum['week']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="curriculum-description-modern">
                                            <?php echo htmlspecialchars(substr($curriculum['description'] ?? '', 0, 100)); ?>
                                            <?php if (strlen($curriculum['description'] ?? '') > 100): ?>...<?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $topics_count = countTopics($curriculum['topics']);
                                        echo $topics_count > 0 ? $topics_count . ' topics' : 'No topics';
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge-modern <?php echo getStatusBadgeClass($curriculum['status']); ?>-modern">
                                            <?php echo ucfirst($curriculum['status']); ?>
                                        </span>
                                        <?php if ($curriculum['teacher_id'] == $teacher_id): ?>
                                            <br><small style="color: var(--success-600); font-weight: 600;">Assigned to Me</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons-modern">
                                            <button class="btn-small-modern btn-view-modern"
                                                    onclick="viewCurriculumDetails(<?php echo htmlspecialchars(json_encode($curriculum)); ?>)">
                                                <i class="fas fa-eye"></i>
                                                <span>View</span>
                                            </button>
                                            <button class="btn-small-modern btn-edit-modern"
                                                    onclick="printCurriculumItem(<?php echo intval($curriculum['id']); ?>)">
                                                <i class="fas fa-print"></i>
                                                <span>Print</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 1.5rem;">
                        <div style="color: var(--gray-600); font-weight: 600;">
                            Showing <?php echo $start_item; ?>-<?php echo $end_item; ?> of <?php echo $total_rows; ?>
                        </div>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <a class="btn-modern-secondary"
                               style="<?php echo $page <= 1 ? 'pointer-events: none; opacity: 0.6;' : ''; ?>"
                               href="<?php echo 'curricullum.php?' . http_build_query(array_merge($pagination_params, ['page' => 1])); ?>">
                                First
                            </a>
                            <a class="btn-modern-secondary"
                               style="<?php echo $page <= 1 ? 'pointer-events: none; opacity: 0.6;' : ''; ?>"
                               href="<?php echo 'curricullum.php?' . http_build_query(array_merge($pagination_params, ['page' => $prev_page])); ?>">
                                Prev
                            </a>
                            <span class="btn-modern-primary" style="pointer-events: none;">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </span>
                            <a class="btn-modern-secondary"
                               style="<?php echo $page >= $total_pages ? 'pointer-events: none; opacity: 0.6;' : ''; ?>"
                               href="<?php echo 'curricullum.php?' . http_build_query(array_merge($pagination_params, ['page' => $next_page])); ?>">
                                Next
                            </a>
                            <a class="btn-modern-secondary"
                               style="<?php echo $page >= $total_pages ? 'pointer-events: none; opacity: 0.6;' : ''; ?>"
                               href="<?php echo 'curricullum.php?' . http_build_query(array_merge($pagination_params, ['page' => $total_pages])); ?>">
                                Last
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Curriculum Details Modal -->
    <div class="modal-modern" id="curriculumModal">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern" id="modalTitle">Curriculum Details</h3>
                <button class="modal-close-modern" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body-modern" id="modalContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-actions-modern">
                <button class="btn-modern-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                    <span>Close</span>
                </button>
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
                    `<div class="topic-item-modern">
                        <i class="fas fa-chevron-right topic-icon-modern"></i>
                        <span>${topic.trim()}</span>
                    </div>`
                ).join('');
            }

            modalContent.innerHTML = `
                <div class="detail-grid-modern">
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Subject</div>
                        <div class="detail-value-modern">${curriculum.subject_name}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Grade Level</div>
                        <div class="detail-value-modern">${curriculum.grade_level}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Class</div>
                        <div class="detail-value-modern">${curriculum.class_name || 'Not assigned'}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Term</div>
                        <div class="detail-value-modern">${curriculum.term || 'Not specified'}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Week</div>
                        <div class="detail-value-modern">${curriculum.week || 'Not specified'}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Duration</div>
                        <div class="detail-value-modern">${curriculum.duration || 'Not specified'}</div>
                    </div>
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Status</div>
                        <div class="detail-value-modern">
                            <span class="status-badge-modern ${curriculum.status === 'active' ? 'status-active' : 'status-inactive'}-modern">
                                ${curriculum.status ? curriculum.status.charAt(0).toUpperCase() + curriculum.status.slice(1) : 'N/A'}
                            </span>
                        </div>
                    </div>
                </div>

                ${curriculum.description ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Description</div>
                        <div class="detail-value-modern">${curriculum.description}</div>
                    </div>
                ` : ''}

                ${curriculum.learning_objectives ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Learning Objectives</div>
                        <div class="detail-value-modern">${curriculum.learning_objectives}</div>
                    </div>
                ` : ''}

                ${curriculum.resources ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Resources</div>
                        <div class="detail-value-modern">${curriculum.resources}</div>
                    </div>
                ` : ''}

                ${curriculum.assessment_methods ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Assessment Methods</div>
                        <div class="detail-value-modern">${curriculum.assessment_methods}</div>
                    </div>
                ` : ''}

                ${curriculum.prerequisites ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Prerequisites</div>
                        <div class="detail-value-modern">${curriculum.prerequisites}</div>
                    </div>
                ` : ''}

                ${topicsHtml ? `
                    <div class="detail-item-modern">
                        <div class="detail-label-modern">Topics/Modules</div>
                        <div class="topics-preview-modern">
                            ${topicsHtml}
                        </div>
                    </div>
                ` : ''}
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

        // Export curriculum
        function exportCurriculum() {
            const params = new URLSearchParams(window.location.search);
            window.open(`../admin/export_curriculum.php?${params.toString()}`, '_blank');
        }

        // View analytics
        function viewAnalytics() {
            window.location.href = 'curriculum-analytics.php';
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
        });

        // Header scroll effect
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (!header) return;
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Add entrance animations on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all animated elements
        document.querySelectorAll('.animate-fade-in-up, .animate-slide-in-left, .animate-slide-in-right').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            observer.observe(el);
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
