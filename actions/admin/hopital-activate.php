<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);
verifyAjaxCsrf();

try{
    $hopitalId=(int)($_POST['hopital_id']??0);

    $nom=trim($_POST['nom']??'');
    $postnom=trim($_POST['postnom']??'');
    $prenom=trim($_POST['prenom']??'');
    $email=trim($_POST['email']??'');
    $telephone=trim($_POST['telephone']??'');

    if(!$hopitalId || !$nom || !$email)
        jsonResponse(false,'Nom et e-mail de l’administrateur obligatoires.',[],422);

    if(!filter_var($email,FILTER_VALIDATE_EMAIL))
        jsonResponse(false,'Adresse e-mail invalide.',[],422);

    /* Hôpital */
    $stmt=$pdo->prepare("
        SELECT id,code,nom,statut
        FROM etablissements
        WHERE id=?
          AND type_etablissement='HOPITAL'
        LIMIT 1
    ");
    $stmt->execute([$hopitalId]);
    $hopital=$stmt->fetch();

    if(!$hopital)
        jsonResponse(false,'Hôpital introuvable.',[],404);

    if($hopital['statut']==='SUSPENDU')
        jsonResponse(false,'Cet hôpital est suspendu.',[],409);

    /* Rôle */
    $stmt=$pdo->prepare("
        SELECT id
        FROM roles
        WHERE code='ADMIN_ACCUEIL'
        LIMIT 1
    ");
    $stmt->execute();

    $roleId=(int)$stmt->fetchColumn();

    if(!$roleId)
        jsonResponse(false,'Le rôle ADMIN_ACCUEIL n’existe pas.',[],500);

    /* Éviter plusieurs admins principaux */
    $stmt=$pdo->prepare("
        SELECT u.id,u.email
        FROM etablissement_users eu
        JOIN users u ON u.id=eu.user_id
        WHERE eu.etablissement_id=?
          AND eu.principal=1
        LIMIT 1
    ");
    $stmt->execute([$hopitalId]);

    if($stmt->fetch())
        jsonResponse(
            false,
            'Cet hôpital possède déjà un administrateur principal.',
            [],
            409
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
            'Cette adresse e-mail est déjà utilisée par un compte STAGIA.',
            [],
            409
        );

    /*
     * Identifiant automatique :
     * HOP-000001-ADMIN
     */
    $base=$hopital['code'].'-ADMIN';
    $identifiant=$base;
    $i=1;

    while(true){

        $stmt=$pdo->prepare("
            SELECT id
            FROM users
            WHERE identifiant=?
            LIMIT 1
        ");
        $stmt->execute([$identifiant]);

        if(!$stmt->fetch()) break;

        $identifiant=$base.'-'.$i++;
    }

    /* Token d'activation */
    $token=bin2hex(random_bytes(32));
    $tokenHash=hash('sha256',$token);

    $pdo->beginTransaction();

    /* Utilisateur non encore activé */
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
        $nom,
        $postnom?:null,
        $prenom?:null,
        $email,
        $identifiant,
        $telephone?:null,
        $tokenHash
    ]);

    $userId=(int)$pdo->lastInsertId();

    /* Association à l'hôpital */
    $stmt=$pdo->prepare("
        INSERT INTO etablissement_users(
            etablissement_id,
            user_id,
            fonction,
            principal
        )
        VALUES(?,?,?,1)
    ");

    $stmt->execute([
        $hopitalId,
        $userId,
        'Administrateur établissement d’accueil'
    ]);

    /*
     * L'établissement est validé,
     * mais l'espace n'est pas encore activé.
     */
    $pdo->prepare("
        UPDATE etablissements
        SET statut='VALIDE'
        WHERE id=?
    ")->execute([$hopitalId]);

    $pdo->commit();

    $activationUrl=
        BASE_URL.
        '/activation-hopital.php?token='.
        urlencode($token);

    jsonResponse(true,'Administrateur créé. Activation requise.',[
        'user_id'=>$userId,
        'identifiant'=>$identifiant,
        'activation_url'=>$activationUrl
    ]);

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