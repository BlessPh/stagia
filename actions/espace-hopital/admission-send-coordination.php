<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);
verifyAjaxCsrf();

$hostId=(int)currentEtablissementId($pdo);
$uid=(int)($_SESSION['user_id']??0)?:null;
$admissionId=(int)($_POST['admission_id']??0);
$coordId=(int)($_POST['coordination_unit_id']??0);

if(!$hostId||!$admissionId||!$coordId)jsonResponse(false,'Admission ou coordination invalide.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("\n        SELECT id,statut,coordination_unit_id\n        FROM stage_admissions\n        WHERE id=? AND host_etablissement_id=?\n        LIMIT 1 FOR UPDATE\n    ");
    $s->execute([$admissionId,$hostId]);
    $ad=$s->fetch(PDO::FETCH_ASSOC);
    if(!$ad)throw new RuntimeException('Admission introuvable.');
    if(!in_array($ad['statut'],['ADMIS','EN_COURS'],true))throw new RuntimeException('Le stagiaire doit être admis avant envoi à la coordination.');
    if(!empty($ad['coordination_unit_id']))throw new RuntimeException('Ce stagiaire est déjà envoyé à une coordination.');

    $s=$pdo->prepare("\n        SELECT id,nom,type\n        FROM host_units\n        WHERE id=? AND host_etablissement_id=? AND actif=1\n          AND (parent_id IS NULL OR parent_id=0)\n          AND UPPER(type) IN('COORDINATION','DEPARTEMENT','DÉPARTEMENT','DEPARTMENT','DIRECTION','UNITE','UNITÉ')\n        LIMIT 1\n    ");
    $s->execute([$coordId,$hostId]);
    $coord=$s->fetch(PDO::FETCH_ASSOC);
    if(!$coord)throw new RuntimeException('Coordination ou département invalide.');

    $pdo->prepare("\n        UPDATE stage_admissions\n        SET coordination_unit_id=?,coordination_sent_at=NOW(),coordination_sent_by=?\n        WHERE id=? AND host_etablissement_id=?\n    ")->execute([$coordId,$uid,$admissionId,$hostId]);

    try{
        $has=$pdo->query("SHOW TABLES LIKE 'stage_admission_history'")->fetchColumn();
        if($has){
            $pdo->prepare("\n                INSERT INTO stage_admission_history(admission_id,event_code,previous_status,new_status,details,actor_user_id,created_at)\n                VALUES(?,'SENT_TO_COORDINATION',?, ?, ?, ?, NOW())\n            ")->execute([$admissionId,$ad['statut'],$ad['statut'],json_encode(['coordination_unit_id'=>$coordId,'coordination_name'=>$coord['nom']],JSON_UNESCAPED_UNICODE),$uid]);
        }
    }catch(Throwable $e){error_log('[ADMISSION COORD HISTORY] '.$e->getMessage());}

    $pdo->commit();
    jsonResponse(true,"Stagiaire envoyé vers « {$coord['nom']} ».");
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
