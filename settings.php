<?php
require_once __DIR__ . '/config.php';
ensure_logged_in();

$db = db_connect();
if (!table_has_column($db, 'users', 'show_active_status')) {
    mysqli_query($db, "ALTER TABLE users ADD COLUMN show_active_status TINYINT(1) NOT NULL DEFAULT 1 AFTER is_online");
}
$user = current_user();
$fontOptions = [
    'Segoe UI', 'Arial', 'Verdana', 'Tahoma', 'Georgia', 'Times New Roman',
    'Palatino Linotype', 'Book Antiqua', 'Garamond', 'Trebuchet MS', 'Impact',
    'Comic Sans MS', 'Lucida Console', 'Courier New', 'Consolas', 'Candara',
    'Calibri', 'Cambria', 'Franklin Gothic Medium', 'Segoe Print', 'MV Boli',
    'Brush Script MT', 'Lucida Handwriting', 'Rockwell'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $theme = normalize_theme($_POST['theme'] ?? 'dark');
    $notifications = isset($_POST['notifications']) ? 1 : 0;
    $showActiveStatus = isset($_POST['show_active_status']) ? 1 : 0;
    $fontSize = (int) ($_POST['font_size'] ?? 14);
    $allowedFonts = $fontOptions;
    $fontFamily = (string) ($_POST['font_family'] ?? 'Segoe UI');
    $fontFamily = in_array($fontFamily, $allowedFonts, true) ? $fontFamily : 'Segoe UI';
    $fontSize = max(12, min(22, $fontSize));

    $avatarUpdate = '';
    $avatarPostPath = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_image'];
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMime, true)) {
            set_flash('settings_error', 'Only JPG, PNG, WEBP, and GIF images are allowed.');
            redirect('settings.php');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $ext = $ext === '' ? 'jpg' : $ext;
        $safeFileName = 'user-' . (int) $user['id'] . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $uploadDir = __DIR__ . '/uploads/avatars';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $targetPath = $uploadDir . '/' . $safeFileName;
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $relativePath = 'uploads/avatars/' . $safeFileName;
            $avatarUpdate = ", avatar = '" . mysqli_real_escape_string($db, $relativePath) . "'";
            $avatarPostPath = $relativePath;
        }
    }

    $fontFamilySql = mysqli_real_escape_string($db, $fontFamily);
    $sql = "UPDATE users SET theme = '$theme', notifications = $notifications, show_active_status = $showActiveStatus, is_online = $showActiveStatus, font_size = $fontSize, font_family = '$fontFamilySql'" . $avatarUpdate . " WHERE id = " . (int) $user['id'];
    if (!mysqli_query($db, $sql)) {
        set_flash('settings_error', 'Settings could not be saved. Please try again.');
        redirect('settings.php');
    }
    if ($avatarPostPath !== null) {
        mysqli_query($db, "CREATE TABLE IF NOT EXISTS social_posts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, caption TEXT NOT NULL, media_type ENUM('image','video') DEFAULT NULL, media_path VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_social_posts (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $postPath = mysqli_real_escape_string($db, $avatarPostPath);
        mysqli_query($db, "INSERT INTO social_posts (user_id, caption, media_type, media_path) VALUES (" . (int) $user['id'] . ", 'Updated their profile photo.', 'image', '$postPath')");
    }
    $_SESSION['theme'] = $theme;
    set_flash('settings_success', 'Settings updated successfully.');
    redirect('settings.php');
}

