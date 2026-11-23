<?php
declare(strict_types=1);
define('OFFICE_APP', true);
require __DIR__.'/office-private/bootstrap.php';
headers_secure();
session_boot();
?>
<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
    <title>Office Daily | অফিস পরিচালনা</title>
    <link rel="stylesheet" href="assets/app.css?v=<?=filemtime(__DIR__.'/assets/app.css')?>">
    <link rel="stylesheet" href="assets/operations.css?v=<?=filemtime(__DIR__.'/assets/operations.css')?>">
</head>
<body class="operations-page">
    <header class="operations-topbar"><a class="operations-brand" href="./"><span aria-hidden="true">✓</span> Office Daily</a><a class="btn" href="./">কাজের পাতায় ফিরুন</a></header>
    <div class="operations-layout">
    <aside class="operations-sidebar"><div class="eyebrow">OFFICE MANAGEMENT</div><h1>অফিস পরিচালনা</h1><p>প্রয়োজনের বিভাগ বেছে নিন।</p><nav id="operations-nav" aria-label="অফিস পরিচালনার বিভাগ"></nav><a class="btn" href="./profile-settings.php">আমার প্রোফাইল</a></aside>
    <main class="operations-main" id="operations-main" tabindex="-1">
        <header class="operations-heading"><div><div class="eyebrow">OFFICE DAILY / MANAGEMENT</div><h2 id="operations-current-title">অফিস পরিচালনা</h2><p id="operations-description">আপনার অনুমতি অনুযায়ী বিভাগগুলো দেখানো হবে।</p></div><span class="operations-label">ম্যানেজমেন্ট ডেস্ক</span></header>
        <p id="operations-status" role="status" aria-live="polite">বিভাগগুলো লোড হচ্ছে…</p>
        <section id="operations-notices" class="panel" hidden></section>
        <section id="operations-inbox" class="panel" hidden></section>
        <section id="operations-insights" class="panel" hidden></section>
        <section id="operations-finance" class="panel" hidden></section>
        <section id="operations-releases" class="panel" hidden></section>
        <section id="operations-mfa" class="panel" hidden></section>
        <section id="operations-access" class="panel" hidden></section>
    </main>
    </div>
    <script type="module" src="assets/operations.js?v=<?=filemtime(__DIR__.'/assets/operations.js')?>"></script>
<script type="module" src="assets/role-preview.js?v=<?=filemtime(__DIR__.'/assets/role-preview.js')?>"></script></body>
</html>
