<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$db = db_connect();
$userId = (int) $_SESSION['user_id'];
$input = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST;
$contactId = (int) ($input['contact_id'] ?? 0);
$action = trim((string) ($input['action'] ?? ''));
if ($contactId <= 0 || $contactId === $userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid contact is required.']);
    exit;
}

mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_chat_preferences (
    user_id INT NOT NULL,
    contact_user_id INT NOT NULL,
    nickname TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,contact_user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$preferenceCollation = mysqli_fetch_assoc(mysqli_query($db, "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='talkora_chat_preferences' LIMIT 1"));
if ($preferenceCollation && !str_starts_with((string) $preferenceCollation['TABLE_COLLATION'], 'utf8mb4')) {
    mysqli_query($db, "ALTER TABLE talkora_chat_preferences CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
$nicknameColumn = mysqli_fetch_assoc(mysqli_query($db, "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='talkora_chat_preferences' AND COLUMN_NAME='nickname' LIMIT 1"));
if ($nicknameColumn && strtolower((string) $nicknameColumn['DATA_TYPE']) !== 'text') {
    mysqli_query($db, "ALTER TABLE talkora_chat_preferences MODIFY nickname TEXT NOT NULL");
}
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_blocked_users (
    user_id INT NOT NULL,
    blocked_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,blocked_user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$contact = mysqli_query($db, "SELECT id FROM contacts WHERE (user_id=$userId AND contact_user_id=$contactId) OR (user_id=$contactId AND contact_user_id=$userId) LIMIT 1");
if (!$contact || mysqli_num_rows($contact) === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Chat settings are only available for contacts.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'nickname') {
    $statement = mysqli_prepare($db, 'SELECT nickname FROM talkora_chat_preferences WHERE user_id=? AND contact_user_id=? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'ii', $userId, $contactId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_bind_result($statement, $storedNickname);
    $found = mysqli_stmt_fetch($statement);
    echo json_encode(['success' => true, 'nickname' => $found ? (string) $storedNickname : ''], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if ($action === 'nickname') {
    $nickname = (string) ($_POST['nickname'] ?? '');
    if ($nickname === '') {
        mysqli_query($db, "DELETE FROM talkora_chat_preferences WHERE user_id=$userId AND contact_user_id=$contactId");
        echo json_encode(['success' => true, 'nickname' => '']);
        exit;
    }
    $statement = mysqli_prepare($db, 'INSERT INTO talkora_chat_preferences (user_id,contact_user_id,nickname) VALUES (?,?,?) ON DUPLICATE KEY UPDATE nickname=VALUES(nickname)');
    if (!$statement) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not prepare the nickname save.']);
        exit;
    }
    mysqli_stmt_bind_param($statement, 'iis', $userId, $contactId, $nickname);
    $saved = mysqli_stmt_execute($statement);
    if (!$saved) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Nickname could not be saved: ' . mysqli_stmt_error($statement)]);
        exit;
    }
    $readBack = mysqli_prepare($db, 'SELECT nickname FROM talkora_chat_preferences WHERE user_id=? AND contact_user_id=? LIMIT 1');
    mysqli_stmt_bind_param($readBack, 'ii', $userId, $contactId);
    mysqli_stmt_execute($readBack);
    mysqli_stmt_bind_result($readBack, $storedNickname);
    $readBackFound = mysqli_stmt_fetch($readBack);
    if (!$readBackFound || (string) $storedNickname !== $nickname) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The nickname did not persist. Check that the app database is writable.']);
        exit;
    }
    echo json_encode(['success' => true, 'nickname' => (string) $storedNickname], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($action === 'block') {
    mysqli_query($db, "INSERT IGNORE INTO talkora_blocked_users (user_id,blocked_user_id) VALUES ($userId,$contactId)");
    echo json_encode(['success' => true, 'blocked' => true]);
    exit;
}
if ($action === 'unblock') {
    mysqli_query($db, "DELETE FROM talkora_blocked_users WHERE user_id=$userId AND blocked_user_id=$contactId");
    echo json_encode(['success' => true, 'blocked' => false]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unsupported chat setting.']);