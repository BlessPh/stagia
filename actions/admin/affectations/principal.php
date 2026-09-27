<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$adminContexte=exigerAdministrationRolesAjax($pdo);verifyAjaxCsrf();
if(function_exists('contextPermission')&&!contextPermission('user.role.assign'))jsonResponse(false,'Permission insuffisante.',[],403);

$actor=$_SESSION['role_code']??'';$super=$adminContexte['super'];$eid=$super?0:(int)$adminContexte['etablissement_id'];$id=(int)($_POST['id']??0);
if(!$super&&!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);
if(!$id)jsonResponse(false,'Affectation invalide.',[],422);

try{
    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT ra.*,r.code role_code,r.systeme,r.etablissement_id role_etablissement_id FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.id=? AND ra.actif=1 LIMIT 1 FOR UPDATE");$s->execute([$id]);$a=$s->fetch(PDO::FETCH_ASSOC);
    if(!$a)throw new RuntimeException('Affectation active introuvable.');

    if($a['role_code']==='MINISTERE'){
        $ministereId=(int)($a['etablissement_id']??0);
        if($a['scope_type']!=='ORGANIZATION'||$a['scope_entity']!=='ESTABLISHMENT'||!$ministereId||(int)($a['scope_id']??0)!==$ministereId)
            throw new RuntimeException('Cette affectation ministérielle n’est pas rattachée correctement à un ministère.');
        $s=$pdo->prepare("SELECT 1 FROM etablissements e JOIN etablissement_users eu ON eu.etablissement_id=e.id AND eu.user_id=? WHERE e.id=? AND e.type_etablissement='MINISTERE' AND e.statut IN('VALIDE','ACTIF') LIMIT 1");
        $s->execute([$a['user_id'],$ministereId]);
        if(!$s->fetchColumn())throw new RuntimeException('Le responsable doit d’abord être rattaché à un ministère actif.');
    }

    if(!$super&&in_array($a['role_code'],['CHEF_SERVICE','EVALUATEUR_CLINIQUE'],true))throw new RuntimeException('Ce rôle clinique est contextuel à une unité. Conservez ENCADREUR comme rôle principal.');

    if(!$super){
        $ok=(int)$a['etablissement_id']===$eid;
        if($actor==='ADMIN_ACCUEIL')$ok=$ok&&(
            ((int)$a['systeme']===0&&(int)$a['role_etablissement_id']===$eid&&$a['scope_type']==='ORGANIZATION')||
            (in_array($a['role_code'],['ADMIN_ACCUEIL','ENCADREUR'],true)&&$a['scope_type']==='ORGANIZATION')||
            (($a['role_code']==='POINTEUR'&&($a['scope_type']==='ORGANIZATION'||($a['scope_type']==='UNIT'&&$a['scope_entity']==='HOST_UNIT')))||(in_array($a['role_code'],['CHEF_SERVICE','EVALUATEUR_CLINIQUE'],true)&&$a['scope_type']==='UNIT'&&$a['scope_entity']==='HOST_UNIT'))
        );
        else $ok=$ok&&$a['scope_type']==='ORGANIZATION'&&(in_array($a['role_code'],['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'],true)||((int)$a['systeme']===0&&(int)$a['role_etablissement_id']===$eid));
        if(!$ok)throw new RuntimeException('Affectation hors de votre établissement.');
    }

    if($super)$pdo->prepare("UPDATE role_assignments SET principal=0 WHERE user_id=?")->execute([$a['user_id']]);
    else $pdo->prepare("UPDATE role_assignments SET principal=0 WHERE user_id=? AND etablissement_id=?")->execute([$a['user_id'],$eid]);

    $pdo->prepare("UPDATE role_assignments SET principal=1 WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE users SET role_id=? WHERE id=?")->execute([$a['role_id'],$a['user_id']]);
    $pdo->commit();jsonResponse(true,'Rôle principal mis à jour.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
