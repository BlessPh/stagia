<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

try{
    $ctx=exigerAdministrationRolesAjax($pdo);
    if(function_exists('contextPermission')&&!contextPermission('user.role.assign'))
        jsonResponse(false,'Permission insuffisante.',[],403);

    $eid=$ctx['super']?(int)($_GET['etablissement_id']??0):(int)$ctx['etablissement_id'];
    if(!$eid)jsonResponse(false,'Établissement introuvable.',[],422);

    $s=$pdo->prepare("
        SELECT id,code,nom,type,parent_id
        FROM host_units
        WHERE host_etablissement_id=? AND actif=1
        ORDER BY COALESCE(parent_id,0),nom
    ");
    $s->execute([$eid]);

    $deps=[];
    $services=[];

    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $u){
        $t=strtoupper(trim((string)$u['type']));
        $x=[
            'id'=>(int)$u['id'],
            'code'=>$u['code']??'',
            'nom'=>$u['nom']??'',
            'type'=>$u['type']??'',
            'parent_id'=>(int)($u['parent_id']??0),
            'label'=>trim(($u['code']??'').' - '.($u['nom']??''),' -')
        ];

        if(in_array($t,['DEPARTEMENT','DÉPARTEMENT','DEPARTMENT','COORDINATION','DIRECTION'],true))
            $deps[]=$x;

        if(in_array($t,['SERVICE','UNITE','UNITÉ'],true))
            $services[]=$x;
    }

    jsonResponse(true,'',['departements'=>$deps,'services'=>$services]);
}catch(Throwable $e){
    jsonResponse(false,$e->getMessage(),[],500);
}