<?php
declare(strict_types=1);

if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function mfa_storage(): void {
    query("CREATE TABLE IF NOT EXISTS od_mfa (
        account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        nonce VARBINARY(12) NOT NULL,
        tag VARBINARY(16) NOT NULL,
        cipher VARBINARY(64) NOT NULL,
        last_step BIGINT NOT NULL,
        FOREIGN KEY(account_id) REFERENCES od_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mfa_base32(string $secret): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($secret) as $byte) $bits .= str_pad(decbin(ord($byte)),8,'0',STR_PAD_LEFT);
    $encoded = '';
    foreach (str_split($bits,5) as $part) $encoded .= $alphabet[bindec(str_pad($part,5,'0'))];
    return $encoded;
}

function mfa_key(): string {
    return hash('sha256','office-daily-mfa|'.config()['app_secret'],true);
}

function mfa_step(string $secret,mixed $code): ?int {
    if (!is_string($code)||!preg_match('/^\d{6}$/D',$code)) return null;
    $now = intdiv(time(),30);
    foreach ([$now,$now-1,$now+1] as $step) {
        $digest = hash_hmac('sha1',pack('N2',0,$step),$secret,true);
        $offset = ord($digest[19]) & 15;
        $value = unpack('N',substr($digest,$offset,4))[1] & 0x7fffffff;
        if (hash_equals(str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT),$code)) return $step;
    }
    return null;
}

function mfa_check(string $account,mixed $code): void {
    transaction(function () use ($account,$code) {
        $item = one('SELECT * FROM od_mfa WHERE account_id=? FOR UPDATE',[$account]);
        if (!$item) return;
        $secret = openssl_decrypt($item['cipher'],'aes-256-gcm',mfa_key(),OPENSSL_RAW_DATA,$item['nonce'],$item['tag'],$account);
        $step = $secret === false ? null : mfa_step($secret,$code);
        if ($step === null || $step <= (int)$item['last_step']) fail('অথেনটিকেটরের নতুন ছয় অঙ্কের কোড দিন।',401);
        query('UPDATE od_mfa SET last_step=? WHERE account_id=?',[$step,$account]);
    });
}

function mfa_action(array $actor,array $input,string $action): bool {
    if (!in_array($action,['mfa_start','mfa_enable'],true)) return false;
    if (!finance_head($actor)) fail('এই অ্যাকাউন্টে MFA সেটআপের অনুমতি নেই।',403);
    $account = require_account();
    $id = $account['id'];
    if (one('SELECT account_id FROM od_mfa WHERE account_id=?',[$id])) fail('MFA ইতিমধ্যে চালু।',409);
    throttle('mfa-setup',$id,12);
    if ($action === 'mfa_start') {
        $password = $input['password']??null;
        if (!is_string($password)||strlen($password)>72||!password_verify($password,$account['password_hash'])) fail('পাসওয়ার্ড সঠিক নয়।',403);
        $secret = random_bytes(20);
        $_SESSION['mfa_pending'] = ['id'=>$id,'secret'=>$secret,'expires'=>time()+600];
        output(['secret'=>mfa_base32($secret)]);
    }
    $pending = $_SESSION['mfa_pending']??[];
    if (($pending['id']??null)!==$id||($pending['expires']??0)<time()) fail('সেটআপের সময় শেষ হয়েছে। আবার শুরু করুন।',409);
    $step = mfa_step($pending['secret'],$input['code']??null);
    if ($step === null) fail('অথেনটিকেটরের কোড সঠিক নয়।');
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($pending['secret'],'aes-256-gcm',mfa_key(),OPENSSL_RAW_DATA,$nonce,$tag,$id);
    if ($cipher === false) fail('MFA সেটআপ সম্পন্ন হয়নি।',503);
    transaction(function () use ($actor,$id,$nonce,$tag,$cipher,$step) {
        query('INSERT INTO od_mfa(account_id,nonce,tag,cipher,last_step) VALUES(?,?,?,?,?)',[$id,$nonce,$tag,$cipher,$step]);
        query('UPDATE od_accounts SET session_version=session_version+1 WHERE id=?',[$id]);
        audit($actor,'MFA চালু',$id);
    });
    unset($_SESSION['mfa_pending']);
    sign_in(one('SELECT id,session_version FROM od_accounts WHERE id=?',[$id]));
    return true;
}
