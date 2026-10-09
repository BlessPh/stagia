<?php

require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-execution.php';
require_once __DIR__.'/../../../includes/student-logbook-api.php';

requireApiMethod('POST');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];
$userId=(int)$student['user_id'];


/* =========================================================
   JSON
========================================================= */

$input=json_decode(
    file_get_contents('php://input'),
    true
);

if(!is_array($input)){
    $input=$_POST;
}


/* =========================================================
   HELPERS
========================================================= */

function uuidV4Api():string{

    $data=random_bytes(16);

    $data[6]=chr(
        (ord($data[6])&0x0f)|0x40
    );

    $data[8]=chr(
        (ord($data[8])&0x3f)|0x80
    );

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(
            bin2hex($data),
            4
        )
    );
}


function tableColumnsApi(
    PDO $pdo,
    string $table
):array{

    $stmt=$pdo->query(
        "SHOW COLUMNS FROM `".$table."`"
    );

    $result=[];

    foreach(
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $column
    ){
        $result[$column['Field']]=$column;
    }

    return $result;
}


function firstColumnApi(
    array $columns,
    array $candidates
):?string{

    foreach($candidates as $candidate){

        if(isset($columns[$candidate])){
            return $candidate;
        }
    }

    return null;
}


/* =========================================================
   DONNÉES
========================================================= */

$assignmentUuid=trim(
    (string)($input['assignment_uuid']??'')
);

$entryUuid=trim(
    (string)($input['uuid']??'')
);

$date=trim(
    (string)($input['date']??'')
);

$summary=trim(
    (string)($input['summary']??$input['resume_activites']??'')
);

$learning=trim(
    (string)($input['learning']??$input['apprentissages']??'')
);

$difficulties=trim(
    (string)($input['difficulties']??$input['difficultes']??'')
);

$observation=trim(
    (string)($input['observation']??$input['observation_etudiant']??'')
);

$activities=$input['activities']??[];


if(
    $assignmentUuid==='' ||
    $date===''
){

    apiResponse(
        false,
        'Le stage et la date sont obligatoires.',
        [],
        422
    );
}


if(
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $date
    )
){

    apiResponse(
        false,
        'Format de date invalide.',
        [],
        422
    );
}


if(!is_array($activities)){

    apiResponse(
        false,
        'La liste des activités est invalide.',
        [],
        422
    );
}


