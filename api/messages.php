<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if(!is_logged_in()){http_response_code(401);echo json_encode(['error'=>'Unauthorized']);exit;}
$db=db_connect();$userId=(int)$_SESSION['user_id'];$receiverId=(int)($_GET['user_id']??0);
if($receiverId<=0||$receiverId===$userId){echo json_encode(['messages'=>[]]);exit;}
$contact=mysqli_query($db,"SELECT id FROM contacts WHERE ((user_id=$userId AND contact_user_id=$receiverId) OR (user_id=$receiverId AND contact_user_id=$userId)) LIMIT 1");if(!$contact||mysqli_num_rows($contact)===0){echo json_encode(['messages'=>[]]);exit;}
$cols=['is_deleted'=>'TINYINT(1) NOT NULL DEFAULT 0','deleted_at'=>'DATETIME DEFAULT NULL','edited_at'=>'DATETIME DEFAULT NULL','reply_to_id'=>'INT DEFAULT NULL','reply_preview_text'=>'VARCHAR(500) DEFAULT NULL','notification_sent_at'=>'DATETIME DEFAULT NULL','media_type'=>'VARCHAR(32) NULL DEFAULT NULL','media_path'=>'VARCHAR(255) NULL DEFAULT NULL','delivered_at'=>'DATETIME DEFAULT NULL','read_at'=>'DATETIME DEFAULT NULL'];foreach($cols as $c=>$def){if(!table_has_column($db,'messages',$c)) mysqli_query($db,"ALTER TABLE messages ADD COLUMN $c $def");}
$sql="SELECT m.*,UNIX_TIMESTAMP(m.created_at) AS created_epoch,u.full_name AS sender_name,ru.full_name AS reply_sender_name,rm.message AS reply_message,rm.media_type AS reply_media_type,rm.is_deleted AS reply_is_deleted,m.reply_preview_text FROM messages m JOIN users u ON u.id=m.sender_id LEFT JOIN messages rm ON rm.id=m.reply_to_id LEFT JOIN users ru ON ru.id=rm.sender_id WHERE ((m.sender_id=$userId AND m.receiver_id=$receiverId) OR (m.sender_id=$receiverId AND m.receiver_id=$userId)) ORDER BY m.id ASC";
$result=mysqli_query($db,$sql);$messages=[];while($row=mysqli_fetch_assoc($result)){$messages[]=$row;}
// Opening the active conversation marks incoming messages read.
mysqli_query($db,"UPDATE messages SET is_read=1, read_at=COALESCE(read_at,NOW()) WHERE sender_id=$receiverId AND receiver_id=$userId AND is_read=0");
foreach($messages as &$row){if((int)$row['receiver_id']===$userId){$row['is_read']=1;$row['read_at']=$row['read_at']?:date('Y-m-d H:i:s');}}
unset($row);echo json_encode(['messages'=>$messages], JSON_INVALID_UTF8_SUBSTITUTE);
