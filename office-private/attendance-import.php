<?php
declare(strict_types=1);

if (!defined('OFFICE_APP')) { http_response_code(404); exit; }

function attendance_import(array $actor,array $input,string $action): bool {
    if ($action!=='attendance_import') return false;
    hr_owner($actor);
    $apply = ($input['apply']??false)===true;
    $lines = $input['records']??null;
    if (!is_array($lines)||!array_is_list($lines)||!count($lines)||count($lines)>200) fail('১ থেকে ২০০টি হাজিরার রেকর্ড দিন।');
    $run = function () use ($actor,$lines,$apply) {
        $preview = [];
        $seen = [];
        $records = [];
        foreach ($lines as $i=>$line) {
            $error = '';
            try {
                if (!is_array($line)) fail('সঠিক কলাম দিন।');
                $email = email_input($line);
                $date = day_input($line);
                if ($date>today()) fail('ভবিষ্যতের হাজিরা দেওয়া যাবে না।');
                $status = $line['status']??'';
                if (!in_array($status,['Present','Absent','Leave','Holiday','Late'],true)) fail('সঠিক হাজিরা স্ট্যাটাস দিন।');
                $note = strval_input(['note'=>$line['note']??''],'note',500,true);
                $account = one('SELECT id FROM od_accounts WHERE email=?',[$email]);
                if (!$account) fail('এই ইমেইলে ইউজার পাওয়া যায়নি।');
                $key = $account['id'].'|'.$date;
                if (isset($seen[$key])) fail('ফাইলে একই ইউজার ও তারিখ একাধিকবার আছে।');
                $seen[$key] = true;
                if (one('SELECT account_id FROM od_attendance WHERE account_id=? AND work_date=?',[$account['id'],$date])) fail('আগের হাজিরা রেকর্ড আছে।');
                $records[] = [$account['id'],$date,$status,$note];
            } catch (RuntimeException $failure) {
                if ($failure->getCode()!==400) throw $failure;
                $error = $failure->getMessage();
            }
            $preview[] = ['row'=>$i+2,'email'=>is_string($line['email']??null)?$line['email']:'','date'=>is_string($line['date']??null)?$line['date']:'','error'=>$error];
        }
        if ($apply) {
            if (count($records)!==count($lines)) fail('প্রথমে আমদানির সব ত্রুটি ঠিক করুন।',409);
            foreach ($records as [$id,$date,$status,$note]) query('INSERT INTO od_attendance(account_id,work_date,status,note,updated_at) VALUES(?,?,?,?,?)',[$id,$date,$status,$note,now()]);
            audit($actor,'হাজিরা CSV আমদানি',(string)count($records));
        }
        return ['rows'=>$preview,'valid'=>count($records),'imported'=>$apply?count($records):0];
    };
    try {
        output($apply ? transaction($run) : $run());
    } catch (PDOException $failure) {
        if ($failure->getCode()==='23000') fail('নতুন হাজিরা রেকর্ড পাওয়া গেছে। আবার প্রিভিউ দেখুন।',409);
        throw $failure;
    }
}
