<?php
declare(strict_types=1);

if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function backup_zip(string $password): string {
    if (!class_exists(ZipArchive::class)) fail('সার্ভারে PHP ZIP সুবিধা প্রয়োজন।',503);
    $path = tempnam(sys_get_temp_dir(),'office-backup-');
    if ($path === false) fail('ব্যাকআপ তৈরি করা যায়নি।',503);
    $zip = new ZipArchive();
    try {
        if ($zip->open($path,ZipArchive::OVERWRITE)!==true) fail('ব্যাকআপ ZIP তৈরি করা যায়নি।',503);
        db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        db()->beginTransaction();
        $tables = [];
        foreach (rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $row) {
            $table = $row['TABLE_NAME'];
            if (!preg_match('/^od_[a-z_]+$/D',$table)) continue;
            $schema = one('SHOW CREATE TABLE `'.$table.'`')['Create Table'];
            $file = tmpfile();
            if (!$file) fail('ব্যাকআপ লেখা যায়নি।',503);
            $tables[] = [$table,$file];
            $line = json_encode(['schema'=>$schema],JSON_THROW_ON_ERROR)."\n";
            if (fwrite($file,$line)!==strlen($line)) fail('ব্যাকআপ লেখা যায়নি।',503);
            $query = query('SELECT * FROM `'.$table.'`');
            while ($record = $query->fetch()) {
                $line = json_encode(array_map(fn($value)=>$value === null ? null : base64_encode((string)$value),$record),JSON_THROW_ON_ERROR)."\n";
                if (fwrite($file,$line)!==strlen($line)) fail('ব্যাকআপ লেখা যায়নি।',503);
            }
            if (!$zip->addFile(stream_get_meta_data($file)['uri'],'tables/'.$table.'.jsonl') ||
                !$zip->setEncryptionName('tables/'.$table.'.jsonl',ZipArchive::EM_AES_256,$password)) fail('ব্যাকআপ এনক্রিপ্ট করা যায়নি।',503);
        }
        db()->commit();
        $manifest = json_encode(['format'=>'office-daily-1','createdAt'=>now(),'tables'=>array_column($tables,0),'appSecret'=>config()['app_secret']],JSON_THROW_ON_ERROR);
        if (!$tables || !$zip->addFromString('manifest.json',$manifest) || !$zip->setEncryptionName('manifest.json',ZipArchive::EM_AES_256,$password) || !$zip->close()) {
            fail('ব্যাকআপ সংরক্ষণ করা যায়নি।',503);
        }
        return $path;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        @unlink($path);
        throw $error;
    }
}

function backup_action(array $actor,array $input,string $action): bool {
    if ($action !== 'operations_backup') return false;
    hr_owner($actor);
    $password = strval_input($input,'backupPassword',100);
    if (strlen($password)<14) fail('ব্যাকআপের আলাদা পাসফ্রেজ অন্তত ১৪ অক্ষরের দিন।');
    throttle('operations-backup',$actor['userId'],6);
    audit($actor,'ব্যাকআপের অনুরোধ','Encrypted ZIP');
    $path = backup_zip($password);
    try {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="Office_Daily_'.date('Y-m-d_His').'.zip"');
        header('Content-Length: '.filesize($path));
        readfile($path);
    } finally {
        @unlink($path);
    }
    exit;
}
