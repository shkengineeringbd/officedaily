<?php
declare(strict_types=1);

if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function finance_storage(): void {
    query("CREATE TABLE IF NOT EXISTS od_payroll_batches (
        id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        branch_id VARCHAR(36) NOT NULL,
        month CHAR(7) NOT NULL,
        snapshot MEDIUMTEXT NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        status VARCHAR(16) NOT NULL,
        budget_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
        submitted_by VARCHAR(36) NOT NULL,
        updated_at VARCHAR(30) NOT NULL,
        UNIQUE KEY branch_month(branch_id,month),
        UNIQUE KEY payroll_budget(budget_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    query("CREATE TABLE IF NOT EXISTS od_budget_settlements (
        budget_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        actual DECIMAL(12,2) NOT NULL,
        returned DECIMAL(12,2) NOT NULL,
        voucher VARCHAR(150) NOT NULL,
        note TEXT NOT NULL,
        updated_at VARCHAR(30) NOT NULL,
        FOREIGN KEY(budget_id) REFERENCES od_budgets(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function finance_month(array $input): string {
    $month = strval_input($input,'month',7);
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)) fail('সঠিক মাস বেছে নিন।');
    return $month;
}

function finance_head(array $actor): bool {
    return $actor['globalRead'] && ($actor['role'] === 'owner' || in_array($actor['post'],['gm','cfo','md'],true));
}

function finance_get(array $actor): never {
    $head = finance_head($actor) && office_access($actor,'payroll') > 0;
    $manager = $actor['manageBranch'] && office_access($actor,'payroll') > 0;
    if (!$head && !$manager) fail('এই বিভাগের অনুমতি নেই।',403);
    $month = finance_month($_GET);
    $scope = $head ? '1=1' : 'p.branch_id=?';
    $args = $head ? [$month] : [$month,$actor['branchId']];
    $batches = rows("SELECT p.*,b.name AS branch_name FROM od_payroll_batches p JOIN od_branches b ON b.id=p.branch_id WHERE p.month=? AND $scope ORDER BY b.name",$args);
    foreach ($batches as &$batch) $batch['snapshot'] = json_decode($batch['snapshot'],true,512,JSON_THROW_ON_ERROR);
    unset($batch);
    $payroll = $manager ? finance_payroll($actor['branchId'],$month) : [];
    output(['batches'=>$batches,'payroll'=>$payroll,'isHead'=>$head,'canSubmit'=>$manager,'month'=>$month]);
}

function finance_payroll(string $branch, string $month, bool $lock = false): array {
    return rows("SELECT p.account_id,a.name,p.basic_salary,p.medical_allowance,p.other_allowance,p.deductions,p.net_salary,p.payment_status
        FROM od_payroll p JOIN od_staff_assignments s ON s.account_id=p.account_id JOIN od_accounts a ON a.id=p.account_id
        WHERE s.branch_id=? AND p.month=? ORDER BY a.name,a.id".($lock?' FOR UPDATE':''),[$branch,$month]);
}

function finance_action(array $actor, array $input, string $action): bool {
    if (!in_array($action,['finance_submit','finance_finalize','finance_budget','finance_settle'],true)) return false;
    $head = finance_head($actor);
    if ($action === 'finance_submit') {
        if (!$actor['manageBranch'] || !office_access($actor,'payroll')) fail('নিজ ব্রাঞ্চের বেতন পাঠানোর অনুমতি নেই।',403);
        $month = finance_month($input);
        transaction(function () use ($actor,$month) {
            $branch = $actor['branchId'];
            one('SELECT id FROM od_branches WHERE id=? FOR UPDATE',[$branch]);
            $current = one('SELECT id FROM od_payroll_batches WHERE branch_id=? AND month=? FOR UPDATE',[$branch,$month]);
            if ($current) fail('এই মাসের বেতন আগে পাঠানো হয়েছে।',409);
            $payroll = finance_payroll($branch,$month,true);
            if (!$payroll) fail('এই মাসের বেতন রেকর্ড পাওয়া যায়নি।',409);
            $total = array_sum(array_map(fn($item)=>release_cents($item['net_salary']),$payroll));
            $id = uid();
            query("INSERT INTO od_payroll_batches(id,branch_id,month,snapshot,total,status,submitted_by,updated_at) VALUES(?,?,?,?,?,'submitted',?,?)",
                [$id,$branch,$month,json_encode($payroll,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),release_amount($total),$actor['userId'],now()]);
            audit($actor,'বেতন হেড অফিসে পাঠানো',$id);
        });
        return true;
    }
    if (!$head) fail('হেড অফিসের অনুমতি প্রয়োজন।',403);
    if ($action !== 'finance_settle' && !office_access($actor,'payroll')) fail('বেতনের অ্যাক্সেস নেই।',403);
    if ($action === 'finance_settle') {
        if ($actor['role'] !== 'owner' && $actor['post'] !== 'cfo') fail('বাজেট নিষ্পত্তির অনুমতি নেই।',403);
        $id = budget_uuid($input,'id');
        $actual = money_input($input,'actual');
        $returned = money_input($input,'returned');
        $voucher = strval_input($input,'voucher',150);
        $note = strval_input($input,'note',2000,true);
        transaction(function () use ($actor,$id,$actual,$returned,$voucher,$note) {
            $budget = one('SELECT amount,status FROM od_budgets WHERE id=? FOR UPDATE',[$id]);
            if (($budget['status']??null) !== 'released') fail('চূড়ান্ত ছাড়ের পর বাজেট নিষ্পত্তি করুন।',409);
            if (release_cents($actual)+release_cents($returned)!==release_cents($budget['amount'])) fail('প্রকৃত ব্যয় ও ফেরতের যোগফল ছাড়কৃত বাজেটের সমান হতে হবে।');
            query('INSERT INTO od_budget_settlements(budget_id,actual,returned,voucher,note,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE actual=VALUES(actual),returned=VALUES(returned),voucher=VALUES(voucher),note=VALUES(note),updated_at=VALUES(updated_at)',
                [$id,$actual,$returned,$voucher,$note,now()]);
            audit($actor,'বাজেট নিষ্পত্তি',$id);
        });
        return true;
    }
    $id = budget_uuid($input,'id');
    transaction(function () use ($actor,$input,$action,$id) {
        $batch = one('SELECT p.*,b.name AS branch_name FROM od_payroll_batches p JOIN od_branches b ON b.id=p.branch_id WHERE p.id=? FOR UPDATE',[$id]);
        if (!$batch) fail('বেতন তালিকা পাওয়া যায়নি।',404);
        if ($action === 'finance_finalize') {
            if ($batch['status'] !== 'submitted') fail('বেতনের অবস্থা বদলেছে।',409);
            query("UPDATE od_payroll_batches SET status='finalized',updated_at=? WHERE id=?",[now(),$id]);
            audit($actor,'মাসিক বেতন চূড়ান্ত',$id);
            if (($input['autoBudget'] ?? false) !== true) return;
        } elseif ($batch['status'] !== 'finalized') {
            fail('আগে বেতন চূড়ান্ত করুন।',409);
        }
        if ($batch['budget_id']) fail('এই বেতন আগে বাজেটে যোগ হয়েছে।',409);
        if (release_cents($batch['total']) <= 0) fail('বেতনের বাজেট শূন্যের বেশি হতে হবে।');
        if (in_array(false,budget_ready(),true)) fail('আগে সক্রিয় বাজেট অনুমোদনকারী নির্ধারণ করুন।',409);
        $budget = uid();
        $payment = budget_payment($input);
        $reason = 'মাসিক বেতন · '.$batch['month'].' · '.$batch['branch_name'];
        query('INSERT INTO od_budgets(id,creator_id,applicant_name,applicant_email,branch,reason,amount,method,payment_details,submitted_on,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [$budget,$actor['userId'],$actor['name'],$actor['email'],$batch['branch_name'],$reason,$batch['total'],$input['method'],$payment,today(),now()]);
        query('INSERT INTO od_budget_branches(budget_id,branch_id) VALUES(?,?)',[$budget,$batch['branch_id']]);
        query('UPDATE od_payroll_batches SET budget_id=?,updated_at=? WHERE id=?',[$budget,now(),$id]);
        budget_event($actor,$budget,'submitted',0,$reason,budget_access($actor));
        audit($actor,'বেতন বাজেটে যোগ',$id);
    });
    return true;
}
