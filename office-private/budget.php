<?php
declare(strict_types=1);
if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function budget_storage(): void {
    query("CREATE TABLE IF NOT EXISTS od_budget_roles (account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, role VARCHAR(16) NOT NULL, assigned_at VARCHAR(30) NOT NULL, assigned_by VARCHAR(36) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Financial records deliberately retain identity snapshots after staff access changes/deletion.
    query("CREATE TABLE IF NOT EXISTS od_budgets (id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, serial BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, creator_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, applicant_name VARCHAR(100) NOT NULL, applicant_email VARCHAR(254) NOT NULL, branch VARCHAR(100) NOT NULL, reason TEXT NOT NULL, amount DECIMAL(12,2) NOT NULL, method VARCHAR(10) NOT NULL, payment_details TEXT NOT NULL, stage TINYINT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'pending', submitted_on CHAR(10) NOT NULL, created_at VARCHAR(30) NOT NULL, released_on CHAR(10) NULL, release_reference VARCHAR(100) NULL, KEY budget_date(submitted_on,serial), KEY budget_stage(status,stage), KEY budget_branch(branch)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    query("CREATE TABLE IF NOT EXISTS od_budget_events (sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, budget_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, actor_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, actor_name VARCHAR(100) NOT NULL, actor_email VARCHAR(254) NOT NULL, actor_role VARCHAR(16) NOT NULL, action VARCHAR(16) NOT NULL, stage TINYINT NOT NULL, note TEXT NOT NULL, occurred_on CHAR(10) NOT NULL, created_at VARCHAR(30) NOT NULL, KEY budget_events(budget_id,sequence), FOREIGN KEY(budget_id) REFERENCES od_budgets(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    query("CREATE TABLE IF NOT EXISTS od_budget_role_events (sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, account_id VARCHAR(36) NOT NULL, account_email VARCHAR(254) NOT NULL, role VARCHAR(16) NOT NULL, actor_id VARCHAR(36) NOT NULL, actor_name VARCHAR(100) NOT NULL, created_at VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function budget_role(array $a): ?string {
    $post=$a['post']??null;
    if(in_array($post,['gm','cfo','md'],true))return $post;
    if($a['role']==='owner')return 'admin';
    return $post==='branch_manager'?'manager':null;
}
function budget_access(array $a): string {
    $r=budget_role($a);if(!in_array($r,['admin','manager','gm','cfo','md'],true)) fail('বাজেট দেখার অনুমতি নেই।',403);return $r;
}
function budget_code(array $b): string { return 'BR-'.str_pad((string)$b['serial'],6,'0',STR_PAD_LEFT); }
function budget_stage_role(int $stage): string { return ['gm','cfo','md','cfo'][$stage]??''; }
function budget_event(array $a,string $id,string $action,int $stage,string $note,string $role): void {
    query('INSERT INTO od_budget_events (budget_id,actor_id,actor_name,actor_email,actor_role,action,stage,note,occurred_on,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,$a['userId'],$a['name'],$a['email'],$role,$action,$stage,$note,today(),now()]);
}
function budget_shape(array $b): array { $b['code']=budget_code($b);$b['stage']=(int)$b['stage'];$b['serial']=(int)$b['serial'];return $b; }
function budget_ready(): array {
    $found=['gm'=>false,'cfo'=>false,'md'=>false];
    foreach(rows("SELECT s.post FROM od_staff_assignments s JOIN od_branches b ON b.id=s.branch_id JOIN od_accounts a ON a.id=s.account_id LEFT JOIN od_members m ON m.user_id=a.id LEFT JOIN od_hr_profiles h ON h.account_id=a.id LEFT JOIN od_identity_profiles p ON p.account_id=a.id WHERE b.is_head=1 AND b.active=1 AND COALESCE(h.access_enabled,1)=1 AND (a.is_owner=1 OR (m.active=1 AND p.status='approved' AND p.branch_id=s.branch_id))") as $r)if(array_key_exists($r['post'],$found))$found[$r['post']]=true;
    if($found['md'])$found['gm']=true; // MD may provide the GM approval as part of full approval.
    return $found;
}
function budget_uuid(array $v,string $key): string {
    $id=id_input($v,$key);if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$id))fail('সঠিক অনুরোধ দিন।');return strtolower($id);
}
function budget_payment(array $v): string {
    $method=$v['method']??'';
    if($method==='Bank') $p=['bank'=>strval_input($v,'bank',100),'accountName'=>strval_input($v,'accountName',100),'accountNumber'=>strval_input($v,'accountNumber',100)];
    elseif($method==='bKash')$p=['mobile'=>phone_input($v['mobile']??null)];
    elseif($method==='Cash')$p=['recipient'=>strval_input($v,'recipient',100)];
    else fail('ব্যাংক, বিকাশ অথবা ক্যাশ বেছে নিন।');
    return json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function budget_action(array $a,array $v,string $action): bool {
    if(!str_starts_with($action,'budget_'))return false;
    if($action==='budget_role') { hr_owner($a); fail('পোস্ট ও ব্রাঞ্চ স্টাফ অ্যাক্সেস বিভাগ থেকে নির্ধারণ করুন।',409); }
    $role=budget_access($a);
    if($action==='budget_submit') {
        $id=budget_uuid($v,'requestId');$name=strval_input($v,'name',100);$branchId=id_input($v,'branchId');$branchRow=one('SELECT name FROM od_branches WHERE id=? AND active=1',[$branchId]);if(!$branchRow)fail('সক্রিয় ব্রাঞ্চ বেছে নিন।');if(!$a['globalRead']&&$branchId!==$a['branchId'])fail('অন্য ব্রাঞ্চে বাজেট জমা দেওয়ার অনুমতি নেই।',403);$branch=$branchRow['name'];$reason=strval_input($v,'reason',5000);
        $amount=money_input($v,'amount');if((float)$amount<=0)fail('বাজেটের পরিমাণ শূন্যের বেশি হতে হবে।');$payment=budget_payment($v);$method=$v['method'];
        transaction(function()use($a,$id,$name,$branch,$branchId,$reason,$amount,$method,$payment,$role){
            one('SELECT id FROM od_accounts WHERE id=? FOR UPDATE',[$a['userId']]);
            $existing=one('SELECT * FROM od_budgets WHERE id=?',[$id]);
            if($existing){
                if($existing['creator_id']!==$a['userId']||$existing['applicant_name']!==$name||$existing['branch']!==$branch||$existing['reason']!==$reason||$existing['amount']!==$amount||$existing['method']!==$method||$existing['payment_details']!==$payment)fail('এই অনুরোধ নম্বর আগে ব্যবহার হয়েছে।',409);
                return;
            }
            if(in_array(false,budget_ready(),true))fail('অ্যাডমিনকে আগে সক্রিয় GM, CFO ও MD নির্ধারণ করতে বলুন।',409);
            query('INSERT INTO od_budgets (id,creator_id,applicant_name,applicant_email,branch,reason,amount,method,payment_details,submitted_on,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$id,$a['userId'],$name,$a['email'],$branch,$reason,$amount,$method,$payment,today(),now()]);
            query('INSERT INTO od_budget_branches(budget_id,branch_id) VALUES(?,?)',[$id,$branchId]);
            budget_event($a,$id,'submitted',0,'আবেদন জমা হয়েছে।',$role);
        });output(['ok'=>true,'id'=>$id]);
    }
    if($action==='budget_md_all') {
        if($role!=='md')fail('শুধু MD একসঙ্গে সব অনুমোদন দিতে পারবেন।',403);
        $id=budget_uuid($v,'id');$note=strval_input($v,'note',2000,true);$expected=$v['expectedStage']??null;
        transaction(function()use($a,$id,$note,$expected){
            $b=one('SELECT * FROM od_budgets WHERE id=? FOR UPDATE',[$id]);staff_budget_assert($a,$id);
            if(!$b||$b['status']!=='pending'||!is_int($expected)||(int)$b['stage']!==$expected||$expected>2)fail('অনুমোদনের ধাপ বদলেছে।',409);
            for($i=$expected;$i<3;$i++)budget_event($a,$id,'approved',$i,'MD কর্তৃক একসঙ্গে অনুমোদন — '.['GM ধাপ','CFO ধাপ','MD ধাপ'][$i].($note!==''?' | '.$note:''),'md');
            query('UPDATE od_budgets SET stage=3 WHERE id=?',[$id]);
        });output(['ok'=>true,'id'=>$id]);
    }
    if($action==='budget_decide'||$action==='budget_release') {
        $id=budget_uuid($v,'id');$expected=$v['expectedStage']??null;if(!is_int($expected)||$expected<0||$expected>3)fail('সঠিক অনুমোদন ধাপ দিন।');
        transaction(function()use($a,$v,$id,$expected,$action){
            $b=one('SELECT * FROM od_budgets WHERE id=? FOR UPDATE',[$id]);if(!$b)fail('বাজেট পাওয়া যায়নি।',404);
            staff_budget_assert($a,$id);
            $role=budget_access($a);
            if($b['status']!=='pending'||(int)$b['stage']!==$expected)fail('বাজেটের ধাপ বদলেছে। রিফ্রেশ করে দেখুন।',409);
            if($role!==budget_stage_role($expected))fail('এই ধাপে অনুমোদন বা রিলিজের অনুমতি নেই।',403);
            if($action==='budget_decide') {
                if($expected===3)fail('এখন CFO-এর টাকা রিলিজের ধাপ।',409);
                $decision=$v['decision']??'';if(!in_array($decision,['approve','reject'],true))fail('সঠিক সিদ্ধান্ত দিন।');
                $note=strval_input($v,'note',2000,$decision==='approve');
                if($decision==='reject')query("UPDATE od_budgets SET status='rejected' WHERE id=?",[$id]);
                else query('UPDATE od_budgets SET stage=stage+1 WHERE id=?',[$id]);
                budget_event($a,$id,$decision==='approve'?'approved':'rejected',$expected,$note,$role);
            }else {
                if($expected!==3)fail('GM, CFO ও MD অনুমোদনের আগে টাকা রিলিজ করা যাবে না।',409);
                $date=day_input($v);if($date<$b['submitted_on']||$date>today())fail('আবেদনের তারিখ থেকে আজকের মধ্যে রিলিজের তারিখ দিন।');
                $reference=strval_input($v,'reference',100);$note=strval_input($v,'note',2000,true);
                query("UPDATE od_budgets SET stage=4,status='released',released_on=?,release_reference=? WHERE id=?",[$date,$reference,$id]);
                budget_event($a,$id,'released',3,'রিলিজ তারিখ: '.$date.' | রেফারেন্স: '.$reference.($note!==''?' | '.$note:''),$role);
            }
        });output(['ok'=>true,'id'=>$id]);
    }
    fail('সঠিক বাজেট অনুরোধ দিন।');
}
function budget_filters(array $q): array {
    $where=[];$args=[];$f=[];
    foreach(['from','to'] as $key){$value=$q[$key]??'';if($value!=='')$f[$key]=day_input(['date'=>$value]);}
    if(isset($f['from'],$f['to'])&&$f['from']>$f['to'])fail('শুরুর তারিখ শেষের তারিখের পরে হতে পারবে না।');
    $dateType=$q['dateType']??'submitted';if(!in_array($dateType,['submitted','decision','released'],true))fail('সঠিক তারিখের ধরন দিন।');$f['dateType']=$dateType;
    if($dateType==='decision'&&(isset($f['from'])||isset($f['to']))){
        $clause="e.budget_id=b.id AND e.action IN ('approved','rejected')";
        foreach(['from'=>'>=','to'=>'<='] as $k=>$op)if(isset($f[$k])){$clause.=" AND e.occurred_on $op ?";$args[]=$f[$k];}
        $where[]='EXISTS (SELECT 1 FROM od_budget_events e WHERE '.$clause.')';
    }else{
        $field=$dateType==='released'?'b.released_on':'b.submitted_on';
        foreach(['from'=>'>=','to'=>'<='] as $k=>$op)if(isset($f[$k])){$where[]="$field $op ?";$args[]=$f[$k];}
    }
    foreach(['branch','method','status','applicant','reviewer','q','min','max'] as $key){
        $value=$q[$key]??'';if($value==='')continue;$value=strval_input([$key=>$value],$key,$key==='q'?200:254);$f[$key]=$value;
        if($key==='branch'){$where[]='b.branch=?';$args[]=$value;}
        elseif($key==='method'){if(!in_array($value,['Bank','bKash','Cash'],true))fail('সঠিক মাধ্যম দিন।');$where[]='b.method=?';$args[]=$value;}
        elseif($key==='status'){
            if(in_array($value,['released','rejected'],true)){$where[]='b.status=?';$args[]=$value;}
            elseif(preg_match('/^stage([0-3])$/',$value,$m)){$where[]="b.status='pending' AND b.stage=?";$args[]=(int)$m[1];}
            else fail('সঠিক স্ট্যাটাস দিন।');
        }elseif($key==='min'||$key==='max'){$v=money_input([$key=>$value],$key);$where[]='b.amount'.($key==='min'?'>=':'<=').'?';$args[]=$v;}
        else{
            $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$value).'%';
            if($key==='applicant'){$where[]="(b.applicant_name LIKE ? ESCAPE '!' OR b.applicant_email LIKE ? ESCAPE '!')";array_push($args,$like,$like);}
            elseif($key==='reviewer'){$where[]="EXISTS (SELECT 1 FROM od_budget_events e WHERE e.budget_id=b.id AND e.action IN ('approved','rejected','released') AND (e.actor_name LIKE ? ESCAPE '!' OR e.actor_email LIKE ? ESCAPE '!'))";array_push($args,$like,$like);}
            else{$where[]="(b.applicant_name LIKE ? ESCAPE '!' OR b.branch LIKE ? ESCAPE '!' OR b.reason LIKE ? ESCAPE '!' OR CONCAT('BR-',LPAD(b.serial,6,'0')) LIKE ? ESCAPE '!' OR b.release_reference LIKE ? ESCAPE '!')";array_push($args,$like,$like,$like,$like,$like);}
        }
    }
    if(isset($f['min'],$f['max'])&&(float)$f['min']>(float)$f['max'])fail('সর্বনিম্ন পরিমাণ সর্বোচ্চের বেশি হতে পারবে না।');
    return [count($where)?implode(' AND ',$where):'1=1',$args,$f];
}
function budget_events(string $id): array {return rows('SELECT actor_name,actor_email,actor_role,action,stage,note,occurred_on,created_at FROM od_budget_events WHERE budget_id=? ORDER BY sequence',[$id]);}
function budget_event_map(array $items): array {
    if(!$items)return [];$ids=array_column($items,'id');$map=[];
    foreach(rows('SELECT * FROM od_budget_events WHERE budget_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY sequence',$ids) as $e)$map[$e['budget_id']][]=$e;
    return $map;
}
function budget_get(array $a): never {
    $role=budget_access($a);$view=$_GET['budget']??'list';
    if($view==='detail'){
        $id=budget_uuid($_GET,'id');staff_budget_assert($a,$id);$b=one('SELECT * FROM od_budgets WHERE id=?',[$id]);if(!$b)fail('বাজেট পাওয়া যায়নি।',404);output(['budget'=>budget_shape($b),'events'=>budget_events($id),'role'=>$role,'today'=>today()]);
    }
    if($view==='csv'||$view==='print')budget_export($a,$view);
    if($view!=='list')fail('সঠিক বাজেট বিভাগ দিন।');
    [$where,$args,$filters]=budget_filters($_GET);[$scope,$scopeArgs]=staff_budget_scope($a);$where.=' AND '.$scope;$args=[...$args,...$scopeArgs];
    $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT);if($page===false||$page<1||$page>10000000)fail('সঠিক পৃষ্ঠা দিন।');$offset=($page-1)*30;
    $stats=one('SELECT COUNT(*) AS total,COALESCE(SUM(b.amount),0) AS amount FROM od_budgets b WHERE '.$where,$args);
    $items=array_map('budget_shape',rows('SELECT b.* FROM od_budgets b WHERE '.$where.' ORDER BY b.serial DESC LIMIT 30 OFFSET '.$offset,$args));
    [$scope,$scopeArgs]=staff_budget_scope($a);
    $stages=$role==='md'?'0,1,2':($role==='cfo'?'1,3':($role==='gm'?'0':'-1'));
    $queue=one("SELECT COUNT(*) AS total FROM od_budgets b WHERE b.status='pending' AND b.stage IN ($stages) AND $scope",$scopeArgs);
    $branchOptions=$a['globalRead']?rows('SELECT id,name FROM od_branches WHERE active=1 ORDER BY name'):rows('SELECT id,name FROM od_branches WHERE id=? AND active=1',[$a['branchId']]);
    $filterBranches=$a['globalRead']?rows('SELECT name FROM od_branches ORDER BY name'):rows('SELECT name FROM od_branches WHERE id=?',[$a['branchId']]);
    output(['items'=>$items,'total'=>(int)$stats['total'],'amount'=>$stats['amount'],'page'=>$page,'pageSize'=>30,'filters'=>$filters,'branches'=>array_column($filterBranches,'name'),'branchOptions'=>$branchOptions,'role'=>$role,'canConfigure'=>$a['role']==='owner','ready'=>budget_ready(),'queueCount'=>(int)$queue['total'],'today'=>today()]);
}
function budget_csv_cell(mixed $v): string {
    $s=(string)($v??'');if(preg_match('/^[\s\x{FEFF}]*[=+@\-]/u',$s))$s="'".$s;return $s;
}
function budget_payment_text(array $b): string { $p=json_decode($b['payment_details'],true);return implode(' | ',array_values($p)); }
function budget_event_text(array $e): string {return strtoupper($e['actor_role']).' '.$e['action'].' — '.$e['actor_name'].' <'.$e['actor_email'].'> — '.(new DateTimeImmutable($e['created_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('Y-m-d H:i:s').' Asia/Dhaka — '.$e['note'];}
function budget_export(array $a,string $format): never {
    budget_access($a);[$where,$args,$filters]=budget_filters($_GET);[$scope,$scopeArgs]=staff_budget_scope($a);$where.=' AND '.$scope;$args=[...$args,...$scopeArgs];
    if(isset($_GET['id'])){$id=budget_uuid($_GET,'id');staff_budget_assert($a,$id);$where.=' AND b.id=?';$args[]=$id;if(!one('SELECT id FROM od_budgets WHERE id=?',[$id]))fail('বাজেট পাওয়া যায়নি।',404);}
    db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');db()->beginTransaction();
    if($format==='csv'){
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="budget-records-'.today().'.csv"');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
        $detail=($_GET['detail']??'')==='1';
        $head=['Budget ID','Applicant','Applicant email','Branch','Reason','Amount BDT','Method','Payment details','Submitted at (Asia/Dhaka)','Status','Stage (0 GM / 1 CFO / 2 MD / 3 release / 4 complete)','Release date','Release reference'];
        $head=array_merge($head,$detail?['Event','Actor name','Actor email','Actor role','Event stage','Event at (Asia/Dhaka)','Note']:['Complete approval and release history']);fputcsv($out,$head,',','"','');
    }else{
        header('Content-Type: text/html; charset=utf-8');$h=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
        echo '<!doctype html><html lang="bn"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Office Daily — Budget records</title><style>body{font:14px/1.7 Arial,sans-serif;color:#193a30;margin:25px}h1{font-size:23px}h2{font-size:18px}article{border:1px solid #cad7cc;padding:20px;margin:20px 0;break-inside:avoid}dl{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}dt{font-weight:bold}dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}li{margin:8px 0}button{padding:10px 20px;cursor:pointer}p{white-space:pre-wrap;overflow-wrap:anywhere}@media print{button{display:none}body{margin:0;color:#000}article{break-inside:auto}h2{break-after:avoid}li{break-inside:avoid}}@page{margin:16mm}</style><button data-print>প্রিন্ট / Save as PDF</button><h1>Office Daily — বাজেট আবেদন ও রিলিজের রেকর্ড</h1><p>তৈরির সময়: '.$h((new DateTimeImmutable())->format('Y-m-d H:i:s')).' Asia/Dhaka</p><p>ফিল্টার: '.$h(json_encode($filters,JSON_UNESCAPED_UNICODE)).'</p>';
    }
    // The repeatable-read transaction preserves one consistent export snapshot across all chunks.
    $ceiling=(int)(one('SELECT COALESCE(MAX(serial),0) AS serial FROM od_budgets')['serial']??0);$cursor=$ceiling+1;$count=0;
    do{
        $items=rows('SELECT b.* FROM od_budgets b WHERE '.$where.' AND b.serial<? ORDER BY b.serial DESC LIMIT 100',[...$args,$cursor]);$map=budget_event_map($items);
        foreach($items as $b){$count++;$events=$map[$b['id']]??[];$cursor=(int)$b['serial'];
            $created=(new DateTimeImmutable($b['created_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('Y-m-d H:i:s');
            if($format==='csv'){
                $base=[budget_code($b),$b['applicant_name'],$b['applicant_email'],$b['branch'],$b['reason'],$b['amount'],$b['method'],budget_payment_text($b),$created,$b['status'],$b['stage'],$b['released_on'],$b['release_reference']];
                if($detail){foreach($events as $e)fputcsv($out,array_map('budget_csv_cell',array_merge($base,[$e['action'],$e['actor_name'],$e['actor_email'],$e['actor_role'],$e['stage'],(new DateTimeImmutable($e['created_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('Y-m-d H:i:s'),$e['note']])),',','"','');}
                else fputcsv($out,array_map('budget_csv_cell',[...$base,implode("\n",array_map('budget_event_text',$events))]),',','"','');
            }else{
                echo '<article><h2>'.$h(budget_code($b)).' — ৳ '.$h($b['amount']).'</h2><dl>';
                foreach(['আবেদনকারী / Applicant'=>$b['applicant_name'].' <'.$b['applicant_email'].'>','ব্রাঞ্চ / Branch'=>$b['branch'],'জমার সময় / Submitted'=>$created.' Asia/Dhaka','অবস্থা / Status'=>($b['status']==='released'?'টাকা রিলিজ সম্পন্ন / Funds released':($b['status']==='rejected'?'প্রত্যাখ্যাত / Rejected':(['GM অনুমোদন / GM approval','CFO অনুমোদন / CFO approval','MD অনুমোদন / MD approval','CFO টাকা রিলিজ / CFO fund release'][(int)$b['stage']]).' — অপেক্ষমাণ / Pending')),'মাধ্যম / Method'=>$b['method'],'প্রাপকের তথ্য / Recipient'=>budget_payment_text($b),'রিলিজের তারিখ / Release date'=>$b['released_on']??'—','লেনদেন রেফারেন্স / Reference'=>$b['release_reference']??'—'] as $key=>$value)echo '<div><dt>'.$h($key).'</dt><dd>'.$h($value).'</dd></div>';
                echo '</dl><h3>বাজেটের কারণ / Reason</h3><p>'.$h($b['reason']).'</p><h3>সম্পূর্ণ অনুমোদন ও রিলিজ ইতিহাস / History</h3><ol>';
                foreach($events as $e)echo '<li>'.$h(budget_event_text($e)).'</li>';echo '</ol></article>';
            }
        }
    }while(count($items)===100);
    db()->commit();
    if($format==='csv')fclose($out);else echo '<p>মোট রেকর্ড: '.$count.'</p><script src="assets/budget-print.js"></script></html>';
    exit;
}
