<?php require_once __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TalkOra | Secure Messaging</title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#2563eb">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>
    <div class="app-shell">
        <div class="container py-5">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <img class="brand-badge ripple" src="assets/img/favicon.svg" alt="">
                        <div>
                            <div class="text-uppercase small text-success fw-bold">Browser & Mobile App</div>
                            <h2 class="mb-0">TalkOra</h2>
                        </div>
                    </div>

                    <h1 class="hero-title">Chat, call, and connect in a beautiful desktop-like experience.</h1>
                    <p class="lead text-secondary mt-4">
                        Messaging platform and a smooth desktop-style interface.
                    </p>

                    <div class="d-flex flex-wrap gap-3 mt-4">
                        <button type="button" id="installAppButton" class="btn btn-primary btn-lg">Install App</button>
                        <a href="login.php" class="btn btn-outline-light btn-lg">Login</a>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="auth-card p-4 p-lg-5">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="feature-card h-100">
                                    <div class="small text-success fw-bold mb-2">Private chat</div>
                                    <strong>Encrypted-style messaging</strong>
                                    <p class="mb-0 text-secondary">Conversation threads, contact list, and instant updates.</p>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="feature-card h-100">
                                    <div class="small text-info fw-bold mb-2">Audio & video calls</div>
                                    <strong>Call-ready interface</strong>
                                    <p class="mb-0 text-secondary">A polished call panel with sound and animations for a native app feel.</p>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="feature-card h-100">
                                    <div class="small text-warning fw-bold mb-2">Settings</div>
                                    <strong>Complete control center</strong>
                                    <p class="mb-0 text-secondary">Theme toggle, font family, font size, notifications, and profile management.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/js/pwa.js?v=<?php echo filemtime(__DIR__ . '/assets/js/pwa.js'); ?>"></script>
    <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(function(){}); }</script>
</body>
</html>
