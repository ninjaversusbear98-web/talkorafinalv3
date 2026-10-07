<?php
require_once __DIR__ . '/config.php';
ensure_logged_in();

$db = db_connect();
$user = current_user();
$userId = (int) $user['id'];
$view = ($_GET['view'] ?? '') === 'reels' ? 'reels' : 'feed';
$visibility = $view === 'reels'
    ? '1=1'
    : "(p.user_id=$userId OR EXISTS (SELECT 1 FROM contacts c WHERE c.user_id=$userId AND c.contact_user_id=p.user_id))";

mysqli_query($db, "CREATE TABLE IF NOT EXISTS social_posts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, caption TEXT NOT NULL, media_type ENUM('image','video') DEFAULT NULL, media_path VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_social_posts (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS social_post_likes (post_id INT NOT NULL, user_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (post_id,user_id), FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS social_post_comments (id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, user_id INT NOT NULL, parent_comment_id INT DEFAULT NULL, comment TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (parent_comment_id) REFERENCES social_post_comments(id) ON DELETE CASCADE, INDEX idx_social_comments (post_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!table_has_column($db, 'social_post_comments', 'parent_comment_id')) {
    mysqli_query($db, "ALTER TABLE social_post_comments ADD COLUMN parent_comment_id INT DEFAULT NULL AFTER user_id");
    mysqli_query($db, "ALTER TABLE social_post_comments ADD CONSTRAINT fk_social_comment_parent FOREIGN KEY (parent_comment_id) REFERENCES social_post_comments(id) ON DELETE CASCADE");
}
mysqli_query($db, "CREATE TABLE IF NOT EXISTS social_comment_likes (comment_id INT NOT NULL, user_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (comment_id,user_id), FOREIGN KEY (comment_id) REFERENCES social_post_comments(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$notice = '';
$error = get_flash('community_error') ?: '';
$postLimitSetting = trim((string) ini_get('post_max_size'));
$postLimitUnit = strtolower(substr($postLimitSetting, -1));
$postLimitValue = (float) $postLimitSetting;
$postLimitMultiplier = $postLimitUnit === 'g' ? 1024 ** 3 : ($postLimitUnit === 'm' ? 1024 ** 2 : ($postLimitUnit === 'k' ? 1024 : 1));
$requestTooLarge = $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST)
    && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postLimitValue * $postLimitMultiplier;

