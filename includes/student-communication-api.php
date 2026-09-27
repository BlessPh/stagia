<?php
declare(strict_types=1);

require_once __DIR__.'/communication-native.php';

function studentCommunicationLimit(mixed $value,int $default=30,int $max=100):int{
    $limit=(int)$value;
    return $limit>0?min($limit,$max):$default;
}

function studentCommunicationOrganisation(PDO $pdo,int $studentId):?int{
    $q=$pdo->prepare("SELECT etablissement_id FROM student_enrollments WHERE student_id=? AND statut='ACTIF' ORDER BY id DESC LIMIT 1");
    $q->execute([$studentId]);
    $id=(int)$q->fetchColumn();
    return $id?:null;
}

function studentCommunicationContacts(PDO $pdo,int $userId,int $studentId):array{
    $organisation=studentCommunicationOrganisation($pdo,$studentId);
    $accueils=communicationEtablissementsAccueilEtudiant($pdo,$userId);
    $partenaires=communicationEtablissementsPartenaires($pdo,$organisation);
    $q=$pdo->prepare("SELECT DISTINCT u.id,u.identifiant,u.prenom,u.postnom,u.nom,u.email,
        e.nom organisation,e.type_etablissement type_organisation,
        COALESCE(ra.etablissement_id,eu.etablissement_id,se.etablissement_id) organisation_id
      FROM users u
      LEFT JOIN role_assignments ra ON ra.user_id=u.id AND ra.actif=1 AND ra.revoked_at IS NULL
        AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
      LEFT JOIN etablissement_users eu ON eu.user_id=u.id
      LEFT JOIN student_profiles sp ON sp.user_id=u.id
      LEFT JOIN student_enrollments se ON se.student_id=sp.id AND se.statut='ACTIF'
      LEFT JOIN etablissements e ON e.id=COALESCE(ra.etablissement_id,eu.etablissement_id,se.etablissement_id)
      WHERE u.id<>? AND u.actif=1 AND u.statut_compte='ACTIF'
      ORDER BY e.nom,u.nom,u.prenom");
    $q->execute([$userId]);
    $contacts=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $contactId=(int)$row['id'];
        $contactOrganisation=(int)($row['organisation_id']??0);
        $student=in_array('STAGIAIRE',communicationRolesUtilisateur($pdo,$contactId),true);
        $allowed=$contactOrganisation===(int)$organisation
            || (!$student && (in_array($contactOrganisation,$accueils,true)||in_array($contactOrganisation,$partenaires,true)));
        if(!$allowed)continue;
        $row['id']=$contactId;
        $row['organisation_id']=$contactOrganisation?:null;
        $row['is_student']=$student;
        $row['display_name']=trim(implode(' ',array_filter([$row['prenom'],$row['postnom'],$row['nom']])));
        $contacts[$contactId]=$row;
    }
    return array_values($contacts);
}

function studentCommunicationResolveRecipients(PDO $pdo,int $userId,int $studentId,array $ids):array{
    $allowed=array_column(studentCommunicationContacts($pdo,$userId,$studentId),null,'id');
    $result=[];
    foreach($ids as $id){$id=(int)$id;if($id>0&&isset($allowed[$id]))$result[$id]=$id;}
    return array_values($result);
}

