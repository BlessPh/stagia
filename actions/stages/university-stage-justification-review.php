<?php
require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/ajax.php';require_once __DIR__.'/../../includes/permissions.php';require_once __DIR__.'/../../includes/stage-justification.php';
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
$csrf=(string)($_POST['csrf']??'');if(empty($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],$csrf))jsonResponse(false,'Jeton de sécurité invalide.',[],419);
try{$eid=(int)currentEtablissementId($pdo);$result=stageJustificationReview($pdo,$eid,(int)$_SESSION['user_id'],trim((string)($_POST['uuid']??'')),(string)($_POST['decision']??''),(string)($_POST['commentaire']??''));jsonResponse(true,'Décision enregistrée.',['justification'=>$result]);
}catch(InvalidArgumentException $e){jsonResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[UNIVERSITY JUSTIFICATION REVIEW] '.$e->getMessage());jsonResponse(false,'Une erreur interne empêche la décision.',[],500);}
