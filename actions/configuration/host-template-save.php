<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/host-unit-template-sync.php';

requireAjaxRole(['SUPER_ADMIN']);
verifyAjaxCsrf();

$typeCode=strtoupper(trim((string)($_POST['establishment_type_code']??'')));
$id=(int)($_POST['id']??0);
$unitType=strtoupper(trim((string)($_POST['type']??'SERVICE')));
$nom=trim((string)($_POST['nom']??''));
$parentId=(int)($_POST['parent_id']??0)?:null;
$description=trim((string)($_POST['description']??''));
$ordre=(int)($_POST['ordre']??0);

$allowed=[
    'DEPARTEMENT','SERVICE','UNITE','LABORATOIRE','PROJET',
    'CHANTIER','ATELIER','PARCELLE','EXPLOITATION','AUTRE'
];

if($typeCode===''||$nom===''||!in_array($unitType,$allowed,true))
    jsonResponse(false,'Type et nom obligatoires.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT 1
        FROM establishment_types
        WHERE code=?
          AND host_enabled=1
          AND actif=1
        LIMIT 1
    ");
    $s->execute([$typeCode]);

    if(!$s->fetchColumn())
        throw new RuntimeException("Ce type d'établissement n'est pas une structure d'accueil active.");

    if($parentId){
        if($id && $parentId===$id)
            throw new RuntimeException('Un élément ne peut pas être son propre parent.');

        $s=$pdo->prepare("
            SELECT id,parent_id
            FROM host_unit_templates
            WHERE id=?
              AND establishment_type_code=?
              AND actif=1
            LIMIT 1
        ");

        $cursor=$parentId;
        $seen=[];

        while($cursor){
            if($id && $cursor===$id)
                throw new RuntimeException('Cette sélection créerait une boucle hiérarchique.');

            if(isset($seen[$cursor]))
                throw new RuntimeException('Hiérarchie invalide.');

            $seen[$cursor]=1;

            $s->execute([$cursor,$typeCode]);
            $r=$s->fetch(PDO::FETCH_ASSOC);

            if(!$r)throw new RuntimeException('Parent invalide.');

            $cursor=(int)($r['parent_id']??0);
        }
    }

    if($id){
        $s=$pdo->prepare("
            SELECT code
            FROM host_unit_templates
            WHERE id=?
              AND establishment_type_code=?
            LIMIT 1
        ");
        $s->execute([$id,$typeCode]);
        $code=$s->fetchColumn();

        if(!$code)throw new RuntimeException('Élément national introuvable.');

        $pdo->prepare("
            UPDATE host_unit_templates
            SET
                parent_id=?,
                nom=?,
                type=?,
                description=?,
                capacite=NULL,
                ordre=?,
                actif=1
            WHERE id=?
              AND establishment_type_code=?
        ")->execute([
            $parentId,
            $nom,
            $unitType,
            $description?:null,
            $ordre,
            $id,
            $typeCode
        ]);

        syncHostUnitTemplate($pdo,$id);
    }else{
        $code=nextHostTemplateCode($pdo,$typeCode,$unitType);

        $pdo->prepare("
            INSERT INTO host_unit_templates(
                establishment_type_code,
                parent_id,
                code,
                nom,
                type,
                description,
                capacite,
                ordre,
                actif
            ) VALUES(?,?,?,?,?,NULL,?,1)
        ")->execute([
            $typeCode,
            $parentId,
            $code,
            $nom,
            $unitType,
            $description?:null,
            $ordre
        ]);

        $id=(int)$pdo->lastInsertId();
        syncHostUnitTemplate($pdo,$id);
    }

    $pdo->commit();
    jsonResponse(true,"Service / unité national enregistré et synchronisé.");
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
