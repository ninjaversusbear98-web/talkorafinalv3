<?php
require_once __DIR__ . '/config.php';

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($token === '') {
    set_flash('auth_error', 'Verification token is missing.');
    redirect('login.php');
}

$db = db_connect();
$token = mysqli_real_escape_string($db, $token);
$result = mysqli_query($db, "SELECT * FROM users WHERE email_verification_token = '$token' LIMIT 1");

if (!$result || mysqli_num_rows($result) === 0) {
    set_flash('auth_error', 'This verification token is invalid or expired.');
    redirect('login.php');
}

$user = mysqli_fetch_assoc($result);
mysqli_query($db, "UPDATE users SET email_verified = 1, email_verification_token = NULL WHERE id = " . (int) $user['id']);
unset($_SESSION['pending_verification_link'], $_SESSION['pending_verification_email']);

    set_flash('success', 'Email verified successfully. You can now log in and start using TalkOra.');
redirect('login.php');
