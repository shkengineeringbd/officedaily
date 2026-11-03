<?php
declare(strict_types=1);
define('OFFICE_APP',true);
require __DIR__.'/office-private/bootstrap.php';
headers_secure();
if (is_file(__DIR__.'/office-private/config.php')) { http_response_code(410); exit('Setup is already complete. Open the application.'); }
$https=($_SERVER['HTTPS']??'')==='on' || ($_SERVER['SERVER_PORT']??'')==='443';
$local=in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true) && PHP_SAPI==='cli-server';
if (!$https && !$local) { http_response_code(403); exit('Open this setup page using HTTPS.'); }
ini_set('session.use_strict_mode','1'); session_name('office_daily_setup');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Strict']); session_start();
if (!isset($_SESSION['setup_csrf'])) $_SESSION['setup_csrf']=bin2hex(random_bytes(32));
function esc(string $s): string { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
$error=''; $complete=false;
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    $handle=null; $pdo=null; $saved=false;
    try {
        if (!hash_equals($_SESSION['setup_csrf'],$_POST['csrf']??'')) fail('সেশন শেষ হয়েছে। পৃষ্ঠা রিফ্রেশ করুন।');
        if (strlen($_POST['setup_key']??'')>200 || !hash_equals('b757507b3d6025d8857d5d2cdcbd7c42cfedd58bb735255b46bb84701b312f78',hash('sha256',$_POST['setup_key']??''))) fail('Setup key সঠিক নয়।');
        if (PHP_VERSION_ID<80200 || !extension_loaded('pdo_mysql') || !extension_loaded('mbstring')) fail('PHP 8.2+ এবং pdo_mysql, mbstring extensions চালু থাকতে হবে।');
        $host=strval_input($_POST,'db_host',253); $name=strval_input($_POST,'db_name',64); $user=strval_input($_POST,'db_user',64); $pass=$_POST['db_password']??''; $port=(int)($_POST['db_port']??3306);
        if (!preg_match('/^[A-Za-z0-9_.-]+$/D',$host) || !preg_match('/^[A-Za-z0-9_]+$/D',$name) || !preg_match('/^[A-Za-z0-9_]+$/D',$user) || $port<1 || $port>65535 || !is_string($pass) || $pass==='') fail('ডেটাবেসের তথ্য সঠিকভাবে দিন।');
        $url=rtrim(strval_input($_POST,'app_url',500),'/'); $p=parse_url($url);
        if (!$p || !isset($p['scheme'],$p['host']) || isset($p['query']) || isset($p['fragment']) || isset($p['user']) || !filter_var($url,FILTER_VALIDATE_URL) || ($p['scheme']!=='https' && !($local && $p['scheme']==='http' && in_array($p['host'],['127.0.0.1','localhost'],true)))) fail('অ্যাপের সঠিক HTTPS ঠিকানা দিন।');
        if (($_SERVER['HTTP_ORIGIN']??'')!==origin($url)) fail('এই পৃষ্ঠার ঠিকানার সঙ্গে App URL মিলতে হবে।');
        $email=email_input($_POST); $adminName=strval_input($_POST,'name',100); $adminPassword=password_input($_POST,'admin_password');
        if ($adminPassword!==($_POST['confirm_password']??'')) fail('দুটি পাসওয়ার্ড মেলেনি।');
        $private=__DIR__.'/office-private'; $handle=fopen($private.'/install.lock','c');
        if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) fail('ইনস্টল চলছে। একটু পরে চেষ্টা করুন।');
        if (is_file($private.'/config.php')) fail('Setup ইতিমধ্যে সম্পন্ন হয়েছে।');
        $pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        foreach(require $private.'/schema.php' as $sql) $pdo->exec($sql);
        if ((int)$pdo->query('SELECT COUNT(*) FROM od_accounts')->fetchColumn()>0) fail('এই ডেটাবেসে অ্যাপের অ্যাকাউন্ট ইতিমধ্যে আছে। পুরোনো কনফিগারেশন পুনরুদ্ধার করুন।');
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('INSERT INTO od_accounts (id,email,name,password_hash,is_owner,session_version,created_at) VALUES (?,?,?,?,1,1,?)');
        $stmt->execute(['owner',$email,$adminName,password_hash($adminPassword,PASSWORD_DEFAULT),now()]);
        $c=['app_url'=>$url,'app_secret'=>bin2hex(random_bytes(32)),'db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_password'=>$pass];
        $configText="<?php\n// Generated on installation. Keep private.\nreturn ".var_export($c,true).";\n";
        $temp=$private.'/config-'.bin2hex(random_bytes(8)).'.php';
        if (file_put_contents($temp,$configText,LOCK_EX)===false) throw new RuntimeException('Configuration write failed');
        chmod($temp,0600);
        if (!rename($temp,$private.'/config.php')) { unlink($temp); throw new RuntimeException('Configuration save failed'); }
        $saved=true; $pdo->commit(); $complete=true; $_SESSION=[]; session_regenerate_id(true);
    } catch(Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        if ($saved && !$complete) @unlink(__DIR__.'/office-private/config.php');
        $error=$e instanceof PDOException?'ডেটাবেস সংযোগ বা টেবিল তৈরি হয়নি। Host, সম্পূর্ণ database name, username ও password যাচাই করুন।':($e->getCode()===400?$e->getMessage():'সেটআপ সম্পন্ন হয়নি। ফোল্ডারের write permission ও সার্ভার configuration যাচাই করুন।');
    } finally { if ($handle) { flock($handle,LOCK_UN); fclose($handle); } }
}
$defaultUrl=$local?'http://127.0.0.1:8788':'https://shkg.info';
?>
<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Office Daily — Setup</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="registration-main"><section class="registration-card"><div class="eyebrow">OFFICE DAILY / INSTALLATION</div><h1>অ্যাপ সেটআপ / App setup</h1>
<?php if($complete): ?><p class="registration-intro">সেটআপ সম্পন্ন হয়েছে। আপনার অ্যাডমিন ইমেইল ও পাসওয়ার্ড দিয়ে লগইন করুন।<br>Setup complete. Sign in using your admin email and password.</p><p class="setup-hint">এখন File Manager থেকে setup.php মুছে দিন। ইনস্টলার স্বয়ংক্রিয়ভাবেও লক করা হয়েছে।<br>Delete setup.php from File Manager. The installer is also locked automatically.</p><p><a class="btn primary registration-submit" href="./">অ্যাপ খুলুন / Open app</a></p>
<?php else: ?><p class="registration-intro">ডেটাবেসের তথ্য এবং অ্যাডমিন লগইন সেট করুন। এই ফর্ম একবারই পূরণ করতে হবে।<br>Connect your database and create the administrator account.</p>
<?php if($error): ?><p role="alert" class="auth-error"><?=esc($error)?></p><?php endif; ?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=esc($_SESSION['setup_csrf'])?>">
<label>Setup key<input required name="setup_key" type="password" maxLength="200" autocomplete="off"></label>
<label>App URL<input required name="app_url" type="url" value="<?=esc($_POST['app_url']??$defaultUrl)?>"></label>
<div class="form-grid"><label>Database host<input required name="db_host" value="<?=esc($_POST['db_host']??'localhost')?>"></label><label>Database port<input required name="db_port" type="number" min="1" max="65535" value="<?=esc($_POST['db_port']??'3306')?>"></label></div>
<label>MySQL database name<input required name="db_name" value="<?=esc($_POST['db_name']??'u761933056_office')?>"></label>
<label>MySQL username<input required name="db_user" value="<?=esc($_POST['db_user']??'u761933056_officeuser')?>"></label>
<label>MySQL password<input required name="db_password" type="password" autocomplete="new-password"></label>
<label>Admin name<input required name="name" maxLength="100" value="<?=esc($_POST['name']??'SHK Engineering')?>"></label>
<label>Admin email<input required name="email" type="email" maxLength="254" value="<?=esc($_POST['email']??'shkengineeringbd@gmail.com')?>"></label>
<label>Admin password<input required name="admin_password" type="password" minLength="12" maxLength="72" autocomplete="new-password"></label>
<label>Confirm admin password<input required name="confirm_password" type="password" minLength="12" maxLength="72" autocomplete="new-password"></label>
<p class="form-note">কমপক্ষে ১২ অক্ষরের আলাদা পাসওয়ার্ড দিন। এই অ্যাপে ইমেইল যাচাই বা ইমেইলে পাসওয়ার্ড রিসেট পাঠানোর ব্যবস্থা নেই। কর্মীর নিবন্ধন অনুমোদনের আগে পরিচয় যাচাই করুন।<br>Use a unique password of 12+ characters. Registration emails are self-declared; the administrator must confirm employee identity before approval. No email delivery or email verification is configured.</p>
<button class="btn primary registration-submit">ইনস্টল করুন / Install</button></form><?php endif; ?></section></main></body></html>
