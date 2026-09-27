<?php
declare(strict_types=1);
require_once __DIR__.'/../api-auth.php';require_once __DIR__.'/../../../includes/student-communication-api.php';
requireApiMethod('GET');$student=requireApiStudent($pdo);$userId=(int)$student['user_id'];
@set_time_limit(35);ignore_user_abort(true);while(ob_get_level()>0)ob_end_clean();
header('Content-Type: text/event-stream; charset=utf-8');header('Cache-Control: no-cache, no-store');header('Connection: keep-alive');header('X-Accel-Buffering: no');
function studentSseSend(string $event,array $data,string $id=''):void{if($id!=='')echo 'id: '.$id."\n";echo 'event: '.$event."\n".'data: '.json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";@ob_flush();flush();}
$raw=trim((string)($_SERVER['HTTP_LAST_EVENT_ID']??($_GET['cursor']??'')));$cursor=[0,0,0,0];if(preg_match('/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/',$raw,$matches))$cursor=array_map('intval',array_slice($matches,1));
try{
 if($raw===''){
  $q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM notifications WHERE utilisateur_id=?');$q->execute([$userId]);$cursor[0]=(int)$q->fetchColumn();
  $q=$pdo->prepare('SELECT COALESCE(MAX(m.id),0) FROM messages m JOIN participants_conversations p ON p.conversation_id=m.conversation_id AND p.utilisateur_id=? AND p.quitte_le IS NULL');$q->execute([$userId]);$cursor[1]=(int)$q->fetchColumn();
  $sql='SELECT COALESCE(MAX(co.id),0) FROM communications_officielles co WHERE co.statut=\'publiee\' AND '.studentCommunicationAudienceClause('co');$q=$pdo->prepare($sql);$q->execute(['audience_user'=>$userId,'audience_legacy_user'=>$userId,'audience_assignment_user'=>$userId]);$cursor[2]=(int)$q->fetchColumn();
  $q=$pdo->prepare('SELECT COALESCE(MAX(ev.id),0) FROM evenements_calendrier ev JOIN participants_evenements p ON p.evenement_calendrier_id=ev.id WHERE p.utilisateur_id=?');$q->execute([$userId]);$cursor[3]=(int)$q->fetchColumn();
 }
 echo "retry: 3000\n\n";studentSseSend('ready',['counts'=>studentNotificationCounts($pdo,$userId)],implode('.',$cursor));$started=microtime(true);
 while(!connection_aborted()&&microtime(true)-$started<25){
  $events=[];
  $q=$pdo->prepare('SELECT id sequence,identifiant_public id,type_evenement event_type,titre subject,contenu description,donnees data,lien_action,priorite priority,cree_le created_at FROM notifications WHERE utilisateur_id=? AND id>? ORDER BY id LIMIT 25');$q->execute([$userId,$cursor[0]]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$cursor[0]=max($cursor[0],(int)$row['sequence']);$data=$row['data']?json_decode((string)$row['data'],true):[];if(!is_array($data))$data=[];$row['type']=studentNotificationCategory((string)$row['event_type']);$row['action']=studentNotificationAction($pdo,(string)$row['event_type'],$data,(string)($row['lien_action']??''));unset($row['data'],$row['lien_action']);$events[]=['notification',$row];}
  $q=$pdo->prepare("SELECT m.id,m.identifiant_public uuid,c.identifiant_public conversation_uuid,m.cree_le created_at FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN participants_conversations p ON p.conversation_id=m.conversation_id AND p.utilisateur_id=? AND p.quitte_le IS NULL WHERE m.id>? AND m.auteur_utilisateur_id<>? AND m.statut IN ('envoye','modifie') ORDER BY m.id LIMIT 25");$q->execute([$userId,$cursor[1],$userId]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$cursor[1]=max($cursor[1],(int)$row['id']);$events[]=['message',$row];}
  $sql="SELECT co.id,co.identifiant_public uuid,co.objet subject,co.publiee_le published_at FROM communications_officielles co WHERE co.id>:after AND co.statut='publiee' AND ".studentCommunicationAudienceClause('co').' ORDER BY co.id LIMIT 25';$q=$pdo->prepare($sql);$q->execute(['after'=>$cursor[2],'audience_user'=>$userId,'audience_legacy_user'=>$userId,'audience_assignment_user'=>$userId]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$cursor[2]=max($cursor[2],(int)$row['id']);$events[]=['communication',$row];}
  $q=$pdo->prepare('SELECT ev.id,ev.identifiant_public uuid,ev.titre title,ev.debut starts_at,p.statut_reponse response_status FROM evenements_calendrier ev JOIN participants_evenements p ON p.evenement_calendrier_id=ev.id WHERE p.utilisateur_id=? AND ev.id>? ORDER BY ev.id LIMIT 25');$q->execute([$userId,$cursor[3]]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$cursor[3]=max($cursor[3],(int)$row['id']);$events[]=['calendar',$row];}
  if($events){$id=implode('.',$cursor);foreach($events as [$event,$data])studentSseSend($event,$data,$id);studentSseSend('counts',studentNotificationCounts($pdo,$userId),$id);}else{echo ': keep-alive '.gmdate('c')."\n\n";@ob_flush();flush();}
  usleep(2000000);
 }
}catch(Throwable $e){error_log('[API NOTIFICATION STREAM] '.$e->getMessage());studentSseSend('error',['message'=>'Flux temporairement indisponible.']);}
exit;
