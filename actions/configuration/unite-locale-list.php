<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
requireAjaxRole(['SUPER_ADMIN']);
$templateId=(int)($_GET['template_id']??0);$status=trim((string)($_GET['status']??''));if(!$templateId)jsonResponse(false,'Modèle invalide.',[],422);
$where=['eas.source_template_id=?','f.ajoute_localement=1'];$params=[$templateId];if($status!==''){$where[]='f.validation_statut=?';$params[]=$status;}
$s=$pdo->prepare("SELECT f.id,f.code,f.nom,f.type_unite,f.motif_ajout,f.validation_statut,f.actif,f.parent_id,p.nom parent_nom,f.created_at,f.review_comment,e.id etablissement_id,e.code etablissement_code,e.nom etablissement_nom,et.libelle type_etablissement_nom,CONCAT_WS(' ',u.prenom,u.nom) demandeur_nom FROM facultes f JOIN etablissements e ON e.id=f.etablissement_id JOIN etablissement_academic_settings eas ON eas.etablissement_id=e.id LEFT JOIN establishment_types et ON et.code=e.type_etablissement LEFT JOIN facultes p ON p.id=f.parent_id LEFT JOIN users u ON u.id=f.created_by_user_id WHERE ".implode(' AND ',$where)." ORDER BY FIELD(f.validation_statut,'EN_ATTENTE','VALIDE_LOCAL','INTEGRE_REFERENTIEL','REFUSE'),f.created_at DESC,f.id DESC");
$s->execute($params);jsonResponse(true,'',['items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
