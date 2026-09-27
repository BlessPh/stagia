<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

try{
    requirePermission($pdo,'academic.view');

    $eid=(int)($_SESSION['etablissement_id']??0);
    if(!$eid)jsonResponse(false,'Aucun établissement académique actif.',[],403);

    $filiereId=(int)($_GET['filiere_id']??0);
    $cycleId=(int)($_GET['cycle_id']??0);
    $status=(string)($_GET['actif']??'');
    $origin=(string)($_GET['origin']??'');
    $search=trim((string)($_GET['search']??''));

    $s=$pdo->prepare("SELECT id,code,nom,curriculum_reference_id,preparatory_level_enabled FROM filieres WHERE etablissement_id=? AND actif=1 AND validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL') AND curriculum_reference_id IS NOT NULL ORDER BY nom");
    $s->execute([$eid]);
    $filieres=$s->fetchAll(PDO::FETCH_ASSOC);

    if(!$filieres)jsonResponse(true,'',[ 'filieres'=>[], 'selected_filiere'=>null, 'cycles'=>[], 'items'=>[], 'kpi'=>['total'=>0,'local'=>0,'national'=>0,'actifs'=>0] ]);

    if(!$filiereId)$filiereId=(int)$filieres[0]['id'];

    $selected=null;
    foreach($filieres as $f){if((int)$f['id']===$filiereId){$selected=$f;break;}}
    if(!$selected)jsonResponse(false,'Filière / programme introuvable pour cet établissement.',[],404);

    $curriculumId=(int)$selected['curriculum_reference_id'];

    $s=$pdo->prepare("SELECT id,code,libelle,ordre FROM academic_cycles WHERE curriculum_reference_id=? AND actif=1 ORDER BY ordre,libelle");
    $s->execute([$curriculumId]);
    $cycles=$s->fetchAll(PDO::FETCH_ASSOC);

    $where=['c.curriculum_reference_id=?','(l.owner_etablissement_id IS NULL OR l.owner_etablissement_id=?)'];
    $params=[$curriculumId,$eid];

    if($cycleId>0){$where[]='l.academic_cycle_id=?';$params[]=$cycleId;}
    if($status==='0'||$status==='1'){$where[]='l.actif=?';$params[]=(int)$status;}
    if($origin==='local')$where[]='l.owner_etablissement_id IS NOT NULL';
    if($origin==='national')$where[]='l.owner_etablissement_id IS NULL';
    if($search!==''){
        $where[]='(l.code LIKE ? OR l.libelle LIKE ? OR c.code LIKE ? OR c.libelle LIKE ?)';
        $like='%'.$search.'%';
        array_push($params,$like,$like,$like,$like);
    }

    $s=$pdo->prepare("
        SELECT
            l.id,l.academic_cycle_id,l.code,l.libelle,l.ordre,l.preparatoire,l.actif,l.owner_etablissement_id,
            c.code cycle_code,c.libelle cycle_libelle,c.ordre cycle_ordre,
            (SELECT COUNT(*) FROM promotions p WHERE p.etablissement_id=? AND p.academic_level_id=l.id) usage_count
        FROM academic_levels l
        JOIN academic_cycles c ON c.id=l.academic_cycle_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY c.ordre,l.ordre,l.code
    ");
    $s->execute(array_merge([$eid],$params));
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    $k=['total'=>0,'local'=>0,'national'=>0,'actifs'=>0];
    foreach($items as &$x){
        $x['local']=((int)($x['owner_etablissement_id']??0)===$eid)?1:0;
        $x['usage_count']=(int)$x['usage_count'];
        $x['preparatoire']=(int)$x['preparatoire'];
        $x['actif']=(int)$x['actif'];
        $k['total']++;
        $k[$x['local']?'local':'national']++;
        if($x['actif'])$k['actifs']++;
    }
    unset($x);

    jsonResponse(true,'',[ 'filieres'=>$filieres, 'selected_filiere'=>$selected, 'cycles'=>$cycles, 'items'=>$items, 'kpi'=>$k, 'can_manage'=>hasPermission($pdo,'academic.manage') ]);
}catch(Throwable $e){
    error_log('[NIVEAU LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur Niveaux académiques : '.$e->getMessage(),[],500);
}