function studentConversation(PDO $pdo,string $uuid,int $userId):?array{
    $q=$pdo->prepare("SELECT c.id,c.identifiant_public uuid,c.objet,c.type_conversation,c.statut,c.cree_le,
        c.cree_par_utilisateur_id,pc.dernier_message_lu_id
      FROM conversations c JOIN participants_conversations pc ON pc.conversation_id=c.id
      WHERE c.identifiant_public=? AND pc.utilisateur_id=? AND pc.quitte_le IS NULL LIMIT 1");
    $q->execute([$uuid,$userId]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function studentConversationList(PDO $pdo,int $userId,int $limit,int $offset):array{
    $q=$pdo->prepare("SELECT c.identifiant_public uuid,c.objet,c.type_conversation,c.statut,c.cree_le,
        c.cree_par_utilisateur_id,
        (SELECT COUNT(*) FROM participants_conversations p2 WHERE p2.conversation_id=c.id AND p2.quitte_le IS NULL) participant_count,
        (SELECT m.contenu FROM messages m WHERE m.conversation_id=c.id AND m.statut IN ('envoye','modifie') ORDER BY m.id DESC LIMIT 1) last_message,
        (SELECT MAX(m.cree_le) FROM messages m WHERE m.conversation_id=c.id AND m.statut IN ('envoye','modifie')) last_activity_at,
        (SELECT b.contenu FROM brouillons_messages b WHERE b.conversation_id=c.id AND b.utilisateur_id=pc.utilisateur_id LIMIT 1) draft,
        (SELECT b.modifie_le FROM brouillons_messages b WHERE b.conversation_id=c.id AND b.utilisateur_id=pc.utilisateur_id LIMIT 1) draft_updated_at,
        (SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.id>COALESCE(pc.dernier_message_lu_id,0)
           AND m.auteur_utilisateur_id<>? AND m.statut IN ('envoye','modifie')) unread_count
      FROM conversations c JOIN participants_conversations pc ON pc.conversation_id=c.id
      WHERE pc.utilisateur_id=? AND pc.quitte_le IS NULL AND c.statut<>'archivee'
      ORDER BY COALESCE(last_activity_at,c.cree_le) DESC LIMIT ? OFFSET ?");
    $q->bindValue(1,$userId,PDO::PARAM_INT);$q->bindValue(2,$userId,PDO::PARAM_INT);
    $q->bindValue(3,$limit,PDO::PARAM_INT);$q->bindValue(4,$offset,PDO::PARAM_INT);$q->execute();
    $items=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$item){$item['participant_count']=(int)$item['participant_count'];$item['unread_count']=(int)$item['unread_count'];}unset($item);
    return $items;
}

function studentConversationParticipants(PDO $pdo,int $conversationId):array{
    $q=$pdo->prepare("SELECT u.id user_id,u.identifiant,TRIM(CONCAT_WS(' ',u.prenom,u.postnom,u.nom)) display_name,
      pc.rejoint_le joined_at,pc.dernier_message_lu_id last_read_message_id
      FROM participants_conversations pc JOIN users u ON u.id=pc.utilisateur_id
      WHERE pc.conversation_id=? AND pc.quitte_le IS NULL ORDER BY display_name");
    $q->execute([$conversationId]);$items=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$item){$item['user_id']=(int)$item['user_id'];$item['last_read_message_id']=$item['last_read_message_id']!==null?(int)$item['last_read_message_id']:null;}unset($item);return $items;
}

function studentConversationMessages(PDO $pdo,int $conversationId,int $userId,int $afterId,int $limit):array{
    $q=$pdo->prepare("SELECT m.id,m.identifiant_public uuid,m.contenu,m.cree_le,m.modifie_le,m.statut,
        m.auteur_utilisateur_id,TRIM(CONCAT_WS(' ',u.prenom,u.postnom,u.nom)) author,
        (m.auteur_utilisateur_id=?) is_mine,
        (SELECT COUNT(*) FROM participants_conversations p WHERE p.conversation_id=m.conversation_id
          AND p.utilisateur_id<>m.auteur_utilisateur_id AND p.quitte_le IS NULL AND p.dernier_message_lu_id>=m.id) read_by_count
      FROM messages m JOIN users u ON u.id=m.auteur_utilisateur_id
      WHERE m.conversation_id=? AND m.id>? AND m.statut IN ('envoye','modifie') ORDER BY m.id LIMIT ?");
    $q->bindValue(1,$userId,PDO::PARAM_INT);$q->bindValue(2,$conversationId,PDO::PARAM_INT);
    $q->bindValue(3,$afterId,PDO::PARAM_INT);$q->bindValue(4,$limit,PDO::PARAM_INT);$q->execute();
    $items=$q->fetchAll(PDO::FETCH_ASSOC);
    if(!$items)return [];
    $ids=array_map('intval',array_column($items,'id'));$ph=implode(',',array_fill(0,count($ids),'?'));
    $a=$pdo->prepare("SELECT pj.message_id,f.identifiant_public uuid,f.nom_original name,f.type_mime mime_type,f.taille_octets size
      FROM pieces_jointes_messages pj JOIN fichiers f ON f.id=pj.fichier_id
      WHERE pj.message_id IN ($ph) AND f.archive_le IS NULL");$a->execute($ids);$attachments=[];
    foreach($a->fetchAll(PDO::FETCH_ASSOC) as $file){$file['size']=(int)$file['size'];$attachments[(int)$file['message_id']][]=$file;}
    foreach($items as &$item){$item['id']=(int)$item['id'];$item['author_user_id']=(int)$item['auteur_utilisateur_id'];unset($item['auteur_utilisateur_id']);$item['is_mine']=(bool)$item['is_mine'];$item['read_by_count']=(int)$item['read_by_count'];$item['attachments']=$attachments[$item['id']]??[];}unset($item);
    return $items;
}

function studentCommunicationAudienceClause(string $communicationAlias='co'):string{
    return "EXISTS(SELECT 1 FROM destinataires_communications dc WHERE dc.communication_officielle_id={$communicationAlias}.id AND (
        dc.utilisateur_id=:audience_user OR dc.role_id IN (
          SELECT role_id FROM users WHERE id=:audience_legacy_user
          UNION SELECT role_id FROM role_assignments WHERE user_id=:audience_assignment_user AND actif=1 AND revoked_at IS NULL
            AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW())
        )))";
}

function studentVisibleCommunication(PDO $pdo,string $uuid,int $userId):?array{
    $sql="SELECT co.id,co.identifiant_public uuid FROM communications_officielles co WHERE co.identifiant_public=:uuid
      AND co.statut='publiee' AND ".studentCommunicationAudienceClause('co')." LIMIT 1";
    $q=$pdo->prepare($sql);$q->execute(['uuid'=>$uuid,'audience_user'=>$userId,'audience_legacy_user'=>$userId,'audience_assignment_user'=>$userId]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function studentCommunicationList(PDO $pdo,int $userId,int $limit,int $offset):array{
    $sql="SELECT co.identifiant_public uuid,co.reference,co.type_communication type,co.objet subject,co.contenu content,
      co.priorite priority,co.accuse_reception_requis acknowledgement_required,co.date_limite_accuse acknowledgement_due_at,
      co.publiee_le published_at,COALESCE(o.nom,'Administration nationale') sender,
      MAX(CASE WHEN direct.utilisateur_id=:direct_user THEN direct.lue_le END) read_at,
      MAX(CASE WHEN direct.utilisateur_id=:direct_user_2 THEN direct.accusee_le END) acknowledged_at
      FROM communications_officielles co
      LEFT JOIN etablissements o ON o.id=co.organisation_emettrice_id
      LEFT JOIN destinataires_communications direct ON direct.communication_officielle_id=co.id AND direct.utilisateur_id=:direct_user_3
      WHERE co.statut='publiee' AND ".studentCommunicationAudienceClause('co')."
      GROUP BY co.id ORDER BY co.publiee_le DESC LIMIT :limit OFFSET :offset";
    $q=$pdo->prepare($sql);
    foreach(['direct_user','direct_user_2','direct_user_3','audience_user','audience_legacy_user','audience_assignment_user'] as $key)$q->bindValue(':'.$key,$userId,PDO::PARAM_INT);
    $q->bindValue(':limit',$limit,PDO::PARAM_INT);$q->bindValue(':offset',$offset,PDO::PARAM_INT);$q->execute();
    $items=$q->fetchAll(PDO::FETCH_ASSOC);foreach($items as &$item)$item['acknowledgement_required']=(bool)$item['acknowledgement_required'];unset($item);return $items;
}

function studentNotificationCounts(PDO $pdo,int $userId):array{
    $q=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE utilisateur_id=? AND lue_le IS NULL AND archivee_le IS NULL');$q->execute([$userId]);$notifications=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM messages m JOIN participants_conversations pc ON pc.conversation_id=m.conversation_id
      AND pc.utilisateur_id=? AND pc.quitte_le IS NULL JOIN conversations c ON c.id=m.conversation_id AND c.statut<>'archivee'
      WHERE m.auteur_utilisateur_id<>? AND m.statut IN ('envoye','modifie') AND m.id>COALESCE(pc.dernier_message_lu_id,0)");$q->execute([$userId,$userId]);$messages=(int)$q->fetchColumn();
    return ['notifications'=>$notifications,'messages'=>$messages,'total'=>max($notifications,$messages)];
}

function studentCalendarEvent(PDO $pdo,string $uuid,int $userId):?array{
    $q=$pdo->prepare("SELECT ev.id,ev.identifiant_public uuid,ev.titre title,ev.description,ev.type_evenement type,
      ev.debut starts_at,ev.fin ends_at,ev.lieu location,ev.lien_externe external_url,ev.statut status,
      ev.cree_par_utilisateur_id creator_user_id,p.statut_reponse response_status,p.repondu_le responded_at
      FROM evenements_calendrier ev JOIN participants_evenements p ON p.evenement_calendrier_id=ev.id
      WHERE ev.identifiant_public=? AND p.utilisateur_id=? LIMIT 1");$q->execute([$uuid,$userId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function studentApiDate(string $value):?DateTimeImmutable{
    if(trim($value)==='')return null;
    try{return new DateTimeImmutable($value);}catch(Throwable){return null;}
}

function studentNotificationCategory(string $eventType):string{
    $type=strtolower($eventType);
    if(str_contains($type,'message')||str_contains($type,'conversation'))return 'message';
    if(str_contains($type,'stage')||str_contains($type,'affectation')||str_contains($type,'assignment')||str_contains($type,'placement')||str_contains($type,'admission')||str_contains($type,'reservation')||str_contains($type,'paiement')||str_contains($type,'payment')||str_contains($type,'campagne')||str_contains($type,'campaign'))return 'internship';
    if(str_contains($type,'journal')||str_contains($type,'logbook')||str_contains($type,'tache')||str_contains($type,'task')||str_contains($type,'feedback')||str_contains($type,'evaluation'))return 'logbook';
    if(str_contains($type,'urgent')||str_contains($type,'alerte'))return 'urgent';
    return 'academic';
}

function studentNotificationAction(PDO $pdo,string $eventType,array $data,?string $legacyLink=null):array{
    $provided=is_array($data['action']??null)?$data['action']:[];
    if($provided){return ['type'=>(string)($provided['type']??'generic'),'target_id'=>isset($provided['target_id'])?(string)$provided['target_id']:null,'label'=>(string)($provided['label']??'Consulter'),'title'=>isset($provided['title'])?(string)$provided['title']:null,'metadata'=>is_array($provided['metadata']??null)?$provided['metadata']:[]];}
    $type=strtolower($eventType);$target=null;$actionType='generic';$label='Consulter';$metadata=[];
    if(str_contains($type,'message')||str_contains($type,'conversation')){
        $actionType='conversation';$label='Ouvrir la discussion';$target=(string)($data['conversation_uuid']??'');
        if($target===''&&$legacyLink&&preg_match('~/conversations/([0-9A-HJKMNP-TV-Z]{26})~i',$legacyLink,$m))$target=$m[1];
        if($target===''&&$legacyLink&&preg_match('/[?&]conversation=(\d+)/',$legacyLink,$m)){$q=$pdo->prepare('SELECT identifiant_public FROM conversations WHERE id=?');$q->execute([(int)$m[1]]);$target=(string)($q->fetchColumn()?:'');}
    }elseif(str_contains($type,'communication_officielle')){$actionType='official_communication';$label='Lire le communique';$target=(string)($data['communication_uuid']??'');}
    elseif(str_contains($type,'calendrier')||str_contains($type,'visioconference')){$actionType='calendar_event';$label="Voir l'evenement";$target=(string)($data['event_uuid']??'');}
    elseif(str_contains($type,'affectation')||str_contains($type,'assignment')){$actionType='internship_assignment';$label='Voir mon affectation';$target=(string)($data['assignment_uuid']??'');}
    elseif(str_contains($type,'campagne')||str_contains($type,'campaign')){$actionType='campaign';$label='Voir la campagne';$target=(string)($data['campaign_uuid']??'');}
    elseif(str_contains($type,'journal')||str_contains($type,'logbook')){$actionType='logbook';$label='Consulter le journal';$target=(string)($data['logbook_uuid']??'');}
    elseif(str_contains($type,'tache')||str_contains($type,'task')){$actionType='task';$label='Voir la tache';$target=(string)($data['task_uuid']??'');}
    elseif(str_contains($type,'paiement')||str_contains($type,'payment')){$actionType='payment';$label='Voir le paiement';$target=(string)($data['payment_uuid']??'');}
    elseif(str_contains($type,'bibliotheque')){$actionType='academic_document';$label='Consulter le document';$target=isset($data['document_uuid'])?(string)$data['document_uuid']:(isset($data['document_id'])?(string)$data['document_id']:'');}
    if($target==='')$target=null;
    return ['type'=>$actionType,'target_id'=>$target,'label'=>$label,'title'=>isset($data['action_title'])?(string)$data['action_title']:null,'metadata'=>$metadata];
}
