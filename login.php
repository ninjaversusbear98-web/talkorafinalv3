<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) $_POST['email']);
    $password = (string) $_POST['password'];

    if ($email === '' || $password === '') {
        set_flash('auth_error', 'Please enter both email and password.');
        redirect('login.php');
    }

    $db = db_connect();
    if (!table_has_column($db, 'users', 'last_login_at')) {
        mysqli_query($db, "ALTER TABLE users ADD COLUMN last_login_at DATETIME DEFAULT NULL");
    }
    $email = mysqli_real_escape_string($db, $email);
    $result = mysqli_query($db, "SELECT * FROM users WHERE email = '$email' LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        set_flash('auth_error', 'No account found with that email.');
        redirect('login.php');
    }

    $user = mysqli_fetch_assoc($result);

    if (!password_verify($password, $user['password'])) {
        set_flash('auth_error', 'Invalid password.');
        redirect('login.php');
    }

    $firstLogin = empty($user['last_login_at']);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['theme'] = normalize_theme($user['theme']);
    $_SESSION['last_activity_at'] = time();
    unset($_SESSION['pending_verification_link'], $_SESSION['pending_verification_email']);
    $lastSeenSql = "UPDATE users SET is_online = 1, last_seen = NOW(), last_login_at = COALESCE(last_login_at, NOW()) WHERE id = " . (int) $user['id'];
    mysqli_query($db, $lastSeenSql);

    set_flash('dashboard_success', $firstLogin ? 'Welcome to TalkOra -Secure Messaging' : 'Welcome back!');
    redirect('dashboard.php');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | TalkOra</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#2563eb">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="auth-page">
    <div class="app-shell">
        <div class="auth-card p-4 p-lg-5" style="width:min(500px,100%);">
            <div class="d-flex align-items-center gap-3 mb-4">
                <img class="brand-badge ripple" src="assets/img/favicon.svg" alt="">
                <div>
                    <div class="text-uppercase small text-success fw-bold">Welcome back</div>
                    <h1 class="h3 mb-0">Login to TalkOra</h1>
                </div>
            </div>

            <?php $success = get_flash('success'); if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php $error = get_flash('auth_error'); if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" class="row g-3">
                <div class="col-12">
                    <label class="form-label">Email address</label>
                    <input type="email" name="email" class="form-control" placeholder="you@example.com" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary w-100">Login</button>
                </div>
                <div class="col-12 text-center small text-secondary">
                    Don’t have an account? <a href="register.php" class="text-success fw-bold">Register here</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
