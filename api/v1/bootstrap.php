<?php

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/rbac-session.php';

const API_ACCESS_TOKEN_TTL = 3600;
const API_REFRESH_TOKEN_TTL = 2592000;

function apiResponse(bool $success,string $message='',array $data=[],int $status=200):void{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(compact('success','message','data'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Signale un endpoint historique sans interrompre les clients existants.
 *
 * La suppression reste progressive : le client reçoit la date de fin de
 * support et, lorsqu'elle est connue, la route canonique à utiliser.
 */
function apiDeprecation(?string $successor=null,string $sunset='Tue, 31 Mar 2027 23:59:59 GMT'):void{
    header('Deprecation: true');
    header('Sunset: '.$sunset);
    header('Warning: 299 - "Endpoint obsolete; migrate to the canonical extensionless route"');
    if($successor!==null&&$successor!==''){
        header('Link: <'.$successor.'>; rel="successor-version"');
    }
}

/** Les fichiers PHP sont des détails d'implémentation, pas le contrat mobile. */
function apiDeprecateDirectPhpRequest():void{
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)??'');
    if($path!==''&&preg_match('~/api/v1/.+\.php$~i',$path)===1){
        apiDeprecation();
    }
}

apiDeprecateDirectPhpRequest();

function requireApiMethod(string $method):void{
    $expected=strtoupper($method);
    if(strtoupper($_SERVER['REQUEST_METHOD']??'')!==$expected){
        header('Allow: '.$expected);
        apiResponse(false,'Méthode HTTP non autorisée.',[],405);
    }
}

function apiInput():array{
    $contentType=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
    if(str_contains($contentType,'application/json')){
        $data=json_decode(file_get_contents('php://input')?:'',true);
        if(!is_array($data) || json_last_error()!==JSON_ERROR_NONE){
            apiResponse(false,'Corps JSON invalide.',[],400);
        }
        return $data;
    }
    return is_array($_POST)?$_POST:[];
}

function bearerToken():?string{
    $header=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(!$header && function_exists('getallheaders')){
        $headers=getallheaders();
        $header=$headers['Authorization']??$headers['authorization']??'';
    }
    if(preg_match('/^Bearer\s+(\S+)$/i',trim((string)$header),$matches)){
        return $matches[1];
    }
    return null;
}

function apiRandomToken(int $bytes):string{
    return bin2hex(random_bytes($bytes));
}

function apiTokenPayload(string $accessToken,string $refreshToken):array{
    return [
        'access_token'=>$accessToken,
        'refresh_token'=>$refreshToken,
        'token_type'=>'Bearer',
        'expires_in'=>API_ACCESS_TOKEN_TTL,
        'refresh_expires_in'=>API_REFRESH_TOKEN_TTL
    ];
}

function createApiSession(PDO $pdo,int $userId,string $name='mobile'):array{
    $accessToken=apiRandomToken(32);
    $refreshToken=apiRandomToken(48);
    $deviceName=trim($name)?:'mobile';
    $stmt=$pdo->prepare("
        INSERT INTO api_tokens(
            user_id,token_hash,refresh_token_hash,name,expires_at,refresh_expires_at
        ) VALUES(
            ?,?,?,?,DATE_ADD(NOW(),INTERVAL ? SECOND),DATE_ADD(NOW(),INTERVAL ? SECOND)
        )
    ");
    $stmt->execute([
        $userId,
        hash('sha256',$accessToken),
        hash('sha256',$refreshToken),
        substr($deviceName,0,100),
        API_ACCESS_TOKEN_TTL,
        API_REFRESH_TOKEN_TTL
    ]);
    return apiTokenPayload($accessToken,$refreshToken);
}

/** Compatibilité avec le contrat historique qui ne renvoyait qu'un access token. */
function createApiToken(PDO $pdo,int $userId,string $name='mobile'):string{
    return createApiSession($pdo,$userId,$name)['access_token'];
}

function requireApiUser(PDO $pdo):array{
    $token=bearerToken();
    if(!$token){
        apiResponse(false,'Authentification requise.',[],401);
    }

    $stmt=$pdo->prepare("
        SELECT t.id AS token_id,t.user_id,t.name AS token_name,t.expires_at AS token_expires_at,u.*
        FROM api_tokens t
        INNER JOIN users u ON u.id=t.user_id
        WHERE t.token_hash=? AND t.expires_at>NOW()
        LIMIT 1
    ");
    $stmt->execute([hash('sha256',$token)]);
    $user=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$user){
        apiResponse(false,'Token invalide ou expiré.',[],401);
    }

    if(!(int)$user['actif'] || ($user['statut_compte']??'ACTIF')!=='ACTIF'){
        $pdo->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$user['token_id']]);
        apiResponse(false,"Ce compte n'est plus autorisé à se connecter.",[],401);
    }

    $access=loadUserAccessContext($pdo,(int)$user['user_id']);
    if(empty($access['ok'])){
        $pdo->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$user['token_id']]);
        apiResponse(false,"Ce compte ne possède plus d'accès actif.",[],403);
    }

    $pdo->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE id=?')->execute([$user['token_id']]);
    $user['access']=$access;
    return $user;
}

