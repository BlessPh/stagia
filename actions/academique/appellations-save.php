<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-structure.php';
require_once __DIR__.'/../../includes/academic-labels.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

if(!hasPermission($pdo,'academic.manage'))jsonResponse(false,'Permission insuffisante.',[],403);
if(!contextAcademicEnabled())jsonResponse(false,"Cet établissement n'a pas de structure académique.",[],403);

$eid=currentEtablissementId($pdo);
if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);

$code=strtoupper(trim((string)($_POST['entity_code']??'')));
$reset=(int)($_POST['reset']??0)===1;
$toggle=isset($_POST['toggle_active']);
$cfg=academicSettings($pdo,$eid);
$allowed=[];

try{
    foreach(academicUnitTypes($pdo,$eid) as $t){
        $c=strtoupper(trim((string)($t['type_unite']??'')));
        if($c!=='')$allowed[$c]=true;
    }
}catch(Throwable $e){}

if(!empty($cfg['unite_academique_active']))$allowed['UNIT']=true;
if(!empty($cfg['departement_active']))$allowed['DEPARTMENT']=true;
if(!empty($cfg['filiere_active']))$allowed['PROGRAM']=true;
if(!empty($cfg['option_specialite_active']))$allowed['OPTION']=true;
if(!empty($cfg['promotion_active']))$allowed['PROMOTION']=true;

if($code===''||!isset($allowed[$code]))
    jsonResponse(false,'Niveau académique non autorisé pour cet établissement.',[],422);

$defaults=academicDefaultLabels();
$current=academicLabels($pdo,$eid);
$defaultSingular=$defaults[$code]['singular']??$code;
$defaultPlural=$defaults[$code]['plural']??$code;
$currentSingular=academicLabelFromMap($current,$code,false,$defaultSingular);
$currentPlural=academicLabelFromMap($current,$code,true,$defaultPlural);
$currentParentEnabled=academicParentEnabled($current,$code,false);
$currentParent=academicParentEntity($current,$code);

try{
    if($reset){
        $s=$pdo->prepare("DELETE FROM etablissement_academic_nomenclature WHERE etablissement_id=? AND entity_code=?");
        $s->execute([$eid,$code]);
        jsonResponse(true,'Configuration STAGIA restaurée.',['entity_code'=>$code]);
    }

    if($toggle){
        $active=(int)(($_POST['is_active']??'1')==='1');

        if(!$active){
            $s=$pdo->prepare("\n                SELECT entity_code\n                FROM etablissement_academic_nomenclature\n                WHERE etablissement_id=?\n                  AND is_active=1\n                  AND parent_enabled=1\n                  AND parent_entity_code=?\n                LIMIT 1\n            ");
            $s->execute([$eid,$code]);
            $child=(string)$s->fetchColumn();
            if($child!=='')
                jsonResponse(false,"Ce niveau est encore utilisé comme parent par $child. Modifiez d'abord ce rattachement.",[],409);
        }

        $s=$pdo->prepare("\n            INSERT INTO etablissement_academic_nomenclature(\n                etablissement_id,entity_code,label_singular,label_plural,\n                is_active,parent_enabled,parent_entity_code,menu_visible,sort_order,\n                created_by,created_at,updated_at\n            ) VALUES(?,?,?,?,?,?,?,?,0,?,NOW(),NOW())\n            ON DUPLICATE KEY UPDATE\n                is_active=VALUES(is_active),\n                menu_visible=VALUES(menu_visible),\n                updated_at=NOW()\n        ");
        $s->execute([
            $eid,$code,$currentSingular,$currentPlural,$active,
            $currentParentEnabled?1:0,$currentParent,$active,
            (int)($_SESSION['user_id']??0)?:null
        ]);

        jsonResponse(true,$active?'Niveau réactivé dans votre structure.':'Niveau retiré de votre structure. Les données existantes ne sont pas supprimées.',['is_active'=>$active]);
    }

    $singular=trim((string)($_POST['label_singular']??''));
    $plural=trim((string)($_POST['label_plural']??''));
    $active=(int)(($_POST['is_active']??'1')==='1');
    $parentEnabled=(int)(($_POST['parent_enabled']??'0')==='1');
    $parentCode=strtoupper(trim((string)($_POST['parent_entity_code']??'')));

    if($singular===''||$plural==='')jsonResponse(false,'Le nom au singulier et au pluriel sont obligatoires.',[],422);
    if(mb_strlen($singular)>100||mb_strlen($plural)>100)jsonResponse(false,'Les appellations sont limitées à 100 caractères.',[],422);

    if($parentEnabled){
        if($parentCode===''||$parentCode===$code||!isset($allowed[$parentCode]))
            jsonResponse(false,'Choisissez un type de parent valide.',[],422);
    }else $parentCode=null;

    $s=$pdo->prepare("\n        INSERT INTO etablissement_academic_nomenclature(\n            etablissement_id,entity_code,label_singular,label_plural,\n            is_active,parent_enabled,parent_entity_code,menu_visible,sort_order,\n            created_by,created_at,updated_at\n        ) VALUES(?,?,?,?,?,?,?,?,0,?,NOW(),NOW())\n        ON DUPLICATE KEY UPDATE\n            label_singular=VALUES(label_singular),\n            label_plural=VALUES(label_plural),\n            is_active=VALUES(is_active),\n            parent_enabled=VALUES(parent_enabled),\n            parent_entity_code=VALUES(parent_entity_code),\n            menu_visible=VALUES(menu_visible),\n            created_by=VALUES(created_by),\n            updated_at=NOW()\n    ");
    $s->execute([
        $eid,$code,$singular,$plural,$active,$parentEnabled,
        $parentCode,$active,(int)($_SESSION['user_id']??0)?:null
    ]);

    jsonResponse(true,'Configuration académique enregistrée.',['entity_code'=>$code]);
}catch(Throwable $e){
    error_log('[ACADEMIC NOMENCLATURE SAVE] '.$e->getMessage());
    jsonResponse(false,'Impossible d’enregistrer la configuration : '.$e->getMessage(),[],500);
}
