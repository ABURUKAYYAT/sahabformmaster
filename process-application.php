<?php
// process-application.php
session_start();
require_once 'config/db.php';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Generate application number if not already set
        $application_number = $_POST['application_number'] ?? generateApplicationNumber();
        
        // Handle file uploads
        $uploads = handleFileUploads($application_number);
        
        // Insert application data
        $stmt = $pdo->prepare("
            INSERT INTO applicants (
                application_number, full_name, gender, date_of_birth, 
                email, phone, address, city, state, class_applied,
                previous_school, last_class_completed, guardian_name,
                guardian_phone, guardian_email, guardian_relationship,
                guardian_occupation, academic_qualifications,
                extracurricular_activities, reason_for_application,
                birth_certificate_path, passport_photo_path,
                previous_school_certificate_path, other_documents_path,
                application_status, terms_accepted, ip_address,
                submission_source, how_heard_about_us
            ) VALUES (
                :app_num, :full_name, :gender, :dob,
                :email, :phone, :address, :city, :state, :class_applied,
                :prev_school, :last_class, :guardian_name,
                :guardian_phone, :guardian_email, :guardian_rel,
                :guardian_occ, :academic_qual,
                :extracurricular, :reason,
                :birth_cert, :passport_photo,
                :school_cert, :other_docs,
                'pending', 1, :ip, 'online_portal', :how_heard
            )
        ");
        
        // Bind parameters
        $stmt->execute([
            ':app_num' => $application_number,
            ':full_name' => $_POST['full_name'],
            ':gender' => $_POST['gender'],
            ':dob' => $_POST['date_of_birth'],
            ':email' => $_POST['email'],
            ':phone' => $_POST['phone'],
            ':address' => $_POST['address'],
            ':city' => $_POST['city'] ?? '',
            ':state' => $_POST['state'] ?? '',
            ':class_applied' => $_POST['class_applied'],
            ':prev_school' => $_POST['previous_school'] ?? '',
            ':last_class' => $_POST['last_class_completed'] ?? '',
            ':guardian_name' => $_POST['guardian_name'],
            ':guardian_phone' => $_POST['guardian_phone'],
            ':guardian_email' => $_POST['guardian_email'] ?? '',
            ':guardian_rel' => $_POST['guardian_relationship'],
            ':guardian_occ' => $_POST['guardian_occupation'] ?? '',
            ':academic_qual' => $_POST['academic_qualifications'] ?? '',
            ':extracurricular' => $_POST['extracurricular_activities'] ?? '',
            ':reason' => $_POST['reason_for_application'],
            ':birth_cert' => $uploads['birth_certificate'] ?? '',
            ':passport_photo' => $uploads['passport_photo'] ?? '',
            ':school_cert' => $uploads['school_certificate'] ?? '',
            ':other_docs' => $uploads['other_documents'] ?? '',
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':how_heard' => $_POST['how_heard_about_us'] ?? ''
        ]);
        
        $applicant_id = $pdo->lastInsertId();
        
        // Generate PDF application form
        $pdf_path = generateApplicationPDF($applicant_id, $application_number);
        
        $pdo->commit();
        
        // Send confirmation email (optional)
        sendConfirmationEmail($_POST['email'], $application_number, $_POST['full_name']);
        
        // Return success response
        echo json_encode([
            'success' => true,
            'application_number' => $application_number,
            'applicant_id' => $applicant_id,
            'pdf_url' => $pdf_path,
            'message' => 'Application submitted successfully!'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Application Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error submitting application: ' . $e->getMessage()
        ]);
    }
}

function generateApplicationNumber() {
    $prefix = 'APP';
    $year = date('Y');
    $random = strtoupper(substr(md5(uniqid()), 0, 6));
    return $prefix . $year . $random;
}

function handleFileUploads($appNumber) {
    $upload_dir = '../uploads/applications/' . $appNumber . '/';
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $uploads = [];
    $file_fields = [
        'birth_certificate' => 'birth_cert',
        'passport_photo' => 'passport',
        'school_certificate' => 'school_cert'
    ];
    
    foreach ($file_fields as $field => $prefix) {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === 0) {
            $file_ext = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
            $file_name = $prefix . '_' . time() . '.' . $file_ext;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES[$field]['tmp_name'], $file_path)) {
                $uploads[$field] = $file_path;
            }
        }
    }
    
    // Handle multiple other documents
    if (isset($_FILES['other_documents'])) {
        $other_docs = [];
        foreach ($_FILES['other_documents']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['other_documents']['error'][$key] === 0) {
                $file_ext = pathinfo($_FILES['other_documents']['name'][$key], PATHINFO_EXTENSION);
                $file_name = 'other_' . time() . '_' . $key . '.' . $file_ext;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($tmp_name, $file_path)) {
                    $other_docs[] = $file_path;
                }
            }
        }
        $uploads['other_documents'] = implode(';', $other_docs);
    }
    
    return $uploads;
}

