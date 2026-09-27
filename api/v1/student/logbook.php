<?php

require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];

$assignmentUuid=
    trim($_GET['assignment_uuid']??'');


try{

    /* =====================================================
       HELPERS
    ====================================================== */

    $columns=function(
        PDO $pdo,
        string $table
    ){

        $stmt=$pdo->query(
            "SHOW COLUMNS FROM `".$table."`"
        );

        $result=[];

        foreach(
            $stmt->fetchAll(PDO::FETCH_ASSOC)
            as $column
        ){
            $result[]=$column['Field'];
        }

        return $result;
    };


    $pick=function(
        array $row,
        array $keys,
        $default=null
    ){

        foreach($keys as $key){

            if(
                array_key_exists($key,$row)
                &&
                $row[$key]!==null
                &&
                $row[$key]!==''
            ){
                return $row[$key];
            }
        }

        return $default;
    };


    /* =====================================================
       COLONNES
    ====================================================== */

    $entryColumns=
        $columns(
            $pdo,
            'stage_logbook_entries'
        );


    $activityColumns=
        $columns(
            $pdo,
            'stage_logbook_activities'
        );


    if(
        !in_array(
            'assignment_id',
            $entryColumns,
            true
        )
    ){

        throw new RuntimeException(
            'La table stage_logbook_entries ne contient pas assignment_id.'
        );
    }


    /* =====================================================
       JOURNAL DE L'ÉTUDIANT
    ====================================================== */

    $sql="
        SELECT

            le.*,

            a.uuid AS assignment_uuid,
            a.statut AS assignment_status,
            a.date_debut AS assignment_start,
            a.date_fin AS assignment_end,

            c.code AS campaign_code,
            c.titre AS campaign_title,

            h.code AS hospital_code,
            h.nom AS hospital_name,

            rot.uuid AS rotation_uuid,
            rot.sequence_no AS rotation_sequence,

            hu.code AS unit_code,
            hu.nom AS unit_name

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

        INNER JOIN stage_campaigns c
            ON c.id=app.campaign_id

        INNER JOIN etablissements h
            ON h.id=a.host_etablissement_id

        LEFT JOIN stage_rotations rot
            ON rot.id=le.rotation_id

        LEFT JOIN host_units hu
            ON hu.id=rot.host_unit_id

        WHERE se.student_id=?
    ";


    $params=[
        $studentId
    ];


    /* =====================================================
       FILTRE STAGE
    ====================================================== */

    if($assignmentUuid!==''){

        $sql.="
            AND a.uuid=?
        ";

        $params[]=
            $assignmentUuid;
    }


    $sql.="
        ORDER BY le.id DESC
    ";


    $stmt=$pdo->prepare($sql);

    $stmt->execute($params);


    $rows=
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /* =====================================================
       STATISTIQUES
    ====================================================== */

    $stats=[

        'total'=>0,

        'draft'=>0,

        'submitted'=>0,

        'validated'=>0,

        'rejected'=>0,

        'activities'=>0
    ];


    $items=[];


    /* =====================================================
       JOURNÉES
    ====================================================== */

    foreach($rows as $row){

        $entryId=(int)$row['id'];


        $uuid=
            $pick(
                $row,
                [
                    'uuid',
                    'public_id'
                ]
            );


        $date=
            $pick(
                $row,
                [
                    'date_journal',
                    'date_journee',
                    'entry_date',
                    'date_activite',
                    'date_stage',
                    'date'
                ]
            );


        $status=
            strtoupper(
                trim(
                    (string)$pick(
                        $row,
                        [
                            'statut',
                            'status'
                        ],
                        ''
                    )
                )
            );


        $summary=
            $pick(
                $row,
                [
                    'resume_activites',
                    'resume',
                    'summary',
                    'description',
                    'observations',
                    'observation'
                ]
            );


        $learning=
            $pick(
                $row,
                [
                    'apprentissages',
                    'lecons_apprises',
                    'learning',
                    'apprentissage',
                    'lecons'
                ]
            );


        $difficulty=
            $pick(
                $row,
                [
                    'difficultes',
                    'difficulties'
                ]
            );

        $studentObservation=
            $pick(
                $row,
                ['observation_etudiant','student_observation']
            );


        $submittedAt=
            $pick(
                $row,
                [
                    'submitted_at',
                    'date_soumission'
                ]
            );


        $validatedAt=
            $pick(
                $row,
                [
                    'validated_at',
                    'date_validation'
                ]
            );


        $validatorComment=
            $pick(
                $row,
                [
                    'commentaire_encadreur',
                    'validation_comment',
                    'commentaire_validation',
                    'validator_comment',
                    'commentaire'
                ]
            );


        /* =================================================
           ACTIVITÉS
        ================================================= */

        $activityForeignKey=null;


        foreach(
            [
                'logbook_entry_id',
                'entry_id',
                'logbook_id'
            ]
            as $candidate
        ){

            if(
                in_array(
                    $candidate,
                    $activityColumns,
                    true
                )
            ){

                $activityForeignKey=
                    $candidate;

                break;
            }
        }


        $activities=[];


        if($activityForeignKey){

            $activityStmt=
                $pdo->prepare(
                    "
                    SELECT *
                    FROM stage_logbook_activities
                    WHERE `".$activityForeignKey."`=?
                    ORDER BY id ASC
                    "
                );


            $activityStmt->execute([
                $entryId
            ]);


            $activityRows=
                $activityStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );


            foreach(
                $activityRows
                as $activity
            ){

                $quantity=
                    $pick(
                        $activity,
                        [
                            'quantite',
                            'quantity',
                            'nombre'
                        ]
                    );


                $activities[]=[

                    'uuid'=>
                        $pick(
                            $activity,
                            [
                                'uuid',
                                'public_id'
                            ]
                        ),

                    'activity'=>
                        $pick(
                            $activity,
                            [
                                'intitule',
                                'activite',
                                'activity',
                                'libelle',
                                'titre',
                                'description'
                            ]
                        ),

                    'description'=>
                        $pick($activity,['description']),

                    'category'=>
                        $pick(
                            $activity,
                            [
                                'categorie',
                                'category',
                                'type_activite',
                                'type'
                            ]
                        ),

                    'involvement_level'=>
                        $pick(
                            $activity,
                            [
                                'niveau_implication',
                                'involvement_level',
                                'niveau'
                            ]
                        ),

                    'quantity'=>
                        $quantity!==null
                            ?(int)$quantity
                            :null,

                    'observation'=>
                        $pick(
                            $activity,
                            [
                                'observation',
                                'notes',
                                'commentaire'
                            ]
                        )

                ];
            }
        }


        /* =================================================
           KPI
        ================================================= */

        $stats['total']++;

        $stats['activities']+=
            count($activities);


        if($status==='BROUILLON'){

            $stats['draft']++;

        }elseif(
            $status==='SOUMIS'
            ||
            $status==='SOUMISE'
        ){

            $stats['submitted']++;

        }elseif(
            $status==='VALIDE'
            ||
            $status==='VALIDEE'
        ){

            $stats['validated']++;

        }elseif(
            $status==='REJETE'
            ||
            $status==='REJETEE'
        ){

            $stats['rejected']++;
        }


        /* =================================================
           DROITS MOBILES
        ================================================= */

        $editable=in_array($status,['BROUILLON','REJETE','REJETEE'],true);


        $canSubmit=
            $status==='BROUILLON';


        /* =================================================
           ITEM
        ================================================= */

        $items[]=[

            'uuid'=>$uuid,

            'date'=>$date,

            'status'=>$status,

            'summary'=>$summary,

            'learning'=>$learning,

            'difficulties'=>$difficulty,

            'observation'=>$studentObservation,

            'submitted_at'=>$submittedAt,

            'validated_at'=>$validatedAt,

            'validator_comment'=>
                $validatorComment,

            'editable'=>$editable,

            'can_submit'=>$canSubmit,

            'activities'=>$activities,

            'activities_count'=>
                count($activities),

            'rotation'=>[
                'uuid'=>$row['rotation_uuid'],
                'sequence'=>(int)$row['rotation_sequence']
            ],


            'assignment'=>[

                'uuid'=>
                    $row['assignment_uuid'],

                'status'=>
                    $row['assignment_status'],

                'start_date'=>
                    $row['assignment_start'],

                'end_date'=>
                    $row['assignment_end']

            ],


            'campaign'=>[

                'code'=>
                    $row['campaign_code'],

                'title'=>
                    $row['campaign_title']

            ],


            'hospital'=>[

                'code'=>
                    $row['hospital_code'],

                'name'=>
                    $row['hospital_name']

            ],


            'unit'=>[

                'code'=>
                    $row['unit_code'],

                'name'=>
                    $row['unit_name']

            ]

        ];
    }


    /* =====================================================
       RÉPONSE
    ====================================================== */

    apiResponse(
        true,
        '',
        [

            'items'=>$items,

            'stats'=>$stats

        ]
    );


}catch(Throwable $e){

    apiResponse(
        false,
        'Erreur journal : '.$e->getMessage(),
        [],
        500
    );
}
