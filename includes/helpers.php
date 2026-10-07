<?php
function db_connect()
{
    static $connection = null;
    static $messageEncodingChecked = false;

    if ($connection === null) {
        $connection = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if (!$connection) {
            die('Database connection failed: ' . mysqli_connect_error());
        }

        mysqli_set_charset($connection, 'utf8mb4');
    }

    if (!$messageEncodingChecked) {
        $messageEncodingChecked = true;
        $encoding = mysqli_query($connection, "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages' AND COLUMN_NAME='message' LIMIT 1");
        $column = $encoding ? mysqli_fetch_assoc($encoding) : null;
        if ($column && !str_starts_with((string) ($column['COLLATION_NAME'] ?? ''), 'utf8mb4')) {
            mysqli_query($connection, 'ALTER TABLE messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }

    return $connection;
}

function table_has_column($db, $table, $column)
{
    $result = mysqli_query($db, "SHOW COLUMNS FROM `" . mysqli_real_escape_string($db, $table) . "` LIKE '" . mysqli_real_escape_string($db, $column) . "'");

    if (!$result) {
        return false;
    }

    return mysqli_num_rows($result) > 0;
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function set_flash($key, $message)
{
    $_SESSION['flash'][$key] = $message;
}

function get_flash($key)
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $message;
}

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

function current_user()
{
    if (!is_logged_in()) {
        return null;
    }

    $db = db_connect();
    if (!table_has_column($db, 'users', 'show_active_status')) {
        mysqli_query($db, "ALTER TABLE users ADD COLUMN show_active_status TINYINT(1) NOT NULL DEFAULT 1 AFTER is_online");
    }
    $userId = (int) $_SESSION['user_id'];
    $result = mysqli_query($db, "SELECT * FROM users WHERE id = $userId LIMIT 1");

    return mysqli_fetch_assoc($result);
}

function generate_unique_user_id($db)
{
    $prefix = 'MS';
    do {
        $suffix = strtoupper(substr(md5(uniqid((string) rand(), true)), 0, 8));
        $userId = $prefix . '-' . $suffix;
        $check = mysqli_query($db, "SELECT id FROM users WHERE user_id = '$userId' LIMIT 1");
    } while (mysqli_num_rows($check) > 0);

    return $userId;
}

function build_verification_link($token)
{
    return BASE_URL . '/verify.php?token=' . urlencode($token);
}

function smtp_command($socket, $command, $expectedStart = null)
{
    if ($socket === false) {
        return false;
    }

    $command = trim($command);
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }

    $response = '';
    $timeout = 30;
    $start = microtime(true);
    while (microtime(true) - $start < $timeout) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }

        $response .= $line;
        if (strlen($line) < 3 || $line[3] !== '-') {
            break;
        }
    }

    if ($expectedStart !== null) {
        $responseCode = substr($response, 0, 3);
        if (str_starts_with($responseCode, (string) $expectedStart) === false) {
            return false;
        }
    }

    return true;
}

function send_via_gmail_smtp($to, $subject, $message)
{
    if (!defined('SMTP_HOST') || !defined('SMTP_PORT') || !defined('SMTP_USERNAME') || !defined('SMTP_PASSWORD')) {
        return false;
    }

    $smtpHost = SMTP_HOST;
    $smtpPort = (int) SMTP_PORT;
    $smtpUser = SMTP_USERNAME;
    $smtpPass = SMTP_PASSWORD;

    if ($smtpUser === 'yourgmail@gmail.com' || $smtpPass === 'your-16-char-app-password' || $smtpUser === '' || $smtpPass === '') {
        return false;
    }

    $socket = fsockopen('tcp://' . $smtpHost, $smtpPort, $errno, $errstr, 30);
    if ($socket === false) {
        return false;
    }

    stream_set_timeout($socket, 30);
    $banner = fgets($socket, 515);
    if ($banner === false || substr($banner, 0, 3) !== '220') {
        fclose($socket);
        return false;
    }

    if (!smtp_command($socket, 'EHLO localhost', '250')) {
        fclose($socket);
        return false;
    }

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        return false;
    }

    if (!smtp_command($socket, 'EHLO localhost', '250')) {
        fclose($socket);
        return false;
    }

    if (!smtp_command($socket, 'AUTH LOGIN', '334')) {
        fclose($socket);
        return false;
    }

    if (!smtp_command($socket, base64_encode($smtpUser), '334')) {
        fclose($socket);
        return false;
    }

    if (!smtp_command($socket, base64_encode($smtpPass), '235')) {
        fclose($socket);
        return false;
    }

    $from = $smtpUser;
    if (!smtp_command($socket, 'MAIL FROM:<' . $from . '>', '250')) {
        fclose($socket);
        return false;
    }

    if (!smtp_command($socket, 'RCPT TO:<' . $to . '>', '250')) {
        fclose($socket);
        return false;
    }

    if (!smtp_command($socket, 'DATA', '354')) {
        fclose($socket);
        return false;
    }

    $emailHeaders = "From: " . SMTP_FROM_NAME . " <" . $from . ">\r\n";
    $emailHeaders .= "To: " . $to . "\r\n";
    $emailHeaders .= "Subject: " . $subject . "\r\n";
    $emailHeaders .= "MIME-Version: 1.0\r\n";
    $emailHeaders .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $dataPayload = $emailHeaders . $message . "\r\n.";

    fwrite($socket, $dataPayload . "\r\n");
    $dataResponse = fgets($socket, 515);
    if ($dataResponse === false || substr($dataResponse, 0, 3) !== '250') {
        fclose($socket);
        return false;
    }

    smtp_command($socket, 'QUIT', '221');
    fclose($socket);
    return true;
}

