<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);

    $academicId=(int)($_POST['academic_enrollment_id']??0);
    $matiereId=(int)($_POST['matiere_id']??0);
    $type=$_POST['type_evaluation']??'EXAMEN';
    $note=$_POST['note']??'';
    $date=$_POST['date_evaluation']??null;
    $observation=trim($_POST['observation']??'');

    $types=['CONTROLE','TP','EXAMEN','RATTRAPAGE','AUTRE'];

    if(!$academicId || !$matiereId || $note==='')
        jsonResponse(false,'Informations obligatoires manquantes.',[],422);

    if(!in_array($type,$types,true))
        jsonResponse(false,'Type d’évaluation invalide.',[],422);

    /* Parcours + établissement */
    $stmt=$pdo->prepare("
        SELECT ae.id,ae.promotion_id,ae.statut
        FROM student_academic_enrollments ae
        JOIN student_enrollments se
          ON se.id=ae.enrollment_id
        WHERE ae.id=? AND se.etablissement_id=?
    ");
    $stmt->execute([$academicId,$etablissementId]);
    $academic=$stmt->fetch();

    if(!$academic)
        jsonResponse(false,'Parcours académique invalide.',[],404);

    if($academic['statut']!=='EN_COURS')
        jsonResponse(false,'Cette année académique est déjà clôturée.',[],409);

    /* Matière de cette promotion */
    $stmt=$pdo->prepare("
        SELECT id,note_max
        FROM matieres
        WHERE id=?
          AND etablissement_id=?
          AND promotion_id=?
          AND actif=1
    ");
    $stmt->execute([
        $matiereId,
        $etablissementId,
        $academic['promotion_id']
    ]);
    $matiere=$stmt->fetch();

    if(!$matiere)
        jsonResponse(false,'Matière invalide pour cette promotion.',[],422);

    $note=(float)$note;
    $noteSur=(float)$matiere['note_max'];

    if($note<0 || $note>$noteSur)
        jsonResponse(false,"La note doit être comprise entre 0 et {$noteSur}.",[],422);

    /* Une seule note du même type par matière */
    $stmt=$pdo->prepare("
        SELECT id
        FROM student_notes
        WHERE academic_enrollment_id=?
          AND matiere_id=?
          AND type_evaluation=?
    ");
    $stmt->execute([$academicId,$matiereId,$type]);

    if($stmt->fetch())
        jsonResponse(false,'Cette évaluation existe déjà pour cette matière.',[],409);

    $stmt=$pdo->prepare("
        INSERT INTO student_notes(
            academic_enrollment_id,matiere_id,
            recorded_by_user_id,type_evaluation,
            note,note_sur,date_evaluation,observation
        )
        VALUES(?,?,?,?,?,?,?,?)
    ");

    $stmt->execute([
        $academicId,
        $matiereId,
        $_SESSION['user_id'],
        $type,
        $note,
        $noteSur,
        $date?:null,
        $observation?:null
    ]);

    jsonResponse(true,'Note enregistrée avec succès.',[
        'id'=>(int)$pdo->lastInsertId()
    ]);

}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}