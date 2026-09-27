<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/host-unit-template-sync.php';

requireAjaxRole(['SUPER_ADMIN']);
verifyAjaxCsrf();

$id=(int)($_POST['id']??0);
$type=strtoupper(trim((string)($_POST['type_code']??'')));

if(!$id||$type==='')jsonResponse(false,'Données invalides.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM host_unit_templates
        WHERE parent_id=?
          AND establishment_type_code=?
          AND actif=1
    ");
    $s->execute([$id,$type]);

    if((int)$s->fetchColumn()>0)
        throw new RuntimeException('Impossible de retirer cet élément : il contient des sous-unités actives.');

    $pdo->prepare("
        UPDATE host_unit_templates
        SET actif=0
        WHERE id=?
          AND establishment_type_code=?
    ")->execute([$id,$type]);

    syncHostUnitTemplate($pdo,$id);

    $pdo->commit();
    jsonResponse(true,'Élément retiré du référentiel actif.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],409);
}
