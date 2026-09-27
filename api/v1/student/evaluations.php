<?php

require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];

$assignmentUuid=
    trim($_GET['assignment_uuid']??'');


try{

    /* =====================================================
       ÉVALUATIONS DE L'ÉTUDIANT
    ====================================================== */

    $sql="
        SELECT

            ev.*,

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

        FROM stage_evaluations ev

        INNER JOIN stage_assignments a
            ON a.id=ev.assignment_id

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
            ON rot.id=ev.rotation_id

        LEFT JOIN host_units hu
            ON hu.id=rot.host_unit_id

        WHERE se.student_id=?

          AND ev.statut IN('VALIDEE','FINALISEE')
    ";


    $params=[
        $studentId
    ];


    /* =====================================================
       FILTRE PAR STAGE
    ====================================================== */

    if($assignmentUuid!==''){

        $sql.="
            AND a.uuid=?
        ";

        $params[]=
            $assignmentUuid;
    }


    $sql.="
        ORDER BY ev.id DESC
    ";


    $stmt=$pdo->prepare($sql);

    $stmt->execute($params);


    $rows=$stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    /* =====================================================
       NORMALISATION MOBILE
    ====================================================== */

    $items=[];

    $stats=[
        'total'=>0,
        'continuous'=>0,
        'mid_rotation'=>0,
        'final_rotation'=>0,
        'validated'=>0,
        'finalized'=>0,
        'average'=>null
    ];

    $notes=[];

    $scoreStmt=$pdo->prepare("SELECT sc.code,sc.nom,sc.categorie,es.note,es.note_max,es.poids,es.commentaire FROM stage_evaluation_scores es LEFT JOIN stage_competencies sc ON sc.id=es.competency_id WHERE es.evaluation_id=? ORDER BY es.id");


    foreach($rows as $row){

        $scoreStmt->execute([(int)$row['id']]);
        $scores=$scoreStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($scores as &$score){
            $score['note']=(float)$score['note'];
            $score['note_max']=(float)$score['note_max'];
            $score['poids']=(float)$score['poids'];
        }
        unset($score);

        /* =================================================
           CHAMPS COMPATIBLES
        ================================================= */

        $uuid=
            $row['uuid']
            ??
            $row['public_id']
            ??
            null;


        $type=
            strtoupper(
                trim(
                    $row['type']
                    ??
                    $row['type_evaluation']
                    ??
                    ''
                )
            );


        $status=
            strtoupper(
                trim(
                    $row['statut']
                    ??
                    $row['status']
                    ??
                    ''
                )
            );


        $note=
            $row['note_finale']
            ??
            $row['note']
            ??
            $row['score_global']
            ??
            $row['pourcentage']
            ??
            null;


        $appreciation=
            $row['appreciation']
            ??
            $row['appreciation_finale']
            ??
            $row['observation']
            ??
            $row['commentaire']
            ??
            null;


        $validatedAt=
            $row['validated_at']
            ??
            $row['date_validation']
            ??
            $row['updated_at']
            ??
            null;


        /* =================================================
           CONVERSION NOTE
        ================================================= */

        if(
            $note!==null
            &&
            $note!==''
        ){

            $note=(float)$note;

            $notes[]=$note;

        }else{

            $note=null;
        }


        /* =================================================
           KPI
        ================================================= */

        $stats['total']++;

        if($status==='VALIDEE')$stats['validated']++;
        if($status==='FINALISEE')$stats['finalized']++;


        if($type==='CONTINUE'){

            $stats['continuous']++;

        }elseif(
            $type==='MI_ROTATION'
        ){

            $stats['mid_rotation']++;

        }elseif(
            $type==='FIN_ROTATION'
        ){

            $stats['final_rotation']++;
        }


        /* =================================================
           RÉPONSE ITEM
        ================================================= */

        $items[]=[

            'uuid'=>$uuid,

            'type'=>$type,

            'status'=>$status,

            'note'=>$note,

            'appreciation'=>$appreciation,

            'strengths'=>$row['points_forts']??null,

            'improvement_areas'=>$row['axes_amelioration']??null,

            'validated_at'=>$validatedAt,

            'finalized_at'=>$row['finalized_at']??null,

            'scores'=>$scores,

            'rotation_id'=>
                isset($row['rotation_id'])
                    ?(int)$row['rotation_id']
                    :null,

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
       MOYENNE DES ÉVALUATIONS DISPONIBLES
    ====================================================== */

    if(count($notes)>0){

        $stats['average']=
            round(
                array_sum($notes)
                /
                count($notes),
                2
            );
    }


    /* =====================================================
       DERNIÈRE ÉVALUATION FINALE
    ====================================================== */

    $finalEvaluation=null;


    foreach($items as $item){

        if(
            $item['type']==='FIN_ROTATION'
        ){

            $finalEvaluation=$item;

            break;
        }
    }


    /* =====================================================
       RÉPONSE
    ====================================================== */

    apiResponse(
        true,
        '',
        [

            'items'=>$items,

            'stats'=>$stats,

            'final_evaluation'=>
                $finalEvaluation

        ]
    );


}catch(Throwable $e){

    apiResponse(
        false,
        'Erreur évaluations : '.$e->getMessage(),
        [],
        500
    );
}
