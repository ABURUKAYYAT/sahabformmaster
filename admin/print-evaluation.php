<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth-check.php';

// Only principal/admin with school authentication
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();

$evaluation_id = $_GET['id'] ?? null;

if (!$evaluation_id) {
    die('Evaluation ID required');
}

try {
    // Fetch evaluation details with related data
    $stmt = $pdo->prepare("
        SELECT e.*, s.full_name, s.class_id, s.admission_no, c.class_name, u.full_name as teacher_fname
        FROM evaluations e
        JOIN students s ON e.student_id = s.id
        LEFT JOIN classes c ON e.class_id = c.id
        LEFT JOIN users u ON e.teacher_id = u.id
        WHERE e.id = ? AND e.school_id = ?
    ");
    $stmt->execute([$evaluation_id, $current_school_id]);
    $evaluation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$evaluation) {
        die('Evaluation not found');
    }

    // Fetch school profile
    $stmt = $pdo->prepare("SELECT * FROM school_profile WHERE school_id = ? LIMIT 1");
    $stmt->execute([$current_school_id]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die('Error fetching evaluation details');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Evaluation - <?php echo htmlspecialchars($evaluation['full_name']); ?></title>
    <style>
        body {
            font-family: 'Times New Roman', serif;
            margin: 0;
            padding: 20px;
            background: white;
        }
        
        .print-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #000;
            padding-bottom: 20px;
        }
        
        .school-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .report-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .report-meta {
            font-size: 12px;
            color: #666;
        }
        
        .evaluation-content {
            margin-top: 30px;
        }
        
        .student-info {
            background: #f8f8f8;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid #000;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #ddd;
            padding: 8px 0;
        }
        
        .info-label {
            font-weight: bold;
            color: #333;
        }
        
        .info-value {
            color: #666;
        }
        
        .ratings-section {
            margin-top: 30px;
        }
        
        .ratings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .rating-card {
            border: 2px solid #000;
            padding: 20px;
            text-align: center;
        }
        
        .rating-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .rating-value {
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .comments-section {
            margin-top: 30px;
            border: 2px solid #000;
            padding: 20px;
        }
        
        .comments-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            text-transform: uppercase;
        }
        
        .comments-content {
            font-size: 14px;
            line-height: 1.6;
            white-space: pre-wrap;
        }
        
        .no-comments {
            font-style: italic;
            color: #666;
        }
        
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        
        .signature-box {
            text-align: center;
            width: 300px;
        }
        
        .signature-line {
            border-bottom: 1px solid #000;
            margin: 40px 0 10px 0;
            width: 100%;
        }
        
        .signature-label {
            font-size: 12px;
            color: #666;
            font-style: italic;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            .print-header {
                border-bottom: 3px solid #000;
            }
        }
        
        @page {
            size: A4;
            margin: 20mm;
        }
    </style>
</head>
<body>
    <div class="print-header">
        <div class="school-name">
            <?php echo htmlspecialchars($school['school_name'] ?? 'SAHABFORMMASTER'); ?>
        </div>
        <div class="report-title">Student Evaluation Report</div>
        <div class="report-meta">
            Generated on: <?php echo date('F j, Y'); ?> | 
            Generated by: <?php echo htmlspecialchars($_SESSION['full_name']); ?> |
            Evaluation ID: <?php echo $evaluation['id']; ?>
        </div>
    </div>

    <div class="evaluation-content">
        <div class="student-info">
            <h3 style="margin-top: 0; margin-bottom: 15px;">Student Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Student Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($evaluation['full_name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Admission Number:</span>
                    <span class="info-value"><?php echo $evaluation['admission_no']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Class:</span>
                    <span class="info-value"><?php echo htmlspecialchars($evaluation['class_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Term:</span>
                    <span class="info-value">Term <?php echo $evaluation['term']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Academic Year:</span>
                    <span class="info-value"><?php echo $evaluation['academic_year']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Date Evaluated:</span>
                    <span class="info-value"><?php echo date('F j, Y', strtotime($evaluation['created_at'])); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Evaluated By:</span>
                    <span class="info-value"><?php echo htmlspecialchars($evaluation['teacher_fname'] ?? 'Unknown'); ?></span>
                </div>
            </div>
        </div>

        <div class="ratings-section">
            <h3>Performance Ratings</h3>
            <div class="ratings-grid">
                <div class="rating-card">
                    <div class="rating-title">Academic Performance</div>
                    <div class="rating-value"><?php echo ucfirst(str_replace('-', ' ', $evaluation['academic'])); ?></div>
                </div>
                <div class="rating-card">
                    <div class="rating-title">Non-Academic Activities</div>
                    <div class="rating-value"><?php echo ucfirst(str_replace('-', ' ', $evaluation['non_academic'])); ?></div>
                </div>
                <div class="rating-card">
                    <div class="rating-title">Cognitive Domain</div>
                    <div class="rating-value"><?php echo ucfirst(str_replace('-', ' ', $evaluation['cognitive'])); ?></div>
                </div>
                <div class="rating-card">
                    <div class="rating-title">Psychomotor Domain</div>
                    <div class="rating-value"><?php echo ucfirst(str_replace('-', ' ', $evaluation['psychomotor'])); ?></div>
                </div>
                <div class="rating-card">
                    <div class="rating-title">Affective Domain</div>
                    <div class="rating-value"><?php echo ucfirst(str_replace('-', ' ', $evaluation['affective'])); ?></div>
                </div>
            </div>
        </div>

        <div class="comments-section">
            <div class="comments-title">Teacher's Comments & Recommendations</div>
            <?php if (!empty($evaluation['comments'])): ?>
                <div class="comments-content"><?php echo htmlspecialchars($evaluation['comments']); ?></div>
            <?php else: ?>
                <div class="comments-content no-comments">No comments provided for this evaluation.</div>
            <?php endif; ?>
        </div>

        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-label">Student Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-label">Parent/Guardian Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-label">Teacher's Signature</div>
            </div>
        </div>
    </div>

    <div class="no-print" style="margin-top: 30px; text-align: center; color: #666; font-size: 12px;">
        Note: This section will not appear in the printed version.
    </div>

    <script>
        // Auto-print when page loads
        window.onload = function() {
            window.print();
        };
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>