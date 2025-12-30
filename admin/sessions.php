<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: login.php');
    exit();
}

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_session'])) {
        $session_name = $_POST['session_name'];
        $academic_year = $_POST['academic_year'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $status = $_POST['status'];
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        
        if ($is_current) {
            $pdo->query("UPDATE sessions SET is_current = 0 WHERE is_current = 1");
        }
        
        $stmt = $pdo->prepare("INSERT INTO sessions (session_name, academic_year, start_date, end_date, is_current, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$session_name, $academic_year, $start_date, $end_date, $is_current, $status, $_SESSION['user_id']])) {
            $message = "Session added successfully!";
            $message_type = "success";
        } else {
            $message = "Error adding session!";
            $message_type = "danger";
        }
    }
    
    if (isset($_POST['edit_session'])) {
        $id = $_POST['session_id'];
        $session_name = $_POST['session_name'];
        $academic_year = $_POST['academic_year'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $status = $_POST['status'];
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        
        if ($is_current) {
            $pdo->query("UPDATE sessions SET is_current = 0 WHERE is_current = 1 AND id != $id");
        }
        
        $stmt = $pdo->prepare("UPDATE sessions SET session_name = ?, academic_year = ?, start_date = ?, end_date = ?, is_current = ?, status = ? WHERE id = ?");
        if ($stmt->execute([$session_name, $academic_year, $start_date, $end_date, $is_current, $status, $id])) {
            $message = "Session updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating session!";
            $message_type = "danger";
        }
    }
    
    if (isset($_POST['delete_session'])) {
        $id = $_POST['session_id'];
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = "Session deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting session!";
            $message_type = "danger";
        }
    }
    
    if (isset($_POST['set_current'])) {
        $id = $_POST['session_id'];
        $pdo->query("UPDATE sessions SET is_current = 0");
        $stmt = $pdo->prepare("UPDATE sessions SET is_current = 1, status = 'active' WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = "Session set as current successfully!";
            $message_type = "success";
        } else {
            $message = "Error setting session as current!";
            $message_type = "danger";
        }
    }
}

// Get all sessions
$sessions = $pdo->query("SELECT s.*, u.full_name as created_by_name FROM sessions s LEFT JOIN users u ON s.created_by = u.id ORDER BY academic_year DESC, start_date DESC")->fetchAll();

