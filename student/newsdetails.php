<?php
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: ../index.php');
    exit;
}

$student_id = (int) ($_SESSION['student_id'] ?? 0);
$student_name = trim((string) ($_SESSION['student_name'] ?? 'Student'));
if ($student_name === '') {
    $student_name = 'Student';
}

$student_class = trim((string) ($_SESSION['class'] ?? ''));
$current_school_id = get_current_school_id();

$news_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($news_id <= 0) {
    header('Location: schoolfeed.php');
    exit;
}

$query = "SELECT *
          FROM school_news
          WHERE id = :id
            AND status = 'published'
            AND school_id = :school_id";
$stmt = $pdo->prepare($query);
$stmt->execute([
    'id' => $news_id,
    'school_id' => $current_school_id
]);
$news_item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$news_item) {
    header('Location: schoolfeed.php');
    exit;
}

$target_audience = (string) ($news_item['target_audience'] ?? '');
$can_view = (
    $target_audience === 'All' ||
    $target_audience === 'Students' ||
    ($student_class !== '' && stripos($target_audience, $student_class) !== false)
);

if (!$can_view) {
    header('Location: schoolfeed.php');
    exit;
}

$stmt = $pdo->prepare('UPDATE school_news SET view_count = view_count + 1 WHERE id = :id AND school_id = :school_id');
$stmt->execute([
    'id' => $news_id,
    'school_id' => $current_school_id
]);
$news_item['view_count'] = (int) ($news_item['view_count'] ?? 0) + 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'comment') {
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if ($comment !== '' && !empty($news_item['allow_comments'])) {
        $comment_stmt = $pdo->prepare(
            "INSERT INTO school_news_comments (news_id, user_id, name, comment, school_id)
             VALUES (:news_id, :user_id, :name, :comment, :school_id)"
        );
        $comment_stmt->execute([
            'news_id' => $news_id,
            'user_id' => $student_id > 0 ? $student_id : 0,
            'name' => $student_name,
            'comment' => $comment,
            'school_id' => $current_school_id
        ]);

        $count_stmt = $pdo->prepare('UPDATE school_news SET comment_count = comment_count + 1 WHERE id = :id AND school_id = :school_id');
        $count_stmt->execute([
            'id' => $news_id,
            'school_id' => $current_school_id
        ]);

        header('Location: newsdetails.php?id=' . $news_id . '#comments');
        exit;
    }
}

$comment_stmt = $pdo->prepare(
    "SELECT *
     FROM school_news_comments
     WHERE news_id = :news_id
       AND school_id = :school_id
     ORDER BY created_at DESC"
);
$comment_stmt->execute([
    'news_id' => $news_id,
    'school_id' => $current_school_id
]);
$comments = $comment_stmt->fetchAll(PDO::FETCH_ASSOC);

$recent_stmt = $pdo->prepare(
    "SELECT id, title, category, published_date, featured_image
     FROM school_news
     WHERE status = 'published'
       AND id != :news_id
       AND school_id = :school_id
       AND (target_audience = 'All'
            OR target_audience = 'Students'
            OR target_audience LIKE :class)
     ORDER BY published_date DESC
     LIMIT 5"
);
$recent_stmt->execute([
    'news_id' => $news_id,
    'school_id' => $current_school_id,
    'class' => $student_class === '' ? '%' : '%' . $student_class . '%'
]);
$recent_news = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = trim((string) ($news_item['title'] ?? 'News Details')) . ' | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$stylesheetVersion = @filemtime(__DIR__ . '/../assets/css/student-news.css') ?: time();
$extraHead = '<link rel="stylesheet" href="../assets/css/student-news.css?v=' . rawurlencode((string) $stylesheetVersion) . '">';
require_once __DIR__ . '/../includes/student_header.php';
?>

