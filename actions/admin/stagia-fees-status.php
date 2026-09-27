<?php
require_once __DIR__.'/../../config/config.php';require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../includes/ajax.php';
try{
    requireRole(['SUPER_ADMIN']);verifyAjaxCsrf();
    $id=(int)($_POST['id']??0);$actif=(int)($_POST['actif']??0);if(!$id)jsonResponse(false,'Frais introuvable.',[],422);
    $s=$pdo->prepare("UPDATE stagia_hospital_level_fees SET actif=?,updated_at=NOW() WHERE id=?");$s->execute([$actif?1:0,$id]);
    jsonResponse(true,$actif?'Frais activé.':'Frais désactivé.');
}catch(Throwable $e){jsonResponse(false,$e->getMessage(),[],500);}