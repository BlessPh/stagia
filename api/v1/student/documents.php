<?php

require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../config/config.php';

requireApiMethod('GET');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];


try{

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

        WHERE cert.student_id=?

        ORDER BY
            cert.generated_at DESC,
            cert.id DESC
    ");


    $stmt->execute([
        $studentId
    ]);


    $rows=$stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    $items=[];


    $stats=[

        'total'=>0,

        'attestations'=>0,

        'certificates'=>0,

        'available'=>0,

        'cancelled'=>0
    ];


    foreach($rows as $row){

        $available=
            $row['statut']==='GENERE'
            &&
            $row['completion_status']==='VALIDE';


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

        }else{

            $stats['cancelled']++;
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
        'Erreur documents : '.$e->getMessage(),
        [],
        500
    );
}
