<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../services/MailService.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$adminContexte=exigerAdministrationRolesAjax($pdo);verifyAjaxCsrf();
if(function_exists('contextPermission')&&(!contextPermission('user.create')||!contextPermission('user.role.assign')))
    jsonResponse(false,'Permission insuffisante.',[],403);

$actorRole=$_SESSION['role_code']??'';$super=$adminContexte['super'];
$nom=trim($_POST['nom']??'');$postnom=trim($_POST['postnom']??'');$prenom=trim($_POST['prenom']??'');
$email=strtolower(trim($_POST['email']??''));$telephone=trim($_POST['telephone']??'');
$roleId=(int)($_POST['role_id']??0);$fonction=trim($_POST['fonction']??'');
$activationMode=strtoupper(trim($_POST['activation_mode']??'INVITATION'));
$tempPassword=(string)($_POST['temporary_password']??'');
$tempPasswordConfirm=(string)($_POST['temporary_password_confirm']??'');
$eid=$super?((int)($_POST['etablissement_id']??0)?:null):(int)$adminContexte['etablissement_id'];

if(!$super&&!$eid)jsonResponse(false,'Aucun établissement associé à votre compte.',[],403);
if($nom===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!$roleId)
    jsonResponse(false,'Nom, e-mail valide et rôle principal sont obligatoires.',[],422);
if(!in_array($activationMode,['INVITATION','PASSWORD'],true))
    jsonResponse(false,'Mode d’activation invalide.',[],422);
if($activationMode==='PASSWORD'){
    if(strlen($tempPassword)<8)
        jsonResponse(false,'Le mot de passe temporaire doit contenir au moins 8 caractères.',[],422);
    if(!hash_equals($tempPassword,$tempPasswordConfirm))
        jsonResponse(false,'La confirmation du mot de passe ne correspond pas.',[],422);
}

