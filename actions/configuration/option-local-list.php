<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$templateId=(int)($_GET['template_id']??0);
$status=trim((string)($_GET['status']??''));
if(!$templateId)jsonResponse(false,'Modèle invalide.',[],422);

$where=["eas.source_template_id=?","o.ajoute_localement=1"];
$params=[$templateId];
if($status!==''){$where[]='o.validation_statut=?';$params[]=$status;}

$s=$pdo->prepare("
    SELECT
        o.id,o.code,o.nom,o.motif_ajout,o.validation_statut,o.actif,
        o.created_at,o.reviewed_at,o.review_comment,
        f.id filiere_id,f.code filiere_code,f.nom filiere_nom,
        e.id etablissement_id,e.code etablissement_code,e.nom etablissement_nom,
        et.libelle type_etablissement_nom,
        CONCAT_WS(' ',u.prenom,u.nom) demandeur_nom
    FROM options_specialites o
    JOIN filieres f ON f.id=o.filiere_id
    JOIN etablissements e ON e.id=o.etablissement_id
    JOIN etablissement_academic_settings eas ON eas.etablissement_id=e.id
    LEFT JOIN establishment_types et ON et.code=e.type_etablissement
    LEFT JOIN users u ON u.id=o.created_by_user_id
    WHERE ".implode(' AND ',$where)."
    ORDER BY
        FIELD(o.validation_statut,'EN_ATTENTE','VALIDE_LOCAL','INTEGRE_REFERENTIEL','REFUSE','NATIONAL'),
        o.created_at DESC,o.id DESC
");
$s->execute($params);
$items=$s->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(true,'',['items'=>$items]);
