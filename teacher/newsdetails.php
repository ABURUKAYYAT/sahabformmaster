<?php

session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

// School authentication and context
$current_school_id = require_school_auth();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Teacher';
$teacher_subject = $_SESSION['subject'] ?? '';

// Get news ID from URL
$news_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($news_id <= 0) {
    header("Location: schoolfeed.php");
    exit;
}

// Fetch the specific news item - filtered by school_id
$stmt = $pdo->prepare("SELECT * FROM school_news WHERE id = :id AND status = 'published' AND school_id = :school_id");
$stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);
$news_item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$news_item) {
    header("Location: schoolfeed.php");
    exit;
}

// Update view count - filtered by school_id
$stmt = $pdo->prepare("UPDATE school_news SET view_count = view_count + 1 WHERE id = :id AND school_id = :school_id");
$stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'comment') {
        $comment = trim($_POST['comment'] ?? '');

        if (!empty($comment)) {
            $stmt = $pdo->prepare("INSERT INTO school_news_comments (news_id, user_id, name, comment) 
                                   VALUES (:news_id, :user_id, :name, :comment)");
            $stmt->execute([
                'news_id' => $news_id,
                'user_id' => $user_id,
                'name' => $user_name,
                'comment' => $comment
            ]);

            // Redirect to prevent form resubmission
            header("Location: newsdetails.php?id=" . $news_id);
            exit;
        }
    }
}

