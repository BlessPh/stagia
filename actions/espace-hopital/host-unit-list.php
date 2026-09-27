<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

try{
    requirePermission($pdo,'host.view');

    $eid=(int)($_SESSION['etablissement_id']??0);

    if(!$eid)jsonResponse(false,"Aucun établissement d'accueil actif.",[],403);
    if(!contextHostEnabled())
        jsonResponse(false,"Le module Structure d'accueil n'est pas activé pour cet établissement.",[],403);

    $search=trim((string)($_GET['search']??''));
    $type=trim((string)($_GET['type']??''));
    $validation=trim((string)($_GET['validation']??''));

    $allowed=[
        'DEPARTEMENT','SERVICE','UNITE','LABORATOIRE','PROJET',
        'CHANTIER','ATELIER','PARCELLE','EXPLOITATION','AUTRE'
    ];

    $where=['h.host_etablissement_id=?'];
    $params=[$eid];

    if($search!==''){
        $where[]='(h.code LIKE ? OR h.nom LIKE ? OR p.nom LIKE ?)';
        $like='%'.$search.'%';
        array_push($params,$like,$like,$like);
    }

    if(in_array($type,$allowed,true)){
        $where[]='h.type=?';
        $params[]=$type;
    }

    if($validation!==''){
        $where[]='h.validation_statut=?';
        $params[]=$validation;
    }

    $s=$pdo->prepare("
        SELECT
            h.id,h.parent_id,h.code,h.nom,h.type,h.description,h.capacite,h.actif,
            h.source_template_host_unit_id,
            h.ajoute_localement,
            h.validation_statut,
            h.motif_ajout,
            h.review_comment,
            DATE_FORMAT(h.created_at,'%d/%m/%Y') date_creation,
            p.nom parent_nom,
            p.type parent_type,
            (SELECT COUNT(*)
             FROM host_units c
             WHERE c.parent_id=h.id
               AND c.host_etablissement_id=h.host_etablissement_id) nb_enfants,
            (SELECT COUNT(*)
             FROM stage_rotations sr
             WHERE sr.host_unit_id=h.id
               AND sr.statut IN('PLANIFIEE','EN_COURS')) rotations_actives
        FROM host_units h
        LEFT JOIN host_units p
          ON p.id=h.parent_id
         AND p.host_etablissement_id=h.host_etablissement_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY h.type,h.nom
    ");
    $s->execute($params);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("
        SELECT id,code,nom,type
        FROM host_units
        WHERE host_etablissement_id=?
          AND actif=1
          AND validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL')
        ORDER BY nom
    ");
    $s->execute([$eid]);
    $parents=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("
        SELECT
            COUNT(*) total,
            COALESCE(SUM(validation_statut='NATIONAL'),0) nationaux,
            COALESCE(SUM(ajoute_localement=1),0) locaux,
            COALESCE(SUM(validation_statut='EN_ATTENTE'),0) attente,
            COALESCE(SUM(actif=1),0) actifs
        FROM host_units
        WHERE host_etablissement_id=?
    ");
    $s->execute([$eid]);
    $k=$s->fetch(PDO::FETCH_ASSOC)?:[];

    jsonResponse(true,'',[
        'items'=>$items,
        'parents'=>$parents,
        'types'=>$allowed,
        'can_manage'=>hasPermission($pdo,'host.manage'),
        'kpi'=>[
            'total'=>(int)($k['total']??0),
            'nationaux'=>(int)($k['nationaux']??0),
            'locaux'=>(int)($k['locaux']??0),
            'attente'=>(int)($k['attente']??0),
            'actifs'=>(int)($k['actifs']??0)
        ]
    ]);
}catch(Throwable $e){
    error_log('[HOST UNIT LIST] '.$e->getMessage());
    jsonResponse(false,"Erreur Services / Unités d'accueil : ".$e->getMessage(),[],500);
}
