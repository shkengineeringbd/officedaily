<?php
declare(strict_types=1);
if(!defined('OFFICE_APP')){http_response_code(404);exit;}
function staff_storage():void{
 $sqls=[
 "CREATE TABLE IF NOT EXISTS od_branches(id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE,is_head TINYINT NOT NULL DEFAULT 0,active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS od_staff_assignments(account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,branch_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,post VARCHAR(20) NOT NULL,assigned_by VARCHAR(36) NOT NULL,assigned_at VARCHAR(30) NOT NULL,KEY branch_staff(branch_id,post)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS od_identity_profiles(account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,full_name VARCHAR(100) NOT NULL,birth_date CHAR(10) NOT NULL,photo_id VARCHAR(36) NOT NULL,nid_id VARCHAR(36) NOT NULL,branch_id VARCHAR(36) NOT NULL,status VARCHAR(16) NOT NULL,revision INT NOT NULL,submitted_at VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS od_identity_files(id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,kind VARCHAR(10) NOT NULL,mime VARCHAR(60) NOT NULL,nonce VARBINARY(12) NOT NULL,tag VARBINARY(16) NOT NULL,cipher MEDIUMBLOB NOT NULL,created_at VARCHAR(30) NOT NULL,KEY file_account(account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS od_profile_events(sequence BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id VARCHAR(36) NOT NULL,revision INT NOT NULL,action VARCHAR(20) NOT NULL,actor_id VARCHAR(36) NOT NULL,actor_name VARCHAR(100) NOT NULL,actor_email VARCHAR(254) NOT NULL,note TEXT NOT NULL,snapshot TEXT NOT NULL,created_at VARCHAR(30) NOT NULL,KEY profile_events(account_id,sequence)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS od_staff_events(sequence BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id VARCHAR(36) NOT NULL,actor_name VARCHAR(100) NOT NULL,actor_email VARCHAR(254) NOT NULL,post VARCHAR(20) NOT NULL,branch_name VARCHAR(100) NOT NULL,created_at VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS od_budget_branches(budget_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,branch_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,KEY branch_budgets(branch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
 ];foreach($sqls as $s)query($s);
 query("INSERT IGNORE INTO od_branches(id,name,is_head) VALUES('head-office','হেড অফিস',1)");
 // Match legacy budget branch snapshots only to explicitly configured branches. Unmatched records stay admin-only.
 query('INSERT IGNORE INTO od_budget_branches(budget_id,branch_id) SELECT b.id,r.id FROM od_budgets b JOIN od_branches r ON r.name=b.branch');
}
function staff_assignment(string $id):?array{return one('SELECT s.*,b.name AS branch_name,b.is_head,b.active AS branch_active FROM od_staff_assignments s JOIN od_branches b ON b.id=s.branch_id WHERE s.account_id=?',[$id]);}
function staff_verified(string $id):bool{$r=one("SELECT p.account_id FROM od_identity_profiles p JOIN od_staff_assignments s ON s.account_id=p.account_id AND s.branch_id=p.branch_id JOIN od_branches b ON b.id=s.branch_id WHERE p.account_id=? AND p.status='approved' AND b.active=1",[$id]);return (bool)$r;}
function staff_decorate(array $a,bool $require=true):array{
 $s=staff_assignment($a['userId']);$verified=$a['role']==='owner'||staff_verified($a['userId']);
 $a['branchId']=$s['branch_id']??null;$a['branchName']=$s['branch_name']??'';$a['post']=$s['post']??($a['role']==='owner'?'admin':null);
 $a['globalRead']=$a['role']==='owner'||($verified&&$s&&(int)$s['is_head']===1&&in_array($s['post'],['gm','cfo','md'],true));
 $a['manageBranch']=$verified&&$s&&$s['post']==='branch_manager';$a['canBrowseStaff']=$a['globalRead']||$a['manageBranch'];
 $a['viewAll']=$a['globalRead']?1:0;
 if($require&&!$verified)fail('ব্রাঞ্চ, পোস্ট এবং ব্যক্তিগত প্রোফাইলের চূড়ান্ত অনুমোদন প্রয়োজন।',403);
 return $a;
}
function staff_account_allowed(array $a,string $uid):bool{
 if($uid===$a['userId']||$a['globalRead'])return true;
 if(!$a['manageBranch']||!$a['branchId'])return false;
 $s=staff_assignment($uid);return $s&&$s['branch_id']===$a['branchId'];
}
function staff_assert_account(array $a,string $uid):void{if(!staff_account_allowed($a,$uid))fail('এই ব্রাঞ্চ বা ইউজারের তথ্য দেখার অনুমতি নেই।',403);}
function staff_task_scope(array $a,string $alias='t'):array{
 if($a['globalRead'])return ['1=1',[]];
 if($a['manageBranch'])return ["EXISTS (SELECT 1 FROM od_members sm JOIN od_staff_assignments ss ON ss.account_id=sm.user_id WHERE sm.id=$alias.assignee_id AND ss.branch_id=?)",[$a['branchId']]];
 return ["$alias.assignee_id=?",[$a['id']]];
}
function staff_budget_scope(array $a,string $alias='b'):array{
 if($a['globalRead'])return ['1=1',[]];
 if(!$a['branchId'])return ['1=0',[]];
 return ["EXISTS (SELECT 1 FROM od_budget_branches sb WHERE sb.budget_id=$alias.id AND sb.branch_id=?)",[$a['branchId']]];
}
function staff_budget_assert(array $a,string $id):void{[$w,$p]=staff_budget_scope($a);if(!one('SELECT b.id FROM od_budgets b WHERE b.id=? AND '.$w,[$id,...$p]))fail('বাজেট পাওয়া যায়নি।',404);}
function staff_assign(array $a,string $uid,string $branch,string $post):void{
 if(!in_array($post,['officer','staff','branch_manager','gm','cfo','md'],true))fail('সঠিক পোস্ট বেছে নিন।');
 $b=one('SELECT * FROM od_branches WHERE id=? AND active=1',[$branch]);if(!$b)fail('সক্রিয় ব্রাঞ্চ বেছে নিন।');
 $old=staff_assignment($uid);
 query('INSERT INTO od_staff_assignments(account_id,branch_id,post,assigned_by,assigned_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE branch_id=VALUES(branch_id),post=VALUES(post),assigned_by=VALUES(assigned_by),assigned_at=VALUES(assigned_at)',[$uid,$branch,$post,$a['userId'],now()]);
 query('UPDATE od_members SET role=?,view_all=0 WHERE user_id=?',[$post==='branch_manager'?'manager':'officer',$uid]);
 if($old&&$old['branch_id']!==$branch)query("UPDATE od_identity_profiles SET status='needs_update' WHERE account_id=?",[$uid]);
 query('INSERT INTO od_staff_events(account_id,actor_name,actor_email,post,branch_name,created_at) VALUES(?,?,?,?,?,?)',[$uid,$a['name'],$a['email'],$post,$b['name'],now()]);
}
function staff_branches():array{return rows('SELECT id,name,is_head,active FROM od_branches ORDER BY is_head DESC,name');}
function staff_profile_info(string $uid):array{
 $u=one('SELECT id,name,email FROM od_accounts WHERE id=?',[$uid]);if(!$u)fail('ইউজার পাওয়া যায়নি।',404);
 return ['user'=>$u,'assignment'=>staff_assignment($uid),'profile'=>one('SELECT * FROM od_identity_profiles WHERE account_id=?',[$uid]),'history'=>rows('SELECT revision,action,actor_name,actor_email,note,created_at FROM od_profile_events WHERE account_id=? ORDER BY sequence',[$uid])];
}
function staff_get(array $a):never{
 $mode=$_GET['staff']??'self';
 if($mode==='file'){
  $f=one('SELECT * FROM od_identity_files WHERE id=?',[id_input($_GET,'id')]);if(!$f)fail('ফাইল পাওয়া যায়নি।',404);staff_assert_account($a,$f['account_id']);
  $plain=openssl_decrypt($f['cipher'],'aes-256-gcm',hash('sha256','office-identity-files|'.config()['app_secret'],true),OPENSSL_RAW_DATA,$f['nonce'],$f['tag'],$f['account_id'].'|'.$f['kind']);if($plain===false)fail('ফাইল খোলা যায়নি।',500);
  header('Content-Type: '.$f['mime']);header('Content-Length: '.strlen($plain));header("Content-Security-Policy: default-src 'none'; sandbox");
  header('Content-Disposition: '.($f['kind']==='photo'?'inline':'attachment').'; filename="'.$f['kind'].($f['mime']==='application/pdf'?'.pdf':($f['mime']==='image/png'?'.png':($f['mime']==='image/webp'?'.webp':'.jpg'))).'"');echo $plain;exit;
 }
 if($mode==='self')output([...staff_profile_info($a['userId']),'branches'=>staff_branches(),'actor'=>$a]);
 if($mode==='branches'){hr_owner($a);output(['branches'=>staff_branches()]);}
 if(!$a['canBrowseStaff'])fail('কর্মী তালিকা দেখার অনুমতি নেই।',403);
 if($mode==='detail'){$uid=id_input($_GET,'accountId');staff_assert_account($a,$uid);output([...staff_profile_info($uid),'canReview'=>staff_can_review($a,$uid)]);}
 if($mode!=='list')fail('সঠিক স্টাফ বিভাগ দিন।');
 $where='1=1';$args=[];if(!$a['globalRead']){$where='s.branch_id=?';$args[]=$a['branchId'];}
 $items=rows("SELECT a.id,a.name,a.email,a.is_owner,s.post,s.branch_id,b.name AS branch_name,p.status AS profile_status,p.revision,p.full_name,COALESCE(m.active,a.is_owner) AS active FROM od_accounts a LEFT JOIN od_staff_assignments s ON s.account_id=a.id LEFT JOIN od_branches b ON b.id=s.branch_id LEFT JOIN od_identity_profiles p ON p.account_id=a.id LEFT JOIN od_members m ON m.user_id=a.id WHERE $where ORDER BY p.status='pending' DESC,a.name",$args);
 output(['users'=>$items,'branches'=>$a['globalRead']?staff_branches():array_values(array_filter(staff_branches(),fn($b)=>$b['id']===$a['branchId'])),'canAssign'=>$a['role']==='owner']);
}
function staff_can_review(array $a,string $uid):bool{
 if($a['userId']===$uid)return false;
 return $a['role']==='owner'||($a['manageBranch']&&staff_account_allowed($a,$uid));
}
function staff_file(array $v,string $key,string $uid,string $kind,?string $existing):string{
 $raw=$v[$key]??'';if($raw===''&&$existing)return $existing;
 if(!is_string($raw)||!preg_match('#^data:([^;]+);base64,([A-Za-z0-9+/=]+)$#D',$raw,$m))fail('ছবি ও NID আপলোড করুন।');
 $data=base64_decode($m[2],true);$max=$kind==='photo'?1048576:3145728;
 if($data===false||strlen($data)>$max||strlen($data)<8)fail('প্রোফাইল ছবি সর্বোচ্চ ১ MB এবং NID সর্বোচ্চ ৩ MB হতে হবে।');
 $size=@getimagesizefromstring($data);$mime=$size['mime']??(str_starts_with($data,'%PDF-')?'application/pdf':'application/octet-stream');
 if(in_array($mime,['image/jpeg','image/png','image/webp'],true)){$size=@getimagesizefromstring($data);if(!$size||$size[0]>6000||$size[1]>6000)fail('সঠিক ও সীমিত আকারের ছবি দিন।');}
 elseif(!($kind==='nid'&&$mime==='application/pdf'&&str_starts_with($data,'%PDF-')))fail('ছবির জন্য JPG/PNG/WebP এবং NID-এর জন্য ছবি বা PDF দিন।');
 $id=uid();$nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($data,'aes-256-gcm',hash('sha256','office-identity-files|'.config()['app_secret'],true),OPENSSL_RAW_DATA,$nonce,$tag,$uid.'|'.$kind);
 if($cipher===false)fail('ফাইল সংরক্ষণ করা যায়নি।',500);
 query('INSERT INTO od_identity_files(id,account_id,kind,mime,nonce,tag,cipher,created_at) VALUES(?,?,?,?,?,?,?,?)',[$id,$uid,$kind,$mime,$nonce,$tag,$cipher,now()]);return $id;
}
function staff_action(array $a,array $v,string $action):bool{
 if(!str_starts_with($action,'staff_')&&!str_starts_with($action,'profile_'))return false;
 if($action==='staff_branch'){
  hr_owner($a);$name=strval_input($v,'name',100);$id=isset($v['id'])?id_input($v,'id'):uid();
  $active=bool_input($v,'active');$head=bool_input($v,'isHead');
  transaction(function()use($id,$name,$active,$head){rows('SELECT id FROM od_branches FOR UPDATE');$old=one('SELECT * FROM od_branches WHERE id=?',[$id]);if($old&&(int)$old['is_head']===1&&(!$head||!$active))fail('হেড অফিস বন্ধ করা যাবে না।');if($head&&(!$active||one('SELECT id FROM od_branches WHERE is_head=1 AND id<>?',[$id])))fail('একটি সক্রিয় হেড অফিস থাকতে হবে।');query('INSERT INTO od_branches(id,name,is_head,active) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),active=VALUES(active),is_head=VALUES(is_head)',[$id,$name,$head,$active]);});output(['ok'=>true]);
 }
 if($action==='staff_assign'){
  hr_owner($a);$u=hr_target($v);$branch=id_input($v,'branchId');$post=strval_input($v,'post',20);
  transaction(function()use($a,$u,$branch,$post){one('SELECT id FROM od_accounts WHERE id=? FOR UPDATE',[$u['id']]);staff_assign($a,$u['id'],$branch,$post);query('UPDATE od_accounts SET session_version=session_version+1 WHERE id=?',[$u['id']]);});
  if($u['id']===$a['userId']){$u=one('SELECT id,session_version FROM od_accounts WHERE id=?',[$u['id']]);sign_in($u);}output(['ok'=>true]);
 }
 if($action==='profile_submit'){
  $uid=$a['userId'];$s=staff_assignment($uid);if(!$s||(int)$s['branch_active']!==1)fail('অ্যাডমিনকে আগে ব্রাঞ্চ ও পোস্ট নির্ধারণ করতে বলুন।',409);
  $name=strval_input($v,'fullName',100);$dob=day_input(['date'=>$v['birthDate']??'']);if($dob>=today()||$dob<'1900-01-01')fail('সঠিক জন্ম তারিখ দিন।');$branch=id_input($v,'branchId');if($branch!==$s['branch_id'])fail('ব্রাঞ্চ পরিবর্তনের জন্য অ্যাডমিনের অনুমতি প্রয়োজন।',403);
  transaction(function()use($a,$v,$uid,$name,$dob,$branch){$current=one('SELECT branch_id FROM od_staff_assignments WHERE account_id=? FOR UPDATE',[$uid]);if(!$current||$current['branch_id']!==$branch)fail('ব্রাঞ্চ বদলেছে। রিফ্রেশ করে আবার জমা দিন।',409);$old=one('SELECT * FROM od_identity_profiles WHERE account_id=? FOR UPDATE',[$uid]);$expected=$v['expectedRevision']??0;if(!is_int($expected)||$expected!==(int)($old['revision']??0))fail('প্রোফাইল বদলেছে। রিফ্রেশ করুন।',409);
   $photo=staff_file($v,'photo',$uid,'photo',$old['photo_id']??null);$nid=staff_file($v,'nid',$uid,'nid',$old['nid_id']??null);$rev=(int)($old['revision']??0)+1;
   query("INSERT INTO od_identity_profiles(account_id,full_name,birth_date,photo_id,nid_id,branch_id,status,revision,submitted_at) VALUES(?,?,?,?,?,?,'pending',?,?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),birth_date=VALUES(birth_date),photo_id=VALUES(photo_id),nid_id=VALUES(nid_id),branch_id=VALUES(branch_id),status='pending',revision=VALUES(revision),submitted_at=VALUES(submitted_at)",[$uid,$name,$dob,$photo,$nid,$branch,$rev,now()]);
   query('INSERT INTO od_profile_events(account_id,revision,action,actor_id,actor_name,actor_email,note,snapshot,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$uid,$rev,'submitted',$uid,$a['name'],$a['email'],'',json_encode(['fullName'=>$name,'birthDate'=>$dob,'photo'=>$photo,'nid'=>$nid,'branchId'=>$branch],JSON_UNESCAPED_UNICODE),now()]);
  });output(['ok'=>true]);
 }
 if($action==='profile_review'){
  $uid=id_input($v,'accountId');if(!staff_can_review($a,$uid))fail('নিজের বা অন্য ব্রাঞ্চের প্রোফাইল অনুমোদন করা যাবে না।',403);
  $decision=$v['decision']??'';if(!in_array($decision,['approved','rejected'],true))fail('সঠিক সিদ্ধান্ত দিন।');$note=strval_input($v,'note',1000,$decision==='approved');
  transaction(function()use($a,$v,$uid,$decision,$note){$s=one('SELECT * FROM od_staff_assignments WHERE account_id=? FOR UPDATE',[$uid]);$p=one('SELECT * FROM od_identity_profiles WHERE account_id=? FOR UPDATE',[$uid]);
   if(!$p||$p['status']!=='pending'||$p['branch_id']!==($s['branch_id']??null)||($v['expectedRevision']??null)!==(int)$p['revision'])fail('অনুরোধের অবস্থা বদলেছে। রিফ্রেশ করুন।',409);
   if(!staff_can_review($a,$uid))fail('অনুমতি নেই।',403);
   query('UPDATE od_identity_profiles SET status=? WHERE account_id=?',[$decision,$uid]);
   query('INSERT INTO od_profile_events(account_id,revision,action,actor_id,actor_name,actor_email,note,snapshot,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$uid,$p['revision'],$decision,$a['userId'],$a['name'],$a['email'],$note,'',now()]);
   if($decision==='approved'){query('UPDATE od_accounts SET name=? WHERE id=?',[$p['full_name'],$uid]);query('UPDATE od_members SET name=? WHERE user_id=?',[$p['full_name'],$uid]);}
  });output(['ok'=>true]);
 }
 fail('সঠিক স্টাফ অনুরোধ দিন।');
}
