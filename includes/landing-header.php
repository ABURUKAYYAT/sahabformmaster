<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = $pageTitle ?? 'iSchool';
$activePage = $activePage ?? '';

$isLoggedIn = isset($_SESSION['user_id'], $_SESSION['role']);
$dashboardUrl = '';
if ($isLoggedIn) {
    switch ($_SESSION['role']) {
        case 'principal':
            $dashboardUrl = 'admin/index.php';
            break;
        case 'teacher':
            $dashboardUrl = 'teacher/index.php';
            break;
        case 'clerk':
            $dashboardUrl = 'clerk/index.php';
            break;
        case 'student':
            $dashboardUrl = 'student/index.php';
            break;
        default:
            $dashboardUrl = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/landing.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="landing">
    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="index.php" aria-label="iSchool home">
                <span class="brand-mark">iS</span>
                <span class="brand-text">iSchool</span>
            </a>

            <button class="nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="primaryNav">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav class="site-nav" id="primaryNav">
                <a href="index.php#features" class="<?php echo $activePage === 'features' ? 'is-active' : ''; ?>">Features</a>
                <a href="index.php#solutions" class="<?php echo $activePage === 'solutions' ? 'is-active' : ''; ?>">Solutions</a>
                <a href="index.php#workflow" class="<?php echo $activePage === 'workflow' ? 'is-active' : ''; ?>">Workflow</a>
                <a href="index.php#resources" class="<?php echo $activePage === 'resources' ? 'is-active' : ''; ?>">Resources</a>
                <a href="pricing.php" class="<?php echo $activePage === 'pricing' ? 'is-active' : ''; ?>">Pricing</a>
                <a href="about.php" class="<?php echo $activePage === 'about' ? 'is-active' : ''; ?>">About</a>
                <a href="contact.php" class="<?php echo $activePage === 'contact' ? 'is-active' : ''; ?>">Contact</a>
            </nav>

            <div class="nav-actions">
                <?php if ($isLoggedIn && $dashboardUrl !== ''): ?>
                    <a class="btn btn-ghost" href="<?php echo htmlspecialchars($dashboardUrl); ?>">Dashboard</a>
                <?php else: ?>
                    <a class="btn btn-ghost" href="login.php">Login</a>
                <?php endif; ?>
                <a class="btn btn-primary" href="contact.php">Request Demo</a>
            </div>
        </div>
    </header>
