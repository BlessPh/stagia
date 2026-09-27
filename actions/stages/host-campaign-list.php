<?php
/**
 * Endpoint AJAX de suivi des campagnes organisées par l'établissement d'accueil.
 * Il consolide capacités, universités acceptées, allocations et réservations en cours.
 */
ob_start();header('Content-Type: application/json; charset=utf-8');
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';

/** Envoie la réponse JSON unique après nettoyage éventuel du tampon de sortie. */
function respond($ok,$message='',$data=[],$status=200){
    http_response_code($status);if(ob_get_length())ob_clean();
    echo json_encode(['success'=>$ok,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE);exit;
}
if(empty($_SESSION['user_id']))respond(false,'Session expirée.',[],401);
if(!in_array($_SESSION['role_code']??'',['ADMIN_ACCUEIL','COORDINATEUR_STAGES'],true))respond(false,'Accès interdit.',[],403);

try{
    /* Les campagnes et tous leurs indicateurs sont strictement bornés à l'hôpital de session. */
    $hostId=currentEtablissementId($pdo);if(!$hostId)respond(false,'Aucun établissement associé.',[],403);
    $s=$pdo->prepare("
        SELECT c.id,c.uuid,c.code,c.stage_type_id,c.titre,c.description,c.date_debut,c.date_fin,c.statut,c.created_at,
               COALESCE(cp.capacite_totale,0) capacite_totale,COALESCE(cp.reserve_hospitaliere,0) reserve_hospitaliere,
               (SELECT COUNT(*) FROM stage_campaign_participations p WHERE p.host_campaign_id=c.id AND p.host_etablissement_id=? AND p.statut='ACCEPTEE') participations,
               (SELECT COUNT(DISTINCT uc.owner_etablissement_id) FROM stage_campaign_participations p JOIN stage_campaigns uc ON uc.id=p.university_campaign_id WHERE p.host_campaign_id=c.id AND p.host_etablissement_id=? AND p.statut='ACCEPTEE') universites,
               (SELECT COALESCE(SUM(COALESCE(p.capacite_allouee,p.capacite_proposee,0)),0) FROM stage_campaign_participations p WHERE p.host_campaign_id=c.id AND p.host_etablissement_id=? AND p.statut='ACCEPTEE') capacite_allouee,
               (SELECT COUNT(*) FROM stage_reservations sr JOIN stage_applications sa ON sa.id=sr.application_id JOIN stage_campaign_participations p ON p.id=sa.participation_id
                 WHERE p.host_campaign_id=c.id AND p.host_etablissement_id=? AND (sr.statut IN('CONFIRMEE','EN_ATTENTE_PAIEMENT') OR (sr.statut='RESERVEE_TEMPORAIREMENT' AND (sr.expires_at IS NULL OR sr.expires_at>NOW())))) places_occupees,
               (SELECT COUNT(*) FROM stage_reservations sr JOIN stage_applications sa ON sa.id=sr.application_id JOIN stage_campaign_participations p ON p.id=sa.participation_id
                 WHERE p.host_campaign_id=c.id AND p.host_etablissement_id=? AND sr.statut='CONFIRMEE') reservations_confirmees
        FROM stage_campaigns c LEFT JOIN stage_capacity_pools cp ON cp.host_campaign_id=c.id
        WHERE c.owner_etablissement_id=? AND c.type_campagne='ACCUEIL'
        ORDER BY c.created_at DESC,c.id DESC
    ");
    $s->execute([$hostId,$hostId,$hostId,$hostId,$hostId,$hostId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    /* Les participations acceptées sont chargées pour détailler la répartition par université. */
    $p=$pdo->prepare("
        SELECT p.id participation_id,p.university_campaign_id,p.capacite_proposee,p.capacite_allouee,p.date_debut,p.date_fin,p.conditions,p.frais_requis,p.montant_frais,p.devise,p.responded_at,
               uc.code university_campaign_code,uc.titre university_campaign_title,e.id university_id,e.code university_code,e.nom university_name
        FROM stage_campaign_participations p JOIN stage_campaigns uc ON uc.id=p.university_campaign_id JOIN etablissements e ON e.id=uc.owner_etablissement_id
        WHERE p.host_campaign_id=? AND p.host_etablissement_id=? AND p.statut='ACCEPTEE' ORDER BY e.nom,p.id
    ");

    $stats=['total'=>count($items),'ouvertes'=>0,'capacite'=>0,'allouees'=>0,'occupees'=>0];
    /* Capacités restantes et alertes de surallocation sont dérivées côté serveur. */
    foreach($items as &$x){
        $p->execute([$x['id'],$hostId]);$x['universites_liees']=$p->fetchAll(PDO::FETCH_ASSOC);
        foreach(['id','capacite_totale','reserve_hospitaliere','capacite_allouee','places_occupees','reservations_confirmees','universites','participations'] as $k)$x[$k]=(int)$x[$k];
        $x['places_non_allouees']=max(0,$x['capacite_totale']-$x['capacite_allouee']);
        $x['places_libres_quota']=max(0,$x['capacite_allouee']-$x['places_occupees']);
        $x['capacite_globale_restante']=max(0,$x['capacite_totale']-$x['places_occupees']);
        $x['surallocation']=max(0,$x['capacite_allouee']-$x['capacite_totale']);
        if($x['statut']==='OUVERTE')$stats['ouvertes']++;
        $stats['capacite']+=$x['capacite_totale'];$stats['allouees']+=$x['capacite_allouee'];$stats['occupees']+=$x['places_occupees'];
    }unset($x);
    respond(true,'',['items'=>$items,'stats'=>$stats]);
}catch(Throwable $e){respond(false,'Erreur chargement campagnes : '.$e->getMessage(),[],500);}
