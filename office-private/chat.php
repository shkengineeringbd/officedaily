<?php
declare(strict_types=1);
if(!defined('OFFICE_APP')){http_response_code(404);exit;}
function chat_storage():void{
 query("CREATE TABLE IF NOT EXISTS od_chat_rooms(id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,kind VARCHAR(10) NOT NULL,pair_key VARCHAR(73) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,created_by VARCHAR(36) NOT NULL,created_at VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 query("CREATE TABLE IF NOT EXISTS od_chat_members(room_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,display_name VARCHAR(100) NOT NULL,last_read_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(room_id,account_id),KEY member_rooms(account_id,room_id),FOREIGN KEY(room_id) REFERENCES od_chat_rooms(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 query("CREATE TABLE IF NOT EXISTS od_chat_messages(seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,room_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,sender_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,sender_name VARCHAR(100) NOT NULL,body TEXT NOT NULL,payload_hash CHAR(64) NOT NULL,created_at VARCHAR(30) NOT NULL,KEY room_messages(room_id,seq),KEY sender_messages(sender_id),FOREIGN KEY(room_id) REFERENCES od_chat_rooms(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 query("CREATE TABLE IF NOT EXISTS od_chat_files(id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,message_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,room_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,sender_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,filename VARCHAR(180) NOT NULL,mime VARCHAR(80) NOT NULL,bytes INT UNSIGNED NOT NULL,nonce VARBINARY(12) NOT NULL,tag VARBINARY(16) NOT NULL,cipher MEDIUMBLOB NOT NULL,created_at VARCHAR(30) NOT NULL,KEY sender_files(sender_id),FOREIGN KEY(message_id) REFERENCES od_chat_messages(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 query("INSERT IGNORE INTO od_chat_rooms(id,kind,pair_key,created_by,created_at) VALUES('office','office',NULL,'system',?)",[now()]);
}
function chat_uuid(array $v,string $key):string{
 $id=strtolower(id_input($v,$key));if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$id))fail('সঠিক চ্যাট অনুরোধ দিন।');return $id;
}
function chat_room_id(array $v):string{return ($v['roomId']??'')==='office'?'office':chat_uuid($v,'roomId');}
function chat_eligible_sql():string{return "COALESCE(h.access_enabled,1)=1 AND (a.is_owner=1 OR (m.active=1 AND p.status='approved' AND p.branch_id=s.branch_id AND b.active=1))";}
function chat_people_sql():string{return 'FROM od_accounts a LEFT JOIN od_members m ON m.user_id=a.id LEFT JOIN od_hr_profiles h ON h.account_id=a.id LEFT JOIN od_staff_assignments s ON s.account_id=a.id LEFT JOIN od_identity_profiles p ON p.account_id=a.id LEFT JOIN od_branches b ON b.id=s.branch_id';}
function chat_person(string $uid):?array{return one("SELECT a.id,a.name,COALESCE(s.post,IF(a.is_owner=1,'admin','staff')) AS post,COALESCE(b.name,'') AS branch_name ".chat_people_sql().' WHERE a.id=? AND '.chat_eligible_sql(),[$uid]);}
function chat_room(array $a,string $id,bool $sending=false):array{
 $r=one('SELECT id,kind,created_at FROM od_chat_rooms WHERE id=?',[$id]);
 if(!$r)fail('কথোপকথন পাওয়া যায়নি।',404);
 if($r['kind']==='office'){if($r['id']!=='office')fail('কথোপকথন পাওয়া যায়নি।',404);$r['title']='Office group';$r['can_send']=true;}
 else{
  if(!one('SELECT account_id FROM od_chat_members WHERE room_id=? AND account_id=?',[$id,$a['userId']]))fail('কথোপকথন পাওয়া যায়নি।',404);
  $peer=one('SELECT c.account_id,COALESCE(a.name,c.display_name) AS name FROM od_chat_members c LEFT JOIN od_accounts a ON a.id=c.account_id WHERE c.room_id=? AND c.account_id<>? LIMIT 1',[$id,$a['userId']]);
  $r['title']=$peer['name']??'Former user';$r['peer_id']=$peer['account_id']??null;$r['can_send']=$peer&&chat_person($peer['account_id'])!==null;
 }
 if($sending&&!$r['can_send'])fail('এই ইউজার বর্তমানে চ্যাটে উপলব্ধ নেই। পুরোনো কথোপকথন দেখা যাবে।',409);
 return $r;
}
function chat_number(array $v,string $key,int $default=0):int{
 $n=filter_var($v[$key]??$default,FILTER_VALIDATE_INT);if($n===false||$n<0||$n>PHP_INT_MAX-1)fail('সঠিক চ্যাট পৃষ্ঠা দিন।');return $n;
}
function chat_attachment(array $v):?array{
 $raw=$v['attachment']??'';if($raw==='')return null;
 $name=strval_input($v,'fileName',180);$name=trim(str_replace(['\\','/'], '_',$name));if(preg_match('/[\x00-\x1F\x7F]/u',$name)||$name==='')fail('সঠিক ফাইলের নাম দিন।');
 $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$allowed=['pdf','jpg','jpeg','png','webp','txt','csv','doc','docx','xls','xlsx','ppt','pptx','zip'];
 if(!in_array($ext,$allowed,true))fail('PDF, ছবি, Office document, TXT/CSV অথবা ZIP ফাইল দিন।');
 if(!is_string($raw)||strlen($raw)>4300000||!preg_match('#^data:([^;,]*);base64,([A-Za-z0-9+/=]+)$#D',$raw,$m))fail('সঠিক ফাইল আপলোড করুন।');
 $data=base64_decode($m[2],true);if($data===false||strlen($data)<1||strlen($data)>3145728)fail('প্রতি ফাইলের সর্বোচ্চ আকার ৩ MB।');
 $mime='application/octet-stream';
 if(in_array($ext,['jpg','jpeg','png','webp'],true)){
  $size=@getimagesizefromstring($data);$expected=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$ext];
  if(!$size||($size['mime']??'')!==$expected||$size[0]>12000||$size[1]>12000)fail('সঠিক ছবি আপলোড করুন।');$mime=$expected;
 }elseif($ext==='pdf'){if(!str_starts_with($data,'%PDF-'))fail('সঠিক PDF ফাইল দিন।');$mime='application/pdf';}
 elseif(in_array($ext,['zip','docx','xlsx','pptx'],true)){
  if(!in_array(substr($data,0,4),["PK\x03\x04","PK\x05\x06","PK\x07\x08"],true))fail('সঠিক ZIP অথবা Office document দিন।');
  $mime=['zip'=>'application/zip','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation'][$ext];
 }elseif(in_array($ext,['doc','xls','ppt'],true)){
  if(substr($data,0,8)!==hex2bin('d0cf11e0a1b11ae1'))fail('সঠিক Office document দিন।');
 }else{if(preg_match('//u',$data)!==1||str_contains($data,"\0"))fail('সঠিক TXT বা CSV ফাইল দিন।');$mime=$ext==='csv'?'text/csv':'text/plain';}
 return ['filename'=>$name,'mime'=>$mime,'bytes'=>strlen($data),'data'=>$data];
}
function chat_get(array $a):never{
 $mode=$_GET['chat']??'rooms';$uid=$a['userId'];
 if($mode==='contacts'){
  $page=max(1,chat_number($_GET,'page',1));if($page>100000)fail('সঠিক পৃষ্ঠা দিন।');$q=strval_input(['q'=>$_GET['q']??''],'q',100,true);
  $where=chat_eligible_sql().' AND a.id<>?';$args=[$uid];
  if($q!==''){$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';$where.=" AND (a.name LIKE ? ESCAPE '!' OR b.name LIKE ? ESCAPE '!')";array_push($args,$like,$like);}
  $count=(int)(one('SELECT COUNT(*) AS total '.chat_people_sql().' WHERE '.$where,$args)['total']??0);
  $items=rows("SELECT a.id,a.name,COALESCE(s.post,IF(a.is_owner=1,'admin','staff')) AS post,COALESCE(b.name,'') AS branch_name ".chat_people_sql().' WHERE '.$where.' ORDER BY a.name,a.id LIMIT 30 OFFSET '.(($page-1)*30),$args);
  output(['items'=>$items,'page'=>$page,'total'=>$count,'hasMore'=>$page*30<$count]);
 }
 if($mode==='rooms'){
  $page=max(1,chat_number($_GET,'page',1));if($page>100000)fail('সঠিক পৃষ্ঠা দিন।');
  $where="(r.kind='office' OR mine.account_id IS NOT NULL)";
  $count=(int)(one("SELECT COUNT(*) AS total FROM od_chat_rooms r LEFT JOIN od_chat_members mine ON mine.room_id=r.id AND mine.account_id=? WHERE $where",[$uid])['total']??0);
  $list=rows("SELECT r.id,r.kind,r.created_at,COALESCE(pa.name,peer.display_name,'Former user') AS peer_name,
   COALESCE((SELECT MAX(m.seq) FROM od_chat_messages m WHERE m.room_id=r.id),0) AS last_seq,
   (SELECT LEFT(m.body,120) FROM od_chat_messages m WHERE m.room_id=r.id ORDER BY m.seq DESC LIMIT 1) AS preview,
   (SELECT m.created_at FROM od_chat_messages m WHERE m.room_id=r.id ORDER BY m.seq DESC LIMIT 1) AS last_at,
   (SELECT COUNT(*) FROM od_chat_messages m WHERE m.room_id=r.id AND m.sender_id<>? AND m.seq>COALESCE(mine.last_read_seq,0)) AS unread
   FROM od_chat_rooms r LEFT JOIN od_chat_members mine ON mine.room_id=r.id AND mine.account_id=?
   LEFT JOIN od_chat_members peer ON peer.room_id=r.id AND peer.account_id<>? AND r.kind='direct'
   LEFT JOIN od_accounts pa ON pa.id=peer.account_id
   WHERE $where ORDER BY last_seq DESC,r.created_at DESC,r.id LIMIT 30 OFFSET ".(($page-1)*30),[$uid,$uid,$uid]);
  foreach($list as &$r){$r['title']=$r['kind']==='office'?'Office group':$r['peer_name'];unset($r['peer_name']);$r['last_seq']=(int)$r['last_seq'];$r['unread']=(int)$r['unread'];}unset($r);
  output(['items'=>$list,'page'=>$page,'hasMore'=>$page*30<$count,'total'=>$count]);
 }
 if($mode==='messages'){
  $id=chat_room_id($_GET);$room=chat_room($a,$id);$before=chat_number($_GET,'before');$after=chat_number($_GET,'after');if($before&&$after)fail('সঠিক চ্যাট অনুরোধ দিন।');
  $where='m.room_id=?';$args=[$id];if($before){$where.=' AND m.seq<?';$args[]=$before;}if($after){$where.=' AND m.seq>?';$args[]=$after;}
  $limit=$after?100:30;
  $items=rows('SELECT m.seq,m.id,m.sender_id,m.sender_name,m.body,m.created_at,f.id AS file_id,f.filename,f.mime,f.bytes FROM od_chat_messages m LEFT JOIN od_chat_files f ON f.message_id=m.id WHERE '.$where.' ORDER BY m.seq '.($after?'ASC':'DESC').' LIMIT '.($limit+1),$args);
  $more=count($items)>$limit;if($more)array_pop($items);if(!$after)$items=array_reverse($items);
  foreach($items as &$m){$m['seq']=(int)$m['seq'];if($m['bytes']!==null)$m['bytes']=(int)$m['bytes'];}unset($m);
  output(['room'=>$room,'items'=>$items,'hasMoreOlder'=>!$after&&$more,'hasMoreNew'=>(bool)$after&&$more,'currentUserId'=>$uid]);
 }
 if($mode==='file'){
  $id=chat_uuid($_GET,'id');$f=one('SELECT id,room_id,message_id,sender_id,filename,mime,bytes FROM od_chat_files WHERE id=?',[$id]);if(!$f)fail('ফাইল পাওয়া যায়নি।',404);chat_room($a,$f['room_id']);
  $data=one('SELECT cipher,nonce,tag FROM od_chat_files WHERE id=?',[$id]);if(!$data)fail('ফাইল পাওয়া যায়নি।',404);
  $plain=openssl_decrypt($data['cipher'],'aes-256-gcm',hash('sha256','office-chat-files|'.config()['app_secret'],true),OPENSSL_RAW_DATA,$data['nonce'],$data['tag'],$f['room_id'].'|'.$f['message_id'].'|'.$f['sender_id']);
  if($plain===false)fail('ফাইল খোলা যায়নি।',500);
  header('Content-Type: '.$f['mime']);header('Content-Length: '.strlen($plain));header("Content-Security-Policy: default-src 'none'; sandbox");
  $ext=strtolower(pathinfo($f['filename'],PATHINFO_EXTENSION));header("Content-Disposition: attachment; filename=\"chat-file.$ext\"; filename*=UTF-8''".rawurlencode($f['filename']));echo $plain;exit;
 }
 fail('সঠিক চ্যাট বিভাগ দিন।');
}
function chat_action(array $a,array $v,string $action):bool{
 if(!str_starts_with($action,'chat_'))return false;
 $uid=$a['userId'];
 if($action==='chat_open'){
  $target=chat_uuid($v,'recipientId');if($target===$uid)fail('অন্য একজন ইউজার বেছে নিন।');$peer=chat_person($target);if(!$peer)fail('ইউজার বর্তমানে চ্যাটে উপলব্ধ নেই।',404);
  $ids=[$uid,$target];sort($ids,SORT_STRING);$pair=implode(':',$ids);$id=uid();
  transaction(function()use($a,$uid,$target,$peer,$pair,$id){
   query("INSERT INTO od_chat_rooms(id,kind,pair_key,created_by,created_at) VALUES(?,'direct',?,?,?) ON DUPLICATE KEY UPDATE pair_key=VALUES(pair_key)",[$id,$pair,$uid,now()]);
   $r=one('SELECT id FROM od_chat_rooms WHERE pair_key=? FOR UPDATE',[$pair]);
   query('INSERT IGNORE INTO od_chat_members(room_id,account_id,display_name) VALUES(?,?,?),(?,?,?)',[$r['id'],$uid,$a['name'],$r['id'],$target,$peer['name']]);
  });$r=one('SELECT id FROM od_chat_rooms WHERE pair_key=?',[$pair]);output(['ok'=>true,'room'=>chat_room($a,$r['id'])]);
 }
 if($action==='chat_send'){
  $room=chat_room_id($v);chat_room($a,$room,true);$id=chat_uuid($v,'requestId');$body=strval_input(['body'=>$v['body']??''],'body',4000,true);$file=chat_attachment($v);if($body===''&&!$file)fail('মেসেজ লিখুন অথবা ফাইল যুক্ত করুন।');
  $hash=hash('sha256',json_encode([$body,$file['filename']??'',isset($file['data'])?hash('sha256',$file['data']):''],JSON_THROW_ON_ERROR));
  throttle('chat-send',$uid,300);if($file)throttle('chat-upload',$uid,40);
  transaction(function()use($a,$uid,$room,$id,$body,$file,$hash){
   one('SELECT id FROM od_chat_rooms WHERE id=? FOR UPDATE',[$room]);chat_room($a,$room,true);$old=one('SELECT sender_id,room_id,payload_hash FROM od_chat_messages WHERE id=?',[$id]);
   if($old){if($old['sender_id']!==$uid||$old['room_id']!==$room||!hash_equals($old['payload_hash'],$hash))fail('এই মেসেজ অনুরোধ আগে ব্যবহার হয়েছে।',409);return;}
   query('INSERT INTO od_chat_messages(id,room_id,sender_id,sender_name,body,payload_hash,created_at) VALUES(?,?,?,?,?,?,?)',[$id,$room,$uid,$a['name'],$body,$hash,now()]);
   if($file){$nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($file['data'],'aes-256-gcm',hash('sha256','office-chat-files|'.config()['app_secret'],true),OPENSSL_RAW_DATA,$nonce,$tag,$room.'|'.$id.'|'.$uid);if($cipher===false)fail('ফাইল সংরক্ষণ করা যায়নি।',500);
    query('INSERT INTO od_chat_files(id,message_id,room_id,sender_id,filename,mime,bytes,nonce,tag,cipher,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[uid(),$id,$room,$uid,$file['filename'],$file['mime'],$file['bytes'],$nonce,$tag,$cipher,now()]);}
  });output(['ok'=>true,'id'=>$id]);
 }
 if($action==='chat_read'){
  $room=chat_room_id($v);chat_room($a,$room);$seq=chat_number($v,'lastSeq');$max=(int)(one('SELECT COALESCE(MAX(seq),0) AS seq FROM od_chat_messages WHERE room_id=?',[$room])['seq']??0);if($seq>$max)fail('সঠিক পঠিত মেসেজ দিন।');
  query('INSERT INTO od_chat_members(room_id,account_id,display_name,last_read_seq) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_read_seq=GREATEST(last_read_seq,VALUES(last_read_seq))',[$room,$uid,$a['name'],$seq]);output(['ok'=>true]);
 }
 fail('সঠিক চ্যাট অনুরোধ দিন।');
}
