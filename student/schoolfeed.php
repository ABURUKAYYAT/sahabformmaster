<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: ../index.php');
    exit;
}

$student_name = trim((string) ($_SESSION['student_name'] ?? 'Student'));
if ($student_name === '') {
    $student_name = 'Student';
}

$student_class = trim((string) ($_SESSION['class'] ?? ''));
$current_school_id = get_current_school_id();

$query = "SELECT id, title, excerpt, content, category, target_audience, view_count,
                 published_date, featured_image, allow_comments, priority
          FROM school_news
          WHERE status = 'published'
            AND school_id = :school_id
            AND (target_audience = 'All'
                 OR target_audience = 'Students'
                 OR target_audience LIKE :class)
          ORDER BY published_date DESC
          LIMIT 20";

$stmt = $pdo->prepare($query);
$stmt->execute([
    'school_id' => $current_school_id,
    'class' => $student_class === '' ? '%' : '%' . $student_class . '%'
]);
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$comment_counts = [];
if (!empty($news_items)) {
    $news_ids = array_map('intval', array_column($news_items, 'id'));
    $placeholders = implode(',', array_fill(0, count($news_ids), '?'));
    $comment_sql = "SELECT news_id, COUNT(*) AS total
                    FROM school_news_comments
                    WHERE school_id = ? AND news_id IN ($placeholders)
                    GROUP BY news_id";
    $comment_stmt = $pdo->prepare($comment_sql);
    $comment_stmt->execute(array_merge([$current_school_id], $news_ids));
    foreach ($comment_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $comment_counts[(int) $row['news_id']] = (int) $row['total'];
    }
}

$total_views = array_sum(array_map(static function (array $item): int {
    return (int) ($item['view_count'] ?? 0);
}, $news_items));
$high_priority_count = count(array_filter($news_items, static function (array $item): bool {
    return strtolower((string) ($item['priority'] ?? '')) === 'high';
}));
$comment_enabled_count = count(array_filter($news_items, static function (array $item): bool {
    return !empty($item['allow_comments']);
}));

$category_filters = [];
foreach ($news_items as $item) {
    $cat = strtolower(trim((string) ($item['category'] ?? 'general')));
    if ($cat !== '') {
        $category_filters[$cat] = true;
    }
}
ksort($category_filters);

$pageTitle = 'School Feed | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$stylesheetVersion = @filemtime(__DIR__ . '/../assets/css/student-news.css') ?: time();
$extraHead = '<link rel="stylesheet" href="../assets/css/student-news.css?v=' . rawurlencode((string) $stylesheetVersion) . '">';
require_once __DIR__ . '/../includes/student_header.php';
?>

