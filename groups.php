<?php
require_once __DIR__ . '/config.php';
ensure_logged_in();

$db = db_connect();
$user = current_user();
$userId = (int) $user['id'];

mysqli_query($db, "CREATE TABLE IF NOT EXISTS chat_groups (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, owner_id INT NOT NULL, room_key CHAR(40) NOT NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS group_members (group_id INT NOT NULL, user_id INT NOT NULL, role ENUM('owner','member') NOT NULL DEFAULT 'member', joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (group_id,user_id), FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS group_messages (id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, sender_id INT NOT NULL, message TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE, FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_group_message (group_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_group'])) {
        $name = trim((string) ($_POST['group_name'] ?? ''));
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $_POST['members'] ?? []))));
        if ($name === '' || count($memberIds) === 0) {
            $error = 'Choose a name and at least one contact.';
        } else {
            $nameEsc = mysqli_real_escape_string($db, mb_substr($name, 0, 80));
            $roomKey = bin2hex(random_bytes(20));
            mysqli_begin_transaction($db);
            $created = mysqli_query($db, "INSERT INTO chat_groups (name,owner_id,room_key) VALUES ('$nameEsc',$userId,'$roomKey')");
            if ($created) {
                $groupId = mysqli_insert_id($db);
                mysqli_query($db, "INSERT INTO group_members (group_id,user_id,role) VALUES ($groupId,$userId,'owner')");
                foreach ($memberIds as $memberId) {
                    $linked = mysqli_query($db, "SELECT id FROM contacts WHERE (user_id=$userId AND contact_user_id=$memberId) OR (user_id=$memberId AND contact_user_id=$userId) LIMIT 1");
                    if ($memberId !== $userId && $linked && mysqli_num_rows($linked) > 0) {
                        mysqli_query($db, "INSERT IGNORE INTO group_members (group_id,user_id) VALUES ($groupId,$memberId)");
                    }
                }
                mysqli_commit($db);
                redirect('groups.php?group_id=' . $groupId);
            }
            mysqli_rollback($db);
            $error = 'The group could not be created. Please try again.';
        }
    } elseif (isset($_POST['send_group_message'])) {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $message = trim((string) ($_POST['message'] ?? ''));
        $membership = mysqli_query($db, "SELECT group_id FROM group_members WHERE group_id=$groupId AND user_id=$userId LIMIT 1");
        if ($membership && mysqli_num_rows($membership) > 0 && $message !== '') {
            $message = mysqli_real_escape_string($db, mb_substr($message, 0, 4000));
            mysqli_query($db, "INSERT INTO group_messages (group_id,sender_id,message) VALUES ($groupId,$userId,'$message')");
        }
        redirect('groups.php?group_id=' . $groupId);
    }
}

$groupsResult = mysqli_query($db, "SELECT g.*, gm.role, (SELECT COUNT(*) FROM group_members gm2 WHERE gm2.group_id=g.id) AS member_count FROM group_members gm JOIN chat_groups g ON g.id=gm.group_id WHERE gm.user_id=$userId ORDER BY g.created_at DESC");
$groups = [];
while ($groupsResult && ($row = mysqli_fetch_assoc($groupsResult))) $groups[] = $row;
$contactsResult = mysqli_query($db, "SELECT DISTINCT u.id,u.full_name FROM contacts c JOIN users u ON u.id=IF(c.user_id=$userId,c.contact_user_id,c.user_id) WHERE c.user_id=$userId OR c.contact_user_id=$userId ORDER BY u.full_name");
$contacts = [];
while ($contactsResult && ($row = mysqli_fetch_assoc($contactsResult))) $contacts[] = $row;

