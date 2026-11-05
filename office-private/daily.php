<?php
declare(strict_types=1);

function daily_storage(): void {
    query("CREATE TABLE IF NOT EXISTS od_daily_work (
        task_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        work_date CHAR(10) NOT NULL,
        title VARCHAR(200) NOT NULL,
        details TEXT NOT NULL,
        report TEXT NOT NULL,
        status VARCHAR(20) NOT NULL,
        PRIMARY KEY(task_id,work_date),
        KEY daily_date(work_date),
        FOREIGN KEY(task_id) REFERENCES od_tasks(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function daily_list(array $actor, string $date): array {
    daily_storage();
    [$scope,$scopeArgs]=staff_task_scope($actor);
    $params=[$date,...$scopeArgs];
    $params[] = $date;
    return rows("SELECT t.id,t.assignee_id,t.date AS origin,
        COALESCE(m.name,'মালিক') AS assignee_name,
        COALESCE(d.title,t.title) AS title,COALESCE(d.details,t.details) AS details,
        COALESCE(d.status,t.status) AS status,
        CASE WHEN d.work_date=? THEN d.report ELSE COALESCE(
          (SELECT r.content FROM od_reports r WHERE r.task_id=t.id AND r.date=? AND r.author_id=t.assignee_id LIMIT 1),'') END AS report
        FROM od_tasks t LEFT JOIN od_members m ON m.id=t.assignee_id
        LEFT JOIN od_daily_work d ON d.task_id=t.id AND d.work_date=(
          SELECT MAX(p.work_date) FROM od_daily_work p WHERE p.task_id=t.id AND p.work_date<=?
        )
        WHERE $scope AND t.date<=? AND (t.date=? OR d.work_date=? OR COALESCE(d.status,t.status)<>'Completed')
        ORDER BY t.date DESC,t.created_at DESC",
        [$date,$date,...$params,$date,$date]);
}

function daily_save(array $actor,array $input): void {
    $date=day_input($input);
    $owner=$actor['role']==='owner';
    if (!$owner && $date!==today()) fail('শুধু আজকের রেকর্ড সম্পাদনা করা যাবে।',403);
    if ($date>today()) fail('ভবিষ্যতের তারিখে রিপোর্ট লেখা যাবে না।');
    $title=strval_input($input,'title',200);
    $details=strval_input($input,'details',5000,true);
    $report=strval_input($input,'report',5000,true);
    $status=$input['status']??null;
    if (!in_array($status,['Remaining','In Progress','Completed'],true)) fail('সঠিক স্ট্যাটাস বেছে নিন।');
    daily_storage();
    transaction(function() use($actor,$owner,$input,$date,$title,$details,$report,$status) {
        $id=isset($input['id'])?id_input($input,'id'):uid();
        if (isset($input['id'])) {
            visible($actor,$id);
            $task=one('SELECT * FROM od_tasks WHERE id=? FOR UPDATE',[$id]);
            if (!$owner && $task['assignee_id']!==$actor['id']) fail('শুধু নিজের কাজ সম্পাদনা করা যাবে।',403);
            if ($date<$task['date']) fail('কাজ শুরুর আগের তারিখ বেছে নেওয়া যাবে না।');
            // Preserve the original day's state before recording later progress.
            if ($date>$task['date']) {
                $oldReport=one('SELECT content FROM od_reports WHERE task_id=? AND author_id=? AND date=?',[$id,$task['assignee_id'],$task['date']]);
                query('INSERT IGNORE INTO od_daily_work (task_id,work_date,title,details,report,status) VALUES (?,?,?,?,?,?)',
                    [$id,$task['date'],$task['title'],$task['details'],$oldReport['content']??'',$task['status']]);
            }
        } else {
            $time=now();
            query('INSERT INTO od_tasks (id,assignee_id,date,title,details,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
                [$id,$actor['id'],$date,$title,$details,$status,$time,$time]);
        }
        if (!$owner && $date!==today()) fail('দিন শেষ হয়েছে। আজকের তারিখে আপডেট দিন।',403);
        query('INSERT INTO od_daily_work (task_id,work_date,title,details,report,status) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE title=VALUES(title),details=VALUES(details),report=VALUES(report),status=VALUES(status)',
            [$id,$date,$title,$details,$report,$status]);
        if (!one('SELECT task_id FROM od_daily_work WHERE task_id=? AND work_date>? LIMIT 1',[$id,$date])) {
            query('UPDATE od_tasks SET title=?,details=?,status=?,updated_at=? WHERE id=?',[$title,$details,$status,now(),$id]);
        }
        audit($actor,'দৈনিক আপডেট',$date.' '.$id);
    });
}

