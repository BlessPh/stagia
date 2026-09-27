<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);
$actif=(int)(($_POST['actif']??'0')==='1');

if(!$eid||!$id)jsonResponse(false,'Promotion invalide.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT p.id,p.actif,p.filiere_id,p.annee_academique_id,p.academic_level_id
        FROM promotions p
        WHERE p.id=? AND p.etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id,$eid]);
    $p=$s->fetch(PDO::FETCH_ASSOC);

    if(!$p)throw new RuntimeException('Promotion introuvable.');

    if(!$actif){
        $s=$pdo->prepare("
            SELECT COUNT(*)
            FROM student_academic_enrollments
            WHERE promotion_id=?
              AND statut='EN_COURS'
        ");
        $s->execute([$id]);
        $students=(int)$s->fetchColumn();

        if($students>0)
            throw new RuntimeException("Impossible de désactiver : $students étudiant(s) sont encore inscrits dans cette promotion.");

        $s=$pdo->prepare("
            SELECT COUNT(*)
            FROM stage_campaign_promotions scp
            JOIN stage_campaigns sc ON sc.id=scp.campaign_id
            WHERE scp.promotion_id=?
              AND sc.statut IN('OUVERTE','ACTIVE','EN_COURS')
        ");
        $s->execute([$id]);
        $campaigns=(int)$s->fetchColumn();

        if($campaigns>0)
            throw new RuntimeException("Impossible de désactiver : cette promotion est encore utilisée dans une campagne de stage active.");
    }else{
        if(!(int)$p['annee_academique_id'] || !(int)$p['academic_level_id'])
            throw new RuntimeException("Cette ancienne promotion doit d'abord être normalisée avec une année académique et un niveau.");
    }

    $pdo->prepare("
        UPDATE promotions
        SET actif=?
        WHERE id=? AND etablissement_id=?
    ")->execute([$actif,$id,$eid]);

    $pdo->commit();

    jsonResponse(true,$actif?'Promotion activée.':'Promotion désactivée.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
