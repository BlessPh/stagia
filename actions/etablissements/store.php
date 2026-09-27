<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../services/MailService.php';
require_once __DIR__.'/../../includes/academic-template-cloner.php';

requireRole(['SUPER_ADMIN']);

function back(array $errors,array $data=[]):never{
    $_SESSION['form_errors']=$errors;
    $_SESSION['old']=$data;
    header('Location: '.BASE_URL.'/views/etablissements/create.php');
    exit;
}

function postValue(string $key):string{
    return trim((string)($_POST[$key]??''));
}

function generateCode(PDO $pdo,string $name):string{
    $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)?:$name;
    $ascii=strtoupper(preg_replace('/[^A-Z0-9 ]/',' ',$ascii));
    $ignore=['DE','DU','DES','LA','LE','LES','D','L','ET','AU','AUX','EN'];
    $words=array_values(array_filter(
        preg_split('/\s+/',trim($ascii)),
        fn($w)=>$w!==''&&!in_array($w,$ignore,true)
    ));

    $base=count($words)<=1
        ?substr($words[0]??'ETB',0,8)
        :substr(implode('',array_map(fn($w)=>$w[0],$words)),0,8);

    if(strlen($base)<2)$base='ETB';

    $code=$base;$n=2;
    $stmt=$pdo->prepare("
        SELECT EXISTS(SELECT 1 FROM etablissements WHERE code=?)
            OR EXISTS(SELECT 1 FROM users WHERE identifiant=?)
    ");

    while(true){
        $stmt->execute([$code,strtolower($code).'.admin']);
        if(!$stmt->fetchColumn())return $code;
        $code=$base.$n++;
    }
}

/* Ne pas appeler cette fonction getType(), PHP possède déjà gettype(). */
function getEstablishmentType(PDO $pdo,string $code):array{
    $stmt=$pdo->prepare("
        SELECT code,libelle,academic_enabled,host_enabled,actif
        FROM establishment_types
        WHERE code=?
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $type=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$type||(int)$type['actif']!==1)
        throw new RuntimeException("Type d'établissement invalide ou inactif.");

    return $type;
}

function resolveAcademicTemplateId(PDO $pdo,array $type):?int{
    if(!(int)($type['academic_enabled']??0))return null;

    $requested=(int)($_POST['academic_template_id']??0);

    if($requested>0){
        $s=$pdo->prepare("
            SELECT id
            FROM academic_structure_templates
            WHERE id=?
              AND type_etablissement=?
              AND actif=1
            LIMIT 1
        ");
        $s->execute([$requested,$type['code']]);

        if(!$s->fetchColumn())
            throw new RuntimeException(
                "Le modèle académique sélectionné est invalide ou incompatible avec ce type d'établissement."
            );

        return $requested;
    }

    /*
     * Compatibilité avec le formulaire actuel :
     * si un seul modèle actif existe, on le choisit automatiquement.
     * Dès qu'il y en a plusieurs, le choix du Super Admin devient obligatoire.
     */
    $s=$pdo->prepare("
        SELECT id
        FROM academic_structure_templates
        WHERE type_etablissement=?
          AND actif=1
        ORDER BY is_default DESC,version_no DESC,id DESC
        LIMIT 2
    ");
    $s->execute([$type['code']]);
    $ids=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));

    if(!$ids)
        throw new RuntimeException(
            "Aucun modèle académique actif n'est configuré pour « {$type['libelle']} »."
        );

    if(count($ids)>1)
        throw new RuntimeException(
            "Plusieurs modèles académiques existent pour « {$type['libelle']} ». ".
            "Choisissez le modèle applicable à cet établissement."
        );

    return $ids[0];
}

function emailConflict(PDO $pdo,string $email):?string{
    if($email==='')return null;

    $stmt=$pdo->prepare("
        SELECT nom
        FROM etablissements
        WHERE LOWER(TRIM(email))=LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$email]);
    if($nom=$stmt->fetchColumn())
        return "L'adresse $email est déjà utilisée par l'établissement « $nom ».";

    $stmt=$pdo->prepare("
        SELECT id
        FROM users
        WHERE LOWER(TRIM(email))=LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$email]);
    if($stmt->fetchColumn())
        return "L'adresse $email est déjà utilisée par un compte STAGIA.";

    $stmt=$pdo->prepare("
        SELECT reference
        FROM demandes_adhesion
        WHERE (
            LOWER(TRIM(email_etablissement))=LOWER(TRIM(?))
            OR LOWER(TRIM(responsable_email))=LOWER(TRIM(?))
        )
        AND statut<>'REJETEE'
        LIMIT 1
    ");
    $stmt->execute([$email,$email]);
    if($ref=$stmt->fetchColumn())
        return "L'adresse $email est déjà utilisée dans la demande d'adhésion $ref.";

    return null;
}

