<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('OFFICE_APP',true);
final class PreviewResult extends Exception { public function __construct(public array $data){parent::__construct();} }
function fail(string $message,int $code=400):never{throw new RuntimeException($message,$code);}
function output(array $data):never{throw new PreviewResult($data);}
function account(bool $real=false):?array{return $GLOBALS['owner'];}
function access_enabled(string $id):bool{return $id!=='disabled-admin';}
function id_input(array $input,string $key):string{return $input[$key];}
function one(string $sql,array $args):?array{return $args[0]==='staff-a'?$GLOBALS['target']:null;}
function rows(string $sql,array $args=[]):array{return [['id'=>'staff-a','name'=>'Test Staff','post'=>'staff','branch_name'=>'Branch A']];}
function staff_decorate(array $actor,bool $verified=true):array{return $actor;}
function audit(array $actor,string $action,string $target):void{$GLOBALS['audits'][]=[$actor['userId'],$target];}
function check(bool $condition,string $message):void{if(!$condition)throw new LogicException($message);}
function denied(callable $action,int $expected):void{try{$action();throw new LogicException('Unauthorized action accepted.');}catch(RuntimeException $error){check($error->getCode()===$expected,'Wrong denial status.');}}
require __DIR__.'/../office-private/role-preview.php';
$_SESSION=[];
$GLOBALS['owner']=['id'=>'admin','is_owner'=>1,'name'=>'Admin','email'=>'admin@example.test'];
$GLOBALS['target']=['id'=>'staff-a','name'=>'Test Staff','email'=>'staff@example.test','is_owner'=>0,'session_version'=>1];
$GLOBALS['audits']=[];
check(preview_account($GLOBALS['owner'])['id']==='admin','Normal account identity changed.');
try{preview_action(['accountId'=>'staff-a'],'preview_start');}catch(PreviewResult $result){check($result->data['ok'],'Preview did not start.');}
check($_SESSION['role_preview_account']==='staff-a','Target was not recorded.');
check(preview_account($GLOBALS['owner'])['id']==='staff-a','Effective account differs from selected user.');
check($GLOBALS['owner']['id']==='admin','Preview replaced the real login.');
check($GLOBALS['audits']===[['admin','staff-a']],'Preview start must identify the actual administrator.');
foreach(['task','status','daily_work','profile_contact_update','profile_photo_update','profile_payment_save','profile_payment_remove','change_password','login','signup','operations_access','budget_submit','chat_send','attendance_import','attendance_selfie_begin','attendance_selfie_checkin','attendance_selfie_submit','operations_backup'] as $action)denied(fn()=>preview_assert_write($action),403);
foreach(['preview_start','preview_stop','logout'] as $action)preview_assert_write($action);
denied(fn()=>preview_action(['accountId'=>'unknown'],'preview_start'),404);
check($_SESSION['role_preview_account']==='staff-a','Invalid selection changed the current target.');
try{preview_state();}catch(PreviewResult $result){check($result->data['active']&&$result->data['selected']['id']==='staff-a','State cannot restore the active preview.');}
$GLOBALS['target']=null;
denied(fn()=>preview_account($GLOBALS['owner']),403);
try{preview_action([],'preview_stop');}catch(PreviewResult $result){check($result->data['ok'],'Cannot exit a revoked target.');}
check(!isset($_SESSION['role_preview_account']),'Exit did not restore normal identity.');
preview_assert_write('task');
$GLOBALS['owner']['is_owner']=0;
denied(fn()=>preview_action(['accountId'=>'staff-a'],'preview_start'),403);
try{preview_state();}catch(PreviewResult $result){check($result->data===['canPreview'=>false],'Staff received the administrator user list.');}
$GLOBALS['owner']['is_owner']=1;$GLOBALS['owner']['id']='disabled-admin';
denied(fn()=>preview_owner(),403);
$GLOBALS['owner']=null;
denied(fn()=>preview_owner(),401);
echo "Role preview owner, identity, read-only, revoked user, exit and authorization checks passed.\n";
