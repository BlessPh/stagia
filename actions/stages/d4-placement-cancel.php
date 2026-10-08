<?php

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-student-notifications.php';
require_once __DIR__.'/../../includes/stage-d4-reservation.php';

requirePermission($pdo,'placement.university.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$placementId=(int)($_POST['placement_id']??0);
$reason=trim((string)($_POST['reason']??''));
$actor=(int)($_SESSION['user_id']??0)?:null;

if(!$eid||!$placementId)jsonResponse(false,'Placement invalide.',[],422);
if($reason===''||mb_strlen($reason)<5)jsonResponse(false,"Le motif d'annulation doit contenir au moins 5 caractères.",[],422);
if(mb_strlen($reason)>1000)jsonResponse(false,"Le motif d'annulation ne peut pas dépasser 1 000 caractères.",[],422);

try{
    $pdo->beginTransaction();
    $stmt=$pdo->prepare("
        SELECT pl.id,pl.uuid,pl.reservation_id,r.application_id,
               pl.statut,pl.date_debut,pl.date_fin,
               c.owner_etablissement_id,h.nom hospital_name,
               ad.id admission_id,ad.statut admission_status
        FROM stage_placements pl
        JOIN stage_reservations r ON r.id=pl.reservation_id
        JOIN stage_campaigns c ON c.id=pl.campaign_id AND c.owner_etablissement_id=?
        JOIN etablissements h ON h.id=pl.host_etablissement_id
        LEFT JOIN stage_admissions ad ON ad.placement_id=pl.id
        WHERE pl.id=? LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([$eid,$placementId]);
    $placement=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$placement)throw new RuntimeException('Placement introuvable pour cette université.');
    if($placement['statut']==='ANNULE'){
        $pdo->commit();
        jsonResponse(true,'Ce placement est déjà annulé.',[
            'placement_id'=>$placementId,'placement_uuid'=>$placement['uuid'],'workflow_status'=>'PLACEMENT_ANNULE'
        ]);
    }
    if($placement['statut']!=='CONFIRME')throw new RuntimeException("Seul un placement confirmé peut être annulé.");
    if($placement['admission_id']&&in_array($placement['admission_status'],['ADMIS','EN_COURS','TERMINE'],true))
        throw new RuntimeException("Ce placement ne peut plus être annulé : l'étudiant a déjà été admis par l'hôpital.");

    if($placement['admission_id']){
        $check=$pdo->prepare("SELECT COUNT(*) FROM stage_assignments WHERE admission_id=? AND statut IN('PLANIFIEE','ACTIVE','TERMINEE')");
        $check->execute([(int)$placement['admission_id']]);
        if((int)$check->fetchColumn()>0)
            throw new RuntimeException("Ce placement ne peut plus être annulé : une affectation hospitalière existe déjà.");

        $pdo->prepare("UPDATE stage_admissions SET statut='ANNULE' WHERE id=? AND statut='ATTENDU'")
            ->execute([(int)$placement['admission_id']]);
        $pdo->prepare("
            INSERT INTO stage_admission_history(admission_id,event_code,previous_status,new_status,details,actor_user_id,created_at)
            VALUES(?,'PLACEMENT_CANCELLED','ATTENDU','ANNULE',?,?,NOW())
        ")->execute([(int)$placement['admission_id'],json_encode(['reason'=>$reason],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$actor]);
    }

    $pdo->prepare("
        UPDATE stage_placements
        SET statut='ANNULE',cancelled_at=NOW(),cancellation_reason=?
        WHERE id=? AND statut='CONFIRME'
    ")->execute([$reason,$placementId]);
    $pdo->prepare("
        UPDATE stage_reservations
        SET statut='ANNULEE',expires_at=NULL,cancelled_at=COALESCE(cancelled_at,NOW())
        WHERE id=?
    ")->execute([(int)$placement['reservation_id']]);
    $pdo->prepare("UPDATE stage_applications SET statut='ANNULEE' WHERE id=?")
        ->execute([(int)$placement['application_id']]);
    d4ReservationHistory($pdo,(int)$placement['reservation_id'],'PLACEMENT_CANCELLED','CONFIRMEE','ANNULEE',[
        'placement_id'=>$placementId,'reason'=>$reason,'source'=>'UNIVERSITY'
    ],$actor);
    $pdo->prepare("
        INSERT INTO stage_placement_history(placement_id,event_code,previous_status,new_status,details,actor_user_id,created_at)
        VALUES(?,'UNIVERSITY_CANCELLED','CONFIRME','ANNULE',?,?,NOW())
    ")->execute([$placementId,json_encode([
        'reservation_id'=>(int)$placement['reservation_id'],'reason'=>$reason
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$actor]);

    stageNotifyStudentReservation($pdo,(int)$placement['reservation_id'],'stage.placement.cancelled',[
        'reservation_status'=>'ANNULEE','workflow_status'=>'PLACEMENT_ANNULE',
        'placement_id'=>$placementId,'placement_uuid'=>$placement['uuid'],'placement_status'=>'ANNULE',
        'start_date'=>$placement['date_debut'],'end_date'=>$placement['date_fin'],
        'cancelled_at'=>date('Y-m-d H:i:s'),'reason'=>$reason
    ]);

    $pdo->commit();
    jsonResponse(true,"Placement et réservation annulés. L'étudiant a été invité à choisir un autre hôpital.",[
        'placement_id'=>$placementId,'placement_uuid'=>$placement['uuid'],
        'reservation_id'=>(int)$placement['reservation_id'],'workflow_status'=>'PLACEMENT_ANNULE'
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
