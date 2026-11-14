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

$server = new PDO('mysql:host=127.0.0.1;port=3306','root','office-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE office_upgrade_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$server->exec('CREATE DATABASE office_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
require $newRoot.'/office-private/bootstrap.php';

function storage_entry(string $root): string {
    $modules = ['bootstrap','daily','people','budget','staffing','chat','storage','operations','releases','finance','mfa'];
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
    foreach (['od_module_access','od_notices','od_release_installments','od_payroll_batches','od_budget_settlements','od_mfa'] as $table) {
        if (!array_key_exists($table,$after)) throw new RuntimeException('Upgrade did not create '.$table);
    }

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
