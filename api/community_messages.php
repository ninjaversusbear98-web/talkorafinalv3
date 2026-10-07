<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
    exit;
}

$db = db_connect();
$userId = (int) $_SESSION['user_id'];
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
$messageType = mysqli_fetch_assoc(mysqli_query($db, "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='talkora_community_messages' AND COLUMN_NAME='message' LIMIT 1"));
if ($messageType && strtolower((string) $messageType['DATA_TYPE']) !== 'text') {
    mysqli_query($db, "ALTER TABLE talkora_community_messages MODIFY message TEXT NOT NULL");
}

$communityId = (int) (($_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST)['community_id'] ?? 0);
$membership = mysqli_query($db, "SELECT role FROM talkora_community_members WHERE community_id=$communityId AND user_id=$userId LIMIT 1");
$member = $membership ? mysqli_fetch_assoc($membership) : null;
$community = mysqli_fetch_assoc(mysqli_query($db, "SELECT visibility FROM talkora_communities WHERE id=$communityId LIMIT 1"));
$publicRosterAccess = $_SERVER['REQUEST_METHOD'] === 'GET' && $community && $community['visibility'] === 'public';
if (!$member && !$publicRosterAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Join this community to view or use its chat.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $messages = [];
    if ($member) {
        $result = mysqli_query($db, "SELECT m.*,u.full_name,u.avatar,r.full_name AS reply_sender_name FROM talkora_community_messages m JOIN users u ON u.id=m.user_id LEFT JOIN talkora_community_messages rm ON rm.id=m.reply_to_id LEFT JOIN users r ON r.id=rm.user_id WHERE m.community_id=$communityId ORDER BY m.id DESC LIMIT 100");
        while ($result && ($row = mysqli_fetch_assoc($result))) $messages[] = $row;
    }
    $messages = array_reverse($messages);
    $members = [];
    $memberResult = mysqli_query($db, "SELECT u.id,u.full_name,u.user_id,u.avatar,cm.role FROM talkora_community_members cm JOIN users u ON u.id=cm.user_id WHERE cm.community_id=$communityId ORDER BY cm.role='owner' DESC,u.full_name LIMIT 250");
    while ($memberResult && ($row = mysqli_fetch_assoc($memberResult))) $members[] = $row;
    $rooms = [];
    $roomResult = $member ? mysqli_query($db, "SELECT c.id,c.name FROM talkora_community_members mine JOIN talkora_communities c ON c.id=mine.community_id WHERE mine.user_id=$userId AND c.id<>$communityId ORDER BY c.name LIMIT 100") : false;
    while ($roomResult && ($row = mysqli_fetch_assoc($roomResult))) $rooms[] = $row;
    echo json_encode(['success' => true, 'messages' => $messages, 'members' => $members, 'rooms' => $rooms], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$action = trim((string) ($_POST['action'] ?? 'send'));
$messageId = (int) ($_POST['message_id'] ?? 0);
if ($action === 'edit' || $action === 'delete' || $action === 'forward') {
    $originalResult = mysqli_query($db, "SELECT * FROM talkora_community_messages WHERE id=$messageId AND community_id=$communityId LIMIT 1");
    $original = $originalResult ? mysqli_fetch_assoc($originalResult) : null;
    if (!$original || (int) $original['is_deleted'] === 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'That message is no longer available.']);
        exit;
    }
    if ($action === 'edit') {
        if ((int) $original['user_id'] !== $userId || (time() - strtotime($original['created_at'])) / 60 > 15) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only your own recent messages can be edited.']);
            exit;
        }
        $text = trim((string) ($_POST['message'] ?? ''));
        if ($text === '' || mb_strlen($text) > 4000) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Enter text up to 4,000 characters.']);
            exit;
        }
        $textSql = mysqli_real_escape_string($db, $text);
        mysqli_query($db, "UPDATE talkora_community_messages SET message='$textSql',edited_at=NOW() WHERE id=$messageId AND user_id=$userId");
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'delete') {
        if ((int) $original['user_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You can only delete your own messages.']);
            exit;
        }
        mysqli_query($db, "UPDATE talkora_community_messages SET message='This message was deleted',media_type=NULL,media_path=NULL,is_deleted=1,edited_at=NULL WHERE id=$messageId AND user_id=$userId");
        echo json_encode(['success' => true]);
        exit;
    }
    $targetId = (int) ($_POST['target_community_id'] ?? 0);
    $targetMember = mysqli_query($db, "SELECT community_id FROM talkora_community_members WHERE community_id=$targetId AND user_id=$userId LIMIT 1");
    if (!$targetMember || mysqli_num_rows($targetMember) === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Join the destination community before forwarding.']);
        exit;
    }
    $text = mysqli_real_escape_string($db, (string) $original['message']);
    $mediaType = empty($original['media_type']) ? 'NULL' : "'" . mysqli_real_escape_string($db, $original['media_type']) . "'";
    $mediaPath = empty($original['media_path']) ? 'NULL' : "'" . mysqli_real_escape_string($db, $original['media_path']) . "'";
    mysqli_query($db, "INSERT INTO talkora_community_messages (community_id,user_id,message,media_type,media_path) VALUES ($targetId,$userId,'$text',$mediaType,$mediaPath)");
    echo json_encode(['success' => true]);
    exit;
}

