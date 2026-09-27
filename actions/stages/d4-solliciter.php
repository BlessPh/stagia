<?php
/**
 * Endpoint AJAX de sollicitation d'une structure d'accueil pour une campagne universitaire D4.
 * Il crée ou réactive une participation après contrôle de la période, du type d'établissement et des doublons.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-d4-participation.php';

requirePermission($pdo,'campaign.d4.solicit');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$uid=(int)($_SESSION['user_id']??0)?:null;
$campaignId=(int)($_POST['campaign_id']??0);
$hostId=(int)($_POST['host_etablissement_id']??0);
$requested=(int)($_POST['capacite_demandee']??0);
$dateStart=trim((string)($_POST['date_debut']??''));
$dateEnd=trim((string)($_POST['date_fin']??''));

if(!$eid||!$campaignId||!$hostId||$requested<1)
    jsonResponse(false,'Session, structure d’accueil et volume souhaité sont obligatoires.',[],422);

try{
    /* La campagne et la participation existante sont verrouillées afin de sécuriser une sollicitation ou re-sollicitation. */
    $pdo->beginTransaction();
    $c=universityD4Campaign($pdo,$campaignId,$eid,true);

    if($c['statut']!=='EN_PREPARATION'||empty($c['published_at']))
        throw new RuntimeException("La session doit être publiée et en préparation avant de solliciter les structures d’accueil.");

    $s=$pdo->prepare("
        SELECT e.id,e.nom
        FROM etablissements e
        JOIN establishment_types et ON et.code=e.type_etablissement AND et.host_enabled=1 AND et.actif=1
        WHERE e.id=? AND e.statut IN('VALIDE','ACTIF')
        LIMIT 1
    ");
    $s->execute([$hostId]);$host=$s->fetch(PDO::FETCH_ASSOC);
    if(!$host)throw new RuntimeException("Structure d’accueil invalide ou non habilitée.");

    if($dateStart==='')$dateStart=$c['date_debut'];
    if($dateEnd==='')$dateEnd=$c['date_fin'];

    $start=strtotime($dateStart);$end=strtotime($dateEnd);
    if(!$start||!$end||$start>$end)throw new RuntimeException("Période d’accueil demandée invalide.");
    if($start<strtotime($c['date_debut'])||$end>strtotime($c['date_fin']))
        throw new RuntimeException("La période demandée doit rester comprise dans la période de la session.");

    $s=$pdo->prepare("
        SELECT id,statut FROM stage_campaign_participations
        WHERE university_campaign_id=? AND host_etablissement_id=?
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$campaignId,$hostId]);$existing=$s->fetch(PDO::FETCH_ASSOC);

    if($existing&&$existing['statut']!=='ANNULEE')
        throw new RuntimeException("Cette structure a déjà été sollicitée pour cette session.");

    /* Une participation annulée peut être réinitialisée ; les autres statuts empêchent un doublon. */
    if($existing){
        $id=(int)$existing['id'];
        $pdo->prepare("
            UPDATE stage_campaign_participations SET statut='SOLLICITEE',capacite_demandee=?,capacite_proposee=NULL,
            capacite_acceptee=NULL,capacite_allouee=NULL,host_campaign_id=NULL,date_debut=?,date_fin=?,
            conditions=NULL,motif_refus=NULL,frais_requis=0,montant_frais=NULL,devise=NULL,requested_by_user_id=?,
            requested_at=NOW(),responded_by_user_id=NULL,responded_at=NULL,finalized_by_user_id=NULL,finalized_at=NULL,
            cancelled_by_user_id=NULL,cancelled_at=NULL,cancellation_reason=NULL WHERE id=?
        ")->execute([$requested,$dateStart,$dateEnd,$uid,$id]);

        d4ParticipationHistory($pdo,$id,'RESOLICITATION','ANNULEE','SOLLICITEE',[
            'capacite_demandee'=>$requested,'date_debut'=>$dateStart,'date_fin'=>$dateEnd
        ],$uid);
    }else{
        $pdo->prepare("
            INSERT INTO stage_campaign_participations(
                university_campaign_id,host_etablissement_id,host_campaign_id,statut,capacite_demandee,
                capacite_proposee,capacite_acceptee,capacite_allouee,date_debut,date_fin,conditions,motif_refus,
                frais_requis,montant_frais,devise,requested_by_user_id,requested_at
            ) VALUES(?,?,NULL,'SOLLICITEE',?,NULL,NULL,NULL,?,?,NULL,NULL,0,NULL,NULL,?,NOW())
        ")->execute([$campaignId,$hostId,$requested,$dateStart,$dateEnd,$uid]);

        $id=(int)$pdo->lastInsertId();
        d4ParticipationHistory($pdo,$id,'SOLLICITATION',null,'SOLLICITEE',[
            'capacite_demandee'=>$requested,'date_debut'=>$dateStart,'date_fin'=>$dateEnd
        ],$uid);
    }

    $pdo->commit();
    jsonResponse(true,"Sollicitation envoyée à « {$host['nom']} ».");
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
