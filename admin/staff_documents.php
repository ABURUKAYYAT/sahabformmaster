<?php
// admin/staff_documents.php
session_start();
require_once '../config/db.php';

// Only allow principal (admin) to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$admin_name = $_SESSION['full_name'] ?? 'Administrator';
$errors = [];
$success = '';

// Get staff ID from URL
$staff_id = intval($_GET['staff_id'] ?? 0);

if ($staff_id <= 0) {
    header("Location: manage_user.php");
    exit;
}

// Fetch staff details for display
$stmt = $pdo->prepare("SELECT id, full_name, staff_id FROM users WHERE id = :id");
$stmt->execute(['id' => $staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    header("Location: manage_user.php");
    exit;
}

// Define allowed file types and max size
$allowed_types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];
$max_file_size = 5 * 1024 * 1024; // 5MB

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    $document_type = trim($_POST['document_type'] ?? '');
    $document_name = trim($_POST['document_name'] ?? '');
    
    if (empty($document_type) || empty($document_name)) {
        $errors[] = 'Document type and name are required.';
    } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please select a file to upload.';
    } elseif ($_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload error: ' . $_FILES['document_file']['error'];
    } elseif ($_FILES['document_file']['size'] > $max_file_size) {
        $errors[] = 'File size exceeds 5MB limit.';
    } else {
        $file = $_FILES['document_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types) || !array_key_exists($file_ext, $allowed_types)) {
            $errors[] = 'Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX';
        } else {
            // Create upload directory if it doesn't exist
            $upload_dir = '../uploads/staff_documents/' . $staff_id . '/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $unique_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $file['name']);
            $file_path = $upload_dir . $unique_name;
            
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                // Insert document record into database
                $stmt = $pdo->prepare("INSERT INTO staff_documents (user_id, document_type, document_name, file_path, uploaded_at, verified_by) VALUES (:user_id, :document_type, :document_name, :file_path, NOW(), :verified_by)");
                $stmt->execute([
                    'user_id' => $staff_id,
                    'document_type' => $document_type,
                    'document_name' => $document_name,
                    'file_path' => $file_path,
                    'verified_by' => $_SESSION['user_id']
                ]);
                
                $success = 'Document uploaded successfully.';
            } else {
                $errors[] = 'Failed to move uploaded file.';
            }
        }
    }
}

// Handle document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $doc_id = intval($_POST['doc_id'] ?? 0);
    
    if ($doc_id > 0) {
        // Get file path before deletion
        $stmt = $pdo->prepare("SELECT file_path FROM staff_documents WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $doc_id, 'user_id' => $staff_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($document) {
            // Delete file from server
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            
            // Delete record from database
            $stmt = $pdo->prepare("DELETE FROM staff_documents WHERE id = :id");
            $stmt->execute(['id' => $doc_id]);
            
            $success = 'Document deleted successfully.';
        } else {
            $errors[] = 'Document not found.';
        }
    }
}

// Handle verification toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify') {
    $doc_id = intval($_POST['doc_id'] ?? 0);
    $verify_status = intval($_POST['verify_status'] ?? 0);
    
    if ($doc_id > 0) {
        $stmt = $pdo->prepare("UPDATE staff_documents SET verified = :verified, verified_by = :verified_by, verified_at = NOW() WHERE id = :id AND user_id = :user_id");
        $stmt->execute([
            'verified' => $verify_status,
            'verified_by' => $_SESSION['user_id'],
            'id' => $doc_id,
            'user_id' => $staff_id
        ]);
        
        $success = $verify_status ? 'Document marked as verified.' : 'Document verification removed.';
    }
}

