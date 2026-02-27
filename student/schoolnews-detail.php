<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Get news ID from URL
$news_id = intval($_GET['id'] ?? 0);
if ($news_id <= 0) {
    header("Location: schoolnews.php");
    exit;
}

$current_school_id = get_current_school_id();

// Fetch news details
$stmt = $pdo->prepare("SELECT sn.*, u.full_name as author_name
                      FROM school_news sn
                      JOIN users u ON sn.author_id = u.id
                      WHERE sn.id = :id AND sn.school_id = :school_id");
$stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);
$news = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$news) {
    header("Location: schoolnews.php");
    exit;
}

// Check permissions: principal can view all, others only published
$is_principal = isset($_SESSION['user_id']) && $_SESSION['role'] === 'principal';
$user_id = $_SESSION['user_id'] ?? null;

if ($news['status'] !== 'published' && !$is_principal) {
    header("Location: schoolnews.php");
    exit;
}

// Increment view count
$stmt = $pdo->prepare("UPDATE school_news SET view_count = view_count + 1 WHERE id = :id AND school_id = :school_id");
$stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);

// Track view (if user logged in)
if ($user_id) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO school_news_views (news_id, user_id, ip_address, school_id)
                          VALUES (:news_id, :user_id, :ip_address, :school_id)");
    $stmt->execute([
        'news_id' => $news_id,
        'user_id' => $user_id,
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'school_id' => $current_school_id
    ]);
}

$errors = [];
$success = '';

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $news['allow_comments']) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_comment') {
        $commenter_name = trim($_POST['name'] ?? '');
        $commenter_email = trim($_POST['email'] ?? '');
        $comment_text = trim($_POST['comment'] ?? '');

        if ($commenter_name === '') {
            $errors[] = 'Name is required.';
        }
        if ($commenter_email === '' || !filter_var($commenter_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required.';
        }
        if ($comment_text === '') {
            $errors[] = 'Comment cannot be empty.';
        }
        if (strlen($comment_text) > 1000) {
            $errors[] = 'Comment must not exceed 1000 characters.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO school_news_comments
                                  (news_id, user_id, name, email, comment, status, school_id)
                                  VALUES (:news_id, :user_id, :name, :email, :comment, :status, :school_id)");
            $stmt->execute([
                'news_id' => $news_id,
                'user_id' => $user_id,
                'name' => $commenter_name,
                'email' => $commenter_email,
                'comment' => $comment_text,
                'status' => 'pending',
                'school_id' => $current_school_id
            ]);
            $success = 'Your comment has been submitted and is awaiting moderation.';
            // Refresh page
            header("Location: schoolnews-detail.php?id=" . $news_id);
            exit;
        }
    }

    if ($action === 'delete_comment' && $is_principal) {
        $comment_id = intval($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM school_news_comments WHERE id = :id AND news_id = :news_id AND school_id = :school_id");
            $stmt->execute(['id' => $comment_id, 'news_id' => $news_id, 'school_id' => $current_school_id]);
            $success = 'Comment deleted.';
            header("Location: schoolnews-detail.php?id=" . $news_id);
            exit;
        }
    }

    if ($action === 'approve_comment' && $is_principal) {
        $comment_id = intval($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $stmt = $pdo->prepare("UPDATE school_news_comments SET status = :status WHERE id = :id AND news_id = :news_id AND school_id = :school_id");
            $stmt->execute(['status' => 'approved', 'id' => $comment_id, 'news_id' => $news_id, 'school_id' => $current_school_id]);
            $success = 'Comment approved.';
            header("Location: schoolnews-detail.php?id=" . $news_id);
            exit;
        }
    }

    if ($action === 'reject_comment' && $is_principal) {
        $comment_id = intval($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $stmt = $pdo->prepare("UPDATE school_news_comments SET status = :status WHERE id = :id AND news_id = :news_id AND school_id = :school_id");
            $stmt->execute(['status' => 'rejected', 'id' => $comment_id, 'news_id' => $news_id, 'school_id' => $current_school_id]);
            $success = 'Comment rejected.';
            header("Location: schoolnews-detail.php?id=" . $news_id);
            exit;
        }
    }
}

