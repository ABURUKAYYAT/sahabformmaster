<?php
// student/index.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $admission_no = trim($_POST['admission_no']);
    $full_name = $_POST['student_name'];
 
    if (empty($admission_no) || empty($full_name)) {
        $error = "Please enter both admission number and name.";
    } else {
        // Prepare SQL to find student
        $stmt = $pdo->prepare("SELECT * FROM students WHERE admission_no = :admission_no");
        $stmt->execute(['admission_no' => $admission_no]);
        $student = $stmt->fetch();

        // Verify student exists and name matches
        if ($student && $full_name === $student['full_name']) {
            // Login Success
            $_SESSION['student_id'] = $student['id'];
            $_SESSION['admission_no'] = $student['admission_no'];
            $_SESSION['student_name'] = $student['name'];
            $_SESSION['school_id'] = $student['school_id'];

            // Redirect to student dashboard
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Invalid admission number or name.";
        }
    }
}

?>
<?php require_once __DIR__ . '/../includes/student_header.php'; ?>

<main class="auth-shell max-w-lg mx-auto">
        <div class="auth-card bg-white shadow-md rounded-lg p-6">
            <div class="auth-header mb-4 text-center">
                <div class="auth-brand mb-2">
                    <span class="brand-mark bg-teal-600 text-white rounded-md px-2 py-1 font-bold">iS</span>
                    <span class="brand-text font-semibold ml-2">iSchool</span>
                </div>
                <h1 class="auth-title text-xl font-semibold">Student Access</h1>
                <p class="auth-subtitle text-sm text-slate-500">Sign in to view your academic dashboard.</p>
            </div>

            <?php if($error): ?>
                <div class="auth-error mb-3 text-sm text-red-600">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST" class="auth-form space-y-4">
                <div>
                    <label for="admission_no" class="block text-sm font-medium text-slate-700">Admission Number</label>
                    <input type="text" name="admission_no" id="admission_no" class="mt-1 block w-full rounded-md border-gray-200 shadow-sm" placeholder="Enter your admission number" required autofocus>
                </div>

                <div>
                    <label for="student_name" class="block text-sm font-medium text-slate-700">Full Name</label>
                    <input type="text" name="student_name" id="student_name" class="mt-1 block w-full rounded-md border-gray-200 shadow-sm" placeholder="Enter your full name" required>
                </div>

                <button type="submit" class="btn btn-primary w-full">Login securely</button>
            </form>

            <div class="auth-footer mt-4 text-center text-sm">
                <a href="../login.php" class="auth-link text-teal-600">Staff login</a>
                <span class="mx-2">&middot;</span>
                <a href="../index.php" class="auth-link text-teal-600">Back to homepage</a>
            </div>
        </div>
    </main>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
