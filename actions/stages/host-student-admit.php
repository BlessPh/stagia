<?php

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);
verifyAjaxCsrf();

$reservationId=(int)($_POST['reservation_id']??0);
$hostId=(int)currentEtablissementId($pdo);
$userId=(int)($_SESSION['user_id']??0);

if(!$reservationId)jsonResponse(false,'Réservation invalide.',[],422);
if(!$hostId)jsonResponse(false,'Aucun hôpital associé à ce compte.',[],403);

try{
    $pdo->beginTransaction();
    $stmt=$pdo->prepare("
        SELECT ad.id,ad.statut
        FROM stage_admissions ad
        INNER JOIN stage_reservations r ON r.id=ad.reservation_id AND r.statut='CONFIRMEE'
        INNER JOIN stage_applications a ON a.id=r.application_id
            AND a.statut='ACCEPTEE' AND a.host_etablissement_id=?
        INNER JOIN stage_placements p ON p.id=ad.placement_id
            AND p.reservation_id=r.id AND p.host_etablissement_id=? AND p.statut='CONFIRME'
        WHERE ad.reservation_id=? AND ad.host_etablissement_id=?
        LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([$hostId,$hostId,$reservationId,$hostId]);
    $admission=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$admission){
        throw new RuntimeException("Aucune admission issue d'un placement universitaire confirmé n'a été trouvée.");
    }
    if(in_array($admission['statut'],['ADMIS','EN_COURS','TERMINE'],true)){
        $pdo->commit();
        jsonResponse(true,'Ce stagiaire est déjà admis.',[
            'admission_id'=>(int)$admission['id'],
            'statut'=>$admission['statut']
        ]);
    }
    if($admission['statut']!=='ATTENDU'){
        throw new RuntimeException('Cette admission ne peut plus être enregistrée.');
    }

    $pdo->prepare("
        UPDATE stage_admissions
        SET statut='ADMIS',admitted_at=NOW(),admitted_by=?,observation=NULL
        WHERE id=? AND statut='ATTENDU'
    ")->execute([$userId,$admission['id']]);
    $pdo->commit();

    jsonResponse(true,'Le stagiaire a été admis avec succès.',[
        'admission_id'=>(int)$admission['id'],
        'statut'=>'ADMIS'
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[HOST STUDENT ADMIT] '.$e->getMessage());
    jsonResponse(false,$e->getMessage(),[],422);
}
