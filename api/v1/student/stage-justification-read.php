<?php
require_once __DIR__.'/../api-auth.php';require_once __DIR__.'/../../../includes/stage-justification.php';requireApiMethod('POST');$student=requireApiStudent($pdo);$uuid=trim((string)($_GET['uuid']??''));
try{if($uuid==='')apiResponse(false,'Justification invalide.',[],422);if(!stageJustificationMarkSeen($pdo,(int)$student['student_id'],$uuid))apiResponse(false,'Décision introuvable ou déjà lue.',[],404);apiResponse(true,'Décision marquée comme lue.',['uuid'=>$uuid]);
}catch(Throwable $e){error_log('[API JUSTIFICATION READ] '.$e->getMessage());apiResponse(false,'Une erreur interne empêche la mise à jour.',[],500);}
