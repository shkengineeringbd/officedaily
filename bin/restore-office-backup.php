<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function restore_entry(ZipArchive $zip,string $entry) {
    $stream = $zip->getStream($entry);
    if (!$stream) throw new RuntimeException('Could not read '.$entry);
    return $stream;
}

try {
    if ($argc !== 3) throw new RuntimeException('Usage: php restore-office-backup.php archive.zip /path/to/staging-config.php');
    $password = getenv('OFFICE_RESTORE_PASSPHRASE');
    if (!$password) throw new RuntimeException('Set OFFICE_RESTORE_PASSPHRASE in the process environment.');
    $config = require $argv[2];
    if (!is_array($config)) throw new RuntimeException('Invalid staging configuration.');
    $zip = new ZipArchive();
    if ($zip->open($argv[1],ZipArchive::RDONLY)!==true) throw new RuntimeException('Could not open archive.');
    $zip->setPassword($password);
    $manifest = json_decode($zip->getFromName('manifest.json') ?: '',true,512,JSON_THROW_ON_ERROR);
    if (($manifest['format']??'')!=='office-daily-1'||!is_array($manifest['tables']??null)||!$manifest['tables']) throw new RuntimeException('Invalid archive format.');
    if (!hash_equals($config['app_secret'], $manifest['appSecret']??'')) throw new RuntimeException('Staging app_secret must match the backup to preserve file decryption.');
    $pdo = new PDO('mysql:host='.$config['db_host'].';port='.$config['db_port'].';dbname='.$config['db_name'].';charset=utf8mb4',
        $config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()!==0) {
        throw new RuntimeException('Select a fresh, empty staging database.');
    }
    $schemas = [];
    foreach ($manifest['tables'] as $table) {
        if (!is_string($table)||!preg_match('/^od_[a-z_]+$/D',$table)||isset($schemas[$table])) throw new RuntimeException('Invalid table name in archive.');
        $stream = restore_entry($zip,'tables/'.$table.'.jsonl');
        $schema = json_decode(fgets($stream) ?: '',true,512,JSON_THROW_ON_ERROR)['schema']??'';
        fclose($stream);
        if (!str_starts_with($schema,'CREATE TABLE `'.$table.'` (')||str_contains($schema,';')) throw new RuntimeException('Invalid table schema in archive.');
        $schemas[$table] = $schema;
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($schemas as $schema) $pdo->exec($schema);
    $pdo->beginTransaction();
    foreach ($schemas as $table=>$schema) {
        $stream = restore_entry($zip,'tables/'.$table.'.jsonl');
        fgets($stream);
        $statement = null;
        $columns = [];
        $count = 0;
        while (($line = fgets($stream))!==false) {
            $record = json_decode($line,true,512,JSON_THROW_ON_ERROR);
            if (!$statement) {
                $columns = array_keys($record);
                foreach ($columns as $column) if (!preg_match('/^[a-z_][a-z0-9_]*$/Di',$column)) throw new RuntimeException('Invalid column name.');
                $statement = $pdo->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')');
            }
            if (array_keys($record)!==$columns) throw new RuntimeException('Invalid row in '.$table);
            $values = array_map(function ($value) {
                if ($value === null) return null;
                $decoded = base64_decode($value,true);
                if ($decoded === false) throw new RuntimeException('Invalid row value.');
                return $decoded;
            }, $record);
            $statement->execute(array_values($values));
            $count++;
        }
        if (!feof($stream)) throw new RuntimeException('Incomplete archive entry: '.$table);
        fclose($stream);
        if ((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==$count) throw new RuntimeException('Restore count mismatch.');
        echo $table.': '.$count.PHP_EOL;
    }
    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    echo 'Restore loaded into the empty target database. Verify application access and protected files before cutover.'.PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'Restore stopped: '.$error->getMessage().PHP_EOL);
    exit(1);
}
