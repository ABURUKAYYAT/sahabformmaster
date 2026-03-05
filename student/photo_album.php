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

$class_id = (int) ($current_student['class_id'] ?? 0);
$class_name = trim((string) ($current_student['class_name'] ?? 'My Class'));

$teachers_stmt = $pdo->prepare("
    SELECT id, full_name, designation, department, profile_image
    FROM users
    WHERE role = 'teacher' AND is_active = 1 AND school_id = ?
    ORDER BY full_name ASC
");
$teachers_stmt->execute([$current_school_id]);
$teachers = $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);

$classmates = [];
if ($class_id > 0) {
    $classmates_stmt = $pdo->prepare('
        SELECT id, full_name, admission_no, passport_photo, gender
        FROM students
        WHERE class_id = ? AND is_active = 1 AND school_id = ?
        ORDER BY full_name ASC
    ');
    $classmates_stmt->execute([$class_id, $current_school_id]);
    $classmates = $classmates_stmt->fetchAll(PDO::FETCH_ASSOC);
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

$total_teachers = count($teachers);
$total_classmates = count($classmates);
$total_uploaded_photos = count(array_filter(
    $classmates,
    static fn(array $mate): bool => trim((string) ($mate['passport_photo'] ?? '')) !== ''
));

$pageTitle = 'Class Photo Album | ' . $class_name . ' | ' . get_school_display_name();
$extraHead = <<<'HTML'
<style>
    .student-layout{overflow-x:hidden}
    .student-album-page section > * + *{margin-top:1rem}
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

    .album-hero{position:relative;overflow:hidden;background:linear-gradient(125deg,#0f6a5c 0%,#168575 48%,#0ea5e9 100%);color:#fff}
    .album-hero::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 90% 15%,rgba(255,255,255,.24),transparent 42%);pointer-events:none}
    .album-hero > *{position:relative;z-index:2}
    .album-chip{display:inline-flex;align-items:center;gap:.35rem;border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.16);border-radius:999px;padding:.35rem .8rem;font-size:.74rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
    .album-hero h1{margin-top:.85rem;color:#fff}
    .album-hero p{color:rgba(255,255,255,.88)}

    .album-stat-card{border-radius:1rem;border:1px solid rgba(255,255,255,.34);background:linear-gradient(135deg,rgba(15,31,45,.28),rgba(15,106,92,.22));backdrop-filter:blur(4px);padding:1rem 1.05rem}
    .album-stat-label{font-size:.69rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:rgba(236,253,250,.82)}
    .album-stat-value{margin-top:.25rem;font-size:1.7rem;font-weight:700;line-height:1.15;color:#fff}
    .album-stat-meta{margin-top:.2rem;font-size:.78rem;color:rgba(240,253,250,.9)}

    .album-section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.05rem}
    .album-section-head h2{font-size:1.25rem;color:#0f1f2d}
    .album-section-head p{font-size:.88rem;color:#64748b}

    .album-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(210px,1fr))}
    .album-person-card{display:block;border-radius:1rem;border:1px solid rgba(15,31,45,.1);background:#fff;padding:1rem;text-decoration:none;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}
    .album-person-card:hover{transform:translateY(-4px);border-color:rgba(22,133,117,.4);box-shadow:0 14px 30px rgba(15,31,51,.14)}

    .album-avatar{position:relative;width:84px;height:84px;border-radius:50%;margin:0 auto .85rem;overflow:hidden;border:3px solid rgba(22,133,117,.25);background:#e2e8f0}
    .album-avatar img{width:100%;height:100%;object-fit:cover}
    .album-avatar-badge{position:absolute;right:-2px;bottom:-2px;display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:999px;border:2px solid #fff;font-size:.7rem}
    .badge-male{background:#dbeafe;color:#1d4ed8}
    .badge-female{background:#fce7f3;color:#be185d}
    .badge-neutral{background:#e2e8f0;color:#334155}

    .album-person-name{font-size:.95rem;font-weight:700;color:#0f1f2d;text-align:center}
    .album-person-meta{margin-top:.15rem;font-size:.78rem;color:#64748b;text-align:center}
    .album-you-tag{display:inline-flex;margin:.45rem auto 0;padding:.15rem .5rem;border-radius:999px;background:#ecfeff;color:#0e7490;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}

    .album-empty{border:1px dashed rgba(15,31,45,.2);border-radius:1rem;background:#f8fafc;padding:2rem 1.25rem;text-align:center;color:#64748b}
    .album-empty i{font-size:1.8rem;color:#94a3b8;margin-bottom:.65rem}
    .album-empty h3{font-family:'Manrope',sans-serif;font-size:1.02rem;font-weight:700;color:#0f1f2d;margin-bottom:.3rem}

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
        .student-album-page .dashboard-card{padding:1.05rem !important}
        .album-hero h1{font-size:1.68rem;line-height:1.2}
        .album-grid{grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:.8rem}
        .album-avatar{width:74px;height:74px}
        .album-person-card{padding:.82rem}
        .album-section-head{margin-bottom:.8rem}
    }
</style>
HTML;

require_once __DIR__ . '/../includes/student_header.php';
?>
<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-album-page space-y-6">
    <section class="dashboard-card album-hero p-6 sm:p-8" data-reveal>
        <span class="album-chip"><i class="fas fa-images"></i> Photo Album</span>
        <h1 class="mt-3 text-3xl font-display">Class <?php echo htmlspecialchars($class_name); ?> Portrait Board</h1>
        <p class="mt-2 text-sm">A clean directory of your teachers and classmates with profile highlights and quick access to details.</p>

        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <div class="album-stat-card">
                <p class="album-stat-label">Teaching Staff</p>
                <p class="album-stat-value"><?php echo number_format($total_teachers); ?></p>
                <p class="album-stat-meta">Active teachers in your school</p>
            </div>
            <div class="album-stat-card">
                <p class="album-stat-label">Class Students</p>
                <p class="album-stat-value"><?php echo number_format($total_classmates); ?></p>
                <p class="album-stat-meta">Students in <?php echo htmlspecialchars($class_name); ?></p>
            </div>
            <div class="album-stat-card">
                <p class="album-stat-label">Photos Uploaded</p>
                <p class="album-stat-value"><?php echo number_format($total_uploaded_photos); ?></p>
                <p class="album-stat-meta"><?php echo number_format(max(0, $total_classmates - $total_uploaded_photos)); ?> pending profile photos</p>
            </div>
        </div>
    </section>

    <section class="dashboard-card p-6" data-reveal data-reveal-delay="80">
        <div class="album-section-head">
            <div>
                <h2 class="font-display">Teaching Staff</h2>
                <p>Mentors and instructors currently assigned within your school.</p>
            </div>
            <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="dashboard.php">Back to dashboard</a>
        </div>

        <?php if (empty($teachers)): ?>
            <div class="album-empty">
                <i class="fas fa-chalkboard-teacher" aria-hidden="true"></i>
                <h3>No teacher profile available</h3>
                <p>Teacher information will appear here once profiles are published.</p>
            </div>
        <?php else: ?>
            <div class="album-grid">
                <?php foreach ($teachers as $teacher): ?>
                    <?php
                    $teacher_photo = $normalize_photo($teacher['profile_image'] ?? null);
                    $designation = trim((string) ($teacher['designation'] ?? ''));
                    $department = trim((string) ($teacher['department'] ?? ''));
                    ?>
                    <a class="album-person-card" href="photo_details.php?type=teacher&id=<?php echo (int) ($teacher['id'] ?? 0); ?>">
                        <div class="album-avatar">
                            <img src="<?php echo htmlspecialchars($teacher_photo); ?>" alt="<?php echo htmlspecialchars((string) ($teacher['full_name'] ?? 'Teacher')); ?>">
                        </div>
                        <p class="album-person-name"><?php echo htmlspecialchars((string) ($teacher['full_name'] ?? 'Teacher')); ?></p>
                        <p class="album-person-meta"><?php echo htmlspecialchars($designation !== '' ? $designation : 'Teacher'); ?></p>
                        <?php if ($department !== ''): ?>
                            <p class="album-person-meta"><?php echo htmlspecialchars($department); ?></p>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-card p-6" data-reveal data-reveal-delay="130">
        <div class="album-section-head">
            <div>
                <h2 class="font-display">Class Students</h2>
                <p>Browse classmates in <?php echo htmlspecialchars($class_name); ?> and open each profile card.</p>
            </div>
            <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="student_class_activities.php">Open class activities</a>
        </div>

        <?php if (empty($classmates)): ?>
            <div class="album-empty">
                <i class="fas fa-user-friends" aria-hidden="true"></i>
                <h3>No class profile available</h3>
                <p>Classmate profiles will display here when class records are set up.</p>
            </div>
        <?php else: ?>
            <div class="album-grid">
                <?php foreach ($classmates as $classmate): ?>
                    <?php
                    $classmate_photo = $normalize_photo($classmate['passport_photo'] ?? null);
                    $gender = strtolower(trim((string) ($classmate['gender'] ?? '')));
                    $gender_icon = 'fa-user';
                    $gender_badge = 'badge-neutral';
                    if ($gender === 'male') {
                        $gender_icon = 'fa-mars';
                        $gender_badge = 'badge-male';
                    } elseif ($gender === 'female') {
                        $gender_icon = 'fa-venus';
                        $gender_badge = 'badge-female';
                    }
                    $is_current_student = (int) ($classmate['id'] ?? 0) === $student_id;
                    ?>
                    <a class="album-person-card" href="photo_details.php?type=student&id=<?php echo (int) ($classmate['id'] ?? 0); ?>">
                        <div class="album-avatar">
                            <img src="<?php echo htmlspecialchars($classmate_photo); ?>" alt="<?php echo htmlspecialchars((string) ($classmate['full_name'] ?? 'Student')); ?>">
                            <span class="album-avatar-badge <?php echo $gender_badge; ?>" aria-hidden="true"><i class="fas <?php echo $gender_icon; ?>"></i></span>
                        </div>
                        <p class="album-person-name"><?php echo htmlspecialchars((string) ($classmate['full_name'] ?? 'Student')); ?></p>
                        <p class="album-person-meta"><?php echo htmlspecialchars((string) ($classmate['admission_no'] ?? 'No admission number')); ?></p>
                        <?php if ($is_current_student): ?>
                            <span class="album-you-tag">You</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
