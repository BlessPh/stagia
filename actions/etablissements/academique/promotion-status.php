<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0);
$id=(int)($_POST['id']??0);

if(!$eid||!$id) jsonResponse(false,'Données invalides.',[],422);

$s=$pdo->prepare("SELECT actif FROM promotions WHERE id=? AND etablissement_id=? LIMIT 1");
$s->execute([$id,$eid]); $p=$s->fetch(PDO::FETCH_ASSOC);

if(!$p) jsonResponse(false,'Promotion introuvable.',[],404);

$actif=(int)$p['actif']?0:1;

if(!$actif){
    $s=$pdo->prepare("SELECT
        (SELECT COUNT(*) FROM student_academic_enrollments
            WHERE promotion_id=? AND statut='EN_COURS')+
        (SELECT COUNT(*) FROM promotion_stage_configs
            WHERE promotion_id=? AND etablissement_id=? AND actif=1)+
        (SELECT COUNT(*) FROM stage_campaign_promotions
            WHERE promotion_id=?)");
    $s->execute([$id,$id,$eid,$id]);

    if((int)$s->fetchColumn()>0)
        jsonResponse(false,'Impossible de désactiver cette promotion : elle est encore utilisée par des étudiants, une configuration de stage ou une campagne.',[],409);
}

$pdo->prepare("UPDATE promotions SET actif=? WHERE id=? AND etablissement_id=?")
    ->execute([$actif,$id,$eid]);

jsonResponse(true,$actif?'Promotion activée.':'Promotion désactivée.');
