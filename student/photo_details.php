<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: index.php');
    exit;
}

$student_id = (int) $_SESSION['student_id'];
$current_school_id = get_current_school_id();

$viewer_stmt = $pdo->prepare('
    SELECT s.class_id, c.class_name
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
    WHERE s.id = ? AND s.school_id = ?
    LIMIT 1
');
$viewer_stmt->execute([$student_id, $current_school_id]);
$current_student = $viewer_stmt->fetch(PDO::FETCH_ASSOC);
if (!$current_student) {
    session_destroy();
    header('Location: index.php?error=access_denied');
    exit;
}

$current_class_id = (int) ($current_student['class_id'] ?? 0);
$current_class_name = trim((string) ($current_student['class_name'] ?? 'My Class'));

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$id = (int) ($_GET['id'] ?? 0);
if (!in_array($type, ['student', 'teacher'], true) || $id <= 0) {
    header('Location: photo_album.php');
    exit;
}

$person_data = null;
if ($type === 'student') {
    $person_stmt = $pdo->prepare('
        SELECT id, full_name, admission_no, passport_photo, gender, phone, address,
               guardian_name, guardian_phone, guardian_email, guardian_relation,
               dob, enrollment_date, student_type, blood_group, medical_conditions, allergies
        FROM students
        WHERE id = ? AND school_id = ? AND class_id = ? AND is_active = 1
        LIMIT 1
    ');
    $person_stmt->execute([$id, $current_school_id, $current_class_id]);
    $person_data = $person_stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $person_stmt = $pdo->prepare("
        SELECT id, full_name, designation, department, qualification, profile_image,
               date_of_birth, date_employed, phone, address, emergency_contact, emergency_phone
        FROM users
        WHERE id = ? AND school_id = ? AND role = 'teacher' AND is_active = 1
        LIMIT 1
    ");
    $person_stmt->execute([$id, $current_school_id]);
    $person_data = $person_stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$person_data) {
    header('Location: photo_album.php');
    exit;
}

$normalize_photo = static function (?string $path, string $fallback = '../assets/images/default-avatar.png'): string {
    $value = trim((string) $path);
    if ($value === '') {
        return $fallback;
    }

    if (preg_match('/^(https?:\/\/|\/|\.\.\/|data:)/i', $value) === 1) {
        return $value;
    }

    return '../' . ltrim($value, '/');
};

$display = static function ($value, string $fallback = 'Not specified'): string {
    $text = trim((string) ($value ?? ''));
    return $text !== '' ? $text : $fallback;
};

$format_date = static function ($value): string {
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return 'Not specified';
    }

    $ts = strtotime($text);
    if ($ts === false) {
        return 'Not specified';
    }

    return date('M j, Y', $ts);
};

$profile_image = $type === 'student'
    ? $normalize_photo($person_data['passport_photo'] ?? null)
    : $normalize_photo($person_data['profile_image'] ?? null);

$profile_name = (string) ($person_data['full_name'] ?? 'Profile');
$profile_title = $type === 'student'
    ? $display($person_data['student_type'] ?? 'Student', 'Student')
    : $display($person_data['designation'] ?? 'Teacher', 'Teacher');

$header_badge = $type === 'student' ? 'Student Profile' : 'Teacher Profile';
$pageTitle = $profile_name . ' | ' . $header_badge . ' | ' . get_school_display_name();

$extraHead = <<<'HTML'
<style>
    .student-layout{overflow-x:hidden}
    .student-photo-details-page section{padding-top:0;padding-bottom:0}
    .dashboard-card{border-radius:1.5rem;border:1px solid rgba(15,31,45,.08);background:#fff;box-shadow:0 10px 24px rgba(15,31,51,.08)}

    .student-sidebar-overlay{position:fixed;inset:0;background:rgba(2,6,23,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:30}
    .sidebar{position:fixed;top:73px;left:0;width:16rem;height:calc(100vh - 73px);background:#fff;border-right:1px solid rgba(15,31,45,.1);box-shadow:0 18px 40px rgba(15,31,51,.12);transform:translateX(-106%);transition:transform .22s ease;z-index:40;overflow-y:auto}
    body.sidebar-open .sidebar{transform:translateX(0)}
    body.sidebar-open .student-sidebar-overlay{opacity:1;pointer-events:auto}
    .sidebar-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid rgba(15,31,45,.08)}
    .sidebar-header h3{margin:0;font-size:1rem;font-weight:700;color:#0f1f2d}
    .sidebar-close{border:0;border-radius:.55rem;padding:.35rem .55rem;background:rgba(15,31,45,.08);color:#334155;font-size:.8rem;line-height:1;cursor:pointer}
    .sidebar-nav{padding:.8rem}
    .nav-list{list-style:none;margin:0;padding:0;display:grid;gap:.2rem}
    .nav-link{display:flex;align-items:center;gap:.65rem;border-radius:.75rem;padding:.62rem .72rem;color:#475569;font-size:.88rem;font-weight:600;text-decoration:none;transition:background-color .15s ease,color .15s ease}
    .nav-link:hover{background:rgba(22,133,117,.1);color:#0f6a5c}
    .nav-link.active{background:rgba(22,133,117,.14);color:#0f6a5c}
    .nav-icon{width:1rem;text-align:center}
    #studentMain{min-width:0}

    .profile-hero{position:relative;overflow:hidden;background:linear-gradient(130deg,#0f1f2d 0%,#0f6a5c 55%,#0ea5e9 100%);color:#fff}
    .profile-hero::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 12% 22%,rgba(255,255,255,.2),transparent 40%);pointer-events:none}
    .profile-hero > *{position:relative;z-index:2}
    .profile-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .78rem;border-radius:999px;border:1px solid rgba(255,255,255,.32);background:rgba(255,255,255,.14);font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}

    .profile-hero-wrap{display:grid;gap:1.2rem;align-items:center}
    @media (min-width:768px){
        .profile-hero-wrap{grid-template-columns:160px 1fr auto}
    }

    .profile-avatar{position:relative;width:148px;height:148px;border-radius:1.35rem;overflow:hidden;border:4px solid rgba(255,255,255,.42);box-shadow:0 18px 40px rgba(2,6,23,.32)}
    .profile-avatar img{width:100%;height:100%;object-fit:cover}

    .profile-gender-badge{position:absolute;bottom:-7px;right:-7px;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:999px;border:2px solid #fff;font-size:.88rem}
    .badge-male{background:#dbeafe;color:#1d4ed8}
    .badge-female{background:#fce7f3;color:#be185d}
    .badge-neutral{background:#e2e8f0;color:#334155}

    .profile-name{margin-top:.6rem;font-size:2.05rem;line-height:1.15;color:#fff}
    .profile-role{margin-top:.3rem;font-size:1rem;color:rgba(255,255,255,.86)}
    .profile-meta{margin-top:.55rem;font-size:.82rem;color:rgba(255,255,255,.82)}

    .profile-actions{display:flex;flex-wrap:wrap;gap:.65rem}
    .profile-back-btn{display:inline-flex;align-items:center;gap:.5rem;padding:.55rem .95rem;border-radius:999px;border:1px solid rgba(255,255,255,.5);background:rgba(255,255,255,.12);color:#fff;text-decoration:none;font-size:.82rem;font-weight:700;transition:background-color .2s ease,transform .2s ease}
    .profile-back-btn:hover{background:rgba(255,255,255,.22);transform:translateY(-1px)}

    .profile-section-header{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1rem}
    .profile-section-header h2{font-size:1.18rem;color:#0f1f2d}
    .profile-section-header p{font-size:.85rem;color:#64748b}

    .profile-info-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}
    .profile-info-item{border-radius:1rem;border:1px solid rgba(15,31,45,.1);background:#fff;padding:.85rem .92rem}
    .profile-info-label{display:block;font-size:.69rem;font-weight:700;letter-spacing:.045em;text-transform:uppercase;color:#64748b}
    .profile-info-value{margin-top:.28rem;font-size:.92rem;color:#0f1f2d;line-height:1.5}

    .profile-callout{display:flex;align-items:center;gap:.7rem;border-radius:1rem;background:#f8fafc;border:1px solid rgba(15,31,45,.1);padding:.85rem 1rem;color:#334155;font-size:.85rem}
    .profile-callout i{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:999px;background:#e2e8f0;color:#0f6a5c}

    @media (min-width:768px){
        #studentMain{padding-left:16rem !important}
        .sidebar{transform:translateX(0);top:73px;height:calc(100vh - 73px)}
        .sidebar-close{display:none}
        .student-sidebar-overlay{display:none}
    }
    @media (max-width:767.98px){
        #studentMain{padding-left:0 !important}
    }
    @media (max-width:640px){
        .student-photo-details-page .dashboard-card{padding:1.05rem !important}
        .profile-avatar{width:118px;height:118px;border-radius:1rem}
        .profile-name{font-size:1.6rem}
        .profile-hero-wrap{justify-items:start}
    }
</style>
HTML;

require_once __DIR__ . '/../includes/student_header.php';
?>
<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-photo-details-page space-y-6">
    <section class="dashboard-card profile-hero p-6 sm:p-8" data-reveal>
        <span class="profile-chip"><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($header_badge); ?></span>

        <div class="profile-hero-wrap mt-4">
            <?php
            $gender = strtolower(trim((string) ($person_data['gender'] ?? '')));
            $gender_icon = 'fa-user';
            $gender_badge = 'badge-neutral';
            if ($gender === 'male') {
                $gender_icon = 'fa-mars';
                $gender_badge = 'badge-male';
            } elseif ($gender === 'female') {
                $gender_icon = 'fa-venus';
                $gender_badge = 'badge-female';
            }
            ?>
            <div class="profile-avatar">
                <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="<?php echo htmlspecialchars($profile_name); ?>">
                <?php if ($type === 'student'): ?>
                    <span class="profile-gender-badge <?php echo $gender_badge; ?>" aria-hidden="true"><i class="fas <?php echo $gender_icon; ?>"></i></span>
                <?php endif; ?>
            </div>

            <div>
                <h1 class="profile-name font-display"><?php echo htmlspecialchars($profile_name); ?></h1>
                <p class="profile-role"><?php echo htmlspecialchars($profile_title); ?></p>
                <p class="profile-meta">
                    <?php if ($type === 'student'): ?>
                        Admission: <?php echo htmlspecialchars($display($person_data['admission_no'] ?? null, 'Not assigned')); ?>
                    <?php else: ?>
                        Department: <?php echo htmlspecialchars($display($person_data['department'] ?? null, 'Not assigned')); ?>
                    <?php endif; ?>
                </p>
            </div>

            <div class="profile-actions">
                <a href="photo_album.php" class="profile-back-btn"><i class="fas fa-arrow-left"></i> Back to album</a>
                <a href="dashboard.php" class="profile-back-btn"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <section class="dashboard-card p-6" data-reveal data-reveal-delay="70">
        <div class="profile-section-header">
            <div>
                <h2 class="font-display">Personal Information</h2>
                <p>
                    <?php if ($type === 'student'): ?>
                        Verified profile records visible within <?php echo htmlspecialchars($current_class_name); ?>.
                    <?php else: ?>
                        Verified profile records available to students in your school.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="profile-info-grid">
            <?php if ($type === 'student'): ?>
                <article class="profile-info-item"><span class="profile-info-label">Gender</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['gender'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Date of Birth</span><p class="profile-info-value"><?php echo htmlspecialchars($format_date($person_data['dob'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Student Type</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['student_type'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Blood Group</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['blood_group'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Enrollment Date</span><p class="profile-info-value"><?php echo htmlspecialchars($format_date($person_data['enrollment_date'] ?? null)); ?></p></article>
            <?php else: ?>
                <article class="profile-info-item"><span class="profile-info-label">Designation</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['designation'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Department</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['department'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Qualification</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['qualification'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Date of Birth</span><p class="profile-info-value"><?php echo htmlspecialchars($format_date($person_data['date_of_birth'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Date Employed</span><p class="profile-info-value"><?php echo htmlspecialchars($format_date($person_data['date_employed'] ?? null)); ?></p></article>
            <?php endif; ?>
        </div>
    </section>

    <section class="dashboard-card p-6" data-reveal data-reveal-delay="110">
        <div class="profile-section-header">
            <div>
                <h2 class="font-display">Contact Information</h2>
                <p>Basic communication details available to your student account.</p>
            </div>
        </div>

        <div class="profile-info-grid">
            <article class="profile-info-item"><span class="profile-info-label">Phone</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['phone'] ?? null)); ?></p></article>
            <article class="profile-info-item"><span class="profile-info-label">Address</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['address'] ?? null)); ?></p></article>
        </div>
    </section>

    <?php if ($type === 'student'): ?>
        <section class="dashboard-card p-6" data-reveal data-reveal-delay="140">
            <div class="profile-section-header">
                <div>
                    <h2 class="font-display">Guardian Information</h2>
                    <p>Emergency and guardian contacts attached to this student profile.</p>
                </div>
            </div>

            <div class="profile-info-grid">
                <article class="profile-info-item"><span class="profile-info-label">Guardian Name</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['guardian_name'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Guardian Phone</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['guardian_phone'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Guardian Email</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['guardian_email'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Relationship</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['guardian_relation'] ?? null)); ?></p></article>
            </div>
        </section>

        <section class="dashboard-card p-6" data-reveal data-reveal-delay="170">
            <div class="profile-section-header">
                <div>
                    <h2 class="font-display">Medical Information</h2>
                    <p>Health notes provided for school support and care workflows.</p>
                </div>
            </div>

            <div class="profile-info-grid">
                <article class="profile-info-item"><span class="profile-info-label">Medical Conditions</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['medical_conditions'] ?? null, 'None specified')); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Allergies</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['allergies'] ?? null, 'None specified')); ?></p></article>
            </div>
        </section>
    <?php else: ?>
        <section class="dashboard-card p-6" data-reveal data-reveal-delay="140">
            <div class="profile-section-header">
                <div>
                    <h2 class="font-display">Emergency Contact</h2>
                    <p>Backup contact details associated with this teacher profile.</p>
                </div>
            </div>

            <div class="profile-info-grid">
                <article class="profile-info-item"><span class="profile-info-label">Contact Name</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['emergency_contact'] ?? null)); ?></p></article>
                <article class="profile-info-item"><span class="profile-info-label">Contact Phone</span><p class="profile-info-value"><?php echo htmlspecialchars($display($person_data['emergency_phone'] ?? null)); ?></p></article>
            </div>
        </section>
    <?php endif; ?>

    <section class="dashboard-card p-5" data-reveal data-reveal-delay="200">
        <div class="profile-callout">
            <i class="fas fa-shield-alt" aria-hidden="true"></i>
            <span>This profile is scoped to your school account and filtered to authorized records only.</span>
        </div>
    </section>
</main>

<script>
const sidebarOverlay = document.getElementById('studentSidebarOverlay');
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
}
if (window.matchMedia('(min-width: 768px)').matches) {
    document.body.classList.remove('sidebar-open');
}
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
