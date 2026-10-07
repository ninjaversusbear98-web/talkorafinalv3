<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) $_POST['full_name']);
    $dob = trim((string) $_POST['dob']);
    $gender = trim((string) $_POST['gender']);
    $contactNumber = trim((string) $_POST['contact_number']);
    $email = trim((string) $_POST['email']);
    $password = (string) $_POST['password'];
    $confirmPassword = (string) $_POST['confirm_password'];

    if ($fullName === '' || $dob === '' || $gender === '' || $contactNumber === '' || $email === '' || $password === '' || $confirmPassword === '') {
        set_flash('register_error', 'Please complete all fields before registering.');
        redirect('register.php');
    }

    if ($password !== $confirmPassword) {
        set_flash('register_error', 'Passwords do not match.');
        redirect('register.php');
    }

    $db = db_connect();
    $fullName = mysqli_real_escape_string($db, $fullName);
    $contactNumber = mysqli_real_escape_string($db, $contactNumber);
    $email = mysqli_real_escape_string($db, $email);
    $gender = in_array($gender, ['Male','Female','Other'], true) ? $gender : 'Other';

    $duplicate = mysqli_query($db, "SELECT id FROM users WHERE email = '$email' OR contact_number = '$contactNumber' LIMIT 1");
    if (mysqli_num_rows($duplicate) > 0) {
        set_flash('register_error', 'This email or contact number is already in use.');
        redirect('register.php');
    }

    $userId = generate_unique_user_id($db);
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $insert = mysqli_query($db, "INSERT INTO users (full_name, dob, gender, contact_number, email, password, user_id, email_verified, email_verification_token, theme, notifications, font_size, font_family)
        VALUES ('$fullName', '$dob', '$gender', '$contactNumber', '$email', '$hashedPassword', '$userId', 1, NULL, 'dark', 1, 14, 'Segoe UI')");

    if (!$insert) {
        set_flash('register_error', 'Registration failed. Please try again.');
        redirect('register.php');
    }
    $newUserId = (int) mysqli_insert_id($db);

    if (trim(TALKORA_OFFICIAL_USER_CODE) !== '') {
        $officialCode = mysqli_real_escape_string($db, trim(TALKORA_OFFICIAL_USER_CODE));
        $officialResult = mysqli_query($db, "SELECT id FROM users WHERE user_id='$officialCode' LIMIT 1");
        $official = $officialResult ? mysqli_fetch_assoc($officialResult) : null;
        if ($official && (int) $official['id'] !== $newUserId) {
            $officialId = (int) $official['id'];
            mysqli_query($db, "INSERT IGNORE INTO contacts (user_id,contact_user_id) VALUES ($newUserId,$officialId),($officialId,$newUserId)");
            $welcome = mysqli_real_escape_string($db, 'Welcome to TalkOra -Secure Messaging');
            if (table_has_column($db, 'messages', 'delivered_at')) {
                mysqli_query($db, "INSERT INTO messages (sender_id,receiver_id,message,delivered_at) VALUES ($officialId,$newUserId,'$welcome',NOW())");
            } else {
                mysqli_query($db, "INSERT INTO messages (sender_id,receiver_id,message) VALUES ($officialId,$newUserId,'$welcome')");
            }
        }
    }

    set_flash('success', 'Welcome to TalkOra -Secure Messaging. Registration successful! You can now log in.');
    redirect('login.php');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | TalkOra</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-shell">
        <div class="auth-card p-4 p-lg-5" style="width:min(780px,100%);">
            <div class="d-flex align-items-center gap-3 mb-4">
                <img class="brand-badge ripple" src="assets/img/favicon.svg" alt="">
                <div>
                    <div class="text-uppercase small text-success fw-bold">Create account</div>
                    <h1 class="h3 mb-0">Register on TalkOra</h1>
                </div>
            </div>

            <?php $error = get_flash('register_error'); if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Full name</label>
                    <input type="text" name="full_name" class="form-control" placeholder="John Doe" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date of birth</label>
                    <input type="date" name="dob" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select" required>
                        <option value="">Select</option>
                        <option>Male</option>
                        <option>Female</option>
                        <option>Other</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact number</label>
                    <input type="tel" name="contact_number" class="form-control" placeholder="+123456789" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="you@example.com" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Generated ID</label>
                    <input type="text" class="form-control" value="Will be generated automatically after registration" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Create a password" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm password</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Repeat the password" required>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary w-100">Register</button>
                </div>
                <div class="col-12 text-center small text-secondary">
                    Already have an account? <a href="login.php" class="text-success fw-bold">Login now</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
