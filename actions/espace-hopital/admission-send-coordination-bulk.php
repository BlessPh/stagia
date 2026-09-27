<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);
verifyAjaxCsrf();

function stgBulkIds($value):array{
    if(is_string($value)){
        $decoded=json_decode($value,true);
        if(is_array($decoded))$value=$decoded;
        else $value=preg_split('/[,;\s]+/',$value,-1,PREG_SPLIT_NO_EMPTY);
    }
    if(!is_array($value))return [];
    $ids=[];
    foreach($value as $v){$i=(int)$v;if($i>0)$ids[$i]=$i;}
    return array_values($ids);
}

try{
    $hostId=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0)?:null;
    $coordId=(int)($_POST['coordination_unit_id']??0);
    $ids=stgBulkIds($_POST['admission_ids']??($_POST['admission_ids[]']??[]));

    if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);
    if(!$coordId)jsonResponse(false,'Coordination ou département invalide.',[],422);
    if(!$ids)jsonResponse(false,'Aucun stagiaire sélectionné.',[],422);
    if(count($ids)>2000)jsonResponse(false,'Maximum 2000 admissions par opération.',[],422);

    $s=$pdo->prepare("\n        SELECT id,nom,type\n        FROM host_units\n        WHERE id=? AND host_etablissement_id=? AND actif=1\n          AND (parent_id IS NULL OR parent_id=0)\n          AND UPPER(type) IN('COORDINATION','DEPARTEMENT','DÉPARTEMENT','DEPARTMENT','DIRECTION','UNITE','UNITÉ')\n        LIMIT 1\n    ");
    $s->execute([$coordId,$hostId]);
    $coord=$s->fetch(PDO::FETCH_ASSOC);
    if(!$coord)jsonResponse(false,'Coordination ou département invalide.',[],422);

    $pdo->beginTransaction();

    $in=implode(',',array_fill(0,count($ids),'?'));
    $params=array_merge([$hostId],$ids);

    $s=$pdo->prepare("\n        SELECT ad.id,ad.statut,ad.coordination_unit_id\n        FROM stage_admissions ad\n        WHERE ad.host_etablissement_id=?\n          AND ad.id IN($in)\n        FOR UPDATE\n    ");
    $s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);

    $eligible=[];
    foreach($rows as $r){
        $status=strtoupper((string)$r['statut']);
        if(in_array($status,['ADMIS','EN_COURS'],true) && empty($r['coordination_unit_id']))
            $eligible[]=(int)$r['id'];
    }

    if($eligible){
        $in2=implode(',',array_fill(0,count($eligible),'?'));
        $up=$pdo->prepare("\n            UPDATE stage_admissions\n            SET coordination_unit_id=?,coordination_sent_at=NOW(),coordination_sent_by=?\n            WHERE host_etablissement_id=?\n              AND statut IN('ADMIS','EN_COURS')\n              AND (coordination_unit_id IS NULL OR coordination_unit_id=0)\n              AND id IN($in2)\n        ");
        $up->execute(array_merge([$coordId,$uid,$hostId],$eligible));
        $updated=(int)$up->rowCount();

        try{
            $has=$pdo->query("SHOW TABLES LIKE 'stage_admission_history'")->fetchColumn();
            if($has){
                $h=$pdo->prepare("\n                    INSERT INTO stage_admission_history(admission_id,event_code,previous_status,new_status,details,actor_user_id,created_at)\n                    VALUES(?,'SENT_TO_COORDINATION',?, ?, ?, ?, NOW())\n                ");
                $details=json_encode(['coordination_unit_id'=>$coordId,'coordination_name'=>$coord['nom']],JSON_UNESCAPED_UNICODE);
                foreach($rows as $r){
                    if(in_array((int)$r['id'],$eligible,true))
                        $h->execute([(int)$r['id'],(string)$r['statut'],(string)$r['statut'],$details,$uid]);
                }
            }
        }catch(Throwable $e){error_log('[ADMISSION BULK COORD HISTORY] '.$e->getMessage());}
    }else{
        $updated=0;
    }

    $pdo->commit();
    $ignored=count($ids)-$updated;
    jsonResponse(true,$updated.' stagiaire(s) envoyé(s) vers « '.$coord['nom'].' ». '.$ignored.' ignoré(s).',[ 'updated'=>$updated, 'ignored'=>$ignored, 'coordination'=>['id'=>(int)$coord['id'],'nom'=>$coord['nom']] ]);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[ADMISSION SEND COORDINATION BULK] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur envoi coordination : '.$e->getMessage(),[],422);
}
