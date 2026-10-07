<?php
require_once __DIR__ . '/config.php';
ensure_logged_in();

$db = db_connect();
$user = current_user();
$userId = (int) $user['id'];

if (!table_has_column($db, 'users', 'show_active_status')) {
    mysqli_query($db, "ALTER TABLE users ADD COLUMN show_active_status TINYINT(1) NOT NULL DEFAULT 1 AFTER is_online");
}
mysqli_query($db, "CREATE TABLE IF NOT EXISTS calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caller_id INT NOT NULL,
    receiver_id INT NOT NULL,
    call_type ENUM('audio','video') NOT NULL DEFAULT 'audio',
    status ENUM('initiated','answered','missed','rejected') NOT NULL DEFAULT 'initiated',
    duration_seconds INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_chat_preferences (user_id INT NOT NULL,contact_user_id INT NOT NULL,nickname TEXT NOT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(user_id,contact_user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(contact_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_blocked_users (user_id INT NOT NULL,blocked_user_id INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,blocked_user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(blocked_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['contact_id']) && trim((string) $_POST['contact_id']) !== '') {
        $contactId = trim((string) $_POST['contact_id']);
        $contactLookup = mysqli_real_escape_string($db, $contactId);
        $phoneDigits = mysqli_real_escape_string($db, preg_replace('/\D+/', '', $contactId));
        $target = mysqli_query($db, "SELECT id, full_name, user_id FROM users WHERE user_id = '$contactLookup' OR contact_number = '$contactLookup' OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(contact_number,'+',''),' ',''),'-',''),'(',''),')','') = '$phoneDigits' LIMIT 1");

        if (!$target || mysqli_num_rows($target) === 0) {
            set_flash('dashboard_error', 'No user found with that ID.');
            redirect('dashboard.php');
        }

        $targetUser = mysqli_fetch_assoc($target);
        $targetUserId = (int) $targetUser['id'];

        if ($targetUserId === $userId) {
            set_flash('dashboard_error', 'You cannot add yourself to your contact list.');
            redirect('dashboard.php');
        }

        $existing = mysqli_query($db, "SELECT id FROM contacts WHERE (user_id = $userId AND contact_user_id = $targetUserId) OR (user_id = $targetUserId AND contact_user_id = $userId) LIMIT 1");
        if ($existing && mysqli_num_rows($existing) > 0) {
            set_flash('dashboard_success', 'This user is already in your contact list.');
            redirect('dashboard.php?user_id=' . $targetUserId);
        }

        mysqli_query($db, "INSERT IGNORE INTO contacts (user_id, contact_user_id) VALUES ($userId, $targetUserId)");
        mysqli_query($db, "INSERT IGNORE INTO contacts (user_id, contact_user_id) VALUES ($targetUserId, $userId)");
        set_flash('dashboard_success', 'Contact added successfully.');
        redirect('dashboard.php?user_id=' . $targetUserId);
    }

    if (isset($_POST['remove_contact_id'])) {
        $removeId = (int) $_POST['remove_contact_id'];
        if ($removeId > 0 && $removeId !== $userId) {
            mysqli_query($db, "DELETE FROM contacts WHERE (user_id = $userId AND contact_user_id = $removeId) OR (user_id = $removeId AND contact_user_id = $userId)");
            mysqli_query($db, "DELETE FROM messages WHERE ((sender_id = $userId AND receiver_id = $removeId) OR (sender_id = $removeId AND receiver_id = $userId))");
            set_flash('dashboard_success', 'Contact removed from your list and chat cleared.');
        }
        redirect('dashboard.php');
    }

    if (isset($_POST['message']) && trim((string) $_POST['message']) !== '' && isset($_POST['receiver_id'])) {
        $receiverId = (int) $_POST['receiver_id'];
        $message = mysqli_real_escape_string($db, trim((string) $_POST['message']));

        $blocked = mysqli_query($db, "SELECT user_id FROM talkora_blocked_users WHERE (user_id=$userId AND blocked_user_id=$receiverId) OR (user_id=$receiverId AND blocked_user_id=$userId) LIMIT 1");
        if ($blocked && mysqli_num_rows($blocked) > 0) {
            set_flash('dashboard_error', 'This contact is blocked. Unblock them before messaging.');
            redirect('dashboard.php?user_id=' . $receiverId);
        }

        $contactCheck = mysqli_query($db, "SELECT id FROM contacts WHERE ((user_id = $userId AND contact_user_id = $receiverId) OR (user_id = $receiverId AND contact_user_id = $userId)) LIMIT 1");
        if (!$contactCheck || mysqli_num_rows($contactCheck) === 0) {
            set_flash('dashboard_error', 'You can only message people who are in your contact list.');
            redirect('dashboard.php?user_id=' . $receiverId);
        }

        mysqli_query($db, "INSERT INTO messages (sender_id, receiver_id, message) VALUES ($userId, $receiverId, '$message')");
        set_flash('dashboard_success', 'Message sent.');
        redirect('dashboard.php?user_id=' . $receiverId);
    }

    if (isset($_POST['call_type'])) {
        $callType = $_POST['call_type'] === 'video' ? 'video' : 'audio';
        $receiverId = (int) ($_POST['receiver_id'] ?? 0);
        if ($receiverId > 0) {
            mysqli_query($db, "INSERT INTO calls (caller_id, receiver_id, call_type, status) VALUES ($userId, $receiverId, '$callType', 'initiated')");
            set_flash('dashboard_success', ucfirst($callType) . ' call started successfully.');
        }
        redirect('dashboard.php?user_id=' . $receiverId);
    }
}

$contacts = mysqli_query($db, "SELECT u.*, (u.show_active_status=1 AND u.is_online=1 AND u.last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)) AS is_online, c.id AS linked_id FROM contacts c JOIN users u ON u.id = c.contact_user_id WHERE c.user_id = $userId ORDER BY is_online DESC, u.last_seen DESC");
$contactsList = [];
while ($row = mysqli_fetch_assoc($contacts)) {
    $contactsList[] = $row;
}

$selectedId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
if ($selectedId === 0 && !empty($contactsList)) {
    $selectedId = (int) $contactsList[0]['id'];
}

if ($selectedId > 0) {
    $messages = mysqli_query($db,
        "SELECT m.*, UNIX_TIMESTAMP(m.created_at) AS created_epoch, s.full_name AS sender_name, r.full_name AS receiver_name,
               ru.full_name AS reply_sender_name, rm.message AS reply_message,
               rm.media_type AS reply_media_type, rm.is_deleted AS reply_is_deleted,
               m.reply_preview_text
        FROM messages m
        LEFT JOIN messages rm ON rm.id = m.reply_to_id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        JOIN users s ON s.id = m.sender_id
        JOIN users r ON r.id = m.receiver_id
        WHERE (m.sender_id = $userId AND m.receiver_id = $selectedId) OR (m.sender_id = $selectedId AND m.receiver_id = $userId)
        ORDER BY m.id ASC");
} else {
    $messages = false;
}

$selectedUser = $selectedId > 0 ? mysqli_fetch_assoc(mysqli_query($db, "SELECT *, (show_active_status=1 AND is_online=1 AND last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)) AS is_online FROM users WHERE id = $selectedId LIMIT 1")) : null;
$chatNickname = '';
$contactBlocked = false;
$chatMedia = [];
if ($selectedUser) {
    $preference = mysqli_fetch_assoc(mysqli_query($db, "SELECT nickname FROM talkora_chat_preferences WHERE user_id=$userId AND contact_user_id=" . (int) $selectedUser['id'] . " LIMIT 1"));
    $chatNickname = (string) ($preference['nickname'] ?? '');
    $blockedCheck = mysqli_query($db, "SELECT user_id FROM talkora_blocked_users WHERE user_id=$userId AND blocked_user_id=" . (int) $selectedUser['id'] . " LIMIT 1");
    $contactBlocked = $blockedCheck && mysqli_num_rows($blockedCheck) > 0;
    $mediaResult = mysqli_query($db, "SELECT media_path,created_at FROM messages WHERE ((sender_id=$userId AND receiver_id=" . (int) $selectedUser['id'] . ") OR (sender_id=" . (int) $selectedUser['id'] . " AND receiver_id=$userId)) AND media_type='image' AND media_path IS NOT NULL AND is_deleted=0 ORDER BY id DESC LIMIT 60");
    while ($mediaResult && ($mediaRow = mysqli_fetch_assoc($mediaResult))) $chatMedia[] = $mediaRow;
}
$callHistoryResult = mysqli_query($db, "SELECT c.*, u.full_name AS contact_name, u.avatar, CASE WHEN c.caller_id = $userId THEN c.receiver_id ELSE c.caller_id END AS other_user_id FROM calls c JOIN users u ON u.id = CASE WHEN c.caller_id = $userId THEN c.receiver_id ELSE c.caller_id END WHERE c.caller_id = $userId OR c.receiver_id = $userId ORDER BY c.created_at DESC LIMIT 8");
$callHistory = [];
while ($row = mysqli_fetch_assoc($callHistoryResult)) {
    $callHistory[] = $row;
}
$showProfile = isset($_GET['view']) && $_GET['view'] === 'profile' && $selectedUser !== null;
$success = get_flash('dashboard_success');
$error = get_flash('dashboard_error');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | TalkOra</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#2563eb">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="dashboard-body <?php echo normalize_theme($user['theme']) === 'light' ? 'light-theme' : ''; ?>" style="--user-font-size:<?php echo max(12, min(22, (int) $user['font_size'])); ?>px;--user-font-family:<?php echo htmlspecialchars(json_encode($user['font_family'] ?: 'Segoe UI'), ENT_QUOTES, 'UTF-8'); ?>" data-current-user-id="<?php echo $userId; ?>" data-selected-user-id="<?php echo $selectedId ?: 0; ?>" data-contact-blocked="<?php echo $contactBlocked ? '1' : '0'; ?>" data-contact-name="<?php echo htmlspecialchars($selectedUser['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-notifications-enabled="<?php echo (int)$user['notifications']; ?>">
    <div class="app-shell">
        <div class="chat-wrap" style="width:min(1280px, 100%);">
            <aside class="sidebar" id="contactsSidebar">
                <div class="sidebar-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-3">
                        <?php
                            $currentUserAvatar = !empty($user['avatar']) && file_exists(__DIR__ . '/' . $user['avatar']) ? $user['avatar'] : '';
                            if ($currentUserAvatar):
                        ?>
                            <img src="<?php echo htmlspecialchars($currentUserAvatar); ?>" alt="Profile picture" class="user-avatar-image" style="width:42px;height:42px;">
                        <?php else: ?>
                            <div class="avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold">
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </div>
                            <div class="small text-secondary">ID: <?php echo htmlspecialchars($user['user_id']); ?></div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="profile.php" class="btn btn-sm profile-nav-btn">Profile</a>
                        <a href="settings.php" class="btn btn-sm settings-nav-btn">Settings</a>
                    </div>
                </div>

                <nav class="sidebar-feature-nav" aria-label="More TalkOra spaces">
                    <a href="community.php">Feed</a>
                    <a href="community.php?view=reels">Reels</a>
                    <a href="groups.php">Groups</a>
                    <a href="communities.php">Communities</a>
                </nav>

                <div class="p-3 border-bottom">
                    <form method="post" class="d-flex gap-2">
                        <input type="text" name="contact_id" class="form-control" placeholder="Add by ID or phone" aria-label="User ID or contact number" required>
                        <button class="btn btn-primary" type="submit">Add</button>
                    </form>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success m-3 mb-0"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger m-3 mb-0"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="contact-list">
                    <?php if (empty($contactsList)):
                    ?>
                        <div class="alert alert-light text-secondary m-3">No contacts yet. Add someone by their user ID.</div>
                    <?php else: ?>
                        <?php foreach ($contactsList as $contact):
                            $isOnline = (int) $contact['is_online'] === 1;
                            $selectedClass = ($selectedId === (int) $contact['id']) ? 'active' : '';
                            $contactAvatarPath = !empty($contact['avatar']) && file_exists(__DIR__ . '/' . $contact['avatar']) ? $contact['avatar'] : '';
                        ?>
                            <div class="contact-item-wrap <?php echo $selectedClass; ?>">
                                <a class="contact-item text-decoration-none d-flex align-items-center gap-3 flex-grow-1" href="dashboard.php?user_id=<?php echo (int) $contact['id']; ?>">
                                    <?php if ($contactAvatarPath): ?>
                                        <img src="<?php echo htmlspecialchars($contactAvatarPath); ?>" alt="Profile picture" class="user-avatar-image" style="width:38px;height:38px;">
                                    <?php else: ?>
                                        <div class="avatar" style="width:38px; height:38px; font-size:0.95rem;">
                                            <?php echo strtoupper(substr($contact['full_name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-semibold text-white contact-name"><?php echo htmlspecialchars($contact['full_name']); ?></div>
                                        <div class="small text-muted contact-id">ID: <?php echo htmlspecialchars($contact['user_id']); ?></div>
                                        <div class="small contact-presence mt-1">
                                            <span class="status-dot <?php echo $isOnline ? 'ripple' : 'offline'; ?>"></span>
                                            <?php echo htmlspecialchars(status_label($isOnline, $contact['last_seen'], (int) ($contact['show_active_status'] ?? 1) === 1)); ?>
                                        </div>
                                    </div>
                                </a>
                                <form method="post" class="contact-remove-form" onsubmit="return confirm('Remove this contact from your list?');">
                                    <input type="hidden" name="remove_contact_id" value="<?php echo (int) $contact['id']; ?>">
                                    <button type="submit" class="btn btn-sm remove-contact-btn">Remove</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="call-history-panel">
                    <div class="call-history-header">
                        <span>Recent calls</span>
                        <?php if (!empty($callHistory)): ?>
                            <button type="button" class="call-history-clear-btn" data-action="clear">Clear all</button>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($callHistory)): ?>
                        <div class="call-history-list">
                            <?php foreach ($callHistory as $call): ?>
                                <?php $isOutgoing = (int) $call['caller_id'] === $userId; ?>
                                <div class="call-history-item" data-call-id="<?php echo (int) $call['id']; ?>">
                                    <div class="call-history-avatar">
                                        <?php echo htmlspecialchars(strtoupper(substr($call['contact_name'], 0, 1))); ?>
                                    </div>
                                    <div class="call-history-meta">
                                        <div class="call-history-name"><?php echo htmlspecialchars($call['contact_name']); ?></div>
                                        <div class="call-history-status"><?php echo htmlspecialchars(ucfirst($call['status'])); ?> • <?php echo htmlspecialchars(strtoupper($call['call_type'])); ?><?php if (!empty($call['duration_seconds'])): ?> • <?php echo sprintf('%02d:%02d', floor(((int)$call['duration_seconds'])/60), ((int)$call['duration_seconds'])%60); ?><?php endif; ?></div>
                                    </div>
                                    <div class="call-history-actions">
                                        <div class="call-history-direction"><?php echo $isOutgoing ? 'Outgoing' : 'Incoming'; ?></div>
                                        <button type="button" class="call-history-delete-btn" aria-label="Delete recent call" title="Delete recent call" data-call-id="<?php echo (int) $call['id']; ?>">×</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="call-history-empty">No recent calls yet.</div>
                    <?php endif; ?>
                </div>
            </aside>
            <button type="button" class="sidebar-overlay" id="sidebarOverlay" aria-label="Close contacts menu"></button>

            <main class="chat-panel">
                <?php if ($selectedUser): ?>
                    <?php
                        $selectedUserAvatar = !empty($selectedUser['avatar']) && file_exists(__DIR__ . '/' . $selectedUser['avatar']) ? $selectedUser['avatar'] : '';
                    ?>
                    <div class="chat-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2 gap-sm-3 min-w-0">
                            <button type="button" class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Open contacts" aria-controls="contactsSidebar" aria-expanded="false">
                                <span></span><span></span><span></span>
                            </button>
                            <a href="dashboard.php?user_id=<?php echo (int) $selectedUser['id']; ?>&view=profile" class="d-flex align-items-center gap-3 text-decoration-none text-reset">
                                <?php if ($selectedUserAvatar): ?>
                                    <img src="<?php echo htmlspecialchars($selectedUserAvatar); ?>" alt="Profile picture" class="user-avatar-image profile-photo-view" style="width:42px;height:42px;">
                                <?php else: ?>
                                    <div class="avatar"><?php echo strtoupper(substr($selectedUser['full_name'], 0, 1)); ?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($chatNickname !== '' ? $chatNickname : $selectedUser['full_name']); ?></div>
                                    <div class="small text-secondary" id="presenceStatus"><?php echo htmlspecialchars(status_label((int) $selectedUser['is_online'] === 1, $selectedUser['last_seen'], (int) ($selectedUser['show_active_status'] ?? 1) === 1)); ?></div>
                                    <div class="small text-success" id="typingStatus" style="display:none;">typing...</div>
                                </div>
                            </a>
                        </div>

                        <div class="call-actions d-flex gap-2">
                            <button class="btn btn-success btn-sm call-trigger" data-call-type="audio" data-receiver-id="<?php echo (int) $selectedUser['id']; ?>" data-receiver-name="<?php echo htmlspecialchars($selectedUser['full_name']); ?>" data-is-online="<?php echo (int) ($selectedUser['is_online'] ?? 0); ?>" aria-label="Audio call" title="Audio call">
                                <svg class="call-trigger-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M4.5 6.5A2.5 2.5 0 0 1 7 4h1.2a1.5 1.5 0 0 1 1.46 1.2l.5 2.8a1.5 1.5 0 0 1-.9 1.74l-1.46.53a12.4 12.4 0 0 0 8.8 8.8l.53-1.46a1.5 1.5 0 0 1 1.74-.9l2.8.5A1.5 1.5 0 0 1 20 15.8V17a2.5 2.5 0 0 1-2.5 2.5H16A14.5 14.5 0 0 1 4.5 6.5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span class="visually-hidden">Audio call</span>
                            </button>
                            <button class="btn btn-secondary btn-sm call-trigger" data-call-type="video" data-receiver-id="<?php echo (int) $selectedUser['id']; ?>" data-receiver-name="<?php echo htmlspecialchars($selectedUser['full_name']); ?>" data-is-online="<?php echo (int) ($selectedUser['is_online'] ?? 0); ?>" aria-label="Video call" title="Video call">
                                <svg class="call-trigger-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <rect x="3.5" y="6.5" width="12.5" height="11" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M16 10l4.2-2.6A1.3 1.3 0 0 1 22 8.7v6.6a1.3 1.3 0 0 1-1.8 1.3L16 14v-4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                </svg>
                                <span class="visually-hidden">Video call</span>
                            </button>
                            <button type="button" class="btn btn-outline-light btn-sm" id="chatThemeToggle" aria-expanded="false" aria-controls="chatThemePanel">Chat settings</button>
                            <a href="logout.php" class="btn btn-sm logout-btn">Logout</a>
                        </div>
                    </div>
                    <section class="chat-theme-panel" id="chatThemePanel" aria-label="Chat settings" hidden>
                        <div class="chat-theme-heading">Chat settings</div>
                        <div class="chat-settings-section">
                            <label for="chatNicknameInput">Nickname for this contact</label>
                            <div class="chat-nickname-controls"><input id="chatNicknameInput" type="text" value="<?php echo htmlspecialchars($chatNickname, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Use any name"><button type="button" class="btn btn-sm btn-primary" id="saveChatNickname">Save</button></div>
                            <small id="chatNicknameStatus" role="status"></small>
                        </div>
                        <div class="chat-settings-section">
                            <div class="chat-theme-heading">Shared photos</div>
                            <div class="chat-media-history">
                                <?php foreach ($chatMedia as $media): ?>
                                    <button type="button" class="chat-media-thumb" aria-label="View shared photo"><img src="<?php echo htmlspecialchars($media['media_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="Shared photo" loading="lazy"></button>
                                <?php endforeach; ?>
                                <?php if (!$chatMedia): ?><span class="small text-secondary">No shared photos yet.</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="chat-settings-section chat-block-control">
                            <?php if ($contactBlocked): ?><p>This contact is blocked. Unblock them to send messages or calls.</p><?php endif; ?>
                            <button type="button" class="btn btn-sm <?php echo $contactBlocked ? 'btn-primary' : 'btn-outline-danger'; ?>" id="chatBlockToggle" data-blocked="<?php echo $contactBlocked ? '1' : '0'; ?>"><?php echo $contactBlocked ? 'Unblock contact' : 'Block contact'; ?></button>
                        </div>
                        <div class="chat-theme-heading">Chat wallpaper</div>
                        <div class="chat-theme-colors" aria-label="Chat colors">
                            <button type="button" class="theme-swatch" data-color="#b4233c" style="--swatch:#b4233c" aria-label="Red"></button>
                            <button type="button" class="theme-swatch" data-color="#d6a516" style="--swatch:#d6a516" aria-label="Yellow"></button>
                            <button type="button" class="theme-swatch" data-color="#0ea5e9" style="--swatch:#0ea5e9" aria-label="Sky blue"></button>
                            <button type="button" class="theme-swatch" data-color="#3559a8" style="--swatch:#3559a8" aria-label="Blue"></button>
                            <button type="button" class="theme-swatch" data-color="#633c83" style="--swatch:#633c83" aria-label="Plum"></button>
                            <button type="button" class="theme-swatch" data-color="#28313f" style="--swatch:#28313f" aria-label="Slate"></button>
                        </div>
                        <div class="chat-theme-wallpapers">
                            <button type="button" class="wallpaper-choice wallpaper-cat" data-wallpaper="assets/img/chat-cat-wallpaper.svg" aria-label="Cat wallpaper"></button>
                            <button type="button" class="wallpaper-choice wallpaper-leaves" data-wallpaper="assets/img/chat-leaves-wallpaper.svg" aria-label="Leaf wallpaper"></button>
                            <button type="button" class="wallpaper-choice wallpaper-reset" data-reset-wallpaper="1" aria-label="Default wallpaper">Default</button>
                        </div>
                        <label class="chat-wallpaper-upload">Choose image<input type="file" id="chatWallpaperInput" accept="image/jpeg,image/png,image/webp"></label>
                    </section>

                    <?php if ($showProfile): ?>
                        <div class="chat-body d-flex align-items-center justify-content-center">
                            <div class="contact-profile-card">
                                <div class="contact-profile-close-wrap">
                                    <a href="dashboard.php?user_id=<?php echo (int) $selectedUser['id']; ?>" class="contact-profile-close" aria-label="Close profile">×</a>
                                </div>
                                <div class="contact-profile-header">
                                    <?php if ($selectedUserAvatar): ?>
                                        <img src="<?php echo htmlspecialchars($selectedUserAvatar); ?>" alt="Profile picture" class="user-avatar-image contact-profile-avatar profile-photo-view">
                                    <?php else: ?>
                                        <div class="avatar contact-profile-avatar"><?php echo strtoupper(substr($selectedUser['full_name'], 0, 1)); ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="contact-profile-name"><?php echo htmlspecialchars($selectedUser['full_name']); ?></div>
                                        <div class="small text-secondary"><?php echo htmlspecialchars(status_label((int) $selectedUser['is_online'] === 1, $selectedUser['last_seen'], (int) ($selectedUser['show_active_status'] ?? 1) === 1)); ?></div>
                                    </div>
                                </div>

                                <div class="contact-profile-details">
                                    <div class="profile-row">
                                        <span class="profile-label">User ID</span>
                                        <span class="profile-value"><?php echo htmlspecialchars($selectedUser['user_id']); ?></span>
                                    </div>
                                    <div class="profile-row">
                                        <span class="profile-label">Email</span>
                                        <span class="profile-value"><?php echo htmlspecialchars($selectedUser['email']); ?></span>
                                    </div>
                                    <div class="profile-row">
                                        <span class="profile-label">Phone</span>
                                        <span class="profile-value"><?php echo htmlspecialchars($selectedUser['contact_number']); ?></span>
                                    </div>
                                    <div class="profile-row">
                                        <span class="profile-label">Gender</span>
                                        <span class="profile-value"><?php echo htmlspecialchars($selectedUser['gender']); ?></span>
                                    </div>
                                </div>

                                <div class="d-flex gap-2 mt-3">
                                    <a href="dashboard.php?user_id=<?php echo (int) $selectedUser['id']; ?>" class="btn btn-primary flex-grow-1">Open chat</a>
                                    <button class="btn btn-outline-light call-trigger call-trigger-profile" data-call-type="audio" data-receiver-id="<?php echo (int) $selectedUser['id']; ?>" data-receiver-name="<?php echo htmlspecialchars($selectedUser['full_name']); ?>" data-is-online="<?php echo (int) ($selectedUser['is_online'] ?? 0); ?>" aria-label="Audio call" title="Audio call">
                                        <svg class="call-trigger-icon" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M4.5 6.5A2.5 2.5 0 0 1 7 4h1.2a1.5 1.5 0 0 1 1.46 1.2l.5 2.8a1.5 1.5 0 0 1-.9 1.74l-1.46.53a12.4 12.4 0 0 0 8.8 8.8l.53-1.46a1.5 1.5 0 0 1 1.74-.9l2.8.5A1.5 1.5 0 0 1 20 15.8V17a2.5 2.5 0 0 1-2.5 2.5H16A14.5 14.5 0 0 1 4.5 6.5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        <span class="visually-hidden">Audio call</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="chat-body" id="chatMessages">
                            <?php if ($messages): ?>
                                <?php while ($message = mysqli_fetch_assoc($messages)): ?>
                                    <?php $isOutgoing = (int) $message['sender_id'] === $userId; ?>
                                    <?php $isDeleted = !empty($message['is_deleted']); ?>
                                    <?php
                                        $tickState = (int) ($message['is_read'] ?? 0) === 1 ? 'seen' : ((isset($message['delivered_at']) && $message['delivered_at']) ? 'delivered' : 'sent');
                                        $tickIcon = $tickState === 'sent' ? '✓' : '✓✓';
                                        $canEditMessage = $isOutgoing && !$isDeleted && (time() - strtotime($message['created_at'])) / 60 <= 15;
                                    ?>
                                    <div class="message-row <?php echo $isOutgoing ? 'outgoing' : 'incoming'; ?>" data-message-id="<?php echo (int) $message['id']; ?>" data-message-text="<?php echo htmlspecialchars((string) $message['message'], ENT_QUOTES, 'UTF-8'); ?>" data-editable="<?php echo $canEditMessage ? '1' : '0'; ?>" data-deletable="<?php echo $isOutgoing && !$isDeleted ? '1' : '0'; ?>">
                                        <div class="message-bubble">
                                            <?php if ($isDeleted): ?>
                                                <div class="message-text deleted-message">This message was deleted</div>
                                            <?php elseif (($message['media_type'] ?? '') === 'call'): ?>
                                                <div class="call-chat-event <?php echo stripos((string) $message['message'], 'missed') !== false ? 'missed' : 'completed'; ?>"><span class="call-chat-event-icon" aria-hidden="true"><?php echo stripos((string) $message['message'], 'video') !== false ? '▣' : '☎'; ?></span><span><?php echo htmlspecialchars((string) $message['message'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                                            <?php else: ?>
                                                <?php echo nl2br(htmlspecialchars($message['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?>
                                            <?php endif; ?>
                                            <div class="message-meta <?php echo $isOutgoing ? 'outgoing' : 'incoming'; ?>">
                                                <time class="message-time" data-epoch="<?php echo (int) ($message['created_epoch'] ?? 0); ?>"><?php echo date('h:i A', strtotime($message['created_at'])); ?></time>
                                                <?php if ($isOutgoing): ?>
                                                    <span class="message-status <?php echo $tickState; ?>" aria-label="<?php echo $tickState === 'seen' ? 'Seen' : ($tickState === 'delivered' ? 'Delivered' : 'Sent'); ?>"><?php echo $tickIcon; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="alert alert-light text-secondary">Start the conversation by sending your first message.</div>
                            <?php endif; ?>
                        </div>

                        <div class="composer">
                            
                            <div class="reply-composer" id="replyComposer"><div class="reply-copy"><strong class="reply-name">Contact</strong><span class="reply-text">Message</span></div><button type="button" id="replyCancel" class="reply-cancel" aria-label="Cancel reply">×</button></div>
                            <form id="chatForm" class="composer-form">
                                <input type="hidden" name="receiver_id" value="<?php echo (int) $selectedUser['id']; ?>">
                                <input type="file" id="imageInput" accept="image/*" class="d-none">
                                <input type="file" id="cameraInput" accept="image/*" capture="environment" class="d-none">
                                <button type="button" id="imageUploadBtn" class="btn btn-outline-light media-btn icon-btn" aria-label="Send image" title="Gallery">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 13.5l2.2-2.8a1 1 0 0 1 1.5 0l2.2 2.7 1.7-2.3a1 1 0 0 1 1.6.1L18 14.5v3.5H6v-3.1l2.5-1.9zm-2.5 6.5h13a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2zM9 8.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z"/></svg>
                                </button>
                                <button type="button" id="cameraBtn" class="btn btn-outline-light media-btn icon-btn" aria-label="Take photo" title="Camera">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.2 5.5 9.4 4h5.2l1.2 1.5H19a2 2 0 0 1 2 2v9.5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7.5a2 2 0 0 1 2-2h3.2ZM12 17a4.25 4.25 0 1 0 0-8.5A4.25 4.25 0 0 0 12 17Zm0-1.8a2.45 2.45 0 1 1 0-4.9 2.45 2.45 0 0 1 0 4.9Z"/></svg>
                                </button>
                                <button type="button" id="voiceRecorderBtn" class="btn btn-outline-light media-btn icon-btn" aria-label="Record voice" title="Voice message">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V22h2v-3.08A7 7 0 0 0 19 12h-2z"/></svg>
                                </button>
                                <button type="button" id="emojiPickerBtn" class="emoji-picker-toggle" aria-label="Choose emoji" title="Emoji">☺</button>
                                <textarea name="message" id="messageInput" class="form-control" placeholder="Write a message..." rows="1" autocomplete="off"></textarea>
                                <button type="submit" id="sendMessageBtn" class="btn btn-primary send-btn" aria-label="Send message">Send</button>
                            </form>
                            <div class="emoji-picker" id="emojiPicker" role="dialog" aria-label="Choose an emoji" aria-hidden="true">
                                <div class="emoji-picker-heading">Emoji</div>
                                <div class="emoji-picker-grid" id="emojiPickerGrid"></div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="chat-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2 min-w-0">
                            <button type="button" class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Open contacts" aria-controls="contactsSidebar" aria-expanded="false">
                                <span></span><span></span><span></span>
                            </button>
                            <div>
                            <div class="fw-bold">No contact selected</div>
                            <div class="small text-secondary">Add a contact using their user ID.</div>
                            </div>
                        </div>
                        <div class="call-actions d-flex gap-2">

                            <a href="logout.php" class="btn btn-sm logout-btn">Logout</a>
                    </div>

                    </div>
                    <div class="chat-body d-flex align-items-center justify-content-center text-secondary">
                        You do not have any contact yet. Use the ID field in the sidebar to add someone.
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <div id="messageActionMenu" class="message-action-menu" aria-hidden="true">
        <button type="button" data-action="reply">Reply</button>
        <button type="button" data-action="forward">Forward</button>
        <button type="button" data-action="copy">Copy</button>
        <button type="button" data-action="translate">Translate</button>
        <button type="button" data-action="edit">Edit</button>
        <button type="button" data-action="delete">Delete</button>
    </div>

    <div class="modal fade call-modal" id="callModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered call-modal-dialog">
            <div class="modal-content call-modal-content text-light">
                <div class="modal-body call-modal-body text-center">
                    <div class="call-mode-badge" id="callTypeBadge">Audio</div>
                    <div class="call-ring-avatar-wrap">
                        <div class="avatar call-avatar" id="callAvatar">C</div>
                    </div>
                    <div class="call-video-shell" id="callVideoShell" style="display:none;">
                        <video id="localCallVideo" autoplay muted playsinline></video>
                        <video id="remoteCallVideo" autoplay playsinline></video>
                    </div>
                    <audio id="remoteCallAudio" autoplay></audio>
                    <h4 class="call-user-name" id="callUserName">Contact</h4>
                    <p class="call-status-text" id="callStatusText">Ringing...</p>
                    <div class="call-timer" id="callTimer" style="display:none;">00:00</div>
                    <div class="call-action-panel">
                        <button type="button" class="btn btn-success call-action-btn" id="answerCallBtn">Answer</button>
                        <button type="button" class="btn btn-outline-light call-action-btn" id="muteCallBtn">Mute</button>
                        <button type="button" class="btn btn-danger call-action-btn call-end-btn" id="endCallBtn">Decline</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="cameraModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content call-modal-content text-light">
                <div class="modal-header border-0"><h5 class="modal-title">Camera</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body text-center">
                    <video id="cameraPreview" autoplay muted playsinline class="camera-preview"></video>
                    <canvas id="cameraCanvas" class="d-none"></canvas>
                    <div class="d-flex justify-content-center gap-2 mt-3">
                        <button type="button" class="btn btn-outline-light" id="switchCameraBtn">Flip camera</button>
                        <button type="button" class="btn btn-success" id="takePhotoBtn">Take photo</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="imageLightbox" class="image-lightbox" aria-hidden="true">
        <button type="button" class="image-lightbox-close" aria-label="Close image">×</button>
        <img id="imageLightboxImg" src="" alt="Enlarged chat media">
    </div>

    <div id="imageContextMenu" class="image-context-menu" aria-hidden="true">
        <button type="button" id="saveImageBtn">Save image</button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
    <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(function(){}); }</script>
    <script>
        window.selectedUserId = <?php echo (int) $selectedUser['id']; ?>;
        window.currentUserId = <?php echo $userId; ?>;
    </script>
