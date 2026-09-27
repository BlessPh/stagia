<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$typeCode=strtoupper(trim((string)($_GET['type_code']??'')));
$status=trim((string)($_GET['status']??''));

if($typeCode==='')jsonResponse(false,"Type d'établissement invalide.",[],422);

$where=[
    'e.type_etablissement=?',
    'h.ajoute_localement=1'
];
$params=[$typeCode];

if($status!==''){
    $where[]='h.validation_statut=?';
    $params[]=$status;
}

$s=$pdo->prepare("
    SELECT
        h.id,h.code,h.nom,h.type,h.description,h.capacite,
        h.motif_ajout,h.validation_statut,h.actif,h.review_comment,
        h.parent_id,h.created_at,
        p.nom parent_nom,
        p.type parent_type,
        e.id etablissement_id,
        e.code etablissement_code,
        e.nom etablissement_nom,
        et.libelle type_etablissement_nom,
        CONCAT_WS(' ',u.prenom,u.nom) demandeur_nom
    FROM host_units h
    JOIN etablissements e
      ON e.id=h.host_etablissement_id
    LEFT JOIN establishment_types et
      ON et.code=e.type_etablissement
    LEFT JOIN host_units p
      ON p.id=h.parent_id
     AND p.host_etablissement_id=h.host_etablissement_id
    LEFT JOIN users u
      ON u.id=h.created_by_user_id
    WHERE ".implode(' AND ',$where)."
    ORDER BY
        FIELD(h.validation_statut,'EN_ATTENTE','VALIDE_LOCAL','INTEGRE_REFERENTIEL','REFUSE'),
        h.created_at DESC,
        h.id DESC
");
$s->execute($params);

jsonResponse(true,'',['items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
