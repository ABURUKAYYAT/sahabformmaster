<?php
session_start();
require_once '../config/db.php';

// Student access using admission_no or user_id
$uid = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;
$errors = [];
$success = '';

if (!$uid && !$admission_no) {
    header("Location: ../index.php");
    exit;
}

// Resolve student record
$student = null;
if ($admission_no) {
    $stmt = $pdo->prepare("SELECT id, full_name, class_id FROM students WHERE admission_no = :admission_no LIMIT 1");
    $stmt->execute(['admission_no' => $admission_no]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$student && $uid) {
    $stmt = $pdo->prepare("SELECT id, full_name, class_id FROM students WHERE user_id = :uid OR id = :uid LIMIT 1");
    $stmt->execute(['uid' => $uid]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$student) {
    die("Student record not found.");
}

$student_id = (int)$student['id'];
$class_id = $student['class_id'];

// normalize term input to canonical values used in DB
function normalize_term($t) {
    $t = trim(strtolower((string)$t));
    $map = [
        '1' => '1st Term','first' => '1st Term','1st' => '1st Term','first term' => '1st Term','1st term' => '1st Term',
        '2' => '2nd Term','second' => '2nd Term','2nd' => '2nd Term','second term' => '2nd Term','2nd term' => '2nd Term',
        '3' => '3rd Term','third' => '3rd Term','3rd' => '3rd Term','third term' => '3rd Term','3rd term' => '3rd Term'
    ];
    return $map[$t] ?? (strlen($t) ? ucfirst($t) : '1st Term');
}

// Accept term from GET (filter) or default to 1st Term
$term = normalize_term($_GET['term'] ?? '1st Term');

// Handle complaint submission (unchanged)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'submit_complaint') {
        $result_id = intval($_POST['result_id'] ?? 0);
        $text = trim($_POST['complaint_text'] ?? '');
        if ($result_id <= 0 || $text === '') {
            $errors[] = "Please select a result and enter your complaint.";
        } else {
            // ensure result belongs to this student
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE id = :id AND student_id = :student_id");
            $stmt->execute(['id' => $result_id, 'student_id' => $student_id]);
            if ((int)$stmt->fetchColumn() === 0) {
                $errors[] = "Selected result not found.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO results_complaints (result_id, student_id, complaint_text, status, created_at) VALUES (:result_id, :student_id, :text, 'pending', NOW())");
                $stmt->execute(['result_id' => $result_id, 'student_id' => $student_id, 'text' => $text]);
                $success = "Complaint submitted.";
            }
        }
    }
}

