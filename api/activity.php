<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'expired' => true]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}
$userId = (int) $_SESSION['user_id'];
$_SESSION['last_activity_at'] = time();
$db = db_connect();
if (!table_has_column($db, 'users', 'show_active_status')) {
    mysqli_query($db, "ALTER TABLE users ADD COLUMN show_active_status TINYINT(1) NOT NULL DEFAULT 1 AFTER is_online");
}
mysqli_query($db, "UPDATE users SET is_online=show_active_status,last_seen=NOW() WHERE id=$userId");
echo json_encode(['success' => true, 'server_time' => time()]);
