<?php
declare(strict_types=1);
define('OFFICE_APP',true);
final class ProfileResult extends Exception { public function __construct(public array $data) { parent::__construct(); } }
function fail(string $message,int $code=400):never { throw new RuntimeException($message,$code); }
function output(array $data):never { throw new ProfileResult($data); }
function config():array { return ['app_secret'=>'test-only-secret']; }
function id_input(array $input,string $key):string { return $input[$key]; }
function staff_assignment(string $id):?array { return ['branch_id'=>$id==='outside'?'other':'local','branch_name'=>'Test Branch']; }
function staff_account_allowed(array $actor,string $id):bool { return $actor['userId']===$id || !empty($actor['globalRead']) || (!empty($actor['manageBranch']) && staff_assignment($id)['branch_id']===$actor['branchId']); }
function one(string $sql,array $args):?array { return ['name'=>'Test Staff','email'=>'staff@example.com','phone'=>'01700000000','designation'=>'Officer','full_name'=>'Test Staff','photo_id'=>null,'nid_id'=>null]; }
function rows(string $sql,array $args):array {
    if (str_contains($sql,'od_profile_payment_methods')) {
        [$nonce,$tag,$cipher]=profile_seal('{"bank":"Test Bank","branch":"Test Branch","holder":"Test Staff","number":"0123456789"}',$args[0].'|payment|payment-1|bank');
        return [['id'=>'payment-1','kind'=>'bank','revision'=>1,'nonce'=>$nonce,'tag'=>$tag,'cipher'=>$cipher]];
    }
    $GLOBALS['historyReads']++;
    return [];
}
function audit(array $actor,string $action,string $target):void { $GLOBALS['audits'][]=$target; }
require __DIR__.'/../office-private/profile-settings.php';
function check(bool $value,string $message):void { if (!$value) throw new RuntimeException($message); }
$base=['userId'=>'viewer','role'=>'officer','branchId'=>'local','globalRead'=>false,'manageBranch'=>false];
foreach (['md','cfo','gm','branch_manager','admin','officer','staff'] as $post) {
    $actor=[...$base,'post'=>$post,'role'=>$post==='admin'?'owner':'officer','globalRead'=>in_array($post,['md','cfo','gm'],true),'manageBranch'=>$post==='branch_manager'];
    $_GET=['accountId'=>'target']; $GLOBALS['historyReads']=0; $GLOBALS['audits']=[];
    try { profile_settings_get($actor); }
    catch (ProfileResult $result) {
        check(!in_array($post,['officer','staff'],true),'ordinary staff must not read another account');
        check($result->data['payments'][0]['details']['number']==='0123456789','management receives decrypted bank details');
        check(!$result->data['isSelf'],'management cannot edit another account');
        check($result->data['canHistory']===($post==='admin'),'history permissions stay separate');
        check($post==='admin' || $GLOBALS['historyReads']===0,'management must not query private contact history');
        check($GLOBALS['audits']===['target'],'payment views are audited');
        continue;
    } catch (RuntimeException $error) {
        check(in_array($post,['officer','staff'],true)&&$error->getCode()===403,'unexpected role rejection: '.$post);
    }
}
check(!profile_can_view_payments([...$base,'manageBranch'=>true],'outside'),'branch manager must not read another branch');
check(!profile_can_view_payments([...$base,'post'=>'gm'],'target'),'unverified executive must not receive management access');
check(profile_can_view_payments($base,'viewer'),'self access remains available');
$_GET=[];
try { profile_settings_get($base); } catch (ProfileResult $result) { check($result->data['isSelf'] && $result->data['canHistory'],'self keeps editing and history'); }
echo "Profile payment role, branch, history and decryption checks passed.\n";
