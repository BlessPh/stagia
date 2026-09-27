<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);
verifyAjaxCsrf();

try{
    $hostId=currentEtablissementId($pdo);
    $admissionId=(int)($_POST['admission_id']??0);
    $observation=trim((string)($_POST['observation']??''));
    $userId=(int)($_SESSION['user_id']??0);

    if(!$hostId||!$admissionId)
        jsonResponse(false,'Admission invalide.',[],422);

    $pdo->beginTransaction();

    $stmt=$pdo->prepare("
        SELECT
            ad.id,
            ad.statut,
            ad.reservation_id,
            sr.statut AS reservation_status,
            app.host_etablissement_id,
            app.statut AS application_status,
            pl.statut AS placement_status
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id
        JOIN stage_placements pl ON pl.id=ad.placement_id
            AND pl.reservation_id=sr.id
            AND pl.host_etablissement_id=ad.host_etablissement_id
        WHERE ad.id=?
          AND ad.host_etablissement_id=?
          AND app.host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$admissionId,$hostId,$hostId]);
    $admission=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$admission)
        throw new RuntimeException('Admission introuvable.');

    if($admission['application_status']!=='ACCEPTEE')
        throw new RuntimeException("La candidature n'a pas reçu de décision universitaire favorable.");
    if($admission['reservation_status']!=='CONFIRMEE')
        throw new RuntimeException("La réservation doit être confirmée avant l'admission.");
    if($admission['placement_status']!=='CONFIRME')
        throw new RuntimeException("Le placement universitaire doit être confirmé avant l'admission.");

    if($admission['statut']==='ADMIS' || $admission['statut']==='EN_COURS'){
        $pdo->commit();
        jsonResponse(true,'Ce stagiaire est déjà admis.',[
            'admission_id'=>$admissionId,
            'status'=>$admission['statut']
        ]);
    }

    if($admission['statut']!=='ATTENDU')
        throw new RuntimeException('Cette admission ne peut plus être enregistrée.');

    $stmt=$pdo->prepare("
        UPDATE stage_admissions
        SET
            statut='ADMIS',
            admitted_at=NOW(),
            admitted_by=?,
            observation=?
        WHERE id=?
          AND host_etablissement_id=?
          AND statut='ATTENDU'
    ");
    $stmt->execute([
        $userId,
        $observation!==''?$observation:null,
        $admissionId,
        $hostId
    ]);

    $pdo->commit();

    jsonResponse(true,"Admission enregistrée. Vous pouvez maintenant l'envoyer vers une coordination. Le paiement sera contrôlé avant l'affectation au service.",[
        'admission_id'=>$admissionId,
        'status'=>'ADMIS'
    ]);

}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[ADMISSION ADMIT] '.$e->getMessage());
    jsonResponse(false,$e->getMessage(),[],422);
}
