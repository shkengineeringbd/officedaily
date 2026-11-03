<?php
declare(strict_types=1);
if (!is_file(__DIR__.'/office-private/config.php')) { header('Location: setup.php',true,302); exit; }
define('OFFICE_APP',true);
require __DIR__.'/office-private/bootstrap.php';
headers_secure(); session_boot();
$canonical=parse_url(config()['app_url']);
$authority=$canonical['host'].(isset($canonical['port'])?':'.$canonical['port']:'');
if (strtolower($_SERVER['HTTP_HOST']??'')!==strtolower($authority)) { header('Location: '.config()['app_url'].'/',true,302); exit; }
?>
<!doctype html>
<html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>"><meta name="description" content="Office Daily — tasks, employee permissions and daily reports"><title>Office Daily</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/app.css?v=<?=filemtime(__DIR__.'/assets/app.css')?>"></head><body><div id="root"><main class="state-card">Office Daily — লোড হচ্ছে / Loading…</main></div><noscript>অ্যাপ চালাতে JavaScript চালু করুন। Enable JavaScript to use this app.</noscript><script type="module" src="assets/app.js?v=<?=filemtime(__DIR__.'/assets/app.js')?>"></script></body></html>
