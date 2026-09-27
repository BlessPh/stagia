<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);
    $enrollmentId=(int)($_POST['enrollment_id']??0);
    $anneeId=(int)($_POST['annee_academique_id']??0);
    $promotionId=(int)($_POST['promotion_id']??0);

    if(!$etablissementId || !$enrollmentId || !$anneeId || !$promotionId)
        jsonResponse(false,'Données obligatoires manquantes.',[],422);

    /* Rattachement appartenant à l'établissement */
    $stmt=$pdo->prepare("SELECT id,statut FROM student_enrollments WHERE id=? AND etablissement_id=?");
    $stmt->execute([$enrollmentId,$etablissementId]);
    $enrollment=$stmt->fetch();

    if(!$enrollment) jsonResponse(false,'Étudiant introuvable dans votre établissement.',[],404);
    if($enrollment['statut']!=='ACTIF') jsonResponse(false,'Le rattachement étudiant n’est pas actif.',[],422);

    /* Année académique */
    $stmt=$pdo->prepare("SELECT id,date_debut,date_fin FROM annees_academiques WHERE id=? AND etablissement_id=?");
    $stmt->execute([$anneeId,$etablissementId]);
    $annee=$stmt->fetch();
    if(!$annee) jsonResponse(false,'Année académique invalide.',[],422);

    /* Promotion */
    $stmt=$pdo->prepare("SELECT id FROM promotions WHERE id=? AND etablissement_id=? AND actif=1");
    $stmt->execute([$promotionId,$etablissementId]);
    if(!$stmt->fetch()) jsonResponse(false,'Promotion invalide ou inactive.',[],422);

    /* Une seule inscription pour une même année */
    $stmt=$pdo->prepare("SELECT id FROM student_academic_enrollments WHERE enrollment_id=? AND annee_academique_id=?");
    $stmt->execute([$enrollmentId,$anneeId]);
    if($stmt->fetch()) jsonResponse(false,'Cet étudiant possède déjà un parcours pour cette année académique.',[],409);

    /* Une seule année EN_COURS à la fois */
    $stmt=$pdo->prepare("SELECT id FROM student_academic_enrollments WHERE enrollment_id=? AND statut='EN_COURS' LIMIT 1");
    $stmt->execute([$enrollmentId]);
    if($stmt->fetch()) jsonResponse(false,'Terminez d’abord l’année académique actuellement en cours.',[],409);

    /* Nouvelle étape du parcours */
    $stmt=$pdo->prepare("INSERT INTO student_academic_enrollments
        (enrollment_id,annee_academique_id,promotion_id,statut,date_debut,date_fin)
        VALUES(?,?,?,'EN_COURS',?,?)");
    $stmt->execute([$enrollmentId,$anneeId,$promotionId,$annee['date_debut'],$annee['date_fin']]);

    jsonResponse(true,'Nouvelle étape du parcours ajoutée avec succès.',['id'=>(int)$pdo->lastInsertId()]);

}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}