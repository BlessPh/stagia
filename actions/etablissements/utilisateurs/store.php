<?php
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/establishment-user-admin.php';
require_once __DIR__.'/../../../services/MailService.php';

requireEstablishmentAjaxPermission('user.create');

$csrf=(string)($_POST['csrf']??'');
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals((string)$_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

function stgLocalRoleAllowedFallback(PDO $pdo,int $roleId,array $ctx):?array{
    $eid=(int)($ctx['id']??0);
    $type=strtoupper((string)($ctx['type_etablissement']??''));

    $hostTypes=['HOPITAL','CENTRE_SANTE','CLINIQUE','ETABLISSEMENT_SANTE','STRUCTURE_ACCUEIL'];
    $hostCodes=[
        'ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE','ENCADREUR','POINTEUR',
        'EVALUATEUR_CLINIQUE','GESTIONNAIRE_FINANCIER_HOSPITALIER','AUTORITE_HOSPITALIERE'
    ];
    $academicCodes=['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'];
    $codes=in_array($type,$hostTypes,true)?$hostCodes:$academicCodes;
    $in=implode(',',array_fill(0,count($codes),'?'));

    $s=$pdo->prepare("SELECT id,code,nom,actif,systeme,etablissement_id FROM roles WHERE id=? AND actif=1 AND (code IN($in) OR (systeme=0 AND etablissement_id=?)) LIMIT 1");
    $s->execute(array_merge([$roleId],$codes,[$eid]));
    $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}

function stgSelectedUnitId():int{
    foreach(['scope_unit_id','unit_scope_id','coordination_unit_id','host_unit_id','unit_id'] as $k){
        $v=(int)($_POST[$k]??0);
        if($v>0)return $v;
    }
    return 0;
}

function stgLoadCoordination(PDO $pdo,int $eid,int $unitId):?array{
    if($unitId<=0)return null;
    $types=['COORDINATION','DEPARTEMENT','DÉPARTEMENT','DEPARTMENT','DIRECTION','UNITE','UNITÉ'];
    $in=implode(',',array_fill(0,count($types),'?'));
    $s=$pdo->prepare("SELECT id,nom,type FROM host_units WHERE id=? AND host_etablissement_id=? AND actif=1 AND (parent_id IS NULL OR parent_id=0) AND UPPER(type) IN($in) LIMIT 1");
    $s->execute(array_merge([$unitId,$eid],$types));
    $u=$s->fetch(PDO::FETCH_ASSOC);
    return $u?:null;
}

try{
    $ctx=establishmentUserAdminContext($pdo);
    $eid=(int)$ctx['id'];
    $actorId=(int)($_SESSION['user_id']??0);

    $nom=trim((string)($_POST['nom']??''));
    $postnom=trim((string)($_POST['postnom']??''));
    $prenom=trim((string)($_POST['prenom']??''));
    $email=strtolower(trim((string)($_POST['email']??'')));
    $telephone=trim((string)($_POST['telephone']??''));
    $fonction=trim((string)($_POST['fonction']??''));
    $roleId=(int)($_POST['role_id']??0);
    $activationMode=strtoupper(trim((string)($_POST['activation_mode']??'INVITATION')));
    if(!in_array($activationMode,['INVITATION','PASSWORD'],true))$activationMode='INVITATION';

    if($nom==='')throw new RuntimeException('Le nom est obligatoire.');
    if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Une adresse e-mail valide est obligatoire.');
    if(!$roleId)throw new RuntimeException('Sélectionnez le rôle principal.');

    $role=establishmentRoleAllowed($pdo,$roleId,$ctx) ?: stgLocalRoleAllowedFallback($pdo,$roleId,$ctx);
    if(!$role)throw new RuntimeException("Ce rôle n'est pas assignable par cet établissement.");

    $roleCode=strtoupper((string)$role['code']);
    if($fonction===''&&$roleCode==='CHEF_SERVICE')$fonction='Chef de service';

    $unitId=stgSelectedUnitId();
    $scopeType='ORGANIZATION';
    $scopeEntity='ESTABLISHMENT';
    $scopeId=$eid;

    $needsCoord=in_array($roleCode,['CHEF_SERVICE','COORDINATEUR_STAGES'],true);
    $mayCoord=in_array($roleCode,['ENCADREUR','EVALUATEUR_CLINIQUE'],true);

    if($needsCoord||($mayCoord&&$unitId>0)){
        $unit=stgLoadCoordination($pdo,$eid,$unitId);
        if(!$unit){
            $label=$roleCode==='CHEF_SERVICE'?'chef de service':($roleCode==='COORDINATEUR_STAGES'?'coordinateur':'utilisateur');
            throw new RuntimeException('Sélectionnez un département / une coordination valide pour ce '.$label.'.');
        }
        $scopeType='UNIT';
        $scopeEntity='HOST_UNIT';
        $scopeId=(int)$unit['id'];
    }

    $s=$pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) LIMIT 1");
    $s->execute([$email]);
    if($s->fetchColumn())throw new RuntimeException('Cette adresse e-mail est déjà utilisée par un compte STAGIA.');

    $identifier=generateEstablishmentUserIdentifier($pdo,(string)$ctx['code']);
    $token=null;$tokenHash=null;$expiry=null;$mailSent=false;

    if($activationMode==='PASSWORD'){
        $pass=(string)($_POST['temporary_password']??'');
        $pass2=(string)($_POST['temporary_password_confirm']??'');
        if(strlen($pass)<8)throw new RuntimeException('Le mot de passe temporaire doit contenir au moins 8 caractères.');
        if($pass!==$pass2)throw new RuntimeException('Les deux mots de passe temporaires ne correspondent pas.');
        $passwordHash=password_hash($pass,PASSWORD_DEFAULT);
        $actif=1;$statut='ACTIF';$mustChange=1;
    }else{
        $token=bin2hex(random_bytes(32));
        $tokenHash=hash('sha256',$token);
        $hours=activationExpiryHours($pdo);
        $expiry=date('Y-m-d H:i:s',time()+($hours*3600));
        $passwordHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        $actif=0;$statut='A_ACTIVER';$mustChange=0;
    }

    $pdo->beginTransaction();

    $s=$pdo->prepare("INSERT INTO users(role_id,nom,postnom,prenom,email,identifiant,password,telephone,actif,statut_compte,activation_token_hash,activation_expire_at,must_change_password) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([$roleId,$nom,$postnom?:null,$prenom?:null,$email,$identifier,$passwordHash,$telephone?:null,$actif,$statut,$tokenHash,$expiry,$mustChange]);
    $userId=(int)$pdo->lastInsertId();

    $s=$pdo->prepare("INSERT INTO etablissement_users(etablissement_id,user_id,fonction,principal,created_at) VALUES(?,?,?,0,NOW())");
    $s->execute([$eid,$userId,$fonction?:null]);

    $s=$pdo->prepare("INSERT INTO role_assignments(user_id,role_id,scope_type,scope_entity,scope_id,etablissement_id,principal,actif,starts_at,ends_at,assigned_by,created_at) VALUES(?,?,?,?,?,?,1,1,NOW(),NULL,?,NOW())");
    $s->execute([$userId,$roleId,$scopeType,$scopeEntity,$scopeId,$eid,$actorId?:null]);

    $pdo->commit();

    if($activationMode==='INVITATION'){
        $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
        $host=$_SERVER['HTTP_HOST']??'localhost';
        $activationUrl=$scheme.'://'.$host.BASE_URL.'/activate.php?token='.urlencode((string)$token);
        $displayName=trim(implode(' ',array_filter([$prenom,$nom,$postnom])));
        try{$mailSent=MailService::envoyerActivation($email,$displayName?:$nom,$activationUrl);}catch(Throwable $mailError){error_log('[LOCAL USER INVITATION] '.$mailError->getMessage());}
    }

    jsonResponse(true,
        $activationMode==='PASSWORD'
            ?'Utilisateur créé avec mot de passe temporaire. Aucun e-mail d’invitation envoyé.'
            :($mailSent?'Utilisateur créé. Invitation envoyée par e-mail.':'Utilisateur créé, mais l’e-mail d’activation n’a pas pu être envoyé.'),
        ['id'=>$userId,'identifiant'=>$identifier,'role_code'=>$roleCode,'activation_mode'=>$activationMode,'mail_sent'=>$mailSent,'scope_type'=>$scopeType,'scope_id'=>$scopeId]
    );
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
