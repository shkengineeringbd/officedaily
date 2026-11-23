<?php
declare(strict_types=1);
if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function preview_owner(): array {
    $owner = account(true);
    if (!$owner) fail('লগইন করুন।',401);
    if ((int)$owner['is_owner']!==1 || !access_enabled($owner['id'])) fail('শুধু অ্যাডমিন প্রিভিউ দেখতে পারবেন।',403);
    return $owner;
}

function preview_target(string $id): ?array {
    return one('SELECT a.id,a.name,a.email,a.is_owner,a.session_version FROM od_accounts a
        JOIN od_members m ON m.user_id=a.id AND m.email=a.email AND m.active=1
        JOIN od_hr_profiles h ON h.account_id=a.id AND h.access_enabled=1
        JOIN od_staff_assignments s ON s.account_id=a.id
        JOIN od_branches b ON b.id=s.branch_id AND b.active=1
        WHERE a.id=? AND a.is_owner=0',[$id]);
}

function preview_account(array $owner): array {
    if (empty($_SESSION['role_preview_account'])) return $owner;
    if ((int)$owner['is_owner']!==1 || !access_enabled($owner['id'])) fail('প্রিভিউর অনুমতি নেই।',403);
    $target = preview_target((string)$_SESSION['role_preview_account']);
    if (!$target) fail('এই ইউজারের প্রিভিউ এখন পাওয়া যাচ্ছে না। প্রিভিউ বন্ধ করে অ্যাডমিনে ফিরুন।',403);
    return $target;
}

function preview_assert_write(string $action): void {
    if (!empty($_SESSION['role_preview_account']) && !in_array($action,['preview_start','preview_stop','logout'],true)) {
        fail('প্রিভিউতে তথ্য পরিবর্তন করা যাবে না। আগে অ্যাডমিন ভিউতে ফিরুন।',403);
    }
}

function preview_state(): never {
    $owner = account(true);
    if (!$owner || (int)$owner['is_owner']!==1 || !access_enabled($owner['id'])) output(['canPreview'=>false]);
    $users = rows('SELECT a.id,a.name,s.post,b.name AS branch_name FROM od_accounts a
        JOIN od_members m ON m.user_id=a.id AND m.email=a.email AND m.active=1
        JOIN od_hr_profiles h ON h.account_id=a.id AND h.access_enabled=1
        JOIN od_staff_assignments s ON s.account_id=a.id
        JOIN od_branches b ON b.id=s.branch_id AND b.active=1
        WHERE a.is_owner=0 ORDER BY s.post,b.name,a.name,a.id');
    $id = $_SESSION['role_preview_account']??null;
    $selected = null;
    foreach ($users as $user) if ($user['id']===$id) { $selected=$user; break; }
    output(['canPreview'=>true,'active'=>$id!==null,'selected'=>$selected,'users'=>$users]);
}

function preview_action(array $input,string $action): never {
    $owner = preview_owner();
    if ($action==='preview_start') {
        $id=id_input($input,'accountId');
        $target=preview_target($id);
        if (!$target) fail('সক্রিয় ইউজার বেছে নিন।',404);
        $actor=staff_decorate(['id'=>'owner','userId'=>$owner['id'],'email'=>$owner['email'],'name'=>$owner['name'],'role'=>'owner','viewAll'=>1],false);
        audit($actor,'ইউজার ভিউ প্রিভিউ শুরু',$id);
        $_SESSION['role_preview_account']=$id;
    } else {
        unset($_SESSION['role_preview_account']);
    }
    output(['ok'=>true]);
}