function send_verification_email($email, $token, $name)
{
    $subject = 'Verify your MsgSender account';
    $verificationLink = build_verification_link($token);
    $message = "Hello $name,\r\n\r\n";
    $message .= "Thank you for registering on MsgSender. Please verify your account using the link below:\r\n";
    $message .= $verificationLink . "\r\n\r\n";
    $message .= "Once verified, you can sign in and continue to your conversation dashboard.";

    $logPath = __DIR__ . '/../storage/email_log.txt';
    $logEntry = "To: $email\nSubject: $subject\nVerification Link: $verificationLink\nMessage:\n$message\n\n---\n";

    if (send_via_gmail_smtp($email, $subject, $message)) {
        $_SESSION['pending_verification_link'] = $verificationLink;
        $_SESSION['pending_verification_email'] = $email;
        return true;
    }

    $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_USERNAME . ">\r\n";
    $headers .= "Reply-To: " . SMTP_USERNAME . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    if (mail($email, $subject, $message, $headers)) {
        $_SESSION['pending_verification_link'] = $verificationLink;
        $_SESSION['pending_verification_email'] = $email;
        return true;
    }

    file_put_contents($logPath, $logEntry, FILE_APPEND);
    $_SESSION['pending_verification_link'] = $verificationLink;
    $_SESSION['pending_verification_email'] = $email;
    return false;
}

function status_label($isOnline, $lastSeen, $showActive = true)
{
    $freshHeartbeat = !empty($lastSeen) && (time() - strtotime($lastSeen)) <= 120;
    if ($showActive && $isOnline && $freshHeartbeat) {
        return 'Active now';
    }

    if (empty($lastSeen)) {
        return 'Offline';
    }

    $seenAt = new DateTimeImmutable($lastSeen);
    $today = new DateTimeImmutable('today');
    $seenDate = $seenAt->setTime(0, 0);
    $daysAgo = (int) $seenDate->diff($today)->format('%a');

    if ($daysAgo === 0) {
        return 'Last seen today at ' . $seenAt->format('g:i A');
    }
    if ($daysAgo === 1) {
        return 'Last seen yesterday';
    }
    if ($daysAgo < 7) {
        return 'Last seen ' . $daysAgo . ' days ago';
    }
    if ($daysAgo < 14) {
        return 'Last seen a week ago';
    }
    if ($daysAgo < 30) {
        return 'Last seen ' . (int) floor($daysAgo / 7) . ' weeks ago';
    }
    if ($daysAgo < 365) {
        return 'Last seen ' . (int) floor($daysAgo / 30) . ' months ago';
    }

    return 'Last seen ' . (int) floor($daysAgo / 365) . ' years ago';
}

function normalize_theme($theme)
{
    $theme = strtolower(trim((string) $theme));
    return in_array($theme, ['light', 'dark', 'midnight'], true) ? $theme : 'dark';
}

function ensure_logged_in()
{
    if (!is_logged_in()) {
        set_flash('auth_error', 'Please log in to continue.');
        redirect('login.php');
    }
}
