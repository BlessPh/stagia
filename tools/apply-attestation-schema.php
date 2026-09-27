<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/stage-certificate-auto.php';
header('Content-Type:text/plain; charset=utf-8');
try{
    stageEnsureCertificateSchema($pdo);
    $n=stageEnsureCertificatesFromValidatedResults($pdo,0);
    echo "OK - Schéma attestations vérifié.\n";
    echo "Attestations créées depuis les résultats validés : {$n}\n";
}catch(Throwable $e){
    http_response_code(500);
    echo "ERREUR : ".$e->getMessage();
}
