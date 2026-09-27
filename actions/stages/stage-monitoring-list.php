<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE'
]);

try{
    $etablissementId=currentEtablissementId($pdo);

    if(!$etablissementId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    $page=max(
        1,
        (int)($_GET['page']??1)
    );

    $perPage=(int)($_GET['per_page']??10);

    if(!in_array($perPage,[10,25,50],true))
        $perPage=10;

    $search=trim(
        (string)($_GET['search']??'')
    );

    $status=trim(
        (string)($_GET['status']??'')
    );

    $promotionExpr=columnExistsMonitoring(
        $pdo,
        'promotions',
        'nom'
    )
        ? "COALESCE(NULLIF(TRIM(p.nom),''),CONCAT('Promotion #',p.id))"
        : "CONCAT('Promotion #',p.id)";

    /* =====================================================
       LISTE DES STAGES
    ====================================================== */
    $sql="
        SELECT
            a.id AS assignment_id,
            a.statut AS assignment_statut,
            a.date_debut,
            a.date_fin,

            COALESCE(
                spl.student_id,
                se.student_id
            ) AS student_id,

            COALESCE(
                sp.stagia_code,
                CONCAT(
                    'INS-',
                    LPAD(app.academic_enrollment_id,8,'0')
                )
            ) AS stagia_code,

            COALESCE(
                NULLIF(TRIM(sp.nom),''),
                CONCAT(
                    'Étudiant #',
                    app.academic_enrollment_id
                )
            ) AS nom,

            COALESCE(sp.postnom,'') AS postnom,
            COALESCE(sp.prenom,'') AS prenom,

            NULL AS matricule,

            c.titre AS campaign_title,
            c.code AS campaign_code,

            {$promotionExpr} AS promotion_nom,

            h.nom AS host_name,
            hu.nom AS unit_name,

            (
                SELECT COUNT(*)
                FROM stage_rotations r
                WHERE r.assignment_id=a.id
            ) AS rotations_total,

            (
                SELECT COUNT(*)
                FROM stage_rotations r
                WHERE r.assignment_id=a.id
                  AND r.statut='TERMINEE'
            ) AS rotations_terminees,

            (
                SELECT COUNT(*)
                FROM stage_attendances att
                WHERE att.assignment_id=a.id
                  AND att.statut='PRESENT'
            ) AS presences,

            (
                SELECT COUNT(*)
                FROM stage_attendances att
                WHERE att.assignment_id=a.id
                  AND att.statut='RETARD'
            ) AS retards,

            (
                SELECT COUNT(*)
                FROM stage_attendances att
                WHERE att.assignment_id=a.id
                  AND att.statut='GARDE'
            ) AS gardes,

            (
                SELECT COUNT(*)
                FROM stage_attendances att
                WHERE att.assignment_id=a.id
                  AND att.statut='ABSENT'
            ) AS absences,

            (
                SELECT COUNT(*)
                FROM stage_attendances att
                WHERE att.assignment_id=a.id
                  AND att.statut='JUSTIFIE'
            ) AS justifiees,

            (
                SELECT COUNT(*)
                FROM stage_logbook_entries l
                WHERE l.assignment_id=a.id
            ) AS journaux_total,

            (
                SELECT COUNT(*)
                FROM stage_logbook_entries l
                WHERE l.assignment_id=a.id
                  AND l.statut='VALIDE'
            ) AS journaux_valides,

            (
                SELECT COUNT(*)
                FROM stage_evaluations ev
                WHERE ev.assignment_id=a.id
                  AND ev.type_evaluation='FIN_ROTATION'
                  AND ev.statut='FINALISEE'
            ) AS evaluation_finale

        FROM stage_assignments a

        INNER JOIN stage_admissions ad
            ON ad.id=a.admission_id

        INNER JOIN stage_reservations sr
            ON sr.id=ad.reservation_id

        INNER JOIN stage_applications app
            ON app.id=sr.application_id

        INNER JOIN student_academic_enrollments sae
            ON sae.id=app.academic_enrollment_id

        INNER JOIN promotions p
            ON p.id=sae.promotion_id

        LEFT JOIN student_enrollments se
            ON se.id=sae.enrollment_id

        LEFT JOIN stage_placements spl
            ON spl.id=(
                SELECT MAX(spl2.id)
                FROM stage_placements spl2
                WHERE spl2.academic_enrollment_id=
                      app.academic_enrollment_id
            )

        LEFT JOIN student_profiles sp
            ON sp.id=COALESCE(
                spl.student_id,
                se.student_id
            )

        INNER JOIN stage_campaigns c
            ON c.id=app.campaign_id

        INNER JOIN etablissements h
            ON h.id=a.host_etablissement_id

        LEFT JOIN host_units hu
            ON hu.id=a.host_unit_id

        WHERE p.etablissement_id=?

        ORDER BY a.id DESC
    ";

    $stmt=$pdo->prepare($sql);
    $stmt->execute([$etablissementId]);

    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =====================================================
       CALCUL DES INDICATEURS
    ====================================================== */
    $items=[];
    $today=new DateTimeImmutable('today');

    foreach($rows as $x){
        $x['assignment_id']=
            (int)$x['assignment_id'];

        if($x['student_id']!==null)
            $x['student_id']=
                (int)$x['student_id'];

        foreach([
            'rotations_total',
            'rotations_terminees',
            'presences',
            'retards',
            'gardes',
            'absences',
            'justifiees',
            'journaux_total',
            'journaux_valides',
            'evaluation_finale'
        ] as $field){
            $x[$field]=(int)$x[$field];
        }

        /* ---------------------------------------------
           TAUX DE PRÉSENCE
        ---------------------------------------------- */
        $totalAttendance=
            $x['presences']+
            $x['retards']+
            $x['gardes']+
            $x['absences']+
            $x['justifiees'];

        $presentAttendance=
            $x['presences']+
            $x['retards']+
            $x['gardes'];

        $x['taux_presence']=
            $totalAttendance>0
                ?round(
                    ($presentAttendance/$totalAttendance)*100,
                    2
                )
                :null;

        /* ---------------------------------------------
           PROGRESSION
        ---------------------------------------------- */
        $x['progression']=stageProgress(
            $x['date_debut'],
            $x['date_fin'],
            $today
        );

        /* ---------------------------------------------
           ÉTAT MÉTIER
        ---------------------------------------------- */
        $baseState=stageState(
            $x['assignment_statut'],
            $x['date_debut'],
            $x['date_fin'],
            $today
        );

        /* ---------------------------------------------
           ALERTES
        ---------------------------------------------- */
        $alerts=0;

        if($x['absences']>0)
            $alerts++;

        if(
            $x['assignment_statut']==='ACTIVE' &&
            $x['date_fin'] &&
            new DateTimeImmutable($x['date_fin'])<$today
        )
            $alerts++;

        if(
            $x['rotations_total']>0 &&
            $x['rotations_terminees']<$x['rotations_total'] &&
            $x['date_fin'] &&
            new DateTimeImmutable($x['date_fin'])<$today
        )
            $alerts++;

        $x['alertes']=$alerts;

        /*
         * Le badge peut afficher ALERTE,
         * mais l'état de base reste conservé
         * pour les statistiques et filtres.
         */
        $x['_base_state']=$baseState;

        $x['etat']=
            $alerts>0
                ?'ALERTE'
                :$baseState;

        $items[]=$x;
    }

    /* =====================================================
       STATISTIQUES GLOBALES
    ====================================================== */
    $stats=[
        'total'=>count($items),
        'en_cours'=>0,
        'a_cloturer'=>0,
        'alertes'=>0
    ];

    foreach($items as $x){
        if($x['_base_state']==='EN_COURS')
            $stats['en_cours']++;

        if($x['_base_state']==='A_CLOTURER')
            $stats['a_cloturer']++;

        if($x['alertes']>0)
            $stats['alertes']++;
    }

    /* =====================================================
       FILTRAGE
    ====================================================== */
    $filtered=array_values(
        array_filter(
            $items,
            function($x)use($search,$status){

                if($status){
                    if(
                        $status==='ALERTE' &&
                        $x['alertes']<=0
                    )
                        return false;

                    if(
                        $status!=='ALERTE' &&
                        $x['_base_state']!==$status
                    )
                        return false;
                }

                if(!$search)
                    return true;

                $haystack=strtolower(
                    implode(' ',[
                        $x['nom']??'',
                        $x['postnom']??'',
                        $x['prenom']??'',
                        $x['stagia_code']??'',
                        $x['campaign_title']??'',
                        $x['campaign_code']??'',
                        $x['host_name']??'',
                        $x['unit_name']??'',
                        $x['promotion_nom']??''
                    ])
                );

                return str_contains(
                    $haystack,
                    strtolower($search)
                );
            }
        )
    );

    /* Champ interne inutile côté navigateur */
    foreach($filtered as &$x)
        unset($x['_base_state']);

    unset($x);

    /* =====================================================
       PAGINATION
    ====================================================== */
    $total=count($filtered);

    $pages=max(
        1,
        (int)ceil(
            $total/$perPage
        )
    );

    if($page>$pages)
        $page=$pages;

    $offset=
        ($page-1)*$perPage;

    $paged=array_slice(
        $filtered,
        $offset,
        $perPage
    );

    $from=
        $total
            ?$offset+1
            :0;

    $to=
        $total
            ?min(
                $offset+$perPage,
                $total
            )
            :0;

    jsonResponse(true,'',[
        'items'=>$paged,

        'stats'=>$stats,

        'pagination'=>[
            'page'=>$page,
            'per_page'=>$perPage,
            'total'=>$total,
            'pages'=>$pages,
            'from'=>$from,
            'to'=>$to
        ]
    ]);

}catch(Throwable $e){
    jsonResponse(
        false,
        'Erreur suivi : '.$e->getMessage(),
        [],
        500
    );
}


/* =========================================================
   ÉTAT DU STAGE
========================================================= */
function stageState(
    string $status,
    ?string $start,
    ?string $end,
    DateTimeImmutable $today
):string{

    if($status==='TERMINEE')
        return 'CLOTURE';

    if($status==='ANNULEE')
        return 'ANNULE';

    if($status==='PLANIFIEE')
        return 'PLANIFIE';

    if(
        $status==='ACTIVE' &&
        $end &&
        new DateTimeImmutable($end)<$today
    )
        return 'A_CLOTURER';

    if($status==='ACTIVE')
        return 'EN_COURS';

    return $status;
}


/* =========================================================
   PROGRESSION
========================================================= */
function stageProgress(
    ?string $start,
    ?string $end,
    DateTimeImmutable $today
):int{

    if(!$start || !$end)
        return 0;

    $from=new DateTimeImmutable($start);
    $to=new DateTimeImmutable($end);

    if($to<=$from)
        return 100;

    if($today<=$from)
        return 0;

    if($today>=$to)
        return 100;

    $total=
        max(
            1,
            $from->diff($to)->days
        );

    $elapsed=
        $from->diff($today)->days;

    return (int)round(
        ($elapsed/$total)*100
    );
}


/* =========================================================
   COLONNE EXISTANTE ?
========================================================= */
function columnExistsMonitoring(
    PDO $pdo,
    string $table,
    string $column
):bool{

    $stmt=$pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME=?
          AND COLUMN_NAME=?
        LIMIT 1
    ");

    $stmt->execute([
        $table,
        $column
    ]);

    return (bool)$stmt->fetchColumn();
}