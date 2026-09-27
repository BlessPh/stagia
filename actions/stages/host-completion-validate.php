<?php
/**
 * Endpoint AJAX de validation définitive d'une clôture de stage.
 * Il termine l'affectation et crée une attestation unique lorsque tous les prérequis sont satisfaits.
 */
if(session_status()!==PHP_SESSION_ACTIVE) session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-completion.php';

/* La décision définitive de clôture est réservée à l'administration d'accueil. */
requireAjaxRole(['ADMIN_ACCUEIL']);

$csrf=$_POST['csrf']??'';

if(
    empty($_SESSION['csrf']) ||
    !$csrf ||
    !hash_equals($_SESSION['csrf'],$csrf)
){
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);
}

/** Génère l'UUID de l'attestation créée à la validation du stage. */
function certificateUuid():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&15)|64);
    $d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

try{
    /* L'affectation ciblée est contrôlée dans le périmètre de l'établissement de la session. */
    $hostId=currentEtablissementId($pdo);
    $userId=(int)$_SESSION['user_id'];
    $assignmentId=(int)($_POST['assignment_id']??0);

    if(!$hostId || !$assignmentId)
        jsonResponse(false,'Stage invalide.',[],422);

    /* Clôture, fin d'affectation et attestation sont validées de manière atomique. */
    $pdo->beginTransaction();

    /* Verrouiller l'affectation */
    $stmt=$pdo->prepare("
        SELECT id
        FROM stage_assignments
        WHERE id=?
          AND host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$assignmentId,$hostId]);

    if(!$stmt->fetchColumn())
        throw new RuntimeException('Affectation introuvable.');


    /* Recalculer toutes les conditions côté serveur */
    $completion=syncStageCompletion($pdo,$assignmentId);
    $completionId=(int)$completion['id'];


    /* Déjà validé : action idempotente */
    /* Une nouvelle demande sur une clôture validée renvoie son résultat sans créer de doublon. */
    if($completion['statut']==='VALIDE'){

        $stmt=$pdo->prepare("
            SELECT id,reference
            FROM stage_certificates
            WHERE completion_id=?
              AND statut='GENERE'
            LIMIT 1
        ");
        $stmt->execute([$completionId]);
        $certificate=$stmt->fetch(PDO::FETCH_ASSOC);

        $pdo->commit();

        jsonResponse(true,'Ce stage est déjà validé.',[
            'completion_id'=>$completionId,
            'certificate'=>$certificate?:null
        ]);
    }


    /* Protection obligatoire */
    /* Seul l'état PRET, avec tous les prérequis satisfaits, autorise la validation. */
    if(
        !$completion['ready'] ||
        $completion['statut']!=='PRET'
    ){
        throw new RuntimeException(
            'Le stage ne satisfait plus toutes les conditions de clôture.'
        );
    }


    /* Verrouiller la clôture */
    $stmt=$pdo->prepare("
        SELECT id,statut,student_id,final_evaluation_id
        FROM stage_completions
        WHERE id=?
          AND host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$completionId,$hostId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$row)
        throw new RuntimeException('Clôture introuvable.');

    if($row['statut']!=='PRET')
        throw new RuntimeException('Cette clôture n’est plus disponible.');


   /* =====================================================
   APPRÉCIATION DE L'ÉVALUATION FINALE
===================================================== */
$appreciation=null;

if($row['final_evaluation_id']){

    $stmt=$pdo->prepare("
        SELECT appreciation
        FROM stage_evaluations
        WHERE id=?
          AND assignment_id=?
          AND type_evaluation='FIN_ROTATION'
          AND statut='FINALISEE'
        LIMIT 1
    ");

    $stmt->execute([
        (int)$row['final_evaluation_id'],
        $assignmentId
    ]);

    $appreciation=$stmt->fetchColumn()?:null;
}


    /* Validation définitive */
    $stmt=$pdo->prepare("
        UPDATE stage_completions
        SET statut='VALIDE',
            appreciation_finale=?,
            validated_at=NOW(),
            validated_by=?
        WHERE id=?
          AND statut='PRET'
    ");
    $stmt->execute([
        $appreciation,
        $userId,
        $completionId
    ]);

    if($stmt->rowCount()!==1)
        throw new RuntimeException('Impossible de valider le stage.');


    /* L'affectation est terminée */
    $stmt=$pdo->prepare("
        UPDATE stage_assignments
        SET statut='TERMINEE'
        WHERE id=?
          AND host_etablissement_id=?
          AND statut<>'TERMINEE'
    ");
    $stmt->execute([$assignmentId,$hostId]);


    /* Vérifier l'attestation existante */
    $stmt=$pdo->prepare("
        SELECT id,reference
        FROM stage_certificates
        WHERE completion_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$completionId]);
    $certificate=$stmt->fetch(PDO::FETCH_ASSOC);


    /* Génération unique de l'attestation */
    /* L'attestation est créée une seule fois et reçoit ensuite sa référence définitive. */
    if(!$certificate){

        $tempReference='TMP-'.strtoupper(bin2hex(random_bytes(8)));

        $stmt=$pdo->prepare("
            INSERT INTO stage_certificates(
                uuid,completion_id,student_id,
                host_etablissement_id,reference,
                type_document,statut,
                generated_by,generated_at
            )
            VALUES(
                ?,?,?,?,?,'ATTESTATION_STAGE',
                'GENERE',?,NOW()
            )
        ");

        $stmt->execute([
            certificateUuid(),
            $completionId,
            (int)$row['student_id'],
            $hostId,
            $tempReference,
            $userId
        ]);

        $certificateId=(int)$pdo->lastInsertId();

        /* Référence définitive */
        $reference=sprintf(
            'ATT-STG-%s-%08d',
            date('Y'),
            $certificateId
        );

        $stmt=$pdo->prepare("
            UPDATE stage_certificates
            SET reference=?
            WHERE id=?
        ");
        $stmt->execute([$reference,$certificateId]);

        $certificate=[
            'id'=>$certificateId,
            'reference'=>$reference
        ];
    }


    $pdo->commit();

    jsonResponse(
        true,
        'Stage validé définitivement. Attestation créée.',
        [
            'completion_id'=>$completionId,
            'statut'=>'VALIDE',
            'certificate'=>$certificate
        ]
    );

}catch(Throwable $e){

    if(isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(false,'Erreur : '.$e->getMessage(),[],422);
}
