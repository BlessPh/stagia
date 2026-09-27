<?php
/**
 * STAGIA-RDC
 * Synchronise une structure d'accueil nationale vers les établissements
 * du même type déjà présents dans la plateforme.
 *
 * Important :
 * - nom/type/parent/statut suivent le référentiel ;
 * - la capacité d'accueil est STRICTEMENT LOCALE à l'établissement ;
 * - le Super Admin ne définit ni ne propage aucune capacité.
 */

function nextHostTemplateCode(PDO $pdo,string $typeCode,string $unitType):string{
    $prefixes=[
        'DEPARTEMENT'=>'DEP','SERVICE'=>'SRV','UNITE'=>'UNI',
        'LABORATOIRE'=>'LAB','PROJET'=>'PRJ','CHANTIER'=>'CHN',
        'ATELIER'=>'ATL','PARCELLE'=>'PAR','EXPLOITATION'=>'EXP',
        'AUTRE'=>'AUT'
    ];

    $prefix=$prefixes[$unitType]??'AUT';

    $s=$pdo->prepare("
        SELECT code
        FROM host_unit_templates
        WHERE establishment_type_code=?
          AND code LIKE ?
    ");
    $s->execute([$typeCode,$prefix.'-%']);
    $used=array_flip($s->fetchAll(PDO::FETCH_COLUMN));

    for($n=1;$n<=999999;$n++){
        $code=$prefix.'-'.str_pad((string)$n,4,'0',STR_PAD_LEFT);
        if(!isset($used[$code]))return $code;
    }

    throw new RuntimeException('Impossible de générer un code.');
}

function syncHostUnitTemplate(PDO $pdo,int $templateId):void{
    $s=$pdo->prepare("
        SELECT *
        FROM host_unit_templates
        WHERE id=?
        LIMIT 1
    ");
    $s->execute([$templateId]);
    $tpl=$s->fetch(PDO::FETCH_ASSOC);

    if(!$tpl)throw new RuntimeException("Structure d'accueil nationale introuvable.");

    $typeCode=$tpl['establishment_type_code'];
    $parentTemplateId=(int)($tpl['parent_id']??0);

    if($parentTemplateId){
        syncHostUnitTemplate($pdo,$parentTemplateId);
    }

    $s=$pdo->prepare("
        SELECT id
        FROM etablissements
        WHERE type_etablissement=?
          AND statut='VALIDE'
    ");
    $s->execute([$typeCode]);
    $establishments=$s->fetchAll(PDO::FETCH_COLUMN);

    foreach($establishments as $eidRaw){
        $eid=(int)$eidRaw;
        $parentRealId=null;

        if($parentTemplateId){
            $p=$pdo->prepare("
                SELECT id
                FROM host_units
                WHERE host_etablissement_id=?
                  AND source_template_host_unit_id=?
                LIMIT 1
            ");
            $p->execute([$eid,$parentTemplateId]);
            $parentRealId=(int)$p->fetchColumn()?:null;
        }

        $find=$pdo->prepare("
            SELECT id
            FROM host_units
            WHERE host_etablissement_id=?
              AND source_template_host_unit_id=?
            LIMIT 1
        ");
        $find->execute([$eid,$templateId]);
        $id=(int)$find->fetchColumn();

        if(!$id){
            $find=$pdo->prepare("
                SELECT id
                FROM host_units
                WHERE host_etablissement_id=?
                  AND code=?
                LIMIT 1
            ");
            $find->execute([$eid,$tpl['code']]);
            $id=(int)$find->fetchColumn();
        }

        if($id){
            $pdo->prepare("
                UPDATE host_units
                SET
                    parent_id=?,
                    code=?,
                    nom=?,
                    type=?,
                    actif=?,
                    source_template_host_unit_id=?,
                    ajoute_localement=0,
                    validation_statut='NATIONAL'
                WHERE id=?
                  AND host_etablissement_id=?
            ")->execute([
                $parentRealId,
                $tpl['code'],
                $tpl['nom'],
                $tpl['type'],
                (int)$tpl['actif'],
                $templateId,
                $id,
                $eid
            ]);
        }elseif((int)$tpl['actif']===1){
            $pdo->prepare("
                INSERT INTO host_units(
                    host_etablissement_id,
                    parent_id,
                    source_template_host_unit_id,
                    ajoute_localement,
                    validation_statut,
                    code,
                    nom,
                    type,
                    description,
                    capacite,
                    actif
                ) VALUES(?,?,?,0,'NATIONAL',?,?,?,?,?,1)
            ")->execute([
                $eid,
                $parentRealId,
                $templateId,
                $tpl['code'],
                $tpl['nom'],
                $tpl['type'],
                $tpl['description'],
                $tpl['capacite']
            ]);
        }
    }
}
