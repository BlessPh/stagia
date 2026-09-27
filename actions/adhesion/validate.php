<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../services/MailService.php';
require_once __DIR__.'/../../includes/academic-template-cloner.php';

class AdhesionException extends RuntimeException{}

function fail(string $message,int $code=400):never{
    http_response_code($code);
    exit($message);
}

function generateCode(PDO $pdo,string $nom):string{
    $clean=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nom)?:$nom;
    $clean=strtoupper(preg_replace('/[^A-Za-z0-9 ]/',' ',$clean));
    $ignore=['DE','DU','DES','LA','LE','LES','D','L','ET','AU','AUX','EN'];
    $mots=array_values(array_filter(preg_split('/\s+/',trim($clean)),fn($m)=>$m!==''&&!in_array($m,$ignore,true)));
    $base=count($mots)===1?substr($mots[0],0,10):substr(implode('',array_map(fn($m)=>$m[0],$mots)),0,8);
    if(strlen($base)<2)$base='ETAB';

    $code=$base;$n=2;
    $check=$pdo->prepare("
        SELECT EXISTS(SELECT 1 FROM etablissements WHERE code=?)
            OR EXISTS(SELECT 1 FROM users WHERE identifiant=?)
    ");

    while(true){
        $check->execute([$code,strtolower($code).'.admin']);
        if(!$check->fetchColumn())return $code;
        $code=$base.$n++;
    }
}

function getEstablishmentType(PDO $pdo,string $code):array{
    $s=$pdo->prepare("
        SELECT code,libelle,categorie,academic_enabled,host_enabled,
               adhesion_enabled,allow_parent,actif
        FROM establishment_types
        WHERE code=?
        LIMIT 1
    ");
    $s->execute([$code]);
    $type=$s->fetch(PDO::FETCH_ASSOC);

    if(!$type)throw new AdhesionException("Type d'établissement introuvable.");
    if(!(int)$type['actif'])throw new AdhesionException("Ce type d'établissement est désactivé.");

    return $type;
}

/*
 * Compatibilité :
 * - établissement non académique -> aucun modèle.
 * - academic_template_id fourni -> STAGIA applique exactement ce modèle.
 * - aucun id fourni et un seul modèle actif existe -> il est choisi automatiquement.
 * - plusieurs modèles actifs -> le Super Admin doit choisir explicitement.
 */
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
            throw new AdhesionException(
                "Le modèle académique sélectionné est invalide ou incompatible avec ce type d'établissement."
            );

        return $requested;
    }

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
        throw new AdhesionException(
            "Aucun modèle académique actif n'est configuré pour « {$type['libelle']} »."
        );

    if(count($ids)>1)
        throw new AdhesionException(
            "Plusieurs modèles académiques sont disponibles pour « {$type['libelle']} ». ".
            "Le Super Admin doit choisir le modèle applicable à cet établissement."
        );

    return $ids[0];
}

if($_SERVER['REQUEST_METHOD']!=='POST'||($_SESSION['role_code']??'')!=='SUPER_ADMIN')
    fail('Accès refusé.',403);

if(
    empty($_SESSION['csrf'])||
    empty($_POST['csrf'])||
    !hash_equals((string)$_SESSION['csrf'],(string)$_POST['csrf'])
)fail('Requête invalide.',403);

$id=(int)($_POST['id']??0);
if(!$id)fail('Données invalides.');

