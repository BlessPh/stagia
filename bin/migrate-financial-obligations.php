<?php
declare(strict_types=1);

require_once __DIR__.'/../config/database.php';

$file=__DIR__.'/../database/migrations/2026_10_07_000015_financial_obligations.sql';
$sql=file_get_contents($file);
if($sql===false){fwrite(STDERR,"Migration introuvable.\n");exit(1);}

try{
    $number=0;
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql,-1,PREG_SPLIT_NO_EMPTY)?:[] as $statement){
        $number++;
        $statement=trim($statement);
        if($statement!=='')$pdo->exec($statement);
    }
    echo "Migration des obligations financieres terminee.\n";
}catch(Throwable $e){
    fwrite(STDERR,"Echec de la migration a l'instruction {$number} : {$e->getMessage()}\n");
    exit(1);
}
