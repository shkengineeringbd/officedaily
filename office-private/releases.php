<?php
declare(strict_types=1);

if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function release_storage(): void {
    query("CREATE TABLE IF NOT EXISTS od_release_installments (
        id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
        budget_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        released_on CHAR(10) NOT NULL,
        reference VARCHAR(100) NOT NULL,
        note TEXT NOT NULL,
        actor_id VARCHAR(36) NOT NULL,
        created_at VARCHAR(30) NOT NULL,
        voided TINYINT NOT NULL DEFAULT 0,
        KEY budget_installments(budget_id,voided),
        FOREIGN KEY(budget_id) REFERENCES od_budgets(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function release_cents(string $amount): int {
    [$whole, $fraction] = explode('.', $amount, 2);
    return (int)$whole * 100 + (int)$fraction;
}

function release_amount(int $cents): string {
    return intdiv($cents, 100).'.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function release_total(string $id): int {
    $total = one('SELECT COALESCE(SUM(amount),0.00) AS total FROM od_release_installments WHERE budget_id=? AND voided=0', [$id]);
    return release_cents($total['total']);
}

function release_record(array $actor, array $input, array $budget, string $role): void {
    $id = $budget['id'];
    $date = day_input($input);
    if ($date < $budget['submitted_on'] || $date > today()) fail('আবেদনের তারিখ থেকে আজকের মধ্যে রিলিজের তারিখ দিন।');
    $reference = strval_input($input, 'reference', 100);
    $note = strval_input($input, 'note', 2000, true);
    $total = release_total($id);
    $approved = release_cents($budget['amount']);
    $remaining = $approved - $total;
    if (isset($input['percent'])) {
        $percent = strval_input($input, 'percent', 6);
        if (!preg_match('/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/D', $percent)) fail('শতাংশ ০.০১ থেকে ১০০-এর মধ্যে দিন।');
        $parts = explode('.', $percent, 2);
        $basisPoints = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
        $requested = intdiv($approved * $basisPoints + 5000, 10000);
        if (($input['expectedReleased'] ?? null) !== release_amount($total)) fail('রিলিজের হিসাব বদলেছে। রিফ্রেশ করে আবার দেখুন।', 409);
    } else {
        $requested = $remaining;
    }
    if ($requested <= 0 || $requested > $remaining) fail('কিস্তির পরিমাণ অনুমোদিত অবশিষ্ট বাজেটের মধ্যে হতে হবে।');
    if (one('SELECT id FROM od_release_installments WHERE budget_id=? AND reference=? AND voided=0', [$id, $reference])) fail('এই রিলিজ রেফারেন্স আগে ব্যবহার হয়েছে।', 409);
    $amount = release_amount($requested);
    query('INSERT INTO od_release_installments(id,budget_id,amount,released_on,reference,note,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)',
        [uid(),$id,$amount,$date,$reference,$note,$actor['userId'],now()]);
    $final = $requested === $remaining;
    if ($final) query("UPDATE od_budgets SET stage=4,status='released',released_on=?,release_reference=? WHERE id=?", [$date,$reference,$id]);
    budget_event($actor,$id,$final ? 'released' : 'installment',3,'পরিমাণ: '.$amount.' | তারিখ: '.$date.' | রেফারেন্স: '.$reference.($note!==''?' | '.$note:''),$role);
}

function release_history_action(array $actor, array $input, string $action): bool {
    if (!in_array($action, ['release_remark','release_correct','release_void'], true)) return false;
    $role = budget_access($actor);
    if ($action === 'release_remark') {
        if (!$actor['globalRead'] || !in_array($role, ['cfo','gm'], true)) fail('মন্তব্য যোগ করার অনুমতি নেই।', 403);
    } else {
        hr_owner($actor);
    }
    $id = budget_uuid($input, 'id');
    $note = strval_input($input, 'note', 2000);
    transaction(function () use ($actor,$input,$action,$id,$note,$role) {
        $budget = one('SELECT * FROM od_budgets WHERE id=? FOR UPDATE', [$id]);
        if (!$budget || $budget['status'] !== 'released') fail('চূড়ান্ত রিলিজ রেকর্ড পাওয়া যায়নি।', 409);
        staff_budget_assert($actor, $id);
        if ($action === 'release_remark') {
            budget_event($actor,$id,'remark',4,$note,$role);
            return;
        }
        $last = one('SELECT * FROM od_release_installments WHERE budget_id=? AND voided=0 ORDER BY sequence DESC LIMIT 1', [$id]);
        if (($input['expectedReference'] ?? null) !== $budget['release_reference']) fail('শেষ রিলিজ রেকর্ড বদলেছে।', 409);
        if ($action === 'release_correct') {
            $date = day_input($input);
            if ($date < $budget['submitted_on'] || $date > today()) fail('সঠিক রিলিজ তারিখ দিন।');
            $reference = strval_input($input, 'reference', 100);
            if ($last) {
                if (one('SELECT id FROM od_release_installments WHERE budget_id=? AND reference=? AND id<>? AND voided=0', [$id,$reference,$last['id']])) fail('এই রেফারেন্স আগে ব্যবহার হয়েছে।', 409);
                query('UPDATE od_release_installments SET released_on=?,reference=? WHERE id=?', [$date,$reference,$last['id']]);
            }
            query('UPDATE od_budgets SET released_on=?,release_reference=? WHERE id=?', [$date,$reference,$id]);
            budget_event($actor,$id,'release_edit',4,$budget['released_on'].' / '.$budget['release_reference'].' → '.$date.' / '.$reference.' | '.$note,'admin');
        } else {
            if ($last) query('UPDATE od_release_installments SET voided=1 WHERE id=?', [$last['id']]);
            query("UPDATE od_budgets SET status='pending',stage=3,released_on=NULL,release_reference=NULL WHERE id=?", [$id]);
            budget_event($actor,$id,'release_void',4,$budget['released_on'].' / '.$budget['release_reference'].' | '.$note,'admin');
        }
        audit($actor, $action, $id);
    });
    return true;
}

function release_overview(array $actor): never {
    if (!$actor['globalRead']) fail('এই বিভাগের অনুমতি নেই।', 403);
    $role = budget_access($actor);
    $items = rows("SELECT b.id,b.serial,b.applicant_name,b.branch,b.amount,b.status,b.released_on,b.release_reference,
        CASE WHEN b.status='released' THEN b.amount ELSE COALESCE(t.total,0.00) END AS released
        FROM od_budgets b LEFT JOIN (
            SELECT budget_id,SUM(amount) AS total FROM od_release_installments WHERE voided=0 GROUP BY budget_id
        ) t ON t.budget_id=b.id
        WHERE b.status='released' OR (b.status='pending' AND b.stage=3)
        ORDER BY b.serial DESC LIMIT 100");
    foreach ($items as &$item) $item['code'] = budget_code($item);
    unset($item);
    output(['items'=>$items,'role'=>$role,'isAdmin'=>$actor['role']==='owner','today'=>today()]);
}
