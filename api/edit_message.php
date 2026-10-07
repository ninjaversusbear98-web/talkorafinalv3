<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if (!is_logged_in()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
$db=db_connect(); $userId=(int)$_SESSION['user_id']; $messageId=(int)($_POST['message_id']??0); $text=trim((string)($_POST['message']??'')); $replyTo=(int)($_POST['reply_to_id']??0);
if($messageId<=0 || $text===''){ http_response_code(400); echo json_encode(['success'=>false,'message'=>'Message and ID are required.']); exit; }
if(!table_has_column($db,'messages','edited_at')) mysqli_query($db,"ALTER TABLE messages ADD COLUMN edited_at DATETIME DEFAULT NULL");
if(!table_has_column($db,'messages','reply_to_id')) mysqli_query($db,"ALTER TABLE messages ADD COLUMN reply_to_id INT DEFAULT NULL");
$row=mysqli_fetch_assoc(mysqli_query($db,"SELECT * FROM messages WHERE id=$messageId LIMIT 1"));
if(!$row){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Message not found.']);exit;}
if((int)$row['sender_id']!==$userId){http_response_code(403);echo json_encode(['success'=>false,'message'=>'You can only edit your own messages.']);exit;}
$age=(time()-strtotime($row['created_at']))/60;
if($age>15){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Messages can only be edited within 15 minutes.']);exit;}
if((int)$row['is_deleted']===1){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Deleted messages cannot be edited.']);exit;}
$esc=mysqli_real_escape_string($db,$text); $replySql='NULL';
if($replyTo>0){
  $replyRow=mysqli_fetch_assoc(mysqli_query($db,"SELECT id FROM messages WHERE id=$replyTo AND ((sender_id=".(int)$row['sender_id']." AND receiver_id=".(int)$row['receiver_id'].") OR (sender_id=".(int)$row['receiver_id']." AND receiver_id=".(int)$row['sender_id'].")) LIMIT 1"));
  if($replyRow) $replySql=(int)$replyRow['id'];
}
mysqli_query($db,"UPDATE messages SET message='$esc', edited_at=NOW() WHERE id=$messageId");
$updated=mysqli_fetch_assoc(mysqli_query($db,"SELECT m.*, u.full_name AS sender_name, ru.full_name AS reply_sender_name, rm.message AS reply_message, rm.is_deleted AS reply_is_deleted, m.reply_preview_text FROM messages m JOIN users u ON u.id=m.sender_id LEFT JOIN messages rm ON rm.id=m.reply_to_id LEFT JOIN users ru ON ru.id=rm.sender_id WHERE m.id=$messageId LIMIT 1"));
echo json_encode(['success'=>true,'message'=>$updated]);
