<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('OFFICE_APP',true);
require __DIR__.'/../office-private/bootstrap.php';
require __DIR__.'/../office-private/backup.php';

try {
    if ($argc !== 2) throw new RuntimeException('Usage: php backup-office.php /private/backup-directory');
    $directory = realpath($argv[1]);
    $app = realpath(__DIR__.'/..');
    if (!$directory || !is_dir($directory) || $directory === $app || str_starts_with($directory,$app.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Choose an existing directory outside the application web root.');
    }
    $password = getenv('OFFICE_BACKUP_PASSPHRASE');
    if (!is_string($password)||strlen($password)<14) throw new RuntimeException('Set OFFICE_BACKUP_PASSPHRASE to a strong, separate passphrase.');
    $path = backup_zip($password);
    $name = 'Office_Daily_'.date('Y-m-d_His').'_'.bin2hex(random_bytes(4)).'.zip';
    $destination = $directory.DIRECTORY_SEPARATOR.$name;
    if (!rename($path,$destination)) throw new RuntimeException('Could not move encrypted ZIP into the backup directory.');
    chmod($destination,0600);
    echo $destination.PHP_EOL;
    $remote = backup_drive_remote();
    if ($remote!==null) backup_copy_to_drive($destination,$name,$remote);
} catch (Throwable $error) {
    if (isset($path)&&is_file($path)) @unlink($path);
    fwrite(STDERR,'Backup stopped: '.$error->getMessage().PHP_EOL);
    exit(1);
}
