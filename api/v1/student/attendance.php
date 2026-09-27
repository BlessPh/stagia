<?php

require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];

$assignmentUuid=
    trim($_GET['assignment_uuid']??'');


try{

    /* =====================================================
       REQUÊTE
    ====================================================== */

    $sql="
        SELECT

            att.*,

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

        FROM stage_attendances att

        INNER JOIN stage_assignments a
            ON a.id=att.assignment_id

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
            ON rot.id=att.rotation_id

        LEFT JOIN host_units hu
            ON hu.id=rot.host_unit_id

        WHERE se.student_id=?
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
        ORDER BY att.id DESC
    ";


    $stmt=$pdo->prepare($sql);

    $stmt->execute($params);


    $rows=$stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    /* =====================================================
       NORMALISATION
    ====================================================== */

    $items=[];


    $stats=[

        'total'=>0,

        'present'=>0,

        'late'=>0,

        'absent'=>0,

        'justified'=>0,

        'guard'=>0,

        'effective_presence'=>0,

        'attendance_rate'=>0
    ];


    foreach($rows as $row){

        /*
         * Compatibilité avec plusieurs noms
         * de colonnes possibles.
         */

        $uuid=
            $row['uuid']
            ??
            $row['public_id']
            ??
            null;


        $date=
            $row['date_presence']
            ??
            $row['attendance_date']
            ??
            $row['date']
            ??
            null;


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


        $arrival=
            $row['heure_arrivee']
            ??
            $row['arrival_time']
            ??
            $row['heure_entree']
            ??
            null;


        $departure=
            $row['heure_depart']
            ??
            $row['departure_time']
            ??
            $row['heure_sortie']
            ??
            null;


        $source=
            $row['source']
            ??
            $row['method']
            ??
            null;


        $observation=
            $row['observation']
            ??
            $row['notes']
            ??
            null;


        /* =================================================
           STATISTIQUES
        ================================================= */

        $stats['total']++;


        switch($status){

            case 'PRESENT':

                $stats['present']++;

                $stats['effective_presence']++;

                break;


            case 'RETARD':

                $stats['late']++;

                $stats['effective_presence']++;

                break;


            case 'ABSENT':

                $stats['absent']++;

                break;


            case 'JUSTIFIE':

                $stats['justified']++;

                break;


            case 'GARDE':

                $stats['guard']++;

                $stats['effective_presence']++;

                break;
        }


        /* =================================================
           ITEM MOBILE
        ================================================= */

        $items[]=[

            'uuid'=>$uuid,

            'date'=>$date,

            'status'=>$status,

            'arrival_time'=>$arrival,

            'departure_time'=>$departure,

            'source'=>$source,

            'observation'=>$observation,

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
       TAUX DE PRÉSENCE
    ====================================================== */

    if($stats['total']>0){

        $stats['attendance_rate']=
            round(
                (
                    $stats['effective_presence']
                    /
                    $stats['total']
                )
                *100,
                2
            );
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
        'Erreur présences : '.$e->getMessage(),
        [],
        500
    );
}