// Get current session
$current_session = $pdo->query("SELECT * FROM sessions WHERE is_current = 1 LIMIT 1")->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Management | School System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4cc9f0;
            --info-color: #4895ef;
            --warning-color: #f72585;
            --danger-color: #7209b7;
            --light-bg: #f8f9fa;
            --dark-bg: #212529;
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
            --gradient-warning: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            --gradient-info: linear-gradient(135deg, #7209b7 0%, #560bad 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 15px 40px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        body {
            background-color: #f5f7fb;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
        }

        .navbar-gradient {
            background: var(--gradient-primary);
            box-shadow: 0 4px 20px rgba(67, 97, 238, 0.15);
        }

        /* Main Container */
        .main-container {
            padding: 20px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
                margin-top: 10px;
            }
        }

        /* Header Section */
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            border-left: 5px solid var(--primary-color);
        }

        .page-header h1 {
            color: #2b2d42;
            font-size: 1.8rem;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #6c757d;
            font-size: 0.95rem;
        }

        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: white;
            border: none;
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.3);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: var(--transition);
        }

        .fab:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 12px 30px rgba(67, 97, 238, 0.4);
        }

        @media (max-width: 768px) {
            .fab {
                bottom: 20px;
                right: 20px;
                width: 56px;
                height: 56px;
            }
        }

        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            height: 100%;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border-top: 4px solid transparent;
            margin-bottom: 20px;
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stats-card.total {
            border-top-color: var(--primary-color);
        }

        .stats-card.active {
            border-top-color: var(--success-color);
        }

        .stats-card.upcoming {
            border-top-color: var(--info-color);
        }

        .stats-card.completed {
            border-top-color: var(--warning-color);
        }

        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 20px;
        }

        .stats-card.total .stats-icon {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary-color);
        }

        .stats-card.active .stats-icon {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success-color);
        }

        .stats-card.upcoming .stats-icon {
            background: rgba(114, 9, 183, 0.1);
            color: var(--info-color);
        }

        .stats-card.completed .stats-icon {
            background: rgba(247, 37, 133, 0.1);
            color: var(--warning-color);
        }

        .stats-number {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 5px;
            line-height: 1;
        }

        .stats-card.total .stats-number {
            color: var(--primary-color);
        }

        .stats-card.active .stats-number {
            color: var(--success-color);
        }

        .stats-card.upcoming .stats-number {
            color: var(--info-color);
        }

        .stats-card.completed .stats-number {
            color: var(--warning-color);
        }

        .stats-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }

        /* Current Session Banner */
        .current-session-banner {
            background: var(--gradient-success);
            border-radius: 16px;
            padding: 25px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(76, 201, 240, 0.2);
            position: relative;
            overflow: hidden;
        }

        .current-session-banner::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .current-session-banner::after {
            content: '';
            position: absolute;
            bottom: -30px;
            right: 30px;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .current-badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 6px 15px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 15px;
        }

        .session-dates {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 12px 15px;
            margin-top: 15px;
            font-size: 0.9rem;
        }

        /* Sessions Table Card */
        .table-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
        }

        .table-header {
            background: #f8f9fa;
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
        }

        .table-header h5 {
            margin: 0;
            color: #2b2d42;
            font-weight: 600;
        }

        .search-box {
            position: relative;
            max-width: 300px;
        }

        .search-box input {
            border-radius: 10px;
            padding-left: 40px;
            border: 1px solid #e0e0e0;
            font-size: 0.9rem;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #adb5bd;
        }

        /* Table Styling */
        .table-responsive {
            padding: 0 15px;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            border-bottom: 2px solid #e9ecef;
            border-top: 0;
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            padding: 15px 10px;
        }

        .table tbody tr {
            border-bottom: 1px solid #f1f3f5;
            transition: var(--transition);
        }

        .table tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.03);
        }

        .table tbody tr.current-row {
            background-color: rgba(76, 201, 240, 0.08);
            border-left: 3px solid var(--success-color);
        }

        .table tbody td {
            padding: 15px 10px;
            vertical-align: middle;
            font-size: 0.9rem;
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .badge-active {
            background-color: rgba(76, 201, 240, 0.15);
            color: var(--success-color);
        }

        .badge-upcoming {
            background-color: rgba(114, 9, 183, 0.15);
            color: var(--info-color);
        }

        .badge-completed {
            background-color: rgba(247, 37, 133, 0.15);
            color: var(--warning-color);
        }

        .badge-cancelled {
            background-color: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }

        .badge-current {
            background: var(--gradient-success);
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: none;
            transition: var(--transition);
        }

        .btn-edit {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }

        .btn-edit:hover {
            background: #ffc107;
            color: white;
            transform: translateY(-2px);
        }

        .btn-delete {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }

        .btn-delete:hover {
            background: #dc3545;
            color: white;
            transform: translateY(-2px);
        }

        .btn-set-current {
            background: rgba(67, 97, 238, 0.15);
            color: var(--primary-color);
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-weight: 500;
            border: none;
            transition: var(--transition);
        }

        .btn-set-current:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        /* Modal Styling */
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            background: var(--gradient-primary);
            color: white;
            border-radius: 16px 16px 0 0;
            padding: 20px 30px;
            border-bottom: none;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }

        .modal-body {
            padding: 25px 30px;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e9ecef;
        }

        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            border-radius: 10px;
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .form-check-label {
            font-size: 0.9rem;
        }

        /* Alert Styling */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .alert-success {
            background-color: rgba(76, 201, 240, 0.15);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border-left: 4px solid #dc3545;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h4 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: #495057;
        }

        .empty-state p {
            max-width: 400px;
            margin: 0 auto 25px;
        }

        /* Mobile Responsive Adjustments */
        @media (max-width: 992px) {
            .stats-number {
                font-size: 1.8rem;
            }
            
            .page-header {
                padding: 20px;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .search-box {
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .stats-card {
                padding: 20px;
            }
            
            .stats-number {
                font-size: 1.6rem;
            }
            
            .current-session-banner {
                padding: 20px;
            }
            
            .modal-body, .modal-footer {
                padding: 20px;
            }
            
            .action-btns {
                flex-wrap: wrap;
            }
            
            .table thead {
                display: none;
            }
            
            .table tbody tr {
                display: block;
                margin-bottom: 20px;
                border: 1px solid #e9ecef;
                border-radius: 12px;
                padding: 15px;
            }
            
            .table tbody td {
                display: block;
                padding: 8px 0;
                border: none;
            }
            
            .table tbody td:before {
                content: attr(data-label);
                font-weight: 600;
                color: #495057;
                display: block;
                font-size: 0.8rem;
                margin-bottom: 3px;
            }
            
            .table tbody td .action-btns {
                justify-content: flex-start;
                margin-top: 10px;
            }
        }

        @media (max-width: 576px) {
            .main-container {
                padding: 10px;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .stats-icon {
                width: 50px;
                height: 50px;
                font-size: 1.3rem;
            }
            
            .modal-dialog {
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation (You can include your actual navbar here) -->
    <nav class="navbar navbar-gradient navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="#">
                <i class="fas fa-graduation-cap me-2"></i>School System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="#"><i class="fas fa-calendar-alt me-1"></i> Sessions</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php"><i class="fas fa-arrow-left me-1"></i> BAck to Dashboard</a>
                    </li>
                    <!-- <li class="nav-item">
                        <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
                    </li> -->
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid main-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-calendar-alt me-2"></i>Academic Session Management</h1>
                    <p class="mb-0">Manage all academic sessions, set current sessions, and track academic periods</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <button type="button" class="btn btn-primary px-4 py-2" data-bs-toggle="modal" data-bs-target="#addSessionModal">
                        <i class="fas fa-plus me-2"></i> New Session
                    </button>
                </div>
            </div>
        </div>

        <!-- Message Alert -->
        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show d-flex align-items-center" role="alert">
            <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <div><?= $message ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Current Session Banner -->
        <?php if ($current_session): ?>
        <div class="current-session-banner">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <div class="current-badge"><i class="fas fa-star me-1"></i> CURRENT ACTIVE SESSION</div>
                    <h3 class="mb-2"><?= htmlspecialchars($current_session['session_name']) ?></h3>
                    <p class="mb-3">Academic Year: <?= htmlspecialchars($current_session['academic_year']) ?></p>
                    <div class="session-dates">
                        <i class="far fa-calendar-alt me-2"></i>
                        <?= date('M d, Y', strtotime($current_session['start_date'])) ?> - <?= date('M d, Y', strtotime($current_session['end_date'])) ?>
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                    <span class="badge-current"><i class="fas fa-check me-1"></i> ACTIVE</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stats-card total">
                    <div class="stats-icon">
                        <i class="fas fa-calendar"></i>
                    </div>
                    <div class="stats-number"><?= count($sessions) ?></div>
                    <div class="stats-label">Total Sessions</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stats-card active">
                    <div class="stats-icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="stats-number"><?= array_reduce($sessions, function($carry, $item) {
                        return $carry + ($item['status'] == 'active' ? 1 : 0);
                    }, 0) ?></div>
                    <div class="stats-label">Active Sessions</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stats-card upcoming">
                    <div class="stats-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stats-number"><?= array_reduce($sessions, function($carry, $item) {
                        return $carry + ($item['status'] == 'upcoming' ? 1 : 0);
                    }, 0) ?></div>
                    <div class="stats-label">Upcoming Sessions</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stats-card completed">
                    <div class="stats-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stats-number"><?= array_reduce($sessions, function($carry, $item) {
                        return $carry + ($item['status'] == 'completed' ? 1 : 0);
                    }, 0) ?></div>
                    <div class="stats-label">Completed Sessions</div>
                </div>
            </div>
        </div>

        <!-- Sessions Table Card -->
        <div class="table-card">
            <div class="table-header d-flex justify-content-between align-items-center">
                <h5><i class="fas fa-list me-2"></i>All Academic Sessions</h5>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" class="form-control" id="sessionSearch" placeholder="Search sessions...">
                </div>
            </div>
            <div class="table-responsive">
                <?php if (empty($sessions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h4>No Sessions Found</h4>
                        <p>Get started by creating your first academic session</p>
                        <button type="button" class="btn btn-primary px-4" data-bs-toggle="modal" data-bs-target="#addSessionModal">
                            <i class="fas fa-plus me-2"></i> Create Session
                        </button>
                    </div>
                <?php else: ?>
                    <table class="table table-hover" id="sessionsTable">
                        <thead>
                            <tr>
                                <th>Session Name</th>
                                <th>Academic Year</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Current</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $session): 
                                $isCurrent = $session['is_current'];
                            ?>
                            <tr class="<?= $isCurrent ? 'current-row' : '' ?>" data-search="<?= strtolower(htmlspecialchars($session['session_name'] . ' ' . $session['academic_year'])) ?>">
                                <td data-label="Session Name">
                                    <strong><?= htmlspecialchars($session['session_name']) ?></strong>
                                </td>
                                <td data-label="Academic Year"><?= htmlspecialchars($session['academic_year']) ?></td>
                                <td data-label="Duration">
                                    <div class="session-dates small">
                                        <i class="far fa-calendar me-1"></i>
                                        <?= date('M d, Y', strtotime($session['start_date'])) ?> - 
                                        <?= date('M d, Y', strtotime($session['end_date'])) ?>
                                    </div>
                                </td>
                                <td data-label="Status">
                                    <?php
                                    $status_class = '';
                                    switch ($session['status']) {
                                        case 'active': $status_class = 'badge-active'; break;
                                        case 'upcoming': $status_class = 'badge-upcoming'; break;
                                        case 'completed': $status_class = 'badge-completed'; break;
                                        case 'cancelled': $status_class = 'badge-cancelled'; break;
                                    }
                                    ?>
                                    <span class="status-badge <?= $status_class ?>">
                                        <i class="fas fa-circle me-1 small"></i><?= ucfirst($session['status']) ?>
                                    </span>
                                </td>
                                <td data-label="Current">
                                    <?php if ($isCurrent): ?>
                                        <span class="badge-current"><i class="fas fa-check me-1"></i> Current</span>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
                                            <button type="submit" name="set_current" class="btn-set-current">
                                                <i class="fas fa-exchange-alt me-1"></i> Set Current
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Created By">
                                    <div class="d-flex align-items-center">
                                        <div class="me-2" style="width: 30px; height: 30px; background: rgba(67, 97, 238, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-user small"></i>
                                        </div>
                                        <?= htmlspecialchars($session['created_by_name'] ?? 'N/A') ?>
                                    </div>
                                </td>
                                <td data-label="Actions">
                                    <div class="action-btns">
                                        <button class="btn-icon btn-edit" data-bs-toggle="modal" data-bs-target="#editSessionModal" 
                                                data-id="<?= $session['id'] ?>"
                                                data-name="<?= htmlspecialchars($session['session_name']) ?>"
                                                data-year="<?= htmlspecialchars($session['academic_year']) ?>"
                                                data-start="<?= $session['start_date'] ?>"
                                                data-end="<?= $session['end_date'] ?>"
                                                data-status="<?= $session['status'] ?>"
                                                data-current="<?= $session['is_current'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon btn-delete" data-bs-toggle="modal" data-bs-target="#deleteSessionModal" 
                                                data-id="<?= $session['id'] ?>"
                                                data-name="<?= htmlspecialchars($session['session_name']) ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Floating Action Button for Mobile -->
        <button class="fab d-lg-none" data-bs-toggle="modal" data-bs-target="#addSessionModal">
            <i class="fas fa-plus"></i>
        </button>
    </div>

    <!-- Modals (Same as before but with new styling) -->
    <!-- Add Session Modal -->
    <div class="modal fade" id="addSessionModal" tabindex="-1" aria-labelledby="addSessionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addSessionModalLabel"><i class="fas fa-plus-circle me-2"></i>Create New Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="session_name" class="form-label">Session Name *</label>
                            <input type="text" class="form-control" id="session_name" name="session_name" required 
                                   placeholder="e.g., First Term 2025/2026">
                        </div>
                        <div class="mb-3">
                            <label for="academic_year" class="form-label">Academic Year *</label>
                            <input type="text" class="form-control" id="academic_year" name="academic_year" required 
                                   placeholder="e.g., 2025/2026">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="start_date" class="form-label">Start Date *</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label for="end_date" class="form-label">End Date *</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="status" class="form-label">Status *</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="upcoming">Upcoming</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_current" name="is_current">
                            <label class="form-check-label" for="is_current">
                                Set as Current Session
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_session" class="btn btn-primary px-4">
                            <i class="fas fa-save me-2"></i> Create Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Session Modal -->
    <div class="modal fade" id="editSessionModal" tabindex="-1" aria-labelledby="editSessionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editSessionModalLabel"><i class="fas fa-edit me-2"></i>Edit Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="session_id" id="edit_session_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_session_name" class="form-label">Session Name *</label>
                            <input type="text" class="form-control" id="edit_session_name" name="session_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_academic_year" class="form-label">Academic Year *</label>
                            <input type="text" class="form-control" id="edit_academic_year" name="academic_year" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="edit_start_date" class="form-label">Start Date *</label>
                                <input type="date" class="form-control" id="edit_start_date" name="start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_end_date" class="form-label">End Date *</label>
                                <input type="date" class="form-control" id="edit_end_date" name="end_date" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status *</label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <option value="upcoming">Upcoming</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="edit_is_current" name="is_current">
                            <label class="form-check-label" for="edit_is_current">
                                Set as Current Session
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_session" class="btn btn-primary px-4">
                            <i class="fas fa-save me-2"></i> Update Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Session Modal -->
    <div class="modal fade" id="deleteSessionModal" tabindex="-1" aria-labelledby="deleteSessionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteSessionModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Delete Session</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="session_id" id="delete_session_id">
                    <div class="modal-body">
                        <div class="text-center mb-4">
                            <div class="mb-3" style="font-size: 4rem; color: #dc3545;">
                                <i class="fas fa-trash-alt"></i>
                            </div>
                            <h5>Are you sure?</h5>
                            <p class="mb-0">You are about to delete the session:</p>
                            <h6 class="mt-2 text-danger" id="delete_session_name"></h6>
                            <p class="text-muted mt-3"><i class="fas fa-info-circle me-1"></i> This action cannot be undone.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_session" class="btn btn-danger px-4">
                            <i class="fas fa-trash me-2"></i> Delete Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap & jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Edit Modal Handler
        $('#editSessionModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var modal = $(this);
            
            modal.find('#edit_session_id').val(button.data('id'));
            modal.find('#edit_session_name').val(button.data('name'));
            modal.find('#edit_academic_year').val(button.data('year'));
            modal.find('#edit_start_date').val(button.data('start'));
            modal.find('#edit_end_date').val(button.data('end'));
            modal.find('#edit_status').val(button.data('status'));
            
            if (button.data('current') == 1) {
                modal.find('#edit_is_current').prop('checked', true);
            } else {
                modal.find('#edit_is_current').prop('checked', false);
            }
        });
        
        // Delete Modal Handler
        $('#deleteSessionModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var modal = $(this);
            
            modal.find('#delete_session_id').val(button.data('id'));
            modal.find('#delete_session_name').text(button.data('name'));
        });
        
        // Search functionality
        $('#sessionSearch').on('keyup', function() {
            var value = $(this).val().toLowerCase();
            $('#sessionsTable tbody tr').filter(function() {
                $(this).toggle($(this).data('search').indexOf(value) > -1);
            });
        });
        
        // Auto-fill today's date for new session
        var today = new Date().toISOString().split('T')[0];
        $('#start_date').val(today);
        
        // Set end date to 90 days from start
        $('#start_date').change(function() {
            var startDate = new Date($(this).val());
            var endDate = new Date(startDate);
            endDate.setDate(endDate.getDate() + 90);
            $('#end_date').val(endDate.toISOString().split('T')[0]);
        });
        
        // Auto-format academic year
        $('#academic_year, #edit_academic_year').on('blur', function() {
            var value = $(this).val();
            if (value.match(/^\d{4}$/)) {
                var nextYear = parseInt(value) + 1;
                $(this).val(value + '/' + nextYear);
            }
        });
        
        // Date validation
        $('form').on('submit', function() {
            var start = new Date($('#start_date').val());
            var end = new Date($('#end_date').val());
            
            if (end <= start) {
                alert('End date must be after start date!');
                return false;
            }
            return true;
        });
        
        // Add data-label attributes for mobile table
        if ($(window).width() < 768) {
            $('#sessionsTable thead th').each(function(i) {
                var label = $(this).text();
                $('#sessionsTable tbody td:nth-child(' + (i + 1) + ')').attr('data-label', label);
            });
        }
        
        // Re-apply data-labels on window resize
        $(window).resize(function() {
            if ($(window).width() < 768) {
                $('#sessionsTable thead th').each(function(i) {
                    var label = $(this).text();
                    $('#sessionsTable tbody td:nth-child(' + (i + 1) + ')').attr('data-label', label);
                });
            }
        });
    });
    </script>
</body>
</html>