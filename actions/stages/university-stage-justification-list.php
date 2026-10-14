<?php
require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/ajax.php';require_once __DIR__.'/../../includes/permissions.php';require_once __DIR__.'/../../includes/stage-justification.php';
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
try{$eid=(int)currentEtablissementId($pdo);if(!$eid)jsonResponse(false,'Établissement introuvable.',[],403);$status=strtoupper(trim((string)($_GET['status']??'')));jsonResponse(true,'',stageJustificationUniversityList($pdo,$eid,$status));
}catch(Throwable $e){error_log('[UNIVERSITY JUSTIFICATION LIST] '.$e->getMessage());jsonResponse(false,'Impossible de charger les justifications.',[],500);}
