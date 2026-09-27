<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
$id=(int)($_GET['id']??0);

if(!$etablissementId || !$id)
    jsonResponse(false,'Campagne invalide.',[],422);

/* Campagne */
$stmt=$pdo->prepare("
    SELECT c.id,c.uuid,c.code,c.titre,c.description,c.type_campagne,
           c.date_debut,c.date_fin,c.ouverture_candidatures,
           c.cloture_candidatures,c.statut,
           st.id stage_type_id,st.code stage_type_code,st.libelle stage_type,
           aa.libelle annee_academique
    FROM stage_campaigns c
    JOIN stage_types st ON st.id=c.stage_type_id
    LEFT JOIN annees_academiques aa
      ON aa.id=c.annee_academique_id
     AND aa.etablissement_id=c.owner_etablissement_id
    WHERE c.id=?
      AND c.owner_etablissement_id=?
      AND c.type_campagne='UNIVERSITAIRE'
    LIMIT 1
");
$stmt->execute([$id,$etablissementId]);
$campaign=$stmt->fetch();

if(!$campaign)
    jsonResponse(false,'Campagne introuvable.',[],404);

/* Promotions */
$stmt=$pdo->prepare("
    SELECT p.id,p.nom,p.niveau,
           f.nom filiere,d.nom departement,fa.nom faculte
    FROM stage_campaign_promotions cp
    JOIN promotions p ON p.id=cp.promotion_id
    JOIN filieres f
      ON f.id=p.filiere_id
     AND f.etablissement_id=?
    LEFT JOIN departements d
      ON d.id=f.departement_id
     AND d.etablissement_id=?
    LEFT JOIN facultes fa
      ON fa.id=d.faculte_id
     AND fa.etablissement_id=?
    WHERE cp.campaign_id=?
      AND p.etablissement_id=?
    ORDER BY fa.nom,d.nom,f.nom,p.nom
");
$stmt->execute([
    $etablissementId,$etablissementId,$etablissementId,
    $id,$etablissementId
]);

$promotions=$stmt->fetchAll();

/* Hôpitaux sollicités */
$stmt=$pdo->prepare("
    SELECT sp.id,sp.host_etablissement_id,sp.host_campaign_id,
           sp.statut,sp.capacite_allouee,
           sp.date_debut,sp.date_fin,sp.conditions,
           sp.motif_refus,sp.frais_requis,
           sp.montant_frais,sp.devise,
           sp.responded_at,sp.created_at,
           h.nom hopital,h.ville,h.province,
           hc.code host_campaign_code,hc.titre host_campaign_titre
    FROM stage_campaign_participations sp
    JOIN etablissements h ON h.id=sp.host_etablissement_id
    LEFT JOIN stage_campaigns hc
      ON hc.id=sp.host_campaign_id
     AND hc.type_campagne='ACCUEIL'
    WHERE sp.university_campaign_id=?
    ORDER BY sp.id DESC
");
$stmt->execute([$id]);
$participations=$stmt->fetchAll();

/* Statistiques */
$stmt=$pdo->prepare("
    SELECT COUNT(*) total,
           COALESCE(SUM(statut='SOLLICITEE'),0) sollicitees,
           COALESCE(SUM(statut='ACCEPTEE'),0) acceptees,
           COALESCE(SUM(statut='REFUSEE'),0) refusees,
           COALESCE(SUM(
               CASE WHEN statut='ACCEPTEE'
                    THEN capacite_allouee ELSE 0 END
           ),0) capacite
    FROM stage_campaign_participations
    WHERE university_campaign_id=?
");
$stmt->execute([$id]);
$stats=$stmt->fetch();

jsonResponse(true,'',[
    'campaign'=>$campaign,
    'promotions'=>$promotions,
    'participations'=>$participations,
    'stats'=>[
        'total'=>(int)$stats['total'],
        'sollicitees'=>(int)$stats['sollicitees'],
        'acceptees'=>(int)$stats['acceptees'],
        'refusees'=>(int)$stats['refusees'],
        'capacite'=>(int)$stats['capacite']
    ]
]);