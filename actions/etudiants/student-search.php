<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) jsonResponse(false,'Aucun établissement associé.',[],403);

$q=trim($_GET['q']??'');
if(strlen($q)<2) jsonResponse(true,'',['items'=>[]]);

/* Recherche globale : code STAGIA, email, téléphone ou identité */
$like="%{$q}%";
$stmt=$pdo->prepare("
    SELECT sp.id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
           sp.sexe,sp.date_naissance,sp.email,sp.telephone,
           EXISTS(
               SELECT 1 FROM student_enrollments se
               WHERE se.student_id=sp.id AND se.etablissement_id=?
           ) deja_rattache
    FROM student_profiles sp
    WHERE sp.statut<>'ARCHIVE' AND (
        sp.stagia_code LIKE ? OR sp.email LIKE ? OR sp.telephone LIKE ?
        OR sp.nom LIKE ? OR sp.postnom LIKE ? OR sp.prenom LIKE ?
    )
    ORDER BY sp.nom,sp.postnom,sp.prenom
    LIMIT 10
");
$stmt->execute([$etablissementId,$like,$like,$like,$like,$like,$like]);

jsonResponse(true,'',['items'=>$stmt->fetchAll()]);