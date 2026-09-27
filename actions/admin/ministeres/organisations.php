<?php
declare(strict_types=1);
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
requireAjaxRole(['SUPER_ADMIN']);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $ministeres=$pdo->query("SELECT id,nom FROM etablissements WHERE type_etablissement='MINISTERE' AND statut IN('VALIDE','ACTIF') ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $organisations=$pdo->query("SELECT id,nom,type_etablissement,province FROM etablissements WHERE type_etablissement<>'MINISTERE' AND statut IN('VALIDE','ACTIF') ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $liens=$pdo->query("SELECT ministere_etablissement_id,organisation_etablissement_id,actif FROM organisations_ministeres")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(true,'',['ministeres'=>$ministeres,'organisations'=>$organisations,'liens'=>$liens]);
}

verifyAjaxCsrf();
$ministereId=(int)($_POST['ministere_id']??0);$ids=$_POST['organisation_ids']??[];
if(!is_array($ids))$ids=[];$ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
$s=$pdo->prepare("SELECT 1 FROM etablissements WHERE id=? AND type_etablissement='MINISTERE' LIMIT 1");$s->execute([$ministereId]);
if(!$s->fetchColumn())jsonResponse(false,'Ministère invalide.',[],422);
try{$pdo->beginTransaction();$pdo->prepare('UPDATE organisations_ministeres SET actif=0 WHERE ministere_etablissement_id=?')->execute([$ministereId]);
    if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT id FROM etablissements WHERE id IN($ph) AND type_etablissement<>'MINISTERE'");$s->execute($ids);$valid=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));sort($valid);$check=$ids;sort($check);if($valid!==$check)throw new RuntimeException('Une organisation sélectionnée est invalide.');$ins=$pdo->prepare("INSERT INTO organisations_ministeres(ministere_etablissement_id,organisation_etablissement_id,actif,rattache_par_user_id) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE actif=1,rattache_par_user_id=VALUES(rattache_par_user_id)");foreach($ids as $id)$ins->execute([$ministereId,$id,currentUserId()]);}
    $pdo->commit();jsonResponse(true,'Rattachements ministériels enregistrés.');
}catch(RuntimeException $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[ADMIN MINISTERES] '.$e->getMessage());jsonResponse(false,'Impossible d’enregistrer les rattachements.',[],500);}