function generateApplicationPDF($applicant_id, $appNumber) {
    require_once 'tcpdf/tcpdf.php';
    
    // Fetch application data
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM applicants WHERE id = ?");
    $stmt->execute([$applicant_id]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Sahab Academy');
    $pdf->SetAuthor('Sahab Academy');
    $pdf->SetTitle('Application Form - ' . $appNumber);
    $pdf->SetSubject('Student Application');
    
    // Add a page
    $pdf->AddPage();
    
    // Logo
    $logo = '../uploads/school/school_logo.jpg';
    if (file_exists($logo)) {
        $pdf->Image($logo, 10, 10, 30, 0, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
    }
    
    // Title
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 20, 'SAHAB ACADEMY - APPLICATION FORM', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 5, 'Application Number: ' . $appNumber, 0, 1, 'C');
    $pdf->Ln(10);
    
    // Personal Information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, '1. PERSONAL INFORMATION', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    
    $personal_info = [
        'Full Name' => $application['full_name'],
        'Date of Birth' => $application['date_of_birth'],
        'Gender' => $application['gender'],
        'Email' => $application['email'],
        'Phone' => $application['phone'],
        'Address' => $application['address']
    ];
    
    foreach ($personal_info as $label => $value) {
        $pdf->Cell(60, 7, $label . ':', 0, 0);
        $pdf->Cell(0, 7, $value, 0, 1);
    }
    
    $pdf->Ln(5);
    
    // Academic Information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, '2. ACADEMIC INFORMATION', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    
    $academic_info = [
        'Class Applying For' => $application['class_applied'],
        'Previous School' => $application['previous_school'],
        'Last Class Completed' => $application['last_class_completed']
    ];
    
    foreach ($academic_info as $label => $value) {
        $pdf->Cell(60, 7, $label . ':', 0, 0);
        $pdf->Cell(0, 7, $value, 0, 1);
    }
    
    $pdf->Ln(5);
    
    // Guardian Information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, '3. GUARDIAN INFORMATION', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    
    $guardian_info = [
        'Guardian Name' => $application['guardian_name'],
        'Relationship' => $application['guardian_relationship'],
        'Phone' => $application['guardian_phone'],
        'Email' => $application['guardian_email'],
        'Occupation' => $application['guardian_occupation']
    ];
    
    foreach ($guardian_info as $label => $value) {
        $pdf->Cell(60, 7, $label . ':', 0, 0);
        $pdf->Cell(0, 7, $value, 0, 1);
    }
    
    $pdf->Ln(10);
    
    // Application Date and Status
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Application Date: ' . date('F j, Y', strtotime($application['application_date'])), 0, 1);
    $pdf->Cell(0, 10, 'Status: PENDING', 0, 1);
    
    $pdf->Ln(15);
    
    // Footer
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 10, 'This is a computer-generated document. No signature required.', 0, 1, 'C');
    $pdf->Cell(0, 10, 'Application submitted via Online Portal on ' . date('Y-m-d H:i:s'), 0, 1, 'C');
    
    // Save PDF file
    $pdf_dir = '../uploads/applications/' . $appNumber . '/';
    if (!file_exists($pdf_dir)) {
        mkdir($pdf_dir, 0777, true);
    }
    
    $pdf_path = $pdf_dir . 'application_form_' . $appNumber . '.pdf';
    $pdf->Output($pdf_path, 'F');
    
    return $pdf_path;
}

function sendConfirmationEmail($email, $appNumber, $name) {
    $to = $email;
    $subject = 'Application Received - Sahab Academy';
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .header { background: #2c3e50; color: white; padding: 20px; }
            .content { padding: 20px; }
            .app-number { background: #f8f9fa; padding: 15px; border-left: 4px solid #3498db; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h2>Sahab Academy</h2>
        </div>
        <div class='content'>
            <h3>Dear $name,</h3>
            <p>Thank you for submitting your application to Sahab Academy!</p>
            
            <div class='app-number'>
                <h4>Your Application Number: <strong>$appNumber</strong></h4>
            </div>
            
            <p><strong>Next Steps:</strong></p>
            <ol>
                <li>Your application is now under review</li>
                <li>Our admissions team will contact you within 5-7 working days</li>
                <li>You can track your application status using your application number</li>
            </ol>
            
            <p><strong>Track Your Application:</strong><br>
            Visit: http://yourdomain.com/track-application.php</p>
            
            <p><strong>Important:</strong> Keep your application number safe. You will need it for all future communication.</p>
            
            <p>Best regards,<br>
            Admissions Office<br>
            Sahab Academy</p>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: admissions@sahabacademy.com" . "\r\n";
    
    mail($to, $subject, $message, $headers);
}
?>