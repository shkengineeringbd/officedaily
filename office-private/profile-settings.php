<?php
declare(strict_types=1);

if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function profile_settings_storage(): void {
    query("CREATE TABLE IF NOT EXISTS od_profile_contact_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        kind VARCHAR(8) NOT NULL, nonce VARBINARY(12) NOT NULL, tag VARBINARY(16) NOT NULL,
        cipher VARBINARY(1024) NOT NULL, changed_at VARCHAR(30) NOT NULL,
        KEY profile_contact_history(account_id,id),
        FOREIGN KEY(account_id) REFERENCES od_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    query("CREATE TABLE IF NOT EXISTS od_profile_payment_methods (
        id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        kind VARCHAR(8) NOT NULL, nonce VARBINARY(12) NOT NULL, tag VARBINARY(16) NOT NULL,
        cipher VARBINARY(4096) NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1,
        KEY profile_payment_account(account_id,id),
        FOREIGN KEY(account_id) REFERENCES od_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function profile_secret_key(): string {
    return hash('sha256','office-profile-settings|'.config()['app_secret'],true);
}

function profile_seal(string $value,string $context): array {
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($value,'aes-256-gcm',profile_secret_key(),OPENSSL_RAW_DATA,$nonce,$tag,$context);
    if ($cipher===false) fail('তথ্য সংরক্ষণ করা যায়নি।',500);
    return [$nonce,$tag,$cipher];
}

function profile_open(array $row,string $context): string {
    $value = openssl_decrypt($row['cipher'],'aes-256-gcm',profile_secret_key(),OPENSSL_RAW_DATA,$row['nonce'],$row['tag'],$context);
    if ($value===false) fail('সংরক্ষিত তথ্য খোলা যায়নি।',500);
    return $value;
}

function profile_remember_contact(string $account,string $kind,?string $old,?string $new): void {
    if ($old===null || $old==='' || $old===$new) return;
    $sealed = profile_seal($old,$account.'|contact|'.$kind);
    query('INSERT INTO od_profile_contact_history(account_id,kind,nonce,tag,cipher,changed_at) VALUES(?,?,?,?,?,?)',
        [$account,$kind,...$sealed,now()]);
}

function profile_remove_replaced_photo(string $account,?string $old,string $new): void {
    if ($old===null || $old===$new) return;
    $legacyReference = one("SELECT sequence FROM od_profile_events WHERE account_id=?
        AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(snapshot),snapshot,'{}'),'$.photo'))=? LIMIT 1",[$account,$old]);
    if (!$legacyReference) query("DELETE FROM od_identity_files WHERE account_id=? AND id=? AND kind='photo'",[$account,$old]);
}

function profile_can_view_payments(array $actor, string $id): bool {
    if ($id === $actor['userId'] || $actor['role'] === 'owner') return true;
    return (!empty($actor['globalRead']) || !empty($actor['manageBranch']))
        && staff_account_allowed($actor, $id);
}

function profile_settings_get(array $actor): never {
    $self = $actor['userId'];
    $id = isset($_GET['accountId']) ? id_input($_GET,'accountId') : $self;
    if (!profile_can_view_payments($actor,$id)) fail('পেমেন্ট তথ্য দেখার অনুমতি নেই।',403);
    $current = one('SELECT a.name,a.email,h.phone,h.designation,p.full_name,p.photo_id,p.nid_id FROM od_accounts a
        LEFT JOIN od_hr_profiles h ON h.account_id=a.id LEFT JOIN od_identity_profiles p ON p.account_id=a.id WHERE a.id=?',[$id]);
    if (!$current) fail('ইউজার পাওয়া যায়নি।',404);
    $canHistory = $id===$self || $actor['role']==='owner';
    $contacts = [];
    foreach ($canHistory ? rows('SELECT kind,nonce,tag,cipher,changed_at FROM od_profile_contact_history
        WHERE account_id=? ORDER BY id DESC',[$id]) : [] as $entry) {
        $contacts[] = ['kind'=>$entry['kind'],'value'=>profile_open($entry,$id.'|contact|'.$entry['kind']),'changedAt'=>$entry['changed_at']];
    }
    $nids = !$canHistory || $current['nid_id']===null ? [] : rows("SELECT id,created_at FROM od_identity_files
        WHERE account_id=? AND kind='nid' AND id<>? ORDER BY created_at DESC,id DESC",[$id,$current['nid_id']]);
    $payments = [];
    {
        foreach (rows('SELECT * FROM od_profile_payment_methods WHERE account_id=? ORDER BY kind,id',[$id]) as $row) {
            $details = json_decode(profile_open($row,$id.'|payment|'.$row['id'].'|'.$row['kind']),true,512,JSON_THROW_ON_ERROR);
            $payments[] = ['id'=>$row['id'],'kind'=>$row['kind'],'revision'=>(int)$row['revision'],'details'=>$details];
        }
    }
    if ($id!==$self) audit($actor,'প্রোফাইল পেমেন্ট তথ্য দেখা',$id);
    output(['current'=>$current,'contacts'=>$contacts,'nids'=>$nids,'payments'=>$payments,'isSelf'=>$id===$self,'canHistory'=>$canHistory,'assignment'=>staff_assignment($id)]);
}

function profile_update_contact(array $actor,array $input): void {
    $id = $actor['userId'];
    $email = email_input($input);
    $phone = ($input['phone']??'')==='' ? null : phone_input($input['phone']);
    $password = password_input($input);
    throttle('profile-contact',$id,10);
    try {
        $updated = transaction(function () use ($actor,$id,$email,$phone,$password) {
            $account = one('SELECT email,password_hash,session_version FROM od_accounts WHERE id=? FOR UPDATE',[$id]);
            if (!$account || !password_verify($password,$account['password_hash'])) fail('বর্তমান পাসওয়ার্ড সঠিক নয়।',403);
            hr_touch($id);
            $oldPhone = one('SELECT phone FROM od_hr_profiles WHERE account_id=? FOR UPDATE',[$id])['phone'];
            if ($account['email']===$email && $oldPhone===$phone) return null;
            profile_remember_contact($id,'email',$account['email'],$email);
            profile_remember_contact($id,'phone',$oldPhone,$phone);
            if ($account['email']!==$email) {
                query('UPDATE od_members SET email=? WHERE user_id=?',[$email,$id]);
                query('UPDATE od_registration_requests SET email=? WHERE user_id=?',[$email,$id]);
            }
            query('UPDATE od_accounts SET email=?,session_version=session_version+1 WHERE id=?',[$email,$id]);
            query('UPDATE od_hr_profiles SET phone=? WHERE account_id=?',[$phone,$id]);
            query("UPDATE od_password_recovery SET status='revoked',token_hash=NULL WHERE account_id=? AND status IN ('pending','approved')",[$id]);
            audit($actor,'প্রোফাইল যোগাযোগের তথ্য পরিবর্তন',$id);
            return ['id'=>$id,'session_version'=>(int)$account['session_version']+1];
        });
    } catch (PDOException $error) {
        if ($error->getCode()==='23000') fail('ইমেইল বা মোবাইল নম্বরটি অন্য অ্যাকাউন্টে আছে।',409);
        throw $error;
    }
    if ($updated) sign_in($updated);
}

function profile_save_payment(array $actor,array $input,string $action): void {
    $account = $actor['userId'];
    $id = $input['id']??null;
    if ($id!==null) $id = budget_uuid($input,'id');
    $revision = $input['revision']??null;
    if ($id!==null && (!is_int($revision)||$revision<1)) fail('পেমেন্ট তথ্য রিফ্রেশ করে আবার চেষ্টা করুন।',409);

    if ($action==='profile_payment_remove') {
        if ($id===null) fail('পেমেন্ট তথ্য বেছে নিন।');
        $deleted = query('DELETE FROM od_profile_payment_methods WHERE id=? AND account_id=? AND revision=?',[$id,$account,$revision])->rowCount();
        if (!$deleted) fail('পেমেন্ট তথ্য পাওয়া যায়নি বা বদলেছে।',409);
        return;
    }
    $kind = $input['kind']??'';
    if (!in_array($kind,['bank','bkash'],true)) fail('ব্যাংক বা bKash বেছে নিন।');
    $details = $kind==='bank'
        ? ['bank'=>strval_input($input,'bank',100),'branch'=>strval_input($input,'branch',150),
           'holder'=>strval_input($input,'holder',100),'number'=>strval_input($input,'number',64),
           'routing'=>strval_input($input,'routing',32,true)]
        : ['mobile'=>phone_input($input['mobile']??null),'holder'=>strval_input($input,'holder',100,true)];
    $record = $id??uid();
    $sealed = profile_seal(json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$account.'|payment|'.$record.'|'.$kind);
    if ($id===null) {
        query('INSERT INTO od_profile_payment_methods(id,account_id,kind,nonce,tag,cipher) VALUES(?,?,?,?,?,?)',
            [$record,$account,$kind,...$sealed]);
        return;
    }
    $changed = query('UPDATE od_profile_payment_methods SET kind=?,nonce=?,tag=?,cipher=?,revision=revision+1
        WHERE id=? AND account_id=? AND revision=?',[$kind,...$sealed,$id,$account,$revision])->rowCount();
    if (!$changed) fail('পেমেন্ট তথ্য পাওয়া যায়নি বা বদলেছে।',409);
}

function profile_settings_action(array $actor,array $input,string $action): void {
    $id = $actor['userId'];
    if ($action==='profile_contact_update') {
        profile_update_contact($actor,$input);
        return;
    }
    if ($action==='profile_photo_update') {
        transaction(function () use ($actor,$input,$id) {
            $current = one('SELECT photo_id FROM od_identity_profiles WHERE account_id=? FOR UPDATE',[$id]);
            if (!$current) fail('আগে ব্যক্তিগত প্রোফাইল জমা দিন।',409);
            if (($input['expectedPhotoId']??null)!==$current['photo_id']) fail('ছবি বদলেছে। রিফ্রেশ করে আবার চেষ্টা করুন।',409);
            $photo = staff_file($input,'photo',$id,'photo',null);
            query('UPDATE od_identity_profiles SET photo_id=?,revision=revision+1 WHERE account_id=?',[$photo,$id]);
            profile_remove_replaced_photo($id,$current['photo_id'],$photo);
            audit($actor,'প্রোফাইল ছবি পরিবর্তন',$id);
        });
        return;
    }
    profile_save_payment($actor,$input,$action);
}

