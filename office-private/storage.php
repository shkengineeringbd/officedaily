<?php
declare(strict_types=1);
if(!defined('OFFICE_APP')){http_response_code(404);exit;}

// Schema upgrades run once per version, never on every API call.
function app_storage():void{
    if(defined('OFFICE_SCHEMA_READY'))return;
    $version='2026-11-05-speed-1';
    try{$row=one("SELECT meta_value FROM od_app_meta WHERE meta_key='schema_version'");}
    catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;$row=null;}
    if(($row['meta_value']??null)!==$version){
        $lock='od-schema-'.substr(hash('sha256',config()['db_name']),0,32);
        if((int)(one('SELECT GET_LOCK(?,15) AS acquired',[$lock])['acquired']??0)!==1)fail('আপডেট চলছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।',503);
        try{
            query('CREATE TABLE IF NOT EXISTS od_app_meta(meta_key VARCHAR(60) PRIMARY KEY,meta_value VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $current=one("SELECT meta_value FROM od_app_meta WHERE meta_key='schema_version'");
            if(($current['meta_value']??null)!==$version){
                people_storage();budget_storage();staff_storage();daily_storage();
                query("INSERT INTO od_app_meta(meta_key,meta_value) VALUES('schema_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",[$version]);
            }
        }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    define('OFFICE_SCHEMA_READY',true);
}
