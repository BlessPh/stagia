<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');verifyAjaxCsrf();

$etabId=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);
$nom=trim((string)($_POST['nom']??''));
$motif=trim((string)($_POST['motif_ajout']??''));
$description=trim((string)($_POST['description']??''));

if(!$id||$nom==='')jsonResponse(false,'Données invalides.',[],422);

$s=$pdo->prepare("
    SELECT id,validation_statut
    FROM options_specialites
    WHERE id=? AND etablissement_id=? AND ajoute_localement=1
    LIMIT 1
");
$s->execute([$id,$etabId]);$row=$s->fetch(PDO::FETCH_ASSOC);
if(!$row)jsonResponse(false,'Seuls les ajouts locaux peuvent être modifiés ici.',[],403);
if(!in_array($row['validation_statut'],['EN_ATTENTE','VALIDE_LOCAL'],true))
    jsonResponse(false,"Cet élément n'est plus modifiable localement.",[],409);

$pdo->prepare("
    UPDATE options_specialites
    SET nom=?,description=?,motif_ajout=?,
        validation_statut='EN_ATTENTE',
        reviewed_by_user_id=NULL,reviewed_at=NULL,review_comment=NULL
    WHERE id=? AND etablissement_id=?
")->execute([$nom,$description?:null,$motif?:null,$id,$etabId]);

jsonResponse(true,'Ajout local mis à jour et renvoyé en validation.');
