<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if(!is_logged_in()){http_response_code(401);echo json_encode(['typing'=>false,'statusText'=>'Offline']);exit;}
$db=db_connect();$userId=(int)$_SESSION['user_id'];$receiverId=(int)($_GET['user_id']??0);
if(!table_has_column($db,'users','show_active_status')) mysqli_query($db,"ALTER TABLE users ADD COLUMN show_active_status TINYINT(1) NOT NULL DEFAULT 1 AFTER is_online");
if($receiverId<=0||$receiverId===$userId){echo json_encode(['typing'=>false,'statusText'=>'Offline']);exit;}
mysqli_query($db,"CREATE TABLE IF NOT EXISTS typing_status (user_id INT NOT NULL, receiver_id INT NOT NULL, is_typing TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL, PRIMARY KEY(user_id,receiver_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$row=mysqli_fetch_assoc(mysqli_query($db,"SELECT is_typing, updated_at FROM typing_status WHERE user_id=$receiverId AND receiver_id=$userId LIMIT 1"));
$typing=$row && (int)$row['is_typing']===1 && (time()-strtotime($row['updated_at'])<6);
$target=mysqli_fetch_assoc(mysqli_query($db,"SELECT is_online,show_active_status,last_seen FROM users WHERE id=$receiverId LIMIT 1"));
$statusText=$typing?'typing...':status_label((int)($target['is_online']??0)===1,$target['last_seen']??null,(int)($target['show_active_status']??1)===1);
echo json_encode(['typing'=>$typing,'statusText'=>$statusText]);
