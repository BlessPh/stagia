<?php
/**
 * Synchronise une option nationale du modèle vers les établissements
 * qui utilisent déjà ce modèle.
 */
function syncAcademicTemplateOption(PDO $pdo,int $templateId,int $optionId):void{
    $s=$pdo->prepare("
        SELECT o.id,o.code,o.nom,o.actif,p.id program_id,p.code program_code
        FROM academic_template_options o
        JOIN academic_template_programs p ON p.id=o.program_id
        WHERE o.id=? AND o.template_id=? LIMIT 1
    ");
    $s->execute([$optionId,$templateId]);
    $option=$s->fetch(PDO::FETCH_ASSOC);
    if(!$option)throw new RuntimeException('Option nationale introuvable.');

    $s=$pdo->prepare("
        SELECT eas.etablissement_id,f.id filiere_id
        FROM etablissement_academic_settings eas
        JOIN filieres f
          ON f.etablissement_id=eas.etablissement_id
         AND (
                f.source_template_program_id=?
                OR (
                    f.source_template_program_id IS NULL
                    AND f.code=?
                )
             )
        WHERE eas.source_template_id=?
    ");
    $s->execute([$option['program_id'],$option['program_code'],$templateId]);
    $targets=$s->fetchAll(PDO::FETCH_ASSOC);

    $findSource=$pdo->prepare("
        SELECT id
        FROM options_specialites
        WHERE etablissement_id=? AND source_template_option_id=?
        LIMIT 1
    ");
    $findCode=$pdo->prepare("
        SELECT id,ajoute_localement,validation_statut
        FROM options_specialites
        WHERE etablissement_id=? AND filiere_id=? AND code=?
        LIMIT 1
    ");
    $update=$pdo->prepare("
        UPDATE options_specialites
        SET filiere_id=?,nom=?,actif=?,
            source_template_option_id=?,
            validation_statut=CASE
                WHEN ajoute_localement=1 THEN 'INTEGRE_REFERENTIEL'
                ELSE 'NATIONAL'
            END
        WHERE id=?
    ");
    $insert=$pdo->prepare("
        INSERT INTO options_specialites(
            etablissement_id,filiere_id,source_template_option_id,
            ajoute_localement,validation_statut,code,nom,actif
        ) VALUES(?,?,?,0,'NATIONAL',?,?,?)
    ");

    foreach($targets as $t){
        $etab=(int)$t['etablissement_id'];
        $filiere=(int)$t['filiere_id'];

        $findSource->execute([$etab,$optionId]);
        $id=(int)$findSource->fetchColumn();

        if(!$id){
            $findCode->execute([$etab,$filiere,$option['code']]);
            $existing=$findCode->fetch(PDO::FETCH_ASSOC);
            if($existing)$id=(int)$existing['id'];
        }

        if($id){
            $update->execute([
                $filiere,$option['nom'],(int)$option['actif'],
                $optionId,$id
            ]);
        }else{
            $insert->execute([
                $etab,$filiere,$optionId,
                $option['code'],$option['nom'],(int)$option['actif']
            ]);
        }
    }
}

function makeAcademicOptionCode(string $value):string{
    $raw=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value;
    $code=strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/','_',$raw),'_'));
    return substr($code?:'OPTION',0,40);
}
