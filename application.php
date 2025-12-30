<?php
session_start();
require_once 'config/db.php';

// Generate application number
function generateApplicationNumber() {
    $prefix = 'APP';
    $year = date('Y');
    $random = strtoupper(substr(md5(uniqid()), 0, 6));
    return $prefix . $year . $random;
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply Online - Sahab Academy</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --light-bg: #f8f9fa;
            --border-radius: 10px;
            --box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .application-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #34495e 100%);
            color: white;
            padding: 3rem 0;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
        }
        
        .application-form-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e0e0e0;
            z-index: 1;
        }
        
        .step {
            position: relative;
            z-index: 2;
            text-align: center;
            width: 100px;
        }
        
        .step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .step.active .step-circle {
            background: var(--secondary-color);
            color: white;
            transform: scale(1.1);
        }
        
        .step.completed .step-circle {
            background: var(--success-color);
            color: white;
        }
        
        .form-step {
            display: none;
        }
        
        .form-step.active {
            display: block;
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .required::after {
            content: " *";
            color: var(--accent-color);
        }
        
        .upload-area {
            border: 2px dashed #ddd;
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .upload-area:hover {
            border-color: var(--secondary-color);
            background: #f8f9ff;
        }
        
        .upload-area.dragover {
            border-color: var(--secondary-color);
            background: #e8f4ff;
        }
        
        .preview-image {
            max-width: 150px;
            max-height: 150px;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, #2980b9 100%);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .progress-container {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 1rem;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            display: none;
        }
        
        .progress-bar {
            height: 5px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success-color) 0%, var(--secondary-color) 100%);
            width: 0%;
            transition: width 0.5s ease;
        }
        
        @media (max-width: 768px) {
            .application-form-container {
                padding: 1rem;
                margin: 0.5rem;
            }
            
            .step {
                width: 70px;
                font-size: 0.9rem;
            }
            
            .step-circle {
                width: 25px;
                height: 25px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <!-- Application Header -->
    <div class="application-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 fw-bold">
                        <i class="fas fa-graduation-cap me-2"></i>Online Application
                    </h1>
                    <p class="lead mb-0">Join Sahab Academy - Where Excellence Meets Opportunity</p>
                    <small>Application Number: <span id="appNumber"><?php echo generateApplicationNumber(); ?></span></small>
                </div>
                <div class="col-md-4 text-end">
                    <div class="d-inline-block bg-white text-dark p-3 rounded">
                        <i class="fas fa-clock text-primary me-2"></i>
                        <span id="timer">30:00</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Application Form -->
    <div class="container">
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step active" data-step="1">
                <div class="step-circle">1</div>
                <div class="step-text">Personal Info</div>
            </div>
            <div class="step" data-step="2">
                <div class="step-circle">2</div>
                <div class="step-text">Academic Info</div>
            </div>
            <div class="step" data-step="3">
                <div class="step-circle">3</div>
                <div class="step-text">Guardian Info</div>
            </div>
            <div class="step" data-step="4">
                <div class="step-circle">4</div>
                <div class="step-text">Documents</div>
            </div>
            <div class="step" data-step="5">
                <div class="step-circle">5</div>
                <div class="step-text">Review & Submit</div>
            </div>
        </div>

        <!-- Form Steps -->
        <form id="applicationForm" class="application-form-container" method="POST" action="process-application.php" enctype="multipart/form-data">
            <!-- Step 1: Personal Information -->
            <div class="form-step active" id="step1">
                <h3 class="mb-4"><i class="fas fa-user-circle me-2 text-primary"></i>Personal Information</h3>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required">Full Name</label>
                        <input type="text" class="form-control" name="full_name" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">Date of Birth</label>
                        <input type="date" class="form-control" name="date_of_birth" max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">Gender</label>
                        <select class="form-select" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">Class Applying For</label>
                        <select class="form-select" name="class_applied" required>
                            <option value="">Select Class</option>
                            <?php
                            // Fetch classes from database
                            $stmt = $pdo->query("SELECT id, class_name FROM classes ORDER BY id");
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                echo "<option value='{$row['class_name']}'>{$row['class_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">Email Address</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">Phone Number</label>
                        <input type="tel" class="form-control" name="phone" pattern="[0-9]{10,15}" required>
                    </div>
                    
                    <div class="col-12">
                        <label class="form-label required">Address</label>
                        <textarea class="form-control" name="address" rows="2" required></textarea>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary" disabled>Previous</button>
                    <button type="button" class="btn btn-primary next-step" data-next="2">Next <i class="fas fa-arrow-right ms-1"></i></button>
                </div>
            </div>

            <!-- Step 2: Academic Information -->
            <div class="form-step" id="step2">
                <h3 class="mb-4"><i class="fas fa-graduation-cap me-2 text-primary"></i>Academic Information</h3>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Previous School</label>
                        <input type="text" class="form-control" name="previous_school">
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Last Class Completed</label>
                        <input type="text" class="form-control" name="last_class_completed">
                    </div>
                    
                    <div class="col-12">
                        <label class="form-label">Academic Qualifications</label>
                        <textarea class="form-control" name="academic_qualifications" rows="3" placeholder="List your academic achievements, awards, etc."></textarea>
                    </div>
                    
                    <div class="col-12">
                        <label class="form-label">Extracurricular Activities</label>
                        <textarea class="form-control" name="extracurricular_activities" rows="3" placeholder="Sports, arts, clubs, etc."></textarea>
                    </div>
                    
                    <div class="col-12">
                        <label class="form-label required">Reason for Application</label>
                        <textarea class="form-control" name="reason_for_application" rows="3" required placeholder="Why do you want to join Sahab Academy?"></textarea>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary prev-step" data-prev="1">
                        <i class="fas fa-arrow-left me-1"></i> Previous
                    </button>
                    <button type="button" class="btn btn-primary next-step" data-next="3">Next <i class="fas fa-arrow-right ms-1"></i></button>
                </div>
            </div>

            <!-- Step 3: Guardian Information -->
            <div class="form-step" id="step3">
                <h3 class="mb-4"><i class="fas fa-users me-2 text-primary"></i>Guardian/Parent Information</h3>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required">Guardian Name</label>
                        <input type="text" class="form-control" name="guardian_name" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">Relationship</label>
                        <select class="form-select" name="guardian_relationship" required>
                            <option value="">Select Relationship</option>
                            <option value="Father">Father</option>
                            <option value="Mother">Mother</option>
                            <option value="Brother">Brother</option>
                            <option value="Sister">Sister</option>
                            <option value="Uncle">Uncle</option>
                            <option value="Aunt">Aunt</option>
                            <option value="Grandfather">Grandfather</option>
                            <option value="Grandmother">Grandmother</option>
                            <option value="Guardian">Guardian</option>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">Guardian Phone</label>
                        <input type="tel" class="form-control" name="guardian_phone" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Guardian Email</label>
                        <input type="email" class="form-control" name="guardian_email">
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Occupation</label>
                        <input type="text" class="form-control" name="guardian_occupation">
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">How did you hear about us?</label>
                        <select class="form-select" name="how_heard_about_us">
                            <option value="">Select Option</option>
                            <option value="Friend/Family">Friend/Family</option>
                            <option value="Social Media">Social Media</option>
                            <option value="Website">Website</option>
                            <option value="Newspaper">Newspaper</option>
                            <option value="Radio/TV">Radio/TV</option>
                            <option value="School Fair">School Fair</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary prev-step" data-prev="2">
                        <i class="fas fa-arrow-left me-1"></i> Previous
                    </button>
                    <button type="button" class="btn btn-primary next-step" data-next="4">Next <i class="fas fa-arrow-right ms-1"></i></button>
                </div>
            </div>

            <!-- Step 4: Documents Upload -->
            <div class="form-step" id="step4">
                <h3 class="mb-4"><i class="fas fa-file-upload me-2 text-primary"></i>Required Documents</h3>
                <p class="text-muted mb-4">Please upload scanned copies of the following documents (Max 2MB each, JPG/PNG/PDF)</p>
                
                <div class="row g-4">
                    <!-- Birth Certificate -->
                    <div class="col-md-6">
                        <div class="upload-area" id="birthCertUpload">
                            <i class="fas fa-file-alt fa-3x text-secondary mb-3"></i>
                            <h5>Birth Certificate</h5>
                            <p class="text-muted small">Upload scanned copy</p>
                            <input type="file" class="d-none" name="birth_certificate" accept=".jpg,.jpeg,.png,.pdf">
                            <div class="preview-container mt-2"></div>
                        </div>
                    </div>
                    
                    <!-- Passport Photo -->
                    <div class="col-md-6">
                        <div class="upload-area" id="photoUpload">
                            <i class="fas fa-camera fa-3x text-secondary mb-3"></i>
                            <h5>Passport Photo</h5>
                            <p class="text-muted small">Recent photo, white background</p>
                            <input type="file" class="d-none" name="passport_photo" accept=".jpg,.jpeg,.png">
                            <div class="preview-container mt-2"></div>
                        </div>
                    </div>
                    
                    <!-- Previous School Certificate -->
                    <div class="col-md-6">
                        <div class="upload-area" id="schoolCertUpload">
                            <i class="fas fa-school fa-3x text-secondary mb-3"></i>
                            <h5>Previous School Certificate</h5>
                            <p class="text-muted small">If applicable</p>
                            <input type="file" class="d-none" name="school_certificate" accept=".jpg,.jpeg,.png,.pdf">
                            <div class="preview-container mt-2"></div>
                        </div>
                    </div>
                    
                    <!-- Other Documents -->
                    <div class="col-md-6">
                        <div class="upload-area" id="otherDocsUpload">
                            <i class="fas fa-folder fa-3x text-secondary mb-3"></i>
                            <h5>Other Documents</h5>
                            <p class="text-muted small">Any additional certificates</p>
                            <input type="file" class="d-none" name="other_documents[]" accept=".jpg,.jpeg,.png,.pdf" multiple>
                            <div class="preview-container mt-2"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Terms and Conditions -->
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="termsCheck" required>
                    <label class="form-check-label" for="termsCheck">
                        I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a> and confirm that all information provided is accurate.
                    </label>
                </div>
                
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary prev-step" data-prev="3">
                        <i class="fas fa-arrow-left me-1"></i> Previous
                    </button>
                    <button type="button" class="btn btn-primary next-step" data-next="5">Next <i class="fas fa-arrow-right ms-1"></i></button>
                </div>
            </div>

            <!-- Step 5: Review and Submit -->
            <div class="form-step" id="step5">
                <h3 class="mb-4"><i class="fas fa-check-circle me-2 text-success"></i>Review Your Application</h3>
                
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Application Summary</h5>
                    </div>
                    <div class="card-body">
                        <div id="reviewContent">
                            <!-- Summary will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Note:</strong> After submission, you'll receive a confirmation email with your application details. Our admissions team will contact you within 5-7 working days.
                </div>
                
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary prev-step" data-prev="4">
                        <i class="fas fa-arrow-left me-1"></i> Previous
                    </button>
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-paper-plane me-2"></i> Submit Application
                    </button>
                </div>
            </div>
        </form>
        
        <!-- Progress Bar for Mobile -->
        <div class="progress-container d-md-none">
            <div class="d-flex justify-content-between mb-2">
                <small>Progress</small>
                <small><span id="currentStep">1</span>/5</small>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
        </div>
    </div>

    <!-- Terms and Conditions Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Terms and Conditions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Add your terms and conditions here -->
                    <p>Terms and conditions content...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-check-circle fa-5x text-success"></i>
                    </div>
                    <h3 class="mb-3">Application Submitted!</h3>
                    <p class="text-muted mb-4">Your application has been received successfully.</p>
                    <div class="alert alert-light mb-4">
                        <strong>Application Number:</strong><br>
                        <span class="h5" id="finalAppNumber"></span>
                    </div>
                    <p class="small text-muted">Check your email for confirmation. You can track your application status using your application number.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const steps = document.querySelectorAll('.step');
            const formSteps = document.querySelectorAll('.form-step');
            const nextButtons = document.querySelectorAll('.next-step');
            const prevButtons = document.querySelectorAll('.prev-step');
            const progressFill = document.getElementById('progressFill');
            const currentStepEl = document.getElementById('currentStep');
            const timerEl = document.getElementById('timer');
            let currentStep = 1;
            let timeLeft = 1800; // 30 minutes in seconds

            // Timer countdown
            const timer = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    alert('Your session has expired. Please refresh the page to start again.');
                    return;
                }
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerEl.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                timeLeft--;
                
                // Change color when less than 5 minutes
                if (timeLeft < 300) {
                    timerEl.parentElement.style.background = 'linear-gradient(135deg, #e74c3c 0%, #c0392b 100%)';
                }
            }, 1000);

            // Update steps
            function updateSteps(step) {
                // Update step indicator
                steps.forEach(s => {
                    const stepNum = parseInt(s.dataset.step);
                    s.classList.remove('active', 'completed');
                    if (stepNum < step) {
                        s.classList.add('completed');
                    } else if (stepNum === step) {
                        s.classList.add('active');
                    }
                });

                // Update form steps
                formSteps.forEach(fs => {
                    fs.classList.remove('active');
                    if (parseInt(fs.id.replace('step', '')) === step) {
                        fs.classList.add('active');
                    }
                });

                // Update progress bar
                const progress = ((step - 1) / (steps.length - 1)) * 100;
                progressFill.style.width = `${progress}%`;
                currentStepEl.textContent = step;
                
                // Show/hide progress bar on mobile
                const progressContainer = document.querySelector('.progress-container');
                if (window.innerWidth < 768) {
                    progressContainer.style.display = 'block';
                }
                
                currentStep = step;
            }

            // Next step button
            nextButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const nextStep = parseInt(this.dataset.next);
                    if (validateStep(currentStep)) {
                        updateSteps(nextStep);
                        if (nextStep === 5) {
                            generateReview();
                        }
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                });
            });

            // Previous step button
            prevButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const prevStep = parseInt(this.dataset.prev);
                    updateSteps(prevStep);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            });

            // Validate current step
            function validateStep(step) {
                const currentFormStep = document.getElementById(`step${step}`);
                const inputs = currentFormStep.querySelectorAll('[required]');
                let isValid = true;

                inputs.forEach(input => {
                    if (!input.value.trim()) {
                        input.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        input.classList.remove('is-invalid');
                    }
                });

                if (!isValid) {
                    alert('Please fill all required fields before proceeding.');
                }

                return isValid;
            }

            // File upload functionality
            const uploadAreas = document.querySelectorAll('.upload-area');
            uploadAreas.forEach(area => {
                const input = area.querySelector('input[type="file"]');
                const previewContainer = area.querySelector('.preview-container');

                area.addEventListener('click', () => input.click());
                area.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    area.classList.add('dragover');
                });
                area.addEventListener('dragleave', () => area.classList.remove('dragover'));
                area.addEventListener('drop', (e) => {
                    e.preventDefault();
                    area.classList.remove('dragover');
                    input.files = e.dataTransfer.files;
                    handleFiles(input.files, previewContainer);
                });

                input.addEventListener('change', () => handleFiles(input.files, previewContainer));
            });

            function handleFiles(files, container) {
                container.innerHTML = '';
                Array.from(files).forEach(file => {
                    if (file.size > 2 * 1024 * 1024) {
                        alert('File size must be less than 2MB');
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = 'd-inline-block me-2 mb-2';
                        
                        if (file.type.startsWith('image/')) {
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.className = 'preview-image';
                            div.appendChild(img);
                        } else {
                            const icon = document.createElement('i');
                            icon.className = 'fas fa-file-pdf text-danger fa-2x';
                            div.appendChild(icon);
                        }
                        
                        const name = document.createElement('div');
                        name.className = 'small text-truncate';
                        name.style.maxWidth = '150px';
                        name.textContent = file.name;
                        div.appendChild(name);
                        
                        container.appendChild(div);
                    };
                    reader.readAsDataURL(file);
                });
            }

            // Generate review summary
            function generateReview() {
                const form = document.getElementById('applicationForm');
                const formData = new FormData(form);
                const reviewContent = document.getElementById('reviewContent');
                
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Personal Information</h6>
                            <p><strong>Name:</strong> ${formData.get('full_name')}</p>
                            <p><strong>Date of Birth:</strong> ${formData.get('date_of_birth')}</p>
                            <p><strong>Gender:</strong> ${formData.get('gender')}</p>
                            <p><strong>Class:</strong> ${formData.get('class_applied')}</p>
                        </div>
                        <div class="col-md-6">
                            <h6>Contact Information</h6>
                            <p><strong>Email:</strong> ${formData.get('email')}</p>
                            <p><strong>Phone:</strong> ${formData.get('phone')}</p>
                            <p><strong>Address:</strong> ${formData.get('address')}</p>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-12">
                            <h6>Application Details</h6>
                            <p><strong>Reason for Application:</strong><br>${formData.get('reason_for_application')}</p>
                            <p><strong>Guardian:</strong> ${formData.get('guardian_name')} (${formData.get('guardian_relationship')})</p>
                        </div>
                    </div>
                    
                `;
                
                reviewContent.innerHTML = html;
            }

            // form submission handler
document.getElementById('applicationForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    if (!document.getElementById('termsCheck').checked) {
        alert('Please accept the terms and conditions.');
        return;
    }

    // Show loading state
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';
    submitBtn.disabled = true;

    // Collect form data
    const formData = new FormData(this);
    formData.append('application_number', document.getElementById('appNumber').textContent);

    try {
        const response = await fetch('process-application.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            document.getElementById('finalAppNumber').textContent = result.application_number;
            
            // Show success modal
            const modal = new bootstrap.Modal(document.getElementById('successModal'));
            modal.show();
            
            // Add download button to success modal
            const modalBody = document.querySelector('#successModal .modal-body');
            const downloadBtn = document.createElement('a');
            downloadBtn.href = result.pdf_url;
            downloadBtn.className = 'btn btn-primary mt-3';
            downloadBtn.innerHTML = '<i class="fas fa-download me-2"></i>Download Application Form (PDF)';
            downloadBtn.target = '_blank';
            downloadBtn.download = `application_${result.application_number}.pdf`;
            
            if (modalBody.querySelector('.btn') === null) {
                modalBody.appendChild(downloadBtn);
            }
            
            // Store application number in localStorage for easy tracking
            localStorage.setItem('lastApplicationNumber', result.application_number);
            
        } else {
            alert('Error: ' + result.message);
            submitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Submit Application';
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
        submitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Submit Application';
        submitBtn.disabled = false;
    }
});
    </script>
</body>
</html>