$selectedId = (int) ($_GET['group_id'] ?? 0);
$selectedGroup = null;
foreach ($groups as $group) if ((int) $group['id'] === $selectedId) $selectedGroup = $group;
if (!$selectedGroup && $selectedId > 0) redirect('groups.php');
$messages = [];
if ($selectedGroup) {
    $messageResult = mysqli_query($db, "SELECT gm.*,u.full_name FROM group_messages gm JOIN users u ON u.id=gm.sender_id WHERE gm.group_id=$selectedId ORDER BY gm.id DESC LIMIT 100");
    while ($messageResult && ($row = mysqli_fetch_assoc($messageResult))) $messages[] = $row;
    $messages = array_reverse($messages);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Groups | TalkOra</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="social-page <?php echo normalize_theme($user['theme']) === 'light' ? 'light-theme' : ''; ?>" data-current-user-id="<?php echo $userId; ?>" data-notifications-enabled="<?php echo (int) $user['notifications']; ?>" style="--user-font-size:<?php echo max(12, min(22, (int) $user['font_size'])); ?>px;--user-font-family:<?php echo htmlspecialchars(json_encode($user['font_family'] ?: 'Segoe UI'), ENT_QUOTES, 'UTF-8'); ?>">
    <main class="groups-shell">
        <header class="social-topbar">
            <a class="social-brand" href="dashboard.php">TalkOra</a>
            <nav class="social-nav" aria-label="Main navigation"><a href="dashboard.php">Chats</a><a href="community.php">Feed</a><a class="active" href="groups.php">Groups</a><a href="communities.php">Communities</a></nav>
            <a class="btn btn-sm btn-outline-light" href="logout.php">Log out</a>
        </header>
        <div class="groups-layout">
            <aside class="groups-rail">
                <h1>Groups</h1>
                <form method="post" class="group-create-form">
                    <label for="groupName">New group</label>
                    <input class="form-control" id="groupName" name="group_name" maxlength="80" placeholder="Give it a name" required>
                    <label for="groupMembers">Add contacts</label>
                    <select class="form-select" id="groupMembers" name="members[]" multiple required>
                        <?php foreach ($contacts as $contact): ?><option value="<?php echo (int) $contact['id']; ?>"><?php echo htmlspecialchars($contact['full_name']); ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-success w-100" type="submit" name="create_group" value="1" <?php echo empty($contacts) ? 'disabled' : ''; ?>>Create group</button>
                </form>
                <?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <div class="group-list" aria-label="Your groups">
                    <?php foreach ($groups as $group): ?><a class="group-list-item <?php echo (int) $group['id'] === $selectedId ? 'active' : ''; ?>" href="groups.php?group_id=<?php echo (int) $group['id']; ?>"><span class="group-avatar"><?php echo htmlspecialchars(strtoupper(substr($group['name'], 0, 1))); ?></span><span><strong><?php echo htmlspecialchars($group['name']); ?></strong><small><?php echo (int) $group['member_count']; ?> members</small></span></a><?php endforeach; ?>
                    <?php if (empty($groups)): ?><p class="empty-groups">Groups you create will appear here.</p><?php endif; ?>
                </div>
            </aside>
            <section class="group-room">
                <?php if ($selectedGroup): ?>
                    <header class="group-room-header"><div><span class="eyebrow">Group chat</span><h2><?php echo htmlspecialchars($selectedGroup['name']); ?></h2><p><?php echo (int) $selectedGroup['member_count']; ?> members</p></div><div class="group-call-actions"><a class="btn btn-outline-success" target="_blank" rel="noopener noreferrer" href="https://meet.jit.si/TalkOraGroup-<?php echo rawurlencode($selectedGroup['room_key']); ?>#config.startWithVideoMuted=true&config.startWithAudioMuted=false">Audio call</a><a class="btn btn-success" target="_blank" rel="noopener noreferrer" href="https://meet.jit.si/TalkOraGroup-<?php echo rawurlencode($selectedGroup['room_key']); ?>#config.startWithVideoMuted=false&config.startWithAudioMuted=false">Video call</a></div></header>
                    <div class="group-call-note">Group calls open in Jitsi Meet. Call participants need an internet connection and microphone/camera access.</div>
                    <div class="group-message-list" id="groupMessageList">
                        <?php foreach ($messages as $message): ?><article class="group-message <?php echo (int) $message['sender_id'] === $userId ? 'mine' : ''; ?>"><strong><?php echo htmlspecialchars($message['full_name']); ?></strong><p><?php echo nl2br(htmlspecialchars($message['message'])); ?></p><time><?php echo date('M j, g:i a', strtotime($message['created_at'])); ?></time></article><?php endforeach; ?>
                        <?php if (empty($messages)): ?><p class="group-empty-chat">Say hello to your group.</p><?php endif; ?>
                    </div>
                    <form method="post" class="group-composer"><input type="hidden" name="group_id" value="<?php echo $selectedId; ?>"><textarea name="message" maxlength="4000" rows="1" placeholder="Message the group" required></textarea><button class="btn btn-success" type="submit" name="send_group_message" value="1">Send</button></form>
                <?php else: ?>
                    <div class="group-welcome"><span class="eyebrow">Talk together</span><h2>Make room for everyone.</h2><p>Create a group with your contacts, keep the conversation going, or start a group voice or video call.</p></div>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <script>const list=document.getElementById('groupMessageList');if(list)list.scrollTop=list.scrollHeight;</script>
    <script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
    <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(function(){}); }</script>
</body>
</html>