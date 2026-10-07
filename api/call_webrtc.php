<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = db_connect();
$userId = (int) $_SESSION['user_id'];
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_blocked_users (user_id INT NOT NULL,blocked_user_id INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,blocked_user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(blocked_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($db, "
    CREATE TABLE IF NOT EXISTS call_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        caller_id INT NOT NULL,
        receiver_id INT NOT NULL,
        call_type ENUM('audio','video') NOT NULL DEFAULT 'audio',
        status ENUM('pending','ringing','connected','ended','rejected','missed') NOT NULL DEFAULT 'pending',
        session_token VARCHAR(64) NOT NULL,
        offer TEXT NULL,
        answer TEXT NULL,
        candidates TEXT NULL,
        started_at DATETIME DEFAULT NULL,
        ended_at DATETIME DEFAULT NULL,
        duration_seconds INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_receiver (receiver_id, status),
        INDEX idx_caller (caller_id)
    )
");
foreach ([['started_at','DATETIME DEFAULT NULL'],['ended_at','DATETIME DEFAULT NULL'],['duration_seconds','INT NOT NULL DEFAULT 0']] as $cc) {
    if (!table_has_column($db, 'call_sessions', $cc[0])) mysqli_query($db, "ALTER TABLE call_sessions ADD COLUMN {$cc[0]} {$cc[1]}");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
    $receiverId = isset($_POST['receiver_id']) ? (int) $_POST['receiver_id'] : 0;
    $callType = isset($_POST['call_type']) && $_POST['call_type'] === 'video' ? 'video' : 'audio';
    $sessionToken = isset($_POST['session_token']) ? trim((string) $_POST['session_token']) : '';
    $offer = isset($_POST['offer']) ? (string) $_POST['offer'] : '';
    $answer = isset($_POST['answer']) ? (string) $_POST['answer'] : '';
    $candidate = isset($_POST['candidate']) ? (string) $_POST['candidate'] : '';

    if ($action !== 'create') {
        $tokenEsc = mysqli_real_escape_string($db, $sessionToken);
        $callSession = $sessionToken !== '' ? mysqli_fetch_assoc(mysqli_query($db, "SELECT caller_id,receiver_id FROM call_sessions WHERE session_token='$tokenEsc' AND (caller_id=$userId OR receiver_id=$userId) LIMIT 1")) : null;
        if (!$callSession) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Call session not found.']);
            exit;
        }
        if (($action === 'offer' && (int) $callSession['caller_id'] !== $userId) || ($action === 'answer' && (int) $callSession['receiver_id'] !== $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'This account cannot perform that call action.']);
            exit;
        }
        if ($action === 'candidate') {
            $candidatePacket = json_decode($candidate, true);
            if (isset($candidatePacket['from']) && (int) $candidatePacket['from'] !== $userId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Invalid ICE candidate sender.']);
                exit;
            }
        }
    }

    if ($action === 'create') {
        if ($receiverId <= 0 || $receiverId === $userId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A valid receiver is required.']);
            exit;
        }

        $blocked = mysqli_query($db, "SELECT user_id FROM talkora_blocked_users WHERE (user_id=$userId AND blocked_user_id=$receiverId) OR (user_id=$receiverId AND blocked_user_id=$userId) LIMIT 1");
        if ($blocked && mysqli_num_rows($blocked) > 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'This contact is blocked. Unblock them before calling.']);
            exit;
        }

        $contact = mysqli_query($db, "SELECT id FROM contacts WHERE (user_id=$userId AND contact_user_id=$receiverId) OR (user_id=$receiverId AND contact_user_id=$userId) LIMIT 1");
        if (!$contact || mysqli_num_rows($contact) === 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Calls are available to contacts only.']);
            exit;
        }

        $token = bin2hex(random_bytes(18));
        $typeEsc = mysqli_real_escape_string($db, $callType);
        $result = mysqli_query($db, "INSERT INTO call_sessions (caller_id, receiver_id, call_type, status, session_token, offer, answer, candidates) VALUES ($userId, $receiverId, '$typeEsc', 'pending', '$token', NULL, NULL, NULL)");

        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to create WebRTC call session.']);
            exit;
        }

        $sessionId = mysqli_insert_id($db);
        echo json_encode(['success' => true, 'session_id' => $sessionId, 'session_token' => $token, 'call_type' => $callType]);
        exit;
    }

    if ($action === 'offer' && $sessionToken !== '') {
        $offerEsc = mysqli_real_escape_string($db, $offer);
        $tokenEsc = mysqli_real_escape_string($db, $sessionToken);
        $updated = mysqli_query($db, "UPDATE call_sessions SET offer='$offerEsc',status='ringing',updated_at=NOW() WHERE session_token='$tokenEsc' LIMIT 1");
        echo json_encode(['success' => (bool) $updated]);
        exit;
    }

    if ($action === 'answer' && $sessionToken !== '') {
        $answerEsc = mysqli_real_escape_string($db, $answer);
        $tokenEsc = mysqli_real_escape_string($db, $sessionToken);
        $updated = mysqli_query($db, "UPDATE call_sessions SET answer='$answerEsc',status='connected',started_at=COALESCE(started_at,NOW()),updated_at=NOW() WHERE session_token='$tokenEsc' LIMIT 1");
        echo json_encode(['success' => (bool) $updated]);
        exit;
    }

    if ($action === 'candidate' && $sessionToken !== '') {
        $candidatePacket = json_decode($candidate, true);
        if (!is_array($candidatePacket) || empty($candidatePacket['candidate']) || !isset($candidatePacket['from']) || (int) $candidatePacket['from'] !== $userId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid ICE candidate packet.']);
            exit;
        }
        $packetJson = json_encode(['from' => $userId, 'candidate' => $candidatePacket['candidate']]);
        $tokenEsc = mysqli_real_escape_string($db, $sessionToken);
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT candidates FROM call_sessions WHERE session_token='$tokenEsc' LIMIT 1"));
        $existing = $row && !empty($row['candidates']) ? (string) $row['candidates'] : '';
        $payload = $existing === '' ? $packetJson : $existing . "\n" . $packetJson;
        $payloadEsc = mysqli_real_escape_string($db, $payload);
        $updated = mysqli_query($db, "UPDATE call_sessions SET candidates='$payloadEsc', updated_at=NOW() WHERE session_token='$tokenEsc' LIMIT 1");
        echo json_encode(['success' => (bool) $updated]);
        exit;
    }

    if ($action === 'end') {
        $sessionToken = isset($_POST['session_token']) ? trim((string) $_POST['session_token']) : '';
        if ($sessionToken !== '') {
            $tokenEsc = mysqli_real_escape_string($db, $sessionToken);
            $session = mysqli_fetch_assoc(mysqli_query($db, "SELECT caller_id,receiver_id,call_type,started_at FROM call_sessions WHERE session_token='$tokenEsc' AND (caller_id=$userId OR receiver_id=$userId) AND ended_at IS NULL LIMIT 1"));
            $updated = mysqli_query($db, "UPDATE call_sessions SET status=IF(started_at IS NULL,'missed','ended'),ended_at=NOW(),duration_seconds=CASE WHEN started_at IS NULL THEN 0 ELSE TIMESTAMPDIFF(SECOND,started_at,NOW()) END,updated_at=NOW() WHERE session_token='$tokenEsc' AND ended_at IS NULL LIMIT 1");
            if ($session && $updated && mysqli_affected_rows($db) > 0) {
                if (!table_has_column($db, 'messages', 'media_type')) mysqli_query($db, "ALTER TABLE messages ADD COLUMN media_type VARCHAR(32) NULL DEFAULT NULL");
                if (!table_has_column($db, 'messages', 'delivered_at')) mysqli_query($db, "ALTER TABLE messages ADD COLUMN delivered_at DATETIME DEFAULT NULL");
                $callerId = (int) $session['caller_id'];
                $receiverId = (int) $session['receiver_id'];
                $callType = $session['call_type'] === 'video' ? 'video' : 'audio';
                $wasAnswered = !empty($session['started_at']);
                $duration = $wasAnswered ? (int) (mysqli_fetch_assoc(mysqli_query($db, "SELECT duration_seconds FROM call_sessions WHERE session_token='$tokenEsc' LIMIT 1"))['duration_seconds'] ?? 0) : 0;
                $durationLabel = sprintf('%02d:%02d', intdiv($duration, 60), $duration % 60);
                $callLabel = $wasAnswered
                    ? ucfirst($callType) . ' call · ' . $durationLabel
                    : 'Missed ' . $callType . ' call';
                $callLabelEsc = mysqli_real_escape_string($db, $callLabel);
                mysqli_query($db, "INSERT INTO messages (sender_id,receiver_id,message,media_type,is_read,delivered_at) VALUES ($callerId,$receiverId,'$callLabelEsc','call',0,NOW())");
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported signaling action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['ice_config'])) {
        $iceServers = [['urls' => ['stun:stun.l.google.com:19302']]];
        $turnUrl = trim((string) getenv('TALKORA_TURN_URL'));
        $turnSecret = (string) getenv('TALKORA_TURN_SECRET');
        if ($turnUrl !== '' && $turnSecret !== '') {
            $expires = time() + 3600;
            $turnUsername = $expires . ':' . $userId;
            $iceServers[] = [
                'urls' => preg_split('/\s*,\s*/', $turnUrl),
                'username' => $turnUsername,
                'credential' => base64_encode(hash_hmac('sha1', $turnUsername, $turnSecret, true)),
            ];
        }
        echo json_encode(['success' => true, 'iceServers' => $iceServers]);
        exit;
    }

    $sessionToken = isset($_GET['session_token']) ? trim((string) $_GET['session_token']) : '';
    $incomingFor = isset($_GET['incoming_for']) ? (int) $_GET['incoming_for'] : 0;

    if ($sessionToken !== '') {
        $tokenEsc = mysqli_real_escape_string($db, $sessionToken);
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM call_sessions WHERE session_token = '$tokenEsc' AND (caller_id=$userId OR receiver_id=$userId) LIMIT 1"));
        echo json_encode(['success' => true, 'session' => $row ?: null]);
        exit;
    }

    if ($incomingFor > 0) {
        $result = mysqli_query($db, "SELECT cs.*, u.full_name AS caller_name FROM call_sessions cs JOIN users u ON u.id=cs.caller_id WHERE cs.receiver_id = $userId AND cs.status='ringing' ORDER BY cs.created_at DESC LIMIT 10");
        $sessions = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $sessions[] = $row;
        }
        echo json_encode(['success' => true, 'sessions' => $sessions]);
        exit;
    }

    echo json_encode(['success' => true, 'calls' => []]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
