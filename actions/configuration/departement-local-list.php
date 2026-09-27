<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$templateId=(int)($_GET['template_id']??0);
$status=trim((string)($_GET['status']??''));

if(!$templateId)jsonResponse(false,'Modèle invalide.',[],422);

$where=['eas.source_template_id=?','d.ajoute_localement=1'];
$params=[$templateId];

if($status!==''){
    $where[]='d.validation_statut=?';
    $params[]=$status;
}

$s=$pdo->prepare("
    SELECT
        d.id,d.code,d.nom,d.motif_ajout,d.validation_statut,d.actif,
        d.faculte_id,d.created_at,d.review_comment,
        f.nom faculte_nom,
        e.id etablissement_id,
        e.code etablissement_code,
        e.nom etablissement_nom,
        et.libelle type_etablissement_nom,
        CONCAT_WS(' ',u.prenom,u.nom) demandeur_nom
    FROM departements d
    JOIN etablissements e
      ON e.id=d.etablissement_id
    JOIN etablissement_academic_settings eas
      ON eas.etablissement_id=e.id
    LEFT JOIN facultes f
      ON f.id=d.faculte_id
     AND f.etablissement_id=d.etablissement_id
    LEFT JOIN establishment_types et
      ON et.code=e.type_etablissement
    LEFT JOIN users u
      ON u.id=d.created_by_user_id
    WHERE ".implode(' AND ',$where)."
    ORDER BY
        FIELD(d.validation_statut,'EN_ATTENTE','VALIDE_LOCAL','INTEGRE_REFERENTIEL','REFUSE'),
        d.created_at DESC,d.id DESC
");
$s->execute($params);

jsonResponse(true,'',['items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
