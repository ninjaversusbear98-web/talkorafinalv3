<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
    exit;
}

$db = db_connect();
$userId = (int) $_SESSION['user_id'];
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_community_games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    community_id INT NOT NULL,
    game_key ENUM('tictactoe','connect4','rps') NOT NULL,
    creator_id INT NOT NULL,
    state_json LONGTEXT NOT NULL,
    status ENUM('waiting','active','complete') NOT NULL DEFAULT 'waiting',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (community_id) REFERENCES talkora_communities(id) ON DELETE CASCADE,
    FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_community_games (community_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_community_watch (
    community_id INT PRIMARY KEY,
    video_id CHAR(11) NOT NULL,
    is_playing TINYINT(1) NOT NULL DEFAULT 0,
    position_seconds DECIMAL(10,3) NOT NULL DEFAULT 0,
    host_user_id INT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (community_id) REFERENCES talkora_communities(id) ON DELETE CASCADE,
    FOREIGN KEY (host_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($db, "CREATE TABLE IF NOT EXISTS talkora_community_typing (
    community_id INT NOT NULL,
    user_id INT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (community_id,user_id),
    FOREIGN KEY (community_id) REFERENCES talkora_communities(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$input = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST;
$communityId = (int) ($input['community_id'] ?? 0);
$membership = mysqli_query($db, "SELECT role FROM talkora_community_members WHERE community_id=$communityId AND user_id=$userId LIMIT 1");
if (!$membership || mysqli_num_rows($membership) === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Join this community to use group activities.']);
    exit;
}

function community_game_win($cells, $row, $column, $symbol, $height, $width, $connect)
{
    foreach ([[0,1],[1,0],[1,1],[1,-1]] as [$dr,$dc]) {
        $count = 1;
        foreach ([1,-1] as $direction) {
            $r = $row + ($dr * $direction);
            $c = $column + ($dc * $direction);
            while ($r >= 0 && $r < $height && $c >= 0 && $c < $width && $cells[$r * $width + $c] === $symbol) {
                $count++;
                $r += $dr * $direction;
                $c += $dc * $direction;
            }
        }
        if ($count >= $connect) return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $games = [];
    $result = mysqli_query($db, "SELECT g.*,u.full_name AS creator_name FROM talkora_community_games g JOIN users u ON u.id=g.creator_id WHERE g.community_id=$communityId AND g.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) ORDER BY g.updated_at DESC LIMIT 24");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $state = json_decode((string) $row['state_json'], true) ?: [];
        $playerIds = array_values(array_unique(array_map('intval', $state['players'] ?? [])));
        $playerNames = [];
        if ($playerIds) {
            $names = mysqli_query($db, "SELECT id,full_name FROM users WHERE id IN (" . implode(',', $playerIds) . ")");
            while ($names && ($player = mysqli_fetch_assoc($names))) $playerNames[(string) $player['id']] = $player['full_name'];
        }
        $row['state'] = $state;
        $row['player_names'] = $playerNames;
        unset($row['state_json']);
        $games[] = $row;
    }
    $watch = mysqli_fetch_assoc(mysqli_query($db, "SELECT w.*,u.full_name AS host_name,UNIX_TIMESTAMP(w.updated_at) AS updated_epoch FROM talkora_community_watch w JOIN users u ON u.id=w.host_user_id WHERE w.community_id=$communityId LIMIT 1"));
    $typers = [];
    $typingResult = mysqli_query($db, "SELECT u.full_name FROM talkora_community_typing t JOIN users u ON u.id=t.user_id WHERE t.community_id=$communityId AND t.user_id<>$userId AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 6 SECOND) ORDER BY t.updated_at DESC LIMIT 8");
    while ($typingResult && ($typingRow = mysqli_fetch_assoc($typingResult))) $typers[] = $typingRow['full_name'];
    echo json_encode(['success' => true, 'games' => $games, 'watch' => $watch ?: null, 'typers' => $typers], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
if ($action === 'typing') {
    $isTyping = (int) ($_POST['typing'] ?? 0) === 1;
    if ($isTyping) {
        mysqli_query($db, "INSERT INTO talkora_community_typing (community_id,user_id,updated_at) VALUES ($communityId,$userId,NOW()) ON DUPLICATE KEY UPDATE updated_at=NOW()");
    } else {
        mysqli_query($db, "DELETE FROM talkora_community_typing WHERE community_id=$communityId AND user_id=$userId");
    }
    echo json_encode(['success' => true]);
    exit;
}
if ($action === 'watch_set') {
    $videoId = trim((string) ($_POST['video_id'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enter a valid YouTube video URL or ID.']);
        exit;
    }
    $videoEsc = mysqli_real_escape_string($db, $videoId);
    mysqli_query($db, "INSERT INTO talkora_community_watch (community_id,video_id,is_playing,position_seconds,host_user_id) VALUES ($communityId,'$videoEsc',0,0,$userId) ON DUPLICATE KEY UPDATE video_id=VALUES(video_id),is_playing=0,position_seconds=0,host_user_id=VALUES(host_user_id),updated_at=NOW()");
    echo json_encode(['success' => true]);
    exit;
}
if ($action === 'watch_control' || $action === 'watch_sync') {
    $isPlaying = (int) ($_POST['is_playing'] ?? 0) === 1 ? 1 : 0;
    $position = max(0, min(86400, (float) ($_POST['position_seconds'] ?? 0)));
    if ($action === 'watch_control') {
        mysqli_query($db, "UPDATE talkora_community_watch SET is_playing=$isPlaying,position_seconds=$position,host_user_id=$userId,updated_at=NOW() WHERE community_id=$communityId");
    } else {
        mysqli_query($db, "UPDATE talkora_community_watch SET is_playing=$isPlaying,position_seconds=$position,updated_at=NOW() WHERE community_id=$communityId AND host_user_id=$userId");
    }
    echo json_encode(['success' => true]);
    exit;
}

$gameId = (int) ($_POST['game_id'] ?? 0);
if ($action === 'create_game') {
    $gameKey = trim((string) ($_POST['game_key'] ?? ''));
    $initialState = match ($gameKey) {
        'tictactoe' => ['players' => [$userId], 'board' => array_fill(0, 9, ''), 'turn' => $userId, 'winner' => 0, 'symbols' => [(string) $userId => 'X']],
        'connect4' => ['players' => [$userId], 'board' => array_fill(0, 42, ''), 'turn' => $userId, 'winner' => 0, 'symbols' => [(string) $userId => 'R']],
        'rps' => ['players' => [$userId], 'choices' => [], 'scores' => [(string) $userId => 0], 'round' => 1, 'last_result' => 'Waiting for another player'],
        default => null,
    };
    if ($initialState === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Choose one of the available games.']);
        exit;
    }
    $stateJson = mysqli_real_escape_string($db, json_encode($initialState));
    mysqli_query($db, "INSERT INTO talkora_community_games (community_id,game_key,creator_id,state_json,status) VALUES ($communityId,'$gameKey',$userId,'$stateJson','waiting')");
    echo json_encode(['success' => mysqli_affected_rows($db) > 0, 'game_id' => (int) mysqli_insert_id($db)]);
    exit;
}

$gameResult = mysqli_query($db, "SELECT * FROM talkora_community_games WHERE id=$gameId AND community_id=$communityId LIMIT 1");
$game = $gameResult ? mysqli_fetch_assoc($gameResult) : null;
if (!$game) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Game session not found.']);
    exit;
}
$state = json_decode((string) $game['state_json'], true) ?: [];
$players = array_values(array_map('intval', $state['players'] ?? []));

if ($action === 'join_game') {
    if (in_array($userId, $players, true)) {
        echo json_encode(['success' => true]);
        exit;
    }
    if ($game['status'] !== 'waiting' || count($players) >= 2) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This game already has two players or has started.']);
        exit;
    }
    $players[] = $userId;
    $state['players'] = $players;
    $symbol = $game['game_key'] === 'tictactoe' ? 'O' : 'Y';
    if ($game['game_key'] === 'connect4') $symbol = 'Y';
    $state['symbols'][(string) $userId] = $symbol;
    $state['scores'][(string) $userId] = 0;
    $stateJson = mysqli_real_escape_string($db, json_encode($state));
    mysqli_query($db, "UPDATE talkora_community_games SET state_json='$stateJson',status='active',updated_at=NOW() WHERE id=$gameId AND status='waiting'");
    echo json_encode(['success' => mysqli_affected_rows($db) > 0]);
    exit;
}

if ($action !== 'move' || !in_array($userId, $players, true) || $game['status'] !== 'active') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You cannot make that move.']);
    exit;
}

$resultText = '';
$status = 'active';
if ($game['game_key'] === 'tictactoe' || $game['game_key'] === 'connect4') {
    if ((int) ($state['turn'] ?? 0) !== $userId) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Wait for the other player’s turn.']);
        exit;
    }
    $symbol = (string) ($state['symbols'][(string) $userId] ?? '');
    if ($game['game_key'] === 'tictactoe') {
        $cell = (int) ($_POST['cell'] ?? -1);
        if ($cell < 0 || $cell > 8 || ($state['board'][$cell] ?? '') !== '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'That square is not available.']);
            exit;
        }
        $state['board'][$cell] = $symbol;
        if (community_game_win($state['board'], intdiv($cell, 3), $cell % 3, $symbol, 3, 3, 3)) {
            $state['winner'] = $userId;
            $status = 'complete';
        } elseif (!in_array('', $state['board'], true)) {
            $state['winner'] = -1;
            $status = 'complete';
        }
    } else {
        $column = (int) ($_POST['column'] ?? -1);
        if ($column < 0 || $column > 6) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Choose a valid column.']);
            exit;
        }
        $cell = -1;
        for ($row = 5; $row >= 0; $row--) {
            $index = $row * 7 + $column;
            if (($state['board'][$index] ?? '') === '') { $cell = $index; break; }
        }
        if ($cell < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'That column is full.']);
            exit;
        }
        $state['board'][$cell] = $symbol;
        if (community_game_win($state['board'], intdiv($cell, 7), $column, $symbol, 6, 7, 4)) {
            $state['winner'] = $userId;
            $status = 'complete';
        } elseif (!in_array('', $state['board'], true)) {
            $state['winner'] = -1;
            $status = 'complete';
        }
    }
    if ($status === 'complete') $resultText = (int) $state['winner'] === -1 ? 'Draw game' : ((string) ($state['symbols'][(string) $userId] ?? '') . ' wins');
    else $state['turn'] = $players[0] === $userId ? $players[1] : $players[0];
} elseif ($game['game_key'] === 'rps') {
    $choice = trim((string) ($_POST['choice'] ?? ''));
    if (!in_array($choice, ['rock','paper','scissors'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Choose rock, paper, or scissors.']);
        exit;
    }
    $state['choices'][(string) $userId] = $choice;
    if (count($state['choices']) >= 2) {
        $first = $state['choices'][(string) $players[0]];
        $second = $state['choices'][(string) $players[1]];
        $winnerId = $first === $second ? 0 : (in_array([$first,$second], [['rock','scissors'],['scissors','paper'],['paper','rock']], true) ? $players[0] : $players[1]);
        if ($winnerId) $state['scores'][(string) $winnerId] = (int) ($state['scores'][(string) $winnerId] ?? 0) + 1;
        $winner = $winnerId ? mysqli_fetch_assoc(mysqli_query($db, "SELECT full_name FROM users WHERE id=$winnerId LIMIT 1")) : null;
        $state['last_result'] = $winnerId ? (($winner['full_name'] ?? 'Player') . ' wins round ' . (int) $state['round']) : 'Round ' . (int) $state['round'] . ' is a draw';
        $state['round'] = (int) $state['round'] + 1;
        $state['choices'] = [];
    }
}

$stateJson = mysqli_real_escape_string($db, json_encode($state));
$resultEsc = mysqli_real_escape_string($db, $resultText);
$updated = mysqli_query($db, "UPDATE talkora_community_games SET state_json='$stateJson',status='$status',updated_at=NOW() WHERE id=$gameId AND community_id=$communityId");
echo json_encode(['success' => (bool) $updated, 'status' => $status, 'result' => $resultText]);