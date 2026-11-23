<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('OFFICE_APP',true);
function fail(string $message,int $code=400):never{throw new RuntimeException($message,$code);}
function today():string{return $GLOBALS['testDate'];}
function now():string{return today().'T03:00:00Z';}
function db():PDO{return $GLOBALS['pdo'];}
function query(string $sql,array $args=[]):PDOStatement{$sql=str_replace([' FOR UPDATE','INSERT IGNORE'],['','INSERT OR IGNORE'],$sql);$sql=str_replace('ON DUPLICATE KEY UPDATE status=VALUES(status)','ON CONFLICT(task_id,work_date) DO UPDATE SET status=excluded.status',$sql);$s=db()->prepare($sql);$s->execute($args);return $s;}
function one(string $sql,array $args=[]):?array{return query($sql,$args)->fetch(PDO::FETCH_ASSOC)?:null;}
function rows(string $sql,array $args=[]):array{return query($sql,$args)->fetchAll(PDO::FETCH_ASSOC);}
function transaction(callable $fn):mixed{db()->beginTransaction();try{$result=$fn();db()->commit();return $result;}catch(Throwable $e){db()->rollBack();throw $e;}}
function audit(array $a,string $action,string $target):void{$GLOBALS['audits'][]=[$a['userId'],$action,$target];}
function visible(array $a,string $id):array{[$scope,$args]=staff_task_scope($a);$task=one('SELECT t.* FROM od_tasks t WHERE t.id=? AND '.$scope,[$id,...$args]);if(!$task)fail('Out of scope',404);return task_decorate($a,$task);}
function check(bool $value,string $message):void{if(!$value)throw new LogicException($message);}
function denied(callable $fn):void{try{$fn();}catch(RuntimeException $e){check(in_array($e->getCode(),[403,404],true),'Wrong denial');return;}throw new LogicException('Unauthorized operation accepted');}
require __DIR__.'/../office-private/task-management.php';
require __DIR__.'/../office-private/staffing.php';
require __DIR__.'/../office-private/daily.php';
define('OFFICE_SCHEMA_READY',true);
$GLOBALS['testDate']='2026-11-23';$GLOBALS['audits']=[];$GLOBALS['pdo']=new PDO('sqlite::memory:');db()->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach([
'CREATE TABLE od_accounts(id TEXT PRIMARY KEY,email TEXT)',
'CREATE TABLE od_members(id TEXT PRIMARY KEY,user_id TEXT,name TEXT,email TEXT,active INT)',
'CREATE TABLE od_hr_profiles(account_id TEXT,access_enabled INT)',
'CREATE TABLE od_staff_assignments(account_id TEXT,branch_id TEXT,post TEXT)',
'CREATE TABLE od_branches(id TEXT,name TEXT,active INT)',
'CREATE TABLE od_tasks(id TEXT PRIMARY KEY,assignee_id TEXT,date TEXT,title TEXT,details TEXT,status TEXT,updated_at TEXT,created_at TEXT)',
'CREATE TABLE od_task_assignments(task_id TEXT PRIMARY KEY,assigner_account_id TEXT,assigner_name TEXT,assigner_post TEXT,assigned_at TEXT)',
'CREATE TABLE od_task_status_events(sequence INTEGER PRIMARY KEY AUTOINCREMENT,task_id TEXT,actor_account_id TEXT,actor_name TEXT,actor_post TEXT,old_status TEXT,new_status TEXT,created_at TEXT)',
'CREATE TABLE od_daily_work(task_id TEXT,work_date TEXT,title TEXT,details TEXT,report TEXT,status TEXT,PRIMARY KEY(task_id,work_date))',
'CREATE TABLE od_reports(task_id TEXT,author_id TEXT,date TEXT,content TEXT)'
] as $sql)query($sql);
query("INSERT INTO od_branches VALUES('a','Branch A',1),('b','Branch B',1)");
foreach([['alice','ma','a','staff'],['bob','mb','b','officer'],['manager','mg','a','gm']] as [$uid,$mid,$branch,$post]){
 query('INSERT INTO od_accounts VALUES(?,?)',[$uid,$uid.'@example.test']);query('INSERT INTO od_members VALUES(?,?,?,?,1)',[$mid,$uid,$uid,$uid.'@example.test']);query('INSERT INTO od_hr_profiles VALUES(?,1)',[$uid]);query('INSERT INTO od_staff_assignments VALUES(?,?,?)',[$uid,$branch,$post]);
}
$base=['id'=>'mg','userId'=>'manager','name'=>'Manager','role'=>'officer','post'=>'gm','branchId'=>'a','globalRead'=>false,'manageBranch'=>false];
$staff=[...$base,'id'=>'ma','userId'=>'alice','name'=>'Alice','post'=>'staff'];
foreach(['gm','agm','cfo','branch_manager'] as $post){$actor=[...$base,'post'=>$post];task_assert_assignee($actor,'ma');denied(fn()=>task_assert_assignee($actor,'mb'));denied(fn()=>task_assert_assignee($actor,'mg'));denied(fn()=>task_assert_assignee($actor,'owner'));check(array_column(task_assignees($actor),'id')===['ma'],'Manager dropdown leaked scope');}
foreach(['staff','officer'] as $post){$actor=[...$staff,'post'=>$post];check(!task_can_assign($actor),'Staff can assign');denied(fn()=>task_assert_assignee($actor,'ma'));}
foreach([['role'=>'owner','post'=>'admin'],['role'=>'officer','post'=>'md']] as $role){$actor=[...$base,...$role];foreach(['ma','mb','mg','owner'] as $id)task_assert_assignee($actor,$id);}
query("INSERT INTO od_tasks VALUES('ta','ma','2026-11-22','Work A','Details','Completed','','2026-11-22'),('tb','mb','2026-11-22','Work B','Details','Remaining','','2026-11-22')");
query("INSERT INTO od_daily_work VALUES('ta','2026-11-22','Work A','Details','Original report','Completed')");
task_record_assigner($base,'ta');check(task_decorate($staff,one("SELECT * FROM od_tasks WHERE id='ta'"))['assigner_name']==='Manager','Creator missing');
denied(fn()=>task_set_status($staff,'ta','Remaining'));denied(fn()=>task_set_status($base,'tb','Completed'));
$before=one("SELECT * FROM od_daily_work WHERE task_id='ta' AND work_date='2026-11-22'");
task_set_status($base,'ta','Remaining');check(one("SELECT status FROM od_tasks WHERE id='ta'")['status']==='Remaining','Manager did not reopen');check(one("SELECT * FROM od_daily_work WHERE task_id='ta' AND work_date='2026-11-22'")===$before,'Old daily snapshot changed');
check(count(daily_list($staff,'2026-11-24'))===1,'Resumed task did not carry forward');
task_set_status($staff,'ta','Completed');check(one("SELECT status FROM od_tasks WHERE id='ta'")['status']==='Completed','Staff cannot complete own work');
$md=[...$base,'post'=>'md'];task_set_status($md,'tb','Completed');
check((int)one('SELECT COUNT(*) AS total FROM od_task_status_events')['total']===3,'Status history missing');
check(!task_edit_allowed([...$base,'userId'=>'someone-else'],one("SELECT * FROM od_tasks WHERE id='ta'")),'Unrelated manager can edit title');
echo "Task role/branch permissions, scoped assignees, provenance, own completion, manager reopening, unchanged history and carry-forward checks passed.\n";
