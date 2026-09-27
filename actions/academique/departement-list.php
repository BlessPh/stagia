<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

try{
    requirePermission($pdo,'academic.view');

    $eid=(int)($_SESSION['etablissement_id']??0);
    if(!$eid)jsonResponse(false,'Aucun établissement académique actif.',[],403);

    $settings=$_SESSION['academic_settings']??[];
    if(empty($settings['departement_active']))
        jsonResponse(false,'Les départements sont désactivés pour cet établissement.',[],403);

    $search=trim((string)($_GET['search']??''));
    $faculteId=(int)($_GET['faculte_id']??0);
    $validation=trim((string)($_GET['validation']??''));

    $where=['d.etablissement_id=?'];
    $params=[$eid];

    if($search!==''){
        $where[]='(
            d.nom LIKE ?
            OR d.specialisation LIKE ?
            OR d.code LIKE ?
            OR f.nom LIKE ?
        )';

        $like='%'.$search.'%';

        array_push(
            $params,
            $like,
            $like,
            $like,
            $like
        );
    }

    if($faculteId>0){
        $where[]='d.faculte_id=?';
        $params[]=$faculteId;
    }

    if($validation!==''){
        $where[]='d.validation_statut=?';
        $params[]=$validation;
    }

    $s=$pdo->prepare("
        SELECT
            d.id,
            d.faculte_id,
            d.code,
            d.nom,
            d.specialisation,
            d.actif,
            d.source_template_department_id,
            d.ajoute_localement,
            d.validation_statut,
            d.motif_ajout,
            d.review_comment,

            DATE_FORMAT(
                d.created_at,
                '%d/%m/%Y'
            ) date_creation,

            f.nom faculte_nom,
            f.type_unite faculte_type,

            COUNT(DISTINCT p.id) programmes_count

        FROM departements d

        LEFT JOIN facultes f
          ON f.id=d.faculte_id
         AND f.etablissement_id=d.etablissement_id

        LEFT JOIN filieres p
          ON p.departement_id=d.id
         AND p.etablissement_id=d.etablissement_id

        WHERE ".implode(' AND ',$where)."

        GROUP BY
            d.id,
            d.faculte_id,
            d.code,
            d.nom,
            d.specialisation,
            d.actif,
            d.source_template_department_id,
            d.ajoute_localement,
            d.validation_statut,
            d.motif_ajout,
            d.review_comment,
            d.created_at,
            f.nom,
            f.type_unite

        ORDER BY
            COALESCE(f.nom,''),
            d.nom
    ");

    $s->execute($params);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Les parents disponibles sont uniquement les structures actives.
     * Les ajouts locaux VALIDE_LOCAL sont immédiatement utilisables.
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
    $facultes=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("
        SELECT
            COUNT(*) total,
            COALESCE(SUM(validation_statut='NATIONAL'),0) nationaux,
            COALESCE(SUM(ajoute_localement=1),0) locaux,
            COALESCE(SUM(actif=1),0) actifs
        FROM departements
        WHERE etablissement_id=?
    ");

    $s->execute([$eid]);
    $kpi=$s->fetch(PDO::FETCH_ASSOC)?:[];

    jsonResponse(
        true,
        '',
        [
            'items'=>$items,
            'facultes'=>$facultes,

            'kpi'=>[
                'total'=>(int)($kpi['total']??0),
                'nationaux'=>(int)($kpi['nationaux']??0),
                'locaux'=>(int)($kpi['locaux']??0),
                'actifs'=>(int)($kpi['actifs']??0)
            ],

            'can_manage'=>hasPermission($pdo,'academic.manage'),
            'departement_obligatoire'=>
                (bool)($settings['departement_obligatoire']??false)
        ]
    );

}catch(Throwable $e){

    error_log(
        '[DEPARTEMENT LIST] '.
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Erreur Départements : '.$e->getMessage(),
        [],
        500
    );
}
