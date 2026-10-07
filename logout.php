<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    $db = db_connect();
    $userId = (int) $_SESSION['user_id'];
    mysqli_query($db, "UPDATE users SET is_online = 0, last_seen = NOW() WHERE id = $userId");
}

session_unset();
session_destroy();
redirect('login.php');
