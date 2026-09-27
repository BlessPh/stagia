<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['STAGIAIRE']);
verifyAjaxCsrf();

try{
    $userId=(int)($_SESSION['user_id']??0);
    $id=(int)($_POST['id']??0);

    if(!$id)
        jsonResponse(false,'Journal invalide.',[],422);

    $s=$pdo->prepare("
        SELECT id
        FROM student_profiles
        WHERE user_id=?
        LIMIT 1
    ");
    $s->execute([$userId]);
    $studentId=(int)$s->fetchColumn();

    if(!$studentId)
        jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $s=$pdo->prepare("
        SELECT
            e.id,
            e.statut,
            e.resume_activites,
            (
                SELECT COUNT(*)
                FROM stage_logbook_activities a
                WHERE a.logbook_entry_id=e.id
            ) AS activities_count
        FROM stage_logbook_entries e
        WHERE e.id=?
          AND e.student_id=?
        LIMIT 1
    ");
    $s->execute([$id,$studentId]);
    $entry=$s->fetch(PDO::FETCH_ASSOC);

    if(!$entry)
        jsonResponse(false,'Journal introuvable.',[],404);

    if($entry['statut']!=='BROUILLON')
        jsonResponse(false,'Seul un brouillon peut être soumis.',[],422);

    if(trim((string)$entry['resume_activites'])==='')
        jsonResponse(false,'Complétez le résumé avant de soumettre.',[],422);

    if((int)$entry['activities_count']<1)
        jsonResponse(
            false,
            'Ajoutez au moins une activité avant de soumettre le journal.',
            [],
            422
        );

    $pdo->prepare("
        UPDATE stage_logbook_entries
        SET
            statut='SOUMIS',
            submitted_at=NOW(),
            commentaire_encadreur=NULL,
            validated_at=NULL,
            validated_by=NULL
        WHERE id=?
          AND student_id=?
          AND statut='BROUILLON'
    ")->execute([$id,$studentId]);

    jsonResponse(
        true,
        'Journal soumis à l’encadreur.',
        ['id'=>$id,'status'=>'SOUMIS']
    );

}catch(Throwable $e){
    error_log(
        '[STUDENT LOGBOOK SUBMIT] '.$e->getMessage().
        ' | '.$e->getFile().':'.$e->getLine()
    );

    jsonResponse(false,$e->getMessage(),[],422);
}
