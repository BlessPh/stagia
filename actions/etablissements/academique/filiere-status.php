<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0); $id=(int)($_POST['id']??0);
if(!$eid||!$id) jsonResponse(false,'Données invalides.',[],422);

$s=$pdo->prepare("SELECT actif FROM filieres WHERE id=? AND etablissement_id=? LIMIT 1");
$s->execute([$id,$eid]); $f=$s->fetch(PDO::FETCH_ASSOC);
if(!$f) jsonResponse(false,'Filière introuvable.',[],404);

$actif=(int)$f['actif']?0:1;

if(!$actif){
    $s=$pdo->prepare("SELECT
        (SELECT COUNT(*) FROM options_specialites WHERE filiere_id=? AND etablissement_id=? AND actif=1)+
        (SELECT COUNT(*) FROM promotions WHERE filiere_id=? AND etablissement_id=? AND actif=1)+
        (SELECT COUNT(*) FROM stage_referentials WHERE filiere_id=? AND etablissement_id=? AND statut='ACTIF')");
    $s->execute([$id,$eid,$id,$eid,$id,$eid]);

    if((int)$s->fetchColumn()>0)
        jsonResponse(false,'Impossible de désactiver cette filière : elle possède encore des options, promotions ou référentiels actifs.',[],409);
}

$pdo->prepare("UPDATE filieres SET actif=? WHERE id=? AND etablissement_id=?")->execute([$actif,$id,$eid]);
jsonResponse(true,$actif?'Filière activée.':'Filière désactivée.');