<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-feed-page space-y-6">
    <section class="news-panel overflow-hidden p-0" data-reveal>
        <div class="news-hero p-6 sm:p-8 text-white">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs uppercase tracking-[0.32em] text-white/75">School Communications</p>
                    <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">School Feed for Students</h1>
                    <p class="mt-3 max-w-2xl text-sm text-white/85 sm:text-base">
                        Latest announcements, reminders, and activities for <?php echo htmlspecialchars($student_name); ?>
                        <?php if ($student_class !== ''): ?> in <?php echo htmlspecialchars($student_class); ?><?php endif; ?>.
                    </p>
                </div>
                <div class="news-hero-actions grid gap-3 sm:grid-cols-2">
                    <a href="#student-feed-list" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                        <i class="fas fa-list"></i>
                        <span>Browse Feed</span>
                    </a>
                    <a href="dashboard.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/30 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Back to Dashboard</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="news-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-4">
            <article class="news-metric-card">
                <div class="news-metric-icon bg-teal-600/10 text-teal-700">
                    <i class="fas fa-newspaper"></i>
                </div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Available Posts</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format(count($news_items)); ?></h2>
                <p class="text-sm text-slate-500">Visible in your feed</p>
            </article>
            <article class="news-metric-card">
                <div class="news-metric-icon bg-sky-600/10 text-sky-700">
                    <i class="fas fa-eye"></i>
                </div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Total Views</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($total_views); ?></h2>
                <p class="text-sm text-slate-500">Across visible posts</p>
            </article>
            <article class="news-metric-card">
                <div class="news-metric-icon bg-emerald-600/10 text-emerald-700">
                    <i class="fas fa-comments"></i>
                </div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Comments Open</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($comment_enabled_count); ?></h2>
                <p class="text-sm text-slate-500">Posts accepting replies</p>
            </article>
            <article class="news-metric-card">
                <div class="news-metric-icon bg-amber-500/10 text-amber-700">
                    <i class="fas fa-bolt"></i>
                </div>
                <p class="text-xs uppercase tracking-wide text-slate-500">High Priority</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo number_format($high_priority_count); ?></h2>
                <p class="text-sm text-slate-500">Urgent notices</p>
            </article>
        </div>
    </section>

    <section id="student-feed-list" class="news-panel rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft sm:p-6" data-reveal data-reveal-delay="70">
        <div class="feed-toolbar flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.28em] text-slate-500">Feed Filters</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900">Find updates quickly</h2>
            </div>
            <div class="feed-search-wrap relative w-full xl:max-w-md">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                    <i class="fas fa-search"></i>
                </span>
                <input
                    id="feed-search"
                    type="search"
                    placeholder="Search by title or excerpt..."
                    class="w-full rounded-xl border border-ink-900/10 bg-white px-10 py-2.5 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                >
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2 overflow-x-auto pb-1" data-filter-group>
            <button type="button" data-filter="all" class="feed-filter-chip is-active">
                <i class="fas fa-layer-group"></i>
                <span>All</span>
            </button>
            <?php foreach ($category_filters as $cat => $_): ?>
                <button type="button" data-filter="<?php echo htmlspecialchars($cat); ?>" class="feed-filter-chip">
                    <i class="fas fa-tag"></i>
                    <span><?php echo htmlspecialchars(ucwords($cat)); ?></span>
                </button>
            <?php endforeach; ?>
            <button type="button" data-filter="high" class="feed-filter-chip">
                <i class="fas fa-exclamation-circle"></i>
                <span>High priority</span>
            </button>
        </div>

        <div class="mt-5 flex items-center justify-between gap-3">
            <p class="text-sm text-slate-600">Showing <strong data-visible-count><?php echo count($news_items); ?></strong> posts</p>
            <p class="text-xs uppercase tracking-wide text-slate-500"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
        </div>

        <?php if (empty($news_items)): ?>
            <div class="news-empty-state mt-5">
                <span class="news-empty-icon"><i class="fas fa-bell"></i></span>
                <h3 class="text-lg font-semibold text-ink-900">No announcements yet</h3>
                <p class="mt-1 text-sm text-slate-500">When your school publishes updates for students, they will appear here.</p>
            </div>
        <?php else: ?>
            <div class="news-feed-grid mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($news_items as $item):
                    $content_preview = strip_tags((string) ($item['content'] ?? ''));
                    if (mb_strlen($content_preview) > 180) {
                        $content_preview = mb_substr($content_preview, 0, 180) . '...';
                    }
                    $excerpt = trim((string) ($item['excerpt'] ?? ''));
                    $display_excerpt = $excerpt !== '' ? $excerpt : $content_preview;
                    $category_slug = strtolower(trim((string) ($item['category'] ?? 'general')));
                    if ($category_slug === '') {
                        $category_slug = 'general';
                    }
                    $priority_slug = strtolower(trim((string) ($item['priority'] ?? 'normal')));
                    $title_index = strtolower(trim((string) ($item['title'] . ' ' . $display_excerpt)));
                    $item_id = (int) $item['id'];
                    $item_comments = (int) ($comment_counts[$item_id] ?? 0);
                ?>
                    <article
                        data-feed-card
                        data-category="<?php echo htmlspecialchars($category_slug); ?>"
                        data-priority="<?php echo htmlspecialchars($priority_slug); ?>"
                        data-title="<?php echo htmlspecialchars($title_index); ?>"
                        class="news-feed-card"
                    >
                        <div class="news-media">
                            <?php if (!empty($item['featured_image'])): ?>
                                <img
                                    src="../<?php echo htmlspecialchars((string) $item['featured_image']); ?>"
                                    alt="<?php echo htmlspecialchars((string) $item['title']); ?>"
                                    class="news-cover"
                                    loading="lazy"
                                    onerror="this.src='https://via.placeholder.com/900x500/0f766e/ffffff?text=School+News'"
                                >
                            <?php else: ?>
                                <div class="news-cover-placeholder">
                                    <i class="fas fa-newspaper"></i>
                                    <span>School Update</span>
                                </div>
                            <?php endif; ?>

                            <div class="news-badge-stack">
                                <span class="news-pill news-pill-category">
                                    <i class="fas fa-tag"></i>
                                    <?php echo htmlspecialchars((string) ($item['category'] ?? 'General')); ?>
                                </span>
                                <?php if ($priority_slug === 'high'): ?>
                                    <span class="news-pill news-pill-priority">
                                        <i class="fas fa-bolt"></i>
                                        High
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="news-body">
                            <h3 class="news-title"><?php echo htmlspecialchars((string) $item['title']); ?></h3>
                            <p class="news-excerpt"><?php echo htmlspecialchars($display_excerpt); ?></p>

                            <div class="news-meta">
                                <span><i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime((string) $item['published_date'])); ?></span>
                                <span><i class="far fa-eye"></i> <?php echo number_format((int) ($item['view_count'] ?? 0)); ?></span>
                                <?php if (!empty($item['allow_comments'])): ?>
                                    <span><i class="far fa-comments"></i> <?php echo number_format($item_comments); ?></span>
                                <?php else: ?>
                                    <span class="text-slate-400"><i class="far fa-comments"></i> Closed</span>
                                <?php endif; ?>
                            </div>

                            <a href="newsdetails.php?id=<?php echo $item_id; ?>" class="news-read-link">
                                <i class="fas fa-book-open"></i>
                                <span>Read Full Story</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div data-empty-state class="news-empty-state mt-5 hidden">
            <span class="news-empty-icon"><i class="fas fa-filter-circle-xmark"></i></span>
            <h3 class="text-lg font-semibold text-ink-900">No posts match your filters</h3>
            <p class="mt-1 text-sm text-slate-500">Try a different search keyword or change the selected filter.</p>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var searchInput = document.getElementById('feed-search');
    var filterButtons = Array.from(document.querySelectorAll('[data-filter]'));
    var feedCards = Array.from(document.querySelectorAll('[data-feed-card]'));
    var visibleCount = document.querySelector('[data-visible-count]');
    var emptyState = document.querySelector('[data-empty-state]');
    var sidebarOverlay = document.getElementById('studentSidebarOverlay');

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open');
        });
    }

    function applyFilters() {
        var term = (searchInput ? searchInput.value : '').toLowerCase().trim();
        var activeButton = document.querySelector('[data-filter].is-active');
        var activeFilter = activeButton ? activeButton.getAttribute('data-filter') : 'all';
        var shown = 0;

        feedCards.forEach(function (card) {
            var title = (card.getAttribute('data-title') || '').toLowerCase();
            var category = card.getAttribute('data-category') || '';
            var priority = card.getAttribute('data-priority') || '';
            var matchesSearch = !term || title.indexOf(term) !== -1;
            var matchesFilter = activeFilter === 'all' || category === activeFilter || priority === activeFilter;
            var isVisible = matchesSearch && matchesFilter;
            card.classList.toggle('is-hidden', !isVisible);
            if (isVisible) {
                shown += 1;
            }
        });

        if (visibleCount) {
            visibleCount.textContent = String(shown);
        }
        if (emptyState) {
            emptyState.classList.toggle('hidden', shown !== 0 || feedCards.length === 0);
        }
    }

    filterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            filterButtons.forEach(function (btn) {
                btn.classList.remove('is-active');
            });
            button.classList.add('is-active');
            applyFilters();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    var reveals = document.querySelectorAll('[data-reveal]');
    if ('IntersectionObserver' in window && reveals.length) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                var delay = entry.target.getAttribute('data-reveal-delay') || '0';
                entry.target.style.transitionDelay = delay + 'ms';
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.12 });

        reveals.forEach(function (el) {
            observer.observe(el);
        });
    } else {
        reveals.forEach(function (el) {
            el.classList.add('is-visible');
        });
    }

    applyFilters();
});
</script>

<?php include __DIR__ . '/../includes/floating-button.php'; ?>
<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
