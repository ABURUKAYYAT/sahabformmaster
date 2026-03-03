<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Ensure teacher is authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();

$teacher_id   = $_SESSION['user_id'];
$user_id      = $_SESSION['user_id'];
$user_name    = $_SESSION['full_name'] ?? 'Teacher';
$teacher_subject = $_SESSION['subject'] ?? '';

// Fetch school-specific published news
$query = "SELECT id, title, excerpt, content, category, target_audience,
                 view_count, published_date, featured_image, allow_comments,
                 priority, status
          FROM school_news
          WHERE status = 'published' AND school_id = ?
          ORDER BY published_date DESC
          LIMIT 20";

$stmt = $pdo->prepare($query);
$stmt->execute([$current_school_id]);
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Comment count
$total_comments = 0;
if (!empty($news_items)) {
    $news_ids = array_column($news_items, 'id');
    $placeholders = str_repeat('?,', count($news_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM school_news_comments WHERE news_id IN ($placeholders)");
    $stmt->execute($news_ids);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_comments = $result['count'] ?? 0;
}

$total_views = !empty($news_items) ? array_sum(array_column($news_items, 'view_count')) : 0;
$high_priority_count = count(array_filter($news_items, fn($n) => strtolower($n['priority'] ?? '') === 'high'));
$comment_enabled_count = count(array_filter($news_items, fn($n) => !empty($n['allow_comments'])));

// Build category filters from existing posts
$category_filters = [];
foreach ($news_items as $item) {
    $cat = strtolower(trim($item['category'] ?? 'General'));
    if ($cat !== '') {
        $category_filters[$cat] = true;
    }
}
ksort($category_filters);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Feed | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <meta name="theme-color" content="#0f172a">
</head>
<body class="landing bg-slate-50">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="w-10 h-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($user_name); ?></span>
                <a class="btn btn-outline" href="../index.php">Home</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 lg:grid-cols-[280px_1fr] py-8">
        <aside class="fixed inset-y-0 left-0 z-40 w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:translate-x-0" data-sidebar>
            <?php include '../includes/teacher_sidebar.php'; ?>
        </aside>

        <main class="space-y-6">
            <!-- Hero + Stats -->
            <section class="rounded-3xl overflow-hidden shadow-lift border border-ink-900/5">
                <div class="bg-gradient-to-r from-teal-700 via-emerald-600 to-sky-600 text-white p-6 sm:p-8">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-white/80 mb-1">School communications</p>
                            <h1 class="text-3xl sm:text-4xl font-display font-semibold leading-tight">News &amp; Announcements</h1>
                            <p class="text-white/80 max-w-2xl mt-2">Fresh updates curated for <?php echo htmlspecialchars(get_school_display_name()); ?> teachers. Stay ahead of events, examinations, and community stories.</p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <a href="index.php" class="inline-flex items-center gap-2 rounded-xl bg-white/10 hover:bg-white/20 px-4 py-2 text-sm font-semibold">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                            <a href="new_post.php" class="inline-flex items-center gap-2 rounded-xl bg-white text-teal-900 hover:bg-white/90 px-4 py-2 text-sm font-semibold shadow-soft">
                                <i class="fas fa-plus"></i>
                                New Post
                            </a>
                        </div>
                    </div>
                </div>
                <div class="grid gap-3 bg-white p-4 sm:p-6 md:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4 shadow-soft">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Active Posts</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo count($news_items); ?></p>
                    </div>
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4 shadow-soft">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Views</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo $total_views; ?></p>
                    </div>
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4 shadow-soft">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Comments Enabled</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo $comment_enabled_count; ?></p>
                    </div>
                    <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4 shadow-soft">
                        <p class="text-xs uppercase tracking-wide text-slate-500">High Priority</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo $high_priority_count; ?></p>
                    </div>
                </div>
            </section>

            <!-- Filters -->
            <section class="rounded-3xl bg-white p-5 shadow-soft border border-ink-900/5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="w-full lg:max-w-md relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-search"></i></span>
                        <input id="feed-search" type="search" placeholder="Search titles or excerpts..." class="w-full rounded-xl border border-ink-900/10 bg-white px-10 py-2.5 text-sm text-ink-900 shadow-inner focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                    </div>
                    <div class="flex flex-wrap gap-2 overflow-x-auto" data-filter-group>
                        <button type="button" data-filter="all" class="filter-chip is-active inline-flex items-center gap-2 rounded-full border border-teal-600 bg-teal-600 px-3 py-2 text-sm font-semibold text-white shadow-soft">
                            <i class="fas fa-layer-group"></i> All
                        </button>
                        <?php foreach ($category_filters as $cat => $_): ?>
                            <button type="button" data-filter="<?php echo htmlspecialchars($cat); ?>" class="filter-chip inline-flex items-center gap-2 rounded-full border border-ink-900/10 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:border-teal-200 hover:bg-teal-50">
                                <i class="fas fa-tag"></i> <?php echo htmlspecialchars(ucwords($cat)); ?>
                            </button>
                        <?php endforeach; ?>
                        <button type="button" data-filter="high" class="filter-chip inline-flex items-center gap-2 rounded-full border border-ink-900/10 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:border-teal-200 hover:bg-teal-50">
                            <i class="fas fa-exclamation-circle"></i> High priority
                        </button>
                    </div>
                </div>
            </section>

            <!-- Feed -->
            <section class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-ink-900">Latest Updates</h2>
                    <span class="text-xs uppercase tracking-wide text-slate-500">Showing <span data-visible-count><?php echo count($news_items); ?></span> posts</span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($news_items as $item):
                        $content_preview = strip_tags($item['content']);
                        if (strlen($content_preview) > 180) {
                            $content_preview = substr($content_preview, 0, 180) . '...';
                        }
                        $category_slug = strtolower(trim($item['category'] ?? 'general'));
                        $priority_slug = strtolower(trim($item['priority'] ?? 'normal'));
                        $title_index = strtolower($item['title'] . ' ' . ($item['excerpt'] ?: $content_preview));
                    ?>
                        <article data-feed-card data-category="<?php echo htmlspecialchars($category_slug); ?>" data-priority="<?php echo htmlspecialchars($priority_slug); ?>" data-title="<?php echo htmlspecialchars($title_index); ?>" class="rounded-2xl bg-white border border-ink-900/5 shadow-soft overflow-hidden flex flex-col transition hover:-translate-y-1 hover:shadow-lift">
                            <div class="relative h-48 bg-sky-50">
                                <?php if (!empty($item['featured_image'])): ?>
                                    <img src="../<?php echo htmlspecialchars($item['featured_image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="w-full h-full object-cover" onerror="this.src='https://via.placeholder.com/800x400/1d4ed8/ffffff?text=School+News'">
                                <?php else: ?>
                                    <img src="https://via.placeholder.com/800x400/1d4ed8/ffffff?text=School+News" alt="News Image" class="w-full h-full object-cover">
                                <?php endif; ?>
                                <div class="absolute top-3 left-3 flex flex-wrap gap-2">
                                    <span class="inline-flex items-center gap-2 rounded-full bg-white/90 text-ink-900 px-3 py-1 text-xs font-semibold shadow-soft">
                                        <i class="fas fa-tag text-teal-700"></i>
                                        <?php echo htmlspecialchars($item['category']); ?>
                                    </span>
                                    <?php if ($priority_slug === 'high'): ?>
                                        <span class="inline-flex items-center gap-2 rounded-full bg-red-100 text-red-700 px-3 py-1 text-xs font-semibold shadow-soft">
                                            <i class="fas fa-bolt"></i>
                                            High
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['target_audience'])): ?>
                                        <span class="inline-flex items-center gap-2 rounded-full bg-white/90 text-ink-900 px-3 py-1 text-xs font-semibold shadow-soft">
                                            <i class="fas fa-users text-sky-700"></i>
                                            <?php echo htmlspecialchars($item['target_audience']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="p-5 flex flex-col gap-3 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <h3 class="text-lg font-semibold text-ink-900 leading-tight"><?php echo htmlspecialchars($item['title']); ?></h3>
                                </div>
                                <p class="text-sm text-slate-600 flex-1"><?php echo !empty($item['excerpt']) ? htmlspecialchars($item['excerpt']) : htmlspecialchars($content_preview); ?></p>
                                <div class="flex flex-col gap-3 border-t border-ink-900/5 pt-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <span class="inline-flex items-center gap-1"><i class="far fa-calendar"></i><?php echo date('M d, Y', strtotime($item['published_date'])); ?></span>
                                        <span class="inline-flex items-center gap-1"><i class="far fa-eye"></i><?php echo intval($item['view_count']); ?></span>
                                        <?php if ($item['allow_comments']): ?>
                                            <span class="inline-flex items-center gap-1 text-teal-700"><i class="far fa-comments"></i>Open comments</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 text-slate-400"><i class="far fa-comments"></i>Closed</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <a href="newsdetails.php?id=<?php echo $item['id']; ?>" class="inline-flex items-center justify-center gap-2 rounded-lg border border-ink-900/10 bg-white px-3 py-2 text-sm font-semibold text-slate-600 transition hover:border-teal-600/40 hover:bg-teal-600/10 hover:text-teal-700">
                                            <i class="fas fa-eye"></i>
                                            View details
                                        </a>
                                        <a href="newsdetails.php?id=<?php echo $item['id']; ?>#comments" class="inline-flex items-center justify-center gap-2 rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white transition hover:bg-teal-600">
                                            <i class="fas fa-book-open"></i>
                                            Open feed
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div data-empty-state class="rounded-2xl border border-dashed border-ink-900/15 bg-white p-8 text-center text-slate-500 <?php echo empty($news_items) ? '' : 'hidden'; ?>">
                    <div class="mx-auto mb-3 inline-flex h-12 w-12 items-center justify-center rounded-full bg-teal-50 text-teal-700">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-ink-900">No news yet</h3>
                    <p class="text-sm text-slate-500 mt-1">Announcements you publish will appear here for teachers.</p>
                    <a href="new_post.php" class="inline-flex items-center gap-2 mt-4 px-4 py-2 rounded-lg bg-teal-700 text-white font-semibold hover:bg-teal-600">
                        <i class="fas fa-plus"></i>
                        Create your first post
                    </a>
                </div>
            </section>
        </main>
    </div>

    <?php include '../includes/floating-button.php'; ?>

    <script>
        // Sidebar
        const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        const sidebar = document.querySelector('[data-sidebar]');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        const body = document.body;

        const openSidebar = () => {
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('opacity-0', 'pointer-events-none');
            overlay.classList.add('opacity-100');
            body.classList.add('nav-open');
        };

        const closeSidebar = () => {
            if (!sidebar || !overlay) return;
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('opacity-0', 'pointer-events-none');
            overlay.classList.remove('opacity-100');
            body.classList.remove('nav-open');
        };

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                if (sidebar.classList.contains('-translate-x-full')) {
                    openSidebar();
                } else {
                    closeSidebar();
                }
            });
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        // Feed filters (client-side)
        const searchInput = document.querySelector('#feed-search');
        const filterButtons = document.querySelectorAll('[data-filter]');
        const feedCards = document.querySelectorAll('[data-feed-card]');
        const visibleCount = document.querySelector('[data-visible-count]');
        const emptyState = document.querySelector('[data-empty-state]');

        const applyFeedFilters = () => {
            const term = (searchInput?.value || '').toLowerCase().trim();
            const activeFilter = document.querySelector('[data-filter].is-active')?.dataset.filter || 'all';
            let shown = 0;

            feedCards.forEach((card) => {
                const title = card.dataset.title || '';
                const category = card.dataset.category || '';
                const priority = card.dataset.priority || '';
                const matchesSearch = !term || title.includes(term);
                const matchesFilter = activeFilter === 'all' || category === activeFilter || priority === activeFilter;
                const show = matchesSearch && matchesFilter;
                card.classList.toggle('hidden', !show);
                if (show) shown++;
            });

            if (visibleCount) visibleCount.textContent = shown;
            if (emptyState) emptyState.classList.toggle('hidden', shown !== 0);
        };

        filterButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                filterButtons.forEach((b) => b.classList.remove('is-active', 'bg-teal-600', 'text-white', 'border-teal-600'));
                btn.classList.add('is-active', 'bg-teal-600', 'text-white', 'border-teal-600');
                applyFeedFilters();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', applyFeedFilters);
        }

        applyFeedFilters();
    </script>
</body>
</html>
