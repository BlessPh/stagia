<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/host-structure.php';

requireAjaxRole(['ADMIN_ACCUEIL']);
verifyAjaxCsrf();
if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

$eid=(int)($_SESSION['etablissement_id']??0);
$code=strtoupper(trim((string)($_POST['entity_code']??'')));
if(!$eid||!in_array($code,['DEPARTEMENT','SERVICE','UNITE'],true))jsonResponse(false,'Configuration invalide.',[],422);

$defaults=hostStructureDefaults();
$singular=trim((string)($_POST['label_singular']??''));
$plural=trim((string)($_POST['label_plural']??''));
$actif=(int)($_POST['actif']??1)===1?1:0;
$order=max(0,(int)($_POST['sort_order']??$defaults[$code]['sort_order']));
$reset=(int)($_POST['reset']??0)===1;

try{
    if($reset){
        $s=$pdo->prepare("DELETE FROM etablissement_host_structure_config WHERE etablissement_id=? AND entity_code=?");
        $s->execute([$eid,$code]);
        jsonResponse(true,'Configuration STAGIA restaurée.');
    }
    if($singular===''||$plural==='')jsonResponse(false,'Le nom singulier et pluriel sont obligatoires.',[],422);
    if(mb_strlen($singular)>100||mb_strlen($plural)>100)jsonResponse(false,'Les appellations sont limitées à 100 caractères.',[],422);

    if(!$actif){
        if($code==='DEPARTEMENT'){
            $s=$pdo->prepare("SELECT COUNT(*) FROM host_units p JOIN host_units c ON c.parent_id=p.id WHERE p.host_etablissement_id=? AND p.type='DEPARTEMENT' AND p.actif=1 AND c.host_etablissement_id=? AND c.actif=1");
            $s->execute([$eid,$eid]);
            if((int)$s->fetchColumn()>0)jsonResponse(false,'Impossible de retirer ce niveau : des structures actives en dépendent encore.',[],409);
        }
        if($code==='SERVICE'){
            $s=$pdo->prepare("SELECT COUNT(*) FROM host_units p JOIN host_units c ON c.parent_id=p.id WHERE p.host_etablissement_id=? AND p.type='SERVICE' AND p.actif=1 AND c.host_etablissement_id=? AND c.actif=1");
            $s->execute([$eid,$eid]);
            if((int)$s->fetchColumn()>0)jsonResponse(false,'Impossible de retirer ce niveau : des unités actives en dépendent encore.',[],409);
        }
    }

    $s=$pdo->prepare("INSERT INTO etablissement_host_structure_config(etablissement_id,entity_code,label_singular,label_plural,actif,sort_order,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE label_singular=VALUES(label_singular),label_plural=VALUES(label_plural),actif=VALUES(actif),sort_order=VALUES(sort_order),updated_by=VALUES(updated_by),updated_at=NOW()");
    $s->execute([$eid,$code,$singular,$plural,$actif,$order,(int)($_SESSION['user_id']??0)?:null]);
    jsonResponse(true,$actif?'Configuration enregistrée.':'Niveau retiré de la structure active.');
}catch(Throwable $e){error_log('[HOST STRUCTURE CONFIG SAVE] '.$e->getMessage());jsonResponse(false,'Impossible d’enregistrer la configuration.',[],500);}
