<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
requirePermission($pdo,'host.manage');verifyAjaxCsrf();
$eid=(int)($_SESSION['etablissement_id']??0);$id=(int)($_POST['id']??0);$actif=(int)($_POST['actif']??0)===1?1:0;
if(!$eid||!$id)jsonResponse(false,'Structure invalide.',[],422);
try{
    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT id,nom,type,actif FROM host_units WHERE id=? AND host_etablissement_id=? LIMIT 1 FOR UPDATE");$s->execute([$id,$eid]);$u=$s->fetch(PDO::FETCH_ASSOC);if(!$u)throw new RuntimeException('Structure introuvable.');
    if(!$actif){
        $s=$pdo->prepare("SELECT COUNT(*) FROM host_units WHERE parent_id=? AND host_etablissement_id=? AND actif=1");$s->execute([$id,$eid]);if((int)$s->fetchColumn()>0)throw new RuntimeException('Désactivez ou déplacez d’abord les structures enfants.');
        if(in_array($u['type'],['SERVICE','UNITE'],true)){$s=$pdo->prepare("SELECT COUNT(*) FROM stage_assignments WHERE host_unit_id=? AND host_etablissement_id=? AND statut IN('PLANIFIEE','ACTIVE')");$s->execute([$id,$eid]);if((int)$s->fetchColumn()>0)throw new RuntimeException('Cette structure possède encore des stagiaires affectés.');}
    }
    $s=$pdo->prepare("UPDATE host_units SET actif=?,updated_at=NOW() WHERE id=? AND host_etablissement_id=?");$s->execute([$actif,$id,$eid]);$pdo->commit();jsonResponse(true,$actif?'Structure réactivée.':'Structure désactivée.');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],409);}
