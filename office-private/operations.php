<?php
declare(strict_types=1);

if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

const OFFICE_ACCESS_MODULES = ['profiles', 'attendance', 'leave', 'payroll', 'notices'];

function operations_storage(): void {
    query("CREATE TABLE IF NOT EXISTS od_module_access (
        account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        module VARCHAR(20) NOT NULL,
        access_level TINYINT NOT NULL,
        PRIMARY KEY(account_id,module),
        FOREIGN KEY(account_id) REFERENCES od_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    query("CREATE TABLE IF NOT EXISTS od_notices (
        id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        branch_id VARCHAR(36) NULL,
        title VARCHAR(150) NOT NULL,
        body TEXT NOT NULL,
        author_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        author_name VARCHAR(100) NOT NULL,
        created_at VARCHAR(30) NOT NULL,
        KEY notice_branch(branch_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function office_access(array $actor, string $module): int {
    if ($actor['role'] === 'owner') return 2;
    $grant = one('SELECT access_level FROM od_module_access WHERE account_id=? AND module=?', [$actor['userId'], $module]);
    if ($grant) return (int)$grant['access_level'];
    $global = $actor['globalRead'];
    $manager = $actor['manageBranch'];
    $post = $actor['post'];
    return match ($module) {
        'profiles' => $global || $manager ? 2 : 0,
        'attendance', 'leave' => $manager || ($global && $post === 'gm') ? 2 : (int)$global,
        'payroll' => (int)($global || $manager),
        'notices' => $manager || ($global && in_array($post, ['gm','cfo'], true)) ? 2 : 1,
        default => 0,
    };
}

function office_module_scope(array $actor, string $module, string $account): bool {
    if ($actor['userId'] === $account) return true;
    if (!office_access($actor, $module)) return false;
    if ($actor['globalRead']) return true;
    if (!$actor['branchId']) return false;
    $target = staff_assignment($account);
    return ($target['branch_id'] ?? null) === $actor['branchId'];
}

function office_may_edit(array $actor, string $module, string $account): bool {
    return office_access($actor, $module) === 2 && office_module_scope($actor, $module, $account);
}

function operations_get(array $actor): never {
    $view = $_GET['operations'] ?? '';
    if ($view === 'mfa') {
        if (!finance_head($actor)) fail('MFA সেটআপের অনুমতি নেই।',403);
        output(['enabled'=>(bool)one('SELECT account_id FROM od_mfa WHERE account_id=?',[$actor['userId']])]);
    }
    if ($view === 'payslip') payslip_get($actor);
    if ($view === 'insights') insights_get($actor);
    if ($view === 'inbox') inbox_get($actor);
    if ($view === 'finance') finance_get($actor);
    if ($view === 'releases') release_overview($actor);
    if ($view === 'access') {
        hr_owner($actor);
        output([
            'modules' => OFFICE_ACCESS_MODULES,
            'users' => rows('SELECT a.id,a.name,a.email FROM od_accounts a JOIN od_members m ON m.user_id=a.id WHERE a.is_owner=0 AND m.active=1 ORDER BY a.name'),
            'grants' => rows('SELECT account_id,module,access_level FROM od_module_access'),
            'backupDriveAvailable' => (bool)getenv('OFFICE_BACKUP_DRIVE_REMOTE'),
        ]);
    }
    if ($view === 'notices') {
        $level = office_access($actor, 'notices');
        if (!$level) fail('নোটিশ দেখার অনুমতি নেই।', 403);
        $canBroadcast = $actor['role'] === 'owner' || ($actor['globalRead'] && in_array($actor['post'], ['gm','cfo'], true));
        $notices = rows(
            'SELECT id,title,body,author_name,created_at,branch_id FROM od_notices WHERE branch_id IS NULL OR branch_id=? OR author_id=? ORDER BY created_at DESC,id DESC LIMIT 100',
            [$actor['branchId'], $canBroadcast ? $actor['userId'] : null]
        );
        output([
            'notices' => $notices,
            'canPublish' => $level === 2 && ($canBroadcast || (bool)$actor['branchId']),
            'canBroadcast' => $canBroadcast,
            'branches' => $canBroadcast ? rows('SELECT id,name FROM od_branches WHERE active=1 ORDER BY name') : [],
        ]);
    }
    fail('সঠিক বিভাগ বেছে নিন।', 404);
}

function operations_action(array $actor, array $input, string $action): bool {
    if ($action === 'operations_access') {
        hr_owner($actor);
        $account = hr_target($input);
        if ((int)$account['is_owner'] === 1) fail('Admin-এর অ্যাক্সেস বদলানো যাবে না।', 403);
        $module = strval_input($input, 'module', 20);
        $level = $input['level'] ?? null;
        if (!in_array($module, OFFICE_ACCESS_MODULES, true) || !in_array($level, [-1,0,1,2], true)) fail('সঠিক অনুমতি দিন।');
        transaction(function () use ($actor, $account, $module, $level) {
            if ($level === -1) {
                query('DELETE FROM od_module_access WHERE account_id=? AND module=?', [$account['id'], $module]);
            } else {
                query('INSERT INTO od_module_access(account_id,module,access_level) VALUES(?,?,?) ON DUPLICATE KEY UPDATE access_level=VALUES(access_level)', [$account['id'], $module, $level]);
            }
            audit($actor, 'অপশনের অ্যাক্সেস বদল', $account['id'].' '.$module.' '.$level);
        });
        return true;
    }
    if ($action === 'operations_due_days') {
        hr_owner($actor);
        $days = $input['days'] ?? null;
        if (!is_int($days) || $days < 0 || $days > 365) fail('সময়সীমা ০ থেকে ৩৬৫ দিনের মধ্যে দিন।');
        transaction(function () use ($actor,$days) {
            query("INSERT INTO od_app_meta(meta_key,meta_value) VALUES('approval_due_days',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",[(string)$days]);
            audit($actor,'অনুমোদনের সময়সীমা বদল',(string)$days);
        });
        return true;
    }
    if ($action !== 'operations_notice') return false;
    if (office_access($actor, 'notices') !== 2) fail('নোটিশ প্রকাশের অনুমতি নেই।', 403);
    $canBroadcast = $actor['role'] === 'owner' || ($actor['globalRead'] && in_array($actor['post'], ['gm','cfo'], true));
    $branch = $canBroadcast ? strval_input($input, 'branchId', 36, true) : $actor['branchId'];
    if (!$canBroadcast && !$branch) fail('সক্রিয় ব্রাঞ্চ প্রয়োজন।', 403);
    if ($branch && !one('SELECT id FROM od_branches WHERE id=? AND active=1', [$branch])) fail('সক্রিয় ব্রাঞ্চ বেছে নিন।');
    $title = strval_input($input, 'title', 150);
    $body = strval_input($input, 'body', 4000);
    transaction(function () use ($actor, $branch, $title, $body) {
        $id = uid();
        query('INSERT INTO od_notices(id,branch_id,title,body,author_id,author_name,created_at) VALUES(?,?,?,?,?,?,?)', [$id, $branch ?: null, $title, $body, $actor['userId'], $actor['name'], now()]);
        audit($actor, 'অফিস নোটিশ প্রকাশ', $id);
    });
    return true;
}
