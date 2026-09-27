<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);
    $id=(int)($_POST['id']??0);
    $statut=$_POST['statut']??'';

    $statuts=['EN_COURS','REUSSI','ECHEC','ABANDON','TERMINE'];
    if(!$id || !in_array($statut,$statuts,true))
        jsonResponse(false,'Statut invalide.',[],422);

    /* Sécurité multi-universités */
    $stmt=$pdo->prepare("
        SELECT ae.id
        FROM student_academic_enrollments ae
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        WHERE ae.id=? AND se.etablissement_id=?
    ");
    $stmt->execute([$id,$etablissementId]);
    if(!$stmt->fetch()) jsonResponse(false,'Parcours introuvable.',[],404);

    $pdo->prepare("UPDATE student_academic_enrollments SET statut=? WHERE id=?")
        ->execute([$statut,$id]);

    jsonResponse(true,'Statut du parcours mis à jour.');

}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}