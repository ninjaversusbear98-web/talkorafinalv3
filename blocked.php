<?php
require_once __DIR__ . '/config.php';
ensure_logged_in();

$db = db_connect();
$userId = (int) $_SESSION['user_id'];
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_blocked_users (user_id INT NOT NULL,blocked_user_id INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,blocked_user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(blocked_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $blockedUserId = (int) ($_POST['blocked_user_id'] ?? 0);
    if ($blockedUserId > 0) {
        mysqli_query($db, "DELETE FROM talkora_blocked_users WHERE user_id=$userId AND blocked_user_id=$blockedUserId");
    }
    redirect('blocked.php');
}

$result = mysqli_query($db, "SELECT u.id,u.full_name,u.user_id,u.avatar,b.created_at FROM talkora_blocked_users b JOIN users u ON u.id=b.blocked_user_id WHERE b.user_id=$userId ORDER BY b.created_at DESC");
$blockedUsers = [];
while ($result && ($row = mysqli_fetch_assoc($result))) $blockedUsers[] = $row;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Blocked contacts | TalkOra</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>
    <main class="app-shell">
        <section class="settings-card p-4 p-lg-5" style="width:min(760px,100%);">
            <header class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-4">
                <div><div class="text-uppercase small text-primary fw-bold">Privacy</div><h1 class="h3 mb-0">Blocked contacts</h1></div>
                <a href="settings.php" class="btn btn-outline-light">Back to settings</a>
            </header>
            <?php if (!$blockedUsers): ?><p class="text-secondary">You have not blocked anyone.</p><?php endif; ?>
            <div class="blocked-contact-list">
                <?php foreach ($blockedUsers as $blocked): ?>
                    <article class="blocked-contact-item">
                        <div class="blocked-contact-identity">
                            <?php if (!empty($blocked['avatar']) && is_file(__DIR__ . '/' . $blocked['avatar'])): ?><img src="<?php echo htmlspecialchars($blocked['avatar'], ENT_QUOTES, 'UTF-8'); ?>" alt="" class="user-avatar-image"><?php else: ?><span class="avatar"><?php echo htmlspecialchars(strtoupper(substr($blocked['full_name'], 0, 1))); ?></span><?php endif; ?>
                            <div><strong><?php echo htmlspecialchars($blocked['full_name']); ?></strong><small>ID: <?php echo htmlspecialchars($blocked['user_id']); ?></small></div>
                        </div>
                        <form method="post"><input type="hidden" name="blocked_user_id" value="<?php echo (int) $blocked['id']; ?>"><button class="btn btn-sm btn-primary" type="submit">Unblock</button></form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>