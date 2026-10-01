<?php
/**
 * Endpoint AJAX de confirmation universitaire d'un placement individuel D4.
 * Il synchronise placement, candidature, admission d'accueil et historique dans une même transaction.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-letter-service.php';

requirePermission($pdo,'placement.university.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$rid=(int)($_POST['reservation_id']??0);
$actor=(int)($_SESSION['user_id']??0)?:null;

if(!$eid||!$rid)jsonResponse(false,'Réservation invalide.',[],422);

/** Génère l'UUID utilisé lors de la création d'un nouveau placement. */
function placementUuidV4():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

try{
    /* La réservation, sa participation et le placement existant sont verrouillés avant confirmation. */
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT r.id reservation_id,r.statut reservation_status,app.id application_id,
               app.statut application_status,app.campaign_id,
               app.academic_enrollment_id,c.date_debut campaign_start,c.date_fin campaign_end,
               se.student_id,p.id participation_id,p.host_etablissement_id,p.date_debut participation_start,
               p.date_fin participation_end,
               COALESCE(NULLIF(p.capacite_acceptee,0),NULLIF(p.capacite_allouee,0)) capacite_retenue,
               h.nom host_name,
               pl.id placement_id,pl.statut placement_status
        FROM stage_reservations r
        JOIN stage_applications app ON app.id=r.application_id
        JOIN stage_campaigns c ON c.id=app.campaign_id AND c.owner_etablissement_id=? AND c.type_campagne='UNIVERSITAIRE'
        JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaign_participations p ON p.id=r.participation_id AND p.university_campaign_id=c.id
             AND p.statut='ACCEPTEE'
             AND COALESCE(NULLIF(p.capacite_acceptee,0),NULLIF(p.capacite_allouee,0),0)>0
        JOIN etablissements h ON h.id=p.host_etablissement_id
        LEFT JOIN stage_placements pl ON pl.reservation_id=r.id
        WHERE r.id=? LIMIT 1 FOR UPDATE
    ");
    $s->execute([$eid,$rid]);$x=$s->fetch(PDO::FETCH_ASSOC);
    if(!$x)throw new RuntimeException('Choix étudiant introuvable pour cette université.');

    if($x['application_status']!=='ACCEPTEE')
        throw new RuntimeException("La candidature doit être acceptée par l'université avant le placement.");

    if($x['reservation_status']!=='CONFIRMEE'){
        if($x['reservation_status']==='EN_ATTENTE_PAIEMENT')throw new RuntimeException("Le placement attend encore la validation du paiement.");
        throw new RuntimeException("La réservation n'est pas prête pour le placement.");
    }

    /* La confirmation est idempotente si le placement est déjà confirmé. */
    if($x['placement_status']==='CONFIRME'){
        $pdo->commit();jsonResponse(true,'Cette affectation est déjà confirmée.');
    }

    $start=$x['participation_start']?:$x['campaign_start'];
    $end=$x['participation_end']?:$x['campaign_end'];

    /* Un placement existant est réactivé ; sinon une nouvelle ligne est créée. */
    if($x['placement_id']){
        $pid=(int)$x['placement_id'];$prev=$x['placement_status'];
        $pdo->prepare("
            UPDATE stage_placements SET campaign_id=?,academic_enrollment_id=?,student_id=?,participation_id=?,
            host_etablissement_id=?,statut='CONFIRME',date_debut=?,date_fin=?,confirmed_at=NOW(),
            university_confirmed_by_user_id=?,university_confirmed_at=NOW(),cancelled_at=NULL,cancellation_reason=NULL
            WHERE id=?
        ")->execute([(int)$x['campaign_id'],(int)$x['academic_enrollment_id'],(int)$x['student_id'],(int)$x['participation_id'],(int)$x['host_etablissement_id'],$start,$end,$actor,$pid]);
    }else{
        $prev=null;
        $pdo->prepare("
            INSERT INTO stage_placements(uuid,reservation_id,campaign_id,academic_enrollment_id,student_id,
            participation_id,host_etablissement_id,statut,date_debut,date_fin,confirmed_at,university_confirmed_by_user_id,university_confirmed_at)
            VALUES(?,?,?,?,?,?,?,'CONFIRME',?,?,NOW(),?,NOW())
        ")->execute([placementUuidV4(),$rid,(int)$x['campaign_id'],(int)$x['academic_enrollment_id'],(int)$x['student_id'],(int)$x['participation_id'],(int)$x['host_etablissement_id'],$start,$end,$actor]);
        $pid=(int)$pdo->lastInsertId();
    }

    /* L'admission hospitalière est créée ou synchronisée pour rendre le stagiaire visible à l'accueil. */
    $s=$pdo->prepare("SELECT id,statut FROM stage_admissions WHERE reservation_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$rid]);$ad=$s->fetch(PDO::FETCH_ASSOC);

    if($ad){
        if(!in_array($ad['statut'],['ADMIS','EN_COURS','TERMINE'],true))
            $pdo->prepare("UPDATE stage_admissions SET placement_id=?,host_etablissement_id=?,statut='ATTENDU',admitted_at=NULL,admitted_by=NULL WHERE id=?")
                ->execute([$pid,(int)$x['host_etablissement_id'],(int)$ad['id']]);
    }else{
        $pdo->prepare("INSERT INTO stage_admissions(uuid,reservation_id,placement_id,host_etablissement_id,statut,admitted_at,admitted_by,observation) VALUES(UUID(),?,?,?,'ATTENDU',NULL,NULL,NULL)")
            ->execute([$rid,$pid,(int)$x['host_etablissement_id']]);
    }

    $letterUrl=stageLetterUrl((int)$x['application_id']);
    try{
        stageLetterCreateForAcceptedApplication($pdo,(int)$x['application_id'],$actor);
    }catch(Throwable $letterError){
        error_log('[STAGE LETTER AFTER PLACEMENT] '.$letterError->getMessage());
    }

    /* L'historique non destructif enregistre la confirmation et le destinataire du placement. */
    $pdo->prepare("
        INSERT INTO stage_placement_history(placement_id,event_code,previous_status,new_status,details,actor_user_id,created_at)
        VALUES(?,'UNIVERSITY_CONFIRMED',?,'CONFIRME',?,?,NOW())
    ")->execute([$pid,$prev,json_encode(['reservation_id'=>$rid,'host_etablissement_id'=>(int)$x['host_etablissement_id'],'host_name'=>$x['host_name']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$actor]);

    $pdo->commit();
    jsonResponse(true,"Placement confirmé vers « {$x['host_name']} ». Le stagiaire est maintenant visible côté structure d’accueil.",[
        'placement_id'=>$pid,
        'letter_url'=>$letterUrl
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
