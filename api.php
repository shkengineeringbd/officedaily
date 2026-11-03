<?php
declare(strict_types=1);
define('OFFICE_APP',true);
require __DIR__.'/office-private/bootstrap.php';
headers_secure();
try {
    session_boot();
    if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
        if (($_GET['registration']??'')==='1') output(registration_state());
        $a=actor(); $owner=$a['role']==='owner';
        if (isset($_GET['task'])) {
            $task=visible($a,id_input($_GET,'task'));
            output(['task'=>$task,'reports'=>rows('SELECT * FROM od_reports WHERE task_id=? ORDER BY date DESC LIMIT 365',[$task['id']]),'comments'=>rows('SELECT * FROM od_comments WHERE task_id=? ORDER BY created_at DESC LIMIT 500',[$task['id']]),'today'=>today()]);
        }
        $where='1=1'; $args=[today()];
        if (!$owner && $a['viewAll']!==1) { $where='(t.assignee_id=? OR t.assignee_id IN (SELECT target_id FROM od_grants WHERE viewer_id=?))'; array_push($args,$a['id'],$a['id']); }
        $tasks=rows("SELECT t.*,COALESCE(m.name,'মালিক') AS assignee_name,(SELECT COUNT(*) FROM od_reports r WHERE r.task_id=t.id AND r.date=?) AS reported_today FROM od_tasks t LEFT JOIN od_members m ON m.id=t.assignee_id WHERE $where ORDER BY t.date DESC,t.created_at DESC LIMIT 1000",$args);
        foreach($tasks as &$t) $t['reported_today']=(int)$t['reported_today']; unset($t);
        $members=$owner?rows('SELECT id,email,name,role,view_all,active FROM od_members ORDER BY name'):[];
        foreach($members as &$m) { $m['view_all']=(int)$m['view_all']; $m['active']=(int)$m['active']; } unset($m);
        output(['actor'=>$a,'today'=>today(),'tasks'=>$tasks,'members'=>$members,'grants'=>$owner?rows('SELECT viewer_id,target_id FROM od_grants'):[],'scope'=>grants($a),'audit'=>$owner?rows('SELECT action,target,created_at,actor_id FROM od_audit ORDER BY created_at DESC LIMIT 80'):[],'registrations'=>$owner?rows("SELECT id,email,name,status,created_at,reviewed_at FROM od_registration_requests ORDER BY CASE WHEN status='pending' THEN 0 ELSE 1 END,created_at DESC LIMIT 1000"):[],'limit'=>1000]);
    }
    $v=require_post(); $action=strval_input($v,'action',40); $n=now();
    if ($action==='login') {
        $email=email_input($v); $password=$v['password']??'';
        if (!is_string($password) || strlen($password)>72 || str_contains($password,"\0")) fail('ইমেইল অথবা পাসওয়ার্ড সঠিক নয়।',401);
        throttle('login-ip',$_SERVER['REMOTE_ADDR']??'unknown',40); throttle('login-account',$email,12);
        $u=one('SELECT * FROM od_accounts WHERE email=?',[$email]);
        // Same password work even when the account does not exist.
        $hash=$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if (!password_verify($password,$hash) || !$u) fail('ইমেইল অথবা পাসওয়ার্ড সঠিক নয়।',401);
        if (password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)) query('UPDATE od_accounts SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
        sign_in($u); output(['ok'=>true]);
    }
    if ($action==='signup') {
        $email=email_input($v); $name=strval_input($v,'name',100); $password=password_input($v);
        throttle('signup-ip',$_SERVER['REMOTE_ADDR']??'unknown',12);
        if (one('SELECT id FROM od_accounts WHERE email=?',[$email])) fail('এই ইমেইল ইতিমধ্যে আছে।');
        $uid=uid(); $rid=uid(); $hash=password_hash($password,PASSWORD_DEFAULT);
        transaction(function() use($uid,$email,$name,$hash,$rid,$n) {
            query('INSERT INTO od_accounts (id,email,name,password_hash,is_owner,session_version,created_at) VALUES (?,?,?,?,0,1,?)',[$uid,$email,$name,$hash,$n]);
            query("INSERT INTO od_registration_requests (id,user_id,email,name,status,created_at) VALUES (?,?,?,?,'pending',?)",[$rid,$uid,$email,$name,$n]);
        });
        sign_in(['id'=>$uid,'session_version'=>1]); output(['ok'=>true,'state'=>'pending']);
    }
    if ($action==='logout') { $_SESSION=[]; session_regenerate_id(true); output(['ok'=>true]); }
    if ($action==='change_password') {
        $u=require_account(); $current=$v['currentPassword']??''; $new=password_input($v,'newPassword');
        if (!is_string($current) || !password_verify($current,$u['password_hash'])) fail('বর্তমান পাসওয়ার্ড সঠিক নয়।',403);
        query('UPDATE od_accounts SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$u['id']]);
        $u['session_version']=(int)$u['session_version']+1; sign_in($u); output(['ok'=>true]);
    }
    if ($action==='register') {
        $u=require_account(); $state=registration_state();
        if (!in_array($state['state'],['new','pending'],true)) fail('এই অ্যাকাউন্টের অনুরোধ আবার পাঠানো যাবে না।',409);
        $name=strval_input($v,'name',100);
        query("INSERT INTO od_registration_requests (id,user_id,email,name,status,created_at) VALUES (?,?,?,?,'pending',?) ON DUPLICATE KEY UPDATE name=VALUES(name)",[uid(),$u['id'],$u['email'],$name,$n]); output(['ok'=>true]);
    }
    $a=actor();
    if (!in_array($action,['member','task','status','approve','reject','report','comment'],true)) fail('সঠিক তথ্য দিন।');
    if (in_array($action,['member','task','status','approve','reject'],true) && $a['role']!=='owner') fail('শুধু মালিক এই পরিবর্তন করতে পারেন।',403);
    if ($action==='approve' || $action==='reject') {
        $rid=id_input($v,'requestId'); $targets=$action==='approve'?targets_input($v):[];
        $name=$action==='approve'?strval_input($v,'name',100):''; $role=$action==='approve'?role_input($v):'officer'; $all=$action==='approve'?bool_input($v,'viewAll'):0;
        transaction(function() use($action,$rid,$targets,$name,$role,$all,$a,$n) {
            $r=one('SELECT * FROM od_registration_requests WHERE id=? FOR UPDATE',[$rid]);
            if (!$r) fail('রেজিস্ট্রেশনের অনুরোধ পাওয়া যায়নি।',404);
            if ($r['status']==='approved') fail('অনুরোধটি ইতিমধ্যে অনুমোদিত। কর্মকর্তার অনুমতি থেকে পরিবর্তন করুন।',409);
            if (one('SELECT id FROM od_accounts WHERE id=? AND is_owner=1',[$r['user_id']])) fail('মালিকের অনুমতি পরিবর্তন করা যাবে না।');
            $status=$action==='approve'?'approved':'rejected';
            query('UPDATE od_registration_requests SET status=?,reviewed_at=?,reviewed_by=? WHERE id=?',[$status,$n,$a['id'],$rid]);
            if ($action==='approve') {
                $m=one('SELECT id,user_id FROM od_members WHERE email=? FOR UPDATE',[$r['email']]);
                if ($m && $m['user_id'] && $m['user_id']!==$r['user_id']) fail('অ্যাকাউন্ট মেলেনি।',409);
                $mid=$m['id']??uid();
                query('INSERT INTO od_members (id,email,user_id,name,role,view_all,active) VALUES (?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),name=VALUES(name),role=VALUES(role),view_all=VALUES(view_all),active=1',[$mid,$r['email'],$r['user_id'],$name,$role,$all]);
                save_grants($mid,$targets);
            }
            audit($a,$action==='approve'?'রেজিস্ট্রেশন অনুমোদন':'রেজিস্ট্রেশন প্রত্যাখ্যান',$rid);
        }); output(['ok'=>true]);
    }
    if ($action==='member') {
        $email=email_input($v); $name=strval_input($v,'name',100); $role=role_input($v); $all=bool_input($v,'viewAll'); $active=bool_input($v,'active'); $targets=targets_input($v);
        $mid=isset($v['id'])?id_input($v,'id'):uid(); $exists=isset($v['id']);
        if (one('SELECT id FROM od_accounts WHERE email=? AND is_owner=1',[$email])) fail('মালিকের অনুমতি পরিবর্তন করা যাবে না।');
        $newPassword=isset($v['newPassword']) && $v['newPassword']!==''?password_input($v,'newPassword'):null;
        transaction(function() use($v,$email,$name,$role,$all,$active,$targets,$mid,$exists,$a,$newPassword) {
            $old=one('SELECT * FROM od_members WHERE id=? FOR UPDATE',[$mid]);
            if ($exists && !$old) fail('কর্মকর্তা নেই।',404);
            if ($old && $old['email']!==$email) fail('অ্যাকাউন্টের ইমেইল বদলানো যাবে না। নতুন কর্মকর্তা যোগ করুন।');
            if (!$exists && one('SELECT id FROM od_members WHERE email=?',[$email])) fail('এই ইমেইল ইতিমধ্যে আছে।');
            // Pre-added emails do not grant a newly claimed account access: owner must approve registration.
            query('INSERT INTO od_members (id,email,name,role,view_all,active) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),role=VALUES(role),view_all=VALUES(view_all),active=VALUES(active)',[$mid,$email,$name,$role,$all,$active]);
            save_grants($mid,$targets);
            if ($newPassword!==null) {
                if (!$old || !$old['user_id']) fail('আগে রেজিস্ট্রেশন অনুমোদন করুন।');
                query('UPDATE od_accounts SET password_hash=?,session_version=session_version+1 WHERE id=? AND is_owner=0',[password_hash($newPassword,PASSWORD_DEFAULT),$old['user_id']]);
            }
            if ($old && !$active && $old['user_id']) query('UPDATE od_accounts SET session_version=session_version+1 WHERE id=?',[$old['user_id']]);
            audit($a,'অনুমতি আপডেট',$name);
        }); output(['ok'=>true]);
    }
    if ($action==='task') {
        $mid=id_input($v,'assigneeId'); $date=day_input($v); $title=strval_input($v,'title',200); $details=strval_input($v,'details',5000,true);
        if ($mid!=='owner' && !one('SELECT id FROM od_members WHERE id=? AND active=1',[$mid])) fail('সক্রিয় কর্মকর্তা বেছে নিন।');
        transaction(function() use($v,$mid,$date,$title,$details,$a,$n) {
            if (isset($v['id'])) { $id=id_input($v,'id'); $old=visible($a,$id); if ($old['assignee_id']!==$mid || $old['date']!==$date) fail('পুরোনো কাজের কর্মকর্তা ও তারিখ বদলানো যাবে না।'); query('UPDATE od_tasks SET title=?,details=?,updated_at=? WHERE id=?',[$title,$details,$n,$id]); audit($a,'কাজ সম্পাদনা',$id); }
            else { $id=uid(); query("INSERT INTO od_tasks (id,assignee_id,title,details,date,status,created_at,updated_at) VALUES (?,?,?,?,?,'Remaining',?,?)",[$id,$mid,$title,$details,$date,$n,$n]); audit($a,'কাজ বরাদ্দ',$id); }
        }); output(['ok'=>true]);
    }
    if ($action==='status') {
        $id=id_input($v,'id'); visible($a,$id); $status=$v['status']??'';
        if (!in_array($status,['Remaining','In Progress','Completed'],true)) fail('সঠিক তথ্য দিন।');
        transaction(function() use($id,$status,$n,$a) { query('UPDATE od_tasks SET status=?,updated_at=? WHERE id=?',[$status,$n,$id]); audit($a,'স্ট্যাটাস: '.$status,$id); }); output(['ok'=>true]);
    }
    if ($action==='report') {
        $tid=id_input($v,'taskId'); $task=visible($a,$tid); $date=day_input($v); $content=strval_input($v,'content');
        if ($a['id']!==$task['assignee_id'] || $task['date']>today() || $date!==today()) fail('শুধু নিজের আজকের আপডেট লেখা বা সম্পাদনা করা যাবে।',403);
        transaction(function() use($a,$tid,$date,$content,$n) {
            // The DB predicate is evaluated at write time, including a request that crosses midnight.
            $s=query("INSERT INTO od_reports (id,task_id,author_id,date,content,updated_at) SELECT ?,?,?,?,?,? FROM DUAL WHERE ?=DATE_FORMAT(UTC_TIMESTAMP()+INTERVAL 6 HOUR,'%Y-%m-%d') ON DUPLICATE KEY UPDATE content=IF(date=DATE_FORMAT(UTC_TIMESTAMP()+INTERVAL 6 HOUR,'%Y-%m-%d'),VALUES(content),content),updated_at=IF(date=DATE_FORMAT(UTC_TIMESTAMP()+INTERVAL 6 HOUR,'%Y-%m-%d'),VALUES(updated_at),updated_at)",[uid(),$tid,$a['id'],$date,$content,$n,$date]);
            if ($date!==today()) fail('শুধু নিজের আজকের আপডেট লেখা বা সম্পাদনা করা যাবে।',403);
            audit($a,'দৈনিক আপডেট',$tid);
        }); output(['ok'=>true]);
    }
    if ($action==='comment') {
        $tid=id_input($v,'taskId'); visible($a,$tid); $content=strval_input($v,'content'); $cid=id_input($v,'requestId');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$cid)) fail('সঠিক তথ্য দিন।');
        transaction(function() use($a,$tid,$content,$cid,$n) {
            $old=one('SELECT task_id,author_id FROM od_comments WHERE id=?',[$cid]);
            if ($old) { if ($old['task_id']!==$tid || $old['author_id']!==$a['id']) fail('সঠিক তথ্য দিন।',409); return; }
            query('INSERT INTO od_comments (id,task_id,author_id,author_name,content,created_at) VALUES (?,?,?,?,?,?)',[$cid,$tid,$a['id'],$a['name'],$content,$n]); audit($a,'মন্তব্য',$tid);
        }); output(['ok'=>true]);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $code=$e instanceof RuntimeException && $e->getCode()>=400 && $e->getCode()<500?(int)$e->getCode():($e->getCode()===503?503:500);
    if ($code===500) error_log('Office Daily API error: '.get_class($e));
    output(['error'=>$code===500?'সেভ করা যায়নি। আবার চেষ্টা করুন।':$e->getMessage()],$code);
}
