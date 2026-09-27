<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/stage-letter-service.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
$eid=(int)currentEtablissementId($pdo);
$uid=(int)($_SESSION['user_id']??0);
if(!$eid)exit('Aucun établissement associé.');

$s=$pdo->prepare("\n    SELECT a.id\n    FROM stage_applications a\n    JOIN stage_campaigns c ON c.id=a.campaign_id\n    LEFT JOIN stage_reservations r ON r.application_id=a.id\n    WHERE c.owner_etablissement_id=?\n      AND a.statut='ACCEPTEE'\n      AND COALESCE(r.statut,'')<>'ANNULEE'\n    ORDER BY a.id DESC\n    LIMIT 1000\n");
$s->execute([$eid]);
$ids=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
$ok=0;$fail=0;$errors=[];
foreach($ids as $id){
    try{stageLetterCreateForAcceptedApplication($pdo,$id,$uid);$ok++;}
    catch(Throwable $e){$fail++;$errors[]='Candidature #'.$id.' : '.$e->getMessage();}
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Génération lettres de stage</title>
<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;padding:30px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;max-width:760px;margin:auto}.ok{color:#16a34a}.bad{color:#dc2626}code{background:#f1f5f9;padding:2px 5px;border-radius:5px}</style></head><body><div class="card">
<h2>Lettres de stage générées</h2>
<p class="ok"><strong><?=$ok?></strong> lettre(s) générée(s) / mise(s) à jour.</p>
<p class="bad"><strong><?=$fail?></strong> erreur(s).</p>
<p>Tu peux retourner dans <code>Candidatures</code> ou <code>Documents officiels → Archives documents</code>.</p>
<?php if($errors): ?><ul><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e,ENT_QUOTES,'UTF-8')?></li><?php endforeach; ?></ul><?php endif; ?>
</div></body></html>
