<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('OFFICE_APP',true);
final class SelfieResult extends Exception{public function __construct(public array $data){parent::__construct();}}
function fail(string $message,int $code=400):never{throw new RuntimeException($message,$code);}
function output(array $data):never{throw new SelfieResult($data);}
function today():string{return '2026-11-23';}
function now():string{return $GLOBALS['clock'];}
function config():array{return ['app_secret'=>'selfie-test-only-secret'];}
function db():PDO{return $GLOBALS['pdo'];}
function query(string $sql,array $args=[]):PDOStatement{$s=db()->prepare(str_replace(' FOR UPDATE','',$sql));$s->execute($args);return $s;}
function one(string $sql,array $args=[]):?array{return query($sql,$args)->fetch(PDO::FETCH_ASSOC)?:null;}
function rows(string $sql,array $args=[]):array{return query($sql,$args)->fetchAll(PDO::FETCH_ASSOC);}
function transaction(callable $fn):mixed{db()->beginTransaction();try{$r=$fn();db()->commit();return $r;}catch(Throwable $e){db()->rollBack();throw $e;}}
function audit(array $a,string $action,string $target):void{}
function check(bool $v,string $message):void{if(!$v)throw new LogicException($message);}
function rejected(callable $fn,int $code):void{try{$fn();}catch(RuntimeException $e){check($e->getCode()===$code,'Wrong rejection');return;}throw new LogicException('Invalid selfie request accepted');}
function begin(array $actor,string $mode):string{try{attendance_selfie_action($actor,['mode'=>$mode],'attendance_selfie_begin');}catch(SelfieResult $r){return $r->data['token'];}throw new LogicException('Missing capture token');}
require __DIR__.'/../office-private/attendance-selfie.php';
$GLOBALS['pdo']=new PDO('sqlite::memory:');db()->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$GLOBALS['clock']='2026-11-23T01:00:00Z';$_SESSION=[];
query('CREATE TABLE od_accounts(id TEXT PRIMARY KEY)');query("INSERT INTO od_accounts VALUES('alice')");
query('CREATE TABLE od_attendance(account_id TEXT,work_date TEXT,status TEXT,check_in TEXT,check_out TEXT,note TEXT,updated_at TEXT,PRIMARY KEY(account_id,work_date))');
foreach(['od_attendance_selfies','od_attendance_checkout_selfies'] as $table)query('CREATE TABLE '.$table.'(account_id TEXT,work_date TEXT,captured_at TEXT,expires_at INTEGER,nonce BLOB,tag BLOB,cipher BLOB,PRIMARY KEY(account_id,work_date))');
$actor=['id'=>'ma','userId'=>'alice','name'=>'Alice'];
rejected(fn()=>begin($actor,'out'),409);
$canvas=imagecreatetruecolor(320,240);ob_start();imagejpeg($canvas,null,80);$plain=ob_get_clean();$image='data:image/jpeg;base64,'.base64_encode($plain);check(strlen($plain)>1000,'Test image too small');
$token=begin($actor,'in');
rejected(fn()=>attendance_selfie_action($actor,['mode'=>'out','token'=>$token,'image'=>$image],'attendance_selfie_submit'),409);
rejected(fn()=>attendance_selfie_action($actor,['mode'=>'in','token'=>$token,'image'=>'data:image/jpeg;base64,bad'],'attendance_selfie_submit'),400);
check(!one('SELECT * FROM od_attendance'),'Invalid selfie created attendance');
check(attendance_selfie_action($actor,['mode'=>'in','token'=>$token,'image'=>$image],'attendance_selfie_submit'),'Check-in failed');
$checkin=one('SELECT * FROM od_attendance');check($checkin['check_in']===now()&&$checkin['check_out']===null,'Check-in timestamp incorrect');
rejected(fn()=>begin($actor,'in'),409);
$GLOBALS['clock']='2026-11-23T11:00:00Z';$token=begin($actor,'out');
check(attendance_selfie_action($actor,['mode'=>'out','token'=>$token,'image'=>$image],'attendance_selfie_submit'),'Check-out failed');
$current=one('SELECT * FROM od_attendance');check($current['check_in']===$checkin['check_in']&&$current['check_out']===now(),'Check-out replaced check-in');
foreach(['in'=>'od_attendance_selfies','out'=>'od_attendance_checkout_selfies'] as $kind=>$table){
 $r=one('SELECT * FROM '.$table);$decrypted=openssl_decrypt($r['cipher'],'aes-256-gcm',attendance_selfie_key(),OPENSSL_RAW_DATA,$r['nonce'],$r['tag'],'alice|'.today().($kind==='out'?'|out':''));check($decrypted===$plain,'Selfie encryption failed');check(abs($r['expires_at']-time()-86400)<10,'Wrong expiry');query('UPDATE '.$table.' SET expires_at=?',[time()-1]);
}
rejected(fn()=>begin($actor,'out'),409);
attendance_selfie_cleanup();
check(!one('SELECT * FROM od_attendance_selfies')&&!one('SELECT * FROM od_attendance_checkout_selfies'),'Expired pictures retained');
check(one('SELECT * FROM od_attendance')===$current,'Cleanup changed attendance');
echo "Selfie challenge/mode binding, image validation, encrypted check-in/out, server timestamps, duplicate prevention and expiry without losing attendance passed.\n";
