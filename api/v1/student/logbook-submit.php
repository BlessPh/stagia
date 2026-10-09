<?php

require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-logbook-api.php';

requireApiMethod('POST');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];
$userId=(int)$student['user_id'];


/* =========================================================
   JSON / POST
========================================================= */

$input=json_decode(
    file_get_contents('php://input'),
    true
);

if(!is_array($input)){
    $input=$_POST;
}


$uuid=trim(
    (string)($_GET['uuid']??$input['uuid']??'')
);


if($uuid===''){

    apiResponse(
        false,
        'Le journal est obligatoire.',
        [],
        422
    );
}


/* =========================================================
   HELPERS
========================================================= */

function logbookSubmitColumns(
    PDO $pdo,
    string $table
):array{

    $stmt=$pdo->query(
        "SHOW COLUMNS FROM `".$table."`"
    );

    $columns=[];

    foreach(
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $column
    ){
        $columns[$column['Field']]=$column;
    }

    return $columns;
}


function logbookSubmitFirstColumn(
    array $columns,
    array $names
):?string{

    foreach($names as $name){

        if(isset($columns[$name])){
            return $name;
        }
    }

    return null;
}


try{

    $pdo->beginTransaction();


    /* =====================================================
       COLONNES
    ====================================================== */

    $entryColumns=
        logbookSubmitColumns(
            $pdo,
            'stage_logbook_entries'
        );


    $activityColumns=
        logbookSubmitColumns(
            $pdo,
            'stage_logbook_activities'
        );


    $statusColumn=
        logbookSubmitFirstColumn(
            $entryColumns,
            [
                'statut',
                'status'
            ]
        );


    if(!$statusColumn){

        throw new RuntimeException(
            'Colonne statut du journal introuvable.'
        );
    }


    /* =====================================================
       JOURNAL + PROPRIÉTAIRE
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT

            le.*,

            a.uuid AS assignment_uuid,
            a.statut AS assignment_status,

            comp.statut AS completion_status

        FROM stage_logbook_entries le

        INNER JOIN stage_assignments a
            ON a.id=le.assignment_id

        INNER JOIN stage_admissions ad
            ON ad.id=a.admission_id

        INNER JOIN stage_reservations sr
            ON sr.id=ad.reservation_id

        INNER JOIN stage_applications app
            ON app.id=sr.application_id

        INNER JOIN student_academic_enrollments ae
            ON ae.id=app.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=ae.enrollment_id

        LEFT JOIN stage_completions comp
            ON comp.assignment_id=a.id

        WHERE le.uuid=?
          AND se.student_id=?

        LIMIT 1

        FOR UPDATE
    ");


    $stmt->execute([
        $uuid,
        $studentId
    ]);


    $entry=$stmt->fetch(
        PDO::FETCH_ASSOC
    );


    if(!$entry){

        throw new RuntimeException(
            'Journal introuvable ou non autorisé.'
        );
    }


    /* =====================================================
       STAGE NON ANNULÉ / NON CLÔTURÉ
    ====================================================== */

    if(
        $entry['assignment_status']
        ===
        'ANNULEE'
    ){

        throw new RuntimeException(
            'Ce stage a été annulé.'
        );
    }


    if(
        $entry['completion_status']
        ===
        'VALIDE'
    ){

        throw new RuntimeException(
            'Ce stage est déjà définitivement clôturé.'
        );
    }


    /* =====================================================
       STATUT DU JOURNAL
    ====================================================== */

    $status=strtoupper(
        trim(
            (string)$entry[$statusColumn]
        )
    );


    if(
        $status==='SOUMIS'
        ||
        $status==='SOUMISE'
    ){

        /*
         * Idempotence simple :
         * si Flutter réessaie après une coupure réseau,
         * on renvoie un succès au lieu de créer une erreur.
         */

        $pdo->commit();

        $publicEntry=studentLogbookApiEntry($pdo,$studentId,$uuid);


        apiResponse(
            true,
            'Journal déjà soumis.',
            ['entry'=>$publicEntry]
        );
    }


    if(
        $status==='VALIDE'
        ||
        $status==='VALIDEE'
    ){

        $pdo->commit();

        $publicEntry=studentLogbookApiEntry($pdo,$studentId,$uuid);


        apiResponse(
            true,
            'Journal déjà validé.',
            ['entry'=>$publicEntry]
        );
    }


    if($status!=='BROUILLON'){

        throw new RuntimeException(
            'Seul un journal en brouillon peut être soumis.'
        );
    }

    $summary='';
    foreach(['resume_activites','resume','summary','description','observation'] as $column){
        if(array_key_exists($column,$entry)&&trim((string)$entry[$column])!==''){
            $summary=trim((string)$entry[$column]);
            break;
        }
    }
    if($summary===''){
        throw new RuntimeException('Completez le resume avant de soumettre.');
    }


    /* =====================================================
       ACTIVITÉS
    ====================================================== */

    $foreignKey=
        logbookSubmitFirstColumn(
            $activityColumns,
            [
                'logbook_entry_id',
                'entry_id',
                'logbook_id'
            ]
        );


    if(!$foreignKey){

        throw new RuntimeException(
            'Relation avec les activités du journal introuvable.'
        );
    }


    $stmt=$pdo->prepare("
        SELECT COUNT(*)

        FROM stage_logbook_activities

        WHERE `$foreignKey`=?
    ");


    $stmt->execute([
        $entry['id']
    ]);


    $activitiesCount=
        (int)$stmt->fetchColumn();


    if($activitiesCount<=0){

        throw new RuntimeException(
            'Ajoutez au moins une activité avant de soumettre le journal.'
        );
    }


    /* =====================================================
       CONSTRUIRE UPDATE
    ====================================================== */

    $sets=[];
    $values=[];


    $sets[]="`$statusColumn`=?";
    $values[]='SOUMIS';


    if(isset($entryColumns['submitted_at'])){

        $sets[]='submitted_at=NOW()';
    }


    if(isset($entryColumns['submitted_by'])){

        $sets[]='submitted_by=?';
        $values[]=$userId;
    }


    /*
     * Effacer une ancienne validation éventuelle
     * lorsqu'un journal rejeté a été corrigé puis
     * remis en brouillon.
     */

    if(isset($entryColumns['validated_at'])){

        $sets[]='validated_at=NULL';
    }


    if(isset($entryColumns['validated_by'])){

        $sets[]='validated_by=NULL';
    }


    if(isset($entryColumns['updated_by'])){

        $sets[]='updated_by=?';
        $values[]=$userId;
    }


    if(isset($entryColumns['updated_at'])){

        $sets[]='updated_at=NOW()';
    }


    $values[]=(int)$entry['id'];


    /* =====================================================
       SOUMISSION
    ====================================================== */

    $stmt=$pdo->prepare("
        UPDATE stage_logbook_entries

        SET
            ".implode(',',$sets)."

        WHERE id=?
    ");


    $stmt->execute($values);


    /* =====================================================
       COMMIT
    ====================================================== */

    $publicEntry=studentLogbookApiEntry($pdo,$studentId,$uuid);

    $pdo->commit();


    apiResponse(
        true,
        'Journal soumis pour validation.',
        ['entry'=>$publicEntry]
    );


}catch(Throwable $e){

    if($pdo->inTransaction()){
        $pdo->rollBack();
    }


    apiResponse(
        false,
        'Erreur soumission : '.$e->getMessage(),
        [],
        409
    );
}
