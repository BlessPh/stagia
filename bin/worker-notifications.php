<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/MailService.php';
require_once __DIR__.'/../includes/communication-native.php';

$limite=max(1,min(100,(int)($argv[1]??20)));$traitees=0;

function webhook(string $url,array $charge,string $secret=''):bool{
    if($url===''||!function_exists('curl_init'))return false;
    $headers=['Content-Type: application/json'];if($secret!=='')$headers[]='Authorization: Bearer '.$secret;
    $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($charge,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_TIMEOUT=>15]);curl_exec($curl);$code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return $code>=200&&$code<300;
}
function configurationCanal(PDO $pdo,?int $org,string $canal):array{$q=$pdo->prepare('SELECT * FROM configurations_canaux_communication WHERE organisation_id <=> ? AND canal=? AND actif=1 LIMIT 1');$q->execute([$org,$canal]);return $q->fetch()?:[];}
function emailConfigure(array $cfg,array $job):bool{
    if(!$cfg)return false;$mail=new PHPMailer\PHPMailer\PHPMailer(true);
    try{$mail->isSMTP();$mail->Host=(string)$cfg['hote'];$mail->SMTPAuth=true;$mail->Username=(string)$cfg['nom_utilisateur'];$mail->Password=communicationDechiffrerSecret($cfg['secret_chiffre']??null);$mail->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;$mail->Port=(int)($cfg['port']?:587);$mail->CharSet='UTF-8';$mail->setFrom((string)$cfg['adresse_expediteur'],(string)($cfg['nom_expediteur']?:'STAGIA-RDC'));$mail->addAddress((string)$job['destinataire']);$mail->isHTML(true);$mail->Subject=(string)$job['titre'];$mail->Body=nl2br(htmlspecialchars((string)$job['contenu'])).'<p><a href="'.htmlspecialchars((string)$job['lien_action']).'">Ouvrir STAGIA</a></p>';$mail->AltBody=(string)$job['contenu'];return $mail->send();}catch(Throwable $e){error_log('MAIL STAGIA : '.$e->getMessage());return false;}
}
function pushAppareils(PDO $pdo,array $cfg,array $job,array $charge,string $secret):bool{
    $destination=(string)$job['destinataire'];$url=(string)($cfg['hote']??(getenv('PUSH_WEBHOOK_URL')?:''));if(!preg_match('/^utilisateur:(\d+)$/',$destination,$matches))return webhook($url,$charge,$secret);
    try{$q=$pdo->prepare('SELECT plateforme,jeton FROM mobile_notification_devices WHERE utilisateur_id=? AND actif=1 ORDER BY derniere_activite_le DESC');$q->execute([(int)$matches[1]]);$devices=$q->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable){return webhook($url,$charge,$secret);}
    if(!$devices)return webhook($url,$charge,$secret);
    $envoye=false;foreach($devices as $device){$payload=$charge;$payload['destination']=$device['jeton'];$payload['platform']=$device['plateforme'];$payload['user_id']=(int)$matches[1];if(webhook($url,$payload,$secret))$envoye=true;}return $envoye;
}
while($traitees<$limite){
    $pdo->beginTransaction();$q=$pdo->query("SELECT l.id,l.notification_id,l.canal,l.destinataire,l.nombre_tentatives,n.identifiant_public notification_uuid,n.utilisateur_id,n.organisation_id,n.type_evenement,n.titre,n.contenu,n.donnees,n.lien_action FROM livraisons_notifications l JOIN notifications n ON n.id=l.notification_id WHERE l.statut IN ('en_attente','echec') AND (l.prochaine_tentative_le IS NULL OR l.prochaine_tentative_le<=UTC_TIMESTAMP(6)) AND l.nombre_tentatives<5 ORDER BY l.id LIMIT 1 FOR UPDATE SKIP LOCKED");$job=$q->fetch();if(!$job){$pdo->commit();break;}$pdo->prepare("UPDATE livraisons_notifications SET statut='en_cours',nombre_tentatives=nombre_tentatives+1 WHERE id=?")->execute([$job['id']]);$pdo->commit();$ok=false;$erreur='';
    try{$data=$job['donnees']?json_decode((string)$job['donnees'],true):[];if(!is_array($data))$data=[];if(!$data&&preg_match('/[?&]conversation=\d+/',(string)$job['lien_action']))$data=communicationDonneesNotification($pdo,(int)$job['utilisateur_id'],(string)$job['type_evenement'],(string)$job['lien_action']);$action=is_array($data['action']??null)?$data['action']:null;$charge=['destination'=>$job['destinataire'],'notification_id'=>$job['notification_uuid'],'event_id'=>$job['notification_uuid'],'type'=>$job['type_evenement'],'titre'=>$job['titre'],'contenu'=>$job['contenu'],'lien'=>$job['lien_action'],'action'=>$action,'action_type'=>$action['type']??'generic','target_id'=>$action['target_id']??null,'schema_version'=>'1'];$canal=$job['canal']==='email'?'smtp':(string)$job['canal'];$cfg=configurationCanal($pdo,$job['organisation_id']!==null?(int)$job['organisation_id']:null,$canal);$secret=$cfg?communicationDechiffrerSecret($cfg['secret_chiffre']??null):'';if($job['canal']==='email')$ok=$cfg?emailConfigure($cfg,$job):sendMail((string)$job['destinataire'],'Utilisateur STAGIA',(string)$job['titre'],nl2br(htmlspecialchars((string)$job['contenu'])));elseif($job['canal']==='sms')$ok=webhook((string)($cfg['hote']??(getenv('SMS_WEBHOOK_URL')?:'')),$charge,$secret);elseif($job['canal']==='push')$ok=pushAppareils($pdo,$cfg,$job,$charge,$secret);if(!$ok)$erreur='Canal non configure ou fournisseur indisponible.';}catch(Throwable $e){$erreur=$e->getMessage();}
    $pdo->prepare("UPDATE livraisons_notifications SET statut=?,envoyee_le=IF(?='envoyee',UTC_TIMESTAMP(6),envoyee_le),prochaine_tentative_le=IF(?='echec',UTC_TIMESTAMP(6)+INTERVAL 15 MINUTE,NULL),derniere_erreur=? WHERE id=?")->execute([$ok?'envoyee':'echec',$ok?'envoyee':'echec',$ok?'envoyee':'echec',$erreur?:null,$job['id']]);$traitees++;echo strtoupper((string)$job['canal']).' #'.$job['id'].' : '.($ok?'envoyee':'echec').PHP_EOL;
}
echo "Traitements termines : $traitees.\n";
