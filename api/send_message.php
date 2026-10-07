<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$db = db_connect();
$userId = (int) $_SESSION['user_id'];
$receiverId = isset($_POST['receiver_id']) ? (int) $_POST['receiver_id'] : 0;
$messageText = isset($_POST['message']) ? trim((string) $_POST['message']) : '';
$replyToId = isset($_POST['reply_to_id']) ? (int) $_POST['reply_to_id'] : 0;

if ($receiverId <= 0 || $receiverId === $userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Receiver is required.']);
    exit;
}

mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_blocked_users (user_id INT NOT NULL,blocked_user_id INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,blocked_user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(blocked_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$blocked = mysqli_query($db, "SELECT user_id FROM talkora_blocked_users WHERE (user_id=$userId AND blocked_user_id=$receiverId) OR (user_id=$receiverId AND blocked_user_id=$userId) LIMIT 1");
if ($blocked && mysqli_num_rows($blocked) > 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This contact is blocked. Unblock them before messaging.']);
    exit;
}

$contactCheck = mysqli_query($db, "SELECT id FROM contacts WHERE ((user_id = $userId AND contact_user_id = $receiverId) OR (user_id = $receiverId AND contact_user_id = $userId)) LIMIT 1");
if (!$contactCheck || mysqli_num_rows($contactCheck) === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only contacts can message each other.']);
    exit;
}

$mediaType = null;
$mediaPath = null;
$uploadDir = __DIR__ . '/../storage/uploads/messages';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

foreach (['image', 'audio'] as $field) {
    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$field];
        $allowedMime = $field === 'image'
            ? ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            : [
                'audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/webm', 'audio/wav', 'audio/x-wav',
                'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/flac', 'application/ogg',
                // Some servers/fileinfo identify an audio-only WebM container as video/webm.
                'video/webm', 'video/mp4'
            ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedAudioExt = ['webm','ogg','oga','mp3','wav','m4a','aac','flac','mp4'];
        $audioExtensionLooksValid = $field === 'audio' && in_array($ext, $allowedAudioExt, true);

        // Browser MediaRecorder output can be reported differently by different hosting
        // servers. Accept known audio MIME types, and also known audio extensions when
        // the server reports a generic/alternate container MIME.
        $mimeAllowed = in_array($mime, $allowedMime, true);
        $genericContainerMime = in_array($mime, ['application/octet-stream', 'binary/octet-stream', 'application/x-unknown'], true);
        if (!$mimeAllowed && !($audioExtensionLooksValid && ($genericContainerMime || str_starts_with((string)$mime, 'audio/')))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unsupported audio format. Please record again or use WebM, OGG, MP3, WAV, or M4A.']);
            exit;
        }

        if ($ext === '') {
            if ($field === 'image') {
                $ext = 'png';
            } elseif (str_contains($mime, 'ogg')) {
                $ext = 'ogg';
            } elseif (str_contains($mime, 'wav')) {
                $ext = 'wav';
            } elseif (str_contains($mime, 'mpeg') || str_contains($mime, 'mp3')) {
                $ext = 'mp3';
            } else {
                $ext = 'webm';
            }
        }

        $filename = 'msg_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save media file.']);
            exit;
        }

        $mediaType = $field;
        $mediaPath = 'storage/uploads/messages/' . $filename;
        break;
    }
}

$emptyText = $messageText === '' && $mediaType === null;
if ($emptyText) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message or media is required.']);
    exit;
}

if (!table_has_column($db, 'messages', 'is_deleted')) { mysqli_query($db, "ALTER TABLE messages ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0"); }
if (!table_has_column($db, 'messages', 'deleted_at')) { mysqli_query($db, "ALTER TABLE messages ADD COLUMN deleted_at DATETIME DEFAULT NULL"); }
if (!table_has_column($db, 'messages', 'edited_at')) { mysqli_query($db, "ALTER TABLE messages ADD COLUMN edited_at DATETIME DEFAULT NULL"); }
if (!table_has_column($db, 'messages', 'reply_to_id')) { mysqli_query($db, "ALTER TABLE messages ADD COLUMN reply_to_id INT DEFAULT NULL"); }
if (!table_has_column($db, 'messages', 'reply_preview_text')) { mysqli_query($db, "ALTER TABLE messages ADD COLUMN reply_preview_text VARCHAR(500) DEFAULT NULL"); }
if (!table_has_column($db, 'messages', 'notification_sent_at')) { mysqli_query($db, "ALTER TABLE messages ADD COLUMN notification_sent_at DATETIME DEFAULT NULL"); }
if (!table_has_column($db, 'messages', 'media_type')) {
    mysqli_query($db, "ALTER TABLE messages ADD COLUMN media_type VARCHAR(32) NULL DEFAULT NULL AFTER message");
}
if (!table_has_column($db, 'messages', 'media_path')) {
    mysqli_query($db, "ALTER TABLE messages ADD COLUMN media_path VARCHAR(255) NULL DEFAULT NULL AFTER media_type");
}

