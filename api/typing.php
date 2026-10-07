<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if (!is_logged_in()) { http_response_code(401); echo json_encode(['success'=>false]); exit; }
$db=db_connect(); $userId=(int)$_SESSION['user_id']; $receiverId=(int)($_POST['receiver_id']??0); $isTyping=(int)($_POST['typing']??0);
if($receiverId<=0 || $receiverId===$userId){echo json_encode(['success'=>false]);exit;}
if(!table_has_column($db,'users','last_seen')){ echo json_encode(['success'=>false]); exit; }
mysqli_query($db,"CREATE TABLE IF NOT EXISTS typing_status (user_id INT NOT NULL, receiver_id INT NOT NULL, is_typing TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL, PRIMARY KEY(user_id,receiver_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$stmt=mysqli_prepare($db,"INSERT INTO typing_status(user_id,receiver_id,is_typing,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE is_typing=VALUES(is_typing),updated_at=NOW()");
if($stmt){mysqli_stmt_bind_param($stmt,'iii',$userId,$receiverId,$isTyping);mysqli_stmt_execute($stmt);mysqli_stmt_close($stmt);}
echo json_encode(['success'=>true,'typing'=>$isTyping]);
