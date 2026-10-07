<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if(!is_logged_in()){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Unauthorized']);exit;}
$db=db_connect();$userId=(int)$_SESSION['user_id'];
if(!table_has_column($db,'messages','delivered_at')) mysqli_query($db,"ALTER TABLE messages ADD COLUMN delivered_at DATETIME DEFAULT NULL");
if(!table_has_column($db,'messages','notification_sent_at')) mysqli_query($db,"ALTER TABLE messages ADD COLUMN notification_sent_at DATETIME DEFAULT NULL");
if($_SERVER['REQUEST_METHOD']==='POST'){
  $ids=$_POST['ids']??[]; if(!is_array($ids)) $ids=[$ids]; $clean=[]; foreach($ids as $id){$id=(int)$id;if($id>0)$clean[]=$id;} if($clean){$list=implode(',',$clean);mysqli_query($db,"UPDATE messages SET notification_sent_at=NOW() WHERE id IN ($list) AND receiver_id=$userId");} echo json_encode(['success'=>true]);exit;
}
$rows=[];$q=mysqli_query($db,"SELECT m.id,m.sender_id,m.receiver_id,m.message,m.media_type,m.created_at,u.full_name AS sender_name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.receiver_id=$userId AND m.sender_id<>$userId AND m.delivered_at IS NOT NULL AND (m.notification_sent_at IS NULL OR m.notification_sent_at < DATE_SUB(NOW(), INTERVAL 1 DAY)) AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) ORDER BY m.id ASC LIMIT 10");while($r=mysqli_fetch_assoc($q))$rows[]=$r;echo json_encode(['success'=>true,'messages'=>$rows]);
