<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/supervisor-access.php';

requirePermission($pdo,'logbook.hosting.review');

$csrf=(string)($_POST['csrf']??'');

if(
    empty($_SESSION['csrf']) ||
    !$csrf ||
    !hash_equals((string)$_SESSION['csrf'],$csrf)
){
    jsonResponse(false,'Jeton CSRF invalide.',[],419);
}

$eid=(int)($_SESSION['etablissement_id']??0);
$userId=(int)($_SESSION['user_id']??0);

$id=(int)($_POST['id']??0);
$decision=strtoupper(trim((string)($_POST['decision']??'')));
$comment=trim((string)($_POST['commentaire']??''));

if(!$id || !in_array($decision,['VALIDE','REJETE'],true)){
    jsonResponse(false,'Décision invalide.',[],422);
}

if($decision==='REJETE' && $comment===''){
    jsonResponse(
        false,
        'Un commentaire est obligatoire pour rejeter un journal.',
        [],
        422
    );
}

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT
            e.id,
            e.rotation_id,
            e.student_id,
            e.statut,
            e.resume_activites
        FROM stage_logbook_entries e
        WHERE e.id=?
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id]);
    $entry=$s->fetch(PDO::FETCH_ASSOC);

    if(!$entry){
        throw new RuntimeException('Journal introuvable.');
    }

    requireSupervisorRotation(
        $pdo,
        (int)$entry['rotation_id'],
        $eid,
        $userId
    );

    if($entry['statut']!=='SOUMIS'){
        throw new RuntimeException(
            "Seul un journal SOUMIS peut être validé ou rejeté."
        );
    }

    if(trim((string)$entry['resume_activites'])===''){
        throw new RuntimeException(
            'Le journal ne contient aucun résumé.'
        );
    }

    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM stage_logbook_activities
        WHERE logbook_entry_id=?
    ");
    $s->execute([$id]);

    if((int)$s->fetchColumn()<=0){
        throw new RuntimeException(
            'Le journal ne contient aucune activité.'
        );
    }

    $s=$pdo->prepare("
        UPDATE stage_logbook_entries
        SET
            statut=?,
            commentaire_encadreur=?,
            validated_at=?
        WHERE id=?
    ");
    $s->execute([
        $decision,
        $comment?:null,
        $decision==='VALIDE'
            ?date('Y-m-d H:i:s')
            :null,
        $id
    ]);

    $s=$pdo->prepare("
        INSERT INTO stage_logbook_review_history(
            logbook_entry_id,
            previous_status,
            new_status,
            commentaire,
            reviewer_user_id,
            reviewed_at
        ) VALUES(
            ?,'SOUMIS',?,?,?,NOW()
        )
    ");
    $s->execute([
        $id,
        $decision,
        $comment?:null,
        $userId
    ]);

    $pdo->commit();

    jsonResponse(
        true,
        $decision==='VALIDE'
            ?'Journal validé.'
            :'Journal rejeté et renvoyé au stagiaire.'
    );

}catch(Throwable $e){
    if($pdo->inTransaction()){
        $pdo->rollBack();
    }

    jsonResponse(false,$e->getMessage(),[],422);
}
