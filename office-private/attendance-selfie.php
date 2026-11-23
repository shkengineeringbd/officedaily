<?php
declare(strict_types=1);
if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function attendance_selfie_storage(): void {
    query("CREATE TABLE IF NOT EXISTS od_attendance_selfies (
        account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        work_date CHAR(10) NOT NULL, captured_at VARCHAR(30) NOT NULL,
        expires_at BIGINT NOT NULL, nonce VARBINARY(12) NOT NULL,
        tag VARBINARY(16) NOT NULL, cipher MEDIUMBLOB NOT NULL,
        PRIMARY KEY(account_id,work_date), KEY selfie_expiry(expires_at),
        FOREIGN KEY(account_id) REFERENCES od_accounts(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function attendance_selfie_cleanup(): void {
    query('DELETE FROM od_attendance_selfies WHERE expires_at<=?', [time()]);
}
function attendance_selfie_key(): string {
    return hash('sha256', 'office-attendance-selfie|'.config()['app_secret'], true);
}
function attendance_selfie_get(array $a): never {
    $mode = $_GET['attendanceSelfie'] ?? 'state';
    if ($mode === 'photo') {
        $date = day_input($_GET);
        // Selfies are private to their owner, including in an authorized read-only preview.
        $r = one('SELECT * FROM od_attendance_selfies WHERE account_id=? AND work_date=? AND expires_at>?', [$a['userId'],$date,time()]);
        if (!$r) fail('ছবির মেয়াদ শেষ হয়েছে বা ছবি নেই।',404);
        $plain = openssl_decrypt($r['cipher'],'aes-256-gcm',attendance_selfie_key(),OPENSSL_RAW_DATA,$r['nonce'],$r['tag'],$a['userId'].'|'.$date);
        if ($plain === false) fail('ছবি খোলা যায়নি।',500);
        header('Content-Type: image/jpeg'); header('Content-Length: '.strlen($plain));
        echo $plain; exit;
    }
    if ($mode !== 'state') fail('সঠিক হাজিরা বিভাগ দিন।');
    $month = $_GET['month'] ?? substr(today(),0,7);
    if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)) fail('সঠিক মাস দিন।');
    $photo = one('SELECT captured_at,expires_at FROM od_attendance_selfies WHERE account_id=? AND work_date=? AND expires_at>?',[$a['userId'],today(),time()]);
    output(['today'=>today(),'month'=>$month,'name'=>$a['name'],'readOnly'=>!empty($_SESSION['role_preview_account']),
        'current'=>one('SELECT * FROM od_attendance WHERE account_id=? AND work_date=?',[$a['userId'],today()]),
        'entries'=>rows('SELECT work_date,status,check_in,check_out,note FROM od_attendance WHERE account_id=? AND work_date LIKE ? ORDER BY work_date DESC',[$a['userId'],$month.'-%']),
        'photo'=>$photo,'serverTime'=>now()]);
}
function attendance_selfie_action(array $a,array $v,string $action): bool {
    if (!in_array($action,['attendance_selfie_begin','attendance_selfie_checkin'],true)) return false;
    if ($action === 'attendance_selfie_begin') {
        if (one('SELECT account_id FROM od_attendance WHERE account_id=? AND work_date=?',[$a['userId'],today()])) fail('আজকের হাজিরা আগে থেকেই আছে।',409);
        $_SESSION['attendance_capture']=['token'=>bin2hex(random_bytes(32)),'account'=>$a['userId'],'date'=>today(),'expires'=>time()+120];
        output(['token'=>$_SESSION['attendance_capture']['token'],'expiresAt'=>$_SESSION['attendance_capture']['expires']]);
    }
    $challenge=$_SESSION['attendance_capture']??null;
    if (!$challenge || $challenge['account']!==$a['userId'] || $challenge['date']!==today() || $challenge['expires']<time() || !is_string($v['token']??null) || !hash_equals($challenge['token'],$v['token'])) fail('ক্যামেরা আবার খুলে নতুন সেলফি তুলুন।',409);
    $data=$v['image']??null;
    if (!is_string($data) || strlen($data)>1500000 || !str_starts_with($data,'data:image/jpeg;base64,')) fail('সঠিক JPEG সেলফি দিন।');
    $plain=base64_decode(substr($data,23),true);
    if ($plain===false || strlen($plain)<1000 || strlen($plain)>1000000) fail('ছবির আকার সঠিক নয়।');
    $size=@getimagesizefromstring($plain);
    if (!$size || $size[2]!==IMAGETYPE_JPEG || min($size[0],$size[1])<120 || max($size[0],$size[1])>1280) fail('সঠিক ক্যামেরার ছবি দিন।');
    $date=today();$stamp=now();$expires=time()+86400;
    $nonce=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',attendance_selfie_key(),OPENSSL_RAW_DATA,$nonce,$tag,$a['userId'].'|'.$date);
    if ($cipher===false) fail('সেলফি সংরক্ষণ করা যায়নি।',500);
    transaction(function()use($a,$date,$stamp,$expires,$nonce,$tag,$cipher){
        one('SELECT id FROM od_accounts WHERE id=? FOR UPDATE',[$a['userId']]);
        if ($date!==today()) fail('দিন বদলেছে। নতুন করে ছবি তুলুন।',409);
        if (one('SELECT account_id FROM od_attendance WHERE account_id=? AND work_date=?',[$a['userId'],$date])) fail('আজকের হাজিরা আগে থেকেই আছে।',409);
        query("INSERT INTO od_attendance(account_id,work_date,status,check_in,note,updated_at) VALUES(?,?,'Present',?,'',?)",[$a['userId'],$date,$stamp,$stamp]);
        query('INSERT INTO od_attendance_selfies(account_id,work_date,captured_at,expires_at,nonce,tag,cipher) VALUES(?,?,?,?,?,?,?)',[$a['userId'],$date,$stamp,$expires,$nonce,$tag,$cipher]);
    });
    unset($_SESSION['attendance_capture']);
    audit($a,'সেলফি দিয়ে হাজিরা',$date);
    return true;
}
