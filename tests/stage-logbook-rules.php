<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../includes/stage-logbook-rules.php';

$tests=0;

function logbookCheck(bool $condition,string $label):void
{
    global $tests;
    if(!$condition)throw new RuntimeException('ÉCHEC : '.$label);
    $tests++;
}

function logbookRejects(callable $callback,string $label):void
{
    try{
        $callback();
    }catch(StageLogbookValidationException){
        logbookCheck(true,$label);
        return;
    }
    throw new RuntimeException('ÉCHEC : '.$label);
}

logbookCheck(
    stageLogbookValidateRotationDate(
        '2026-10-10','2026-10-01','2026-10-12','ACTIVE','2026-10-13'
    )==='2026-10-10',
    'Date passée dans une rotation active'
);
logbookCheck(
    stageLogbookValidateRotationDate(
        '2026-09-30','2026-09-01','2026-09-30','TERMINEE','2026-10-13'
    )==='2026-09-30',
    'Rattrapage au dernier jour d’une rotation terminée'
);
logbookRejects(
    fn()=>stageLogbookValidateRotationDate(
        '2026-10-14','2026-10-01','2026-10-20','ACTIVE','2026-10-13'
    ),
    'Date future'
);
logbookRejects(
    fn()=>stageLogbookValidateRotationDate(
        '2026-09-30','2026-10-01','2026-10-12','ACTIVE','2026-10-13'
    ),
    'Date avant la rotation'
);
logbookRejects(
    fn()=>stageLogbookValidateRotationDate(
        '2026-10-10','2026-10-01','2026-10-12','PLANIFIEE','2026-10-13'
    ),
    'Rotation non ouverte au journal'
);
logbookRejects(
    fn()=>stageLogbookNormalizeDate('2026-02-30'),
    'Date calendaire inexistante'
);

echo $tests." contrôles du journal réussis.\n";
