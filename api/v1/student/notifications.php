<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-communication-api.php';
requireApiMethod('GET');$student=requireApiStudent($pdo);$userId=(int)$student['user_id'];
try{
    $limit=studentCommunicationLimit($_GET['limit']??30);$before=max(0,(int)($_GET['before_id']??0));
    $unread=filter_var($_GET['unread']??false,FILTER_VALIDATE_BOOLEAN);$type=trim((string)($_GET['type']??''));
    $sql="SELECT id sequence,identifiant_public id,type_evenement event_type,titre subject,contenu description,donnees data,lien_action,
      priorite priority,lue_le read_at,archivee_le archived_at,cree_le created_at
      FROM notifications WHERE utilisateur_id=:user AND archivee_le IS NULL";
    $params=['user'=>$userId];if($before>0){$sql.=' AND id<:before';$params['before']=$before;}
    if($unread)$sql.=' AND lue_le IS NULL';if($type!==''){$sql.=' AND type_evenement=:type';$params['type']=$type;}
    $sql.=' ORDER BY id DESC LIMIT :limit';$q=$pdo->prepare($sql);
    foreach($params as $key=>$value)$q->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);$q->bindValue(':limit',$limit,PDO::PARAM_INT);$q->execute();
    $items=$q->fetchAll(PDO::FETCH_ASSOC);foreach($items as &$item){$item['sequence']=(int)$item['sequence'];$data=$item['data']?json_decode((string)$item['data'],true):[];if(!is_array($data))$data=[];$item['type']=studentNotificationCategory((string)$item['event_type']);$item['action']=studentNotificationAction($pdo,(string)$item['event_type'],$data,(string)($item['lien_action']??''));unset($item['data'],$item['lien_action']);}unset($item);
    apiResponse(true,'',['items'=>$items,'next_before_id'=>$items?(int)end($items)['sequence']:null,'counts'=>studentNotificationCounts($pdo,$userId)]);
}catch(Throwable $e){error_log('[API NOTIFICATIONS] '.$e->getMessage());apiResponse(false,'Impossible de charger les notifications.',[],500);}
