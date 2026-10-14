<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/student-account-deletion.php';
try{
    $result=studentAccountProcessDueDeletions($pdo,(int)($argv[1]??100));
    echo 'Suppressions traitees : '.$result['processed'].PHP_EOL;
    echo 'Echecs : '.$result['failed'].PHP_EOL;
    exit($result['failed']>0?1:0);
}catch(Throwable $e){fwrite(STDERR,'Echec : '.$e->getMessage().PHP_EOL);exit(1);}
