<?php
declare(strict_types=1);
if(!defined('OFFICE_APP')){http_response_code(404);exit;}
function task_management_storage():void{
    query("CREATE TABLE IF NOT EXISTS od_task_assignments(task_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,assigner_account_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,assigner_name VARCHAR(100) NOT NULL,assigner_post VARCHAR(20) NOT NULL,assigned_at VARCHAR(30) NOT NULL,FOREIGN KEY(task_id) REFERENCES od_tasks(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    query("CREATE TABLE IF NOT EXISTS od_task_status_events(sequence BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,task_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,actor_account_id VARCHAR(36) NOT NULL,actor_name VARCHAR(100) NOT NULL,actor_post VARCHAR(20) NOT NULL,old_status VARCHAR(20) NOT NULL,new_status VARCHAR(20) NOT NULL,created_at VARCHAR(30) NOT NULL,KEY task_status_history(task_id,sequence),FOREIGN KEY(task_id) REFERENCES od_tasks(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function task_is_global(array $a):bool{return $a['role']==='owner'||($a['post']??null)==='md';}
function task_can_assign(array $a):bool{
    return task_is_global($a)||(!empty($a['branchId'])&&in_array($a['post']??null,['gm','agm','cfo','branch_manager'],true));
}
function task_may_assign_member(array $a,array $target):bool{
    if(!task_can_assign($a)||empty($target['active'])||empty($target['access_enabled']))return false;
    if(task_is_global($a))return true;
    return ($target['branch_id']??null)===$a['branchId']&&in_array($target['post']??null,['staff','officer'],true);
}
function task_assignees(array $a):array{
    if(!task_can_assign($a))return [];
    $where=task_is_global($a)?'1=1':"s.branch_id=? AND s.post IN ('staff','officer')";
    $args=task_is_global($a)?[]:[$a['branchId']];
    return rows("SELECT m.id,m.name,m.email,m.active,a.id AS user_id,s.post,s.branch_id,b.name AS branch_name FROM od_members m JOIN od_accounts a ON a.id=m.user_id AND a.email=m.email JOIN od_hr_profiles h ON h.account_id=a.id AND h.access_enabled=1 JOIN od_staff_assignments s ON s.account_id=a.id JOIN od_branches b ON b.id=s.branch_id AND b.active=1 WHERE m.active=1 AND $where ORDER BY b.name,m.name",$args);
}
function task_assert_assignee(array $a,string $mid):void{
    if($mid==='owner'){if(!task_is_global($a))fail('অ্যাডমিনকে কাজ দেওয়ার অনুমতি নেই।',403);return;}
    $target=one('SELECT m.id,m.active,h.access_enabled,s.post,s.branch_id FROM od_members m JOIN od_accounts a ON a.id=m.user_id AND a.email=m.email JOIN od_hr_profiles h ON h.account_id=a.id JOIN od_staff_assignments s ON s.account_id=a.id JOIN od_branches b ON b.id=s.branch_id AND b.active=1 WHERE m.id=?',[$mid]);
    if(!$target||!task_may_assign_member($a,$target))fail('এই ইউজারকে কাজ দেওয়ার অনুমতি নেই।',403);
}
function task_record_assigner(array $a,string $taskId):void{
    query('INSERT INTO od_task_assignments(task_id,assigner_account_id,assigner_name,assigner_post,assigned_at) VALUES(?,?,?,?,?)',[$taskId,$a['userId'],$a['name'],$a['post']??($a['role']==='owner'?'admin':'staff'),now()]);
}
function task_review_allowed(array $a):bool{
    return task_is_global($a)||in_array($a['post']??null,['gm','agm','cfo','branch_manager'],true);
}
function task_edit_allowed(array $a,array $task):bool{
    if(task_is_global($a))return true;
    if(!task_can_assign($a))return false;
    $assignment=one('SELECT assigner_account_id FROM od_task_assignments WHERE task_id=?',[$task['id']]);
    return ($assignment['assigner_account_id']??null)===$a['userId'];
}
function task_decorate(array $a,array $task):array{
    $assignment=one('SELECT assigner_name,assigner_post,assigned_at FROM od_task_assignments WHERE task_id=?',[$task['id']]);
    return [...$task,...($assignment??[]),'canReview'=>task_review_allowed($a),'canEdit'=>task_edit_allowed($a,$task),'canComplete'=>$task['assignee_id']===$a['id']];
}
function task_record_status(array $a,string $id,string $old,string $new):void{
    if($old===$new)return;
    query('INSERT INTO od_task_status_events(task_id,actor_account_id,actor_name,actor_post,old_status,new_status,created_at) VALUES(?,?,?,?,?,?,?)',[$id,$a['userId'],$a['name'],$a['post']??'admin',$old,$new,now()]);
}
function task_set_status(array $a,string $id,string $status):void{
    if(!in_array($status,['Remaining','In Progress','Completed'],true))fail('সঠিক স্ট্যাটাস দিন।');
    visible($a,$id);
    transaction(function()use($a,$id,$status){
        $task=one('SELECT * FROM od_tasks WHERE id=? FOR UPDATE',[$id]);
        if(!$task)fail('কাজ পাওয়া যায়নি।',404);
        if(!task_review_allowed($a)&&($task['assignee_id']!==$a['id']||$status!=='Completed'))fail('শুধু নিজের কাজ Complete দিতে পারবেন।',403);
        if($task['date']>today())fail('ভবিষ্যতের কাজ এখন Complete বা Resume করা যাবে না।',403);
        $date=today();
        if($date>$task['date']) {
            $originReport=one('SELECT content AS report FROM od_reports WHERE task_id=? AND author_id=? AND date=?',[$id,$task['assignee_id'],$task['date']]);
            query('INSERT IGNORE INTO od_daily_work(task_id,work_date,title,details,report,status) VALUES(?,?,?,?,?,?)',[$id,$task['date'],$task['title'],$task['details'],$originReport['report']??'',$task['status']]);
        }
        // Preserve old daily snapshots. A new snapshot makes resumed work carry forward.
        $report=one('SELECT report FROM od_daily_work WHERE task_id=? AND work_date=?',[$id,$date]);
        if(!$report)$report=one('SELECT content AS report FROM od_reports WHERE task_id=? AND author_id=? AND date=?',[$id,$task['assignee_id'],$date]);
        query('INSERT INTO od_daily_work(task_id,work_date,title,details,report,status) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)',[$id,$date,$task['title'],$task['details'],$report['report']??'',$status]);
        query('UPDATE od_tasks SET status=?,updated_at=? WHERE id=?',[$status,now(),$id]);
        task_record_status($a,$id,$task['status'],$status);
        audit($a,'স্ট্যাটাস: '.$status,$id);
    });
}
