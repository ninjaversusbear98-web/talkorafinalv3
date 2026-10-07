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
$messageId = (int) ($_POST['message_id'] ?? 0);
$receiverId = (int) ($_POST['receiver_id'] ?? 0);
if ($messageId <= 0 || $receiverId <= 0 || $receiverId === $userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a valid message and contact.']);
    exit;
}

mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_blocked_users (user_id INT NOT NULL,blocked_user_id INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,blocked_user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(blocked_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$blocked = mysqli_query($db, "SELECT user_id FROM talkora_blocked_users WHERE (user_id=$userId AND blocked_user_id=$receiverId) OR (user_id=$receiverId AND blocked_user_id=$userId) LIMIT 1");
if ($blocked && mysqli_num_rows($blocked) > 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This contact is blocked. Unblock them before forwarding.']);
    exit;
}

foreach ([
    ['is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0'],
    ['media_type', 'VARCHAR(32) NULL DEFAULT NULL'],
    ['media_path', 'VARCHAR(255) NULL DEFAULT NULL'],
] as [$column, $definition]) {
    if (!table_has_column($db, 'messages', $column)) {
        mysqli_query($db, "ALTER TABLE messages ADD COLUMN $column $definition");
    }
}

$messageResult = mysqli_query($db, "SELECT * FROM messages WHERE id=$messageId AND (sender_id=$userId OR receiver_id=$userId) LIMIT 1");
$message = $messageResult ? mysqli_fetch_assoc($messageResult) : null;
if (!$message || (int) ($message['is_deleted'] ?? 0) === 1) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'This message is no longer available.']);
    exit;
}

$contactResult = mysqli_query($db, "SELECT id FROM contacts WHERE (user_id=$userId AND contact_user_id=$receiverId) OR (user_id=$receiverId AND contact_user_id=$userId) LIMIT 1");
if (!$contactResult || mysqli_num_rows($contactResult) === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Messages can only be forwarded to your contacts.']);
    exit;
}

$columns = ['sender_id', 'receiver_id', 'message'];
$values = [(string) $userId, (string) $receiverId, "'" . mysqli_real_escape_string($db, (string) $message['message']) . "'"];
foreach (['media_type', 'media_path'] as $column) {
    if (table_has_column($db, 'messages', $column)) {
        $columns[] = $column;
        $value = (string) ($message[$column] ?? '');
        $values[] = $value === '' ? 'NULL' : "'" . mysqli_real_escape_string($db, $value) . "'";
    }
}
if (table_has_column($db, 'messages', 'is_read')) {
    $columns[] = 'is_read';
    $values[] = '0';
}
if (table_has_column($db, 'messages', 'delivered_at')) {
    $columns[] = 'delivered_at';
    $values[] = 'NOW()';
}

$columnSql = implode(',', array_map(static fn($column) => "`$column`", $columns));
$valueSql = implode(',', $values);
if (!mysqli_query($db, "INSERT INTO messages ($columnSql) VALUES ($valueSql)")) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The message could not be forwarded.']);
    exit;
}

echo json_encode(['success' => true]);