<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-structure.php';

try{
    requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

    $eid=currentEtablissementId($pdo);
    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);

    $cfg=academicSettings($pdo,$eid);
    if(!$cfg || !(int)$cfg['unite_academique_active'])
        jsonResponse(false,"Le module Unités académiques n'est pas activé.",[],403);

    $page=max(1,(int)($_GET['page']??1));
    $per=min(100,max(5,(int)($_GET['per_page']??10)));
    $search=trim((string)($_GET['search']??''));
    $statut=(string)($_GET['statut']??'');
    $type=trim((string)($_GET['type']??''));

    $where='WHERE f.etablissement_id=?';
    $params=[$eid];

    if($search!==''){
        $where.=" AND (f.code LIKE ? OR f.nom LIKE ? OR f.type_unite LIKE ?)";
        $like="%$search%";
        array_push($params,$like,$like,$like);
    }
    if($statut==='0'||$statut==='1'){
        $where.=" AND f.actif=?";
        $params[]=(int)$statut;
    }
    if($type!=='' && academicUnitTypeAllowed($pdo,$eid,$type)){
        $where.=" AND f.type_unite=?";
        $params[]=$type;
    }

    $s=$pdo->prepare("SELECT COUNT(*) FROM facultes f $where");
    $s->execute($params);
    $total=(int)$s->fetchColumn();

    $pages=max(1,(int)ceil($total/$per));
    $page=min($page,$pages);
    $offset=($page-1)*$per;

    $s=$pdo->prepare("
        SELECT
            f.id,f.code,f.nom,f.type_unite,f.parent_id,f.actif,
            p.nom parent_nom,
            f.source_template_unit_id,
            f.ajoute_localement,
            f.validation_statut,
            f.motif_ajout,
            f.review_comment,
            DATE_FORMAT(f.created_at,'%d/%m/%Y') date_creation,
            (
                SELECT COUNT(*)
                FROM departements d
                WHERE d.faculte_id=f.id
                  AND d.etablissement_id=f.etablissement_id
            ) nb_departements,
            (
                SELECT COUNT(*)
                FROM facultes c
                WHERE c.parent_id=f.id
                  AND c.etablissement_id=f.etablissement_id
            ) nb_enfants
        FROM facultes f
        LEFT JOIN facultes p ON p.id=f.parent_id
        $where
        ORDER BY f.type_unite,f.nom
        LIMIT $per OFFSET $offset
    ");
    $s->execute($params);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("
        SELECT
            COUNT(*) total,
            COALESCE(SUM(actif=1),0) actifs,
            COALESCE(SUM(actif=0),0) inactifs
        FROM facultes
        WHERE etablissement_id=?
    ");
    $s->execute([$eid]);
    $stats=$s->fetch(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("SELECT COUNT(*) FROM departements WHERE etablissement_id=?");
    $s->execute([$eid]);
    $departements=(int)$s->fetchColumn();

    $s=$pdo->prepare("
        SELECT id,nom,type_unite,actif,validation_statut
        FROM facultes
        WHERE etablissement_id=? AND actif=1
        ORDER BY nom
    ");
    $s->execute([$eid]);
    $parents=$s->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>[
            'total'=>(int)($stats['total']??0),
            'actifs'=>(int)($stats['actifs']??0),
            'inactifs'=>(int)($stats['inactifs']??0),
            'departements'=>$departements
        ],
        'form'=>[
            'types'=>academicUnitTypes($pdo,$eid),
            'parents'=>$parents,
            'parent_allowed'=>(bool)$cfg['unite_parentale_autorisee']
        ],
        'pagination'=>[
            'page'=>$page,
            'per_page'=>$per,
            'total'=>$total,
            'pages'=>$pages,
            'from'=>$total?$offset+1:0,
            'to'=>min($offset+$per,$total)
        ]
    ]);
}catch(Throwable $e){
    error_log('[FACULTE LIST] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(
        false,
        'Erreur Unités académiques : '.$e->getMessage(),
        [],
        500
    );
}
