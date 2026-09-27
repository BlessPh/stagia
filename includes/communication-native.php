<?php
declare(strict_types=1);

require_once __DIR__.'/admin-scope.php';

function communicationUlid():string{
    $alphabet='0123456789ABCDEFGHJKMNPQRSTVWXYZ';$temps=(int)floor(microtime(true)*1000);$id='';
    for($i=0;$i<10;$i++){$id=$alphabet[$temps%32].$id;$temps=intdiv($temps,32);}
    foreach(str_split(random_bytes(16)) as $octet)$id.=$alphabet[ord($octet)&31];
    return substr($id,0,26);
}

function communicationCleConfiguration():string{
    $brute=(string)(getenv('COMMUNICATION_CONFIG_KEY')?:'');
    if(strlen($brute)<24)throw new RuntimeException('Définissez COMMUNICATION_CONFIG_KEY avec au moins 24 caractères.');
    return hash('sha256',$brute,true);
}

function communicationChiffrerSecret(string $secret):string{
    if($secret==='')return '';$iv=random_bytes(12);$tag='';
    $chiffre=openssl_encrypt($secret,'aes-256-gcm',communicationCleConfiguration(),OPENSSL_RAW_DATA,$iv,$tag);
    if($chiffre===false)throw new RuntimeException('Le secret ne peut pas être chiffré.');
    return base64_encode($iv.$tag.$chiffre);
}

function communicationDechiffrerSecret(?string $valeur):string{
    if(!$valeur)return '';$brut=base64_decode($valeur,true);if($brut===false||strlen($brut)<29)return '';
    $clair=openssl_decrypt(substr($brut,28),'aes-256-gcm',communicationCleConfiguration(),OPENSSL_RAW_DATA,substr($brut,0,12),substr($brut,12,16));
    return $clair===false?'':$clair;
}

function communicationRoleCodes():array{
    return ['SUPER_ADMIN','ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','STAGIAIRE','ADMIN_ACCUEIL','ENCADREUR','AUTORITE','RECRUTEUR','MINISTERE','ORDRE_MEDECINS','POINTEUR','CHEF_SERVICE','AUTORITE_HOSPITALIERE','COORDINATEUR_STAGES','EVALUATEUR_CLINIQUE','GESTIONNAIRE_FINANCIER_HOSPITALIER'];
}
function communicationUtilisateurAutorise(PDO $pdo):bool{
    return hasRole(communicationRoleCodes())||estResponsableMinisteriel($pdo);
}
function requireCommunicationRole():void{
    global $pdo;
    if(!$pdo instanceof PDO||!communicationUtilisateurAutorise($pdo)){
        http_response_code(403);
        exit('Accès refusé.');
    }
}
function communicationEstSuperAdministrateur():bool{return hasRole('SUPER_ADMIN');}
function communicationEstEtudiant():bool{return hasRole('STAGIAIRE');}

/** Établissements dans lesquels l'émetteur peut chercher des destinataires. */
function communicationEtablissementsMinisteriels(PDO $pdo,int $utilisateurId):array{
    $ministeres=ministeresRepresentes($pdo,$utilisateurId);
    if(!$ministeres)return [];
    $ministereIds=array_map('intval',array_column($ministeres,'id'));
    $ph=implode(',',array_fill(0,count($ministereIds),'?'));
    $q=$pdo->prepare("SELECT organisation_etablissement_id
        FROM organisations_ministeres
        WHERE actif=1 AND ministere_etablissement_id IN ($ph)");
    $q->execute($ministereIds);
    return array_values(array_unique(array_merge(
        $ministereIds,
        array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN))
    )));
}
function communicationEtablissementId(PDO $pdo):?int{
    $id=currentEtablissementId($pdo);if($id)return $id;$session=(int)($_SESSION['etablissement_id']??0);if($session)return $session;
    $utilisateur=(int)($_SESSION['user_id']??0);if(!$utilisateur)return null;
    $q=$pdo->prepare("SELECT se.etablissement_id FROM student_profiles sp JOIN student_enrollments se ON se.student_id=sp.id AND se.statut='ACTIF' WHERE sp.user_id=? ORDER BY se.id DESC LIMIT 1");$q->execute([$utilisateur]);$id=(int)$q->fetchColumn();if($id)return $id;
    $q=$pdo->prepare('SELECT etablissement_id FROM etablissement_users WHERE user_id=? ORDER BY principal DESC,id LIMIT 1');$q->execute([$utilisateur]);$id=(int)$q->fetchColumn();return $id?:null;
}