if ($requestTooLarge) {
    $error = 'The upload exceeds this host’s PHP post_max_size limit. Ask your hosting provider to raise it.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postId = (int) ($_POST['post_id'] ?? 0);
    $postResult = $postId > 0 ? mysqli_query($db, "SELECT p.* FROM social_posts p WHERE p.id=$postId AND $visibility LIMIT 1") : false;
    $post = $postResult ? mysqli_fetch_assoc($postResult) : null;

    if ($action === 'create') {
        $caption = trim((string) ($_POST['caption'] ?? ''));
        $mediaType = null;
        $mediaPath = null;
        if (isset($_FILES['media']) && $_FILES['media']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['media'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
            if ($finfo) finfo_close($finfo);
            $types = [
                'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'],
                'image/webp' => ['image', 'webp'], 'image/gif' => ['image', 'gif'],
                'video/mp4' => ['video', 'mp4'], 'video/webm' => ['video', 'webm'],
                'video/ogg' => ['video', 'ogv'],
                'video/quicktime' => ['video', 'mov'], 'video/x-m4v' => ['video', 'm4v'],
                'video/3gpp' => ['video', '3gp'], 'video/3gpp2' => ['video', '3g2'],
            ];
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                $error = 'The video exceeds this host’s PHP upload_max_filesize limit. Ask your hosting provider to raise it.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK || !isset($types[$mime]) || $file['size'] > 20 * 1024 * 1024) {
                $error = 'Choose a supported photo or video up to 20 MB. Unsupported formats and lower host limits will be rejected.';
            } else {
                [$mediaType, $extension] = $types[$mime];
                $directory = __DIR__ . '/uploads/posts';
                if (!is_dir($directory)) mkdir($directory, 0775, true);
                $filename = 'post_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
                if (move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
                    $mediaPath = 'uploads/posts/' . $filename;
                } else {
                    $error = 'The upload could not be saved.';
                }
            }
        }

        if ($error === '' && $caption === '' && $mediaPath === null) $error = 'Add a caption or choose a photo/video.';
        if ($error === '') {
            $caption = mysqli_real_escape_string($db, mb_substr($caption, 0, 3000));
            $typeSql = $mediaType ? "'" . mysqli_real_escape_string($db, $mediaType) . "'" : 'NULL';
            $pathSql = $mediaPath ? "'" . mysqli_real_escape_string($db, $mediaPath) . "'" : 'NULL';
            if (mysqli_query($db, "INSERT INTO social_posts (user_id,caption,media_type,media_path) VALUES ($userId,'$caption',$typeSql,$pathSql)")) redirect('community.php' . ($view === 'reels' ? '?view=reels' : ''));
            $error = 'The post could not be published.';
        }
    } elseif ($action === 'like' && $post) {
        $liked = mysqli_query($db, "SELECT post_id FROM social_post_likes WHERE post_id=$postId AND user_id=$userId LIMIT 1");
        $isLiked = $liked && mysqli_num_rows($liked) > 0;
        if ($isLiked) mysqli_query($db, "DELETE FROM social_post_likes WHERE post_id=$postId AND user_id=$userId");
        else mysqli_query($db, "INSERT IGNORE INTO social_post_likes (post_id,user_id) VALUES ($postId,$userId)");
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            $countResult = mysqli_query($db, "SELECT COUNT(*) AS total FROM social_post_likes WHERE post_id=$postId");
            $likeCount = (int) (mysqli_fetch_assoc($countResult)['total'] ?? 0);
            echo json_encode(['success' => true, 'liked' => !$isLiked, 'count' => $likeCount]);
            exit;
        }
        redirect('community.php' . ($view === 'reels' ? '?view=reels#post-' : '#post-') . $postId);
    } elseif ($action === 'comment_like' && $post) {
        $commentId = (int) ($_POST['comment_id'] ?? 0);
        $commentResult = mysqli_query($db, "SELECT id FROM social_post_comments WHERE id=$commentId AND post_id=$postId LIMIT 1");
        if ($commentId > 0 && $commentResult && mysqli_num_rows($commentResult) > 0) {
            $likeResult = mysqli_query($db, "SELECT comment_id FROM social_comment_likes WHERE comment_id=$commentId AND user_id=$userId LIMIT 1");
            if ($likeResult && mysqli_num_rows($likeResult) > 0) mysqli_query($db, "DELETE FROM social_comment_likes WHERE comment_id=$commentId AND user_id=$userId");
            else mysqli_query($db, "INSERT IGNORE INTO social_comment_likes (comment_id,user_id) VALUES ($commentId,$userId)");
        }
        redirect('community.php' . ($view === 'reels' ? '?view=reels#post-' : '#post-') . $postId);
    } elseif ($action === 'comment' && $post) {
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $gifCommentPath = null;
        $selectedGif = trim((string) ($_POST['gif_url'] ?? ''));
        if ($selectedGif !== '') {
            $gifHost = strtolower((string) parse_url($selectedGif, PHP_URL_HOST));
            $gifPath = (string) parse_url($selectedGif, PHP_URL_PATH);
            if (!preg_match('/^media\d*\.giphy\.com$/', $gifHost) || !preg_match('#^/media/[A-Za-z0-9_-]+/giphy\.gif$#', $gifPath)) {
                http_response_code(400);
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                    echo json_encode(['success' => false, 'message' => 'Choose a GIF from the picker.']);
                    exit;
                }
                set_flash('community_error', 'Choose a GIF from the picker.');
                redirect('community.php' . ($view === 'reels' ? '?view=reels#post-' : '#post-') . $postId);
            }
            $gifCommentPath = $selectedGif;
        }
        if ($comment !== '' || $gifCommentPath !== null) {
            $comment = mysqli_real_escape_string($db, mb_substr($comment, 0, 1200));
            if ($gifCommentPath !== null) $comment = '__TALKORA_GIF__:' . mysqli_real_escape_string($db, $gifCommentPath);
            $parentId = (int) ($_POST['parent_comment_id'] ?? 0);
            $parentSql = 'NULL';
            if ($parentId > 0) {
                $parentResult = mysqli_query($db, "SELECT id FROM social_post_comments WHERE id=$parentId AND post_id=$postId LIMIT 1");
                if ($parentResult && mysqli_num_rows($parentResult) > 0) $parentSql = (string) $parentId;
            }
            $created = mysqli_query($db, "INSERT INTO social_post_comments (post_id,user_id,parent_comment_id,comment) VALUES ($postId,$userId,$parentSql,'$comment')");
            if ($created && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                $commentId = mysqli_insert_id($db);
                $commentRow = mysqli_fetch_assoc(mysqli_query($db, "SELECT id,comment FROM social_post_comments WHERE id=$commentId LIMIT 1"));
                $countResult = mysqli_query($db, "SELECT COUNT(*) AS total FROM social_post_comments WHERE post_id=$postId");
                echo json_encode(['success' => true, 'id' => $commentId, 'comment' => $commentRow['comment'], 'author' => $user['full_name'], 'user_id' => $userId, 'count' => (int) (mysqli_fetch_assoc($countResult)['total'] ?? 0)]);
                exit;
            }
        }
        redirect('community.php' . ($view === 'reels' ? '?view=reels#post-' : '#post-') . $postId);
    } elseif ($action === 'delete' && $post && (int) $post['user_id'] === $userId) {
        if (!empty($post['media_path'])) {
            $mediaPath = mysqli_real_escape_string($db, (string) $post['media_path']);
            $avatarUse = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS total FROM users WHERE avatar='$mediaPath'"));
            $postUse = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS total FROM social_posts WHERE media_path='$mediaPath' AND id<>$postId"));
            if ((int) ($avatarUse['total'] ?? 0) === 0 && (int) ($postUse['total'] ?? 0) === 0) {
                $path = __DIR__ . '/' . ltrim((string) $post['media_path'], '/');
                if (is_file($path)) unlink($path);
            }
        }
        mysqli_query($db, "DELETE FROM social_posts WHERE id=$postId AND user_id=$userId");
        redirect('community.php' . ($view === 'reels' ? '?view=reels' : ''));
    } else {
        $error = 'That post is not available in your feed.';
    }
}

