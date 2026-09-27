<?php
/** Charge les réglages structurels académiques d'un établissement. */
function academicSettings(PDO $pdo,int $etablissementId):array{
    $s=$pdo->prepare("SELECT * FROM etablissement_academic_settings WHERE etablissement_id=? LIMIT 1");
    $s->execute([$etablissementId]);
    return $s->fetch(PDO::FETCH_ASSOC)?:[];
}

/** Retourne les types d'unités académiques activés et libellés pour l'établissement. */
function academicUnitTypes(PDO $pdo,int $etablissementId):array{
    $cfg=academicSettings($pdo,$etablissementId);

    /* Aucun fallback : si STAGIA n'active pas le module, aucune unité. */
    if(!$cfg || !(int)($cfg['unite_academique_active']??0))return [];

    $s=$pdo->prepare("
        SELECT
            UPPER(TRIM(e.type_unite)) type_unite,
            COALESCE(
                NULLIF(TRIM(e.libelle),''),
                NULLIF(TRIM(t.libelle),''),
                UPPER(TRIM(e.type_unite))
            ) libelle
        FROM etablissement_academic_unit_types e
        JOIN academic_unit_types t
          ON t.code=UPPER(TRIM(e.type_unite))
         AND t.actif=1
        WHERE e.etablissement_id=?
          AND e.actif=1
        ORDER BY e.ordre,e.libelle,e.type_unite
    ");
    $s->execute([$etablissementId]);

    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    $out=[];

    foreach($rows as $r){
        $code=strtoupper(trim((string)$r['type_unite']));
        if($code==='')continue;
        $out[$code]=[
            'type_unite'=>$code,
            'libelle'=>$r['libelle']?:$code
        ];
    }

    return array_values($out);
}

/** Vérifie qu'un type d'unité figure dans les types activés. */
function academicUnitTypeAllowed(PDO $pdo,int $etablissementId,string $type):bool{
    $type=strtoupper(trim($type));

    foreach(academicUnitTypes($pdo,$etablissementId) as $row)
        if($row['type_unite']===$type)return true;

    return false;
}

/** Exige un type d'unité autorisé et retourne sa forme normalisée. */
function requireAcademicUnitType(PDO $pdo,int $etablissementId,string $type):string{
    $type=strtoupper(trim($type));

    if(!academicUnitTypeAllowed($pdo,$etablissementId,$type))
        throw new RuntimeException(
            "Le type d'unité « $type » n'est pas autorisé par la configuration STAGIA de cet établissement."
        );

    return $type;
}

/** Calcule le préfixe officiel ou une valeur de repli pour les codes d'unités. */
function academicUnitPrefix(PDO $pdo,string $type):string{
    $type=strtoupper(trim($type));

    $s=$pdo->prepare("
        SELECT prefixe_code
        FROM academic_unit_types
        WHERE code=?
          AND actif=1
        LIMIT 1
    ");
    $s->execute([$type]);

    $prefix=strtoupper(trim((string)$s->fetchColumn()));
    if($prefix!=='')return $prefix;

    $fallback=preg_replace('/[^A-Z0-9]/','',$type);
    return substr($fallback?:'UNA',0,6);
}

/** Génère un code d'unité unique en ajoutant un suffixe numérique en cas de collision. */
function academicUnitCode(PDO $pdo,int $id,string $type):string{
    $base=academicUnitPrefix($pdo,$type).'-'.str_pad((string)$id,4,'0',STR_PAD_LEFT);
    $code=$base;$n=2;

    $s=$pdo->prepare("SELECT 1 FROM facultes WHERE code=? AND id<>? LIMIT 1");

    while(true){
        $s->execute([$code,$id]);
        if(!$s->fetchColumn())return $code;
        $code=$base.'-'.$n++;
    }
}

/** Valide le parent demandé et interdit les auto-références ou les cycles hiérarchiques. */
function academicValidateParent(PDO $pdo,int $etablissementId,?int $parentId,?int $currentId=null):void{
    if(!$parentId)return;

    $cfg=academicSettings($pdo,$etablissementId);

    if(!(int)($cfg['unite_parentale_autorisee']??0))
        throw new RuntimeException("La configuration STAGIA de cet établissement n'autorise pas les unités imbriquées.");

    if($currentId&&$parentId===$currentId)
        throw new RuntimeException("Une unité ne peut pas être sa propre parente.");

    $s=$pdo->prepare("
        SELECT id,parent_id,actif,type_unite
        FROM facultes
        WHERE id=?
          AND etablissement_id=?
        LIMIT 1
    ");
    $s->execute([$parentId,$etablissementId]);
    $p=$s->fetch(PDO::FETCH_ASSOC);

    if(!$p)throw new RuntimeException("Unité parente introuvable.");
    if(!(int)$p['actif'])throw new RuntimeException("L'unité parente est inactive.");

    requireAcademicUnitType($pdo,$etablissementId,(string)$p['type_unite']);

    if(!$currentId)return;

    $seen=[];$cursor=$parentId;

    while($cursor){
        if(isset($seen[$cursor]))
            throw new RuntimeException("Hiérarchie académique invalide.");

        if($cursor===$currentId)
            throw new RuntimeException("Cette sélection créerait une boucle hiérarchique.");

        $seen[$cursor]=1;
        $s->execute([$cursor,$etablissementId]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        $cursor=$row?(int)($row['parent_id']??0):0;
    }
}