// Fetch staff documents
$stmt = $pdo->prepare("SELECT d.*, u.full_name as verified_by_name FROM staff_documents d LEFT JOIN users u ON d.verified_by = u.id WHERE d.user_id = :user_id ORDER BY d.uploaded_at DESC");
$stmt->execute(['user_id' => $staff_id]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Document type labels
$document_types = [
    'cv' => 'Curriculum Vitae',
    'certificate' => 'Academic Certificate',
    'license' => 'Teaching License',
    'id_copy' => 'ID Copy',
    'police_clearance' => 'Police Clearance',
    'medical' => 'Medical Certificate',
    'contract' => 'Employment Contract',
    'photo' => 'Photograph',
    'other' => 'Other Document'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Staff Documents | <?php echo htmlspecialchars($staff['full_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .documents-header {
            background: linear-gradient(135deg, #0066cc 0%, #004d99 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .documents-header h1 {
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        .staff-info {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 15px;
        }
        .staff-avatar {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .upload-section {
            background: var(--white);
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .upload-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .file-input {
            padding: 8px;
            background: #f9f9f9;
        }
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .document-card {
            background: var(--white);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid #eee;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .document-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        .document-card.verified {
            border-left: 4px solid #28a745;
        }
        .document-card.unverified {
            border-left: 4px solid #dc3545;
        }
        .document-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .document-type {
            background: #e6f7ff;
            color: #0066cc;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .document-name {
            font-weight: 600;
            font-size: 16px;
            margin: 10px 0;
            color: #333;
        }
        .document-meta {
            color: #666;
            font-size: 13px;
            margin: 10px 0;
        }
        .document-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .btn-small {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
            cursor: pointer;
        }
        .btn-view {
            background: #e8f4ff;
            color: #0066cc;
            border: 1px solid #b3d9ff;
        }
        .btn-download {
            background: #e6f9f0;
            color: #006633;
            border: 1px solid #a3e9c4;
        }
        .btn-delete {
            background: #ffefef;
            color: #c00;
            border: 1px solid #f5c6c6;
        }
        .btn-verify {
            background: #fff0e6;
            color: #e65c00;
            border: 1px solid #ffccaa;
        }
        .verification-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .verified-badge {
            background: #d4edda;
            color: #155724;
        }
        .unverified-badge {
            background: #f8d7da;
            color: #721c24;
        }
        .no-documents {
            text-align: center;
            padding: 40px 20px;
            color: #666;
            background: #f9f9f9;
            border-radius: 10px;
            margin: 20px 0;
        }
        .no-documents i {
            font-size: 48px;
            color: #ccc;
            margin-bottom: 15px;
            display: block;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--white);
            color: var(--dark-gold);
            border: 2px solid var(--gold);
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        .btn-back:hover {
            background: var(--gold);
            color: white;
        }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-right">
            <div class="school-logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                <h1 class="school-name">SahabFormMaster</h1>
            </div>
        </div>

        <div class="header-left">
            <div class="teacher-info">
                <span class="teacher-name"><?php echo htmlspecialchars($admin_name); ?></span>
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <ul class="nav-list">
                <li class="nav-item">
                    <a href="index.php" class="nav-link">
                        <span class="nav-icon">📊</span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_user.php" class="nav-link">
                        <span class="nav-icon">👨‍🏫</span>
                        <span class="nav-text">Manage Staff</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="staff_documents.php?staff_id=<?php echo $staff_id; ?>" class="nav-link active">
                        <span class="nav-icon">📄</span>
                        <span class="nav-text">Staff Documents</span>
                    </a>
                </li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <a href="staff_profile.php?id=<?php echo $staff_id; ?>" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Profile
        </a>

        <div class="documents-header">
            <h1><i class="fas fa-file-alt"></i> Document Management</h1>
            <div class="staff-info">
                <div class="staff-avatar">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div>
                    <h3 style="margin: 0 0 5px 0;"><?php echo htmlspecialchars($staff['full_name']); ?></h3>
                    <p style="margin: 0; opacity: 0.9;">Staff ID: <?php echo htmlspecialchars($staff['staff_id']); ?></p>
                </div>
            </div>
            <p>Upload and manage staff documents. Allowed file types: PDF, JPG, PNG, DOC, DOCX (Max 5MB)</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <strong><i class="fas fa-exclamation-circle"></i> Error:</strong><br>
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <section class="upload-section">
            <h2><i class="fas fa-cloud-upload-alt"></i> Upload New Document</h2>
            <form method="POST" enctype="multipart/form-data" class="upload-form">
                <input type="hidden" name="action" value="upload">
                
                <div class="form-group">
                    <label for="document_type">Document Type <span style="color:#c00;">*</span></label>
                    <select name="document_type" id="document_type" class="form-control" required>
                        <option value="">Select Document Type</option>
                        <?php foreach ($document_types as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="document_name">Document Name <span style="color:#c00;">*</span></label>
                    <input type="text" name="document_name" id="document_name" class="form-control" 
                           placeholder="e.g., Bachelor Degree Certificate" required>
                </div>
                
                <div class="form-group">
                    <label for="document_file">Select File <span style="color:#c00;">*</span></label>
                    <input type="file" name="document_file" id="document_file" class="form-control file-input" required>
                </div>
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn-gold" style="padding: 12px 25px;">
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
                </div>
            </form>
        </section>

        <!-- Documents List -->
        <section>
            <h2><i class="fas fa-folder-open"></i> Staff Documents (<?php echo count($documents); ?>)</h2>
            
            <?php if (empty($documents)): ?>
                <div class="no-documents">
                    <i class="fas fa-file-alt"></i>
                    <h3>No Documents Uploaded</h3>
                    <p>Upload documents using the form above to get started.</p>
                </div>
            <?php else: ?>
                <div class="documents-grid">
                    <?php foreach ($document as $doc): 
                        $file_ext = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                        $file_icon = '';
                        switch($file_ext) {
                            case 'pdf': $file_icon = 'fas fa-file-pdf'; break;
                            case 'jpg': case 'jpeg': case 'png': $file_icon = 'fas fa-file-image'; break;
                            case 'doc': case 'docx': $file_icon = 'fas fa-file-word'; break;
                            default: $file_icon = 'fas fa-file';
                        }
                    ?>
                        <div class="document-card <?php echo $doc['verified'] ? 'verified' : 'unverified'; ?>">
                            <div class="document-header">
                                <span class="document-type"><?php echo $document_types[$doc['document_type']] ?? ucfirst($doc['document_type']); ?></span>
                                <span class="verification-badge <?php echo $doc['verified'] ? 'verified-badge' : 'unverified-badge'; ?>">
                                    <i class="fas <?php echo $doc['verified'] ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                                    <?php echo $doc['verified'] ? 'Verified' : 'Pending'; ?>
                                </span>
                            </div>
                            
                            <div class="document-name">
                                <i class="<?php echo $file_icon; ?>" style="color: #666; margin-right: 8px;"></i>
                                <?php echo htmlspecialchars($doc['document_name']); ?>
                            </div>
                            
                            <div class="document-meta">
                                <div><i class="fas fa-calendar"></i> Uploaded: <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?></div>
                                <?php if ($doc['verified'] && $doc['verified_at']): ?>
                                    <div><i class="fas fa-check-circle"></i> Verified: <?php echo date('M j, Y', strtotime($doc['verified_at'])); ?></div>
                                    <?php if ($doc['verified_by_name']): ?>
                                        <div><i class="fas fa-user-check"></i> By: <?php echo htmlspecialchars($doc['verified_by_name']); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($doc['notes']): ?>
                                    <div><i class="fas fa-sticky-note"></i> Notes: <?php echo htmlspecialchars($doc['notes']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="document-actions">
                                <a href="<?php echo $doc['file_path']; ?>" target="_blank" class="btn-small btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="<?php echo $doc['file_path']; ?>" download class="btn-small btn-download">
                                    <i class="fas fa-download"></i> Download
                                </a>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="verify">
                                    <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                    <input type="hidden" name="verify_status" value="<?php echo $doc['verified'] ? '0' : '1'; ?>">
                                    <button type="submit" class="btn-small btn-verify" onclick="return confirm('<?php echo $doc['verified'] ? 'Remove verification?' : 'Mark as verified?'; ?>');">
                                        <i class="fas <?php echo $doc['verified'] ? 'fa-times' : 'fa-check'; ?>"></i>
                                        <?php echo $doc['verified'] ? 'Unverify' : 'Verify'; ?>
                                    </button>
                                </form>
                                
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this document?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                    <button type="submit" class="btn-small btn-delete">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>Document Management</h4>
                <p>Managing documents for <?php echo htmlspecialchars($staff['full_name']); ?></p>
            </div>
            <div class="footer-section">
                <h4>Total Documents</h4>
                <p><?php echo count($documents); ?> files uploaded</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Document Management v1.0</p>
        </div>
    </div>
</footer>

</body>
</html>
