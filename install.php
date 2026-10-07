<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $connection = mysqli_connect(DB_HOST, DB_USER, DB_PASS);

    if (!$connection) {
        set_flash('install_error', 'Database connection failed: ' . mysqli_connect_error());
        redirect('install.php');
    }

    mysqli_query($connection, 'CREATE DATABASE IF NOT EXISTS ' . DB_NAME);
    mysqli_select_db($connection, DB_NAME);

    $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
    if ($sql === false) {
        set_flash('install_error', 'Schema file not found.');
        redirect('install.php');
    }

    if (!mysqli_multi_query($connection, $sql)) {
        set_flash('install_error', 'Schema execution failed: ' . mysqli_error($connection));
        redirect('install.php');
    }

    do {
        if ($result = mysqli_store_result($connection)) {
            mysqli_free_result($result);
        }
        if (mysqli_more_results($connection) && !mysqli_next_result($connection)) {
            set_flash('install_error', 'Schema execution failed: ' . mysqli_error($connection));
            mysqli_close($connection);
            redirect('install.php');
        }
    } while (mysqli_more_results($connection));

    $message = 'Installation successful! You can now register a new account.';
    set_flash('success', $message);
    redirect('login.php');
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TalkOra Installer</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-shell">
        <div class="auth-card p-4 p-lg-5" style="width:min(620px,100%);">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="brand-badge ripple">M</div>
                <div>
                    <div class="text-uppercase small text-success fw-bold">Setup</div>
                    <h1 class="h3 mb-0">Install TalkOra</h1>
                </div>
            </div>

            <?php $error = get_flash('install_error'); if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="alert alert-info">
                This installer creates the MySQL database, tables, and default seed data required for the app.
            </div>

            <ul class="list-group list-group-flush mb-4">
                <li class="list-group-item bg-transparent border-0 px-0">• Creates database: <strong><?php echo htmlspecialchars(DB_NAME); ?></strong></li>
                <li class="list-group-item bg-transparent border-0 px-0">• Creates users, contacts, messages, and calls tables</li>
                <li class="list-group-item bg-transparent border-0 px-0">• Prepares the app for registration and login</li>
            </ul>

            <form method="post">
                <button type="submit" class="btn btn-primary w-100">Install software</button>
            </form>
        </div>
    </div>
</body>
</html>
