<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if(!is_logged_in()){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Unauthorized']);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'message'=>'Method not allowed']);exit;}
$db=db_connect();$userId=(int)$_SESSION['user_id'];$messageId=(int)($_POST['message_id']??0);
if($messageId<=0){http_response_code(400);echo json_encode(['success'=>false,'message'=>'Missing message.']);exit;}
foreach([
 ['is_deleted','TINYINT(1) NOT NULL DEFAULT 0'],['deleted_at','DATETIME DEFAULT NULL'],['edited_at','DATETIME DEFAULT NULL'],['reply_to_id','INT DEFAULT NULL'],['reply_preview_text','VARCHAR(500) DEFAULT NULL'],['notification_sent_at','DATETIME DEFAULT NULL'],['media_type','VARCHAR(32) NULL DEFAULT NULL'],['media_path','VARCHAR(255) NULL DEFAULT NULL']
] as $c){if(!table_has_column($db,'messages',$c[0])) mysqli_query($db,"ALTER TABLE messages ADD COLUMN {$c[0]} {$c[1]}");}
$r=mysqli_query($db,"SELECT * FROM messages WHERE id=$messageId LIMIT 1"); if(!$r||mysqli_num_rows($r)===0){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Message not found.']);exit;}
$m=mysqli_fetch_assoc($r);if((int)$m['sender_id']!==$userId){http_response_code(403);echo json_encode(['success'=>false,'message'=>'You can only delete your own messages.']);exit;}
if(!empty($m['media_path'])){ $pathEsc=mysqli_real_escape_string($db,(string)$m['media_path']); $otherMedia=mysqli_fetch_assoc(mysqli_query($db,"SELECT COUNT(*) AS total FROM messages WHERE media_path='$pathEsc' AND id<>$messageId")); if((int)($otherMedia['total']??0)===0){ $p=__DIR__.'/../'.ltrim($m['media_path'],'/'); if(is_file($p)) @unlink($p); } }
mysqli_query($db,"UPDATE messages SET is_deleted=1,deleted_at=NOW(),message='This message was deleted',media_type=NULL,media_path=NULL WHERE id=$messageId");
$receiverId=(int)$m['receiver_id'];$result=mysqli_query($db,"SELECT m.*,u.full_name AS sender_name,ru.full_name AS reply_sender_name,rm.message AS reply_message,rm.is_deleted AS reply_is_deleted,m.reply_preview_text FROM messages m JOIN users u ON u.id=m.sender_id LEFT JOIN messages rm ON rm.id=m.reply_to_id LEFT JOIN users ru ON ru.id=rm.sender_id WHERE ((m.sender_id=$userId AND m.receiver_id=$receiverId) OR (m.sender_id=$receiverId AND m.receiver_id=$userId)) ORDER BY m.id ASC");
$rows=[];while($row=mysqli_fetch_assoc($result))$rows[]=$row;echo json_encode(['success'=>true,'messages'=>$rows]);
