<?php
session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}


$student_id = $_SESSION['student_id'];

// Fetch student's evaluations
$stmt = $pdo->prepare("
    SELECT e.*, t.full_name as teacher_fname
    FROM evaluations e 
    JOIN users t ON e.teacher_id = t.id 
    WHERE e.student_id = ? 
    ORDER BY e.academic_year DESC, e.term DESC
");
$stmt->execute([$student_id]);
$evaluations = $stmt->fetchAll();

// Fetch student details
$student = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$student->execute([$student_id]);
$student_data = $student->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Evaluations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .student-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .evaluation-card {
            background: white;
            border-radius: 15px;
            border-left: 5px solid var(--secondary-color);
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        
        .evaluation-card:hover {
            transform: translateX(5px);
        }
        
        .rating-pill {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .excellent { background-color: #d4edda; color: #155724; }
        .very-good { background-color: #cce5ff; color: #004085; }
        .good { background-color: #fff3cd; color: #856404; }
        .needs-improvement { background-color: #f8d7da; color: #721c24; }
        
        .term-badge {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .progress-bar {
            border-radius: 10px;
            height: 10px;
        }
        
        @media (max-width: 768px) {
            .student-card {
                padding: 20px;
            }
            
            .term-badge {
                font-size: 0.9rem;
                padding: 6px 15px;
            }
        }
    </style>
</head>
<body>
    
    <div class="container py-5">
        <a href='dashboard.php' style='color:white, '>Back to Dashboard</a>
        <br>
        <!-- Student Profile -->
        <div class="student-card">
            <div class="row align-items-center">
                <div class="col-md-3 text-center mb-3">
                    <div class="rounded-circle bg-primary d-inline-flex align-items-center justify-content-center" 
                         style="width: 120px; height: 120px;">
                        <i class="fas fa-user-graduate fa-3x text-white"></i>
                    </div>
                </div>
                <div class="col-md-9">
                    <h2 class="mb-1"><?= htmlspecialchars($student_data['full_name'] ); ?></h2>
                    <p class="text-muted mb-2">
                        <i class="fas fa-graduation-cap me-2"></i>Class: <?= htmlspecialchars($student_data['class_id']); ?> | 
                        Roll No: <?= htmlspecialchars($student_data['admission_no']); ?>
                    </p>
                    <p class="text-muted">
                        <i class="fas fa-calendar-alt me-2"></i>Academic Year: <?= date('Y'); ?> - <?= date('Y')+1; ?>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Evaluation Summary -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card bg-primary text-white text-center p-3 rounded-3">
                    <h6>Total Evaluations</h6>
                    <h3 class="mb-0"><?= count($evaluations); ?></h3>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card bg-success text-white text-center p-3 rounded-3">
                    <h6>Excellent Ratings</h6>
                    <h3 class="mb-0">
                        <?php
                        $excellent_count = 0;
                        foreach ($evaluations as $eval) {
                            if ($eval['academic'] === 'excellent' || $eval['non_academic'] === 'excellent') {
                                $excellent_count++;
                            }
                        }
                        echo $excellent_count;
                        ?>
                    </h3>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card bg-info text-white text-center p-3 rounded-3">
                    <h6>Current Term</h6>
                    <h3 class="mb-0">Term <?= !empty($evaluations) ? $evaluations[0]['term'] : 'N/A'; ?></h3>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card bg-warning text-white text-center p-3 rounded-3">
                    <h6>Overall Progress</h6>
                    <h3 class="mb-0">
                        <?php
                        if (!empty($evaluations)) {
                            $total_ratings = count($evaluations) * 5;
                            $positive_ratings = 0;
                            foreach ($evaluations as $eval) {
                                $ratings = [$eval['academic'], $eval['non_academic'], $eval['cognitive'], $eval['psychomotor'], $eval['affective']];
                                foreach ($ratings as $rating) {
                                    if ($rating === 'excellent' || $rating === 'very-good') {
                                        $positive_ratings++;
                                    }
                                }
                            }
                            echo round(($positive_ratings / $total_ratings) * 100) . '%';
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </h3>
                </div>
            </div>
        </div>
        
        <!-- Term Filter -->
        <div class="mb-4">
            <h4 class="text-white">Filter by Term</h4>
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-outline-light active" data-term="all">All Terms</button>
                <button type="button" class="btn btn-outline-light" data-term="1">Term 1</button>
                <button type="button" class="btn btn-outline-light" data-term="2">Term 2</button>
                <button type="button" class="btn btn-outline-light" data-term="3">Term 3</button>
            </div>
        </div>
        
        <!-- Evaluations List -->
        <h3 class="text-white mb-4">My Evaluations</h3>
        
        <?php if (empty($evaluations)): ?>
            <div class="alert alert-info text-center">
                <h4><i class="fas fa-info-circle me-2"></i>No Evaluations Yet</h4>
                <p>You don't have any evaluations yet. Your teacher will evaluate you soon.</p>
            </div>
        <?php else: ?>
            <?php foreach ($evaluations as $eval): ?>
                <div class="evaluation-card p-4" data-term="<?= $eval['term']; ?>">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center mb-3">
                                <span class="term-badge me-3">
                                    Term <?= $eval['term']; ?> - <?= $eval['year']; ?>
                                </span>
                                <small class="text-muted">
                                    <i class="fas fa-calendar me-1"></i>
                                    <?= date('F d, Y', strtotime($eval['created_at'])); ?>
                                </small>
                            </div>
                            
                            <h5 class="mb-3">Evaluation by: <?= htmlspecialchars($eval['teacher_fname'] . ' ' . $eval['teacher_lname']); ?></h5>
                            
                            <div class="row">
                                <div class="col-sm-6 mb-2">
                                    <strong>Academic:</strong>
                                    <span class="rating-pill <?= $eval['academic']; ?> ms-2">
                                        <?= ucfirst($eval['academic']); ?>
                                    </span>
                                </div>
                                <div class="col-sm-6 mb-2">
                                    <strong>Non-Academic:</strong>
                                    <span class="rating-pill <?= $eval['non_academic']; ?> ms-2">
                                        <?= ucfirst($eval['non_academic']); ?>
                                    </span>
                                </div>
                                <div class="col-sm-4 mb-2">
                                    <strong>Cognitive:</strong>
                                    <span class="rating-pill <?= $eval['cognitive']; ?> ms-2">
                                        <?= ucfirst($eval['cognitive']); ?>
                                    </span>
                                </div>
                                <div class="col-sm-4 mb-2">
                                    <strong>Psychomotor:</strong>
                                    <span class="rating-pill <?= $eval['psychomotor']; ?> ms-2">
                                        <?= ucfirst($eval['psychomotor']); ?>
                                    </span>
                                </div>
                                <div class="col-sm-4 mb-2">
                                    <strong>Affective:</strong>
                                    <span class="rating-pill <?= $eval['affective']; ?> ms-2">
                                        <?= ucfirst($eval['affective']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if (!empty($eval['comments'])): ?>
                                <div class="mt-3">
                                    <strong>Teacher's Comments:</strong>
                                    <p class="mt-2 p-3 bg-light rounded"><?= nl2br(htmlspecialchars($eval['comments'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-4 text-end">
                            <button class="btn btn-outline-primary mb-2" onclick="printEvaluation(<?= $eval['id']; ?>)">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                            <br>
                            <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#viewModal<?= $eval['id']; ?>">
                                <i class="fas fa-expand me-2"></i>View Details
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- View Modal -->
                <div class="modal fade" id="viewModal<?= $eval['id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Evaluation Details - Term <?= $eval['term']; ?> <?= $eval['year']; ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Performance Summary</h6>
                                        <canvas id="radarChart<?= $eval['id']; ?>" width="300" height="300"></canvas>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Detailed Ratings</h6>
                                        <?php
                                        $ratings = [
                                            'Academic' => $eval['academic'],
                                            'Non-Academic' => $eval['non_academic'],
                                            'Cognitive' => $eval['cognitive'],
                                            'Psychomotor' => $eval['psychomotor'],
                                            'Affective' => $eval['affective']
                                        ];
                                        
                                        foreach ($ratings as $key => $value):
                                            $color = '';
                                            $score = 0;
                                            switch($value) {
                                                case 'excellent': $color = 'bg-success'; $score = 100; break;
                                                case 'very-good': $color = 'bg-info'; $score = 75; break;
                                                case 'good': $color = 'bg-warning'; $score = 50; break;
                                                case 'needs-improvement': $color = 'bg-danger'; $score = 25; break;
                                            }
                                        ?>
                                            <div class="mb-3">
                                                <strong><?= $key; ?>:</strong>
                                                <span class="float-end"><?= ucfirst($value); ?></span>
                                                <div class="progress mt-1" style="height: 10px;">
                                                    <div class="progress-bar <?= $color; ?>" 
                                                         role="progressbar" 
                                                         style="width: <?= $score; ?>%">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <h6>Detailed Comments</h6>
                                <div class="p-3 bg-light rounded">
                                    <?= nl2br(htmlspecialchars($eval['comments'])); ?>
                                </div>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-user-tie me-1"></i>
                                        Evaluated by: <?= htmlspecialchars($eval['teacher_fname'] . ' ' . $eval['teacher_lname']); ?>
                                        <br>
                                        <i class="fas fa-clock me-1"></i>
                                        Date: <?= date('F d, Y', strtotime($eval['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button class="btn btn-primary" onclick="printEvaluation(<?= $eval['id']; ?>)">
                                    <i class="fas fa-print me-2"></i>Print Evaluation
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        function printEvaluation(evaluationId) {
            window.open(`print-evaluation.php?id=${evaluationId}`, '_blank');
        }
        
        // Term filter
        document.querySelectorAll('[data-term]').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active button
                document.querySelectorAll('[data-term]').forEach(b => {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                
                const term = this.dataset.term;
                document.querySelectorAll('.evaluation-card').forEach(card => {
                    if (term === 'all' || card.dataset.term === term) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });
        
        // Initialize radar charts
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($evaluations as $eval): ?>
                const ctx<?= $eval['id']; ?> = document.getElementById('radarChart<?= $eval['id']; ?>').getContext('2d');
                
                function getScore(rating) {
                    switch(rating) {
                        case 'excellent': return 100;
                        case 'very-good': return 75;
                        case 'good': return 50;
                        case 'needs-improvement': return 25;
                        default: return 0;
                    }
                }
                
                new Chart(ctx<?= $eval['id']; ?>, {
                    type: 'radar',
                    data: {
                        labels: ['Academic', 'Non-Academic', 'Cognitive', 'Psychomotor', 'Affective'],
                        datasets: [{
                            label: 'Performance',
                            data: [
                                getScore('<?= $eval['academic']; ?>'),
                                getScore('<?= $eval['non_academic']; ?>'),
                                getScore('<?= $eval['cognitive']; ?>'),
                                getScore('<?= $eval['psychomotor']; ?>'),
                                getScore('<?= $eval['affective']; ?>')
                            ],
                            backgroundColor: 'rgba(54, 162, 235, 0.2)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        scales: {
                            r: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    stepSize: 25
                                }
                            }
                        }
                    }
                });
            <?php endforeach; ?>
        });
    </script>
</body>
</html>