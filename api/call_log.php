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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';

    if ($action === 'delete') {
        $callId = isset($_POST['call_id']) ? (int) $_POST['call_id'] : 0;
        if ($callId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Call ID is required.']);
            exit;
        }

        $callIdEsc = (int) $callId;
        mysqli_query($db, "DELETE FROM calls WHERE id = $callIdEsc AND (caller_id = $userId OR receiver_id = $userId)");
        echo json_encode(['success' => true, 'deleted' => true]);
        exit;
    }

    if ($action === 'end') {
        $receiverId = isset($_POST['receiver_id']) ? (int) $_POST['receiver_id'] : 0;
        $duration = max(0, (int)($_POST['duration_seconds'] ?? 0));
        if (!table_has_column($db, 'calls', 'duration_seconds')) mysqli_query($db, "ALTER TABLE calls ADD COLUMN duration_seconds INT NOT NULL DEFAULT 0");
        if ($receiverId > 0) {
            mysqli_query($db, "UPDATE calls SET status='answered', duration_seconds=$duration WHERE ((caller_id=$userId AND receiver_id=$receiverId) OR (caller_id=$receiverId AND receiver_id=$userId)) ORDER BY id DESC LIMIT 1");
        }
        echo json_encode(['success'=>true,'duration_seconds'=>$duration]); exit;
    }

    if ($action === 'clear') {
        mysqli_query($db, "DELETE FROM calls WHERE caller_id = $userId OR receiver_id = $userId");
        echo json_encode(['success' => true, 'cleared' => true]);
        exit;
    }

    $receiverId = isset($_POST['receiver_id']) ? (int) $_POST['receiver_id'] : 0;
    $callType = isset($_POST['call_type']) && $_POST['call_type'] === 'video' ? 'video' : 'audio';

    if ($receiverId <= 0 || $receiverId === $userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid receiver is required.']);
        exit;
    }

    $statusMap = [
        'initiate' => 'initiated',
        'answer' => 'answered',
        'missed' => 'missed',
        'decline' => 'rejected',
    ];

    $status = $statusMap[$action] ?? 'initiated';

    $callTypeEsc = mysqli_real_escape_string($db, $callType);
    $statusEsc = mysqli_real_escape_string($db, $status);

    mysqli_query($db, "INSERT INTO calls (caller_id, receiver_id, call_type, status) VALUES ($userId, $receiverId, '$callTypeEsc', '$statusEsc')");

    echo json_encode([
        'success' => true,
        'status' => $status,
        'call_type' => $callType,
    ]);
    exit;
}

$query = mysqli_query($db, "
    SELECT c.*, 
           u.full_name AS contact_name,
           CASE WHEN c.caller_id = $userId THEN c.receiver_id ELSE c.caller_id END AS other_user_id
    FROM calls c
    JOIN users u ON u.id = CASE WHEN c.caller_id = $userId THEN c.receiver_id ELSE c.caller_id END
    WHERE c.caller_id = $userId OR c.receiver_id = $userId
    ORDER BY c.created_at DESC
    LIMIT 8");

$calls = [];
while ($row = mysqli_fetch_assoc($query)) {
    $calls[] = $row;
}

echo json_encode(['success' => true, 'calls' => $calls]);