function requireApiStudent(PDO $pdo):array{
    $user=requireApiUser($pdo);
    if(!in_array('STAGIAIRE',$user['access']['role_codes']??[],true)){
        apiResponse(false,'Accès réservé aux étudiants.',[],403);
    }

    $stmt=$pdo->prepare("
        SELECT sp.id AS student_id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,sp.statut,
               u.id AS user_id,u.email,u.identifiant,'STAGIAIRE' AS role_code
        FROM student_profiles sp
        INNER JOIN users u ON u.id=sp.user_id
        WHERE sp.user_id=?
        LIMIT 1
    ");
    $stmt->execute([$user['user_id']]);
    $student=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$student){
        apiResponse(false,'Profil étudiant introuvable.',[],404);
    }
    if($student['statut']!=='ACTIF'){
        apiResponse(false,'Profil étudiant non actif.',[],403);
    }
    return $student;
}

function apiFindLoginUser(PDO $pdo,string $login):array{
    $stmt=$pdo->prepare("
        SELECT u.*
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id=u.id
        WHERE LOWER(TRIM(u.identifiant))=LOWER(TRIM(:login))
           OR LOWER(TRIM(u.email))=LOWER(TRIM(:email))
           OR LOWER(TRIM(sp.stagia_code))=LOWER(TRIM(:stagia_code))
        ORDER BY u.id LIMIT 2
    ");
    $stmt->execute(['login'=>$login,'email'=>$login,'stagia_code'=>$login]);
    $matches=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($matches)===1)return ['user'=>$matches[0],'ambiguous'=>false];
    if(count($matches)>1)return ['user'=>null,'ambiguous'=>true];

    $stmt=$pdo->prepare("
        SELECT u.* FROM users u
        WHERE LOWER(TRIM(u.nom))=LOWER(TRIM(?))
           OR LOWER(TRIM(u.prenom))=LOWER(TRIM(?))
           OR LOWER(TRIM(CONCAT_WS(' ',u.nom,u.postnom,u.prenom)))=LOWER(TRIM(?))
           OR LOWER(TRIM(CONCAT_WS(' ',u.prenom,u.nom,u.postnom)))=LOWER(TRIM(?))
        ORDER BY u.id LIMIT 2
    ");
    $stmt->execute([$login,$login,$login,$login]);
    $matches=$stmt->fetchAll(PDO::FETCH_ASSOC);
    return ['user'=>count($matches)===1?$matches[0]:null,'ambiguous'=>count($matches)>1];
}

function apiFindRecoveryUser(PDO $pdo,string $identifier):?array{
    $stmt=$pdo->prepare("
        SELECT u.id,u.nom,u.postnom,u.prenom,u.email,u.actif,u.statut_compte
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id=u.id
        WHERE LOWER(TRIM(u.identifiant))=LOWER(TRIM(:identifier))
           OR LOWER(TRIM(u.email))=LOWER(TRIM(:email))
           OR LOWER(TRIM(sp.stagia_code))=LOWER(TRIM(:stagia_code))
        ORDER BY u.id
        LIMIT 2
    ");
    $stmt->execute([
        'identifier'=>$identifier,
        'email'=>$identifier,
        'stagia_code'=>$identifier
    ]);
    $matches=$stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($matches)===1?$matches[0]:null;
}

function apiPasswordIsValid(string $password):bool{
    return strlen($password)>=8
        && preg_match('/[A-Z]/',$password)===1
        && preg_match('/[a-z]/',$password)===1
        && preg_match('/[0-9]/',$password)===1
        && preg_match('/[^A-Za-z0-9]/',$password)===1;
}

/** Transforme un chemin de fichier public en URL absolue utilisable par le mobile. */
function apiPublicFileUrl(?string $path):?string{
    $path=trim((string)$path);
    if($path==='')return null;
    if(filter_var($path,FILTER_VALIDATE_URL)!==false)return $path;

    $forwardedProto=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]);
    $scheme=$forwardedProto!==''
        ?$forwardedProto
        :((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http');
    if(!in_array($scheme,['http','https'],true))$scheme='https';

    $forwardedHost=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_HOST']??''))[0]);
    $host=$forwardedHost!==''?$forwardedHost:trim((string)($_SERVER['HTTP_HOST']??''));
    $base='/'.trim((string)(getenv('APP_BASE_URL')?:''),'/');
    if($base==='/')$base='';
    $normalized='/'.ltrim(str_replace('\\','/',$path),'/');
    $relative=$base!==''&&str_starts_with($normalized,$base.'/')
        ?$normalized
        :$base.$normalized;

    return $host!==''?$scheme.'://'.$host.$relative:$relative;
}

function apiUserData(PDO $pdo,int $userId):?array{
    $stmt=$pdo->prepare("
        SELECT u.id,u.identifiant,u.matricule AS user_matricule,
               u.nom,u.postnom,u.prenom,u.sexe,u.date_naissance,
               u.email,u.telephone,u.photo,u.adresse,u.ville,u.province,u.actif,u.statut_compte,
               sp.id AS student_id,sp.stagia_code,sp.nom AS student_nom,
               sp.postnom AS student_postnom,sp.prenom AS student_prenom,
               sp.sexe AS student_sexe,sp.date_naissance AS student_date_naissance,
               sp.email AS student_email,sp.telephone AS student_telephone,
               sp.photo AS student_photo,sp.statut AS student_status
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id=u.id
        WHERE u.id=? LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;

    $access=loadUserAccessContext($pdo,$userId);
    $studentId=$row['student_id']!==null?(int)$row['student_id']:null;
    $context=null;

    if($studentId!==null){
        $stmt=$pdo->prepare("
            SELECT se.matricule,e.adresse,e.ville,e.province
            FROM student_enrollments se
            INNER JOIN etablissements e ON e.id=se.etablissement_id
            WHERE se.student_id=?
            ORDER BY (se.statut='ACTIF') DESC,se.id DESC
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $context=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }else{
        $stmt=$pdo->prepare("
            SELECT NULL AS matricule,e.adresse,e.ville,e.province
            FROM etablissement_users membership
            INNER JOIN etablissements e ON e.id=membership.etablissement_id
            WHERE membership.user_id=?
            ORDER BY membership.principal DESC,membership.id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $context=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }

    $isStudent=$studentId!==null;
    $nom=$isStudent&&!empty($row['student_nom'])?$row['student_nom']:$row['nom'];
    $postnom=$isStudent&&!empty($row['student_postnom'])?$row['student_postnom']:$row['postnom'];
    $prenom=$isStudent&&!empty($row['student_prenom'])?$row['student_prenom']:$row['prenom'];
    $sexe=$isStudent&&!empty($row['student_sexe'])?$row['student_sexe']:$row['sexe'];
    $dateNaissance=$isStudent&&!empty($row['student_date_naissance'])
        ?$row['student_date_naissance']
        :$row['date_naissance'];
    $email=$isStudent&&!empty($row['student_email'])?$row['student_email']:$row['email'];
    $telephone=$isStudent&&!empty($row['student_telephone'])
        ?$row['student_telephone']
        :$row['telephone'];
    $photo=$isStudent&&!empty($row['student_photo'])?$row['student_photo']:$row['photo'];
    $adresse=$row['adresse']?:($context['adresse']??null);
    $ville=$row['ville']?:($context['ville']??null);
    $province=$row['province']?:($context['province']??null);
    $matricule=$context['matricule']??$row['user_matricule']??null;

    $data=[
        'user'=>[
            'id'=>(int)$row['id'],
            'identifiant'=>$row['identifiant'],
            'nom'=>$nom,
            'postnom'=>$postnom,
            'prenom'=>$prenom,
            'sexe'=>$sexe,
            'date_naissance'=>$dateNaissance,
            'adresse'=>$adresse,
            'ville'=>$ville,
            'province'=>$province,
            'avatar_url'=>apiPublicFileUrl($photo),
            'telephone'=>$telephone,
            'email'=>$email,
            'matricule'=>$matricule,
            'actif'=>(bool)$row['actif'],
            'statut_compte'=>$row['statut_compte'],
            'role'=>['code'=>$access['role_code']??null,'nom'=>$access['role_nom']??null],
            'roles'=>$access['role_codes']??[]
        ],
        'student'=>null
    ];
    if($studentId!==null){
        $data['student']=[
            'id'=>$studentId,
            'stagia_code'=>$row['stagia_code'],
            'nom'=>$nom,
            'postnom'=>$postnom,
            'prenom'=>$prenom,
            'sexe'=>$sexe,
            'date_naissance'=>$dateNaissance,
            'adresse'=>$adresse,
            'ville'=>$ville,
            'province'=>$province,
            'avatar_url'=>apiPublicFileUrl($photo),
            'telephone'=>$telephone,
            'email'=>$email,
            'matricule'=>$matricule,
            'statut'=>$row['student_status']
        ];
    }
    return $data;
}
