<?php
declare(strict_types=1);
define('OFFICE_APP',true);
require __DIR__.'/office-private/bootstrap.php';
headers_secure();session_boot();
?>
<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>"><title>My attendance | Office Daily</title><link rel="stylesheet" href="assets/app.css?v=<?=filemtime(__DIR__.'/assets/app.css')?>"><link rel="stylesheet" href="assets/attendance.css?v=<?=filemtime(__DIR__.'/assets/attendance.css')?>"></head><body class="attendance-page">
<header class="attendance-topbar"><a class="brand" href="./">Office Daily</a><a class="btn" href="./">Workspace-এ ফিরুন</a></header>
<main class="attendance-main"><div class="heading"><div><div class="eyebrow">WORKSPACE / MY ATTENDANCE</div><h1>আমার হাজিরা</h1><p>নতুন সেলফি দিয়ে চেক-ইন এবং চেক-আউট দিন।</p></div><span class="date-chip" id="attendance-date">বাংলাদেশ সময়</span></div>
<p id="attendance-message" role="status" aria-live="polite">হাজিরা লোড হচ্ছে…</p>
<div id="attendance-content" hidden>
<section class="attendance-today panel"><h2>আজকের উপস্থিতি</h2><p id="attendance-person"></p><div class="attendance-times"><div><span>চেক-ইন</span><strong id="attendance-in">—</strong></div><div><span>চেক-আউট</span><strong id="attendance-out">—</strong></div><div><span>অবস্থা</span><strong id="attendance-status">—</strong></div></div>
<p id="attendance-readonly" class="attendance-note" hidden>অ্যাডমিন প্রিভিউ: শুধু দেখা যাবে, সেলফি বা হাজিরা দেওয়া যাবে না।</p>
<div class="attendance-actions"><button class="btn primary" id="attendance-camera" type="button" disabled>সেলফি দিয়ে Check-in</button><button class="btn" id="attendance-checkout" type="button" disabled>সেলফি দিয়ে Check-out</button></div>
<section id="attendance-capture" class="attendance-capture" hidden><video id="attendance-video" autoplay playsinline muted aria-label="লাইভ সেলফি ক্যামেরা"></video><p>মুখটি পরিষ্কার দেখা যাচ্ছে নিশ্চিত করে ছবি তুলুন।</p><div class="attendance-actions"><button class="btn primary" type="button" id="attendance-submit" disabled>সেলফি তুলে জমা দিন</button><button class="btn" type="button" id="attendance-cancel">বাতিল</button></div></section>
<div class="attendance-photos"><figure id="attendance-photo-in" hidden><img id="attendance-photo-image-in" alt="আজকের চেক-ইন সেলফি"><figcaption id="attendance-photo-caption-in"></figcaption></figure><figure id="attendance-photo-out" hidden><img id="attendance-photo-image-out" alt="আজকের চেক-আউট সেলফি"><figcaption id="attendance-photo-caption-out"></figcaption></figure></div>
<p class="attendance-note">Check-in ও Check-out—দুই ক্ষেত্রেই আলাদা নতুন সেলফি আবশ্যক। সেলফি জমা সার্ভারে পৌঁছানোর সময় রেকর্ড হবে। সময় বাংলাদেশ সময় হিসেবে দেখানো হয়। ছবিটি ২৪ ঘণ্টা দেখা যাবে; তারিখ ও হাজিরার সময় রেকর্ডে থাকবে। ক্যামেরা থেকে ছবি তোলা হয়; গ্যালারি আপলোড নেই।</p></section>
<section class="panel attendance-history"><div class="attendance-history-heading"><h2>হাজিরার রেকর্ড</h2><label>মাস <input id="attendance-month" type="month"></label></div><div class="table-scroll"><table><thead><tr><th>তারিখ</th><th>অবস্থা</th><th>চেক-ইন</th><th>চেক-আউট</th></tr></thead><tbody id="attendance-rows"></tbody></table></div></section>
</div></main><script type="module" src="assets/attendance.js?v=<?=filemtime(__DIR__.'/assets/attendance.js')?>"></script><script type="module" src="assets/role-preview.js?v=<?=filemtime(__DIR__.'/assets/role-preview.js')?>"></script></body></html>
