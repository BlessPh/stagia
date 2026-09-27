<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

try{
    requirePermission($pdo,'academic.view');

    $eid=(int)($_SESSION['etablissement_id']??0);
    if(!$eid)
        jsonResponse(false,'Aucun établissement académique actif.',[],403);

    $cfg=$_SESSION['academic_settings']??[];

    if(empty($cfg['filiere_active']))
        jsonResponse(
            false,
            'Les filières / programmes sont désactivés pour cet établissement.',
            [],
            403
        );

    $search=trim((string)($_GET['search']??''));
    $validation=trim((string)($_GET['validation']??''));
    $mode=strtoupper(trim((string)($_GET['rattachement']??'')));

    $where=['p.etablissement_id=?'];
    $params=[$eid];

    if($search!==''){
        $where[]='(
            p.nom LIKE ?
            OR p.code LIKE ?
            OR r.nom LIKE ?
            OR r.code LIKE ?
            OR d.nom LIKE ?
            OR u.nom LIKE ?
        )';

        $like='%'.$search.'%';

        array_push(
            $params,
            $like,$like,$like,$like,$like,$like
        );
    }

    if($validation!==''){
        $where[]='p.validation_statut=?';
        $params[]=$validation;
    }

    if(in_array(
        $mode,
        ['DEPARTEMENT','UNITE','ETABLISSEMENT'],
        true
    )){
        if($mode==='DEPARTEMENT')
            $where[]='p.departement_id IS NOT NULL';

        if($mode==='UNITE')
            $where[]='p.departement_id IS NULL AND p.faculte_id IS NOT NULL';

        if($mode==='ETABLISSEMENT')
            $where[]='p.departement_id IS NULL AND p.faculte_id IS NULL';
    }

    $s=$pdo->prepare("
        SELECT
            p.id,
            p.code,
            p.nom,
            p.duree_annees,
            p.description,
            p.actif,
            p.faculte_id,
            p.departement_id,
            p.curriculum_reference_id,
            p.preparatory_level_enabled,
            p.source_template_program_id,
            p.ajoute_localement,
            p.validation_statut,
            p.motif_ajout,
            p.review_comment,

            DATE_FORMAT(
                p.created_at,
                '%d/%m/%Y'
            ) date_creation,

            r.code curriculum_code,
            r.nom curriculum_nom,
            r.domaine curriculum_domaine,

            d.nom departement_nom,
            d.specialisation departement_specialisation,

            u.nom unite_nom,
            u.type_unite,

            CASE
                WHEN p.departement_id IS NOT NULL
                    THEN 'DEPARTEMENT'
                WHEN p.faculte_id IS NOT NULL
                    THEN 'UNITE'
                ELSE 'ETABLISSEMENT'
            END rattachement_type,

            (
                SELECT COUNT(*)
                FROM options_specialites o
                WHERE o.filiere_id=p.id
                  AND o.etablissement_id=p.etablissement_id
            ) nb_options,

            (
                SELECT COUNT(*)
                FROM promotions pr
                WHERE pr.filiere_id=p.id
                  AND pr.etablissement_id=p.etablissement_id
            ) nb_promotions

        FROM filieres p

        LEFT JOIN curriculum_references r
          ON r.id=p.curriculum_reference_id

        LEFT JOIN departements d
          ON d.id=p.departement_id
         AND d.etablissement_id=p.etablissement_id

        LEFT JOIN facultes u
          ON u.id=p.faculte_id
         AND u.etablissement_id=p.etablissement_id

        WHERE ".implode(' AND ',$where)."

        ORDER BY p.nom
    ");

    $s->execute($params);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Parents disponibles :
     * uniquement les structures actives et utilisables localement.
     */
    $s=$pdo->prepare("
        SELECT
            id,
            code,
            nom,
            type_unite
        FROM facultes
        WHERE etablissement_id=?
          AND actif=1
          AND validation_statut IN(
              'NATIONAL',
              'VALIDE_LOCAL',
              'INTEGRE_REFERENTIEL'
          )
        ORDER BY nom
    ");

    $s->execute([$eid]);
    $units=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("
        SELECT
            d.id,
            d.code,
            d.nom,
            d.specialisation,
            d.faculte_id,
            f.nom unite_nom
        FROM departements d

        LEFT JOIN facultes f
          ON f.id=d.faculte_id
         AND f.etablissement_id=d.etablissement_id

        WHERE d.etablissement_id=?
          AND d.actif=1
          AND d.validation_statut IN(
              'NATIONAL',
              'VALIDE_LOCAL',
              'INTEGRE_REFERENTIEL'
          )

        ORDER BY d.nom
    ");

    $s->execute([$eid]);
    $departments=$s->fetchAll(PDO::FETCH_ASSOC);

    $curricula=$pdo->query("
        SELECT
            id,
            code,
            nom,
            domaine
        FROM curriculum_references
        WHERE actif=1
        ORDER BY domaine,nom
    ")->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("
        SELECT
            COUNT(*) total,
            COALESCE(
                SUM(validation_statut='NATIONAL'),
                0
            ) nationaux,
            COALESCE(
                SUM(ajoute_localement=1),
                0
            ) locaux,
            COALESCE(
                SUM(actif=1),
                0
            ) actifs
        FROM filieres
        WHERE etablissement_id=?
    ");

    $s->execute([$eid]);
    $kpi=$s->fetch(PDO::FETCH_ASSOC)?:[];

    jsonResponse(
        true,
        '',
        [
            'items'=>$items,
            'units'=>$units,
            'departments'=>$departments,
            'curricula'=>$curricula,

            /*
             * La structure est maintenant souple :
             * - aucun parent est toujours permis ;
             * - les types de parents sont proposés seulement
             *   s'ils existent réellement.
             */
            'rules'=>[
                'allow_department'=>count($departments)>0,
                'allow_unit'=>count($units)>0,
                'allow_establishment'=>true
            ],

            'kpi'=>[
                'total'=>(int)($kpi['total']??0),
                'nationaux'=>(int)($kpi['nationaux']??0),
                'locaux'=>(int)($kpi['locaux']??0),
                'actifs'=>(int)($kpi['actifs']??0)
            ],

            'can_manage'=>hasPermission(
                $pdo,
                'academic.manage'
            )
        ]
    );

}catch(Throwable $e){

    error_log(
        '[FILIERE LIST] '.
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Erreur Filières / Programmes : '.
        $e->getMessage(),
        [],
        500
    );
}
