<?php
declare(strict_types=1);

if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function payslip_get(array $actor): never {
    $month = finance_month($_GET);
    $account = isset($_GET['accountId']) ? id_input($_GET,'accountId') : $actor['userId'];
    if (!office_module_scope($actor,'payroll',$account)) fail('বেতনপত্র দেখার অনুমতি নেই।',403);
    $payroll = one('SELECT p.*,a.name,b.name AS branch_name FROM od_payroll p JOIN od_accounts a ON a.id=p.account_id
        LEFT JOIN od_staff_assignments s ON s.account_id=p.account_id LEFT JOIN od_branches b ON b.id=s.branch_id
        WHERE p.account_id=? AND p.month=?',[$account,$month]);
    if (!$payroll) fail('এই মাসের বেতনপত্র পাওয়া যায়নি।',404);
    $escape = fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8"><title>Office Daily | পে-স্লিপ</title></head><body><main><h1>মাসিক পে-স্লিপ</h1><dl>';
    foreach (['name'=>'নাম','branch_name'=>'ব্রাঞ্চ','month'=>'মাস','basic_salary'=>'মূল বেতন','medical_allowance'=>'চিকিৎসা ভাতা',
        'other_allowance'=>'অন্যান্য ভাতা','deductions'=>'কর্তন','net_salary'=>'নিট বেতন','payment_status'=>'পেমেন্ট স্ট্যাটাস'] as $key=>$label) {
        echo '<dt>'.$label.'</dt><dd>'.$escape($payroll[$key]).'</dd>';
    }
    echo '</dl></main></body></html>';
    exit;
}