// Fetch approved comments (and pending if principal)
$comment_query = "SELECT * FROM school_news_comments WHERE news_id = :news_id AND school_id = :school_id";
if ($is_principal) {
    $comment_query .= " ORDER BY created_at DESC";
} else {
    $comment_query .= " AND status = 'approved' ORDER BY created_at DESC";
}

$stmt = $pdo->prepare($comment_query);
$stmt->execute(['news_id' => $news_id, 'school_id' => $current_school_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get related news (same category, excluding current)
$stmt = $pdo->prepare("SELECT id, title, featured_image, excerpt, published_date
                      FROM school_news
                      WHERE category = :category AND id != :id AND status = 'published' AND school_id = :school_id
                      ORDER BY published_date DESC
                      LIMIT 4");
$stmt->execute(['category' => $news['category'], 'id' => $news_id, 'school_id' => $current_school_id]);
$related_news = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format content (handle markdown-like formatting)
function formatContent($text) {
    // Convert markdown-like syntax to HTML
    $text = htmlspecialchars($text);
    
    // Bold **text**
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    
    // Italic *text*
    $text = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $text);
    
    // Underline __text__
    $text = preg_replace('/__(.+?)__/s', '<u>$1</u>', $text);
    
    // Headings ## text
    $text = preg_replace('/^## (.*?)$/m', '<h2 class="detail-h2">$1</h2>', $text);
    $text = preg_replace('/^### (.*?)$/m', '<h3 class="detail-h3">$1</h3>', $text);
    
    // Code blocks ```code```
    $text = preg_replace('/```(.*?)```/s', '<pre class="code-block"><code>$1</code></pre>', $text);
    
    // Inline code `code`
    $text = preg_replace('/`(.+?)`/', '<code class="inline-code">$1</code>', $text);
    
    // Blockquotes > text
    $text = preg_replace('/^> (.*?)$/m', '<blockquote class="detail-quote">$1</blockquote>', $text);
    
    // Line breaks
    $text = nl2br($text);
    
    return $text;
}

$user_name = $_SESSION['full_name'] ?? 'Guest';
$user_role = $_SESSION['role'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?php echo htmlspecialchars($news['title']); ?> | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/schoolnews-detail.css">
</head>
<body>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-right">
            <div class="school-logo-container">
                <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
            </div>
        </div>

        <div class="header-left">
            <div class="teacher-info">
                <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="teacher-role"><?php echo ucfirst($user_role); ?></span>
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="detail-container">
    <!-- Back navigation -->
    <div class="detail-nav">
        <a href="schoolnews.php" class="btn-back">← Back to News</a>
    </div>

    <main class="detail-main">
        <!-- Article Section -->
        <article class="news-article">
            <!-- Header -->
            <header class="article-header">
                <div class="article-meta">
                    <span class="category-badge"><?php echo htmlspecialchars($news['category']); ?></span>
                    <span class="priority-badge priority-<?php echo $news['priority']; ?>">
                        <?php echo ucfirst($news['priority']); ?> Priority
                    </span>
                    <span class="status-badge status-<?php echo $news['status']; ?>">
                        <?php echo ucfirst($news['status']); ?>
                    </span>
                </div>

                <h1 class="article-title"><?php echo htmlspecialchars($news['title']); ?></h1>

                <div class="article-info">
                    <div class="info-item">
                        <span class="label">By</span>
                        <span class="value"><?php echo htmlspecialchars($news['author_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Published</span>
                        <span class="value"><?php echo date('F d, Y • h:i A', strtotime($news['published_date'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">For</span>
                        <span class="value"><?php echo htmlspecialchars($news['target_audience']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Views</span>
                        <span class="value"><?php echo intval($news['view_count']); ?></span>
                    </div>
                </div>
            </header>

            <!-- Featured Image -->
            <?php if ($news['featured_image']): ?>
            <div class="article-featured">
                <img src="../<?php echo htmlspecialchars($news['featured_image']); ?>" 
                     alt="<?php echo htmlspecialchars($news['title']); ?>" 
                     class="featured-image">
            </div>
            <?php endif; ?>

            <!-- Excerpt -->
            <?php if ($news['excerpt']): ?>
            <div class="article-excerpt">
                <p><?php echo htmlspecialchars($news['excerpt']); ?></p>
            </div>
            <?php endif; ?>

            <!-- Content -->
            <div class="article-content">
                <?php echo formatContent($news['content']); ?>
            </div>

            <!-- Tags -->
            <?php if ($news['tags']): ?>
            <div class="article-tags">
                <span class="tags-label">Tags:</span>
                <?php 
                $tags = array_map('trim', explode(',', $news['tags']));
                foreach ($tags as $tag): 
                ?>
                    <a href="schoolnews.php?search=<?php echo urlencode($tag); ?>" class="tag">
                        #<?php echo htmlspecialchars($tag); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Admin Actions -->
            <?php if ($is_principal): ?>
            <div class="admin-actions">
                <!-- <a href="schoolnews.php?edit=<?php echo intval($news['id']); ?>" class="btn-gold">✏️ Edit</a> -->
                <!-- <a href="schoolnews.php" class="btn-secondary">📋 All News</a> -->
            </div>
            <?php endif; ?>
        </article>

        <!-- Errors/Success -->
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Comments Section -->
        <?php if ($news['allow_comments']): ?>
        <section class="comments-section">
            <h2 class="section-title">💬 Comments</h2>

            <!-- Comment Form -->
            <div class="comment-form-wrapper">
                <h3>Add Your Comment</h3>
                <form method="POST" class="comment-form">
                    <input type="hidden" name="action" value="add_comment">

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="name">Name *</label>
                                <input type="text" id="name" name="name" class="form-control" 
                                       placeholder="Your name" required maxlength="100">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="email">Email *</label>
                                <input type="email" id="email" name="email" class="form-control" 
                                       placeholder="your.email@example.com" required maxlength="100">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="comment">Comment *</label>
                        <textarea id="comment" name="comment" class="form-control" rows="4" 
                                  placeholder="Share your thoughts... (max 1000 characters)" 
                                  required maxlength="1000"></textarea>
                        <small class="char-count"><span id="char-current">0</span>/1000</small>
                    </div>

                    <button type="submit" class="btn-gold">Post Comment</button>
                </form>
            </div>

            <!-- Comments List -->
            <div class="comments-list">
                <h3><?php echo count($comments); ?> Comment<?php echo count($comments) !== 1 ? 's' : ''; ?></h3>

                <?php if (count($comments) === 0): ?>
                    <div class="no-comments">
                        <p>No comments yet. Be the first to comment!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item">
                            <div class="comment-header">
                                <div class="comment-author">
                                    <strong><?php echo htmlspecialchars($comment['name']); ?></strong>
                                    <span class="comment-date">
                                        <?php echo date('M d, Y • h:i A', strtotime($comment['created_at'])); ?>
                                    </span>
                                </div>
                                <?php if ($is_principal): ?>
                                    <div class="comment-status">
                                        <?php if ($comment['status'] === 'pending'): ?>
                                            <span class="status-pending">⏳ Pending</span>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="approve_comment">
                                                <input type="hidden" name="comment_id" value="<?php echo intval($comment['id']); ?>">
                                                <button type="submit" class="btn-approve-comment" title="Approve">✓</button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="reject_comment">
                                                <input type="hidden" name="comment_id" value="<?php echo intval($comment['id']); ?>">
                                                <button type="submit" class="btn-reject-comment" title="Reject">✗</button>
                                            </form>
                                        <?php elseif ($comment['status'] === 'approved'): ?>
                                            <span class="status-approved">✓ Approved</span>
                                        <?php else: ?>
                                            <span class="status-rejected">✗ Rejected</span>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this comment?');">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?php echo intval($comment['id']); ?>">
                                            <button type="submit" class="btn-delete-comment" title="Delete">🗑️</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="comment-body">
                                <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        <?php else: ?>
            <div class="alert alert-info">
                <p>💭 Comments are disabled for this news item.</p>
            </div>
        <?php endif; ?>

        <!-- Related News -->
        <?php if (count($related_news) > 0): ?>
        <section class="related-section">
            <h2 class="section-title">📚 Related News</h2>
            <div class="related-grid">
                <?php foreach ($related_news as $item): ?>
                <div class="related-card">
                    <?php if ($item['featured_image']): ?>
                        <img src="../<?php echo htmlspecialchars($item['featured_image']); ?>" 
                             alt="<?php echo htmlspecialchars($item['title']); ?>" 
                             class="related-image">
                    <?php else: ?>
                        <div class="related-image-placeholder">📰</div>
                    <?php endif; ?>
                    <div class="related-content">
                        <h4><?php echo htmlspecialchars($item['title']); ?></h4>
                        <p><?php echo htmlspecialchars(substr($item['excerpt'], 0, 100)); ?>...</p>
                        <small><?php echo date('M d, Y', strtotime($item['published_date'])); ?></small>
                        <a href="schoolnews-detail.php?id=<?php echo intval($item['id']); ?>" class="read-more">Read More →</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <!-- Sidebar -->
    <aside class="detail-sidebar">
        <!-- Share Section -->
        <div class="sidebar-card">
            <h3>📤 Share</h3>
            <div class="share-buttons">
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" 
                   class="share-btn facebook" target="_blank" title="Share on Facebook">f</a>
                <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>&text=<?php echo urlencode($news['title']); ?>" 
                   class="share-btn twitter" target="_blank" title="Share on Twitter">𝕏</a>
                <a href="https://wa.me/?text=<?php echo urlencode($news['title'] . ' ' . 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" 
                   class="share-btn whatsapp" target="_blank" title="Share on WhatsApp">W</a>
                <a href="mailto:?subject=<?php echo urlencode($news['title']); ?>&body=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" 
                   class="share-btn email" title="Share via Email">✉️</a>
            </div>
        </div>

        <!-- News Info -->
        <div class="sidebar-card">
            <h3>ℹ️ News Info</h3>
            <div class="info-list">
                <div class="info-row">
                    <span class="label">Category</span>
                    <span class="value"><?php echo htmlspecialchars($news['category']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Priority</span>
                    <span class="value"><?php echo ucfirst($news['priority']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Audience</span>
                    <span class="value"><?php echo htmlspecialchars($news['target_audience']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Status</span>
                    <span class="value"><?php echo ucfirst($news['status']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Views</span>
                    <span class="value"><?php echo intval($news['view_count']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Comments</span>
                    <span class="value"><?php echo count($comments); ?></span>
                </div>
            </div>
        </div>

        <!-- Latest News -->
        <div class="sidebar-card">
            <h3>📰 Latest News</h3>
            <div class="latest-list">
                <?php
                $stmt = $pdo->prepare("SELECT id, title, published_date FROM school_news
                                      WHERE status = 'published' AND id != :id AND school_id = :school_id
                                      ORDER BY published_date DESC LIMIT 5");
                $stmt->execute(['id' => $news_id, 'school_id' => $current_school_id]);
                $latest = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($latest as $item):
                ?>
                    <a href="schoolnews-detail.php?id=<?php echo intval($item['id']); ?>" class="latest-item">
                        <span><?php echo htmlspecialchars(substr($item['title'], 0, 40)); ?></span>
                        <small><?php echo date('M d', strtotime($item['published_date'])); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Professional school management system.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php">Dashboard</a>
                    <a href="schoolnews.php">News Management</a>
                    <a href="manage_user.php">Users</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p>Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Version 1.0</p>
        </div>
    </div>
</footer>

<script>
// Character counter for comment
const commentTextarea = document.getElementById('comment');
const charCurrent = document.getElementById('char-current');

if (commentTextarea) {
    commentTextarea.addEventListener('input', function() {
        charCurrent.textContent = this.value.length;
    });
}
</script>

<?php include '../includes/floating-button.php'; ?>
</body>
</html>
