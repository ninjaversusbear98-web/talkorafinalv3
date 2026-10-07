<?php
require_once __DIR__ . '/config.php';
ensure_logged_in();

$user = current_user();
$user['is_online'] = (int) ($user['show_active_status'] ?? 1) === 1 && (int) ($user['is_online'] ?? 0) === 1 && !empty($user['last_seen']) && (time() - strtotime($user['last_seen'])) <= 120 ? 1 : 0;
$theme = normalize_theme($user['theme']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | TalkOra</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="<?php echo $theme === 'light' ? 'light-theme' : ''; ?>" data-current-user-id="<?php echo (int) $user['id']; ?>" data-notifications-enabled="<?php echo (int) $user['notifications']; ?>" style="--user-font-size:<?php echo max(12, min(22, (int) $user['font_size'])); ?>px;--user-font-family:<?php echo htmlspecialchars(json_encode($user['font_family'] ?: 'Segoe UI'), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="app-shell">
        <div class="settings-card p-4 p-lg-5" style="width:min(760px,100%);">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <div class="text-uppercase small text-success fw-bold">Account</div>
                    <h1 class="h3 mb-0">Your profile</h1>
                </div>
                <div class="d-flex gap-2">
                    <a href="dashboard.php" class="btn btn-outline-success">Back to chat</a>
                    <a href="settings.php" class="btn btn-outline-primary">Settings</a>
                </div>
            </div>

            <div class="user-profile-box mb-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <?php
                        $avatarPath = !empty($user['avatar']) && file_exists(__DIR__ . '/' . $user['avatar']) ? $user['avatar'] : '';
                        if ($avatarPath):
                    ?>
                        <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Profile picture" class="user-avatar-image profile-photo-view" style="width:58px;height:58px;">
                    <?php else: ?>
                        <div class="avatar" style="width:58px;height:58px;font-size:1.2rem;">
                            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h3 class="mb-1"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                        <div class="small text-secondary">User ID: <strong><?php echo htmlspecialchars($user['user_id']); ?></strong></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="small text-secondary">Email</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($user['email']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-secondary">Contact number</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($user['contact_number']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-secondary">Gender</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($user['gender']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-secondary">Date of birth</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars(date('d M Y', strtotime($user['dob']))); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-secondary">Status</div>
                        <div class="fw-semibold">
                            <span class="status-dot <?php echo (int) $user['is_online'] === 1 ? 'ripple' : 'offline'; ?>"></span>
                            <?php echo htmlspecialchars(status_label((int) $user['is_online'] === 1, $user['last_seen'], (int) ($user['show_active_status'] ?? 1) === 1)); ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-secondary">Verification</div>
                        <div class="fw-semibold"><?php echo (int) $user['email_verified'] === 1 ? 'Verified' : 'Pending'; ?></div>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <div class="small text-secondary">Share your unique ID to connect with friends:</div>
                <button type="button" class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($user['user_id']); ?>').then(() => alert('User ID copied.'));">
                    Copy ID
                </button>
            </div>
        </div>
    </div>
    <div id="imageLightbox" class="image-lightbox" aria-hidden="true"><button type="button" class="image-lightbox-close" aria-label="Close image">×</button><img id="imageLightboxImg" src="" alt="Expanded profile picture"></div>
    <script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
    <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(function(){}); }</script>
</body>
</html>
