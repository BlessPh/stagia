<?php
/**
 * Endpoint AJAX de création ou modification d'une rotation de stage dans une unité d'accueil.
 * Il protège la période, la capacité, l'absence de chevauchement et l'encadreur principal.
 */
if(session_status()!==PHP_SESSION_ACTIVE)
    session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* La planification détaillée des rotations relève de l'administration d'accueil. */
requireAjaxRole(['ADMIN_ACCUEIL']);

$csrf=$_POST['csrf']??'';

if(
    empty($_SESSION['csrf']) ||
    !$csrf ||
    !hash_equals($_SESSION['csrf'],$csrf)
){
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);
}

/** Génère un UUID v4 stocké avec une nouvelle rotation. */
function rotationUuid(){
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($d),4)
    );
}

try{
    /* Identifiants, période et données pédagogiques reçues depuis le formulaire. */
    $hostId=currentEtablissementId($pdo);

    if(!$hostId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    $id=(int)($_POST['id']??0);
    $assignmentId=(int)($_POST['assignment_id']??0);
    $unitId=(int)($_POST['host_unit_id']??0);
    $supervisorId=(int)($_POST['supervisor_id']??0);

    $dateDebut=trim($_POST['date_debut']??'');
    $dateFin=trim($_POST['date_fin']??'');
    $objectifs=trim($_POST['objectifs']??'');
    $observation=trim($_POST['observation']??'');

    if(!$assignmentId || !$unitId)
        jsonResponse(false,'Affectation ou service invalide.',[],422);

    if(!$dateDebut || !$dateFin)
        jsonResponse(false,'Les dates sont obligatoires.',[],422);

    if($dateDebut>$dateFin)
        jsonResponse(false,'Période de rotation invalide.',[],422);


    /* Affectation */
    $stmt=$pdo->prepare("
        SELECT id,date_debut,date_fin,statut
        FROM stage_assignments
        WHERE id=?
          AND host_etablissement_id=?
          AND statut IN('ACTIVE','PLANIFIEE')
        LIMIT 1
    ");

    $stmt->execute([$assignmentId,$hostId]);
    $assignment=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$assignment)
        jsonResponse(false,'Affectation introuvable ou inactive.',[],404);


    /* Rotation obligatoirement dans l'affectation */
    if($dateDebut<$assignment['date_debut'])
        jsonResponse(
            false,
            'La rotation commence avant l’affectation.',
            [],
            422
        );

    if(
        !empty($assignment['date_fin']) &&
        $dateFin>$assignment['date_fin']
    ){
        jsonResponse(
            false,
            'La rotation dépasse la période d’affectation.',
            [],
            422
        );
    }


    /* Service actif */
    $stmt=$pdo->prepare("
        SELECT id,code,nom,type,capacite
        FROM host_units
        WHERE id=?
          AND host_etablissement_id=?
          AND actif=1
        LIMIT 1
    ");

    $stmt->execute([$unitId,$hostId]);
    $unit=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$unit)
        jsonResponse(false,'Service / unité invalide.',[],422);


    /* Pas de chevauchement pour ce stagiaire */
    $stmt=$pdo->prepare("
        SELECT id
        FROM stage_rotations
        WHERE assignment_id=?
          AND id<>?
          AND statut<>'ANNULEE'
          AND date_debut<=?
          AND date_fin>=?
        LIMIT 1
    ");

    $stmt->execute([
        $assignmentId,
        $id,
        $dateFin,
        $dateDebut
    ]);

    if($stmt->fetchColumn())
        jsonResponse(
            false,
            'Cette période chevauche déjà une autre rotation.',
            [],
            409
        );


    /* Capacité du service */
    if($unit['capacite']!==null){

        $stmt=$pdo->prepare("
            SELECT COUNT(*)
            FROM stage_rotations
            WHERE host_unit_id=?
              AND host_etablissement_id=?
              AND id<>?
              AND statut<>'ANNULEE'
              AND date_debut<=?
              AND date_fin>=?
        ");

        $stmt->execute([
            $unitId,
            $hostId,
            $id,
            $dateFin,
            $dateDebut
        ]);

        if(
            (int)$stmt->fetchColumn()
            >=
            (int)$unit['capacite']
        ){
            jsonResponse(
                false,
                'Capacité du service / unité atteinte.',
                [],
                409
            );
        }
    }


    /* Encadreur optionnel */
    if($supervisorId){

        $stmt=$pdo->prepare("
            SELECT u.id
            FROM etablissement_users eu
            INNER JOIN users u ON u.id=eu.user_id
            WHERE eu.etablissement_id=?
              AND u.id=?
              AND u.actif=1
            LIMIT 1
        ");

        $stmt->execute([
            $hostId,
            $supervisorId
        ]);

        if(!$stmt->fetchColumn())
            jsonResponse(
                false,
                'Encadreur invalide.',
                [],
                422
            );
    }


    /* Le statut est dérivé automatiquement de la période par rapport à la date courante. */
    $today=date('Y-m-d');

    if($dateFin<$today)
        $statut='TERMINEE';
    elseif($dateDebut>$today)
        $statut='PLANIFIEE';
    else
        $statut='ACTIVE';


    /* Rotation et désignation de l'encadreur principal sont écrites dans une même transaction. */
    $pdo->beginTransaction();


    /* Modification */
    /* Une rotation existante est verrouillée ; une rotation terminée reste immuable. */
    if($id){

        $stmt=$pdo->prepare("
            SELECT id,sequence_no,statut
            FROM stage_rotations
            WHERE id=?
              AND assignment_id=?
              AND host_etablissement_id=?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $id,
            $assignmentId,
            $hostId
        ]);

        $existing=$stmt->fetch(PDO::FETCH_ASSOC);

        if(!$existing)
            throw new RuntimeException(
                'Rotation introuvable.'
            );

        if($existing['statut']==='TERMINEE')
            throw new RuntimeException(
                'Une rotation terminée ne peut plus être modifiée.'
            );


        $stmt=$pdo->prepare("
            UPDATE stage_rotations
            SET host_unit_id=?,
                date_debut=?,
                date_fin=?,
                statut=?,
                objectifs=?,
                observation=?,
                started_at=CASE
                    WHEN ?='ACTIVE'
                    THEN COALESCE(started_at,NOW())
                    ELSE started_at
                END,
                ended_at=CASE
                    WHEN ?='TERMINEE'
                    THEN COALESCE(ended_at,NOW())
                    ELSE NULL
                END
            WHERE id=?
              AND host_etablissement_id=?
        ");

        $stmt->execute([
            $unitId,
            $dateDebut,
            $dateFin,
            $statut,
            $objectifs?:null,
            $observation?:null,
            $statut,
            $statut,
            $id,
            $hostId
        ]);

        $rotationId=$id;

    }else{
        /* Pour une création, le numéro de séquence est calculé à l'intérieur de l'affectation. */

        /* Numéro de séquence */
        $stmt=$pdo->prepare("
            SELECT COALESCE(MAX(sequence_no),0)+1
            FROM stage_rotations
            WHERE assignment_id=?
        ");

        $stmt->execute([$assignmentId]);

        $sequence=(int)$stmt->fetchColumn();


        $stmt=$pdo->prepare("
            INSERT INTO stage_rotations(
                uuid,
                assignment_id,
                host_unit_id,
                host_etablissement_id,
                sequence_no,
                date_debut,
                date_fin,
                statut,
                objectifs,
                observation,
                created_by,
                started_at,
                ended_at
            )
            VALUES(
                ?,?,?,?,?,?,?,?,?,?,?,
                CASE WHEN ?='ACTIVE' THEN NOW() ELSE NULL END,
                CASE WHEN ?='TERMINEE' THEN NOW() ELSE NULL END
            )
        ");

        $stmt->execute([
            rotationUuid(),
            $assignmentId,
            $unitId,
            $hostId,
            $sequence,
            $dateDebut,
            $dateFin,
            $statut,
            $objectifs?:null,
            $observation?:null,
            (int)$_SESSION['user_id'],
            $statut,
            $statut
        ]);

        $rotationId=(int)$pdo->lastInsertId();
    }


    /* =====================================================
       ENCADREUR PRINCIPAL
    ====================================================== */
    $stmt=$pdo->prepare("
        UPDATE stage_rotation_supervisors
        SET actif=0,
            principal=0
        WHERE rotation_id=?
          AND role_supervision='ENCADREUR'
    ");

    $stmt->execute([$rotationId]);


    if($supervisorId){

        $stmt=$pdo->prepare("
            INSERT INTO stage_rotation_supervisors(
                rotation_id,
                user_id,
                role_supervision,
                principal,
                assigned_by,
                assigned_at,
                actif
            )
            VALUES(
                ?,?,
                'ENCADREUR',
                1,
                ?,
                NOW(),
                1
            )

            ON DUPLICATE KEY UPDATE
                principal=1,
                assigned_by=VALUES(assigned_by),
                assigned_at=NOW(),
                actif=1
        ");

        $stmt->execute([
            $rotationId,
            $supervisorId,
            (int)$_SESSION['user_id']
        ]);
    }


    /* Validation atomique de la rotation et de son encadreur, puis réponse JSON. */
    $pdo->commit();


    jsonResponse(
        true,
        'Rotation enregistrée avec succès.',
        [
            'rotation_id'=>$rotationId,
            'statut'=>$statut
        ]
    );


}catch(Throwable $e){

    if(isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(
        false,
        'Erreur : '.$e->getMessage(),
        [],
        500
    );
}
