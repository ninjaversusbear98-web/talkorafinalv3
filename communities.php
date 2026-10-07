<?php
require_once __DIR__ . '/config.php';
ensure_logged_in();

$db = db_connect();
$user = current_user();
$userId = (int) $user['id'];

mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_communities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    owner_id INT NOT NULL,
    visibility ENUM('public','private') NOT NULL DEFAULT 'public',
    password_hash VARCHAR(255) DEFAULT NULL,
    theme_color VARCHAR(16) NOT NULL DEFAULT 'blue',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_community_visibility (visibility, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!table_has_column($db, 'talkora_communities', 'theme_color')) {
    mysqli_query($db, "ALTER TABLE talkora_communities ADD COLUMN theme_color VARCHAR(16) NOT NULL DEFAULT 'blue' AFTER password_hash");
}
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_community_members (
    community_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner','member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (community_id, user_id),
    FOREIGN KEY (community_id) REFERENCES talkora_communities(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_community_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    community_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    media_type VARCHAR(16) DEFAULT NULL,
    media_path VARCHAR(255) DEFAULT NULL,
    reply_to_id INT DEFAULT NULL,
    reply_preview_text VARCHAR(500) DEFAULT NULL,
    edited_at DATETIME DEFAULT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (community_id) REFERENCES talkora_communities(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_community_message (community_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_community_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    community_id INT NOT NULL,
    reporter_id INT NOT NULL,
    reason VARCHAR(80) NOT NULL,
    details TEXT NOT NULL,
    status ENUM('open','reviewed','resolved') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (community_id) REFERENCES talkora_communities(id) ON DELETE CASCADE,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_community_reports (community_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach ([
    'media_type' => 'VARCHAR(16) DEFAULT NULL',
    'media_path' => 'VARCHAR(255) DEFAULT NULL',
    'reply_to_id' => 'INT DEFAULT NULL',
    'reply_preview_text' => 'VARCHAR(500) DEFAULT NULL',
    'edited_at' => 'DATETIME DEFAULT NULL',
    'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
] as $column => $definition) {
    if (!table_has_column($db, 'talkora_community_messages', $column)) {
        mysqli_query($db, "ALTER TABLE talkora_community_messages ADD COLUMN `$column` $definition");
    }
}
$messageColumn = mysqli_fetch_assoc(mysqli_query($db, "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='talkora_community_messages' AND COLUMN_NAME='message' LIMIT 1"));
if ($messageColumn && strtolower((string) $messageColumn['DATA_TYPE']) !== 'text') {
    mysqli_query($db, "ALTER TABLE talkora_community_messages MODIFY message TEXT NOT NULL");
}

$error = '';
$notice = '';
$action = (string) ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $visibility = ($_POST['visibility'] ?? '') === 'private' ? 'private' : 'public';
        $roomPassword = trim((string) ($_POST['room_password'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($description) > 500) {
            $error = 'Enter a community name and keep the description under 500 characters.';
        } else {
            $nameSql = mysqli_real_escape_string($db, $name);
            $descriptionSql = mysqli_real_escape_string($db, $description);
            $visibilitySql = mysqli_real_escape_string($db, $visibility);
            $passwordSql = $visibility === 'public' && $roomPassword !== ''
                ? "'" . mysqli_real_escape_string($db, password_hash($roomPassword, PASSWORD_DEFAULT)) . "'"
                : 'NULL';
            $created = mysqli_query($db, "INSERT INTO talkora_communities (name,description,owner_id,visibility,password_hash) VALUES ('$nameSql','$descriptionSql',$userId,'$visibilitySql',$passwordSql)");
            if ($created) {
                $communityId = (int) mysqli_insert_id($db);
                mysqli_query($db, "INSERT INTO talkora_community_members (community_id,user_id,role) VALUES ($communityId,$userId,'owner')");
                $inviteCodes = preg_split('/[\s,;]+/', trim((string) ($_POST['invite_codes'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                foreach (array_slice(array_unique($inviteCodes), 0, 25) as $inviteCode) {
                    $inviteCode = mysqli_real_escape_string($db, $inviteCode);
                    $invite = mysqli_fetch_assoc(mysqli_query($db, "SELECT id FROM users WHERE user_id='$inviteCode' LIMIT 1"));
                    if ($invite && (int) $invite['id'] !== $userId) {
                        mysqli_query($db, "INSERT IGNORE INTO talkora_community_members (community_id,user_id,role) VALUES ($communityId," . (int) $invite['id'] . ",'member')");
                    }
                }
                redirect('communities.php?community_id=' . $communityId);
            }
            $error = 'The community could not be created.';
        }
    } elseif ($action === 'join') {
        $communityId = (int) ($_POST['community_id'] ?? 0);
        $community = mysqli_fetch_assoc(mysqli_query($db, "SELECT id,password_hash FROM talkora_communities WHERE id=$communityId AND visibility='public' LIMIT 1"));
        if (!$community) {
            $error = 'This public community is not available.';
        } elseif (!empty($community['password_hash']) && !password_verify((string) ($_POST['room_password'] ?? ''), $community['password_hash'])) {
            $error = 'The room password is incorrect.';
        } else {
            mysqli_query($db, "INSERT IGNORE INTO talkora_community_members (community_id,user_id,role) VALUES ($communityId,$userId,'member')");
            redirect('communities.php?community_id=' . $communityId);
        }
    } elseif ($action === 'leave') {
        $communityId = (int) ($_POST['community_id'] ?? 0);
        mysqli_query($db, "DELETE FROM talkora_community_members WHERE community_id=$communityId AND user_id=$userId AND role='member'");
        redirect('communities.php');
    } elseif ($action === 'invite') {
        $communityId = (int) ($_POST['community_id'] ?? 0);
        $owner = mysqli_query($db, "SELECT id FROM talkora_community_members WHERE community_id=$communityId AND user_id=$userId AND role='owner' LIMIT 1");
        if (!$owner || mysqli_num_rows($owner) === 0) {
            $error = 'Only the community owner can invite members.';
        } else {
            $codes = preg_split('/[\s,;]+/', trim((string) ($_POST['invite_codes'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $added = 0;
            foreach (array_slice(array_unique($codes), 0, 25) as $code) {
                $code = mysqli_real_escape_string($db, $code);
                $invite = mysqli_fetch_assoc(mysqli_query($db, "SELECT id FROM users WHERE user_id='$code' LIMIT 1"));
                if ($invite && (int) $invite['id'] !== $userId) {
                    $added += mysqli_query($db, "INSERT IGNORE INTO talkora_community_members (community_id,user_id,role) VALUES ($communityId," . (int) $invite['id'] . ",'member')") ? mysqli_affected_rows($db) : 0;
                }
            }
            $notice = $added . ' member' . ($added === 1 ? '' : 's') . ' added.';
        }
    } elseif ($action === 'update_settings') {
        $communityId = (int) ($_POST['community_id'] ?? 0);
        $owner = mysqli_query($db, "SELECT id FROM talkora_communities WHERE id=$communityId AND owner_id=$userId LIMIT 1");
        $theme = (string) ($_POST['theme_color'] ?? 'blue');
        $allowedThemes = ['blue', 'coral', 'mint', 'amber'];
        if (!$owner || mysqli_num_rows($owner) === 0) {
            $error = 'Only the community owner can change community settings.';
        } elseif (!in_array($theme, $allowedThemes, true)) {
            $error = 'Choose one of the available community themes.';
        } else {
            $themeEsc = mysqli_real_escape_string($db, $theme);
            mysqli_query($db, "UPDATE talkora_communities SET theme_color='$themeEsc' WHERE id=$communityId");
            redirect('communities.php?community_id=' . $communityId);
        }
    } elseif ($action === 'delete_community') {
        $communityId = (int) ($_POST['community_id'] ?? 0);
        $deleted = mysqli_query($db, "DELETE FROM talkora_communities WHERE id=$communityId AND owner_id=$userId");
        if ($deleted && mysqli_affected_rows($db) > 0) redirect('communities.php');
        $error = 'Only the owner can delete this community.';
    } elseif ($action === 'report_community') {
        $communityId = (int) ($_POST['community_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $details = trim((string) ($_POST['details'] ?? ''));
        $canSee = mysqli_query($db, "SELECT c.id FROM talkora_communities c WHERE c.id=$communityId AND (c.visibility='public' OR EXISTS(SELECT 1 FROM talkora_community_members cm WHERE cm.community_id=c.id AND cm.user_id=$userId)) LIMIT 1");
        $allowedReasons = ['Spam', 'Harassment', 'Harmful content', 'Other'];
        if (!$canSee || mysqli_num_rows($canSee) === 0 || !in_array($reason, $allowedReasons, true)) {
            $error = 'The community report could not be submitted.';
        } else {
            $reasonSql = mysqli_real_escape_string($db, $reason);
            $detailsSql = mysqli_real_escape_string($db, mb_substr($details, 0, 2000));
            mysqli_query($db, "INSERT INTO talkora_community_reports (community_id,reporter_id,reason,details) VALUES ($communityId,$userId,'$reasonSql','$detailsSql')");
            $notice = 'Report submitted for review.';
        }
    } elseif ($action === 'message') {
        $communityId = (int) ($_POST['community_id'] ?? 0);
        $message = trim((string) ($_POST['message'] ?? ''));
        $member = mysqli_query($db, "SELECT user_id FROM talkora_community_members WHERE community_id=$communityId AND user_id=$userId LIMIT 1");
        if (!$member || mysqli_num_rows($member) === 0) {
            $error = 'Join this community before sending a message.';
        } elseif ($message === '' || mb_strlen($message) > 2000) {
            $error = 'Write a message up to 2,000 characters.';
        } else {
            $messageSql = mysqli_real_escape_string($db, $message);
            mysqli_query($db, "INSERT INTO talkora_community_messages (community_id,user_id,message) VALUES ($communityId,$userId,'$messageSql')");
            redirect('communities.php?community_id=' . $communityId . '#community-chat');
        }
    }
}

$communityId = (int) ($_GET['community_id'] ?? ($_POST['community_id'] ?? 0));
$selected = null;
$isMember = false;
if ($communityId > 0) {
    $selectedResult = mysqli_query($db, "SELECT c.*,u.full_name AS owner_name,(SELECT COUNT(*) FROM talkora_community_members cm WHERE cm.community_id=c.id) AS member_count,EXISTS(SELECT 1 FROM talkora_community_members mine WHERE mine.community_id=c.id AND mine.user_id=$userId) AS is_member FROM talkora_communities c JOIN users u ON u.id=c.owner_id WHERE c.id=$communityId AND (c.visibility='public' OR EXISTS(SELECT 1 FROM talkora_community_members access WHERE access.community_id=c.id AND access.user_id=$userId)) LIMIT 1");
    $selected = $selectedResult ? mysqli_fetch_assoc($selectedResult) : null;
    if (!$selected) $error = 'This community is private or no longer available.';
    else $isMember = (int) $selected['is_member'] === 1;
}
$communitiesResult = mysqli_query($db, "SELECT c.*,u.full_name AS owner_name,(SELECT COUNT(*) FROM talkora_community_members cm WHERE cm.community_id=c.id) AS member_count,EXISTS(SELECT 1 FROM talkora_community_members mine WHERE mine.community_id=c.id AND mine.user_id=$userId) AS is_member FROM talkora_communities c JOIN users u ON u.id=c.owner_id WHERE c.visibility='public' OR EXISTS(SELECT 1 FROM talkora_community_members access WHERE access.community_id=c.id AND access.user_id=$userId) ORDER BY is_member DESC,c.created_at DESC LIMIT 100");
$communities = [];
while ($communitiesResult && ($row = mysqli_fetch_assoc($communitiesResult))) $communities[] = $row;
$themeValues = ['blue' => '#3b82f6', 'coral' => '#f97373', 'mint' => '#2fbd92', 'amber' => '#e5a526'];
$communityAccent = $selected ? ($themeValues[$selected['theme_color'] ?? 'blue'] ?? $themeValues['blue']) : $themeValues['blue'];
$roomMessages = [];
$roomMembers = [];
if ($selected && ($isMember || $selected['visibility'] === 'public')) {
    $messagesResult = mysqli_query($db, "SELECT m.*,u.full_name,u.avatar,r.full_name AS reply_sender_name FROM talkora_community_messages m JOIN users u ON u.id=m.user_id LEFT JOIN talkora_community_messages rm ON rm.id=m.reply_to_id LEFT JOIN users r ON r.id=rm.user_id WHERE m.community_id=$communityId ORDER BY m.id DESC LIMIT 100");
    while ($messagesResult && ($row = mysqli_fetch_assoc($messagesResult))) $roomMessages[] = $row;
    $roomMessages = array_reverse($roomMessages);
    $membersResult = mysqli_query($db, "SELECT u.id,u.full_name,u.user_id,u.avatar,cm.role FROM talkora_community_members cm JOIN users u ON u.id=cm.user_id WHERE cm.community_id=$communityId ORDER BY cm.role='owner' DESC,u.full_name LIMIT 250");
    while ($membersResult && ($row = mysqli_fetch_assoc($membersResult))) $roomMembers[] = $row;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Communities | TalkOra</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#2563eb">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="social-page communities-page <?php echo normalize_theme($user['theme']) === 'light' ? 'light-theme' : ''; ?>" style="--community-accent:<?php echo htmlspecialchars($communityAccent, ENT_QUOTES, 'UTF-8'); ?>" data-current-user-id="<?php echo $userId; ?>" data-notifications-enabled="<?php echo (int) $user['notifications']; ?>">
    <main class="community-shell">
        <header class="social-topbar">
            <a class="social-brand" href="dashboard.php">TalkOra</a>
            <nav class="social-nav" aria-label="Main navigation">
                <a href="dashboard.php">Chats</a><a href="community.php">Feed</a><a href="community.php?view=reels">Reels</a><a href="groups.php">Groups</a><a class="active" href="communities.php">Communities</a>
            </nav>
            <a class="btn btn-sm btn-outline-light" href="profile.php">Profile</a>
        </header>
        <section class="community-content communities-layout">
            <aside class="communities-list">
                <div class="community-heading"><div><span class="eyebrow">Find your people</span><h1>Communities</h1></div></div>
                <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
                <?php foreach ($communities as $community): ?>
                    <a class="community-list-item <?php echo $selected && (int) $selected['id'] === (int) $community['id'] ? 'active' : ''; ?>" href="communities.php?community_id=<?php echo (int) $community['id']; ?>">
                        <span class="community-list-icon" aria-hidden="true"><?php echo $community['visibility'] === 'private' ? '🔒' : '#'; ?></span>
                        <span class="community-list-copy"><strong><?php echo htmlspecialchars($community['name']); ?></strong><small><?php echo (int) $community['member_count']; ?> members · <?php echo $community['visibility'] === 'private' ? 'Private' : 'Public'; ?></small></span>
                        <?php if ((int) $community['is_member']): ?><span class="community-member-mark">Joined</span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!$communities): ?><p class="communities-empty-list">No communities yet. Start one for your circle.</p><?php endif; ?>
                <details class="community-create">
                    <summary>Create a community</summary>
                    <form method="post" class="community-create-form">
                        <input type="hidden" name="action" value="create">
                        <label>Name<input name="name" maxlength="100" required placeholder="e.g. Weekend hikers"></label>
                        <label>Description<textarea name="description" maxlength="500" rows="3" placeholder="What brings everyone together?"></textarea></label>
                        <label>Visibility<select name="visibility"><option value="public">Public, discoverable by everyone</option><option value="private">Private, invite only</option></select></label>
                        <label>Room password <span class="community-field-hint">Optional for public rooms</span><input type="password" name="room_password" maxlength="100" autocomplete="new-password"></label>
                        <label>Invite TalkOra IDs <span class="community-field-hint">Optional, separate IDs with commas</span><textarea name="invite_codes" rows="2" placeholder="TO-12345, TO-67890"></textarea></label>
                        <button class="btn btn-primary" type="submit">Create community</button>
                    </form>
                </details>
            </aside>
            <section class="community-room" id="community-chat">
                <?php if ($selected): ?>
                    <header class="community-room-header">
                        <div><span class="eyebrow"><?php echo $selected['visibility'] === 'private' ? 'Private community' : 'Public community'; ?></span><h2><?php echo htmlspecialchars($selected['name']); ?></h2><p><?php echo htmlspecialchars($selected['description'] ?: 'Created by ' . $selected['owner_name']); ?></p></div>
                        <details class="community-members"><summary><?php echo (int) $selected['member_count']; ?> members</summary><div class="community-member-list" id="communityMemberList"><?php foreach ($roomMembers as $roomMember): ?><div class="community-member"><span class="community-member-avatar"><?php echo htmlspecialchars(strtoupper(substr($roomMember['full_name'], 0, 1))); ?></span><span><strong><?php echo htmlspecialchars($roomMember['full_name']); ?></strong><small><?php echo htmlspecialchars($roomMember['user_id']); ?></small></span><?php if ($roomMember['role'] === 'owner'): ?><em>Owner</em><?php endif; ?></div><?php endforeach; ?><?php if (!$roomMembers): ?><p class="small text-secondary mb-0">No members yet.</p><?php endif; ?></div></details>
                    </header>
                    <?php if (!$isMember): ?>
                        <div class="community-join-panel">
                            <p><?php echo !empty($selected['password_hash']) ? 'This room is password protected.' : 'Join this public room to take part in the conversation.'; ?></p>
                            <form method="post" class="community-join-form"><input type="hidden" name="action" value="join"><input type="hidden" name="community_id" value="<?php echo (int) $selected['id']; ?>"><?php if (!empty($selected['password_hash'])): ?><label class="visually-hidden" for="roomPassword">Room password</label><input id="roomPassword" type="password" name="room_password" placeholder="Room password" required><?php endif; ?><button class="btn btn-primary" type="submit">Join community</button></form>
                        </div>
                    <?php else: ?>
                        <?php if ((int) $selected['owner_id'] === $userId): ?>
                            <details class="community-invite"><summary>Invite members</summary><form method="post"><input type="hidden" name="action" value="invite"><input type="hidden" name="community_id" value="<?php echo (int) $selected['id']; ?>"><label class="visually-hidden" for="communityInviteCodes">TalkOra IDs to invite</label><input id="communityInviteCodes" name="invite_codes" placeholder="TalkOra IDs, separated by commas" required><button class="btn btn-sm btn-primary" type="submit">Add</button></form></details>
                            <details class="community-settings"><summary>Community settings</summary><form method="post" class="community-settings-form"><input type="hidden" name="action" value="update_settings"><input type="hidden" name="community_id" value="<?php echo (int) $selected['id']; ?>"><label for="communityTheme">Community theme</label><select id="communityTheme" name="theme_color"><?php foreach (['blue' => 'Ocean blue', 'coral' => 'Coral', 'mint' => 'Mint', 'amber' => 'Amber'] as $themeKey => $themeName): ?><option value="<?php echo $themeKey; ?>" <?php echo ($selected['theme_color'] ?? 'blue') === $themeKey ? 'selected' : ''; ?>><?php echo $themeName; ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Save theme</button></form><form method="post" class="community-delete-form" onsubmit="return confirm('Delete this community and its chat history? This cannot be undone.');"><input type="hidden" name="action" value="delete_community"><input type="hidden" name="community_id" value="<?php echo (int) $selected['id']; ?>"><button class="btn btn-danger" type="submit">Delete community</button></form></details>
                        <?php else: ?>
                            <form method="post" class="community-leave"><input type="hidden" name="action" value="leave"><input type="hidden" name="community_id" value="<?php echo (int) $selected['id']; ?>"><button type="submit">Leave community</button></form>
                        <?php endif; ?>
                        <details class="community-report"><summary>Report community</summary><form method="post"><input type="hidden" name="action" value="report_community"><input type="hidden" name="community_id" value="<?php echo (int) $selected['id']; ?>"><label class="visually-hidden" for="communityReportReason">Reason for report</label><select id="communityReportReason" name="reason" required><option value="">Choose a reason</option><option>Spam</option><option>Harassment</option><option>Harmful content</option><option>Other</option></select><label class="visually-hidden" for="communityReportDetails">Report details</label><textarea id="communityReportDetails" name="details" maxlength="2000" rows="2" placeholder="Add details (optional)"></textarea><button class="btn btn-sm btn-danger" type="submit">Submit report</button></form></details>
                        <details class="community-tools" id="communityTools"><summary>Watch together and games</summary><div class="community-watch-tool"><form id="communityWatchForm"><label for="communityYoutubeUrl">YouTube video</label><div><input id="communityYoutubeUrl" type="url" placeholder="Paste a YouTube link" required><button class="btn btn-sm btn-primary" type="submit">Share video</button></div></form><div class="community-watch-meta" id="communityWatchMeta">No shared video selected.</div><div class="community-youtube-frame" id="communityYoutubeFrame" hidden><div id="communityYoutubePlayer"></div></div></div><div class="community-games-tool"><div class="community-games-heading"><strong>Play together</strong><span>Choose a game, then invite a member to join.</span></div><div class="community-game-create"><button type="button" data-create-game="tictactoe">Tic-Tac-Toe</button><button type="button" data-create-game="connect4">Connect Four</button><button type="button" data-create-game="rps">Rock / Paper / Scissors</button></div><div class="community-game-list" id="communityGameList" aria-live="polite"></div></div></details>
                        <div class="community-room-messages" id="communityRoomMessages" aria-live="polite" data-community-id="<?php echo (int) $communityId; ?>" data-user-id="<?php echo $userId; ?>">
                            <?php foreach ($roomMessages as $message): $mine = (int) $message['user_id'] === $userId; $deleted = (int) ($message['is_deleted'] ?? 0) === 1; $editable = $mine && !$deleted && (time() - strtotime($message['created_at'])) / 60 <= 15; ?>
                                <article class="community-room-message <?php echo $mine ? 'mine' : ''; ?>" data-community-message-id="<?php echo (int) $message['id']; ?>" data-message-text="<?php echo htmlspecialchars((string) $message['message'], ENT_QUOTES, 'UTF-8'); ?>" data-editable="<?php echo $editable ? '1' : '0'; ?>" data-deletable="<?php echo $mine && !$deleted ? '1' : '0'; ?>">
                                    <div class="community-room-message-meta"><strong><?php echo htmlspecialchars($message['full_name']); ?></strong><time><?php echo htmlspecialchars(date('M j, g:i A', strtotime($message['created_at']))); ?></time></div>
                                    <?php if (!empty($message['reply_to_id']) && !empty($message['reply_preview_text'])): ?><div class="community-reply-preview"><strong><?php echo htmlspecialchars($message['reply_sender_name'] ?: 'Message'); ?></strong><span><?php echo htmlspecialchars($message['reply_preview_text']); ?></span></div><?php endif; ?>
                                    <?php if ($deleted): ?><p class="community-deleted-message">This message was deleted</p><?php else: ?>
                                        <?php if (($message['media_type'] ?? '') === 'image' && !empty($message['media_path'])): ?><img class="community-message-image" src="<?php echo htmlspecialchars($message['media_path']); ?>" alt="Photo shared by <?php echo htmlspecialchars($message['full_name']); ?>" loading="lazy"><?php elseif (($message['media_type'] ?? '') === 'audio' && !empty($message['media_path'])): ?><audio class="community-message-audio" controls preload="metadata" src="<?php echo htmlspecialchars($message['media_path']); ?>"></audio><?php endif; ?>
                                        <?php if ($message['message'] !== ''): ?><p><?php echo nl2br(htmlspecialchars($message['message'])); ?><?php if (!empty($message['edited_at'])): ?> <small>(edited)</small><?php endif; ?></p><?php endif; ?>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                            <?php if (!$roomMessages): ?><p class="communities-empty-room">This room is ready. Say hello to get things started.</p><?php endif; ?>
                        </div>
                        <div class="community-typing-indicator" id="communityTypingIndicator" aria-live="polite" hidden><span id="communityTypingNames"></span><span class="typing-dots" aria-hidden="true"><i></i><i></i><i></i></span></div>
                        <form class="community-room-composer" id="communityMessageForm" enctype="multipart/form-data"><input type="hidden" name="community_id" value="<?php echo (int) $selected['id']; ?>"><input type="hidden" name="reply_to_id" id="communityReplyToId" value=""><div class="community-reply-composer" id="communityReplyComposer" hidden><span id="communityReplyPreview"></span><button type="button" id="communityReplyCancel" aria-label="Cancel reply">×</button></div><input type="file" id="communityImageInput" name="image" accept="image/*" hidden><input type="file" id="communityAudioInput" name="audio" accept="audio/*" hidden><button type="button" class="community-media-button" id="communityImageButton" aria-label="Attach photo" title="Attach photo">▧</button><button type="button" class="community-media-button" id="communityAudioButton" aria-label="Attach audio" title="Attach audio">♫</button><button type="button" class="community-media-button" id="communityRecordButton" aria-label="Record voice message" title="Record voice message">●</button><label class="visually-hidden" for="communityMessage">Write a message</label><textarea id="communityMessage" name="message" maxlength="4000" rows="1" placeholder="Write to the community"></textarea><button class="btn btn-primary" type="submit">Send</button></form>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="communities-welcome"><span class="eyebrow">TalkOra spaces</span><h2>Meet around what matters.</h2><p>Browse public rooms, join a password-protected space, or create a private community and invite members by their TalkOra ID.</p></div>
                <?php endif; ?>
            </section>
        </section>
    </main>
    <?php if ($selected && $isMember): ?><div class="community-message-menu" id="communityMessageMenu" role="menu"><button type="button" data-community-action="reply">Reply</button><button type="button" data-community-action="forward">Forward</button><button type="button" data-community-action="copy">Copy</button><button type="button" data-community-action="edit">Edit</button><button type="button" data-community-action="delete">Delete</button></div><div class="image-lightbox" id="communityImageLightbox" aria-hidden="true"><button type="button" class="image-lightbox-close" aria-label="Close image">×</button><img alt="Expanded community photo"></div><?php endif; ?>
    <script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
    <?php if ($selected && $isMember): ?><script src="assets/js/community-chat.js?v=<?php echo filemtime(__DIR__ . '/assets/js/community-chat.js'); ?>"></script><script src="assets/js/community-collab.js?v=<?php echo filemtime(__DIR__ . '/assets/js/community-collab.js'); ?>"></script><?php endif; ?>
    <script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(function(){});</script>
</body>
</html>