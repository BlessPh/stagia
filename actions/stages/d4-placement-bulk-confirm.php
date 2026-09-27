<?php
/**
 * Endpoint AJAX de confirmation groupée de placements D4 vers une même structure d'accueil.
 * Les réservations non éligibles sont listées comme ignorées, sans faire échouer les autres du lot.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-letter-service.php';

requirePermission($pdo,'placement.university.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$uid=(int)($_SESSION['user_id']??0)?:null;
$campaignId=(int)($_POST['campaign_id']??0);
$hostId=(int)($_POST['host_etablissement_id']??0);
$ids=$_POST['reservation_ids']??[];
if(!is_array($ids))$ids=[$ids];
$ids=array_values(array_unique(array_filter(array_map('intval',$ids))));

if(!$eid||!$campaignId||!$hostId||!$ids)
    jsonResponse(false,'Session, structure d’accueil ou étudiants invalides.',[],422);

/** Génère l'UUID de chaque placement créé par l'opération groupée. */
function placementUuidV4Bulk():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

try{
    /* La campagne et l'offre hospitalière sont verrouillées avant le calcul de capacité. */
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT id,code,titre FROM stage_campaigns
        WHERE id=? AND owner_etablissement_id=? AND type_campagne='UNIVERSITAIRE'
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$campaignId,$eid]);$campaign=$s->fetch(PDO::FETCH_ASSOC);
    if(!$campaign)throw new RuntimeException('Session introuvable pour cette université.');

    $s=$pdo->prepare("
        SELECT p.id,p.capacite_acceptee,h.nom host_name
        FROM stage_campaign_participations p
        JOIN etablissements h ON h.id=p.host_etablissement_id
        WHERE p.university_campaign_id=? AND p.host_etablissement_id=? AND p.statut='ACCEPTEE'
          AND COALESCE(p.capacite_acceptee,0)>0
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$campaignId,$hostId]);$participation=$s->fetch(PDO::FETCH_ASSOC);
    if(!$participation)throw new RuntimeException("Cette structure n'a aucune offre retenue pour cette session.");

    $s=$pdo->prepare("SELECT COUNT(*) FROM stage_placements WHERE campaign_id=? AND host_etablissement_id=? AND statut='CONFIRME'");
    $s->execute([$campaignId,$hostId]);
    $available=max(0,(int)$participation['capacite_acceptee']-(int)$s->fetchColumn());

    /* Le lot entier est refusé immédiatement s'il dépasse le quota de l'hôpital. */
    if(count($ids)>$available)
        throw new RuntimeException(count($ids)." étudiant(s) sélectionné(s), mais seulement $available place(s) disponible(s).");

    $done=[];$skipped=[];

    /* Chaque réservation est vérifiée individuellement : les cas non confirmés sont ignorés et tracés. */
    foreach($ids as $rid){
        $s=$pdo->prepare("
            SELECT r.id reservation_id,r.statut reservation_status,app.id application_id,
                   app.statut application_status,app.campaign_id,
                   app.academic_enrollment_id,c.date_debut campaign_start,c.date_fin campaign_end,
                   se.student_id,p.id participation_id,p.host_etablissement_id,p.date_debut participation_start,
                   p.date_fin participation_end,sp.stagia_code,CONCAT_WS(' ',sp.prenom,sp.nom,sp.postnom) student_name,
                   pl.id placement_id,pl.statut placement_status
            FROM stage_reservations r
            JOIN stage_applications app ON app.id=r.application_id
            JOIN stage_campaigns c ON c.id=app.campaign_id AND c.owner_etablissement_id=? AND c.id=? AND c.type_campagne='UNIVERSITAIRE'
            JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
            JOIN student_enrollments se ON se.id=ae.enrollment_id
            JOIN student_profiles sp ON sp.id=se.student_id
            JOIN stage_campaign_participations p ON p.id=r.participation_id AND p.university_campaign_id=c.id
                 AND p.host_etablissement_id=? AND p.statut='ACCEPTEE'
            LEFT JOIN stage_placements pl ON pl.reservation_id=r.id
            WHERE r.id=? LIMIT 1 FOR UPDATE
        ");
        $s->execute([$eid,$campaignId,$hostId,$rid]);$x=$s->fetch(PDO::FETCH_ASSOC);

        if(!$x){$skipped[]=['reservation_id'=>$rid,'reason'=>'Introuvable'];continue;}
        if($x['application_status']!=='ACCEPTEE'){$skipped[]=['reservation_id'=>$rid,'student'=>$x['student_name'],'reason'=>'Décision universitaire en attente'];continue;}
        if($x['reservation_status']!=='CONFIRMEE'){$skipped[]=['reservation_id'=>$rid,'student'=>$x['student_name'],'reason'=>'Réservation non confirmée'];continue;}
        if($x['placement_status']==='CONFIRME'){$skipped[]=['reservation_id'=>$rid,'student'=>$x['student_name'],'reason'=>'Déjà affecté'];continue;}

        $start=$x['participation_start']?:$x['campaign_start'];
        $end=$x['participation_end']?:$x['campaign_end'];

        /* Le placement existant est mis à jour, ou une nouvelle ligne est insérée. */
        if($x['placement_id']){
            $pid=(int)$x['placement_id'];$prev=$x['placement_status'];
            $pdo->prepare("
                UPDATE stage_placements SET campaign_id=?,academic_enrollment_id=?,student_id=?,participation_id=?,
                host_etablissement_id=?,statut='CONFIRME',date_debut=?,date_fin=?,confirmed_at=NOW(),
                university_confirmed_by_user_id=?,university_confirmed_at=NOW(),cancelled_at=NULL,cancellation_reason=NULL
                WHERE id=?
            ")->execute([$campaignId,(int)$x['academic_enrollment_id'],(int)$x['student_id'],(int)$x['participation_id'],$hostId,$start,$end,$uid,$pid]);
        }else{
            $prev=null;
            $pdo->prepare("
                INSERT INTO stage_placements(uuid,reservation_id,campaign_id,academic_enrollment_id,student_id,
                participation_id,host_etablissement_id,statut,date_debut,date_fin,confirmed_at,university_confirmed_by_user_id,university_confirmed_at)
                VALUES(?,?,?,?,?,?,?,'CONFIRME',?,?,NOW(),?,NOW())
            ")->execute([placementUuidV4Bulk(),$rid,$campaignId,(int)$x['academic_enrollment_id'],(int)$x['student_id'],(int)$x['participation_id'],$hostId,$start,$end,$uid]);
            $pid=(int)$pdo->lastInsertId();
        }

        /* L'admission d'accueil est créée ou remise à l'état attendu avec le placement confirmé. */
        $s=$pdo->prepare("SELECT id,statut FROM stage_admissions WHERE reservation_id=? LIMIT 1 FOR UPDATE");
        $s->execute([$rid]);$ad=$s->fetch(PDO::FETCH_ASSOC);

        if($ad){
            if(!in_array($ad['statut'],['ADMIS','EN_COURS','TERMINE'],true))
                $pdo->prepare("UPDATE stage_admissions SET placement_id=?,host_etablissement_id=?,statut='ATTENDU',admitted_at=NULL,admitted_by=NULL WHERE id=?")
                    ->execute([$pid,$hostId,(int)$ad['id']]);
        }else{
            $pdo->prepare("INSERT INTO stage_admissions(uuid,reservation_id,placement_id,host_etablissement_id,statut,admitted_at,admitted_by,observation) VALUES(UUID(),?,?,?,'ATTENDU',NULL,NULL,NULL)")
                ->execute([$rid,$pid,$hostId]);
        }

        try{
            stageLetterCreateForAcceptedApplication($pdo,(int)$x['application_id'],$uid);
        }catch(Throwable $letterError){
            error_log('[STAGE LETTER AFTER BULK PLACEMENT] '.$letterError->getMessage());
        }

        $pdo->prepare("
            INSERT INTO stage_placement_history(placement_id,event_code,previous_status,new_status,details,actor_user_id,created_at)
            VALUES(?,'UNIVERSITY_BULK_CONFIRMED',?,'CONFIRME',?,?,NOW())
        ")->execute([$pid,$prev,json_encode(['reservation_id'=>$rid,'host_etablissement_id'=>$hostId,'mode'=>'BULK'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$uid]);

        $done[]=['reservation_id'=>$rid,'student'=>$x['student_name'],'stagia_code'=>$x['stagia_code']];
    }

    $pdo->commit();
    jsonResponse(true,count($done)." étudiant(s) affecté(s) vers « {$participation['host_name']} ».".(count($skipped)?" ".count($skipped)." ignoré(s).":''),[
        'affected_count'=>count($done),'skipped_count'=>count($skipped),'affected'=>$done,'skipped'=>$skipped
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
