<?php
/**
 * Endpoint AJAX qui prépare la liste des stages clôturables dans l'établissement d'accueil.
 * Il expose les prérequis de clôture et l'attestation éventuellement déjà générée.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-completion.php';

/* La consultation et le pilotage de clôture relèvent de l'accueil. */
requireAjaxRole(['ADMIN_ACCUEIL']);

try{
    /* L'établissement actif de la session borne le périmètre des stages affichés. */
    $hostId=currentEtablissementId($pdo);

    if(!$hostId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    /* =====================================================
       STAGES DE L'ÉTABLISSEMENT
       LEFT JOIN pour préserver les stages historiques
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT DISTINCT
            a.id AS assignment_id,

            spl.student_id,

            COALESCE(
                sp.stagia_code,
                CONCAT('STG-ID-',LPAD(spl.student_id,8,'0'))
            ) AS stagia_code,

            COALESCE(
                NULLIF(TRIM(sp.nom),''),
                CONCAT('Stagiaire #',spl.student_id)
            ) AS nom,

            COALESCE(sp.postnom,'') AS postnom,
            COALESCE(sp.prenom,'') AS prenom,

            CONCAT(
                'CAM-',
                LPAD(sa.campaign_id,6,'0')
            ) AS campaign_code,

            COALESCE(
                c.titre,
                CONCAT('Campagne #',sa.campaign_id)
            ) AS campaign_title,

            COALESCE(
                hu.nom,
                CONCAT('Unité #',a.host_unit_id)
            ) AS unit_name

        FROM stage_assignments a

        INNER JOIN stage_admissions ad
            ON ad.id=a.admission_id

        INNER JOIN stage_reservations sr
            ON sr.id=ad.reservation_id

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        LEFT JOIN stage_placements spl
            ON spl.academic_enrollment_id=
               sa.academic_enrollment_id

        LEFT JOIN student_profiles sp
            ON sp.id=spl.student_id

        LEFT JOIN stage_campaigns c
            ON c.id=sa.campaign_id

        LEFT JOIN host_units hu
            ON hu.id=a.host_unit_id

        WHERE a.host_etablissement_id=?
          AND a.statut IN('ACTIVE','TERMINEE')

        ORDER BY a.id DESC
    ");

    $stmt->execute([$hostId]);
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =====================================================
       CLÔTURE EXISTANTE
    ====================================================== */
    /* Une seule clôture, la plus récente, est récupérée pour chaque affectation. */
    $completionStmt=$pdo->prepare("
        SELECT *
        FROM stage_completions
        WHERE assignment_id=?
        ORDER BY id DESC
        LIMIT 1
    ");

    /* =====================================================
       ATTESTATION
    ====================================================== */
    /* L'attestation est recherchée seulement après l'identification d'une clôture. */
    $certStmt=$pdo->prepare("
        SELECT
            id,
            uuid,
            reference,
            type_document,
            statut,
            fichier,
            generated_at
        FROM stage_certificates
        WHERE completion_id=?
          AND statut='GENERE'
        ORDER BY id DESC
        LIMIT 1
    ");

    /* =====================================================
       TRAITEMENT
    ====================================================== */
    /* Chaque stage reçoit son état de clôture synchronisé et son document éventuel. */
    foreach($items as &$x){

        $assignmentId=(int)$x['assignment_id'];

        $x['assignment_id']=$assignmentId;

        if(isset($x['student_id']))
            $x['student_id']=
                $x['student_id']!==null
                    ?(int)$x['student_id']
                    :null;

        /* -------------------------------------------------
           Clôture existante
        -------------------------------------------------- */
        $completionStmt->execute([$assignmentId]);

        $current=$completionStmt->fetch(
            PDO::FETCH_ASSOC
        );

        /*
         * Ne jamais recalculer une clôture définitive.
         */
        if(
            $current &&
            in_array(
                $current['statut'],
                ['VALIDE','REFUSE','ANNULE'],
                true
            )
        ){
            normalizeCompletionForList($current);

            $current['blockers']=[];

            $x['completion']=$current;

        }else{

            $x['completion']=syncStageCompletion(
                $pdo,
                $assignmentId
            );
        }

        /* -------------------------------------------------
           Attestation
        -------------------------------------------------- */
        $completionId=(int)(
            $x['completion']['id']??0
        );

        if(!$completionId){
            $x['certificate']=null;
            continue;
        }

        $certStmt->execute([$completionId]);

        $certificate=$certStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if(!$certificate){
            $x['certificate']=null;
            continue;
        }

        $certificate['id']=
            (int)$certificate['id'];

        $x['certificate']=$certificate;
    }

    unset($x);

    jsonResponse(
        true,
        '',
        [
            'items'=>$items
        ]
    );

}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur clôture : '.$e->getMessage(),
        [],
        500
    );
}


/* =========================================================
   NORMALISATION JSON
========================================================= */
/** Convertit les valeurs numériques des données de clôture avant l'encodage JSON. */
function normalizeCompletionForList(
    array &$c
):void{

    $ints=[
        'id',
        'assignment_id',
        'admission_id',
        'student_id',
        'host_etablissement_id',
        'campaign_id',
        'total_rotations',
        'rotations_terminees',
        'total_presences',
        'total_retards',
        'total_absences',
        'total_justifiees',
        'total_journaux',
        'journaux_valides',
        'validated_by'
    ];

    foreach($ints as $field){

        if(
            array_key_exists($field,$c) &&
            $c[$field]!==null
        ){
            $c[$field]=(int)$c[$field];
        }
    }

    if(
        array_key_exists(
            'final_evaluation_id',
            $c
        ) &&
        $c['final_evaluation_id']!==null
    ){
        $c['final_evaluation_id']=
            (int)$c['final_evaluation_id'];
    }

    if(
        array_key_exists(
            'taux_presence',
            $c
        ) &&
        $c['taux_presence']!==null
    ){
        $c['taux_presence']=
            (float)$c['taux_presence'];
    }

    if(
        array_key_exists(
            'note_finale',
            $c
        ) &&
        $c['note_finale']!==null
    ){
        $c['note_finale']=
            (float)$c['note_finale'];
    }
}
