<?php
/**
 * Endpoint AJAX de création d'une convention de stage en brouillon.
 * Un instantané des données du placement est conservé avec la convention créée.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/stage-convention.php';

verifyAjaxCsrf();

try{
    /* La création est exclusivement réservée à l'établissement de formation. */
    requireConventionAjax();
    if(!conventionCanAcademicManage())
        throw new RuntimeException("Seul l'établissement de formation peut créer la convention.");

    $placementId=(int)($_POST['placement_id']??0);
    $titre=trim((string)($_POST['titre']??''));
    $dateEmission=trim((string)($_POST['date_emission']??''));

    if(!$placementId)throw new RuntimeException('Placement obligatoire.');
    /* Le contrôle du placement et l'insertion de la convention forment une seule transaction. */
    $pdo->beginTransaction();
    $p=conventionLoadPlacement($pdo,$placementId,true);

    if((int)$p['university_id']!==conventionEtablissementId())
        throw new RuntimeException("Ce placement n'appartient pas à votre établissement de formation.");
    if(!in_array($p['placement_status'],['CONFIRME','TERMINE'],true))
        throw new RuntimeException('Le placement doit être confirmé.');

    $s=$pdo->prepare("
        SELECT COUNT(*) FROM stage_conventions
        WHERE placement_id=? AND statut IN('BROUILLON','A_SIGNER','SIGNEE')
    ");
    $s->execute([$placementId]);
    if((int)$s->fetchColumn()>0)
        throw new RuntimeException('Une convention active existe déjà pour ce placement.');

    $s=$pdo->prepare("SELECT COALESCE(MAX(version),0)+1 FROM stage_conventions WHERE placement_id=?");
    $s->execute([$placementId]);
    $version=(int)$s->fetchColumn();

    if($titre==='')$titre='Convention de stage — '.conventionStudentName($p);
    if(mb_strlen($titre)>200)throw new RuntimeException('Titre trop long.');

    /* Référence séquentielle et snapshot garantissent la traçabilité du document. */
    $reference=conventionReference($pdo);
    $snapshot=json_encode(conventionSnapshot($p),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $s=$pdo->prepare("
        INSERT INTO stage_conventions(
            uuid,placement_id,reference,titre,version,statut,
            date_emission,snapshot,created_by_user_id
        ) VALUES(?,?,?,?,?,'BROUILLON',?,?,?)
    ");
    $s->execute([
        conventionUuidV4(),$placementId,$reference,$titre,$version,
        $dateEmission!==''?$dateEmission:date('Y-m-d'),
        $snapshot,conventionUserId()?:null
    ]);

    $id=(int)$pdo->lastInsertId();
    $pdo->commit();
    jsonResponse(true,"Convention $reference créée en brouillon.",['id'=>$id]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
