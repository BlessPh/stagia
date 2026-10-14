<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
try{
    requireRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE']);
    $eid=(int)currentEtablissementId($pdo);
    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);
    $s=$pdo->prepare("SELECT id,assignment_id,statut,mini_juries_total,mini_juries_valides,note_finale,decision,appreciation,date_deliberation,validated_at FROM stage_final_deliberations WHERE host_etablissement_id=? AND statut<>'ANNULEE'");
    $s->execute([$eid]);
    jsonResponse(true,'',['deliberations'=>$s->fetchAll(PDO::FETCH_ASSOC),'can_manage'=>1]);
}catch(Throwable $e){jsonResponse(false,'Erreur Délibérations : '.$e->getMessage(),[],500);}


