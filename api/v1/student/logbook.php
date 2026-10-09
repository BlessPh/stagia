<?php

require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-logbook-api.php';

requireApiMethod('GET');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];

$assignmentUuid=
    trim($_GET['assignment_uuid']??'');

$rotationUuid=trim((string)($_GET['rotation_uuid']??''));
$rawStatuses=$_GET['status']??$_GET['statuses']??[];
$statusFilter=is_array($rawStatuses)?$rawStatuses:explode(',',(string)$rawStatuses);
$statusFilter=array_values(array_unique(array_filter(array_map(
    static function($status):string{
        $status=strtoupper(trim((string)$status));
        return match($status){'SOUMIS'=>'SOUMISE','VALIDE'=>'VALIDEE','REJETE'=>'REJETEE',default=>$status};
    },$statusFilter
))));
$allowedStatuses=['BROUILLON','SOUMISE','VALIDEE','REJETEE'];
$invalidStatuses=array_values(array_diff($statusFilter,$allowedStatuses));
if($invalidStatuses)apiResponse(false,'Filtre status invalide.', ['allowed_values'=>$allowedStatuses],422);
$dateFrom=trim((string)($_GET['date_from']??''));$dateTo=trim((string)($_GET['date_to']??''));
foreach(['date_from'=>$dateFrom,'date_to'=>$dateTo] as $name=>$value){
    if($value==='')continue;$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$parsed||$parsed->format('Y-m-d')!==$value)apiResponse(false,'Filtre '.$name.' invalide.',[],422);
}
if($dateFrom!==''&&$dateTo!==''&&$dateFrom>$dateTo)apiResponse(false,'date_from doit précéder date_to.',[],422);
$editableFilter=trim((string)($_GET['editable']??''));
if($editableFilter!==''&&!in_array(strtolower($editableFilter),['1','0','true','false'],true))apiResponse(false,'Filtre editable invalide.',[],422);
$editableFilter=$editableFilter===''?null:in_array(strtolower($editableFilter),['1','true'],true);
$page=max(1,(int)($_GET['page']??1));$perPage=max(1,min(100,(int)($_GET['per_page']??20)));


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
            studentLogbookApiStatus(
                    (string)$pick(
                        $row,
                        [
                            'statut',
                            'status'
                        ],
                        ''
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

    $items=array_values(array_filter($items,static function(array $item)use(
        $rotationUuid,$statusFilter,$dateFrom,$dateTo,$editableFilter
    ):bool{
        if($rotationUuid!==''&&($item['rotation']['uuid']??null)!==$rotationUuid)return false;
        if($statusFilter&&!in_array($item['status'],$statusFilter,true))return false;
        if($dateFrom!==''&&$item['date']<$dateFrom)return false;
        if($dateTo!==''&&$item['date']>$dateTo)return false;
        if($editableFilter!==null&&(bool)$item['editable']!==$editableFilter)return false;
        return true;
    }));
    $stats=['total'=>count($items),'draft'=>0,'submitted'=>0,'validated'=>0,'rejected'=>0,'activities'=>0];
    foreach($items as $item){
        $stats['activities']+=(int)$item['activities_count'];
        $bucket=['BROUILLON'=>'draft','SOUMISE'=>'submitted','VALIDEE'=>'validated','REJETEE'=>'rejected'][$item['status']]??null;
        if($bucket)$stats[$bucket]++;
    }
    $total=count($items);$pages=max(1,(int)ceil($total/$perPage));if($page>$pages)$page=$pages;
    $offset=($page-1)*$perPage;$pageItems=array_slice($items,$offset,$perPage);

    apiResponse(
        true,
        '',
        [

            'items'=>$pageItems,

            'stats'=>$stats,

            'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,
                'from'=>$total?$offset+1:0,'to'=>$total?$offset+count($pageItems):0],

            'filters'=>['assignment_uuid'=>$assignmentUuid?:null,'rotation_uuid'=>$rotationUuid?:null,
                'statuses'=>$statusFilter,'date_from'=>$dateFrom?:null,'date_to'=>$dateTo?:null,
                'editable'=>$editableFilter],

            'available_filters'=>['statuses'=>$allowedStatuses,'editable'=>[true,false],'per_page_max'=>100]

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
