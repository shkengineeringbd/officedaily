<?php
declare(strict_types=1);
if (!is_file(__DIR__.'/office-private/config.php')) { header('Location: ./',true,302); exit; }
define('OFFICE_APP',true);
require __DIR__.'/office-private/bootstrap.php';
headers_secure();
session_boot();
if (!isset($_SESSION['account_id'])) { header('Location: ./',true,302); exit; }
?>
<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>">
    <title>Office Daily | প্রোফাইল সেটিংস</title>
    <link rel="stylesheet" href="assets/app.css?v=<?=filemtime(__DIR__.'/assets/app.css')?>">
</head>
<body>
    <main class="state-card">
        <p><a href="./">← Office Daily</a></p>
        <h1>প্রোফাইল সেটিংস</h1>
        <p id="profile-message" role="status"></p>
        <section id="profile-contact" class="panel" hidden></section>
        <section id="profile-payment" class="panel" hidden></section>
        <section id="profile-history" class="panel" hidden></section>
    </main>
    <script type="module" src="assets/profile-settings.js?v=<?=filemtime(__DIR__.'/assets/profile-settings.js')?>"></script>
</body>
</html>