/* =========================================================
   REQUÊTE + CSRF
========================================================= */
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Location: '.BASE_URL.'/views/etablissements/index.php');
    exit;
}

$sessionCsrf=(string)($_SESSION['csrf']??'');
$postCsrf=(string)($_POST['csrf']??'');

if($sessionCsrf===''||$postCsrf===''||!hash_equals($sessionCsrf,$postCsrf)){
    http_response_code(403);
    exit('Requête invalide.');
}

/* =========================================================
   DONNÉES
========================================================= */
$data=[];

foreach([
    'nom_etablissement','type_etablissement','numero_agrement',
    'email_etablissement','telephone_etablissement','province',
    'ville','adresse','responsable_nom','responsable_postnom',
    'responsable_prenom','responsable_fonction',
    'responsable_email','responsable_telephone'
] as $field)$data[$field]=postValue($field);

$data['academic_template_id']=(int)($_POST['academic_template_id']??0);
$data['nom_etablissement']=preg_replace('/\s+/u',' ',$data['nom_etablissement'])??$data['nom_etablissement'];
$data['type_etablissement']=strtoupper($data['type_etablissement']);
$data['email_etablissement']=strtolower($data['email_etablissement']);
$data['responsable_email']=strtolower($data['responsable_email']);

$uploadedFile=null;
$uploadedLogo=null;

