<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
$current_school_id = require_school_auth();

$paper_id = intval($_GET['id'] ?? 0);

if ($paper_id <= 0) {
    header("Location: generate_paper.php");
    exit;
}

// Fetch paper details
$stmt = $pdo->prepare("
    SELECT ep.*, s.subject_name, c.class_name, u.full_name as teacher_name,
           ep.pdf_file_path, gp.file_path
    FROM exam_papers ep
    LEFT JOIN subjects s ON ep.subject_id = s.id
    LEFT JOIN classes c ON ep.class_id = c.id
    LEFT JOIN users u ON ep.created_by = u.id
    LEFT JOIN generated_papers gp ON ep.id = gp.paper_id
    WHERE ep.id = ?
    ORDER BY gp.generation_date DESC
    LIMIT 1
");
$stmt->execute([$paper_id]);
$paper = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$paper) {
    header("Location: generate_paper.php");
    exit;
}

// Get PDF file path (prefer the one from exam_papers table)
$pdf_path = !empty($paper['pdf_file_path']) ? $paper['pdf_file_path'] : $paper['file_path'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Paper - <?php echo htmlspecialchars($paper['paper_title']); ?></title>
    <link rel="stylesheet" href="../assets/css/admin-students.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .paper-viewer {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .paper-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
        }
        
        .pdf-container {
            width: 100%;
            height: 800px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .btn-icon {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .paper-viewer {
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="header-content">
            <h1><i class="fas fa-file-pdf"></i> Exam Paper Viewer</h1>
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></span>
            </div>
        </div>
    </header>

    <main class="container">
        <!-- Back Button -->
        <div class="no-print" style="margin-bottom: 20px;">
            <a href="generate_paper.php" class="btn">
                <i class="fas fa-arrow-left"></i> Back to Generator
            </a>
            <a href="../admin/dashboard.php" class="btn secondary" style="margin-left: 10px;">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </div>

        <!-- Paper Information -->
        <div class="paper-info no-print">
            <h2><?php echo htmlspecialchars($paper['paper_title']); ?></h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-top: 10px;">
                <div><strong>Subject:</strong> <?php echo htmlspecialchars($paper['subject_name']); ?></div>
                <div><strong>Class:</strong> <?php echo htmlspecialchars($paper['class_name']); ?></div>
                <div><strong>Paper Code:</strong> <?php echo htmlspecialchars($paper['paper_code']); ?></div>
                <div><strong>Total Marks:</strong> <?php echo $paper['total_marks']; ?></div>
                <div><strong>Time:</strong> <?php echo $paper['time_allotted']; ?> minutes</div>
                <div><strong>Prepared By:</strong> <?php echo htmlspecialchars($paper['teacher_name']); ?></div>
            </div>
        </div>

        <!-- PDF Viewer -->
        <div class="paper-viewer">
            <?php if($pdf_path && file_exists($pdf_path)): ?>
                <iframe src="<?php echo $pdf_path; ?>#toolbar=0" 
                        class="pdf-container" 
                        title="Exam Paper PDF">
                </iframe>
                
                <div class="action-buttons no-print">
                    <a href="<?php echo $pdf_path; ?>" 
                       download="<?php echo basename($pdf_path); ?>" 
                       class="btn primary">
                        <i class="fas fa-download"></i> Download PDF
                    </a>
                    
                    <button onclick="window.print()" class="btn secondary">
                        <i class="fas fa-print"></i> Print Paper
                    </button>
                    
                    <a href="generate_pdf.php?paper_id=<?php echo $paper_id; ?>" 
                       target="_blank" 
                       class="btn">
                        <i class="fas fa-redo"></i> Regenerate PDF
                    </a>
                    
                    <a href="edit_paper.php?id=<?php echo $paper_id; ?>" 
                       class="btn warning">
                        <i class="fas fa-edit"></i> Edit Paper
                    </a>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 50px;">
                    <i class="fas fa-file-pdf fa-3x" style="color: #ccc; margin-bottom: 20px;"></i>
                    <h3>PDF Not Generated Yet</h3>
                    <p>The PDF version of this paper hasn't been generated yet.</p>
                    <a href="generate_pdf.php?paper_id=<?php echo $paper_id; ?>" 
                       class="btn primary" style="margin-top: 20px;">
                        <i class="fas fa-magic"></i> Generate PDF Now
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="no-print" style="background: #f8f9fa; padding: 20px; border-radius: 5px; margin-top: 20px;">
            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px;">
                <a href="generate_paper.php?clone=<?php echo $paper_id; ?>" class="btn small">
                    <i class="fas fa-copy"></i> Clone This Paper
                </a>
                <a href="paper_stats.php?id=<?php echo $paper_id; ?>" class="btn small">
                    <i class="fas fa-chart-bar"></i> View Statistics
                </a>
                <a href="share_paper.php?id=<?php echo $paper_id; ?>" class="btn small">
                    <i class="fas fa-share-alt"></i> Share Paper
                </a>
                <form method="POST" action="delete_paper.php" 
                      onsubmit="return confirm('Are you sure you want to delete this paper?');" 
                      style="display: inline;">
                    <input type="hidden" name="paper_id" value="<?php echo $paper_id; ?>">
                    <button type="submit" class="btn small danger">
                        <i class="fas fa-trash"></i> Delete Paper
                    </button>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Auto-print if URL has #print
        if (window.location.hash === '#print') {
            setTimeout(() => {
                window.print();
            }, 1000);
        }
        
        // PDF viewer enhancements
        const pdfFrame = document.querySelector('.pdf-container');
        if (pdfFrame) {
            // Add loading indicator
            pdfFrame.onload = function() {
                console.log('PDF loaded successfully');
            };
            
            pdfFrame.onerror = function() {
                console.error('Failed to load PDF');
                alert('Failed to load PDF. Please try regenerating it.');
            };
        }
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