$text = trim((string) ($_POST['message'] ?? ''));
$replyToId = (int) ($_POST['reply_to_id'] ?? 0);
$mediaType = null;
$mediaPath = null;
foreach (['image', 'audio'] as $field) {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) continue;
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 20 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Choose a supported file up to 20 MB.']);
        exit;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    $types = $field === 'image'
        ? ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif']
        : ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'video/webm' => 'webm', 'video/mp4' => 'mp4'];
    if (!isset($types[$mime])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This image or audio format is not supported.']);
        exit;
    }
    $directory = __DIR__ . '/../storage/uploads/communities';
    if (!is_dir($directory)) mkdir($directory, 0775, true);
    $filename = 'community_' . $communityId . '_' . bin2hex(random_bytes(12)) . '.' . $types[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The upload could not be saved.']);
        exit;
    }
    $mediaType = $field;
    $mediaPath = 'storage/uploads/communities/' . $filename;
    break;
}
if (($text === '' && $mediaPath === null) || mb_strlen($text) > 4000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Write a message or attach media.']);
    exit;
}
$replyPreview = null;
if ($replyToId > 0) {
    $replyResult = mysqli_query($db, "SELECT message,is_deleted FROM talkora_community_messages WHERE id=$replyToId AND community_id=$communityId LIMIT 1");
    $replyRow = $replyResult ? mysqli_fetch_assoc($replyResult) : null;
    if ($replyRow) $replyPreview = (int) $replyRow['is_deleted'] === 1 ? 'Message' : mb_substr((string) $replyRow['message'], 0, 500);
    else $replyToId = 0;
}
$textSql = mysqli_real_escape_string($db, $text);
$typeSql = $mediaType ? "'" . mysqli_real_escape_string($db, $mediaType) . "'" : 'NULL';
$pathSql = $mediaPath ? "'" . mysqli_real_escape_string($db, $mediaPath) . "'" : 'NULL';
$replySql = $replyToId > 0 ? (string) $replyToId : 'NULL';
$previewSql = $replyPreview !== null ? "'" . mysqli_real_escape_string($db, $replyPreview) . "'" : 'NULL';
$inserted = mysqli_query($db, "INSERT INTO talkora_community_messages (community_id,user_id,message,media_type,media_path,reply_to_id,reply_preview_text) VALUES ($communityId,$userId,'$textSql',$typeSql,$pathSql,$replySql,$previewSql)");
echo json_encode(['success' => (bool) $inserted, 'message' => $inserted ? '' : 'Message could not be saved.']);