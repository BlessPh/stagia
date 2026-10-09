<?php

require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];

$assignmentUuid=
    trim($_GET['assignment_uuid']??'');

$rotationUuid=trim((string)($_GET['rotation_uuid']??''));
$allowedStatuses=['PRESENT','RETARD','ABSENT','JUSTIFIE','GARDE'];
$rawStatuses=$_GET['status']??$_GET['statuses']??[];
$statusFilter=is_array($rawStatuses)?$rawStatuses:explode(',',(string)$rawStatuses);
$statusFilter=array_values(array_unique(array_filter(array_map(
    static fn($status):string=>strtoupper(trim((string)$status)),
    $statusFilter
))));
$invalidStatuses=array_values(array_diff($statusFilter,$allowedStatuses));
if($invalidStatuses){
    apiResponse(false,'Filtre status invalide : '.implode(', ',$invalidStatuses).'.',[
        'allowed_values'=>$allowedStatuses
    ],422);
}

$dateFrom=trim((string)($_GET['date_from']??''));
$dateTo=trim((string)($_GET['date_to']??''));
$validDate=static function(string $value):bool{
    if($value==='')return true;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date!==false&&$date->format('Y-m-d')===$value;
};
if(!$validDate($dateFrom))apiResponse(false,'Filtre date_from invalide. Format attendu : YYYY-MM-DD.',[],422);
if(!$validDate($dateTo))apiResponse(false,'Filtre date_to invalide. Format attendu : YYYY-MM-DD.',[],422);
if($dateFrom!==''&&$dateTo!==''&&$dateFrom>$dateTo){
    apiResponse(false,'La date de début ne peut pas être postérieure à la date de fin.',[],422);
}

$page=max(1,(int)($_GET['page']??1));
$perPage=max(1,min(100,(int)($_GET['per_page']??20)));


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

            hu.id AS unit_id,
            hu.code AS unit_code,
            hu.nom AS unit_name,
            hu.type AS unit_type,

            validator.id AS validator_id,
            TRIM(CONCAT_WS(' ',validator.prenom,validator.nom,validator.postnom)) AS validator_name

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

        LEFT JOIN users validator
            ON validator.id=att.validated_by

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

    if($rotationUuid!==''){
        $sql.=" AND rot.uuid=?";
        $params[]=$rotationUuid;
    }

    if($statusFilter){
        $sql.=' AND att.statut IN('.implode(',',array_fill(0,count($statusFilter),'?')).')';
        array_push($params,...$statusFilter);
    }

    if($dateFrom!==''){
        $sql.=" AND att.date_presence>=?";
        $params[]=$dateFrom;
    }

    if($dateTo!==''){
        $sql.=" AND att.date_presence<=?";
        $params[]=$dateTo;
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

        'attendance_rate'=>0,

        'minutes_late'=>0,

        'total_duration_minutes'=>0,

        'validated'=>0
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

        $minutesLate=max(0,(int)($row['minutes_retard']??0));
        $durationMinutes=null;
        if($arrival&&$departure){
            $arrivalTime=DateTimeImmutable::createFromFormat('!H:i:s',(string)$arrival);
            $departureTime=DateTimeImmutable::createFromFormat('!H:i:s',(string)$departure);
            if($arrivalTime&&$departureTime){
                $durationMinutes=(int)(($departureTime->getTimestamp()-$arrivalTime->getTimestamp())/60);
                if($durationMinutes<0)$durationMinutes+=1440;
            }
        }


        /* =================================================
           STATISTIQUES
        ================================================= */

        $stats['total']++;
        $stats['minutes_late']+=$minutesLate;
        if($durationMinutes!==null)$stats['total_duration_minutes']+=$durationMinutes;
        if(!empty($row['validated_at']))$stats['validated']++;


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

            'minutes_late'=>$minutesLate,

            'duration_minutes'=>$durationMinutes,

            'justification'=>$row['justification']??null,

            'observation'=>$observation,

            'validated'=>!empty($row['validated_at']),

            'validated_at'=>$row['validated_at']??null,

            'validator'=>!empty($row['validator_id'])?[
                'user_id'=>(int)$row['validator_id'],
                'name'=>$row['validator_name']
            ]:null,

            'rotation'=>$row['rotation_uuid']!==null?[
                'uuid'=>$row['rotation_uuid'],
                'sequence'=>(int)$row['rotation_sequence']
            ]:null,


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


            'unit'=>$row['unit_id']!==null?[

                'id'=>(int)$row['unit_id'],

                'code'=>$row['unit_code'],

                'name'=>$row['unit_name'],

                'type'=>$row['unit_type']

            ]:null

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

    $total=count($items);
    $pages=max(1,(int)ceil($total/$perPage));
    if($page>$pages)$page=$pages;
    $offset=($page-1)*$perPage;
    $pageItems=array_slice($items,$offset,$perPage);

    apiResponse(
        true,
        '',
        [

            'items'=>$pageItems,

            'stats'=>$stats,

            'pagination'=>[
                'page'=>$page,
                'per_page'=>$perPage,
                'total'=>$total,
                'pages'=>$pages,
                'from'=>$total?$offset+1:0,
                'to'=>$total?$offset+count($pageItems):0
            ],

            'filters'=>[
                'assignment_uuid'=>$assignmentUuid?:null,
                'rotation_uuid'=>$rotationUuid?:null,
                'statuses'=>$statusFilter,
                'date_from'=>$dateFrom?:null,
                'date_to'=>$dateTo?:null
            ],

            'available_filters'=>[
                'statuses'=>$allowedStatuses,
                'per_page_max'=>100
            ]

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