// Fetch student's results for the selected term (use prepared stmt)
try {
    $stmt = $pdo->prepare("SELECT r.*, sub.subject_name
        FROM results r
        JOIN subjects sub ON r.subject_id = sub.id
        WHERE r.student_id = :student_id AND LOWER(TRIM(r.term)) = LOWER(TRIM(:term))
        ORDER BY sub.subject_name");
    $stmt->execute(['student_id' => $student_id, 'term' => $term]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Database error while fetching results.";
    error_log("student/results.php DB error: " . $e->getMessage());
    $results = [];
}

// Fetch existing complaints by student
$stmt = $pdo->prepare("SELECT rc.*, sub.subject_name, r.term FROM results_complaints rc JOIN results r ON rc.result_id = r.id JOIN subjects sub ON r.subject_id = sub.id WHERE rc.student_id = :student_id ORDER BY rc.created_at DESC");
$stmt->execute(['student_id' => $student_id]);
$complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get class name
$stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = :id");
$stmt->execute(['id' => $class_id]);
$class_name = $stmt->fetchColumn() ?: 'N/A';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Results | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="stylesheet" href="../assets/css/myresults-modern.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Student Portal</p>
                    </div>
                </div>
            </div>

            <!-- Student Info and Logout -->
            <div class="header-right">
                <div class="student-info">
                    <p class="student-label">Student</p>
                    <span class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <?php include '../includes/student_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Performance Summary Cards -->
            <div class="performance-summary">
                <?php
                if (!empty($results)) {
                    // Calculate performance metrics
                    $total_score = 0;
                    $total_possible = count($results) * 100;
                    $pass_count = 0;
                    $total_results = count($results);
                    
                    foreach ($results as $r) {
                        $first_ca = floatval($r['first_ca'] ?? 0);
                        $second_ca = floatval($r['second_ca'] ?? 0);
                        $exam = floatval($r['exam'] ?? 0);
                        $grand_total = $first_ca + $second_ca + $exam;
                        $total_score += $grand_total;
                        
                        if ($grand_total >= 50) {
                            $pass_count++;
                        }
                    }
                    
                    $average_score = $total_results > 0 ? round($total_score / $total_results, 1) : 0;
                    $pass_percentage = $total_results > 0 ? round(($pass_count / $total_results) * 100) : 0;
                ?>
                <div class="performance-card">
                    <div class="performance-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="performance-value"><?php echo $average_score; ?></div>
                    <div class="performance-label">Average Score</div>
                    <?php if ($average_score >= 70): ?>
                        <div class="performance-trend performance-trend-up">
                            <i class="fas fa-arrow-up"></i>
                            Excellent Performance
                        </div>
                    <?php elseif ($average_score >= 50): ?>
                        <div class="performance-trend performance-trend-up">
                            <i class="fas fa-arrow-up"></i>
                            Good Performance
                        </div>
                    <?php else: ?>
                        <div class="performance-trend performance-trend-down">
                            <i class="fas fa-arrow-down"></i>
                            Needs Improvement
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="performance-card">
                    <div class="performance-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="performance-value"><?php echo $pass_percentage; ?>%</div>
                    <div class="performance-label">Pass Rate</div>
                    <?php if ($pass_percentage >= 80): ?>
                        <div class="performance-trend performance-trend-up">
                            <i class="fas fa-arrow-up"></i>
                            Outstanding
                        </div>
                    <?php elseif ($pass_percentage >= 60): ?>
                        <div class="performance-trend performance-trend-up">
                            <i class="fas fa-arrow-up"></i>
                            Good
                        </div>
                    <?php else: ?>
                        <div class="performance-trend performance-trend-down">
                            <i class="fas fa-arrow-down"></i>
                            Needs Focus
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="performance-card">
                    <div class="performance-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="performance-value"><?php echo $total_results; ?></div>
                    <div class="performance-label">Subjects</div>
                    <div class="performance-trend">
                        <i class="fas fa-circle"></i>
                        <?php echo $total_results; ?> Subjects Analyzed
                    </div>
                </div>
                
                <div class="performance-card">
                    <div class="performance-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="performance-value">
                        <?php 
                            $max_score = 0;
                            foreach ($results as $r) {
                                $first_ca = floatval($r['first_ca'] ?? 0);
                                $second_ca = floatval($r['second_ca'] ?? 0);
                                $exam = floatval($r['exam'] ?? 0);
                                $grand_total = $first_ca + $second_ca + $exam;
                                $max_score = max($max_score, $grand_total);
                            }
                            echo $max_score;
                        ?>
                    </div>
                    <div class="performance-label">Highest Score</div>
                    <div class="performance-trend">
                        <i class="fas fa-circle"></i>
                        Best Performance
                    </div>
                </div>
                <?php } else { ?>
                    <div class="performance-card">
                        <div class="performance-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="performance-value">-</div>
                        <div class="performance-label">No Data</div>
                        <div class="performance-trend">
                            <i class="fas fa-circle"></i>
                            No results to analyze
                        </div>
                    </div>
                    <div class="performance-card">
                        <div class="performance-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="performance-value">-</div>
                        <div class="performance-label">No Data</div>
                        <div class="performance-trend">
                            <i class="fas fa-circle"></i>
                            No results to analyze
                        </div>
                    </div>
                    <div class="performance-card">
                        <div class="performance-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="performance-value">-</div>
                        <div class="performance-label">No Data</div>
                        <div class="performance-trend">
                            <i class="fas fa-circle"></i>
                            No results to analyze
                        </div>
                    </div>
                    <div class="performance-card">
                        <div class="performance-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="performance-value">-</div>
                        <div class="performance-label">No Data</div>
                        <div class="performance-trend">
                            <i class="fas fa-circle"></i>
                            No results to analyze
                        </div>
                    </div>
                <?php } ?>
            </div>

            <!-- Results Controls -->
            <div class="results-controls">
                <div class="results-controls-item">
                    <div class="term-selector">
                        <div class="term-selector-label">Select Term</div>
                        <select class="term-selector-select" name="term" form="termForm" id="termSelector">
                            <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                            <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                            <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                        </select>
                    </div>
                </div>
                <div class="results-controls-item">
                    <button type="submit" class="load-btn" form="termForm">
                        <i class="fas fa-search"></i>
                        <span>Load Results</span>
                    </button>
                </div>
                <?php if (!empty($results)): ?>
                <div class="results-controls-item">
                    <form method="POST" action="../teacher/generate-result-pdf.php" style="display: inline;">
                        <input type="hidden" name="student_id" value="<?php echo intval($student_id); ?>">
                        <input type="hidden" name="class_id" value="<?php echo intval($class_id); ?>">
                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                        <button type="submit" class="download-btn">
                            <i class="fas fa-download"></i>
                            <span>Download PDF</span>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Alerts -->
            <?php if(isset($success)): ?>
                <div class="alert-modern alert-success-modern animate-fade-in-up">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <?php if(!empty($errors)): ?>
                <div class="alert-modern alert-error-modern animate-fade-in-up">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars(implode('<br>', $errors)); ?></span>
                </div>
            <?php endif; ?>

            <!-- Results Grid -->
            <div class="results-grid">
                <?php if (empty($results)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📊</div>
                        <h3 class="empty-title">No Results Available</h3>
                        <p class="empty-text">No results found for <?php echo htmlspecialchars($term); ?> term.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($results as $r):
                        $first_ca = floatval($r['first_ca'] ?? 0);
                        $second_ca = floatval($r['second_ca'] ?? 0);
                        $ca_total = $first_ca + $second_ca;
                        $exam = floatval($r['exam'] ?? 0);
                        $grand_total = $ca_total + $exam;

                        if ($grand_total >= 90) { $grade = ['A','Excellent']; $grade_class = 'grade-a'; }
                        elseif ($grand_total >= 80) { $grade = ['B','Very Good']; $grade_class = 'grade-b'; }
                        elseif ($grand_total >= 70) { $grade = ['C','Good']; $grade_class = 'grade-c'; }
                        elseif ($grand_total >= 60) { $grade = ['D','Fair']; $grade_class = 'grade-d'; }
                        elseif ($grand_total >= 50) { $grade = ['E','Pass']; $grade_class = 'grade-e'; }
                        else { $grade = ['F','Fail']; $grade_class = 'grade-f'; }
                    ?>
                    <div class="result-card">
                        <div class="subject-header">
                            <div class="subject-name"><?php echo htmlspecialchars($r['subject_name']); ?></div>
                            <div class="subject-grade <?php echo $grade_class; ?>"><?php echo $grade[0]; ?></div>
                        </div>

                        <div class="scores-grid">
                            <div class="score-item">
                                <div class="score-label">First CA</div>
                                <div class="score-value"><?php echo number_format($first_ca, 1); ?></div>
                            </div>
                            <div class="score-item">
                                <div class="score-label">Second CA</div>
                                <div class="score-value"><?php echo number_format($second_ca, 1); ?></div>
                            </div>
                            <div class="score-item">
                                <div class="score-label">Exam</div>
                                <div class="score-value"><?php echo number_format($exam, 1); ?></div>
                            </div>
                        </div>

                        <div class="total-score">
                            <div class="total-label">Total Score</div>
                            <div class="total-value"><?php echo number_format($grand_total, 1); ?></div>
                        </div>

                        <div class="remark">
                            <?php echo $grade[1]; ?>
                        </div>

                        <button type="button" class="complaint-btn" onclick="toggleComplaintForm(<?php echo intval($r['id']); ?>)">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Submit Complaint</span>
                        </button>

                        <div id="complaint-<?php echo intval($r['id']); ?>" class="complaint-form">
                            <form method="POST" action="myresults.php">
                                <input type="hidden" name="action" value="submit_complaint">
                                <input type="hidden" name="result_id" value="<?php echo intval($r['id']); ?>">
                                <textarea name="complaint_text" rows="3" placeholder="Enter your complaint regarding this result..." class="complaint-textarea" required></textarea>
                                <div class="form-actions">
                                    <button class="submit-btn" type="submit">Submit Complaint</button>
                                    <button class="cancel-btn" type="button" onclick="toggleComplaintForm(<?php echo intval($r['id']); ?>)">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Complaints Section -->
            <div class="complaints-section">
                <div class="complaints-header">
                    <h3 class="complaints-title">My Complaints</h3>
                    <div class="complaints-count"><?php echo count($complaints); ?> Complaints</div>
                </div>
                
                <?php if (empty($complaints)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">💬</div>
                        <h3 class="empty-title">No Complaints</h3>
                        <p class="empty-text">You haven't submitted any complaints yet.</p>
                    </div>
                <?php else: ?>
                    <div class="complaints-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Subject</th>
                                <th>Term</th>
                                <th>Complaint</th>
                                <th>Status</th>
                                <th>Response</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($complaints as $i => $c): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($c['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($c['term']); ?></td>
                                    <td><?php echo htmlspecialchars($c['complaint_text']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $c['status']; ?>">
                                            <?php echo htmlspecialchars(ucfirst($c['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['teacher_response'] ?? 'Awaiting response...'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>About SahabFormMaster</h4>
                    <p>A comprehensive educational management system designed to help students track their academic progress and performance.</p>
                </div>
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="myresults.php">My Results</a></li>
                        <li><a href="mysubjects.php">My Subjects</a></li>
                        <li><a href="attendance.php">Attendance</a></li>
                        <li><a href="#">Support</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact Information</h4>
                    <p>📧 student.support@sahabformmaster.com</p>
                    <p>📱 +234 808 683 5607</p>
                    <p>🌐 www.sahabformmaster.com</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 SahabFormMaster. All rights reserved.</p>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <span>•</span>
                    <a href="#">Terms of Service</a>
                    <span>•</span>
                    <span>Version 2.0</span>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
            // Update aria-expanded attribute for accessibility
            const isExpanded = sidebar.classList.contains('active');
            mobileMenuToggle.setAttribute('aria-expanded', isExpanded);
        });

        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                    mobileMenuToggle.setAttribute('aria-expanded', 'false');
                }
            }
        });

        // Enhanced complaint form toggle with animation
        function toggleComplaintForm(resultId) {
            const form = document.getElementById('complaint-' + resultId);
            const isActive = form.classList.contains('active');
            
            form.classList.toggle('active');
            
            // Add animation classes for better UX
            if (!isActive) {
                form.style.maxHeight = form.scrollHeight + 'px';
                form.setAttribute('aria-hidden', 'false');
                // Scroll to form if opening
                form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                form.style.maxHeight = '0px';
                form.setAttribute('aria-hidden', 'true');
            }
        }

        // Add hover effects to result cards
        document.addEventListener('DOMContentLoaded', () => {
            const resultCards = document.querySelectorAll('.result-card');
            resultCards.forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.style.transform = 'translateY(-5px)';
                    card.style.boxShadow = '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)';
                });
                
                card.addEventListener('mouseleave', () => {
                    card.style.transform = 'translateY(0)';
                    card.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)';
                });
            });

            // Add click animation to buttons
            const complaintButtons = document.querySelectorAll('.complaint-btn');
            complaintButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    btn.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        btn.style.transform = 'scale(1)';
                    }, 150);
                });
            });

            // Add loading animation to download button
            const downloadBtn = document.querySelector('.download-btn');
            if (downloadBtn) {
                downloadBtn.addEventListener('click', () => {
                    downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Generating PDF...</span>';
                    downloadBtn.disabled = true;
                    downloadBtn.setAttribute('aria-busy', 'true');
                    setTimeout(() => {
                        downloadBtn.innerHTML = '<i class="fas fa-download"></i><span>Download PDF</span>';
                        downloadBtn.disabled = false;
                        downloadBtn.setAttribute('aria-busy', 'false');
                    }, 2000);
                });
            }

            // Add keyboard navigation support
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    const activeForms = document.querySelectorAll('.complaint-form.active');
                    activeForms.forEach(form => {
                        form.classList.remove('active');
                        form.style.maxHeight = '0px';
                        form.setAttribute('aria-hidden', 'true');
                    });
                }
            });
        });

        // Smooth scrolling for term selector
        const termSelector = document.getElementById('termSelector');
        if (termSelector) {
            termSelector.addEventListener('change', () => {
                // Add a subtle animation to the results grid
                const resultsGrid = document.querySelector('.results-grid');
                if (resultsGrid) {
                    resultsGrid.style.opacity = '0.5';
                    resultsGrid.style.transform = 'translateY(10px)';
                    setTimeout(() => {
                        resultsGrid.style.opacity = '1';
                        resultsGrid.style.transform = 'translateY(0)';
                    }, 300);
                }
            });
        }
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