// Fetch comments for this news item
$stmt = $pdo->prepare("SELECT * FROM school_news_comments WHERE news_id = :news_id ORDER BY created_at DESC");
$stmt->execute(['news_id' => $news_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent news for sidebar - filtered by school_id
$recent_stmt = $pdo->prepare("SELECT id, title, published_date, featured_image FROM school_news
                              WHERE status = 'published' AND id != :news_id AND school_id = :school_id
                              ORDER BY published_date DESC LIMIT 5");
$recent_stmt->execute(['news_id' => $news_id, 'school_id' => $current_school_id]);
$recent_news = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($news_item['title']); ?> | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
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
            <!-- Hero -->
            <section class="rounded-3xl overflow-hidden shadow-lift border border-ink-900/5">
                <div class="bg-gradient-to-r from-teal-700 via-emerald-600 to-sky-600 text-white p-6 sm:p-8">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="space-y-2">
                            <p class="text-xs uppercase tracking-wide text-white/80">School News</p>
                            <h1 class="text-3xl sm:text-4xl font-display font-semibold leading-tight"><?php echo htmlspecialchars($news_item['title']); ?></h1>
                            <div class="flex flex-wrap items-center gap-3 text-white/80 text-sm">
                                <span class="inline-flex items-center gap-2"><i class="far fa-calendar"></i><?php echo date('F d, Y', strtotime($news_item['published_date'])); ?></span>
                                <span class="inline-flex items-center gap-2"><i class="fas fa-tag"></i><?php echo htmlspecialchars($news_item['category']); ?></span>
                                <?php if (!empty($news_item['target_audience'])): ?>
                                    <span class="inline-flex items-center gap-2"><i class="fas fa-users"></i><?php echo htmlspecialchars($news_item['target_audience']); ?></span>
                                <?php endif; ?>
                                <span class="inline-flex items-center gap-2"><i class="far fa-eye"></i><?php echo intval($news_item['view_count']) + 1; ?></span>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <a href="schoolfeed.php" class="inline-flex items-center gap-2 rounded-xl bg-white/10 hover:bg-white/20 px-4 py-2 text-sm font-semibold">
                                <i class="fas fa-arrow-left"></i>
                                Back to Feed
                            </a>
                            <a href="new_post.php" class="inline-flex items-center gap-2 rounded-xl bg-white text-teal-900 hover:bg-white/90 px-4 py-2 text-sm font-semibold shadow-soft">
                                <i class="fas fa-plus"></i>
                                New Post
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-[1.65fr_1fr]">
                <article class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                    <?php if ($news_item['featured_image']): ?>
                        <div class="mb-5 overflow-hidden rounded-2xl border border-ink-900/10">
                            <img src="../<?php echo htmlspecialchars($news_item['featured_image']); ?>" alt="<?php echo htmlspecialchars($news_item['title']); ?>" class="w-full h-72 object-cover" onerror="this.src='https://via.placeholder.com/900x450/1d4ed8/ffffff?text=School+News';">
                        </div>
                    <?php else: ?>
                        <div class="mb-5 h-72 rounded-2xl bg-sky-50 border border-dashed border-ink-900/10 flex items-center justify-center text-slate-400">
                            <i class="fas fa-image mr-2"></i> No image provided
                        </div>
                    <?php endif; ?>

                    <div class="flex flex-wrap items-center gap-2 mb-4">
                        <span class="inline-flex items-center gap-2 rounded-full bg-teal-600/10 text-teal-700 px-3 py-1 text-xs font-semibold">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars($news_item['category']); ?>
                        </span>
                        <?php if (strtolower($news_item['priority'] ?? '') === 'high'): ?>
                            <span class="inline-flex items-center gap-2 rounded-full bg-red-100 text-red-700 px-3 py-1 text-xs font-semibold">
                                <i class="fas fa-bolt"></i>
                                High Priority
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($news_item['target_audience'])): ?>
                            <span class="inline-flex items-center gap-2 rounded-full bg-sky-100 text-sky-700 px-3 py-1 text-xs font-semibold">
                                <i class="fas fa-users"></i>
                                <?php echo htmlspecialchars($news_item['target_audience']); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($news_item['allow_comments']): ?>
                            <span class="inline-flex items-center gap-2 rounded-full bg-emerald-100 text-emerald-700 px-3 py-1 text-xs font-semibold">
                                <i class="far fa-comments"></i>
                                Comments open
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 text-slate-600 px-3 py-1 text-xs font-semibold">
                                <i class="far fa-comments"></i>
                                Comments closed
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="prose max-w-none text-slate-700 mb-6">
                        <?php echo nl2br(htmlspecialchars($news_item['content'])); ?>
                    </div>

                    <div class="flex flex-wrap items-center justify-between text-sm text-slate-500 gap-3">
                        <div class="flex items-center gap-4 flex-wrap">
                            <span class="inline-flex items-center gap-2"><i class="far fa-eye"></i><?php echo intval($news_item['view_count']) + 1; ?> views</span>
                            <span class="inline-flex items-center gap-2"><i class="fas fa-users"></i><?php echo htmlspecialchars($news_item['target_audience']); ?></span>
                        </div>
                        <div class="text-xs text-slate-400">Published <?php echo date('F d, Y', strtotime($news_item['published_date'])); ?></div>
                    </div>

                    <div id="comments" class="mt-8">
                        <?php if ($news_item['allow_comments']): ?>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-lg font-semibold text-ink-900">Comments (<?php echo count($comments); ?>)</h3>
                                <span class="text-xs uppercase tracking-wide text-slate-500">Teachers only</span>
                            </div>
                            <form method="POST" class="space-y-3 mb-6">
                                <input type="hidden" name="action" value="comment">
                                <input type="hidden" name="news_id" value="<?php echo $news_id; ?>">
                                <textarea name="comment" class="w-full p-3 border border-ink-900/10 rounded-xl min-h-[110px] text-sm focus:border-teal-600 focus:ring-2 focus:ring-teal-100" placeholder="Share your perspective with colleagues..." required></textarea>
                                <button type="submit" class="inline-flex items-center gap-2 bg-teal-700 hover:bg-teal-600 text-white px-4 py-2 rounded-lg font-semibold">
                                    <i class="fas fa-paper-plane"></i> Post Comment
                                </button>
                            </form>

                            <div class="space-y-4">
                                <?php if (empty($comments)): ?>
                                    <div class="text-slate-500 p-4 rounded-lg bg-mist-50 border border-dashed border-ink-900/10">No comments yet. Start the discussion.</div>
                                <?php else: ?>
                                    <?php foreach ($comments as $comment): ?>
                                        <div class="p-4 bg-white border border-ink-900/5 rounded-xl shadow-sm">
                                            <div class="flex items-center justify-between mb-2">
                                                <div class="text-sm font-semibold text-ink-900 flex items-center gap-2">
                                                    <?php echo htmlspecialchars($comment['name']); ?>
                                                    <?php if ($comment['user_id'] == $user_id): ?>
                                                        <span class="text-xs text-teal-700">(You)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-xs text-slate-400"><?php echo date('M d, Y', strtotime($comment['created_at'])); ?> &bull; <?php echo date('h:i A', strtotime($comment['created_at'])); ?></div>
                                            </div>
                                            <div class="text-slate-700 text-sm"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-4 rounded-lg bg-mist-50 text-slate-500 border border-dashed border-ink-900/10">Comments are disabled for this post.</div>
                        <?php endif; ?>
                    </div>
                </article>

                <aside class="space-y-4">
                    <div class="rounded-3xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <h4 class="text-lg font-semibold text-ink-900 mb-3">Teacher Info</h4>
                        <div class="text-sm text-slate-600 space-y-2">
                            <div><strong class="text-ink-900">Name:</strong> <?php echo htmlspecialchars($user_name); ?></div>
                            <div><strong class="text-ink-900">Subject:</strong> <?php echo htmlspecialchars($teacher_subject); ?></div>
                            <div><strong class="text-ink-900">Role:</strong> Teacher</div>
                            <div><strong class="text-ink-900">Comment Access:</strong> <?php echo $news_item['allow_comments'] ? 'Enabled' : 'Disabled'; ?></div>
                        </div>
                    </div>

                    <?php if (!empty($recent_news)): ?>
                        <div class="rounded-3xl bg-white p-5 shadow-soft border border-ink-900/5">
                            <h4 class="text-lg font-semibold text-ink-900 mb-3">Recent News</h4>
                            <div class="space-y-3">
                                <?php foreach ($recent_news as $recent): ?>
                                    <a href="newsdetails.php?id=<?php echo $recent['id']; ?>" class="flex items-center gap-3 rounded-lg p-2 hover:bg-mist-50 border border-transparent hover:border-ink-900/10 transition">
                                        <?php if ($recent['featured_image']): ?>
                                            <img src="../<?php echo htmlspecialchars($recent['featured_image']); ?>" alt="<?php echo htmlspecialchars($recent['title']); ?>" class="w-16 h-12 rounded-md object-cover" onerror="this.style.display='none'">
                                        <?php else: ?>
                                            <div class="w-16 h-12 rounded-md bg-slate-100 flex items-center justify-center text-slate-400 text-xs">No image</div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="text-sm font-semibold text-ink-900 line-clamp-2"><?php echo htmlspecialchars($recent['title']); ?></div>
                                            <div class="text-xs text-slate-400"><?php echo date('M d, Y', strtotime($recent['published_date'])); ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="rounded-3xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <h4 class="text-lg font-semibold text-ink-900 mb-3">Details</h4>
                        <div class="text-sm text-slate-600 space-y-2">
                            <div><strong class="text-ink-900">Status:</strong> Published</div>
                            <div><strong class="text-ink-900">Comments:</strong> <?php echo $news_item['allow_comments'] ? 'Allowed' : 'Not Allowed'; ?></div>
                            <div><strong class="text-ink-900">Priority:</strong> <?php echo ucfirst($news_item['priority']); ?></div>
                            <div><strong class="text-ink-900">Target:</strong> <?php echo htmlspecialchars($news_item['target_audience']); ?></div>
                            <div><strong class="text-ink-900">Views:</strong> <?php echo intval($news_item['view_count']) + 1; ?></div>
                        </div>
                    </div>
                </aside>
            </section>
        </main>
    </div>

    <?php include '../includes/floating-button.php'; ?>

    <script>
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
    </script>
</body>
</html>