$d=null;$type=null;$token='';$expiration='';$identifiant='';
$etablissementId=0;$userId=0;$academicTemplateId=null;

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT * FROM demandes_adhesion WHERE id=? LIMIT 1 FOR UPDATE");
    $s->execute([$id]);
    $d=$s->fetch(PDO::FETCH_ASSOC);

    if(!$d||!in_array($d['statut'],['SOUMISE','EN_EXAMEN','A_COMPLETER'],true))
        throw new AdhesionException('Cette demande ne peut plus être validée.');

    $type=getEstablishmentType($pdo,$d['type_etablissement']);
    $academicTemplateId=resolveAcademicTemplateId($pdo,$type);

    $s=$pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) LIMIT 1");
    $s->execute([$d['responsable_email']]);
    if($s->fetchColumn())
        throw new AdhesionException("Un utilisateur utilise déjà l'e-mail du responsable.");

    $s=$pdo->query("SELECT id FROM roles WHERE code='ADMIN_ETABLISSEMENT' LIMIT 1");
    $roleId=(int)$s->fetchColumn();
    if(!$roleId)throw new AdhesionException('Rôle ADMIN_ETABLISSEMENT introuvable.');

    $code=generateCode($pdo,$d['nom_etablissement']);
    $identifiant=strtolower($code).'.admin';
    $token=bin2hex(random_bytes(32));
    $tokenHash=hash('sha256',$token);
    $expiration=date('Y-m-d H:i:s',time()+48*3600);
    $passwordHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);

    $pdo->prepare("
        INSERT INTO etablissements(
            code,nom,type_etablissement,email,telephone,adresse,
            piece_justificative,province,ville,numero_agrement,statut
        ) VALUES(?,?,?,?,?,?,?,?,?,?,'VALIDE')
    ")->execute([
        $code,$d['nom_etablissement'],$type['code'],$d['email_etablissement'],
        $d['telephone_etablissement'],$d['adresse'],$d['piece_justificative'],
        $d['province'],$d['ville'],$d['numero_agrement']
    ]);

    $etablissementId=(int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO users(
            role_id,nom,postnom,prenom,email,identifiant,password,telephone,
            actif,statut_compte,activation_token_hash,activation_expire_at
        ) VALUES(?,?,?,?,?,?,?,?,0,'A_ACTIVER',?,?)
    ")->execute([
        $roleId,$d['responsable_nom'],$d['responsable_postnom'],$d['responsable_prenom'],
        $d['responsable_email'],$identifiant,$passwordHash,$d['responsable_telephone'],
        $tokenHash,$expiration
    ]);

    $userId=(int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO etablissement_users(etablissement_id,user_id,fonction,principal)
        VALUES(?,?,?,1)
    ")->execute([$etablissementId,$userId,$d['responsable_fonction']]);

    if($academicTemplateId!==null)
        applyAcademicTemplate($pdo,$etablissementId,$type,$academicTemplateId);

    applyHostUnitTemplate($pdo,$etablissementId,$type);

    $pdo->prepare("
        UPDATE demandes_adhesion
        SET statut='VALIDEE',traite_par=?,traite_le=NOW(),
            etablissement_id=?,user_id=?
        WHERE id=?
    ")->execute([$_SESSION['user_id'],$etablissementId,$userId,$id]);

    $pdo->commit();

}catch(AdhesionException $e){
    if($pdo->inTransaction())$pdo->rollBack();
    fail($e->getMessage());

}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[ADHESION VALIDATION] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    fail('Impossible de valider la demande.');
}

$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
$lien=$scheme.'://'.$_SERVER['HTTP_HOST'].BASE_URL.'/activate.php?token='.urlencode($token);
$nomComplet=trim(($d['responsable_prenom']??'').' '.($d['responsable_nom']??''));

$mailEnvoye=MailService::envoyerActivation(
    $d['responsable_email'],
    $nomComplet,
    $lien
);

if(!$mailEnvoye)
    error_log('[SMTP ACTIVATION] '.MailService::getLastError());

$_SESSION['activation_resend']=[
    'demande_id'=>$id,
    'etablissement'=>$d['nom_etablissement'],
    'email'=>$d['responsable_email'],
    'identifiant'=>$identifiant,
    'expire'=>date('d/m/Y à H:i',strtotime($expiration)),
    'mail_sent'=>$mailEnvoye,
    'configuration_academique'=>(bool)$type['academic_enabled'],
    'academic_template_id'=>$academicTemplateId
];

header('Location: '.BASE_URL.'/views/adhesions/activation-link.php');
exit;
