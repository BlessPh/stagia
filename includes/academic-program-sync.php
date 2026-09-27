<?php
/**
 * STAGIA-RDC - Synchronisation des filières / programmes nationaux.
 */

function makeAcademicTemplateProgramCode(PDO $pdo,int $templateId,string $name):string{
    $raw=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)?:$name;
    $base=strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/','_',$raw),'_'));
    $base=substr($base?:'PROGRAMME',0,32);
    $code=$base;$n=2;

    while(true){
        $s=$pdo->prepare("
            SELECT COUNT(*)
            FROM academic_template_programs
            WHERE template_id=? AND code=?
        ");
        $s->execute([$templateId,$code]);

        if(!(int)$s->fetchColumn())return $code;

        $suffix='_'.($n++);
        $code=substr($base,0,40-strlen($suffix)).$suffix;
    }
}

function syncAcademicTemplateProgram(PDO $pdo,int $templateId,int $templateProgramId):void{
    $s=$pdo->prepare("
        SELECT *
        FROM academic_template_programs
        WHERE id=? AND template_id=?
        LIMIT 1
    ");
    $s->execute([$templateProgramId,$templateId]);
    $p=$s->fetch(PDO::FETCH_ASSOC);

    if(!$p)throw new RuntimeException('Programme national introuvable.');

    $templateUnitId=(int)($p['unit_id']??0);
    $templateDepartmentId=(int)($p['department_id']??0);

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
        $departementId=null;

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

            if(!$faculteId && function_exists('syncAcademicTemplateUnit')){
                syncAcademicTemplateUnit($pdo,$templateId,$templateUnitId);
                $u->execute([$eid,$templateUnitId]);
                $faculteId=(int)$u->fetchColumn()?:null;
            }
        }

        if($templateDepartmentId){
            $d=$pdo->prepare("
                SELECT id,faculte_id
                FROM departements
                WHERE etablissement_id=?
                  AND source_template_department_id=?
                LIMIT 1
            ");
            $d->execute([$eid,$templateDepartmentId]);
            $dep=$d->fetch(PDO::FETCH_ASSOC);

            if(!$dep && function_exists('syncAcademicTemplateDepartment')){
                syncAcademicTemplateDepartment($pdo,$templateId,$templateDepartmentId);
                $d->execute([$eid,$templateDepartmentId]);
                $dep=$d->fetch(PDO::FETCH_ASSOC);
            }

            if($dep){
                $departementId=(int)$dep['id'];
                $faculteId=(int)($dep['faculte_id']??0)?:$faculteId;
            }
        }

        $find=$pdo->prepare("
            SELECT id
            FROM filieres
            WHERE etablissement_id=?
              AND source_template_program_id=?
            LIMIT 1
        ");
        $find->execute([$eid,$templateProgramId]);
        $id=(int)$find->fetchColumn();

        if(!$id){
            $find=$pdo->prepare("
                SELECT id
                FROM filieres
                WHERE etablissement_id=? AND code=?
                LIMIT 1
            ");
            $find->execute([$eid,$p['code']]);
            $id=(int)$find->fetchColumn();
        }

        if($id){
            $pdo->prepare("
                UPDATE filieres
                SET
                    faculte_id=?,
                    departement_id=?,
                    curriculum_reference_id=?,
                    preparatory_level_enabled=?,
                    code=?,
                    nom=?,
                    duree_annees=?,
                    actif=?,
                    source_template_program_id=?,
                    ajoute_localement=0,
                    validation_statut='NATIONAL'
                WHERE id=? AND etablissement_id=?
            ")->execute([
                $faculteId,
                $departementId,
                $p['curriculum_reference_id'],
                (int)$p['preparatory_level_enabled'],
                $p['code'],
                $p['nom'],
                $p['duree_annees'],
                (int)$p['actif'],
                $templateProgramId,
                $id,
                $eid
            ]);
        }elseif((int)$p['actif']===1){
            $pdo->prepare("
                INSERT INTO filieres(
                    etablissement_id,
                    faculte_id,
                    departement_id,
                    source_template_program_id,
                    ajoute_localement,
                    validation_statut,
                    curriculum_reference_id,
                    preparatory_level_enabled,
                    code,
                    nom,
                    duree_annees,
                    actif
                ) VALUES(?,?,?,?,0,'NATIONAL',?,?,?,?,?,1)
            ")->execute([
                $eid,
                $faculteId,
                $departementId,
                $templateProgramId,
                $p['curriculum_reference_id'],
                (int)$p['preparatory_level_enabled'],
                $p['code'],
                $p['nom'],
                $p['duree_annees']
            ]);
        }
    }
}
