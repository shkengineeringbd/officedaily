<?php
declare(strict_types=1);

if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function insights_get(array $actor): never {
    $month = finance_month($_GET);
    if (!$actor['globalRead'] && !$actor['manageBranch']) fail('ড্যাশবোর্ড দেখার অনুমতি নেই।',403);
    $branches = $actor['globalRead']
        ? rows('SELECT id,name FROM od_branches WHERE active=1 ORDER BY name')
        : rows('SELECT id,name FROM od_branches WHERE id=? AND active=1', [$actor['branchId']]);
    foreach ($branches as &$branch) {
        $id = $branch['id'];
        $branch['tasks'] = rows("SELECT t.status,COUNT(*) AS total FROM od_tasks t JOIN od_members m ON m.id=t.assignee_id
            JOIN od_staff_assignments s ON s.account_id=m.user_id WHERE s.branch_id=? GROUP BY t.status", [$id]);
        $branch['attendance'] = office_access($actor,'attendance') ? rows("SELECT a.status,COUNT(*) AS total FROM od_attendance a
            JOIN od_staff_assignments s ON s.account_id=a.account_id WHERE s.branch_id=? AND a.work_date LIKE ? GROUP BY a.status",[$id,$month.'-%']) : [];
        $branch['budget'] = rows("SELECT b.status,COUNT(*) AS total,COALESCE(SUM(b.amount),0.00) AS amount
            FROM od_budgets b JOIN od_budget_branches s ON s.budget_id=b.id WHERE s.branch_id=? AND b.submitted_on LIKE ? GROUP BY b.status",[$id,$month.'-%']);
    }
    unset($branch);
    output(['branches'=>$branches,'month'=>$month]);
}

function inbox_get(array $actor): never {
    $role = budget_role($actor);
    $stages = match ($role) {'gm'=>'0','cfo'=>'1,3','md'=>'0,1,2',default=>'-1'};
    [$scope,$args] = staff_budget_scope($actor);
    $budgets = rows("SELECT b.serial,b.reason,b.branch,b.stage,b.submitted_on FROM od_budgets b
        WHERE b.status='pending' AND b.stage IN ($stages) AND $scope ORDER BY b.submitted_on,b.serial LIMIT 100",$args);
    $returned = rows("SELECT serial,reason,branch,submitted_on FROM od_budgets WHERE creator_id=? AND status='rejected' ORDER BY serial DESC LIMIT 20",[$actor['userId']]);
    $leaves = [];
    if (office_access($actor,'leave')===2) {
        $global = $actor['globalRead'];
        $where = $global ? '1=1' : 's.branch_id=?';
        $leaves = rows("SELECT l.id,l.account_id,a.name,l.start_date,l.end_date,l.created_at FROM od_leave_requests l
            JOIN od_accounts a ON a.id=l.account_id LEFT JOIN od_staff_assignments s ON s.account_id=a.id
            WHERE l.status='pending' AND l.account_id<>? AND $where ORDER BY l.created_at LIMIT 100",$global ? [$actor['userId']] : [$actor['userId'],$actor['branchId']]);
    }
    $uid = $actor['userId'];
    $chat = one("SELECT COUNT(*) AS total FROM od_chat_messages m JOIN od_chat_rooms r ON r.id=m.room_id
        LEFT JOIN od_chat_members me ON me.room_id=r.id AND me.account_id=?
        WHERE (r.kind='office' OR me.account_id IS NOT NULL) AND m.sender_id<>? AND m.seq>COALESCE(me.last_read_seq,0)",[$uid,$uid]);
    $days = (int)(one("SELECT meta_value FROM od_app_meta WHERE meta_key='approval_due_days'")['meta_value']??0);
    if ($days > 0) {
        foreach ($budgets as &$budget) $budget['due_on']=(new DateTimeImmutable($budget['submitted_on']))->modify('+'.$days.' days')->format('Y-m-d');
        unset($budget);
        foreach ($leaves as &$leave) $leave['due_on']=(new DateTimeImmutable($leave['created_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->modify('+'.$days.' days')->format('Y-m-d');
        unset($leave);
    }
    output(['budgets'=>$budgets,'returned'=>$returned,'leaves'=>$leaves,'unreadChat'=>(int)$chat['total'],'dueDays'=>$days,'canConfigure'=>$actor['role']==='owner']);
}
