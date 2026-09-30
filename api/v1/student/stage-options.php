<?php

require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-student-workflow.php';

requireApiMethod('GET');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];


try{

    expireStudentTemporaryReservations($pdo,$studentId);

    /* =====================================================
       CAMPAGNES D4 ÉLIGIBLES
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT

            ae.id AS academic_enrollment_id,

            c.id AS campaign_id,
            c.code,
            c.titre,
            c.date_debut,
            c.date_fin,

            st.code AS stage_type_code,
            st.libelle AS stage_type_label,

            p.code AS promotion_code,
            p.nom AS promotion,
            p.niveau,

            f.nom AS filiere

        FROM student_academic_enrollments ae

        INNER JOIN student_enrollments se
            ON se.id=ae.enrollment_id

        INNER JOIN stage_campaigns c
            ON c.owner_etablissement_id=se.etablissement_id
           AND c.annee_academique_id=ae.annee_academique_id
           AND c.type_campagne='UNIVERSITAIRE'
           AND c.statut='OUVERTE'

        INNER JOIN stage_types st
            ON st.id=c.stage_type_id
           AND st.code='MEDICAL_D4'

        INNER JOIN stage_campaign_promotions cp
            ON cp.campaign_id=c.id
           AND cp.promotion_id=ae.promotion_id

        INNER JOIN promotions p
            ON p.id=ae.promotion_id

        LEFT JOIN filieres f
            ON f.id=p.filiere_id

        WHERE se.student_id=?
          AND se.statut='ACTIF'
          AND ae.statut='EN_COURS'

        ORDER BY
            c.date_debut,
            c.id DESC
    ");


    $stmt->execute([
        $studentId
    ]);


    $campaignRows=
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    $campaigns=[];


    foreach($campaignRows as $campaign){

        $campaignId=
            (int)$campaign['campaign_id'];


        $academicId=
            (int)$campaign['academic_enrollment_id'];


        /* =================================================
           BLOQUER CAMPAGNE DÉJÀ ENGAGÉE
        ================================================= */

        $stmt=$pdo->prepare("
            SELECT 1

            FROM stage_applications sa

            INNER JOIN student_academic_enrollments ae2
                ON ae2.id=sa.academic_enrollment_id

            INNER JOIN student_enrollments se2
                ON se2.id=ae2.enrollment_id

            LEFT JOIN stage_reservations sr2
                ON sr2.application_id=sa.id

            LEFT JOIN stage_admissions ad2
                ON ad2.reservation_id=sr2.id
               AND ad2.statut<>'ANNULE'

            LEFT JOIN stage_placements pl2
                ON pl2.reservation_id=sr2.id
               AND pl2.statut='CONFIRME'

            LEFT JOIN stage_assignments ass2
                ON ass2.admission_id=ad2.id

            WHERE sa.campaign_id=?
              AND se2.student_id=?

              AND (

                    (
                        sr2.statut='RESERVEE_TEMPORAIREMENT'

                        AND (
                            sr2.expires_at IS NULL
                            OR sr2.expires_at>NOW()
                        )
                    )

                    OR sr2.statut IN(
                        'EN_ATTENTE_PAIEMENT',
                        'CONFIRMEE'
                    )

                    OR ad2.id IS NOT NULL

                    OR pl2.id IS NOT NULL

                    OR (
                        ass2.id IS NOT NULL
                        AND ass2.statut<>'ANNULEE'
                    )

              )

            LIMIT 1
        ");


        $stmt->execute([
            $campaignId,
            $studentId
        ]);


        if($stmt->fetchColumn()){

            /*
             * L'étudiant possède déjà une réservation,
             * admission ou affectation pour cette campagne.
             */

            continue;
        }


        /* =================================================
           BLOQUER CAMPAGNE DÉJÀ TERMINÉE / VALIDÉE
        ================================================= */

        $stmt=$pdo->prepare("
            SELECT 1

            FROM stage_completions

            WHERE campaign_id=?
              AND student_id=?

              AND statut IN(
                  'EN_PREPARATION',
                  'PRET',
                  'VALIDE'
              )

            LIMIT 1
        ");


        $stmt->execute([
            $campaignId,
            $studentId
        ]);


        if($stmt->fetchColumn()){

            continue;
        }


        /* =================================================
           HÔPITAUX AYANT ACCEPTÉ LA CAMPAGNE
        ================================================= */

        $stmt=$pdo->prepare("
            SELECT

                part.id AS participation_id,

                part.host_etablissement_id,

                part.capacite_acceptee,

                part.frais_requis,

                part.montant_frais,

                part.devise,

                part.conditions,

                h.code AS hospital_code,

                h.nom AS hospital_name,

                h.ville,

                h.province

            FROM stage_campaign_participations part

            INNER JOIN stage_campaigns host_campaign
                ON host_campaign.id=part.host_campaign_id
               AND host_campaign.type_campagne='ACCUEIL'
               AND host_campaign.statut NOT IN('ANNULEE','TERMINEE')

            INNER JOIN stage_capacity_pools capacity_pool
                ON capacity_pool.host_campaign_id=host_campaign.id

            INNER JOIN etablissements h
                ON h.id=part.host_etablissement_id
               AND h.type_etablissement='HOPITAL'
               AND h.statut IN('VALIDE','ACTIF')

            WHERE part.university_campaign_id=?
              AND part.statut='ACCEPTEE'
              AND part.capacite_acceptee IS NOT NULL
              AND part.capacite_acceptee>0

            ORDER BY h.nom
        ");


        $stmt->execute([
            $campaignId
        ]);


        $participations=
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        $hospitals=[];


        foreach(
            $participations
            as $participation
        ){

            $participationId=
                (int)$participation['participation_id'];


            $capacity=
                (int)$participation['capacite_acceptee'];


            /* =============================================
               PLACES ACTUELLEMENT UTILISÉES
            ============================================= */

            $stmt=$pdo->prepare("
                SELECT COUNT(*)

                FROM stage_reservations

                WHERE participation_id=?

                  AND (

                        statut IN(
                            'EN_ATTENTE_PAIEMENT',
                            'CONFIRMEE'
                        )

                        OR (

                            statut='RESERVEE_TEMPORAIREMENT'

                            AND (
                                expires_at IS NULL
                                OR expires_at>NOW()
                            )

                        )

                  )
            ");


            $stmt->execute([
                $participationId
            ]);


            $used=
                (int)$stmt->fetchColumn();


            $available=
                max(
                    0,
                    $capacity-$used
                );


            /* =============================================
               HÔPITAL
            ============================================= */

            $hospitals[]=[

                'participation_id'=>
                    $participationId,

                'hospital'=>[

                    'code'=>
                        $participation['hospital_code'],

                    'name'=>
                        $participation['hospital_name'],

                    'city'=>
                        $participation['ville'],

                    'province'=>
                        $participation['province']

                ],

                'capacity'=>
                    $capacity,

                'used_places'=>
                    $used,

                'available_places'=>
                    $available,

                'available'=>
                    $available>0,

                'fees_required'=>
                    (bool)$participation['frais_requis'],

                'amount'=>
                    $participation['montant_frais']!==null
                        ?(float)$participation['montant_frais']
                        :null,

                'currency'=>
                    $participation['devise'],

                'conditions'=>
                    $participation['conditions']

            ];
        }

        /* Une campagne ouverte sans accueil hospitalier finalisé ne doit pas
           être proposée, même si des données historiques incohérentes existent. */
        if(!$hospitals){
            continue;
        }


        /* =================================================
           CAMPAGNE
        ================================================= */

        $campaigns[]=[

            /*
             * Ces deux identifiants seront envoyés
             * à reserve.php.
             */

            'campaign_id'=>
                $campaignId,

            'academic_enrollment_id'=>
                $academicId,

            'code'=>
                $campaign['code'],

            'title'=>
                $campaign['titre'],

            'start_date'=>
                $campaign['date_debut'],

            'end_date'=>
                $campaign['date_fin'],

            'stage_type'=>[
                'code'=>$campaign['stage_type_code'],
                'label'=>$campaign['stage_type_label']
            ],

            'mode'=>studentStageTypeMode($campaign['stage_type_code']),

            'promotion'=>[

                'code'=>
                    $campaign['promotion_code'],

                'name'=>
                    $campaign['promotion'],

                'level'=>
                    $campaign['niveau']

            ],

            'program'=>
                $campaign['filiere'],

            'hospitals'=>
                $hospitals,

            'hospitals_count'=>
                count($hospitals),

            'available_hospitals'=>
                count(
                    array_filter(
                        $hospitals,
                        function($hospital){

                            return
                                $hospital['available']
                                ===true;
                        }
                    )
                )

        ];
    }


    /* Les autres types restent visibles quand un accueil hospitalier valide
       existe, mais leur placement est piloté par l'université : ils ne sont
       jamais envoyés au service de réservation D4. */
    $stmt=$pdo->prepare("
        SELECT DISTINCT ae.id academic_enrollment_id,c.id campaign_id,c.code,c.titre,
               c.date_debut,c.date_fin,st.code stage_type_code,st.libelle stage_type_label,
               p.code promotion_code,p.nom promotion,p.niveau,f.nom filiere
        FROM student_academic_enrollments ae
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaigns c ON c.owner_etablissement_id=se.etablissement_id
             AND c.annee_academique_id=ae.annee_academique_id
             AND c.type_campagne='UNIVERSITAIRE' AND c.statut='OUVERTE'
        JOIN stage_types st ON st.id=c.stage_type_id AND st.actif=1 AND st.code<>'MEDICAL_D4'
        JOIN stage_campaign_promotions cp ON cp.campaign_id=c.id AND cp.promotion_id=ae.promotion_id
        JOIN promotions p ON p.id=ae.promotion_id
        LEFT JOIN filieres f ON f.id=p.filiere_id
        WHERE se.student_id=? AND se.statut='ACTIF' AND ae.statut='EN_COURS'
          AND EXISTS(
              SELECT 1
              FROM stage_campaign_participations accepted_part
              JOIN stage_campaigns accepted_host_campaign
                ON accepted_host_campaign.id=accepted_part.host_campaign_id
               AND accepted_host_campaign.type_campagne='ACCUEIL'
               AND accepted_host_campaign.statut NOT IN('ANNULEE','TERMINEE')
              JOIN stage_capacity_pools accepted_pool
                ON accepted_pool.host_campaign_id=accepted_host_campaign.id
              JOIN etablissements accepted_hospital
                ON accepted_hospital.id=accepted_part.host_etablissement_id
               AND accepted_hospital.type_etablissement='HOPITAL'
               AND accepted_hospital.statut IN('VALIDE','ACTIF')
              WHERE accepted_part.university_campaign_id=c.id
                AND accepted_part.statut='ACCEPTEE'
                AND COALESCE(
                      NULLIF(accepted_part.capacite_acceptee,0),
                      NULLIF(accepted_part.capacite_allouee,0),
                      0
                    )>0
          )
        ORDER BY c.date_debut,c.id DESC
    ");
    $stmt->execute([$studentId]);
    $managedCampaigns=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $campaign){
        $hospitals=studentCampaignAcceptedHospitals(
            $pdo,
            (int)$campaign['campaign_id']
        );
        $managedCampaigns[]=[
            'campaign_id'=>(int)$campaign['campaign_id'],
            'academic_enrollment_id'=>(int)$campaign['academic_enrollment_id'],
            'code'=>$campaign['code'],'title'=>$campaign['titre'],
            'start_date'=>$campaign['date_debut'],'end_date'=>$campaign['date_fin'],
            'stage_type'=>['code'=>$campaign['stage_type_code'],'label'=>$campaign['stage_type_label']],
            'mode'=>studentStageTypeMode($campaign['stage_type_code']),
            'promotion'=>['code'=>$campaign['promotion_code'],'name'=>$campaign['promotion'],'level'=>$campaign['niveau']],
            'program'=>$campaign['filiere'],
            'hospitals'=>$hospitals,
            'hospitals_count'=>count($hospitals),
            'available_hospitals'=>count(array_filter(
                $hospitals,
                static fn(array $hospital):bool=>$hospital['available']===true
            ))
        ];
    }

    /* =====================================================
       STATISTIQUES
    ====================================================== */

    $totalHospitals=0;
    $availableHospitals=0;
    $availablePlaces=0;


    foreach(array_merge($campaigns,$managedCampaigns) as $campaign){

        foreach(
            $campaign['hospitals']
            as $hospital
        ){

            $totalHospitals++;


            if($hospital['available']){

                $availableHospitals++;
            }


            $availablePlaces+=
                (int)$hospital[
                    'available_places'
                ];
        }
    }


    /* =====================================================
       RÉPONSE
    ====================================================== */

    apiResponse(
        true,
        '',
        [

            'campaigns'=>$campaigns,

            'university_managed_campaigns'=>$managedCampaigns,

            'stats'=>[

                'campaigns'=>
                    count($campaigns),

                'university_managed_campaigns'=>count($managedCampaigns),

                'hospitals'=>
                    $totalHospitals,

                'available_hospitals'=>
                    $availableHospitals,

                'available_places'=>
                    $availablePlaces

            ]

        ]
    );


}catch(Throwable $e){

    apiResponse(
        false,
        'Erreur options de stage : '.
        $e->getMessage(),
        [],
        500
    );
}
