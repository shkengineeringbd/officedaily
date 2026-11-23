<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('OFFICE_APP',true);
require __DIR__.'/../office-private/bootstrap.php';
require __DIR__.'/../office-private/attendance-selfie.php';
try{attendance_selfie_storage();attendance_selfie_cleanup();echo "Expired attendance selfies removed.\n";}catch(Throwable $e){fwrite(STDERR,"Selfie cleanup failed.\n");exit(1);}