$mediaFilter = $view === 'reels' ? "AND p.media_type='video'" : '';
$feedPage = max(1, min(100, (int) ($_GET['page'] ?? 1)));
$postOffset = ($feedPage - 1) * 24;
$postsResult = mysqli_query($db, "SELECT p.*,UNIX_TIMESTAMP(p.created_at) AS created_epoch,u.full_name,u.avatar,(SELECT COUNT(*) FROM social_post_likes l WHERE l.post_id=p.id) AS like_count,(SELECT COUNT(*) FROM social_post_comments cm WHERE cm.post_id=p.id) AS comment_count,(SELECT COUNT(*) FROM social_post_likes mine WHERE mine.post_id=p.id AND mine.user_id=$userId) AS is_liked FROM social_posts p JOIN users u ON u.id=p.user_id WHERE $visibility $mediaFilter ORDER BY p.created_at DESC LIMIT 24 OFFSET $postOffset");
$posts = [];
while ($postsResult && ($row = mysqli_fetch_assoc($postsResult))) $posts[] = $row;
$postIds = array_map(static fn($post) => (int) $post['id'], $posts);
$commentsByPost = [];
if ($postIds) {
    $commentsResult = mysqli_query($db, "SELECT c.*,u.full_name,(SELECT COUNT(*) FROM social_comment_likes l WHERE l.comment_id=c.id) AS like_count,(SELECT COUNT(*) FROM social_comment_likes mine WHERE mine.comment_id=c.id AND mine.user_id=$userId) AS is_liked FROM social_post_comments c JOIN users u ON u.id=c.user_id WHERE c.post_id IN (" . implode(',', $postIds) . ") ORDER BY c.id DESC LIMIT 120");
    while ($commentsResult && ($row = mysqli_fetch_assoc($commentsResult))) $commentsByPost[(int) $row['post_id']][] = $row;
    foreach ($commentsByPost as &$postComments) $postComments = array_reverse($postComments);
    unset($postComments);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $view === 'reels' ? 'Reels' : 'Feed'; ?> | TalkOra</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#2563eb">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="social-page <?php echo normalize_theme($user['theme']) === 'light' ? 'light-theme' : ''; ?> <?php echo $view === 'reels' ? 'reels-mode' : ''; ?>" data-current-user-id="<?php echo $userId; ?>" data-notifications-enabled="<?php echo (int) $user['notifications']; ?>" style="--user-font-size:<?php echo max(12, min(22, (int) $user['font_size'])); ?>px;--user-font-family:<?php echo htmlspecialchars(json_encode($user['font_family'] ?: 'Segoe UI'), ENT_QUOTES, 'UTF-8'); ?>">
    <main class="community-shell">
        <header class="social-topbar">
            <a class="social-brand" href="dashboard.php">TalkOra</a>
            <nav class="social-nav" aria-label="Main navigation"><a href="dashboard.php">Chats</a><a class="<?php echo $view === 'feed' ? 'active' : ''; ?>" href="community.php">Feed</a><a class="<?php echo $view === 'reels' ? 'active' : ''; ?>" href="community.php?view=reels">Reels</a><a href="groups.php">Groups</a><a href="communities.php">Communities</a></nav>
            <a class="btn btn-sm btn-outline-light" href="logout.php">Log out</a>
        </header>
        <section class="community-content">
            <?php if ($view === 'feed'): ?><div class="community-heading"><div><span class="eyebrow">Your circle</span><h1>Community feed</h1></div><div class="community-count"><?php echo count($posts); ?> posts</div></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <?php if ($view === 'feed'): ?>
                <form method="post" enctype="multipart/form-data" class="post-composer">
                    <input type="hidden" name="action" value="create">
                    <label for="postCaption">Share an update</label>
                    <textarea id="postCaption" name="caption" maxlength="3000" rows="3" placeholder="What would you like to share?"></textarea>
                    <div class="post-composer-tools"><label class="media-select" for="postMedia"><span aria-hidden="true">＋</span> Photo or video<input id="postMedia" type="file" name="media" accept="image/*,video/*"></label><span class="upload-hint">Photos and videos from this device</span><button class="btn btn-success" type="submit">Publish</button></div>
                </form>
            <?php endif; ?>
            <div class="<?php echo $view === 'reels' ? 'reels-feed' : 'posts-feed'; ?>">
                <?php foreach ($posts as $postIndex => $post): $postId = (int) $post['id']; $avatar = !empty($post['avatar']) && is_file(__DIR__ . '/' . $post['avatar']) ? $post['avatar'] : ''; ?>
                    <article class="social-post <?php echo $view === 'reels' ? 'reel-post' : ''; ?>" id="post-<?php echo $postId; ?>">
                        <header class="post-author">
                            <?php if ($avatar): ?><img src="<?php echo htmlspecialchars($avatar); ?>" alt="" class="post-avatar"><?php else: ?><span class="post-avatar post-avatar-initial"><?php echo htmlspecialchars(strtoupper(substr($post['full_name'], 0, 1))); ?></span><?php endif; ?>
                            <div><strong><a class="profile-name-link" href="<?php echo (int) $post['user_id'] === $userId ? 'profile.php' : 'dashboard.php?user_id=' . (int) $post['user_id'] . '&amp;view=profile'; ?>"><?php echo htmlspecialchars($post['full_name']); ?></a></strong><time class="local-time" data-epoch="<?php echo (int) ($post['created_epoch'] ?? 0); ?>"><?php echo date('M j, Y · g:i a', strtotime($post['created_at'])); ?></time></div>
                            <?php if ((int) $post['user_id'] === $userId): ?><form method="post" class="post-delete"><input type="hidden" name="action" value="delete"><input type="hidden" name="post_id" value="<?php echo $postId; ?>"><button type="submit" aria-label="Delete post" title="Delete post">×</button></form><?php endif; ?>
                        </header>
                        <?php if ($post['caption'] !== ''): ?><p class="post-caption"><?php echo nl2br(htmlspecialchars($post['caption'])); ?></p><?php endif; ?>
                        <?php if ($post['media_type'] === 'image' && $post['media_path']): ?><img class="post-media" src="<?php echo htmlspecialchars($post['media_path']); ?>" alt="Photo shared by <?php echo htmlspecialchars($post['full_name']); ?>" loading="<?php echo $postIndex < 2 ? 'eager' : 'lazy'; ?>" fetchpriority="<?php echo $postIndex === 0 ? 'high' : 'auto'; ?>" decoding="async"><?php elseif ($post['media_type'] === 'video' && $post['media_path']): ?><video class="post-media post-video" src="<?php echo htmlspecialchars($post['media_path']); ?>" controls muted playsinline preload="none" <?php echo $view === 'reels' ? '' : 'loop'; ?>></video><?php endif; ?>
                        <?php if ($view === 'reels'): ?>
                            <div class="reel-actions" aria-label="Reel actions">
                                <form method="post" data-reel-like-form><input type="hidden" name="action" value="like"><input type="hidden" name="post_id" value="<?php echo $postId; ?>"><button class="reel-action-button post-like <?php echo (int) $post['is_liked'] ? 'liked' : ''; ?>" type="submit" aria-label="<?php echo (int) $post['is_liked'] ? 'Unlike reel' : 'Like reel'; ?>"><span class="reel-action-icon" aria-hidden="true"><?php echo (int) $post['is_liked'] ? '♥' : '♡'; ?></span><span class="reel-action-count" data-like-count><?php echo (int) $post['like_count']; ?></span></button></form>
                                <button type="button" class="reel-action-button" data-open-reel-comments aria-label="Open comments"><span class="reel-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H6l-3 2v-6.5A7.5 7.5 0 1 1 20 11.5Z"/></svg></span><span class="reel-action-count" data-comment-count><?php echo (int) $post['comment_count']; ?></span></button>
                                <button type="button" class="reel-action-button" data-toggle-reel-settings aria-label="Reel settings" aria-expanded="false"><span class="reel-action-icon" aria-hidden="true">⚙</span></button>
                            </div>
                            <div class="reel-settings-menu" data-reel-settings hidden>
                                <label><input type="checkbox" data-reel-auto-next checked> Auto-play next reel</label>
                                <label><input type="checkbox" data-reel-loop> Loop current reel</label>
                                <label><input type="checkbox" data-reel-sound checked> Start with sound</label>
                            </div>
                        <?php else: ?>
                            <div class="post-counts"><span><?php echo (int) $post['like_count']; ?> likes</span><span><?php echo (int) $post['comment_count']; ?> comments</span></div>
                            <div class="post-actions"><form method="post"><input type="hidden" name="action" value="like"><input type="hidden" name="post_id" value="<?php echo $postId; ?>"><button class="post-like <?php echo (int) $post['is_liked'] ? 'liked' : ''; ?>" type="submit" aria-label="<?php echo (int) $post['is_liked'] ? 'Unlike post' : 'Like post'; ?>"><?php echo (int) $post['is_liked'] ? '♥ Liked' : '♡ Like'; ?></button></form><a href="#comment-<?php echo $postId; ?>">Comment</a></div>
                        <?php endif; ?>
                        <div class="post-comments <?php echo $view === 'reels' ? 'reel-comments-sheet' : ''; ?>" <?php echo $view === 'reels' ? 'data-reel-comments-sheet hidden' : ''; ?>>
                            <?php if ($view === 'reels'): ?><div class="reel-comments-heading"><strong>Comments</strong><button type="button" data-close-reel-comments aria-label="Close comments">×</button></div><div class="reel-comment-list"><?php endif; ?>
                            <?php foreach (($commentsByPost[$postId] ?? []) as $comment): ?>
                                <div class="social-comment <?php echo !empty($comment['parent_comment_id']) ? 'is-reply' : ''; ?>" id="comment-<?php echo (int) $comment['id']; ?>">
                                    <p><strong><a class="profile-name-link" href="<?php echo (int) $comment['user_id'] === $userId ? 'profile.php' : 'dashboard.php?user_id=' . (int) $comment['user_id'] . '&amp;view=profile'; ?>"><?php echo htmlspecialchars($comment['full_name']); ?></a></strong> <?php $commentGif = str_starts_with((string) $comment['comment'], '__TALKORA_GIF__:') ? substr((string) $comment['comment'], 16) : ''; $gifHost = strtolower((string) parse_url($commentGif, PHP_URL_HOST)); $gifPath = (string) parse_url($commentGif, PHP_URL_PATH); $isGifComment = preg_match('/^media\d*\.giphy\.com$/', $gifHost) && preg_match('#^/media/[A-Za-z0-9_-]+/giphy\.gif$#', $gifPath); if ($isGifComment): ?><img class="comment-gif" src="<?php echo htmlspecialchars($commentGif, ENT_QUOTES, 'UTF-8'); ?>" alt="GIF comment" loading="lazy" decoding="async"><?php elseif (str_starts_with((string) $comment['comment'], '__TALKORA_GIF__:') && preg_match('#^uploads/comments/comment_[A-Za-z0-9_.-]+\.gif$#', substr((string) $comment['comment'], 16))): ?><img class="comment-gif" src="<?php echo htmlspecialchars(substr((string) $comment['comment'], 16), ENT_QUOTES, 'UTF-8'); ?>" alt="GIF comment" loading="lazy" decoding="async"><?php else: ?> <?php echo nl2br(htmlspecialchars($comment['comment'])); ?><?php endif; ?></p>
                                    <div class="comment-tools">
                                        <form method="post"><input type="hidden" name="action" value="comment_like"><input type="hidden" name="post_id" value="<?php echo $postId; ?>"><input type="hidden" name="comment_id" value="<?php echo (int) $comment['id']; ?>"><button class="comment-like <?php echo (int) $comment['is_liked'] ? 'liked' : ''; ?>" type="submit"><?php echo (int) $comment['is_liked'] ? '♥ Liked' : '♡ Like'; ?> <span><?php echo (int) $comment['like_count']; ?></span></button></form>
                                        <button class="comment-reply-toggle" type="button" aria-expanded="false" aria-controls="reply-form-<?php echo (int) $comment['id']; ?>">Reply</button>
                                    </div>
                                    <form method="post" class="comment-form comment-reply-form" id="reply-form-<?php echo (int) $comment['id']; ?>" hidden><input type="hidden" name="action" value="comment"><input type="hidden" name="post_id" value="<?php echo $postId; ?>"><input type="hidden" name="parent_comment_id" value="<?php echo (int) $comment['id']; ?>"><input type="hidden" name="gif_url" value=""><label class="visually-hidden" for="replyText<?php echo (int) $comment['id']; ?>">Reply to comment</label><input id="replyText<?php echo (int) $comment['id']; ?>" name="comment" maxlength="1200" placeholder="Write a reply..."><button type="button" class="comment-gif-picker" data-open-gif-picker aria-label="Choose GIF">GIF</button><button type="submit">Reply</button></form>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($view === 'reels'): ?></div><?php endif; ?>
                            <form method="post" class="comment-form" id="comment-<?php echo $postId; ?>" <?php echo $view === 'reels' ? 'data-reel-comment-form' : ''; ?>><input type="hidden" name="action" value="comment"><input type="hidden" name="post_id" value="<?php echo $postId; ?>"><input type="hidden" name="gif_url" value=""><label class="visually-hidden" for="commentText<?php echo $postId; ?>">Write a comment</label><input id="commentText<?php echo $postId; ?>" name="comment" maxlength="1200" placeholder="Write a comment..."><button type="button" class="comment-gif-picker" data-open-gif-picker aria-label="Choose GIF">GIF</button><button type="submit" aria-label="Post comment">Post</button></form>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (count($posts) === 24): ?>
                    <nav class="feed-pagination" aria-label="Feed pages">
                        <?php if ($feedPage > 1): ?><a href="community.php<?php echo $view === 'reels' ? '?view=reels&amp;page=' : '?page='; ?><?php echo $feedPage - 1; ?>">Newer posts</a><?php endif; ?>
                        <a href="community.php<?php echo $view === 'reels' ? '?view=reels&amp;page=' : '?page='; ?><?php echo $feedPage + 1; ?>">Older posts</a>
                    </nav>
                <?php endif; ?>
                <?php if (empty($posts)): ?><div class="community-empty"><span class="empty-mark"><?php echo $view === 'reels' ? '▶' : '✳'; ?></span><h2><?php echo $view === 'reels' ? 'No reels yet' : 'Your feed is quiet'; ?></h2><p><?php echo $view === 'reels' ? 'Video posts from you and your contacts will show up here.' : 'Posts from your contacts will show up here. Share the first update to get things started.'; ?></p></div><?php endif; ?>
            </div>
        </section>
    </main>
    <section class="gif-picker-overlay" id="gifPicker" aria-label="Choose a GIF" hidden>
        <div class="gif-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="gifPickerTitle">
            <header><strong id="gifPickerTitle">Choose a GIF</strong><button type="button" data-close-gif-picker aria-label="Close GIF picker">×</button></header>
            <input type="search" id="gifSearch" placeholder="Search GIFs" aria-label="Search GIFs">
            <div class="gif-picker-grid" id="gifPickerGrid"></div>
            <div class="gif-picker-credit">GIFs by GIPHY</div>
        </div>
    </section>
        <button type="button" class="back-to-top" aria-label="Back to top" title="Back to top">↑</button>
        <script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
        <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(function(){}); }</script>
        <script src="assets/js/community.js?v=<?php echo filemtime(__DIR__ . '/assets/js/community.js'); ?>"></script>
</body>
</html>