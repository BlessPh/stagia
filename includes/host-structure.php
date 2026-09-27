<?php
function hostStructureDefaults():array{
    return [
        'DEPARTEMENT'=>['label_singular'=>'Département','label_plural'=>'Départements','actif'=>1,'sort_order'=>10],
        'SERVICE'=>['label_singular'=>'Service','label_plural'=>'Services','actif'=>1,'sort_order'=>20],
        'UNITE'=>['label_singular'=>'Unité','label_plural'=>'Unités','actif'=>1,'sort_order'=>30]
    ];
}

function hostStructureConfig(PDO $pdo,int $eid):array{
    $cfg=hostStructureDefaults();
    if($eid<=0)return $cfg;
    try{
        $s=$pdo->prepare("SELECT entity_code,label_singular,label_plural,actif,sort_order FROM etablissement_host_structure_config WHERE etablissement_id=?");
        $s->execute([$eid]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
            $code=strtoupper(trim((string)($r['entity_code']??'')));
            if(!isset($cfg[$code]))continue;
            if(trim((string)$r['label_singular'])!=='')$cfg[$code]['label_singular']=trim((string)$r['label_singular']);
            if(trim((string)$r['label_plural'])!=='')$cfg[$code]['label_plural']=trim((string)$r['label_plural']);
            $cfg[$code]['actif']=(int)$r['actif'];
            $cfg[$code]['sort_order']=(int)$r['sort_order'];
            $cfg[$code]['custom']=1;
        }
    }catch(Throwable $e){error_log('[HOST STRUCTURE] '.$e->getMessage());}
    uasort($cfg,fn($a,$b)=>$a['sort_order']<=>$b['sort_order']);
    return $cfg;
}

function hostStructureLabel(array $cfg,string $code,bool $plural=false,string $fallback=''):string{
    $code=strtoupper(trim($code));$key=$plural?'label_plural':'label_singular';
    $v=trim((string)($cfg[$code][$key]??''));
    return $v!==''?$v:($fallback!==''?$fallback:$code);
}

function hostStructureEnabled(array $cfg,string $code):bool{
    return !empty($cfg[strtoupper(trim($code))]['actif']);
}

function hostStructureAllowedParentTypes(string $type,array $cfg):array{
    $type=strtoupper(trim($type));
    if($type==='DEPARTEMENT')return [];
    if($type==='SERVICE')return hostStructureEnabled($cfg,'DEPARTEMENT')?['DEPARTEMENT']:[];
    if($type==='UNITE'){
        $r=[];
        if(hostStructureEnabled($cfg,'SERVICE'))$r[]='SERVICE';
        if(hostStructureEnabled($cfg,'DEPARTEMENT'))$r[]='DEPARTEMENT';
        return $r;
    }
    return [];
}
