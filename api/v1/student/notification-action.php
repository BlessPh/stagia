<?php
require_once __DIR__.'/../api-auth.php';require_once __DIR__.'/../../../includes/student-communication-api.php';
requireApiMethod('POST');$student=requireApiStudent($pdo);$uuid=trim((string)($_GET['uuid']??''));$action=strtolower((string)($_GET['action']??''));
if(!in_array($action,['read','archive'],true))apiResponse(false,'Action invalide.',[],422);
$column=$action==='read'?'lue_le':'archivee_le';$q=$pdo->prepare("UPDATE notifications SET $column=COALESCE($column,UTC_TIMESTAMP(6)) WHERE identifiant_public=? AND utilisateur_id=?");$q->execute([$uuid,(int)$student['user_id']]);
if(!$q->rowCount()){$q=$pdo->prepare('SELECT 1 FROM notifications WHERE identifiant_public=? AND utilisateur_id=?');$q->execute([$uuid,(int)$student['user_id']]);if(!$q->fetchColumn())apiResponse(false,'Notification introuvable.',[],404);}
apiResponse(true,$action==='read'?'Notification marquee comme lue.':'Notification archivee.',['counts'=>studentNotificationCounts($pdo,(int)$student['user_id'])]);
