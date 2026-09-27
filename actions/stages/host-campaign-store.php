<?php
/**
 * Endpoint AJAX de création d'une campagne d'accueil avec son bassin de capacité.
 * La campagne commence en préparation et reçoit un code lisible après insertion.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES']);
verifyAjaxCsrf();

/** Génère l'UUID de la campagne avant son insertion en base. */
function hostCampaignUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

try{
    /* Les dates, le type de stage et les capacités sont validés avant toute écriture. */
    $hostId=currentEtablissementId($pdo);$type=(int)($_POST['stage_type_id']??0);
    $title=trim((string)($_POST['titre']??''));$start=(string)($_POST['date_debut']??'');$end=(string)($_POST['date_fin']??'');
    $capacity=(int)($_POST['capacite_totale']??0);$reserve=(int)($_POST['reserve_hospitaliere']??0);

    if(!$hostId||!$type||$title===''||$start===''||$end==='')jsonResponse(false,'Informations obligatoires manquantes.',[],422);
    if($end<$start)jsonResponse(false,'Période invalide.',[],422);
    if($capacity<1)jsonResponse(false,'La capacité totale doit être supérieure à zéro.',[],422);
    if($reserve<0||$reserve>$capacity)jsonResponse(false,'Réserve hospitalière invalide.',[],422);

    $s=$pdo->prepare("SELECT id FROM etablissements WHERE id=? AND type_etablissement='HOPITAL' AND statut IN('VALIDE','ACTIF')");
    $s->execute([$hostId]);if(!$s->fetchColumn())jsonResponse(false,"Votre établissement n'est pas configuré comme hôpital actif.",[],403);
    $s=$pdo->prepare("SELECT id FROM stage_types WHERE id=? AND actif=1");$s->execute([$type]);
    if(!$s->fetchColumn())jsonResponse(false,'Type de stage invalide.',[],422);

    /* Campagne, code définitif et bassin de capacité doivent être créés ensemble. */
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO stage_campaigns(uuid,stage_type_id,owner_etablissement_id,type_campagne,titre,date_debut,date_fin,statut,created_by_user_id)
                   VALUES(?,?,?,'ACCUEIL',?,?,?,'EN_PREPARATION',?)")
        ->execute([hostCampaignUuid(),$type,$hostId,$title,$start,$end,(int)($_SESSION['user_id']??0)?:null]);
    /* Le code fonctionnel est dérivé de l'identifiant attribué par la base. */
    $id=(int)$pdo->lastInsertId();$code='ACC-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);
    $pdo->prepare("UPDATE stage_campaigns SET code=? WHERE id=?")->execute([$code,$id]);
    $pdo->prepare("INSERT INTO stage_capacity_pools(host_campaign_id,capacite_totale,reserve_hospitaliere) VALUES(?,?,?)")->execute([$id,$capacity,$reserve]);
    $pdo->commit();jsonResponse(true,"Campagne d'accueil $code créée.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){
    /* Une erreur annule la campagne et son bassin pour éviter une configuration incomplète. */
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
