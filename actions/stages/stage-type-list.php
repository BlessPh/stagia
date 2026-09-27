<?php
/**
 * Endpoint AJAX de liste des types de stage globaux et locaux visibles par l'établissement.
 * Chaque type est enrichi de son usage, de ses politiques et de sa configuration financière calculée.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-campaign.php';
require_once __DIR__.'/../../includes/stage-type.php';

try{
    /* Le helper garantit le droit de gestion et l'identité de l'établissement académique courant. */
    requireStageTypeManage($pdo);
    $eid=stageTypeEtablissementId($pdo);
    $search=trim((string)($_GET['search']??''));
    $status=strtoupper(trim((string)($_GET['status']??'')));

    /* Les types globaux sont lisibles avec les types créés localement par l'établissement. */
    $where=['(st.owner_etablissement_id IS NULL OR st.owner_etablissement_id=?)'];
    $params=[$eid];

    if($search!==''){
        $where[]='(st.code LIKE ? OR st.libelle LIKE ? OR st.description LIKE ?)';
        $like='%'.$search.'%';
        array_push($params,$like,$like,$like);
    }
    if(in_array($status,['ACTIF','INACTIF'],true)){
        $where[]='st.actif=?';
        $params[]=$status==='ACTIF'?1:0;
    }

    $s=$pdo->prepare("
        SELECT
            st.id,st.code,st.libelle,st.description,st.owner_etablissement_id,
            st.created_by_user_id,st.actif,st.created_at,
            e.nom owner_name,
            (SELECT COUNT(*) FROM stage_campaigns c WHERE c.stage_type_id=st.id) usage_count,
            (SELECT COUNT(*) FROM stage_type_policies p WHERE p.stage_type_id=st.id) policies_count
        FROM stage_types st
        LEFT JOIN etablissements e ON e.id=st.owner_etablissement_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY st.owner_etablissement_id IS NULL DESC,st.actif DESC,st.libelle,st.id
    ");
    $s->execute($params);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    /* Conversion des types JSON et calcul des attributs de provenance et politiques. */
    foreach($items as &$x){
        $x['id']=(int)$x['id'];
        $x['owner_etablissement_id']=$x['owner_etablissement_id']!==null?(int)$x['owner_etablissement_id']:null;
        $x['created_by_user_id']=$x['created_by_user_id']!==null?(int)$x['created_by_user_id']:null;
        $x['actif']=(int)$x['actif'];
        $x['usage_count']=(int)$x['usage_count'];
        $x['policies_count']=(int)$x['policies_count'];
        $x['local']=$x['owner_etablissement_id']===$eid;
        $x['legacy']=$x['owner_etablissement_id']===null;
        $x['policies']=stagePolicySnapshot($pdo,$x['id']);
        $x['financial']=stageTypeFinancialFromPolicies($x['policies'],$x['legacy']);
    }unset($x);

    /* Indicateurs limités aux types locaux de l'établissement courant. */
    $s=$pdo->prepare("
        SELECT
            COUNT(*) total,
            COALESCE(SUM(actif=1),0) actifs,
            COALESCE(SUM(actif=0),0) inactifs,
            COALESCE(SUM((SELECT COUNT(*) FROM stage_campaigns c WHERE c.stage_type_id=stage_types.id)>0),0) utilises
        FROM stage_types
        WHERE owner_etablissement_id=?
    ");
    $s->execute([$eid]);
    $k=$s->fetch(PDO::FETCH_ASSOC)?:[];

    jsonResponse(true,'',[
        'items'=>$items,
        'levels'=>stageTypeAvailableLevels($pdo,$eid),
        'kpi'=>[
            'total'=>(int)($k['total']??0),
            'actifs'=>(int)($k['actifs']??0),
            'inactifs'=>(int)($k['inactifs']??0),
            'utilises'=>(int)($k['utilises']??0)
        ]
    ]);
}catch(Throwable $e){
    jsonResponse(false,$e->getMessage(),[],403);
}
