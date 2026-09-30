<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';

verifyAjaxCsrf();
$eid=(int)($_SESSION['etablissement_id']??0);$id=(int)($_POST['id']??0);
$action=strtoupper(trim((string)($_POST['action']??'')));$reason=trim((string)($_POST['reason']??''));
if(!$eid||!$id)jsonResponse(false,'Campagne invalide.',[],422);

$permission=$action==='CANCEL'?'campaign.university.cancel':'campaign.university.publish';
requirePermission($pdo,$permission);
if(!in_array($action,['PUBLISH','OPEN','CLOSE','FINISH','CANCEL'],true))jsonResponse(false,'Action de campagne invalide.',[],422);

try{
    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT c.*,st.code stage_type_code,NOW() db_now,CURDATE() db_date FROM stage_campaigns c JOIN stage_types st ON st.id=c.stage_type_id WHERE c.id=? AND c.owner_etablissement_id=? AND c.type_campagne='UNIVERSITAIRE' LIMIT 1 FOR UPDATE");
    $s->execute([$id,$eid]);$c=$s->fetch(PDO::FETCH_ASSOC);
    if(!$c)throw new RuntimeException('Campagne introuvable.');

    $old=$c['statut'];$new=$old;$message='';$userId=(int)($_SESSION['user_id']??0)?:null;
    $configuration=campaignConfig($c['configuration']);

    if($action==='PUBLISH'){
        if($old!=='BROUILLON')throw new RuntimeException('Seul un brouillon peut être publié.');

        $s=$pdo->prepare("SELECT COUNT(*) FROM stage_campaign_promotions WHERE campaign_id=?");$s->execute([$id]);
        if((int)$s->fetchColumn()<1)throw new RuntimeException('La campagne doit cibler au moins une promotion.');
        if(empty($c['objectif_stage']))throw new RuntimeException("L'objectif du stage doit être renseigné avant publication.");
        if(empty($c['ouverture_candidatures'])||empty($c['cloture_candidatures']))throw new RuntimeException('La période des candidatures est incomplète.');
        if($c['cloture_candidatures']<=$c['db_now'])throw new RuntimeException('La période de candidature est déjà clôturée.');

        $configuration['policy_snapshot']=stagePolicySnapshot($pdo,(int)$c['stage_type_id']);
        $configuration['configuration_frozen_at']=$c['db_now'];

        $hostIds=array_values(array_unique(array_filter(array_map('intval',(array)($configuration['selected_host_ids']??[])))));
        $demands=(array)($configuration['selected_host_demands']??[]);

        if(!$hostIds)throw new RuntimeException('Sélectionnez au moins un hôpital avant de publier cette campagne.');

        if($hostIds){
            $ins=$pdo->prepare("INSERT INTO stage_campaign_participations(university_campaign_id,host_etablissement_id,statut,capacite_demandee,date_debut,date_fin)
                                VALUES(?,?,'SOLLICITEE',?,?,?)
                                ON DUPLICATE KEY UPDATE capacite_demandee=VALUES(capacite_demandee),date_debut=VALUES(date_debut),date_fin=VALUES(date_fin)");
            foreach($hostIds as $hid){
                $n=(int)($demands[(string)$hid]??$demands[$hid]??0);
                if($n<1)throw new RuntimeException("Indiquez le nombre de places souhaitées pour chaque hôpital sélectionné.");
                $ins->execute([$id,$hid,$n,$c['date_debut'],$c['date_fin']]);
            }
            $new='EN_PREPARATION';$message=count($hostIds).' hôpital(s) sollicité(s). La campagne attend leurs réponses avant ouverture.';
        }else{
            $new=$c['ouverture_candidatures']<=$c['db_now']?'OUVERTE':'EN_PREPARATION';
            $message=$new==='OUVERTE'?'Campagne publiée et ouverte aux étudiants éligibles.':'Campagne publiée. Elle pourra être ouverte à la date prévue.';
        }

        $pdo->prepare("UPDATE stage_campaigns SET statut=?,configuration=?,published_by_user_id=?,published_at=NOW() WHERE id=? AND owner_etablissement_id=?")
            ->execute([$new,json_encode($configuration,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId,$id,$eid]);
    }

    if($action==='OPEN'){
        if($old!=='EN_PREPARATION')throw new RuntimeException("La campagne n'est pas en préparation.");
        if(empty($c['ouverture_candidatures'])||$c['ouverture_candidatures']>$c['db_now'])throw new RuntimeException("La date d'ouverture des candidatures n'est pas encore atteinte.");
        if(empty($c['cloture_candidatures'])||$c['cloture_candidatures']<=$c['db_now'])throw new RuntimeException('La date de clôture des candidatures est dépassée.');

        if(!campaignHasAcceptedHostCapacity($pdo,$id))
            throw new RuntimeException("Aucun hôpital sollicité n'a encore accepté cette campagne avec une capacité valide.");

        $new='OUVERTE';$pdo->prepare("UPDATE stage_campaigns SET statut='OUVERTE' WHERE id=? AND owner_etablissement_id=?")->execute([$id,$eid]);
        $message='Campagne ouverte aux étudiants éligibles.';
    }

    if($action==='CLOSE'){
        if($old!=='OUVERTE')throw new RuntimeException('Seule une campagne ouverte peut clôturer les candidatures.');
        $new='CLOTUREE';$pdo->prepare("UPDATE stage_campaigns SET statut='CLOTUREE' WHERE id=? AND owner_etablissement_id=?")->execute([$id,$eid]);$message='Candidatures clôturées.';
    }

    if($action==='FINISH'){
        if($old!=='CLOTUREE')throw new RuntimeException('La campagne doit d’abord être clôturée.');
        if($c['date_fin']>$c['db_date'])throw new RuntimeException("La période du stage n'est pas encore terminée.");
        $new='TERMINEE';$pdo->prepare("UPDATE stage_campaigns SET statut='TERMINEE' WHERE id=? AND owner_etablissement_id=?")->execute([$id,$eid]);$message='Campagne marquée comme terminée.';
    }

    if($action==='CANCEL'){
        if(in_array($old,['TERMINEE','ANNULEE'],true))throw new RuntimeException('Cette campagne ne peut plus être annulée.');
        if($reason==='')throw new RuntimeException("Le motif d'annulation est obligatoire.");
        $new='ANNULEE';$pdo->prepare("UPDATE stage_campaigns SET statut='ANNULEE',cancelled_by_user_id=?,cancelled_at=NOW(),cancellation_reason=? WHERE id=? AND owner_etablissement_id=?")->execute([$userId,$reason,$id,$eid]);
        $message='Campagne annulée.';
    }

    campaignStatusHistory($pdo,$id,$old,$new,$reason!==''?$reason:$message,$userId);
    $pdo->commit();jsonResponse(true,$message,['statut'=>$new]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
