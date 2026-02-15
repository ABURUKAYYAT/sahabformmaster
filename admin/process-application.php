<?php
session_start();
require_once '../config/db.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = [
            'full_name', 'gender', 'date_of_birth', 'email', 'phone', 'address',
            'class_applied', 'guardian_name', 'guardian_phone', 
            'guardian_relationship', 'reason_for_application'
        ];
        
        $errors = [];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
            }
        }
        
        if (!empty($errors)) {
            echo json_encode([
                'success' => false,
                'message' => 'Validation Error',
                'errors' => $errors
            ]);
            exit;
        }
        
        $pdo->beginTransaction();
        
        // Generate application number
        $applicationNumber = 'APP' . date('Y') . strtoupper(substr(md5(uniqid()), 0, 6));
        
        // Handle file uploads
        $uploads = [];
        $uploadDir = '../uploads/applicants/' . date('Y/m/');
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Process uploaded files
        $files = ['birth_certificate', 'passport_photo', 'school_certificate', 'other_documents'];
        foreach ($files as $file) {
            if (isset($_FILES[$file]) && $_FILES[$file]['error'] === 0) {
                $filename = uniqid() . '_' . basename($_FILES[$file]['name']);
                $targetPath = $uploadDir . $filename;
                
                if (move_uploaded_file($_FILES[$file]['tmp_name'], $targetPath)) {
                    $uploads[$file] = $targetPath;
                }
            }
        }
        
        // Insert applicant data with validated/cleaned data
        $stmt = $pdo->prepare("
            INSERT INTO applicants (
                application_number, full_name, gender, date_of_birth, nationality, religion,
                email, phone, address, city, state, class_applied,
                previous_school, last_class_completed, guardian_name, guardian_phone,
                guardian_email, guardian_relationship, guardian_occupation,
                academic_qualifications, extracurricular_activities, reason_for_application,
                birth_certificate_path, passport_photo_path, previous_school_certificate_path,
                application_status, ip_address, submission_source, how_heard_about_us, terms_accepted
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");
        
        // Clean and validate input data
        $full_name = trim($_POST['full_name']);
        $gender = trim($_POST['gender']);
        $date_of_birth = $_POST['date_of_birth'];
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $class_applied = trim($_POST['class_applied']);
        $guardian_name = trim($_POST['guardian_name']);
        $guardian_phone = trim($_POST['guardian_phone']);
        $guardian_relationship = trim($_POST['guardian_relationship']);
        $reason_for_application = trim($_POST['reason_for_application']);
        
        // Optional fields with null coalescing
        $nationality = $_POST['nationality'] ?? null;
        $religion = $_POST['religion'] ?? null;
        $city = $_POST['city'] ?? null;
        $state = $_POST['state'] ?? null;
        $previous_school = $_POST['previous_school'] ?? null;
        $last_class_completed = $_POST['last_class_completed'] ?? null;
        $guardian_email = $_POST['guardian_email'] ?? null;
        $guardian_occupation = $_POST['guardian_occupation'] ?? null;
        $academic_qualifications = $_POST['academic_qualifications'] ?? null;
        $extracurricular_activities = $_POST['extracurricular_activities'] ?? null;
        $how_heard_about_us = $_POST['how_heard_about_us'] ?? null;
        
        $stmt->execute([
            $applicationNumber,
            $full_name,
            $gender,
            $date_of_birth,
            $nationality,
            $religion,
            $email,
            $phone,
            $address,
            $city,
            $state,
            $class_applied,
            $previous_school,
            $last_class_completed,
            $guardian_name,
            $guardian_phone,
            $guardian_email,
            $guardian_relationship,
            $guardian_occupation,
            $academic_qualifications,
            $extracurricular_activities,
            $reason_for_application,
            $uploads['birth_certificate'] ?? null,
            $uploads['passport_photo'] ?? null,
            $uploads['school_certificate'] ?? null,
            'pending',
            $_SERVER['REMOTE_ADDR'],
            'online_portal',
            $how_heard_about_us,
            1
        ]);
        
        $applicantId = $pdo->lastInsertId();
        
        // Send confirmation email
        sendConfirmationEmail($email, $applicationNumber, $full_name);
        
        $pdo->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'application_number' => $applicationNumber,
            'message' => 'Application submitted successfully!'
        ]);
        
    } catch(Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Application Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error processing your application. Please try again.'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
}

function sendConfirmationEmail($email, $appNumber, $name) {
    $subject = "Application Received - Sahab Academy";
    $message = "
        <h2>Application Received</h2>
        <p>Dear $name,</p>
        <p>Thank you for applying to Sahab Academy.</p>
        <p><strong>Your Application Number:</strong> $appNumber</p>
        <p>We will review your application and contact you within 5-7 working days.</p>
        <p>You can track your application status using your application number.</p>
        <br>
        <p>Best regards,<br>Sahab Academy Admissions Team</p>
    ";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: admissions@sahabacademy.com\r\n";
    
    mail($email, $subject, $message, $headers);
}
?>
