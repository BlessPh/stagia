<?php

require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../config/config.php';

requireApiMethod('GET');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];

$allowedTypes=['ATTESTATION_STAGE','CERTIFICAT_STAGE'];
$allowedStatuses=['GENERE','ANNULE'];
$parseList=static function(mixed $value):array{
    $values=is_array($value)?$value:explode(',',(string)$value);
    return array_values(array_unique(array_filter(array_map(
        static fn($item):string=>strtoupper(trim((string)$item)),
        $values
    ))));
};
$types=$parseList($_GET['type']??$_GET['types']??[]);
$statuses=$parseList($_GET['status']??$_GET['statuses']??[]);
if($invalid=array_values(array_diff($types,$allowedTypes)))apiResponse(false,'Filtre type invalide : '.implode(', ',$invalid).'.',['allowed_values'=>$allowedTypes],422);
if($invalid=array_values(array_diff($statuses,$allowedStatuses)))apiResponse(false,'Filtre status invalide : '.implode(', ',$invalid).'.',['allowed_values'=>$allowedStatuses],422);

$availableFilter=null;
if(array_key_exists('available',$_GET)){
    $rawAvailable=strtolower(trim((string)$_GET['available']));
    if(!in_array($rawAvailable,['1','0','true','false'],true))apiResponse(false,'Filtre available invalide. Valeurs attendues : true ou false.',[],422);
    $availableFilter=in_array($rawAvailable,['1','true'],true);
}
$assignmentUuid=trim((string)($_GET['assignment_uuid']??''));
$campaignUuid=trim((string)($_GET['campaign_uuid']??''));
$page=max(1,(int)($_GET['page']??1));
$perPage=max(1,min(100,(int)($_GET['per_page']??20)));

try{

    $where=['cert.student_id=?'];
    $params=[$studentId];
    if($types){$where[]='cert.type_document IN('.implode(',',array_fill(0,count($types),'?')).')';array_push($params,...$types);}
    if($statuses){$where[]='cert.statut IN('.implode(',',array_fill(0,count($statuses),'?')).')';array_push($params,...$statuses);}
    if($assignmentUuid!==''){$where[]='a.uuid=?';$params[]=$assignmentUuid;}
    if($campaignUuid!==''){$where[]='c.uuid=?';$params[]=$campaignUuid;}

    $stmt=$pdo->prepare("
        SELECT

            cert.uuid,
            cert.reference,
            cert.type_document,
            cert.statut,
            cert.generated_at,
            cert.cancelled_at,
            cert.motif_annulation,

            comp.statut AS completion_status,
            comp.note_finale,
            comp.taux_presence,
            comp.validated_at,

            (
                SELECT t.statut
                FROM stage_result_items ri
                INNER JOIN stage_result_transmissions t ON t.id=ri.transmission_id
                WHERE ri.assignment_id=comp.assignment_id
                  AND t.statut IN('VALIDE','ARCHIVE')
                ORDER BY COALESCE(t.validated_at,t.archived_at,t.updated_at,t.created_at) DESC,t.id DESC
                LIMIT 1
            ) AS university_result_status,

            a.uuid AS assignment_uuid,
            a.date_debut,
            a.date_fin,

            hu.code AS unit_code,
            hu.nom AS unit_name,

            h.code AS hospital_code,
            h.nom AS hospital_name,

            c.code AS campaign_code,
            c.titre AS campaign_title,

            uni.code AS university_code,
            uni.nom AS university_name

        FROM stage_certificates cert

        INNER JOIN stage_completions comp
            ON comp.id=cert.completion_id

        INNER JOIN stage_assignments a
            ON a.id=comp.assignment_id

        LEFT JOIN host_units hu
            ON hu.id=a.host_unit_id

        INNER JOIN etablissements h
            ON h.id=cert.host_etablissement_id

        INNER JOIN stage_campaigns c
            ON c.id=comp.campaign_id

        INNER JOIN etablissements uni
            ON uni.id=c.owner_etablissement_id

        WHERE ".implode(' AND ',$where)."

        ORDER BY
            cert.generated_at DESC,
            cert.id DESC
    ");


    $stmt->execute($params);


    $rows=$stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    $items=[];


    $stats=[

        'total'=>0,

        'attestations'=>0,

        'certificates'=>0,

        'available'=>0,

        'cancelled'=>0,

        'pending_validation'=>0
    ];


    foreach($rows as $row){

        $available=
            $row['statut']==='GENERE'
            &&
            $row['completion_status']==='VALIDE'
            &&
            in_array($row['university_result_status'],['VALIDE','ARCHIVE'],true);

        if($availableFilter!==null&&$available!==$availableFilter)continue;


        if(
            $row['type_document']
            ===
            'ATTESTATION_STAGE'
        ){

            $stats['attestations']++;
        }


        if(
            $row['type_document']
            ===
            'CERTIFICAT_STAGE'
        ){

            $stats['certificates']++;
        }


        if($available){

            $stats['available']++;

        }elseif($row['statut']==='ANNULE'){

            $stats['cancelled']++;

        }else{

            $stats['pending_validation']++;
        }


        $stats['total']++;


        $fileEndpoint=null;


        if($available){

            $fileEndpoint=
                rtrim(BASE_URL,'/')
                .
                '/api/v1/student/certificates/'
                .
                rawurlencode(
                    $row['uuid']
                )
                .'/file';
        }


        $verificationUrl=

            rtrim(BASE_URL,'/')
            .
            '/verification-attestation.php?token='
            .
            rawurlencode(
                $row['uuid']
            );


        $items[]=[

            'uuid'=>
                $row['uuid'],

            'reference'=>
                $row['reference'],

            'type_document'=>
                $row['type_document'],

            'status'=>
                $row['statut'],

            'available'=>
                $available,

            'university_result_status'=>$row['university_result_status'],

            'availability_reason'=>$available
                ?null
                :'Le document sera disponible après validation des résultats par l’université.',

            'generated_at'=>
                $row['generated_at'],

            'cancelled_at'=>
                $row['cancelled_at'],

            'cancellation_reason'=>
                $row['motif_annulation'],


            'stage'=>[

                'assignment_uuid'=>
                    $row['assignment_uuid'],

                'start_date'=>
                    $row['date_debut'],

                'end_date'=>
                    $row['date_fin'],

                'note_finale'=>
                    $row['note_finale']!==null
                        ?(float)$row['note_finale']
                        :null,

                'taux_presence'=>
                    $row['taux_presence']!==null
                        ?(float)$row['taux_presence']
                        :null,

                'validated_at'=>
                    $row['validated_at']

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


            'university'=>[

                'code'=>
                    $row['university_code'],

                'name'=>
                    $row['university_name']

            ],


            'unit'=>[

                'code'=>
                    $row['unit_code'],

                'name'=>
                    $row['unit_name']

            ],


            'file_endpoint'=>$fileEndpoint,

            'download_endpoint'=>$fileEndpoint?$fileEndpoint.'?download=1':null,

            'requires_bearer'=>$fileEndpoint!==null,

            'verification_url'=>
                $verificationUrl

        ];
    }


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
                'types'=>$types,
                'statuses'=>$statuses,
                'available'=>$availableFilter,
                'assignment_uuid'=>$assignmentUuid?:null,
                'campaign_uuid'=>$campaignUuid?:null
            ],

            'available_filters'=>[
                'types'=>$allowedTypes,
                'statuses'=>$allowedStatuses,
                'available'=>[true,false],
                'per_page_max'=>100
            ]

        ]
    );


}catch(Throwable $e){

    apiResponse(
        false,
        'Erreur documents : '.$e->getMessage(),
        [],
        500
    );
}