function stagiaUniqueIdentifier(PDO $pdo,string $prenom,string $nom,string $email):string{
    $base=$prenom!==''?$prenom.'.'.$nom:explode('@',$email)[0];
    $base=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$base)?:$base;
    $base=strtolower(trim(preg_replace('/[^a-zA-Z0-9._-]+/','.', $base),'.-_'))?:'user';
    $s=$pdo->prepare("SELECT 1 FROM users WHERE identifiant=? LIMIT 1");$candidate=$base;$n=2;
    while(true){$s->execute([$candidate]);if(!$s->fetchColumn())return $candidate;$candidate=$base.$n++;}
}

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) LIMIT 1 FOR UPDATE");
    $s->execute([$email]);if($s->fetchColumn())throw new RuntimeException('Cette adresse e-mail est déjà utilisée.');

    $s=$pdo->prepare("SELECT id,code,nom,actif,systeme,etablissement_id FROM roles WHERE id=? LIMIT 1");
    $s->execute([$roleId]);$role=$s->fetch(PDO::FETCH_ASSOC);
    if(!$role||!(int)$role['actif'])throw new RuntimeException('Rôle principal invalide ou inactif.');

    $scopeType='ORGANIZATION';$scopeEntity='ESTABLISHMENT';$scopeId=$eid;
    if(!$super){
        $allowed=$actorRole==='ADMIN_ACCUEIL'?['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','POINTEUR','GESTIONNAIRE_FINANCIER_HOSPITALIER']:['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'];
        $roleLocal=(int)$role['systeme']===0&&(int)$role['etablissement_id']===$eid;
        if(!$roleLocal&&!in_array($role['code'],$allowed,true))throw new RuntimeException('Ce rôle ne peut pas être attribué depuis votre établissement.');
        $s=$pdo->prepare("SELECT id FROM etablissements WHERE id=? AND statut IN('VALIDE','ACTIF') LIMIT 1");
        $s->execute([$eid]);if(!$s->fetchColumn())throw new RuntimeException('Établissement invalide ou inactif.');
    }else{
        if($role['code']==='SUPER_ADMIN'){
            $scopeType='PLATFORM';$scopeEntity='PLATFORM';$scopeId=null;$eid=null;$fonction='';
        }elseif($role['code']==='MINISTERE'){
            if(!$eid)throw new RuntimeException('Sélectionnez obligatoirement le ministère représenté.');
            $s=$pdo->prepare("SELECT id FROM etablissements WHERE id=? AND type_etablissement='MINISTERE' AND statut IN('VALIDE','ACTIF') LIMIT 1");
            $s->execute([$eid]);if(!$s->fetchColumn())throw new RuntimeException('Le ministère sélectionné est invalide ou inactif.');
            $scopeType='ORGANIZATION';$scopeEntity='ESTABLISHMENT';$scopeId=$eid;
            if($fonction==='')$fonction='Responsable ministériel';
        }elseif($role['code']==='ORDRE_MEDECINS'){
            /* Compatibilité conservée tant que les ordres professionnels ne
               disposent pas encore d'un type d'organisation dédié. */
            $scopeType='PLATFORM';$scopeEntity='PLATFORM';$scopeId=null;$eid=null;$fonction='';
        }elseif($role['code']==='STAGIAIRE'){
            $scopeType='SELF';$scopeEntity='USER';$scopeId=null;$eid=null;$fonction='';
        }else{
            if(!$eid)throw new RuntimeException('Sélectionnez l’établissement concerné par ce rôle.');
            $s=$pdo->prepare("SELECT id FROM etablissements WHERE id=? AND statut IN('VALIDE','ACTIF') LIMIT 1");
            $s->execute([$eid]);if(!$s->fetchColumn())throw new RuntimeException('Établissement invalide.');
            if((int)$role['systeme']===0&&(int)$role['etablissement_id']!==$eid)
                throw new RuntimeException('Ce rôle local appartient à un autre établissement.');
        }
    }

    $identifiant=stagiaUniqueIdentifier($pdo,$prenom,$nom,$email);
    $token=null;$tokenHash=null;$expiration=null;
    if($activationMode==='INVITATION'){
        $token=bin2hex(random_bytes(32));$tokenHash=hash('sha256',$token);
        $expiration=date('Y-m-d H:i:s',time()+48*3600);
        $passwordHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        $actif=0;$statut='A_ACTIVER';
    }else{
        $passwordHash=password_hash($tempPassword,PASSWORD_DEFAULT);
        $actif=1;$statut='ACTIF';
    }

    $pdo->prepare("INSERT INTO users(role_id,nom,postnom,prenom,email,identifiant,password,telephone,actif,statut_compte,activation_token_hash,activation_expire_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
        $roleId,$nom,$postnom?:null,$prenom?:null,$email,$identifiant,$passwordHash,$telephone?:null,
        $actif,$statut,$tokenHash,$expiration
    ]);
    $userId=(int)$pdo->lastInsertId();if($scopeType==='SELF')$scopeId=$userId;

    $pdo->prepare("INSERT INTO role_assignments(user_id,role_id,scope_type,scope_entity,scope_id,etablissement_id,principal,actif,assigned_by)
        VALUES(?,?,?,?,?,?,1,1,?)")->execute([$userId,$roleId,$scopeType,$scopeEntity,$scopeId,$eid,$_SESSION['user_id']??null]);

    if($eid)$pdo->prepare("INSERT INTO etablissement_users(etablissement_id,user_id,fonction,principal) VALUES(?,?,?,1)")
        ->execute([$eid,$userId,$fonction?:null]);

    $pdo->commit();

    if($activationMode==='PASSWORD'){
        jsonResponse(true,'Utilisateur créé et activé avec le mot de passe temporaire. Aucun e-mail envoyé.',[
            'id'=>$userId,'identifiant'=>$identifiant,'mail_sent'=>false,'activation_mode'=>'PASSWORD'
        ]);
    }

    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    $url=$scheme.'://'.$_SERVER['HTTP_HOST'].BASE_URL.'/activate.php?token='.urlencode($token);
    $nomComplet=trim($prenom.' '.$nom.' '.$postnom);$mail=MailService::envoyerActivation($email,$nomComplet,$url);
    if(!$mail)error_log('[USER CREATE SMTP] '.MailService::getLastError());

    jsonResponse(true,$mail?'Utilisateur créé et invitation envoyée.':'Utilisateur créé. L’e-mail d’activation n’a pas pu être envoyé.',[
        'id'=>$userId,'identifiant'=>$identifiant,'mail_sent'=>$mail,'activation_mode'=>'INVITATION'
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
