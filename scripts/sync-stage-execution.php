<?php
/**
 * STAGIA-RDC
 * Synchronisation globale des rotations.
 *
 * Usage :
 * php scripts/sync-stage-execution.php
 */

if(PHP_SAPI!=='cli'){
    http_response_code(403);
    exit("CLI uniquement.\n");
}

require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/stage-execution.php';

try{
    $result=syncStageExecution($pdo);

    echo "[STAGIA] Synchronisation rotations OK\n";
    echo "Date : {$result['date']}\n";
    echo "Rotations individuelles modifiées : {$result['individual_changed']}\n";
    echo "Plans collectifs modifiés : {$result['plan_changed']}\n";

    exit(0);

}catch(Throwable $e){
    fwrite(STDERR,"[STAGIA] ERREUR : ".$e->getMessage()."\n");
    exit(1);
}
