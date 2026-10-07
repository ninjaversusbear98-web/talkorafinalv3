# TalkOra

TalkOra is a PHP + MySQL social messaging app with contact chat, calls, a feed, groups, communities, relative presence, and installable PWA support.

## Tech stack

- PHP (without OOP)
- MySQL
- HTML5
- CSS3
- Bootstrap 5
- JavaScript

## Installation

1. Start Apache and MySQL in XAMPP.
2. Open http://localhost/msgsender/install.php
3. The installer will create the database and required tables.
4. Register a new account from http://localhost/msgsender/register.php
5. Verify the email link and then log in at http://localhost/msgsender/login.php

## Notes

- The email verification flow now supports real Gmail SMTP using an app password. Fill in the values in config.php before testing.
- If SMTP is not configured, the app falls back to writing the verification link into storage/email_log.txt so the link can still be used in local testing.
- The application is a front-end prototype for the desktop concept. A real .exe Windows package can be created later with PHP Desktop, WinBinder, or Inno Setup packaging around this project.
- A modern design, sounds, and animations are handled by CSS + JavaScript to make the interface feel smooth and app-like.

## Gmail SMTP setup

1. Open config.php.
2. Replace the placeholder values in SMTP_USERNAME and SMTP_PASSWORD with your Gmail address and a Gmail App Password.
3. Make sure 2-Step Verification is enabled on the Gmail account.
4. Generate an App Password in Google Account > Security > App passwords.
5. Save config.php and register again.

Example:

- SMTP_USERNAME = 'yourname@gmail.com'
- SMTP_PASSWORD = 'abcd efgh ijkl mnop'

Google App Passwords are 16 characters with no spaces when entered in the code. If you paste with spaces, remove them before saving.

## Main pages

- install.php
- register.php
- login.php
- verify.php
- dashboard.php
- settings.php
- logout.php

## Database name

- msgsender_db

## Responsive + WhatsApp-style update

This build includes the responsive mobile/tablet layout plus: theme contrast fixes, system browser notifications, camera capture with front/rear camera switching, gallery images, voice messages, image deletion, message replies, 15-minute edit/delete, blue seen ticks, multiline composer, smoother chat refresh, and WebRTC audio/video calling with ringing, answer/decline controls, and duration tracking.

### Hosting requirements

- Signed-in users are logged out after 15 minutes without activity. Presence uses an active-tab heartbeat and falls back to last-seen after two minutes without one.
- Use **HTTPS** for camera, microphone, WebRTC and browser notification permissions.
- Allow microphone/camera permissions in the browser.
- Browser system notifications require the user to grant notification permission in Settings.
- Browser notifications and incoming-call polling work while TalkOra is open in an active or background browser tab and notification permission is granted. Notifications cannot wake a fully closed browser without a Web Push provider/VAPID subscription backend, which this package does not currently include.
- WebRTC uses public STUN by default. For restrictive NATs/firewalls, configure a coturn-compatible TURN REST service with `TALKORA_TURN_URL` (comma-separated `turn:`/`turns:` URLs) and `TALKORA_TURN_SECRET` in the PHP server environment. The API issues short-lived TURN credentials; calls also require HTTPS and microphone/camera permissions.
- Real-time calls require both users to have TalkOra open and reachable for call signaling. A browser that is fully closed or suspended cannot receive an incoming call without a separately configured Web Push/call-wakeup service. Reliable calls across restrictive networks also require a reachable TURN relay; STUN-only operation is not guaranteed.
- `config.php` now builds `BASE_URL` from the current host/path, so verification links work when deployed under a normal hosting folder.

### Groups and community

- `groups.php` lets users create contact-based groups, exchange group messages, and open shared audio/video rooms.
- Group audio/video rooms use Jitsi Meet's hosted service; participants need an internet connection and microphone/camera permission. Group room links are unique to each group, but calls are hosted outside this application.
- `community.php` contains the contact-visible post feed and a public video-only Reels view. Posts support text, images, and video, with likes and comments including selectable GIFs.

### GIF search

- Comment GIFs are selected from the in-app GIPHY tray, not uploaded from a device. Set `TALKORA_GIPHY_API_KEY` in the PHP environment for GIPHY search and trending results. Without a key, the tray includes one curated GIF.
- The home-page Install button opens the browser's native PWA install prompt where supported. The site must be served over HTTPS (localhost is allowed for development); on iOS Safari use Share > Add to Home Screen.

### Official welcome message

- Set `TALKORA_OFFICIAL_USER_CODE` in `config.php` to the official account's generated `user_id` (for example, its `MS-...` code). New registrations are automatically connected to that account and receive `Welcome to TalkOra-Secure Messaging` from it. Leave the value blank to disable the automatic welcome.
- The installer creates the group and social tables on fresh installs. Existing installs create them the first time the corresponding page is opened.

### Database migration

The PHP APIs automatically add new columns to an existing `messages`/`call_sessions` table when needed. For a fresh installation, `sql/schema.sql` already contains the new fields.
