<?php
declare(strict_types=1);

// Runtime files are PHP-only and are additionally denied by .htaccess.
if (!defined('OFFICE_APP')) { http_response_code(404); exit; }
date_default_timezone_set('Asia/Dhaka');

function config(): array {
    static $value;
    if ($value === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) fail('প্রথমে অ্যাপ সেটআপ সম্পন্ন করুন।', 503);
        $value = require $path;
        if (!is_array($value)) throw new RuntimeException('Invalid configuration');
    }
    return $value;
}
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $c = config();
        $pdo = new PDO('mysql:host='.$c['db_host'].';port='.$c['db_port'].';dbname='.$c['db_name'].';charset=utf8mb4', $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_STRINGIFY_FETCHES=>false]);
        $pdo->exec("SET time_zone='+00:00'");
    }
    return $pdo;
}
function query(string $sql, array $args=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($args); return $s; }
function one(string $sql, array $args=[]): ?array { return query($sql,$args)->fetch() ?: null; }
function rows(string $sql, array $args=[]): array { return query($sql,$args)->fetchAll(); }
function uid(): string { $v=random_bytes(16); $v[6]=chr((ord($v[6])&15)|64); $v[8]=chr((ord($v[8])&63)|128); $h=bin2hex($v); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function today(): string { return (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d'); }
function fail(string $message, int $status=400): never { throw new RuntimeException($message,$status); }
function output(array $data,int $status=200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR); exit;
}
function headers_secure(): void {
    header('Cache-Control: private, no-store'); header('Vary: Cookie'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}
function session_boot(): void {
    if (session_status()===PHP_SESSION_ACTIVE) return;
    $url=config()['app_url']; $path=parse_url($url,PHP_URL_PATH) ?: '/';
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.cookie_httponly','1');
    session_name('office_daily_session'); session_set_cookie_params(['lifetime'=>0,'path'=>rtrim($path,'/').'/', 'secure'=>str_starts_with($url,'https://'),'httponly'=>true,'samesite'=>'Lax']); session_start();
    $n=time();
    if (isset($_SESSION['account_id']) && ($n-($_SESSION['last_seen']??0)>3600 || $n-($_SESSION['started']??0)>28800)) $_SESSION=[];
    $_SESSION['last_seen']=$n;
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
}
function origin(string $url): string { $p=parse_url($url); return ($p['scheme']??'').'://'.($p['host']??'').(isset($p['port'])?':'.$p['port']:''); }
function require_post(): array {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') fail('অনুরোধটি অনুমোদিত নয়।',405);
    if (($_SERVER['HTTP_ORIGIN']??'')!==origin(config()['app_url'])) fail('অনুরোধটি অনুমোদিত নয়।',403);
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('সেশন শেষ হয়েছে। পৃষ্ঠা রিফ্রেশ করুন।',403);
    if (!str_contains($_SERVER['CONTENT_TYPE']??'','application/json')) fail('JSON প্রয়োজন।',415);
    $raw=file_get_contents('php://input',false,null,0,6000001);
    if ($raw===false || strlen($raw)>6000000) fail('তথ্যের আকার বেশি।',413);
    try { $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR); } catch (JsonException $e) { fail('সঠিক তথ্য দিন।'); }
    if (!is_array($data) || array_is_list($data)) fail('সঠিক তথ্য দিন।');
    if (strlen($raw)>50000 && !in_array(($data['action']??''),['profile_submit','chat_send'],true)) fail('তথ্যের আকার বেশি।',413);
    return $data;
}
function strval_input(array $v,string $key,int $max=5000,bool $empty=false): string {
    if (!isset($v[$key]) || !is_string($v[$key])) fail('সঠিক তথ্য দিন।');
    $s=trim($v[$key]); if ((!$empty && $s==='') || preg_match('//u',$s)!==1 || mb_strlen($s,'UTF-8')>$max) fail('সঠিক তথ্য দিন।'); return $s;
}
function id_input(array $v,string $key): string { return strval_input($v,$key,100); }
function email_input(array $v): string { $s=strtolower(strval_input($v,'email',254)); if (!filter_var($s,FILTER_VALIDATE_EMAIL)) fail('সঠিক ইমেইল দিন।'); return $s; }
function password_input(array $v,string $key='password'): string {
    $p=$v[$key]??null; if (!is_string($p) || strlen($p)<6 || strlen($p)>72 || str_contains($p,"\0")) fail('পাসওয়ার্ড ৬ থেকে ৭২ বাইটের মধ্যে হতে হবে।'); return $p;
}
function bool_input(array $v,string $key): int { if (!array_key_exists($key,$v) || !is_bool($v[$key])) fail('সঠিক তথ্য দিন।'); return (int)$v[$key]; }
function role_input(array $v): string { $r=$v['role']??''; if (!in_array($r,['officer','manager'],true)) fail('সঠিক তথ্য দিন।'); return $r; }
function day_input(array $v): string { $s=strval_input($v,'date',10); $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s); if (!$d || $d->format('Y-m-d')!==$s) fail('সঠিক তথ্য দিন।'); return $s; }
function targets_input(array $v): array {
    $targets=$v['targets']??null; if (!is_array($targets) || !array_is_list($targets) || count($targets)>300) fail('সঠিক তথ্য দিন।');
    foreach ($targets as $id) { if (!is_string($id) || strlen($id)>100 || !one('SELECT id FROM od_members WHERE id=?',[$id])) fail('অনুমতির কর্মকর্তা পাওয়া যায়নি।'); }
    return array_values(array_unique($targets));
}
function account(): ?array {
    if (!isset($_SESSION['account_id'])) return null;
    $u=one('SELECT id,email,name,is_owner,session_version,password_hash FROM od_accounts WHERE id=?',[$_SESSION['account_id']]);
    if (!$u || (int)$u['session_version']!==(int)($_SESSION['version']??-1)) { unset($_SESSION['account_id']); return null; }
    return $u;
}
function require_account(): array { $u=account(); if (!$u) fail('লগইন করুন।',401); return $u; }
function actor(bool $verified=true): array {
    $u=require_account(); if(function_exists('access_enabled')&&!access_enabled($u['id']))fail('অ্যাকাউন্টের অ্যাক্সেস বন্ধ রয়েছে।',403); if ((int)$u['is_owner']===1) return staff_decorate(['id'=>'owner','userId'=>$u['id'],'email'=>$u['email'],'name'=>$u['name'],'role'=>'owner','viewAll'=>1],$verified);
    $m=one('SELECT * FROM od_members WHERE user_id=? AND email=? AND active=1',[$u['id'],$u['email']]);
    if (!$m) fail('এই অ্যাপে আপনার অ্যাক্সেস দেওয়া হয়নি।',403);
    return staff_decorate(['id'=>$m['id'],'userId'=>$u['id'],'email'=>$u['email'],'name'=>$m['name'],'role'=>$m['role'],'viewAll'=>(int)$m['view_all']],$verified);
}
function registration_state(): array {
    $u=account(); if (!$u) return ['state'=>'signed_out','user'=>null];
    $user=['name'=>$u['name'],'email'=>$u['email']];
    if(function_exists('access_enabled')&&!access_enabled($u['id']))return ['state'=>'disabled','user'=>$user];
    if ((int)$u['is_owner']===1) return ['state'=>'approved','user'=>$user];
    $m=one('SELECT active FROM od_members WHERE user_id=?',[$u['id']]);
    if($m && (int)$m['active']===1 && function_exists('staff_verified') && !staff_verified($u['id']))return ['state'=>'profile_required','user'=>$user];
    if ($m) return ['state'=>(int)$m['active']===1?'approved':'disabled','user'=>$user];
    $r=one('SELECT id,status,created_at,reviewed_at FROM od_registration_requests WHERE user_id=?',[$u['id']]);
    return ['state'=>$r ? ($r['status']==='approved'?'disabled':$r['status']) : 'new','user'=>$user,'request'=>$r];
}
function grants(array $a): array { return array_column(rows('SELECT target_id FROM od_grants WHERE viewer_id=?',[$a['id']]),'target_id'); }
function visible(array $a,string $taskId): array {
    [$scope,$args]=staff_task_scope($a);
    $t=one('SELECT t.* FROM od_tasks t WHERE t.id=? AND '.$scope,[$taskId,...$args]);
    if(!$t)fail('কাজটি পাওয়া যায়নি।',404);return $t;
}
function audit(array $a,string $action,string $target): void { query('INSERT INTO od_audit (id,actor_id,actor_user_id,action,target,created_at) VALUES (?,?,?,?,?,?)',[uid(),$a['id'],$a['userId'],$action,$target,now()]); }
function save_grants(string $mid,array $targets): void { query('DELETE FROM od_grants WHERE viewer_id=?',[$mid]); foreach ($targets as $t) query('INSERT INTO od_grants (id,viewer_id,target_id) VALUES (?,?,?)',[uid(),$mid,$t]); }
function transaction(callable $fn): mixed { db()->beginTransaction(); try { $v=$fn(); db()->commit(); return $v; } catch (Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; } }
function throttle(string $category,string $identity,int $limit): void {
    $key=hash_hmac('sha256',$category.':'.$identity,config()['app_secret']); $n=time();
    query('INSERT INTO od_rate_limits (bucket,attempts,window_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(window_at<?,1,attempts+1),window_at=IF(window_at<?,?,window_at)',[$key,$n,$n-600,$n-600,$n]);
    $r=one('SELECT attempts FROM od_rate_limits WHERE bucket=?',[$key]);
    if ((int)$r['attempts']>$limit) { header('Retry-After: 600'); fail('অনেকবার চেষ্টা হয়েছে। ১০ মিনিট পরে চেষ্টা করুন।',429); }
    if (random_int(1,100)===1) query('DELETE FROM od_rate_limits WHERE window_at<?',[$n-3600]);
}
function sign_in(array $u): void { session_regenerate_id(true); $_SESSION['account_id']=$u['id']; $_SESSION['version']=(int)$u['session_version']; $_SESSION['started']=time(); $_SESSION['last_seen']=time(); }