function communicationHistorique(PDO $pdo,string $type,int $id,array $avant,array $apres,int $acteur):void{
    $pdo->prepare('INSERT INTO historique_modifications_communication(type_ressource,ressource_id,anciennes_valeurs,nouvelles_valeurs,modifie_par_utilisateur_id) VALUES(?,?,?,?,?)')->execute([$type,$id,json_encode($avant,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($apres,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$acteur]);
}
function communicationMettreCorbeille(PDO $pdo,string $type,int $id,string $libelle,string $etat,array $donnees,int $acteur):void{
    $pdo->prepare('INSERT INTO corbeille_communications(type_ressource,ressource_id,libelle,etat_avant,donnees,supprime_par_utilisateur_id) VALUES(?,?,?,?,?,?)')->execute([$type,$id,mb_substr($libelle,0,255),$etat,json_encode($donnees,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$acteur]);
}
function communicationCanalActif(PDO $pdo,int $utilisateurId,string $type,string $canal):bool{
    $q=$pdo->prepare("SELECT active FROM preferences_notifications WHERE utilisateur_id=? AND type_evenement IN (?, '*') AND canal=? ORDER BY type_evenement<>'*' DESC LIMIT 1");$q->execute([$utilisateurId,$type,$canal]);$valeur=$q->fetchColumn();return $valeur===false?true:(bool)$valeur;
}
function communicationDonneesNotification(PDO $pdo,int $utilisateurId,string $type,string $lien):array{
    if(in_array($type,['nouvelle_conversation','nouveau_message'],true)){
        $id=0;if(preg_match('/[?&]conversation=(\d+)/',$lien,$m))$id=(int)$m[1];
        if($id){$q=$pdo->prepare('SELECT identifiant_public,objet FROM conversations WHERE id=? AND EXISTS(SELECT 1 FROM participants_conversations p WHERE p.conversation_id=conversations.id AND p.utilisateur_id=? AND p.quitte_le IS NULL)');$q->execute([$id,$utilisateurId]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row)return ['conversation_uuid'=>$row['identifiant_public'],'action'=>['type'=>'conversation','target_id'=>$row['identifiant_public'],'label'=>'Ouvrir la discussion','title'=>$row['objet'],'metadata'=>[]]];}
    }
    if($type==='communication_officielle'){
        $q=$pdo->prepare("SELECT co.identifiant_public,co.objet FROM destinataires_communications d JOIN communications_officielles co ON co.id=d.communication_officielle_id WHERE d.utilisateur_id=? AND co.statut='publiee' ORDER BY d.id DESC LIMIT 1");$q->execute([$utilisateurId]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row)return ['communication_uuid'=>$row['identifiant_public'],'action'=>['type'=>'official_communication','target_id'=>$row['identifiant_public'],'label'=>'Lire le communique','title'=>$row['objet'],'metadata'=>[]]];
    }
    if(in_array($type,['invitation_calendrier','visioconference'],true)){
        $q=$pdo->prepare('SELECT ev.identifiant_public,ev.titre FROM participants_evenements p JOIN evenements_calendrier ev ON ev.id=p.evenement_calendrier_id WHERE p.utilisateur_id=? ORDER BY ev.id DESC LIMIT 1');$q->execute([$utilisateurId]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row)return ['event_uuid'=>$row['identifiant_public'],'action'=>['type'=>'calendar_event','target_id'=>$row['identifiant_public'],'label'=>"Voir l'evenement",'title'=>$row['titre'],'metadata'=>[]]];
    }
    return [];
}
function communicationNotifier(PDO $pdo,int $utilisateurId,?int $etablissementId,string $type,string $titre,string $contenu,string $lien,array $donnees=[]):void{
    if(!$donnees)$donnees=communicationDonneesNotification($pdo,$utilisateurId,$type,$lien);
    $notificationUuid=communicationUlid();
    $json=$donnees?json_encode($donnees,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $pdo->prepare('INSERT INTO notifications(identifiant_public,utilisateur_id,organisation_id,type_evenement,titre,contenu,donnees,lien_action) VALUES(?,?,?,?,?,?,?,?)')->execute([$notificationUuid,$utilisateurId,$etablissementId,$type,$titre,$contenu,$json,$lien]);$notificationId=(int)$pdo->lastInsertId();
    $q=$pdo->prepare('SELECT email,telephone FROM users WHERE id=?');$q->execute([$utilisateurId]);$contact=$q->fetch()?:[];
    foreach(['email'=>(string)($contact['email']??''),'sms'=>(string)($contact['telephone']??''),'push'=>'utilisateur:'.$utilisateurId] as $canal=>$destination){
        if($destination===''||!communicationCanalActif($pdo,$utilisateurId,$type,$canal))continue;
        $pdo->prepare("INSERT IGNORE INTO livraisons_notifications(notification_id,canal,destinataire,statut,prochaine_tentative_le) VALUES(?,?,?,'en_attente',UTC_TIMESTAMP(6))")->execute([$notificationId,$canal,$destination]);
        $charge=json_encode(['notification_id'=>$notificationId,'notification_uuid'=>$notificationUuid,'canal'=>$canal,'destinataire'=>$destination,'type'=>$type,'titre'=>$titre,'contenu'=>$contenu,'lien'=>$lien,'donnees'=>$donnees,'schema_version'=>'1'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $pdo->prepare("INSERT INTO taches_asynchrones(file_attente,type_tache,charge_utile,statut,priorite,disponible_le) VALUES('notifications','livrer_notification',?,'en_attente',10,UTC_TIMESTAMP(6))")->execute([$charge]);
        $pdo->prepare("INSERT INTO messages_sortants(type_evenement,charge_utile,statut,disponible_le) VALUES('notification.multicanal',?,'en_attente',UTC_TIMESTAMP(6))")->execute([$charge]);
    }
}
function communicationVerifierParticipant(PDO $pdo,int $conversationId,int $utilisateurId):bool{$q=$pdo->prepare('SELECT COUNT(*) FROM participants_conversations WHERE conversation_id=? AND utilisateur_id=? AND quitte_le IS NULL');$q->execute([$conversationId,$utilisateurId]);return(int)$q->fetchColumn()>0;}

function communicationContexteAcademique(PDO $pdo,int $utilisateurId):array{
    $q=$pdo->prepare("SELECT se.etablissement_id,sae.promotion_id,sae.annee_academique_id,f.id faculte_id,f.nom faculte,p.nom promotion,aa.libelle annee_academique
      FROM student_profiles sp JOIN student_enrollments se ON se.student_id=sp.id AND se.statut='ACTIF'
      JOIN student_academic_enrollments sae ON sae.enrollment_id=se.id AND sae.statut='EN_COURS'
      JOIN promotions p ON p.id=sae.promotion_id JOIN annees_academiques aa ON aa.id=sae.annee_academique_id
      JOIN filieres fi ON fi.id=p.filiere_id LEFT JOIN facultes f ON f.id=fi.faculte_id
      WHERE sp.user_id=? ORDER BY sae.id DESC LIMIT 1");$q->execute([$utilisateurId]);return $q->fetch()?:[];
}
function communicationEtablissementsAccueilEtudiant(PDO $pdo,int $utilisateurId):array{
    $q=$pdo->prepare("SELECT DISTINCT plc.host_etablissement_id FROM student_profiles sp JOIN stage_placements plc ON plc.student_id=sp.id AND plc.statut IN ('CONFIRME','TERMINE') WHERE sp.user_id=?");$q->execute([$utilisateurId]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
function communicationEtablissementsPartenaires(PDO $pdo,?int $etablissementId):array{
    if(!$etablissementId)return [];
    $q=$pdo->prepare("SELECT DISTINCT part.host_etablissement_id FROM stage_campaigns camp JOIN stage_campaign_participations part ON part.university_campaign_id=camp.id AND part.statut IN ('ACCEPTEE','CLOTUREE') WHERE camp.owner_etablissement_id=?");$q->execute([$etablissementId]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
function communicationRolesUtilisateur(PDO $pdo,int $utilisateurId):array{
    $q=$pdo->prepare("SELECT DISTINCT r.code FROM role_assignments ra JOIN roles r ON r.id=ra.role_id AND r.actif=1 WHERE ra.user_id=? AND ra.actif=1 AND ra.revoked_at IS NULL AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())");$q->execute([$utilisateurId]);$roles=$q->fetchAll(PDO::FETCH_COLUMN);
    if(!$roles){$q=$pdo->prepare('SELECT r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.actif=1');$q->execute([$utilisateurId]);$roles=$q->fetchAll(PDO::FETCH_COLUMN);}return array_values(array_unique(array_map('strval',$roles)));
}
function communicationDestinatairesAutorises(PDO $pdo,int $utilisateurId,?int $etablissementId,string $role=''):array{
    $estEtudiant=communicationEstEtudiant();$estMinisteriel=estResponsableMinisteriel($pdo,$utilisateurId);$perimetreMinisteriel=$estMinisteriel?communicationEtablissementsMinisteriels($pdo,$utilisateurId):[];$accueils=$estEtudiant?communicationEtablissementsAccueilEtudiant($pdo,$utilisateurId):[];$partenaires=$estEtudiant?communicationEtablissementsPartenaires($pdo,$etablissementId):[];
    $q=$pdo->prepare("SELECT DISTINCT u.id,u.prenom,u.postnom,u.nom,u.email,e.nom organisation,e.type_etablissement types_organisation,r.nom role_nom,r.code role_code,COALESCE(ra.etablissement_id,eu.etablissement_id,se.etablissement_id) organisation_id,sae.promotion_id,sae.annee_academique_id,f.id faculte_id,f.nom faculte,p.nom promotion,aa.libelle annee_academique
      FROM users u JOIN roles r ON r.id=u.role_id AND r.actif=1
      LEFT JOIN role_assignments ra ON ra.user_id=u.id AND ra.actif=1 AND ra.revoked_at IS NULL AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
      LEFT JOIN etablissement_users eu ON eu.user_id=u.id
      LEFT JOIN student_profiles sp ON sp.user_id=u.id LEFT JOIN student_enrollments se ON se.student_id=sp.id AND se.statut='ACTIF'
      LEFT JOIN etablissements e ON e.id=COALESCE(ra.etablissement_id,eu.etablissement_id,se.etablissement_id)
      LEFT JOIN student_academic_enrollments sae ON sae.enrollment_id=se.id AND sae.statut='EN_COURS'
      LEFT JOIN promotions p ON p.id=sae.promotion_id LEFT JOIN annees_academiques aa ON aa.id=sae.annee_academique_id
      LEFT JOIN filieres fi ON fi.id=p.filiere_id LEFT JOIN facultes f ON f.id=fi.faculte_id
      WHERE u.id<>? AND u.actif=1 AND u.statut_compte='ACTIF' ORDER BY e.nom,u.nom,u.prenom");$q->execute([$utilisateurId]);$resultats=[];
    foreach($q->fetchAll() as $personne){$id=(int)$personne['id'];$destEtab=(int)($personne['organisation_id']??0);$destRoles=communicationRolesUtilisateur($pdo,$id);$destEtudiant=in_array('STAGIAIRE',$destRoles,true);$autorise=false;
        if(communicationEstSuperAdministrateur())$autorise=true;elseif($estMinisteriel){$autorise=in_array($destEtab,$perimetreMinisteriel,true);}elseif($estEtudiant){$meme=$destEtab===$etablissementId;$autorise=$meme||(!$destEtudiant&&(in_array($destEtab,$accueils,true)||in_array($destEtab,$partenaires,true)));}else{$autorise=!$destEtudiant||$destEtab===$etablissementId;}
        if($autorise){$personne['role_code']=$destEtudiant?'STAGIAIRE':($destRoles[0]??$personne['role_code']);$personne['role_nom']=$destEtudiant?'Stagiaire':$personne['role_nom'];$resultats[$id]=$personne;}}
    return array_values($resultats);
}
function communicationPeutContacter(PDO $pdo,int $utilisateurId,?int $etablissementId,string $role,int $destinataireId):bool{foreach(communicationDestinatairesAutorises($pdo,$utilisateurId,$etablissementId,$role) as $p)if((int)$p['id']===$destinataireId)return true;return false;}
function communicationResoudreCible(PDO $pdo,int $utilisateurId,?int $etablissementId,string $role,string $cible):array{
    $autorises=communicationDestinatairesAutorises($pdo,$utilisateurId,$etablissementId,$role);$contexte=communicationContexteAcademique($pdo,$utilisateurId);$perimetreMinisteriel=estResponsableMinisteriel($pdo,$utilisateurId)?communicationEtablissementsMinisteriels($pdo,$utilisateurId):[];$ids=[];
    if(str_starts_with($cible,'personne:')){$id=(int)substr($cible,9);foreach($autorises as $p)if((int)$p['id']===$id)$ids[]=$id;return $ids;}
    $groupe=str_starts_with($cible,'groupe:')?substr($cible,7):'';foreach($autorises as $p){$code=(string)($p['role_code']??'');$type=strtoupper((string)($p['types_organisation']??''));$etu=$code==='STAGIAIRE';$destEtab=(int)($p['organisation_id']??0);$memeOuMinisteriel=$destEtab===$etablissementId||in_array($destEtab,$perimetreMinisteriel,true);$inclure=match($groupe){'etudiants'=>$etu&&$memeOuMinisteriel,'faculte'=>$etu&&!empty($contexte['faculte_id'])&&(int)($p['faculte_id']??0)===(int)$contexte['faculte_id'],'promotion'=>$etu&&!empty($contexte['promotion_id'])&&(int)($p['promotion_id']??0)===(int)$contexte['promotion_id'],'annee_academique'=>$etu&&!empty($contexte['annee_academique_id'])&&(int)($p['annee_academique_id']??0)===(int)$contexte['annee_academique_id'],'universites'=>!$etu&&in_array($type,['UNIVERSITE','INSTITUT','INSTITUT_SUPERIEUR'],true),'responsables'=>!$etu,'hopitaux'=>!$etu&&in_array($type,['HOPITAL','CLINIQUE','CENTRE_SANTE'],true),default=>false};if($inclure)$ids[]=(int)$p['id'];}return array_values(array_unique($ids));
}
function communicationEnregistrerFichier(PDO $pdo,array $fichier,int $utilisateurId):int{
    if(($fichier['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return 0;if(($fichier['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Le transfert de la pièce jointe a échoué.');
    $taille=(int)($fichier['size']??0);if($taille<1||$taille>10*1024*1024)throw new RuntimeException('La pièce jointe doit avoir une taille maximale de 10 Mo.');$nom=(string)($fichier['name']??'fichier');$extension=strtolower(pathinfo($nom,PATHINFO_EXTENSION));if(!in_array($extension,['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','jpg','jpeg','png','webp'],true))throw new RuntimeException('Type de fichier non autorisé.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$fichier['tmp_name'])?:'application/octet-stream';$stockage=bin2hex(random_bytes(24)).'.'.$extension;$dossier=__DIR__.'/../storage/communication';if(!is_dir($dossier)&&!mkdir($dossier,0770,true)&&!is_dir($dossier))throw new RuntimeException('Le dossier de stockage ne peut pas être créé.');$destination=$dossier.'/'.$stockage;if(!move_uploaded_file((string)$fichier['tmp_name'],$destination))throw new RuntimeException('Impossible d’enregistrer la pièce jointe.');
    $pdo->prepare('INSERT INTO fichiers(identifiant_public,nom_original,nom_stockage,chemin_stockage,type_mime,taille_octets,empreinte_sha256,cree_par_utilisateur_id) VALUES(?,?,?,?,?,?,?,?)')->execute([communicationUlid(),mb_substr($nom,0,255),$stockage,'storage/communication/'.$stockage,$mime,$taille,hash_file('sha256',$destination),$utilisateurId]);return(int)$pdo->lastInsertId();
}