$theme = normalize_theme($user['theme']);
$notifications = (int) $user['notifications'];
$fontSize = max(12, (int) $user['font_size']);
$fontFamily = trim((string) $user['font_family']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | TalkOra</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="<?php echo $theme === 'light' ? 'light-theme' : ''; ?>" data-current-user-id="<?php echo (int) $user['id']; ?>" data-notifications-enabled="<?php echo $notifications; ?>" style="--user-font-size:<?php echo $fontSize; ?>px;--user-font-family:<?php echo htmlspecialchars(json_encode($fontFamily), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="app-shell">
        <div class="settings-card p-4 p-lg-5" style="width:min(820px,100%);">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <div class="text-uppercase small text-success fw-bold">Preferences</div>
                    <h1 class="h3 mb-0">App settings</h1>
                </div>
                <div class="d-flex gap-2 flex-wrap"><a href="blocked.php" class="btn btn-outline-light">Blocked contacts</a><a href="dashboard.php" class="btn btn-outline-success">Back to chat</a></div>
            </div>

            <?php $message = get_flash('settings_success'); if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php $error = get_flash('settings_error'); if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="row g-4">
                <div class="col-12">
                    <div class="user-profile-box">
                        <label class="form-label fw-bold">Profile picture</label>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <?php
                                $avatarPath = !empty($user['avatar']) && file_exists(__DIR__ . '/' . $user['avatar']) ? $user['avatar'] : '';
                                if ($avatarPath):
                            ?>
                                <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Profile picture" class="user-avatar-image profile-photo-view" style="width:72px;height:72px;">
                            <?php else: ?>
                                <div class="avatar" style="width:72px;height:72px;font-size:1.2rem;">
                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="profile_image" accept="image/*" class="form-control" style="max-width: 280px;">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="user-profile-box">
                        <label class="form-label fw-bold">Theme</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="themeToggle" name="theme" value="light" <?php echo $theme === 'light' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="themeToggle">Dark / Light mode</label>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="user-profile-box">
                        <label class="form-label fw-bold">Notifications</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="notificationsToggle" name="notifications" value="1" <?php echo $notifications ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="notificationsToggle">Enable message and call alerts</label>
                            <div class="small text-secondary mt-2" id="notificationStatus" role="status" aria-live="polite">Checking this browser’s notification support…</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="user-profile-box">
                        <label class="form-label fw-bold" for="activeStatusSetting">Activity status</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="activeStatusSetting" name="show_active_status" value="1" <?php echo (int) ($user['show_active_status'] ?? 1) === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="activeStatusSetting">Show when I’m active</label>
                            <div class="small text-secondary mt-2">When off, contacts see your last-seen time instead.</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="user-profile-box">
                        <label class="form-label fw-bold">Font size</label>
                        <input type="range" class="form-range" name="font_size" min="12" max="22" value="<?php echo $fontSize; ?>" id="fontSizeSetting">
                        <div class="small text-secondary">Current: <span id="fontSizeValue"><?php echo $fontSize; ?></span>px</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="user-profile-box">
                        <label class="form-label fw-bold">Font family</label>
                        <select name="font_family" class="form-select">
                            <?php foreach ($fontOptions as $fontOption): ?>
                                <option value="<?php echo htmlspecialchars($fontOption, ENT_QUOTES, 'UTF-8'); ?>" style="font-family:<?php echo htmlspecialchars($fontOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $fontFamily === $fontOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($fontOption, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="user-profile-box">
                        <label class="form-label fw-bold" for="compactChatSetting">Chat density</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="compactChatSetting">
                            <label class="form-check-label" for="compactChatSetting">Compact message spacing</label>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="user-profile-box">
                        <label class="form-label fw-bold" for="enterToSendSetting">Message shortcut</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enterToSendSetting" checked>
                            <label class="form-check-label" for="enterToSendSetting">Enter sends; Shift+Enter adds a line</label>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="user-profile-box d-flex align-items-center justify-content-between gap-3 flex-wrap">
                        <div><div class="fw-bold">Notification sound</div><div class="small text-secondary">Play a short preview on this device.</div></div>
                        <button type="button" class="btn btn-outline-success" id="soundTest">Test sound</button>
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Save settings</button>
                </div>
            </form>
        </div>
    </div>
    <div id="imageLightbox" class="image-lightbox" aria-hidden="true"><button type="button" class="image-lightbox-close" aria-label="Close image">×</button><img id="imageLightboxImg" src="" alt="Expanded profile picture"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
        <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(function(){}); }</script>
        <script>
            const fontSlider = document.getElementById('fontSizeSetting');
            fontSlider?.addEventListener('input', () => {
                document.getElementById('fontSizeValue').textContent = fontSlider.value;
                document.body.style.setProperty('--user-font-size', fontSlider.value + 'px');
            });
        </script>
</body>
</html>
