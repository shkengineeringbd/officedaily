<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('OFFICE_APP', true);
$oldRoot = getenv('OFFICE_TEST_ROOT');
if (!$oldRoot || !is_dir($oldRoot)) throw new RuntimeException('Run tests/operations-upgrade.sh.');
$newRoot = dirname(__DIR__);
$oldConfig = $oldRoot.'/office-private/config.php';
$newConfig = $newRoot.'/office-private/config.php';
if (file_exists($newConfig)) throw new RuntimeException('Use a clean checkout.');
$config = [
    'app_url'=>'http://127.0.0.1',
    'app_secret'=>bin2hex(random_bytes(32)),
    'db_host'=>'127.0.0.1',
    'db_port'=>3306,
    'db_name'=>'office_upgrade_test',
    'db_user'=>'root',
    'db_password'=>'office-test-only',
];
$restoreConfig = $config;
$restoreConfig['db_name'] = 'office_restore_test';
$restorePath = $oldRoot.'/restore-config.php';

function write_config(string $path,array $data): void {
    if (file_put_contents($path,'<?php return '.var_export($data,true).';')===false) throw new RuntimeException('Config write failed.');
}
function run_php(string ...$arguments): string {
    $process = proc_open([PHP_BINARY,...$arguments],[0=>['pipe','r'],1=>['pipe','w'],2=>STDERR],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start PHP.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process)!==0 || $output===false) throw new RuntimeException('PHP subprocess failed.');
    return $output;
}
function snapshot(PDO $connection): array {
    $snapshot = [];
    foreach ($connection->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if ($table==='od_app_meta') continue;
        $rows = $connection->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map('serialize',$rows);
        sort($encoded);
        $snapshot[$table] = $encoded;
    }
    ksort($snapshot);
    return $snapshot;
}
function check(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function verify_operations(string $root): void {
    foreach (['staffing','operations','budget','releases','people'] as $module) {
        require $root.'/office-private/'.$module.'.php';
    }
    $owner = ['id'=>'owner','userId'=>'owner','email'=>'test@example.test','name'=>'পরীক্ষা',
        'role'=>'owner','post'=>'admin','globalRead'=>true,'manageBranch'=>false,'branchId'=>null];
    $manager = ['id'=>uid(),'userId'=>uid(),'role'=>'manager','post'=>'branch_manager',
        'globalRead'=>false,'manageBranch'=>true,'branchId'=>'test-a'];
    $staff = uid();
    $other = uid();
    foreach ([$manager['userId']=>'test-a',$staff=>'test-a',$other=>'test-b'] as $id=>$branch) {
        query('INSERT INTO od_accounts(id,email,name,password_hash,created_at) VALUES(?,?,?,?,?)',[$id,$id.'@example.test','Test','unused',now()]);
        query('INSERT IGNORE INTO od_branches(id,name) VALUES(?,?)',[$branch,$branch]);
        query('INSERT INTO od_staff_assignments(account_id,branch_id,post,assigned_by,assigned_at) VALUES(?,?,?,?,?)',[$id,$branch,'staff','owner',now()]);
    }
    check(office_may_edit($manager,'leave',$staff),'Manager cannot review own branch leave.');
    check(!office_may_edit($manager,'leave',$other),'Manager could edit another branch.');
    check(office_module_scope($manager,'payroll',$staff)&&!office_may_edit($manager,'payroll',$staff),'Payroll should default to view access for the manager.');
    operations_action($owner,['accountId'=>$manager['userId'],'module'=>'leave','level'=>0],'operations_access');
    check(!office_module_scope($manager,'leave',$staff),'An explicit denial did not revoke branch leave access.');
    operations_action($owner,['accountId'=>$manager['userId'],'module'=>'leave','level'=>-1],'operations_access');
    check(office_may_edit($manager,'leave',$staff),'Removing the override did not restore the role default.');

    $id = uid();
    $date = today();
    query("INSERT INTO od_budgets(id,creator_id,applicant_name,applicant_email,branch,reason,amount,method,payment_details,stage,submitted_on,created_at) VALUES(?,?,?,?,?,?,?,?,?,3,?,?)",
        [$id,'owner','Test','test@example.test','test-a','Test','100.00','Cash','{}',$date,now()]);
    $budget = one('SELECT * FROM od_budgets WHERE id=?',[$id]);
    $first = ['percent'=>'25','expectedReleased'=>'0.00','date'=>$date,'reference'=>'FIRST','note'=>''];
    transaction(fn()=>release_record($owner,$first,$budget,'cfo'));
    check(release_total($id)===2500,'First installment differs from the requested percentage.');
    try {
        transaction(fn()=>release_record($owner,$first,$budget,'cfo'));
        throw new LogicException('Repeated stale installment was accepted.');
    } catch (RuntimeException $error) {
        check($error->getCode()===409,'Stale installment was rejected for an unexpected reason.');
    }
    $last = ['percent'=>'75','expectedReleased'=>'25.00','date'=>$date,'reference'=>'LAST','note'=>''];
    transaction(fn()=>release_record($owner,$last,$budget,'cfo'));
    check(release_total($id)===10000,'Final installment did not balance.');
    check(one('SELECT status FROM od_budgets WHERE id=?',[$id])['status']==='released','Full payment did not complete the release.');
    check(array_column(budget_events($id),'action')===['installment','released'],'Release history differs from the recorded installments.');
    echo "Branch permissions and percentage payments passed.\n";
}

$server = new PDO('mysql:host=127.0.0.1;port=3306','root','office-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE office_upgrade_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$server->exec('CREATE DATABASE office_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
require $newRoot.'/office-private/bootstrap.php';

function storage_entry(string $root): string {
    $modules = ['bootstrap','daily','people','budget','staffing','chat','storage','operations','releases','finance','mfa','profile-settings'];
    $entry = "<?php\ndefine('OFFICE_APP',true);\n";
    foreach ($modules as $module) {
        $path = $root.'/office-private/'.$module.'.php';
        if (is_file($path)) $entry .= 'require '.var_export($path,true).";\n";
    }
    return $entry;
}

try {
    write_config($oldConfig,$config);
    write_config($newConfig,$config);
    write_config($restorePath,$restoreConfig);
    $oldRunner = $oldRoot.'/initialize-old.php';
    file_put_contents($oldRunner,storage_entry($oldRoot).<<<'CODE'
foreach (require __DIR__.'/office-private/schema.php' as $sql) db()->exec($sql);
app_storage();
$u='owner';
$id=uid();
$now=now();
$date=today();
query('INSERT INTO od_accounts(id,email,name,password_hash,is_owner,created_at) VALUES(?,?,?,?,1,?)',[$u,'test@example.test','পরীক্ষা',password_hash('office-test-only',PASSWORD_DEFAULT),$now]);
query('INSERT INTO od_attendance(account_id,work_date,status,note,updated_at) VALUES(?,?,?,?,?)',[$u,$date,'Present','নমুনা',$now]);
query("INSERT INTO od_budgets(id,creator_id,applicant_name,applicant_email,branch,reason,amount,method,payment_details,stage,status,submitted_on,created_at,released_on,release_reference) VALUES(?,?,?,?,?,?,?,?,?,4,'released',?,?,?,?)",[$id,$u,'পরীক্ষা','test@example.test','হেড অফিস','নমুনা','45.50','Cash','{}',$date,$now,$date,'OLD-001']);
budget_event(['userId'=>$u,'name'=>'পরীক্ষা','email'=>'test@example.test'],$id,'released',3,'নমুনা','cfo');
query('INSERT INTO od_identity_files(id,account_id,kind,mime,nonce,tag,cipher,created_at) VALUES(?,?,?,?,?,?,?,?)',[uid(),$u,'nid','image/png',random_bytes(12),random_bytes(16),random_bytes(512),$now]);
CODE);
    run_php($oldRunner);
    $before = snapshot(db());

    $runner = $oldRoot.'/initialize-upgrade.php';
    file_put_contents($runner,storage_entry($newRoot).'app_storage();');
    run_php($runner);
    run_php($runner);
    $after = snapshot(db());
    if (array_intersect_key($after,$before)!==$before) throw new RuntimeException('An existing table or record changed during upgrade.');
    foreach (['od_module_access','od_notices','od_release_installments','od_payroll_batches','od_budget_settlements','od_mfa','od_profile_contact_history','od_profile_payment_methods'] as $table) {
        if (!array_key_exists($table,$after)) throw new RuntimeException('Upgrade did not create '.$table);
    }
    verify_operations($newRoot);
    $profileTest = $oldRoot.'/profile-settings-test.php';
    file_put_contents($profileTest,storage_entry($newRoot).<<<'CODE'
function expect_profile(bool $value,string $message): void {
    if (!$value) throw new RuntimeException($message);
}
session_boot();
$owner=['id'=>'owner','userId'=>'owner','name'=>'Test','email'=>'test@example.test','role'=>'owner'];
$other=uid();
query('INSERT INTO od_accounts(id,email,name,password_hash,created_at) VALUES(?,?,?,?,?)',[$other,'used@example.test','Other','unused',now()]);
query('INSERT INTO od_members(id,email,user_id,name) VALUES(?,?,?,?)',[uid(),'test@example.test','owner','Test']);
query('INSERT INTO od_registration_requests(id,user_id,email,name,created_at) VALUES(?,?,?,?,?)',[uid(),'owner','test@example.test','Test',now()]);
query('INSERT INTO od_hr_profiles(account_id,phone) VALUES(?,?)',['owner','+8801711111111']);
query('INSERT INTO od_password_recovery(id,account_id,status,token_hash,created_at) VALUES(?,?,?,?,?)',[uid(),'owner','approved',str_repeat('a',64),now()]);
try {
    profile_update_contact($owner,['email'=>'used@example.test','phone'=>'01722222222','password'=>'office-test-only']);
    throw new LogicException('Duplicate contact update succeeded.');
} catch (RuntimeException $error) {
    expect_profile($error->getCode()===409,'Unexpected contact validation result.');
}
expect_profile(!rows('SELECT id FROM od_profile_contact_history'),'Failed contacts left a history entry.');
try {
    profile_update_contact($owner,['email'=>'new@example.test','phone'=>'01722222222','password'=>'wrong-password']);
    throw new LogicException('Contact change accepted an incorrect password.');
} catch (RuntimeException $error) {
    expect_profile($error->getCode()===403,'Unexpected password validation result.');
}
profile_update_contact($owner,['email'=>'new@example.test','phone'=>'01722222222','password'=>'office-test-only']);
expect_profile(login_account('test@example.test')===null && login_account('01711111111')===null,'Previous login identifier remained active.');
expect_profile(login_account('new@example.test')['id']==='owner' && login_account('01722222222')['id']==='owner','Updated identifiers do not work.');
expect_profile(one("SELECT email FROM od_members WHERE user_id='owner'")['email']==='new@example.test','Member email not synchronized.');
expect_profile(one("SELECT email FROM od_registration_requests WHERE user_id='owner'")['email']==='new@example.test','Registration email not synchronized.');
expect_profile(one("SELECT status FROM od_password_recovery WHERE account_id='owner'")['status']==='revoked','Old recovery link remained active.');
expect_profile((int)$_SESSION['version']===2,'Updated session version missing.');
$history = rows('SELECT * FROM od_profile_contact_history ORDER BY id');
expect_profile(count($history)===2,'Contact history must contain both prior values.');
expect_profile(profile_open($history[0],'owner|contact|email')==='test@example.test' && profile_open($history[1],'owner|contact|phone')==='+8801711111111','Historic contact details are incorrect.');
$photo='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+r0ioAAAAASUVORK5CYII=';
$oldPhoto=staff_file(['photo'=>$photo],'photo','owner','photo',null);
$nid=staff_file(['nid'=>'data:application/pdf;base64,'.base64_encode('%PDF-1.7 synthetic')],'nid','owner','nid',null);
query("INSERT INTO od_identity_profiles(account_id,full_name,birth_date,photo_id,nid_id,branch_id,status,revision,submitted_at) VALUES(?,?,?,?,?,?,'approved',1,?)",
    ['owner','Test','1990-01-01',$oldPhoto,$nid,'head-office',now()]);
profile_settings_action($owner,['photo'=>$photo,'expectedPhotoId'=>$oldPhoto],'profile_photo_update');
$profile=one("SELECT * FROM od_identity_profiles WHERE account_id='owner'");
expect_profile($profile['status']==='approved' && $profile['photo_id']!==$oldPhoto && $profile['nid_id']===$nid,'Photo replacement changed approval or identity.');
expect_profile(!one('SELECT id FROM od_identity_files WHERE id=?',[$oldPhoto]),'Newly replaced profile photo was retained.');
$bank=['kind'=>'bank','bank'=>'Test Bank','branch'=>'Test Branch','holder'=>'Test Person','number'=>'0012345','routing'=>''];
profile_save_payment($owner,$bank,'profile_payment_save');
profile_save_payment($owner,['number'=>'0099999',...array_diff_key($bank,['number'=>true])],'profile_payment_save');
profile_save_payment($owner,['kind'=>'bkash','mobile'=>'01733333333','holder'=>''],'profile_payment_save');
$payments=rows("SELECT * FROM od_profile_payment_methods WHERE account_id='owner' ORDER BY kind,id");
expect_profile(count($payments)===3 && array_column($payments,'kind')===['bank','bank','bkash'],'Multiple bank and bKash entries were not retained.');
$first=$payments[0];
expect_profile(!str_contains($first['cipher'],'Test Bank'),'Bank details were not encrypted.');
try {
    profile_save_payment(['userId'=>$other],[...$bank,'id'=>$first['id'],'revision'=>1],'profile_payment_save');
    throw new LogicException('Another user modified a payment record.');
} catch (RuntimeException $error) {
    expect_profile($error->getCode()===409,'Unexpected cross-account edit result.');
}
profile_save_payment($owner,[...$bank,'id'=>$first['id'],'revision'=>1,'number'=>'0008888'],'profile_payment_save');
$changed=one('SELECT * FROM od_profile_payment_methods WHERE id=?',[$first['id']]);
$view=json_decode(profile_open($changed,'owner|payment|'.$first['id'].'|bank'),true,512,JSON_THROW_ON_ERROR);
expect_profile((int)$changed['revision']===2 && $view['number']==='0008888','Bank edit lost leading zeroes or revision.');
echo "Profile settings, protected contact history and multiple payment methods passed.\n";
CODE);
    echo run_php($profileTest);
    $after = snapshot(db());

    $passphrase = bin2hex(random_bytes(20));
    putenv('OFFICE_BACKUP_PASSPHRASE='.$passphrase);
    putenv('OFFICE_RESTORE_PASSPHRASE='.$passphrase);
    $archive = trim(run_php($newRoot.'/bin/backup-office.php',$oldRoot));
    if (!is_file($archive)) throw new RuntimeException('Backup archive missing.');
    run_php($newRoot.'/bin/restore-office-backup.php',$archive,$restorePath);
    $restored = new PDO('mysql:host=127.0.0.1;dbname=office_restore_test;charset=utf8mb4','root','office-test-only');
    if (snapshot($restored)!==$after) throw new RuntimeException('Restored data differs from the backup source.');
    echo "Upgrade preserved existing rows and encrypted backup restored all application data.\n";
} finally {
    @unlink($newConfig);
}
