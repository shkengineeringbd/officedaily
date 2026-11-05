<?php
declare(strict_types=1);
if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function people_storage(): void {
    $tables=[
    "CREATE TABLE IF NOT EXISTS od_hr_profiles (account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, phone VARCHAR(20) UNIQUE NULL, access_enabled TINYINT NOT NULL DEFAULT 1, basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0, medical_allowance DECIMAL(12,2) NOT NULL DEFAULT 0, other_allowance DECIMAL(12,2) NOT NULL DEFAULT 0, annual_leave INT NOT NULL DEFAULT 0, designation VARCHAR(100) NOT NULL DEFAULT '', joining_date CHAR(10) NULL, FOREIGN KEY(account_id) REFERENCES od_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS od_attendance (account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, work_date CHAR(10) NOT NULL, status VARCHAR(16) NOT NULL, check_in VARCHAR(30) NULL, check_out VARCHAR(30) NULL, note TEXT NOT NULL, updated_at VARCHAR(30) NOT NULL, PRIMARY KEY(account_id,work_date), FOREIGN KEY(account_id) REFERENCES od_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS od_leave_requests (id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, start_date CHAR(10) NOT NULL, end_date CHAR(10) NOT NULL, days INT NOT NULL, reason TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', created_at VARCHAR(30) NOT NULL, FOREIGN KEY(account_id) REFERENCES od_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS od_payroll (account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, month CHAR(7) NOT NULL, basic_salary DECIMAL(12,2) NOT NULL, medical_allowance DECIMAL(12,2) NOT NULL, other_allowance DECIMAL(12,2) NOT NULL, deductions DECIMAL(12,2) NOT NULL, net_salary DECIMAL(12,2) NOT NULL, payment_status VARCHAR(16) NOT NULL, note TEXT NOT NULL, updated_at VARCHAR(30) NOT NULL, PRIMARY KEY(account_id,month), FOREIGN KEY(account_id) REFERENCES od_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS od_password_recovery (id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE, expires_at BIGINT NULL, created_at VARCHAR(30) NOT NULL, reviewed_at VARCHAR(30) NULL, KEY recovery_account(account_id,status), FOREIGN KEY(account_id) REFERENCES od_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach($tables as $sql) query($sql);
}
function phone_input(mixed $raw): string {
    if(!is_string($raw)) fail('সঠিক মোবাইল নম্বর দিন।');
    $raw=strtr($raw,['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
    $p=preg_replace('/[\s().-]/','',trim($raw));
    if(preg_match('/^01[3-9][0-9]{8}$/',$p)) $p='+88'.$p;
    elseif(preg_match('/^8801[3-9][0-9]{8}$/',$p)) $p='+'.$p;
    if(!preg_match('/^\+[1-9][0-9]{7,14}$/',$p)) fail('সঠিক মোবাইল নম্বর দিন।');
    return $p;
}
function login_account(string $identifier): ?array {
    if(str_contains($identifier,'@')) return one('SELECT * FROM od_accounts WHERE email=?',[strtolower(trim($identifier))]);
    try{$p=phone_input($identifier);}catch(RuntimeException $e){return null;}
    return one('SELECT a.* FROM od_accounts a JOIN od_hr_profiles h ON h.account_id=a.id WHERE h.phone=?',[$p]);
}
function access_enabled(string $uid): bool {
    $h=one('SELECT access_enabled FROM od_hr_profiles WHERE account_id=?',[$uid]);
    return !$h || (int)$h['access_enabled']===1;
}
function hr_owner(array $a): void {if($a['role']!=='owner')fail('শুধু অ্যাডমিন এই পরিবর্তন করতে পারেন।',403);}
function hr_target(array $v): array {
    $id=id_input($v,'accountId');$u=one('SELECT id,email,name,is_owner,session_version FROM od_accounts WHERE id=?',[$id]);
    if(!$u) fail('ইউজার পাওয়া যায়নি।',404);return $u;
}
function hr_touch(string $id): void {query('INSERT IGNORE INTO od_hr_profiles (account_id) VALUES (?)',[$id]);}
function money_input(array $v,string $key): string {
    $s=$v[$key]??null;
    if(!is_string($s) && !is_int($s))fail('বেতনের সঠিক পরিমাণ দিন।');
    $s=(string)$s;if(!preg_match('/^\d{1,9}(\.\d{1,2})?$/',$s))fail('বেতনের সঠিক পরিমাণ দিন।');
    return number_format((float)$s,2,'.','');
}
function hr_profiles(string $where,array $args,string $year):array{
    $list=rows("SELECT a.id,a.email,a.name,a.is_owner,h.phone,COALESCE(h.access_enabled,1) AS access_enabled,
      COALESCE(h.basic_salary,0.00) AS basic_salary,COALESCE(h.medical_allowance,0.00) AS medical_allowance,COALESCE(h.other_allowance,0.00) AS other_allowance,
      COALESCE(h.annual_leave,0) AS annual_leave,COALESCE(h.designation,'') AS designation,h.joining_date,
      CASE WHEN a.is_owner=1 THEN 'approved' WHEN m.id IS NOT NULL THEN IF(m.active=1,'approved','disabled') ELSE COALESCE(r.status,'new') END AS registration_status,
      COALESCE(l.used,0) AS leave_used,GREATEST(0,COALESCE(h.annual_leave,0)-COALESCE(l.used,0)) AS leave_remaining
      FROM od_accounts a LEFT JOIN od_hr_profiles h ON h.account_id=a.id LEFT JOIN od_members m ON m.user_id=a.id
      LEFT JOIN od_registration_requests r ON r.user_id=a.id
      LEFT JOIN (SELECT account_id,SUM(days) AS used FROM od_leave_requests WHERE status='approved' AND start_date>=? AND end_date<=? GROUP BY account_id) l ON l.account_id=a.id
      WHERE $where ORDER BY a.is_owner DESC,a.name",[$year.'-01-01',$year.'-12-31',...$args]);
    foreach($list as &$u)foreach(['is_owner','access_enabled','annual_leave','leave_used','leave_remaining'] as $key)$u[$key]=(int)$u[$key];unset($u);
    return $list;
}
function hr_summary(string $uid,string $year):array{
    $items=hr_profiles('a.id=?',[$uid],$year);if(!$items)fail('ইউজার পাওয়া যায়নি।',404);return $items[0];
}
function hr_get(array $a): never {
    $year=substr(today(),0,4);$month=$_GET['month']??substr(today(),0,7);
    if(!is_string($month)||!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)) fail('সঠিক মাস বেছে নিন।');
    $uid=$a['userId'];$owner=$a['role']==='owner';$target=$uid;
    if(isset($_GET['accountId'])) {$target=id_input($_GET,'accountId');staff_assert_account($a,$target);}
    if(!one('SELECT id FROM od_accounts WHERE id=?',[$target]))fail('ইউজার নেই।',404);
    $users=[];if($a['canBrowseStaff'])$users=$a['globalRead']?hr_profiles('1=1',[],$year):hr_profiles('EXISTS(SELECT 1 FROM od_staff_assignments s WHERE s.account_id=a.id AND s.branch_id=?)',[$a['branchId']],$year);
    output(['today'=>today(),'month'=>$month,'year'=>$year,'profile'=>hr_summary($target,$year),'users'=>$users,
      'attendance'=>rows('SELECT * FROM od_attendance WHERE account_id=? AND work_date LIKE ? ORDER BY work_date DESC',[$target,$month.'-%']),
      'payroll'=>rows('SELECT * FROM od_payroll WHERE account_id=? ORDER BY month DESC LIMIT 36',[$target]),
      'leaves'=>rows('SELECT * FROM od_leave_requests WHERE account_id=? ORDER BY created_at DESC LIMIT 100',[$target]),
      'recoveries'=>$owner?rows("SELECT r.id,r.status,r.expires_at,r.created_at,a.name,a.email,h.phone FROM od_password_recovery r JOIN od_accounts a ON a.id=r.account_id LEFT JOIN od_hr_profiles h ON h.account_id=a.id WHERE r.status IN ('pending','approved') ORDER BY r.created_at DESC LIMIT 100"):[]]);
}
function recovery_request(array $v): never {
    $id=strval_input($v,'identifier',254);throttle('recovery-ip',$_SERVER['REMOTE_ADDR']??'unknown',8);
    $u=login_account($id);
    if($u) transaction(function()use($u){
        one('SELECT id FROM od_accounts WHERE id=? FOR UPDATE',[$u['id']]);
        if(!one("SELECT id FROM od_password_recovery WHERE account_id=? AND (status='pending' OR (status='approved' AND expires_at>?))",[$u['id'],time()]))
          query('INSERT INTO od_password_recovery (id,account_id,created_at) VALUES (?,?,?)',[uid(),$u['id'],now()]);
    });
    output(['ok'=>true,'message'=>'তথ্য মিললে অ্যাডমিনের কাছে অনুরোধ যাবে। অনুমোদনের পর লিংকের জন্য অ্যাডমিনের সঙ্গে যোগাযোগ করুন।']);
}
function recovery_reset(array $v): never {
    $token=strval_input($v,'token',128);$p=password_input($v);
    throttle('reset-ip',$_SERVER['REMOTE_ADDR']??'unknown',12);
    if(!preg_match('/^[a-f0-9]{64}$/',$token))fail('লিংকটি সঠিক নয় বা মেয়াদ শেষ।',400);
    transaction(function()use($token,$p){
      $r=one("SELECT * FROM od_password_recovery WHERE token_hash=? AND status='approved' FOR UPDATE",[hash('sha256',$token)]);
      if(!$r || (int)$r['expires_at']<time())fail('লিংকটি সঠিক নয় বা মেয়াদ শেষ।',400);
      query('UPDATE od_accounts SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($p,PASSWORD_DEFAULT),$r['account_id']]);
      query("UPDATE od_password_recovery SET status='used',token_hash=NULL WHERE id=?",[$r['id']]);
      query("UPDATE od_password_recovery SET status='revoked',token_hash=NULL WHERE account_id=? AND status IN ('pending','approved')",[$r['account_id']]);
    });$_SESSION=[];session_regenerate_id(true);output(['ok'=>true]);
}
function people_action(array $a,array $v,string $action): bool {
    if($action==='user_access') {
      hr_owner($a);$u=hr_target($v);if((int)$u['is_owner']===1)fail('অ্যাডমিনের অ্যাক্সেস বন্ধ করা যাবে না।');$enabled=bool_input($v,'enabled');
      transaction(function()use($u,$enabled,$a){hr_touch($u['id']);query('UPDATE od_hr_profiles SET access_enabled=? WHERE account_id=?',[$enabled,$u['id']]);query('UPDATE od_members SET active=? WHERE user_id=?',[$enabled,$u['id']]);query('UPDATE od_accounts SET session_version=session_version+1 WHERE id=?',[$u['id']]);audit($a,$enabled?'অ্যাক্সেস পুনরায় চালু':'অ্যাক্সেস বাতিল',$u['email']);});return true;
    }
    if($action==='delete_prepare') {
      hr_owner($a);$u=hr_target($v);if((int)$u['is_owner']===1)fail('অ্যাডমিন মুছা যাবে না।');
      $token=bin2hex(random_bytes(32));$_SESSION['delete_challenge']=['uid'=>$u['id'],'hash'=>hash('sha256',$token),'until'=>time()+300];
      output(['ok'=>true,'challenge'=>$token,'email'=>$u['email'],'warning'=>'প্রথম সতর্কতা: এই ইউজারের কাজ, রিপোর্ট, মন্তব্য, বেতন, উপস্থিতি ও ছুটির সব রেকর্ড স্থায়ীভাবে মুছে যাবে।']);
    }
    if($action==='user_delete') {
      hr_owner($a);$u=hr_target($v);if((int)$u['is_owner']===1)fail('অ্যাডমিন মুছা যাবে না।');
      $c=$_SESSION['delete_challenge']??[];$token=$v['challenge']??'';
      if(!is_string($token)||($c['uid']??'')!==$u['id']||($c['until']??0)<time()||!hash_equals($c['hash']??'',hash('sha256',$token)))fail('ডিলিটের প্রথম সতর্কতা আবার নিশ্চিত করুন।',403);
      if(($v['confirmEmail']??'')!==$u['email'])fail('নিশ্চিত করতে ইউজারের ইমেইল হুবহু লিখুন।');
      $admin=one('SELECT password_hash FROM od_accounts WHERE id=?',[$a['userId']]);throttle('delete-admin',$a['userId'],8);
      if(!is_string($v['adminPassword']??null)||!password_verify($v['adminPassword'],$admin['password_hash']))fail('অ্যাডমিন পাসওয়ার্ড সঠিক নয়।',403);
      daily_storage();transaction(function()use($u){
        $id=$u['id'];$m=one('SELECT id FROM od_members WHERE user_id=? OR email=? FOR UPDATE',[$id,$u['email']]);$mid=$m['id']??'';
        query("DELETE FROM od_audit WHERE target IN (SELECT id FROM od_tasks WHERE assignee_id=?) OR SUBSTRING_INDEX(target,' ',-1) IN (SELECT id FROM od_tasks WHERE assignee_id=?)",[$mid,$mid]);
        query('DELETE FROM od_audit WHERE target IN (SELECT id FROM od_leave_requests WHERE account_id=?) OR target IN (SELECT id FROM od_password_recovery WHERE account_id=?) OR target IN (SELECT id FROM od_registration_requests WHERE user_id=?)',[$id,$id,$id]);
        foreach(['od_comments','od_reports','od_daily_work'] as $table)query("DELETE FROM $table WHERE task_id IN (SELECT id FROM od_tasks WHERE assignee_id=?)",[$mid]);
        query('DELETE FROM od_comments WHERE author_id=?',[$mid]);query('DELETE FROM od_reports WHERE author_id=?',[$mid]);
        query('DELETE FROM od_tasks WHERE assignee_id=?',[$mid]);query('DELETE FROM od_grants WHERE viewer_id=? OR target_id=?',[$mid,$mid]);
        query("DELETE FROM od_audit WHERE actor_user_id=? OR actor_id=? OR target=? OR SUBSTRING_INDEX(target,' ',-1)=? OR SUBSTRING_INDEX(target,' ',-1)=?",[$id,$mid,$u['email'],$u['email'],$id]);
        query('DELETE FROM od_members WHERE user_id=? OR email=?',[$id,$u['email']]);query('DELETE FROM od_registration_requests WHERE user_id=?',[$id]);
        foreach(['od_attendance','od_leave_requests','od_payroll','od_password_recovery','od_hr_profiles'] as $table)query("DELETE FROM $table WHERE account_id=?",[$id]);
        query('DELETE FROM od_budget_roles WHERE account_id=?',[$id]);
        foreach(['od_identity_files','od_identity_profiles','od_staff_assignments','od_profile_events'] as $table)query("DELETE FROM $table WHERE account_id=?",[$id]);
        query('DELETE FROM od_chat_files WHERE sender_id=?',[$id]);query('DELETE FROM od_chat_messages WHERE sender_id=?',[$id]);query("UPDATE od_chat_members SET display_name='Former user' WHERE account_id=?",[$id]);
        query('DELETE FROM od_accounts WHERE id=? AND is_owner=0',[$id]);
      });unset($_SESSION['delete_challenge']);return true;
    }
    if($action==='hr_profile') {
      hr_owner($a);$u=hr_target($v);$phone=($v['phone']??'')===''?null:phone_input($v['phone']);
      if($phone && one('SELECT account_id FROM od_hr_profiles WHERE phone=? AND account_id<>?',[$phone,$u['id']]))fail('মোবাইল নম্বরটি অন্য ইউজারের আছে।');
      $basic=money_input($v,'basicSalary');$medical=money_input($v,'medicalAllowance');$other=money_input($v,'otherAllowance');
      $leave=filter_var($v['annualLeave']??null,FILTER_VALIDATE_INT);if($leave===false||$leave<0||$leave>366)fail('সঠিক ছুটির সংখ্যা দিন।');
      $title=strval_input($v,'designation',100,true);$joining=($v['joiningDate']??'')===''?null:day_input(['date'=>$v['joiningDate']]);
      hr_touch($u['id']);query('UPDATE od_hr_profiles SET phone=?,basic_salary=?,medical_allowance=?,other_allowance=?,annual_leave=?,designation=?,joining_date=? WHERE account_id=?',[$phone,$basic,$medical,$other,$leave,$title,$joining,$u['id']]);audit($a,'প্রোফাইল ও বেতন নির্ধারণ',$u['email']);return true;
    }
    if($action==='attendance') {
      $mode=$v['mode']??'';$date=day_input($v);$uid=$a['userId'];
      if($mode==='admin') {
        hr_owner($a);$u=hr_target($v);$uid=$u['id'];if($date>today())fail('ভবিষ্যতের উপস্থিতি দেওয়া যাবে না।');
        $status=$v['status']??'';if(!in_array($status,['Present','Absent','Leave','Holiday','Late'],true))fail('সঠিক উপস্থিতি বেছে নিন।');
        $note=strval_input($v,'note',1000,true);
        query('INSERT INTO od_attendance (account_id,work_date,status,note,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),note=VALUES(note),updated_at=VALUES(updated_at)',[$uid,$date,$status,$note,now()]);
      } else {
        if($date!==today())fail('শুধু আজকের উপস্থিতি দেওয়া যাবে।',403);
        transaction(function()use($uid,$date,$mode){
          one('SELECT id FROM od_accounts WHERE id=? FOR UPDATE',[$uid]);$r=one('SELECT * FROM od_attendance WHERE account_id=? AND work_date=? FOR UPDATE',[$uid,$date]);
          if($mode==='in') {
            if($r)fail('আজকের উপস্থিতি আগে থেকেই আছে। প্রয়োজনে অ্যাডমিনকে জানান।',409);
            query("INSERT INTO od_attendance (account_id,work_date,status,check_in,note,updated_at) VALUES (?,?,'Present',?,'',?)",[$uid,$date,now(),now()]);
          } elseif($mode==='out') {
            if(!$r||!$r['check_in']||$r['check_out'])fail('চেক-ইন ও চেক-আউটের অবস্থা যাচাই করুন।',409);
            query('UPDATE od_attendance SET check_out=?,updated_at=? WHERE account_id=? AND work_date=?',[now(),now(),$uid,$date]);
          }else fail('সঠিক উপস্থিতির অনুরোধ দিন।');
          if($date!==today())fail('দিন শেষ হয়েছে। পৃষ্ঠা রিফ্রেশ করুন।',403);
        });
      }audit($a,'উপস্থিতি',$date.' '.$uid);return true;
    }
    if($action==='leave_request') {
      $start=day_input(['date'=>$v['startDate']??'']);$end=day_input(['date'=>$v['endDate']??'']);
      if($start<today()||$end<$start||substr($start,0,4)!==substr($end,0,4))fail('একই বছরের সঠিক ছুটির তারিখ দিন।');
      $days=(int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days+1;$reason=strval_input($v,'reason',1000);
      transaction(function()use($a,$start,$end,$days,$reason){
        one('SELECT id FROM od_accounts WHERE id=? FOR UPDATE',[$a['userId']]);
        if(one("SELECT id FROM od_leave_requests WHERE account_id=? AND status IN ('pending','approved') AND start_date<=? AND end_date>=?",[$a['userId'],$end,$start]))fail('এই তারিখে ছুটির অনুরোধ আগে থেকেই আছে।');
        query('INSERT INTO od_leave_requests (id,account_id,start_date,end_date,days,reason,created_at) VALUES (?,?,?,?,?,?,?)',[uid(),$a['userId'],$start,$end,$days,$reason,now()]);
      });return true;
    }
    if($action==='leave_review') {
      hr_owner($a);$id=id_input($v,'id');$status=$v['status']??'';if(!in_array($status,['approved','rejected'],true))fail('সঠিক সিদ্ধান্ত দিন।');
      transaction(function()use($id,$status){$r=one('SELECT * FROM od_leave_requests WHERE id=? FOR UPDATE',[$id]);if(!$r||$r['status']!=='pending')fail('অনুরোধটি আর অপেক্ষায় নেই।',409);
        hr_touch($r['account_id']);one('SELECT account_id FROM od_hr_profiles WHERE account_id=? FOR UPDATE',[$r['account_id']]);
        $p=hr_summary($r['account_id'],substr($r['start_date'],0,4));if($status==='approved'&&(int)$r['days']>$p['leave_remaining'])fail('বরাদ্দ ছুটির চেয়ে বেশি। আগে বরাদ্দ ঠিক করুন।');
        query('UPDATE od_leave_requests SET status=? WHERE id=?',[$status,$id]);
      });audit($a,'ছুটির সিদ্ধান্ত',$id);return true;
    }
    if($action==='payroll') {
      hr_owner($a);$u=hr_target($v);$month=strval_input($v,'month',7);if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))fail('সঠিক মাস দিন।');
      $b=money_input($v,'basicSalary');$m=money_input($v,'medicalAllowance');$o=money_input($v,'otherAllowance');$d=money_input($v,'deductions');$net=round((float)$b+(float)$m+(float)$o-(float)$d,2);if($net<0)fail('কর্তন মোট বেতনের বেশি হতে পারবে না।');
      $state=$v['paymentStatus']??'';if(!in_array($state,['Unpaid','Paid'],true))fail('সঠিক পরিশোধ অবস্থা দিন।');$note=strval_input($v,'note',1000,true);
      query('INSERT INTO od_payroll (account_id,month,basic_salary,medical_allowance,other_allowance,deductions,net_salary,payment_status,note,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary),medical_allowance=VALUES(medical_allowance),other_allowance=VALUES(other_allowance),deductions=VALUES(deductions),net_salary=VALUES(net_salary),payment_status=VALUES(payment_status),note=VALUES(note),updated_at=VALUES(updated_at)',[$u['id'],$month,$b,$m,$o,$d,$net,$state,$note,now()]);audit($a,'বেতনের রেকর্ড',$month.' '.$u['email']);return true;
    }
    if($action==='recovery_review') {
      hr_owner($a);$id=id_input($v,'id');$decision=$v['decision']??'';if(!in_array($decision,['approve','reject'],true))fail('সঠিক সিদ্ধান্ত দিন।');
      $token=bin2hex(random_bytes(32));$expiry=time()+3600;
      transaction(function()use($id,$decision,$token,$expiry){$r=one('SELECT * FROM od_password_recovery WHERE id=? FOR UPDATE',[$id]);if(!$r||$r['status']!=='pending')fail('অনুরোধটি আর অপেক্ষায় নেই।',409);
        query('UPDATE od_password_recovery SET status=?,token_hash=?,expires_at=?,reviewed_at=? WHERE id=?',[$decision==='approve'?'approved':'rejected',$decision==='approve'?hash('sha256',$token):null,$decision==='approve'?$expiry:null,now(),$id]);
      });audit($a,'পাসওয়ার্ড রিকভারি '.$decision,$id);
      output(['ok'=>true,'link'=>$decision==='approve'?rtrim(config()['app_url'],'/').'/?reset='.rawurlencode($token):null,'expiresIn'=>3600]);
    }
    return false;
}