try{

    $pdo->beginTransaction();


    /* =====================================================
       VÉRIFIER LE STAGE
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT
            a.id,
            a.uuid,
            a.statut,
            a.date_debut,
            a.date_fin,
            a.host_etablissement_id,
            ad.statut AS admission_status,
            sr.statut AS reservation_status

        FROM stage_assignments a

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

        WHERE a.uuid=?
          AND se.student_id=?

        LIMIT 1

        FOR UPDATE
    ");

    $stmt->execute([
        $assignmentUuid,
        $studentId
    ]);

    $assignment=$stmt->fetch(
        PDO::FETCH_ASSOC
    );


    if(!$assignment){

        throw new RuntimeException(
            'Stage introuvable ou non autorisé.'
        );
    }


    if($entryUuid===''&&!in_array($assignment['statut'],['PLANIFIEE','ACTIVE'],true)){

        throw new RuntimeException(
            'Le journal ne peut être modifié que pendant un stage actif.'
        );
    }


    /* =====================================================
       DATE DANS LA PÉRIODE DU STAGE
    ====================================================== */

    if(
        !empty($assignment['date_debut'])
        &&
        $date<$assignment['date_debut']
    ){

        throw new RuntimeException(
            'La date est antérieure au début du stage.'
        );
    }


    if(
        !empty($assignment['date_fin'])
        &&
        $date>$assignment['date_fin']
    ){

        throw new RuntimeException(
            'La date dépasse la fin du stage.'
        );
    }


    $assignmentId=
        (int)$assignment['id'];

    if($entryUuid===''&&$date!==stageExecutionDate()){
        throw new RuntimeException("Un nouveau journal ne peut etre cree que pour aujourd'hui.");
    }

    if($entryUuid===''&&($assignment['reservation_status']!=='CONFIRMEE'||!in_array($assignment['admission_status'],['ADMIS','EN_COURS'],true))){
        throw new RuntimeException("Ce stage n'est pas ouvert au suivi.");
    }

    if($entryUuid!==''&&$assignment['statut']==='ANNULEE'){
        throw new RuntimeException('Ce stage a ete annule.');
    }

    if($entryUuid===''){
        requireActiveRotation($pdo,$assignmentId,$date);
    }


    /* =====================================================
       COLONNES JOURNAL
    ====================================================== */

    $entryColumns=
        tableColumnsApi(
            $pdo,
            'stage_logbook_entries'
        );


    $dateColumn=
        firstColumnApi(
            $entryColumns,
            [
                'date_journal',
                'date_journee',
                'entry_date',
                'date_activite',
                'date_stage',
                'date'
            ]
        );


    if(!$dateColumn){

        throw new RuntimeException(
            'Colonne date du journal introuvable.'
        );
    }


    $summaryColumn=
        firstColumnApi(
            $entryColumns,
            [
                'resume_activites',
                'resume',
                'summary',
                'description',
                'observation',
                'observations'
            ]
        );


    $learningColumn=
        firstColumnApi(
            $entryColumns,
            [
                'apprentissages',
                'lecons_apprises',
                'learning',
                'apprentissage',
                'lecons'
            ]
        );


    $difficultyColumn=
        firstColumnApi(
            $entryColumns,
            [
                'difficultes',
                'difficulties'
            ]
        );

    $observationColumn=
        firstColumnApi(
            $entryColumns,
            ['observation_etudiant','student_observation']
        );


    /* =====================================================
       ROTATION CORRESPONDANTE
    ====================================================== */

    $rotationId=null;


    if(isset($entryColumns['rotation_id'])){

        $stmt=$pdo->prepare("
            SELECT id

            FROM stage_rotations

            WHERE assignment_id=?

              AND statut IN(
                  'ACTIVE',
                  'PLANIFIEE',
                  'TERMINEE'
              )

              AND (
                  date_debut IS NULL
                  OR date_debut<=?
              )

              AND (
                  date_fin IS NULL
                  OR date_fin>=?
              )

            ORDER BY
                CASE statut
                    WHEN 'ACTIVE' THEN 1
                    WHEN 'PLANIFIEE' THEN 2
                    ELSE 3
                END,
                id DESC

            LIMIT 1
        ");

        $stmt->execute([
            $assignmentId,
            $date,
            $date
        ]);

        $rotationId=
            $stmt->fetchColumn();

        $rotationId=
            $rotationId!==false
                ?(int)$rotationId
                :null;
    }

    if(isset($entryColumns['rotation_id'])&&!$rotationId){
        throw new RuntimeException('Aucune rotation ne correspond a cette date.');
    }


    /* =====================================================
       RECHERCHER JOURNAL EXISTANT
    ====================================================== */

    $entry=null;


    if($entryUuid!==''){

        $stmt=$pdo->prepare("
            SELECT *

            FROM stage_logbook_entries

            WHERE uuid=?
              AND assignment_id=?

            LIMIT 1

            FOR UPDATE
        ");

        $stmt->execute([
            $entryUuid,
            $assignmentId
        ]);

        $entry=$stmt->fetch(
            PDO::FETCH_ASSOC
        );


        if(!$entry){

            throw new RuntimeException(
                'Journal introuvable.'
            );
        }


    }else{

        /*
         * Empêcher deux journaux
         * pour le même stage et le même jour.
         */

        $stmt=$pdo->prepare("
            SELECT *

            FROM stage_logbook_entries

            WHERE assignment_id=?
              AND `$dateColumn`=?

            LIMIT 1

            FOR UPDATE
        ");

        $stmt->execute([
            $assignmentId,
            $date
        ]);

        $entry=$stmt->fetch(
            PDO::FETCH_ASSOC
        );
    }


    /* =====================================================
       VERROUILLAGE
    ====================================================== */

    if($entry){

        $status=
            strtoupper(
                $entry['statut']
                ??
                $entry['status']
                ??
                ''
            );


        if(
            !in_array(
                $status,
                [
                    'BROUILLON',
                    'REJETE',
                    'REJETEE'
                ],
                true
            )
        ){

            throw new RuntimeException(
                'Ce journal a déjà été soumis et ne peut plus être modifié.'
            );
        }
    }


    /* =====================================================
       CRÉER / MODIFIER JOURNAL
    ====================================================== */

    if($entry){

        $sets=[];
        $values=[];


        $sets[]="`$dateColumn`=?";
        $values[]=$date;


        if($summaryColumn){

            $sets[]="`$summaryColumn`=?";

            $values[]=
                $summary!==''?$summary:null;
        }


        if($learningColumn){

            $sets[]="`$learningColumn`=?";

            $values[]=
                $learning!==''?$learning:null;
        }


        if($difficultyColumn){

            $sets[]="`$difficultyColumn`=?";

            $values[]=
                $difficulties!==''?$difficulties:null;
        }

        if($observationColumn){
            $sets[]="`$observationColumn`=?";
            $values[]=$observation!==''?$observation:null;
        }


        if(isset($entryColumns['rotation_id'])){

            $sets[]="rotation_id=?";

            $values[]=$rotationId;
        }

        if(isset($entryColumns['statut'])){

            $sets[]="statut='BROUILLON'";
        }


        if(isset($entryColumns['submitted_at'])){

            $sets[]="submitted_at=NULL";
        }


        if(isset($entryColumns['updated_by'])){

            $sets[]="updated_by=?";

            $values[]=$userId;
        }


        if(isset($entryColumns['updated_at'])){

            $sets[]="updated_at=NOW()";
        }


        $values[]=$entry['id'];


        $stmt=$pdo->prepare("
            UPDATE stage_logbook_entries
            SET ".implode(',',$sets)."
            WHERE id=?
        ");

        $stmt->execute($values);


        $entryId=
            (int)$entry['id'];

        $entryUuid=
            $entry['uuid']??$entryUuid;


    }else{

        $fields=[];
        $placeholders=[];
        $values=[];


        if(isset($entryColumns['uuid'])){

            $entryUuid=uuidV4Api();

            $fields[]='uuid';
            $placeholders[]='?';
            $values[]=$entryUuid;
        }


        $fields[]='assignment_id';
        $placeholders[]='?';
        $values[]=$assignmentId;

        if(isset($entryColumns['host_etablissement_id'])){
            $fields[]='host_etablissement_id';
            $placeholders[]='?';
            $values[]=(int)$assignment['host_etablissement_id'];
        }


        if(isset($entryColumns['student_id'])){

            $fields[]='student_id';
            $placeholders[]='?';
            $values[]=$studentId;
        }


        if(isset($entryColumns['rotation_id'])){

            $fields[]='rotation_id';
            $placeholders[]='?';
            $values[]=$rotationId;
        }


        $fields[]=$dateColumn;
        $placeholders[]='?';
        $values[]=$date;


        if($summaryColumn){

            $fields[]=$summaryColumn;
            $placeholders[]='?';

            $values[]=
                $summary!==''?$summary:null;
        }


        if($learningColumn){

            $fields[]=$learningColumn;
            $placeholders[]='?';

            $values[]=
                $learning!==''?$learning:null;
        }


        if($difficultyColumn){

            $fields[]=$difficultyColumn;
            $placeholders[]='?';

            $values[]=
                $difficulties!==''?$difficulties:null;
        }

        if($observationColumn){
            $fields[]=$observationColumn;
            $placeholders[]='?';
            $values[]=$observation!==''?$observation:null;
        }


        if(isset($entryColumns['statut'])){

            $fields[]='statut';
            $placeholders[]='?';
            $values[]='BROUILLON';
        }


        if(isset($entryColumns['created_by'])){

            $fields[]='created_by';
            $placeholders[]='?';
            $values[]=$userId;
        }


        if(isset($entryColumns['created_at'])){

            $fields[]='created_at';
            $placeholders[]='NOW()';
        }


        if(isset($entryColumns['updated_at'])){

            $fields[]='updated_at';
            $placeholders[]='NOW()';
        }


        $stmt=$pdo->prepare("
            INSERT INTO stage_logbook_entries(
                `".implode('`,`',$fields)."`
            )
            VALUES(
                ".implode(',',$placeholders)."
            )
        ");

        $stmt->execute($values);


        $entryId=
            (int)$pdo->lastInsertId();
    }


    /* =====================================================
       ACTIVITÉS
    ====================================================== */

    $activityColumns=
        tableColumnsApi(
            $pdo,
            'stage_logbook_activities'
        );


    $foreignKey=
        firstColumnApi(
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


    /*
     * Remplacement des activités du brouillon.
     */

    $stmt=$pdo->prepare("
        DELETE FROM stage_logbook_activities
        WHERE `$foreignKey`=?
    ");

    $stmt->execute([
        $entryId
    ]);


    foreach($activities as $activity){

        if(!is_array($activity)){
            continue;
        }


        $label=trim(
            (string)(
                $activity['activity']
                ??''
            )
        );


        if($label===''){
            continue;
        }


        $activityField=
            firstColumnApi(
                $activityColumns,
                [
                    'intitule',
                    'activite',
                    'activity',
                    'libelle',
                    'titre',
                    'description'
                ]
            );


        if(!$activityField){

            throw new RuntimeException(
                'Colonne activité introuvable.'
            );
        }


        $fields=[
            $foreignKey,
            $activityField
        ];


        $placeholders=[
            '?',
            '?'
        ];


        $values=[
            $entryId,
            $label
        ];

        if(isset($activityColumns['description'])&&$activityField!=='description'){
            $fields[]='description';
            $placeholders[]='?';
            $values[]=trim((string)($activity['description']??''))?:null;
        }


        if(isset($activityColumns['uuid'])){

            $fields[]='uuid';
            $placeholders[]='?';
            $values[]=uuidV4Api();
        }


        $categoryField=
            firstColumnApi(
                $activityColumns,
                [
                    'categorie',
                    'category',
                    'type_activite',
                    'type'
                ]
            );


        if($categoryField){

            $fields[]=$categoryField;
            $placeholders[]='?';

            $values[]=
                trim(
                    (string)(
                        $activity['category']
                        ??''
                    )
                )?:null;
        }


        $levelField=
            firstColumnApi(
                $activityColumns,
                [
                    'niveau_implication',
                    'involvement_level',
                    'niveau'
                ]
            );


        if($levelField){

            $fields[]=$levelField;
            $placeholders[]='?';

            $values[]=
                trim(
                    (string)(
                        $activity['involvement_level']
                        ??''
                    )
                )?:null;
        }


        $quantityField=
            firstColumnApi(
                $activityColumns,
                [
                    'quantite',
                    'quantity',
                    'nombre'
                ]
            );


        if($quantityField){

            $fields[]=$quantityField;
            $placeholders[]='?';

            $values[]=
                max(
                    1,
                    (int)(
                        $activity['quantity']
                        ??1
                    )
                );
        }


        $observationField=
            firstColumnApi(
                $activityColumns,
                [
                    'observation',
                    'notes',
                    'commentaire'
                ]
            );


        if($observationField){

            $fields[]=$observationField;
            $placeholders[]='?';

            $values[]=
                trim(
                    (string)(
                        $activity['observation']
                        ??''
                    )
                )?:null;
        }


        if(isset($activityColumns['created_at'])){

            $fields[]='created_at';
            $placeholders[]='NOW()';
        }


        if(isset($activityColumns['updated_at'])){

            $fields[]='updated_at';
            $placeholders[]='NOW()';
        }


        $stmt=$pdo->prepare("
            INSERT INTO stage_logbook_activities(
                `".implode('`,`',$fields)."`
            )
            VALUES(
                ".implode(',',$placeholders)."
            )
        ");


        $stmt->execute($values);
    }


    /* =====================================================
       COMMIT
    ====================================================== */

    $entry=studentLogbookApiEntry($pdo,$studentId,$entryUuid);

    $pdo->commit();


    apiResponse(
        true,
        'Journal enregistré en brouillon.',
        ['entry'=>$entry]
    );


}catch(Throwable $e){

    if($pdo->inTransaction()){
        $pdo->rollBack();
    }


    apiResponse(
        false,
        'Erreur journal : '.$e->getMessage(),
        [],
        409
    );
}

if($summary===''){
    apiResponse(false,'Le resume des activites est obligatoire.',[],422);
}

$allowedActivityTypes=['OBSERVATION','PARTICIPATION','REALISATION','GARDE','CONSULTATION','AUTRE'];
$allowedInvolvement=['','OBSERVE','ASSISTE','REALISE_SUPERVISE','REALISE_AUTONOME'];
$normalizedActivities=[];
foreach($activities as $activity){
    if(!is_array($activity))apiResponse(false,'Une activite est invalide.',[],422);
    $category=strtoupper(trim((string)($activity['category']??$activity['type_activite']??'PARTICIPATION')));
    $label=trim((string)($activity['activity']??$activity['intitule']??''));
    $level=strtoupper(trim((string)($activity['involvement_level']??$activity['niveau_implication']??'')));
    if(!in_array($category,$allowedActivityTypes,true))apiResponse(false,"Type d'activite invalide.",[],422);
    if($label==='')apiResponse(false,"L'intitule de chaque activite est obligatoire.",[],422);
    if(!in_array($level,$allowedInvolvement,true))apiResponse(false,"Niveau d'implication invalide.",[],422);
    $normalizedActivities[]=[
        'category'=>$category,
        'activity'=>$label,
        'description'=>trim((string)($activity['description']??'')),
        'involvement_level'=>$level,
        'quantity'=>max(1,(int)($activity['quantity']??$activity['quantite']??1)),
        'observation'=>trim((string)($activity['observation']??''))
    ];
}
$activities=$normalizedActivities;