$hasDeliveredAt = table_has_column($db, 'messages', 'delivered_at');
$hasReadAt = table_has_column($db, 'messages', 'read_at');
$messageTextEsc = mysqli_real_escape_string($db, $messageText);
$mediaTypeEsc = $mediaType ? "'" . mysqli_real_escape_string($db, $mediaType) . "'" : 'NULL';
$mediaPathEsc = $mediaPath ? "'" . mysqli_real_escape_string($db, $mediaPath) . "'" : 'NULL';
$replySql = 'NULL';
$replyPreviewSql = 'NULL';
if ($replyToId > 0) {
    $replyCheck = mysqli_query($db, "SELECT id, message, media_type, is_deleted FROM messages WHERE id=$replyToId AND ((sender_id=$userId AND receiver_id=$receiverId) OR (sender_id=$receiverId AND receiver_id=$userId)) LIMIT 1");
    if ($replyCheck && mysqli_num_rows($replyCheck)>0) {
        $replyRow = mysqli_fetch_assoc($replyCheck);
        $replySql=(int)$replyToId;
        $preview = trim((string)($replyRow['message'] ?? ''));
        if ((int)($replyRow['is_deleted'] ?? 0) === 1) $preview = 'Message';
        if ($preview === '' && !empty($replyRow['media_type'])) $preview = $replyRow['media_type'] === 'audio' ? '🎤 Voice message' : '📷 Photo';
        $preview = mb_substr($preview, 0, 500);
        $replyPreviewSql = "'" . mysqli_real_escape_string($db, $preview) . "'";
    }
}

if ($hasDeliveredAt && $hasReadAt) {
    $result = mysqli_query($db, "INSERT INTO messages (sender_id, receiver_id, message, media_type, media_path, is_read, delivered_at, read_at, reply_to_id, reply_preview_text) VALUES ($userId, $receiverId, '$messageTextEsc', $mediaTypeEsc, $mediaPathEsc, 0, NOW(), NULL, $replySql, $replyPreviewSql)");
} elseif ($hasDeliveredAt) {
    $result = mysqli_query($db, "INSERT INTO messages (sender_id, receiver_id, message, media_type, media_path, is_read, delivered_at, reply_to_id, reply_preview_text) VALUES ($userId, $receiverId, '$messageTextEsc', $mediaTypeEsc, $mediaPathEsc, 0, NOW(), $replySql, $replyPreviewSql)");
} else {
    $result = mysqli_query($db, "INSERT INTO messages (sender_id, receiver_id, message, media_type, media_path, is_read, reply_to_id, reply_preview_text) VALUES ($userId, $receiverId, '$messageTextEsc', $mediaTypeEsc, $mediaPathEsc, 0, $replySql, $replyPreviewSql)");
}

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send message.']);
    exit;
}

$messageId = mysqli_insert_id($db);
$record = mysqli_fetch_assoc(mysqli_query($db, "SELECT m.*, UNIX_TIMESTAMP(m.created_at) AS created_epoch, u.full_name AS sender_name, ru.full_name AS reply_sender_name, rm.message AS reply_message, rm.is_deleted AS reply_is_deleted, m.reply_preview_text FROM messages m JOIN users u ON u.id=m.sender_id LEFT JOIN messages rm ON rm.id=m.reply_to_id LEFT JOIN users ru ON ru.id=rm.sender_id WHERE m.id=$messageId LIMIT 1"));

echo json_encode(['success'=>true,'message'=>$record], JSON_INVALID_UTF8_SUBSTITUTE);
