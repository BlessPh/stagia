<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE'
]);

verifyAjaxCsrf();

try{

    $etablissementId=currentEtablissementId($pdo);

    $studentId=(int)($_POST['student_id']??0);
    $email=trim($_POST['email']??'');
    $telephone=trim($_POST['telephone']??'');

    if(!$etablissementId || !$studentId || !$email)
        jsonResponse(
            false,
            'Étudiant et e-mail obligatoires.',
            [],
            422
        );

    if(!filter_var($email,FILTER_VALIDATE_EMAIL))
        jsonResponse(
            false,
            'Adresse e-mail invalide.',
            [],
            422
        );

    /*
     * Vérifier que l'étudiant appartient
     * bien à l'établissement connecté.
     */
    $stmt=$pdo->prepare("
        SELECT
            sp.id,
            sp.stagia_code,
            sp.nom,
            sp.postnom,
            sp.prenom,
            sp.user_id

        FROM student_profiles sp

        JOIN student_enrollments se
          ON se.student_id=sp.id
         AND se.etablissement_id=?

        WHERE sp.id=?
        LIMIT 1
    ");

    $stmt->execute([
        $etablissementId,
        $studentId
    ]);

    $student=$stmt->fetch();

    if(!$student)
        jsonResponse(
            false,
            'Étudiant introuvable dans votre établissement.',
            [],
            404
        );

    /*
     * Ne jamais créer deux comptes
     * pour le même profil étudiant.
     */
    if($student['user_id'])
        jsonResponse(
            false,
            'Cet étudiant possède déjà un compte STAGIA.',
            [],
            409
        );

    /* Rôle STAGIAIRE */
    $stmt=$pdo->prepare("
        SELECT id
        FROM roles
        WHERE code='STAGIAIRE'
        LIMIT 1
    ");

    $stmt->execute();

    $roleId=(int)$stmt->fetchColumn();

    if(!$roleId)
        jsonResponse(
            false,
            'Le rôle STAGIAIRE est introuvable.',
            [],
            500
        );

    /* E-mail unique */
    $stmt=$pdo->prepare("
        SELECT id
        FROM users
        WHERE email=?
        LIMIT 1
    ");

    $stmt->execute([$email]);

    if($stmt->fetch())
        jsonResponse(
            false,
            'Cette adresse e-mail est déjà utilisée.',
            [],
            409
        );

    /* Identifiant permanent STAGIA */
    $identifiant=$student['stagia_code'];

    $stmt=$pdo->prepare("
        SELECT id
        FROM users
        WHERE identifiant=?
        LIMIT 1
    ");

    $stmt->execute([$identifiant]);

    if($stmt->fetch())
        jsonResponse(
            false,
            'Cet identifiant STAGIA possède déjà un compte utilisateur.',
            [],
            409
        );

    /* Token d'activation */
    $token=bin2hex(random_bytes(32));
    $tokenHash=hash('sha256',$token);

    $pdo->beginTransaction();

    /* Créer users */
    $stmt=$pdo->prepare("
        INSERT INTO users(
            role_id,
            nom,
            postnom,
            prenom,
            email,
            identifiant,
            password,
            telephone,
            actif,
            statut_compte,
            activation_token_hash
        )
        VALUES(
            ?,?,?,?,?,?,
            NULL,?,
            1,
            'A_ACTIVER',
            ?
        )
    ");

    $stmt->execute([
        $roleId,
        $student['nom'],
        $student['postnom']?:null,
        $student['prenom']?:null,
        $email,
        $identifiant,
        $telephone?:null,
        $tokenHash
    ]);

    $userId=(int)$pdo->lastInsertId();

    /*
     * Relier le compte au profil global.
     * Le profil étudiant n'est PAS recréé.
     */
    $stmt=$pdo->prepare("
        UPDATE student_profiles
        SET user_id=?
        WHERE id=?
          AND user_id IS NULL
    ");

    $stmt->execute([
        $userId,
        $studentId
    ]);

    if($stmt->rowCount()!==1)
        throw new RuntimeException(
            'Impossible de relier le compte au profil étudiant.'
        );

    $pdo->commit();

    $activationUrl=
        BASE_URL.
        '/activation-etudiant.php?token='.
        urlencode($token);

    jsonResponse(
        true,
        'Compte étudiant créé. Activation requise.',
        [
            'user_id'=>$userId,
            'identifiant'=>$identifiant,
            'activation_url'=>$activationUrl
        ]
    );

}catch(Throwable $e){

    if($pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(
        false,
        'Erreur serveur : '.$e->getMessage(),
        [],
        500
    );
}