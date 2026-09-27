<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$templateId=(int)($_GET['template_id']??0);
$status=trim((string)($_GET['status']??''));

if(!$templateId)jsonResponse(false,'Modèle invalide.',[],422);

$where=['eas.source_template_id=?','p.ajoute_localement=1'];
$params=[$templateId];

if($status!==''){
    $where[]='p.validation_statut=?';
    $params[]=$status;
}

$s=$pdo->prepare("
    SELECT
        p.id,p.code,p.nom,p.duree_annees,p.description,
        p.curriculum_reference_id,p.preparatory_level_enabled,
        p.motif_ajout,p.validation_statut,p.actif,p.review_comment,
        p.faculte_id,p.departement_id,p.created_at,
        r.code curriculum_code,
        r.nom curriculum_nom,
        u.nom unite_nom,
        d.nom departement_nom,
        CASE
            WHEN p.departement_id IS NOT NULL THEN 'DEPARTEMENT'
            WHEN p.faculte_id IS NOT NULL THEN 'UNITE'
            ELSE 'ETABLISSEMENT'
        END rattachement_type,
        e.id etablissement_id,
        e.code etablissement_code,
        e.nom etablissement_nom,
        et.libelle type_etablissement_nom,
        CONCAT_WS(' ',usr.prenom,usr.nom) demandeur_nom
    FROM filieres p
    JOIN etablissements e
      ON e.id=p.etablissement_id
    JOIN etablissement_academic_settings eas
      ON eas.etablissement_id=e.id
    LEFT JOIN curriculum_references r
      ON r.id=p.curriculum_reference_id
    LEFT JOIN facultes u
      ON u.id=p.faculte_id
     AND u.etablissement_id=p.etablissement_id
    LEFT JOIN departements d
      ON d.id=p.departement_id
     AND d.etablissement_id=p.etablissement_id
    LEFT JOIN establishment_types et
      ON et.code=e.type_etablissement
    LEFT JOIN users usr
      ON usr.id=p.created_by_user_id
    WHERE ".implode(' AND ',$where)."
    ORDER BY
        FIELD(p.validation_statut,'EN_ATTENTE','VALIDE_LOCAL','INTEGRE_REFERENTIEL','REFUSE'),
        p.created_at DESC,p.id DESC
");
$s->execute($params);

jsonResponse(true,'',['items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
