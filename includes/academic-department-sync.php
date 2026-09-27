<?php
/**
 * STAGIA-RDC - Synchronisation des départements nationaux.
 */

function makeAcademicTemplateDepartmentCode(PDO $pdo,int $templateId,string $name):string{
    $raw=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)?:$name;
    $base=strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/','_',$raw),'_'));
    $base=substr($base?:'DEPARTEMENT',0,32);
    $code=$base;$n=2;

    while(true){
        $s=$pdo->prepare("
            SELECT COUNT(*)
            FROM academic_template_departments
            WHERE template_id=? AND code=?
        ");
        $s->execute([$templateId,$code]);
        if(!(int)$s->fetchColumn())return $code;

        $suffix='_'.($n++);
        $code=substr($base,0,40-strlen($suffix)).$suffix;
    }
}

function syncAcademicTemplateDepartment(PDO $pdo,int $templateId,int $templateDepartmentId):void{
    $s=$pdo->prepare("
        SELECT *
        FROM academic_template_departments
        WHERE id=? AND template_id=?
        LIMIT 1
    ");
    $s->execute([$templateDepartmentId,$templateId]);
    $dep=$s->fetch(PDO::FETCH_ASSOC);

    if(!$dep)throw new RuntimeException('Département national introuvable.');

    $templateUnitId=(int)($dep['unit_id']??0);

    $s=$pdo->prepare("
        SELECT etablissement_id
        FROM etablissement_academic_settings
        WHERE source_template_id=?
    ");
    $s->execute([$templateId]);
    $etablissements=$s->fetchAll(PDO::FETCH_COLUMN);

    foreach($etablissements as $eidRaw){
        $eid=(int)$eidRaw;
        $faculteId=null;

        if($templateUnitId){
            $u=$pdo->prepare("
                SELECT id
                FROM facultes
                WHERE etablissement_id=?
                  AND source_template_unit_id=?
                LIMIT 1
            ");
            $u->execute([$eid,$templateUnitId]);
            $faculteId=(int)$u->fetchColumn()?:null;

            if(!$faculteId){
                // L'unité nationale doit être synchronisée avant le département.
                if(function_exists('syncAcademicTemplateUnit')){
                    syncAcademicTemplateUnit($pdo,$templateId,$templateUnitId);
                    $u->execute([$eid,$templateUnitId]);
                    $faculteId=(int)$u->fetchColumn()?:null;
                }
            }
        }

        $find=$pdo->prepare("
            SELECT id
            FROM departements
            WHERE etablissement_id=?
              AND source_template_department_id=?
            LIMIT 1
        ");
        $find->execute([$eid,$templateDepartmentId]);
        $id=(int)$find->fetchColumn();

        if(!$id){
            $find=$pdo->prepare("
                SELECT id
                FROM departements
                WHERE etablissement_id=? AND code=?
                LIMIT 1
            ");
            $find->execute([$eid,$dep['code']]);
            $id=(int)$find->fetchColumn();
        }

        if($id){
            $pdo->prepare("
                UPDATE departements
                SET
                    faculte_id=?,
                    code=?,
                    nom=?,
                    actif=?,
                    source_template_department_id=?,
                    ajoute_localement=0,
                    validation_statut='NATIONAL'
                WHERE id=? AND etablissement_id=?
            ")->execute([
                $faculteId,
                $dep['code'],
                $dep['nom'],
                (int)$dep['actif'],
                $templateDepartmentId,
                $id,
                $eid
            ]);
        }elseif((int)$dep['actif']===1){
            $pdo->prepare("
                INSERT INTO departements(
                    etablissement_id,
                    faculte_id,
                    source_template_department_id,
                    ajoute_localement,
                    validation_statut,
                    code,
                    nom,
                    actif
                ) VALUES(?,?,?,0,'NATIONAL',?,?,1)
            ")->execute([
                $eid,
                $faculteId,
                $templateDepartmentId,
                $dep['code'],
                $dep['nom']
            ]);
        }
    }
}
