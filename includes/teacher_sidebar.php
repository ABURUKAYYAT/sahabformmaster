<?php
$current = basename($_SERVER['PHP_SELF']);

function nav_item($href, $label, $icon)
{
    global $current;
    $aliases = [
        'lesson-plan.php' => ['lesson-plans-detail.php'],
    ];
    $active = $current === $href || in_array($current, $aliases[$href] ?? [], true);
    $base   = 'flex items-center gap-3 rounded-xl px-3 py-2 font-semibold ';
    $activeClasses = 'bg-teal-600/10 text-teal-700';
    $idleClasses   = 'text-slate-600 hover:bg-teal-600/10 hover:text-teal-700';
    $classes = $base . ($active ? $activeClasses : $idleClasses);

    echo '<a href="' . htmlspecialchars($href) . '" class="' . $classes . '">';
    echo '<i class="fas ' . htmlspecialchars($icon) . '"></i>';
    echo '<span>' . htmlspecialchars($label) . '</span>';
    echo '</a>';
}
?>
<div class="p-6 border-b border-ink-900/10">
    <h2 class="text-lg font-semibold text-ink-900">Navigation</h2>
    <p class="text-sm text-slate-500">Teacher workspace</p>
</div>
<nav class="p-4 space-y-1 text-sm">
    <?php
        nav_item('index.php', 'Dashboard', 'fa-tachometer-alt');
        nav_item('schoolfeed.php', 'School Feeds', 'fa-newspaper');
        nav_item('school_diary.php', 'School Diary', 'fa-book');
        nav_item('students.php', 'Students', 'fa-users');
        nav_item('results.php', 'Results', 'fa-chart-line');
        nav_item('subjects.php', 'Subjects', 'fa-book-open');
        nav_item('questions.php', 'Question Bank', 'fa-question-circle');
        nav_item('lesson-plan.php', 'Lesson Plans', 'fa-clipboard-list');
        nav_item('curricullum.php', 'Curriculum', 'fa-graduation-cap');
        nav_item('content_coverage.php', 'Content Coverage', 'fa-clipboard-check');
        nav_item('teacher_class_activities.php', 'Class Activities', 'fa-tasks');
        nav_item('cbt_tests.php', 'CBT Tests', 'fa-laptop-code');
        nav_item('student-evaluation.php', 'Evaluations', 'fa-star');
        nav_item('class_attendance.php', 'Attendance', 'fa-calendar-check');
        nav_item('timebook.php', 'Time Book', 'fa-clock');
        nav_item('permissions.php', 'Permissions', 'fa-key');
        nav_item('payments.php', 'Payments', 'fa-money-bill-wave');
    ?>
</nav>
