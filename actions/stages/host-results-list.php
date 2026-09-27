<?php
/**
 * Endpoint AJAX de préparation des transmissions de résultats depuis l'établissement d'accueil.
 * Il calcule l'état de préparation de chaque campagne et le dernier envoi déjà effectué.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-results.php';

requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE','AUTORITE_HOSPITALIERE']);

try{
    /* Les campagnes disponibles sont dérivées des affectations non annulées de l'hôpital courant. */
    $hostId=stageResultHostId($pdo);
    if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);

    $q=trim((string)($_GET['q']??''));
    $where=["a.host_etablissement_id=?","a.statut<>'ANNULEE'"];
    $params=[$hostId];
    if($q!==''){
        $where[]="(c.code LIKE ? OR c.titre LIKE ? OR uni.nom LIKE ?)";
        $like='%'.$q.'%';array_push($params,$like,$like,$like);
    }

    $s=$pdo->prepare("
        SELECT DISTINCT
            c.id campaign_id,c.code,c.titre,c.date_debut,c.date_fin,c.statut campaign_status,
            uni.id university_id,uni.nom university_name
        FROM stage_assignments a
        JOIN stage_admissions ad ON ad.id=a.admission_id
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications sa ON sa.id=sr.application_id
        JOIN stage_campaigns c ON c.id=sa.campaign_id
        JOIN etablissements uni ON uni.id=c.owner_etablissement_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY c.date_debut DESC,c.id DESC
    ");
    $s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);

    $stats=['total'=>count($rows),'pret'=>0,'envoye'=>0,'valide'=>0];
    /* Les métriques et la dernière transmission déterminent si le bouton d'envoi est autorisé. */
    foreach($rows as &$x){
        $x['campaign_id']=(int)$x['campaign_id'];
        $x['university_id']=(int)$x['university_id'];

        $m=stageResultCampaignStats($pdo,$hostId,$x['campaign_id']);
        $tr=stageResultLatestTransmission($pdo,$hostId,$x['campaign_id']);

        $x['metrics']=$m;
        $x['transmission']=$tr?[
            'id'=>(int)$tr['id'],
            'uuid'=>$tr['uuid'],
            'statut'=>$tr['statut'],
            'transmitted_at'=>$tr['transmitted_at'],
            'received_at'=>$tr['received_at'],
            'validated_at'=>$tr['validated_at']
        ]:null;

        $x['can_send']=(!$tr||!in_array($tr['statut'],['ENVOYE','RECU','VALIDE','ARCHIVE'],true))&&$m['ready']?1:0;

        if($m['ready'])$stats['pret']++;
        if($tr&&in_array($tr['statut'],['ENVOYE','RECU'],true))$stats['envoye']++;
        if($tr&&in_array($tr['statut'],['VALIDE','ARCHIVE'],true))$stats['valide']++;
    }
    unset($x);

    /* Réponse de pilotage : campagnes, readiness et compteurs de transmission. */
    jsonResponse(true,'',['items'=>$rows,'stats'=>$stats]);
}catch(Throwable $e){
    error_log('[HOST RESULTS LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur résultats : '.$e->getMessage(),[],500);
}
