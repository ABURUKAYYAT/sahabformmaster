<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = $pageTitle ?? ('Student | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool'));
$headerStudentName = trim((string) ($_SESSION['student_name'] ?? $_SESSION['full_name'] ?? ($student_name ?? 'Student')));
if ($headerStudentName === '') {
    $headerStudentName = 'Student';
}
$headerAdmissionNo = trim((string) ($_SESSION['admission_no'] ?? ($admission_no ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?php
    // Allow pages to inject additional head content (page-specific CSS, inline styles)
    if (!empty($extraHead)) {
        echo $extraHead;
    }
    ?>
    <meta name="theme-color" content="#0f766e">
</head>
<body class="student-layout min-h-screen bg-slate-50 text-slate-900">
    <header class="site-header bg-white border-b">
        <div class="container mx-auto px-4 py-3 flex items-center justify-between gap-4">
            <a class="brand flex items-center gap-3" href="../index.php" aria-label="iSchool home">
                <span class="brand-mark bg-teal-600 text-white rounded-md px-2 py-1 font-bold">iS</span>
                <span class="brand-text font-semibold">iSchool</span>
            </a>

            <div class="flex items-center gap-3">
                <button id="mobileMenuToggle" class="md:hidden p-2 rounded-md text-slate-600 hover:bg-slate-100" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
                <a class="btn btn-outline md:hidden" href="logout.php" aria-label="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
                <div class="hidden md:flex items-center gap-3 text-sm">
                    <span class="hidden lg:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($headerStudentName); ?></span>
                    <div class="text-right">
                        <div class="text-xs uppercase tracking-wide text-slate-500">Student Portal</div>
                        <div class="font-semibold text-ink-900"><?php echo htmlspecialchars($headerStudentName); ?></div>
                        <?php if ($headerAdmissionNo !== ''): ?>
                            <div class="text-xs text-slate-500">Admission <?php echo htmlspecialchars($headerAdmissionNo); ?></div>
                        <?php endif; ?>
                    </div>
                    <a class="btn btn-outline" href="dashboard.php">Dashboard</a>
                    <a class="btn btn-primary" href="logout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <?php include __DIR__ . '/student_sidebar.php'; ?>

    <div id="studentMain" class="md:pl-64">
        <div class="container mx-auto px-4 py-6">
