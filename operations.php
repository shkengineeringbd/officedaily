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
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <main class="state-card">
        <p><a href="./">← Office Daily</a></p>
        <h1>অফিস পরিচালনা</h1>
        <p id="operations-status" role="status"></p>
        <section id="operations-notices" class="panel" hidden></section>
        <section id="operations-releases" class="panel" hidden></section>
        <section id="operations-access" class="panel" hidden></section>
    </main>
    <script type="module" src="assets/operations.js"></script>
</body>
</html>
