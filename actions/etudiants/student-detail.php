<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
$etablissementId=currentEtablissementId($pdo);
$enrollmentId=(int)($_GET['id']??0);

if(!$etablissementId) jsonResponse(false,'Aucun établissement associé.',[],403);
if(!$enrollmentId) jsonResponse(false,'Étudiant invalide.',[],422);

/* Profil global + rattachement de CET établissement */
$stmt=$pdo->prepare("SELECT  se.id enrollment_id,
    se.student_id,
    se.matricule,
    se.email_institutionnel,
    se.date_inscription,
    se.statut,
    sp.user_id,
    sp.stagia_code,
    sp.nom,
    sp.postnom,
    sp.prenom,
    sp.sexe,
    sp.date_naissance,
    sp.email,
    sp.telephone,
    sp.photo
    FROM student_enrollments se
    JOIN student_profiles sp ON sp.id=se.student_id
    WHERE se.id=? AND se.etablissement_id=?
");
$stmt->execute([$enrollmentId,$etablissementId]);
$student=$stmt->fetch();

if(!$student) jsonResponse(false,'Étudiant introuvable dans votre établissement.',[],404);

/* Parcours académique uniquement dans cet établissement */
$stmt=$pdo->prepare("
    SELECT ae.id,ae.statut,ae.date_debut,ae.date_fin,
           aa.libelle annee,p.nom promotion,f.nom filiere,
           d.nom departement,fa.nom faculte
    FROM student_academic_enrollments ae
    JOIN annees_academiques aa ON aa.id=ae.annee_academique_id
    JOIN promotions p ON p.id=ae.promotion_id
    JOIN filieres f ON f.id=p.filiere_id
    LEFT JOIN departements d ON d.id=f.departement_id
    LEFT JOIN facultes fa ON fa.id=d.faculte_id
    WHERE ae.enrollment_id=?
      AND aa.etablissement_id=?
      AND p.etablissement_id=?
    ORDER BY aa.date_debut DESC,ae.id DESC
");
$stmt->execute([$enrollmentId,$etablissementId,$etablissementId]);

jsonResponse(true,'',[
    'student'=>$student,
    'parcours'=>$stmt->fetchAll()
]);