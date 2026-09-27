<?php
/** Noms académiques standards utilisés lorsqu'un établissement ne définit pas sa propre nomenclature. */
function academicDefaultLabels():array{
    return [
        'UNIT'=>['singular'=>'Unité académique','plural'=>'Unités académiques'],
        'FACULTE'=>['singular'=>'Faculté','plural'=>'Facultés'],
        'SECTION'=>['singular'=>'Section','plural'=>'Sections'],
        'ECOLE'=>['singular'=>'École','plural'=>'Écoles'],
        'INSTITUT'=>['singular'=>'Institut','plural'=>'Instituts'],
        'CENTRE'=>['singular'=>'Centre','plural'=>'Centres'],
        'DEPARTMENT'=>['singular'=>'Département','plural'=>'Départements'],
        'PROGRAM'=>['singular'=>'Filière / Programme','plural'=>'Filières / Programmes'],
        'OPTION'=>['singular'=>'Option / Spécialité','plural'=>'Options / Spécialités'],
        'PROMOTION'=>['singular'=>'Promotion','plural'=>'Promotions']
    ];
}

/** Charge puis fusionne les libellés personnalisés de l'établissement avec les valeurs standards. */
function academicLabels(PDO $pdo,int $etablissementId):array{
    $labels=academicDefaultLabels();
    foreach($labels as $code=>&$x){
        $x['is_active']=1;
        $x['parent_enabled']=0;
        $x['parent_entity_code']=null;
        $x['menu_visible']=1;
        $x['sort_order']=0;
        $x['custom']=0;
    }
    unset($x);

    if($etablissementId<=0)return $labels;

    try{
        $s=$pdo->prepare("\n            SELECT entity_code,label_singular,label_plural,\n                   is_active,parent_enabled,parent_entity_code,\n                   menu_visible,sort_order\n            FROM etablissement_academic_nomenclature\n            WHERE etablissement_id=?\n        ");
        $s->execute([$etablissementId]);
        $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    }catch(Throwable $e){
        /* Compatibilité avec le premier pack avant exécution de la migration V2. */
        try{
            $s=$pdo->prepare("\n                SELECT entity_code,label_singular,label_plural,menu_visible,sort_order\n                FROM etablissement_academic_nomenclature\n                WHERE etablissement_id=?\n            ");
            $s->execute([$etablissementId]);
            $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        }catch(Throwable $e2){
            error_log('[ACADEMIC LABELS] '.$e2->getMessage());
            return $labels;
        }
    }

    foreach($rows as $r){
        $code=strtoupper(trim((string)($r['entity_code']??'')));
        if($code==='')continue;
        if(!isset($labels[$code]))$labels[$code]=[
            'singular'=>$code,'plural'=>$code,'is_active'=>1,
            'parent_enabled'=>0,'parent_entity_code'=>null,
            'menu_visible'=>1,'sort_order'=>0,'custom'=>0
        ];

        $singular=trim((string)($r['label_singular']??''));
        $plural=trim((string)($r['label_plural']??''));
        if($singular!=='')$labels[$code]['singular']=$singular;
        if($plural!=='')$labels[$code]['plural']=$plural;

        $labels[$code]['is_active']=array_key_exists('is_active',$r)?(int)$r['is_active']:1;
        $labels[$code]['parent_enabled']=array_key_exists('parent_enabled',$r)?(int)$r['parent_enabled']:0;
        $labels[$code]['parent_entity_code']=strtoupper(trim((string)($r['parent_entity_code']??'')))?:null;
        $labels[$code]['menu_visible']=(int)($r['menu_visible']??1);
        $labels[$code]['sort_order']=(int)($r['sort_order']??0);
        $labels[$code]['custom']=1;
    }

    return $labels;
}

/** Retourne le libellé singulier ou pluriel d'une entité, avec une valeur de repli explicite. */
function academicLabelFromMap(array $labels,string $code,bool $plural=false,string $fallback=''):string{
    $code=strtoupper(trim($code));
    $key=$plural?'plural':'singular';
    $value=trim((string)($labels[$code][$key]??''));
    return $value!==''?$value:($fallback!==''?$fallback:$code);
}

/** Indique si une entité académique est activée dans la nomenclature. */
function academicEntityEnabled(array $labels,string $code,bool $default=true):bool{
    $code=strtoupper(trim($code));
    if(!isset($labels[$code])||!array_key_exists('is_active',$labels[$code]))return $default;
    return (int)$labels[$code]['is_active']===1;
}

/** Indique si une entité autorise une hiérarchie parent-enfant. */
function academicParentEnabled(array $labels,string $code,bool $default=false):bool{
    $code=strtoupper(trim($code));
    if(!isset($labels[$code])||!array_key_exists('parent_enabled',$labels[$code]))return $default;
    return (int)$labels[$code]['parent_enabled']===1;
}

/** Retourne le code de l'entité parente configurée, ou null sans parent. */
function academicParentEntity(array $labels,string $code):?string{
    $code=strtoupper(trim($code));
    $parent=strtoupper(trim((string)($labels[$code]['parent_entity_code']??'')));
    return $parent!==''?$parent:null;
}
