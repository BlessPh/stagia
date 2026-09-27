<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$adminContexte=exigerAdministrationRolesAjax($pdo);verifyAjaxCsrf();
if(function_exists('contextPermission')&&!contextPermission('user.role.assign'))jsonResponse(false,'Permission insuffisante.',[],403);

$actor=$_SESSION['role_code']??'';$super=$adminContexte['super'];$eid=$super?0:(int)$adminContexte['etablissement_id'];
$id=(int)($_POST['id']??0);$reason=trim($_POST['reason']??'');
if(!$super&&!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);
if(!$id)jsonResponse(false,'Affectation invalide.',[],422);

$localCondition=$actor==='ADMIN_ACCUEIL'
    ?"(((r.code IN('ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','GESTIONNAIRE_FINANCIER_HOSPITALIER') OR (r.systeme=0 AND r.etablissement_id=".(int)$eid.")) AND ra.scope_type='ORGANIZATION') OR (r.code='POINTEUR' AND (ra.scope_type='ORGANIZATION' OR (ra.scope_type='UNIT' AND ra.scope_entity='HOST_UNIT'))) OR (r.code IN('CHEF_SERVICE','EVALUATEUR_CLINIQUE') AND ra.scope_type='UNIT' AND ra.scope_entity='HOST_UNIT'))"
    :"((r.code IN('ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE') OR (r.systeme=0 AND r.etablissement_id=".(int)$eid.")) AND ra.scope_type='ORGANIZATION')";

try{
    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT ra.*,r.code role_code,r.systeme,r.etablissement_id role_etablissement_id FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.id=? LIMIT 1 FOR UPDATE");$s->execute([$id]);$a=$s->fetch(PDO::FETCH_ASSOC);
    if(!$a)throw new RuntimeException('Affectation introuvable.');

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

    if((int)$a['actif']===1){
        if($reason==='')throw new RuntimeException('Le motif de révocation est obligatoire.');
        $sql="SELECT COUNT(*) FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.user_id=? AND ra.actif=1 AND ra.id<>?";$p=[$a['user_id'],$id];
        if(!$super){$sql.=" AND ra.etablissement_id=? AND $localCondition";$p[]=$eid;}
        $s=$pdo->prepare($sql);$s->execute($p);
        if(!(int)$s->fetchColumn())throw new RuntimeException("Impossible de révoquer la dernière affectation active. Attribuez d’abord un autre rôle/périmètre ou suspendez le compte.");

        $pdo->prepare("UPDATE role_assignments SET actif=0,principal=0,revoked_at=NOW(),revoked_by=?,revocation_reason=? WHERE id=?")
            ->execute([$_SESSION['user_id']??null,$reason,$id]);

        if((int)$a['principal']===1){
            $sql="SELECT ra.id,ra.role_id FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.user_id=? AND ra.actif=1";$p=[$a['user_id']];
            if(!$super){$sql.=" AND ra.etablissement_id=? AND $localCondition";$p[]=$eid;}
            $sql.=" ORDER BY ra.id LIMIT 1";$s=$pdo->prepare($sql);$s->execute($p);$n=$s->fetch(PDO::FETCH_ASSOC);
            if($n){$pdo->prepare("UPDATE role_assignments SET principal=1 WHERE id=?")->execute([$n['id']]);$pdo->prepare("UPDATE users SET role_id=? WHERE id=?")->execute([$n['role_id'],$a['user_id']]);}
        }
        $pdo->commit();jsonResponse(true,'Affectation révoquée.');
    }

    if($a['role_code']==='MINISTERE'){
        $ministereId=(int)($a['etablissement_id']??0);
        if($a['scope_type']!=='ORGANIZATION'||$a['scope_entity']!=='ESTABLISHMENT'||!$ministereId||(int)($a['scope_id']??0)!==$ministereId)
            throw new RuntimeException('Cette ancienne affectation ministérielle est incomplète. Créez une nouvelle affectation rattachée au ministère avant de la réactiver.');
        $s=$pdo->prepare("SELECT 1 FROM etablissements e JOIN etablissement_users eu ON eu.etablissement_id=e.id AND eu.user_id=? WHERE e.id=? AND e.type_etablissement='MINISTERE' AND e.statut IN('VALIDE','ACTIF') LIMIT 1");
        $s->execute([$a['user_id'],$ministereId]);
        if(!$s->fetchColumn())throw new RuntimeException('Le responsable doit d’abord être rattaché à un ministère actif.');
    }

    $s=$pdo->prepare("SELECT id FROM role_assignments WHERE user_id=? AND role_id=? AND scope_type=? AND COALESCE(scope_entity,'')=COALESCE(?,'') AND COALESCE(scope_id,0)=COALESCE(?,0) AND actif=1 AND id<>? LIMIT 1");
    $s->execute([$a['user_id'],$a['role_id'],$a['scope_type'],$a['scope_entity'],$a['scope_id'],$id]);
    if($s->fetchColumn())throw new RuntimeException('Une affectation active identique existe déjà.');

    $pdo->prepare("UPDATE role_assignments SET actif=1,revoked_at=NULL,revoked_by=NULL,revocation_reason=NULL WHERE id=?")->execute([$id]);
    $pdo->commit();jsonResponse(true,'Affectation réactivée.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
