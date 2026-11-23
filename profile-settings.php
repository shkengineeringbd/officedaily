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
<link rel="stylesheet" href="assets/profile-settings.css?v=<?=filemtime(__DIR__.'/assets/profile-settings.css')?>">
</head>
<body class="profile-page">
    <aside class="profile-sidebar">
        <a class="profile-brand" href="./">▦ <span>Office Daily</span></a>
        <nav aria-label="Main navigation">
            <a href="./" data-bn="ড্যাশবোর্ড">Dashboard</a>
            <a href="./?view=tasks" data-bn="আমার কাজ">My Tasks</a>
            <a href="./?view=people" data-bn="উপস্থিতি">Attendance</a>
            <a href="./?view=budget" data-bn="বাজেট">Budget</a>
            <a href="./profile-settings.php" aria-current="page" data-bn="আমার প্রোফাইল">My Profile</a>
        </nav>
    </aside>
    <main class="profile-main">
        <header class="profile-heading"><div><h1 data-bn="প্রোফাইল সেটিংস">Profile Settings</h1><p data-bn="ব্যক্তিগত ও পেমেন্ট তথ্য পরিচালনা করুন">Manage your personal and payment details</p></div><div class="profile-language"><button type="button" data-language="en">English</button><button type="button" data-language="bn">বাংলা</button></div></header>
        <p id="profile-message" role="status" aria-live="polite">Loading…</p>
        <section id="profile-contact" class="profile-card" hidden></section>
        <section id="profile-payment" class="profile-card" hidden></section>
        <section id="profile-history" class="profile-card" hidden></section>
        <div class="profile-bottom"><a class="btn" href="./" data-bn="ফিরে যান">Back to Office Daily</a></div>
    </main>
    <script type="module" src="assets/profile-settings.js?v=<?=filemtime(__DIR__.'/assets/profile-settings.js')?>"></script>
<script type="module" src="assets/role-preview.js?v=<?=filemtime(__DIR__.'/assets/role-preview.js')?>"></script></body>
</html>

