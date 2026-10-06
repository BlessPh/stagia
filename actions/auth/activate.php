<?php
if(session_status()!==PHP_SESSION_ACTIVE) session_start();

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/student-role-assignment.php';

function retour(string $token,string $error): never{
    header(
        'Location: '.BASE_URL.
        '/activate.php?token='.urlencode($token).
        '&error='.$error
    );
    exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);
    exit('Méthode non autorisée.');
}

$token=$_POST['token']??'';
$password=$_POST['password']??'';
$confirmation=$_POST['confirmation']??'';

/* TOKEN */
if(
    $token==='' ||
    !ctype_xdigit($token) ||
    strlen($token)!==64
){
    retour($token,'expired');
}

/* CONFIRMATION */
if($password!==$confirmation){
    retour($token,'confirm');
}

/* =========================================================
   MOT DE PASSE
   8 caractères minimum
   1 majuscule
   1 minuscule
   1 chiffre
   1 caractère spécial
========================================================= */

$validPassword=
    strlen($password)>=8 &&
    preg_match('/[A-Z]/',$password) &&
    preg_match('/[a-z]/',$password) &&
    preg_match('/[0-9]/',$password) &&
    preg_match('/[^A-Za-z0-9]/',$password);

if(!$validPassword){
    retour($token,'password');
}

/* =========================================================
   VÉRIFICATION DU LIEN
========================================================= */

$tokenHash=hash('sha256',$token);

$stmt=$pdo->prepare("
    SELECT id
    FROM users
    WHERE activation_token_hash=?
      AND statut_compte='A_ACTIVER'
      AND activation_expire_at>NOW()
    LIMIT 1
");

$stmt->execute([$tokenHash]);
$userId=$stmt->fetchColumn();

if(!$userId){
    retour($token,'expired');
}

/* =========================================================
   ACTIVATION
========================================================= */

try{

    $pdo->beginTransaction();

    $stmt=$pdo->prepare("
        SELECT sp.id student_id,
               (
                   SELECT se.etablissement_id
                   FROM student_enrollments se
                   WHERE se.student_id=sp.id
                   ORDER BY (se.statut='ACTIF') DESC,se.id DESC
                   LIMIT 1
               ) etablissement_id
        FROM student_profiles sp
        WHERE sp.user_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$userId]);
    $student=$stmt->fetch(PDO::FETCH_ASSOC)?:null;

    $stmt=$pdo->prepare("
        UPDATE users
        SET password=?,
            actif=1,
            statut_compte='ACTIF',
            activation_token_hash=NULL,
            activation_expire_at=NULL,
            activated_at=NOW()
        WHERE id=?
          AND statut_compte='A_ACTIVER'
    ");

    $stmt->execute([
        password_hash($password,PASSWORD_DEFAULT),
        $userId
    ]);

    if(!$stmt->rowCount()){
        $pdo->rollBack();
        retour($token,'error');
    }

    if($student){
        ensureStudentRoleAssignment(
            $pdo,
            (int)$userId,
            (int)($student['etablissement_id']??0)?:null,
            null
        );
    }

    $pdo->commit();

    header(
        'Location: '.BASE_URL.
        '/login.php?activated=1'
    );

    exit;

}catch(Throwable $e){

    if($pdo->inTransaction())$pdo->rollBack();

    error_log(
        '[ACTIVATION COMPTE] '.$e->getMessage()
    );

    retour($token,'error');
}