<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-news-details-page space-y-6">
    <section class="news-panel overflow-hidden p-0" data-reveal>
        <div class="news-hero p-6 sm:p-8 text-white">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <a href="schoolfeed.php" class="student-news-back-link">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Feed</span>
                    </a>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <span class="news-pill news-pill-category">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars((string) ($news_item['category'] ?? 'General')); ?>
                        </span>
                        <?php if (strtolower((string) ($news_item['priority'] ?? '')) === 'high'): ?>
                            <span class="news-pill news-pill-priority">
                                <i class="fas fa-bolt"></i>
                                High Priority
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($news_item['allow_comments'])): ?>
                            <span class="news-pill news-pill-open">
                                <i class="far fa-comments"></i>
                                Comments Open
                            </span>
                        <?php endif; ?>
                    </div>

                    <h1 class="mt-4 text-3xl font-display font-semibold leading-tight sm:text-4xl">
                        <?php echo htmlspecialchars((string) ($news_item['title'] ?? 'School News')); ?>
                    </h1>
                    <?php if (!empty($news_item['excerpt'])): ?>
                        <p class="mt-3 max-w-2xl text-sm text-white/85 sm:text-base">
                            <?php echo htmlspecialchars((string) $news_item['excerpt']); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="details-meta-card">
                    <div class="details-meta-item">
                        <span>Published</span>
                        <strong><?php echo date('M d, Y', strtotime((string) $news_item['published_date'])); ?></strong>
                    </div>
                    <div class="details-meta-item">
                        <span>Views</span>
                        <strong><?php echo number_format((int) $news_item['view_count']); ?></strong>
                    </div>
                    <div class="details-meta-item">
                        <span>Audience</span>
                        <strong><?php echo htmlspecialchars($target_audience); ?></strong>
                    </div>
                    <div class="details-meta-item">
                        <span>Comments</span>
                        <strong><?php echo number_format(count($comments)); ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-[1.65fr_1fr]" data-reveal data-reveal-delay="70">
        <article class="news-panel rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft sm:p-6">
            <?php if (!empty($news_item['featured_image'])): ?>
                <div class="details-featured-wrap mb-5">
                    <img
                        src="../<?php echo htmlspecialchars((string) $news_item['featured_image']); ?>"
                        alt="<?php echo htmlspecialchars((string) ($news_item['title'] ?? 'School News')); ?>"
                        class="details-featured-image"
                        onerror="this.src='https://via.placeholder.com/900x500/0f766e/ffffff?text=School+News'"
                    >
                </div>
            <?php endif; ?>

            <div class="details-content">
                <?php echo nl2br(htmlspecialchars((string) ($news_item['content'] ?? ''))); ?>
            </div>

            <div class="details-footer mt-6">
                <div class="details-footer-item">
                    <i class="far fa-calendar"></i>
                    <span>Published <?php echo date('F d, Y', strtotime((string) $news_item['published_date'])); ?></span>
                </div>
                <div class="details-footer-item">
                    <i class="far fa-eye"></i>
                    <span><?php echo number_format((int) $news_item['view_count']); ?> views</span>
                </div>
            </div>

            <div id="comments" class="details-comments mt-8">
                <?php if (!empty($news_item['allow_comments'])): ?>
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h2 class="text-xl font-semibold text-ink-900">Comments (<?php echo count($comments); ?>)</h2>
                        <span class="text-xs uppercase tracking-wide text-slate-500">Student Discussion</span>
                    </div>

                    <form method="POST" class="comment-form">
                        <input type="hidden" name="action" value="comment">
                        <label for="comment" class="text-sm font-semibold text-slate-700">Add your comment</label>
                        <textarea
                            id="comment"
                            name="comment"
                            class="comment-textarea"
                            rows="4"
                            maxlength="1200"
                            placeholder="Share your thoughts respectfully..."
                            required
                        ></textarea>
                        <div class="comment-form-footer">
                            <p class="text-xs text-slate-500"><span id="commentCount">0</span>/1200</p>
                            <button type="submit" class="comment-submit-btn">
                                <i class="fas fa-paper-plane"></i>
                                <span>Post Comment</span>
                            </button>
                        </div>
                    </form>

                    <?php if (empty($comments)): ?>
                        <div class="news-empty-state mt-5">
                            <span class="news-empty-icon"><i class="far fa-comment-dots"></i></span>
                            <h3 class="text-lg font-semibold text-ink-900">No comments yet</h3>
                            <p class="mt-1 text-sm text-slate-500">Be the first to add your response.</p>
                        </div>
                    <?php else: ?>
                        <div class="comments-list mt-5 space-y-4">
                            <?php foreach ($comments as $comment): ?>
                                <article class="comment-card">
                                    <div class="comment-card-header">
                                        <div class="comment-avatar">
                                            <?php echo strtoupper(substr((string) ($comment['name'] ?? 'S'), 0, 1)); ?>
                                        </div>
                                        <div>
                                            <p class="comment-author"><?php echo htmlspecialchars((string) ($comment['name'] ?? 'Student')); ?></p>
                                            <p class="comment-time"><?php echo date('M d, Y • h:i A', strtotime((string) $comment['created_at'])); ?></p>
                                        </div>
                                    </div>
                                    <p class="comment-text"><?php echo nl2br(htmlspecialchars((string) ($comment['comment'] ?? ''))); ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="news-empty-state mt-2">
                        <span class="news-empty-icon"><i class="fas fa-comment-slash"></i></span>
                        <h3 class="text-lg font-semibold text-ink-900">Comments disabled</h3>
                        <p class="mt-1 text-sm text-slate-500">This announcement is read-only.</p>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <aside class="space-y-4">
            <section class="news-panel rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft">
                <h3 class="text-lg font-semibold text-ink-900">Post Details</h3>
                <div class="mt-3 space-y-2 text-sm text-slate-600">
                    <div><strong class="text-ink-900">Category:</strong> <?php echo htmlspecialchars((string) ($news_item['category'] ?? 'General')); ?></div>
                    <div><strong class="text-ink-900">Priority:</strong> <?php echo htmlspecialchars(ucfirst((string) ($news_item['priority'] ?? 'normal'))); ?></div>
                    <div><strong class="text-ink-900">Audience:</strong> <?php echo htmlspecialchars($target_audience); ?></div>
                    <div><strong class="text-ink-900">Comments:</strong> <?php echo !empty($news_item['allow_comments']) ? 'Enabled' : 'Disabled'; ?></div>
                </div>
            </section>

            <?php if (!empty($recent_news)): ?>
                <section class="news-panel rounded-3xl border border-ink-900/5 bg-white p-5 shadow-soft">
                    <h3 class="text-lg font-semibold text-ink-900">Recent Posts</h3>
                    <div class="mt-3 space-y-3">
                        <?php foreach ($recent_news as $recent): ?>
                            <a href="newsdetails.php?id=<?php echo (int) $recent['id']; ?>" class="recent-news-link">
                                <?php if (!empty($recent['featured_image'])): ?>
                                    <img
                                        src="../<?php echo htmlspecialchars((string) $recent['featured_image']); ?>"
                                        alt="<?php echo htmlspecialchars((string) $recent['title']); ?>"
                                        class="recent-news-image"
                                        onerror="this.style.display='none'"
                                    >
                                <?php else: ?>
                                    <span class="recent-news-placeholder"><i class="fas fa-newspaper"></i></span>
                                <?php endif; ?>
                                <span class="recent-news-text">
                                    <strong><?php echo htmlspecialchars((string) $recent['title']); ?></strong>
                                    <small><?php echo date('M d, Y', strtotime((string) $recent['published_date'])); ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebarOverlay = document.getElementById('studentSidebarOverlay');
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open');
        });
    }

    var commentInput = document.getElementById('comment');
    var commentCount = document.getElementById('commentCount');
    if (commentInput && commentCount) {
        var updateCount = function () {
            commentCount.textContent = String(commentInput.value.length);
        };
        commentInput.addEventListener('input', updateCount);
        updateCount();
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
});
</script>

<?php include __DIR__ . '/../includes/floating-button.php'; ?>
<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