/* =========================================================
   VALIDATION
========================================================= */
try{
    $type=getEstablishmentType($pdo,$data['type_etablissement']);
    $academicTemplateId=resolveAcademicTemplateId($pdo,$type);

    $errors=[];

    if($data['nom_etablissement']==='')
        $errors[]="Le nom de l'établissement est obligatoire.";

    if($data['responsable_nom']==='')
        $errors[]='Le nom du responsable est obligatoire.';

    if($data['responsable_telephone']==='')
        $errors[]='Le téléphone du responsable est obligatoire.';

    if(!filter_var($data['responsable_email'],FILTER_VALIDATE_EMAIL))
        $errors[]="L'adresse e-mail du responsable est invalide.";

    if(
        $data['email_etablissement']!==''&&
        !filter_var($data['email_etablissement'],FILTER_VALIDATE_EMAIL)
    )$errors[]="L'adresse e-mail institutionnelle est invalide.";

    if($data['nom_etablissement']!==''){
        $stmt=$pdo->prepare("
            SELECT id
            FROM etablissements
            WHERE LOWER(TRIM(nom))=LOWER(TRIM(?))
            LIMIT 1
        ");
        $stmt->execute([$data['nom_etablissement']]);

        if($stmt->fetchColumn())
            $errors[]='Un établissement portant ce nom existe déjà.';

        $stmt=$pdo->prepare("
            SELECT reference
            FROM demandes_adhesion
            WHERE LOWER(TRIM(nom_etablissement))=LOWER(TRIM(?))
              AND statut<>'REJETEE'
            LIMIT 1
        ");
        $stmt->execute([$data['nom_etablissement']]);

        if($ref=$stmt->fetchColumn())
            $errors[]="Une demande d'adhésion active existe déjà pour cet établissement ($ref). Utilisez le workflow d'adhésion.";
    }

    if($data['numero_agrement']!==''){
        $stmt=$pdo->prepare("
            SELECT id
            FROM etablissements
            WHERE numero_agrement=?
            LIMIT 1
        ");
        $stmt->execute([$data['numero_agrement']]);

        if($stmt->fetchColumn())
            $errors[]="Ce numéro d'agrément est déjà utilisé.";
    }

    foreach(array_unique(array_filter([
        $data['email_etablissement'],
        $data['responsable_email']
    ])) as $email){
        if($error=emailConflict($pdo,$email))$errors[]=$error;
    }

    /* =====================================================
       LOGO ÉTABLISSEMENT — FACULTATIF
    ===================================================== */
    $logoFile=$_FILES['logo']??null;
    $logoAllowed=[
        'image/png'=>'png',
        'image/jpeg'=>'jpg',
        'image/webp'=>'webp'
    ];
    $logoMime=null;

    if($logoFile&&($logoFile['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        if($logoFile['error']!==UPLOAD_ERR_OK){
            $errors[]="Une erreur est survenue lors de l'envoi du logo.";
        }else{
            if(($logoFile['size']??0)<=0)$errors[]='Le logo est vide.';
            if(($logoFile['size']??0)>2*1024*1024)$errors[]='Le logo ne doit pas dépasser 2 Mo.';
            if(!is_uploaded_file($logoFile['tmp_name']))$errors[]='Le logo envoyé est invalide.';

            if(is_uploaded_file($logoFile['tmp_name'])){
                $logoMime=(new finfo(FILEINFO_MIME_TYPE))->file($logoFile['tmp_name']);
                if(!isset($logoAllowed[$logoMime]))
                    $errors[]='Format du logo non autorisé. Utilisez PNG, JPG, JPEG ou WEBP.';
            }
        }
    }

    /* =====================================================
       PIÈCE JUSTIFICATIVE
    ===================================================== */
    $file=$_FILES['piece_justificative']??null;
    $allowed=[
        'application/pdf'=>'pdf',
        'image/jpeg'=>'jpg',
        'image/png'=>'png'
    ];
    $mime=null;

    if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){
        $errors[]="La pièce justificative est obligatoire.";
    }elseif($file['error']!==UPLOAD_ERR_OK){
        $errors[]="Une erreur est survenue lors de l'envoi de la pièce justificative.";
    }else{
        if(($file['size']??0)<=0)$errors[]='La pièce justificative est vide.';
        if(($file['size']??0)>5*1024*1024)$errors[]='La pièce justificative ne doit pas dépasser 5 Mo.';
        if(!is_uploaded_file($file['tmp_name']))$errors[]='Le fichier envoyé est invalide.';

        if(is_uploaded_file($file['tmp_name'])){
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if(!isset($allowed[$mime]))
                $errors[]='Format non autorisé. Utilisez PDF, JPG, JPEG ou PNG.';
        }
    }

    if($errors)back(array_values(array_unique($errors)),$data);

    /* =====================================================
       FICHIER
    ===================================================== */
    $directory=__DIR__.'/../../uploads/etablissements';

    if(!is_dir($directory)&&!mkdir($directory,0775,true))
        throw new RuntimeException('Impossible de créer le dossier des pièces justificatives.');

    $filename=bin2hex(random_bytes(20)).'.'.$allowed[$mime];
    $fullPath=$directory.'/'.$filename;

    if(!move_uploaded_file($file['tmp_name'],$fullPath))
        throw new RuntimeException('Impossible d’enregistrer la pièce justificative.');

    $uploadedFile=$fullPath;
    $piecePath='uploads/etablissements/'.$filename;

    $logoPath=null;
    if($logoFile&&($logoFile['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
        $logoDirectory=__DIR__.'/../../uploads/etablissements/logos';

        if(!is_dir($logoDirectory)&&!mkdir($logoDirectory,0775,true))
            throw new RuntimeException("Impossible de créer le dossier des logos.");

        $logoFilename=bin2hex(random_bytes(20)).'.'.$logoAllowed[$logoMime];
        $logoFullPath=$logoDirectory.'/'.$logoFilename;

        if(!move_uploaded_file($logoFile['tmp_name'],$logoFullPath))
            throw new RuntimeException("Impossible d'enregistrer le logo de l'établissement.");

        $uploadedLogo=$logoFullPath;
        $logoPath='uploads/etablissements/logos/'.$logoFilename;
    }

    /* =====================================================
       PRÉPARATION COMPTE
    ===================================================== */
    $code=generateCode($pdo,$data['nom_etablissement']);
    $identifiant=strtolower($code).'.admin';

    $stmt=$pdo->query("
        SELECT id
        FROM roles
        WHERE code='ADMIN_ETABLISSEMENT'
        LIMIT 1
    ");
    $roleId=(int)$stmt->fetchColumn();

    if(!$roleId)
        throw new RuntimeException('Le rôle ADMIN_ETABLISSEMENT est introuvable.');

    $token=bin2hex(random_bytes(32));
    $tokenHash=hash('sha256',$token);
    $expiration=date('Y-m-d H:i:s',time()+48*3600);
    $passwordHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);

    /* =====================================================
       TRANSACTION
    ===================================================== */
    $pdo->beginTransaction();

    $stmt=$pdo->prepare("
        SELECT id
        FROM etablissements
        WHERE LOWER(TRIM(nom))=LOWER(TRIM(?))
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$data['nom_etablissement']]);

    if($stmt->fetchColumn())
        throw new RuntimeException('Un établissement portant ce nom existe déjà.');

    foreach(array_unique(array_filter([
        $data['email_etablissement'],
        $data['responsable_email']
    ])) as $email){
        if($error=emailConflict($pdo,$email))
            throw new RuntimeException($error);
    }

    /* =====================================================
       1. ÉTABLISSEMENT
    ===================================================== */
    $pdo->prepare("
        INSERT INTO etablissements(
            code,nom,logo,type_etablissement,email,telephone,
            adresse,piece_justificative,province,ville,
            numero_agrement,statut
        )
        VALUES(?,?,?,?,?,?,?,?,?,?,?,'VALIDE')
    ")->execute([
        $code,
        $data['nom_etablissement'],
        $logoPath,
        $type['code'],
        $data['email_etablissement']?:null,
        $data['telephone_etablissement']?:null,
        $data['adresse']?:null,
        $piecePath,
        $data['province']?:null,
        $data['ville']?:null,
        $data['numero_agrement']?:null
    ]);

    $etablissementId=(int)$pdo->lastInsertId();

    /* =====================================================
       2. ADMINISTRATEUR PRINCIPAL
    ===================================================== */
    $pdo->prepare("
        INSERT INTO users(
            role_id,nom,postnom,prenom,email,
            identifiant,password,telephone,
            actif,statut_compte,
            activation_token_hash,activation_expire_at
        )
        VALUES(?,?,?,?,?,?,?,?,0,'A_ACTIVER',?,?)
    ")->execute([
        $roleId,
        $data['responsable_nom'],
        $data['responsable_postnom']?:null,
        $data['responsable_prenom']?:null,
        $data['responsable_email'],
        $identifiant,
        $passwordHash,
        $data['responsable_telephone'],
        $tokenHash,
        $expiration
    ]);

    $userId=(int)$pdo->lastInsertId();

    /* =====================================================
       3. LIAISON
    ===================================================== */
    $pdo->prepare("
        INSERT INTO etablissement_users(
            etablissement_id,user_id,fonction,principal
        )
        VALUES(?,?,?,1)
    ")->execute([
        $etablissementId,
        $userId,
        $data['responsable_fonction']?:null
    ]);

    /* =====================================================
       4. CONFIGURATION ACADÉMIQUE
    ===================================================== */
    if($academicTemplateId!==null)
        applyAcademicTemplate($pdo,$etablissementId,$type,$academicTemplateId);

    $pdo->commit();

    /* =====================================================
       E-MAIL D'ACTIVATION
    ===================================================== */
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';

    $activationUrl=
        $scheme.'://'.
        $_SERVER['HTTP_HOST'].
        BASE_URL.
        '/activate.php?token='.
        urlencode($token);

    $nomResponsable=trim(
        $data['responsable_prenom'].' '.
        $data['responsable_nom'].' '.
        $data['responsable_postnom']
    );

    $mailEnvoye=MailService::envoyerActivation(
        $data['responsable_email'],
        $nomResponsable,
        $activationUrl
    );

    if(!$mailEnvoye)
        error_log('[ETABLISSEMENT SMTP] '.MailService::getLastError());

    unset($_SESSION['old'],$_SESSION['form_errors']);

    header(
        'Location: '.
        BASE_URL.
        '/views/etablissements/show.php?id='.
        $etablissementId.
        '&created=1&mail='.
        ($mailEnvoye?'1':'0')
    );
    exit;

}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();

    if($uploadedFile&&is_file($uploadedFile))
        @unlink($uploadedFile);

    if($uploadedLogo&&is_file($uploadedLogo))
        @unlink($uploadedLogo);

    error_log(
        '[CREATE ETABLISSEMENT] '.
        $e->getMessage().
        ' | '.$e->getFile().
        ':'.$e->getLine()
    );

    back([$e->getMessage()],$data);
}
