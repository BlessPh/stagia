<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once __DIR__.'/../includes/certificate-service.php';
require_once __DIR__.'/../includes/stage-letter-service.php';

$tests=0;
function documentJourneyCheck(bool $condition,string $label):void{
    global $tests;
    if(!$condition)throw new RuntimeException('ÉCHEC : '.$label);
    $tests++;
}

documentJourneyCheck(certificateScoreLabel(87.5)==='87,50 %','Note d’attestation sur 100');
documentJourneyCheck(certificateScoreLabel(null)==='—','Note absente');
documentJourneyCheck(certificatePublicTypeLabel('ATTESTATION_STAGE')==='Attestation de stage','Libellé public attestation');
documentJourneyCheck(certificatePublicTypeLabel('CERTIFICAT_STAGE')==='Certificat de stage','Libellé public certificat');
documentJourneyCheck(stageLetterReference(42)==='STG/LET/'.date('Y').'/00042','Référence de lettre');

$letter=['university_id'=>7,'university_etablissement_id'=>7,'host_id'=>11,'host_etablissement_id'=>11];
documentJourneyCheck(stageLetterActorCanView($letter,7,'ADMIN_ETABLISSEMENT'),'Université propriétaire autorisée');
documentJourneyCheck(stageLetterActorCanView($letter,11,'ADMIN_ACCUEIL'),'Hôpital destinataire autorisé');
documentJourneyCheck(!stageLetterActorCanView($letter,8,'ADMIN_ETABLISSEMENT'),'Autre université refusée');
documentJourneyCheck(!stageLetterActorCanView($letter,12,'ADMIN_ACCUEIL'),'Autre hôpital refusé');

echo $tests." contrôles du parcours documentaire réussis.\n";
