<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);

    $id=(int)($_POST['id']??0);
    $type=$_POST['type_evaluation']??'';
    $note=$_POST['note']??'';
    $date=$_POST['date_evaluation']??null;
    $observation=trim($_POST['observation']??'');

    $types=['CONTROLE','TP','EXAMEN','RATTRAPAGE','AUTRE'];

    if(!$id || $note==='' || !in_array($type,$types,true))
        jsonResponse(false,'Données invalides.',[],422);

    /* Note + sécurité établissement */
    $stmt=$pdo->prepare("
        SELECT sn.id,sn.academic_enrollment_id,sn.matiere_id,
               m.note_max,ae.statut
        FROM student_notes sn
        JOIN student_academic_enrollments ae
          ON ae.id=sn.academic_enrollment_id
        JOIN student_enrollments se
          ON se.id=ae.enrollment_id
        JOIN matieres m
          ON m.id=sn.matiere_id
         AND m.etablissement_id=se.etablissement_id
        WHERE sn.id=? AND se.etablissement_id=?
    ");
    $stmt->execute([$id,$etablissementId]);
    $current=$stmt->fetch();

    if(!$current)
        jsonResponse(false,'Note introuvable.',[],404);

    if($current['statut']!=='EN_COURS')
        jsonResponse(false,'Cette année académique est déjà clôturée.',[],409);

    $note=(float)$note;
    $noteSur=(float)$current['note_max'];

    if($note<0 || $note>$noteSur)
        jsonResponse(false,"La note doit être comprise entre 0 et {$noteSur}.",[],422);

    /* Empêcher doublon de type */
    $stmt=$pdo->prepare("
        SELECT id
        FROM student_notes
        WHERE academic_enrollment_id=?
          AND matiere_id=?
          AND type_evaluation=?
          AND id<>?
    ");
    $stmt->execute([
        $current['academic_enrollment_id'],
        $current['matiere_id'],
        $type,
        $id
    ]);

    if($stmt->fetch())
        jsonResponse(false,'Une évaluation de ce type existe déjà.',[],409);

    $pdo->prepare("
        UPDATE student_notes
        SET type_evaluation=?,note=?,note_sur=?,
            date_evaluation=?,observation=?,
            recorded_by_user_id=?
        WHERE id=?
    ")->execute([
        $type,$note,$noteSur,$date?:null,
        $observation?:null,$_SESSION['user_id'],$id
    ]);

    jsonResponse(true,'Note modifiée avec succès.');

}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}