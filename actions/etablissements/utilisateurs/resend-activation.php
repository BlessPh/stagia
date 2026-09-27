<?php
if(session_status()!==PHP_SESSION_ACTIVE){
    session_start();
}

require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/establishment-user-admin.php';
require_once __DIR__.'/../../../services/MailService.php';

requireEstablishmentAjaxPermission('user.create');

$csrf=(string)($_POST['csrf']??'');

if(
    empty($_SESSION['csrf']) ||
    !$csrf ||
    !hash_equals((string)$_SESSION['csrf'],$csrf)
){
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);
}

try{
    $ctx=establishmentUserAdminContext($pdo);
    $eid=(int)$ctx['id'];
    $id=(int)($_POST['id']??0);

    if(!$id){
        throw new RuntimeException('Utilisateur invalide.');
    }

    $user=establishmentUserBelongs(
        $pdo,
        $id,
        $eid
    );

    if(!$user){
        throw new RuntimeException(
            "Cet utilisateur n'appartient pas à votre établissement."
        );
    }

    if($user['statut_compte']!=='A_ACTIVER'){
        throw new RuntimeException(
            "Ce compte n'est plus en attente d'activation."
        );
    }

    if(!$user['email']){
        throw new RuntimeException(
            "Ce compte ne possède aucune adresse e-mail."
        );
    }

    $token=bin2hex(random_bytes(32));
    $tokenHash=hash('sha256',$token);
    $hours=activationExpiryHours($pdo);

    $expiry=date(
        'Y-m-d H:i:s',
        time()+($hours*3600)
    );

    $s=$pdo->prepare("
        UPDATE users
        SET
            activation_token_hash=?,
            activation_expire_at=?,
            actif=0,
            statut_compte='A_ACTIVER'
        WHERE id=?
    ");
    $s->execute([
        $tokenHash,
        $expiry,
        $id
    ]);

    $scheme=(
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS']!=='off'
    )?'https':'http';

    $activationUrl=
        $scheme.'://'.
        ($_SERVER['HTTP_HOST']??'localhost').
        BASE_URL.
        '/activate.php?token='.
        urlencode($token);

    $name=trim(
        implode(
            ' ',
            array_filter([
                $user['prenom'],
                $user['nom'],
                $user['postnom']
            ])
        )
    );

    $sent=MailService::envoyerActivation(
        $user['email'],
        $name?:$user['nom'],
        $activationUrl
    );

    if(!$sent){
        throw new RuntimeException(
            "Le lien a été régénéré, mais l'e-mail n'a pas pu être envoyé."
        );
    }

    jsonResponse(
        true,
        "Nouvelle invitation d'activation envoyée."
    );

}catch(Throwable $e){
    jsonResponse(
        false,
        $e->getMessage(),
        [],
        422
    );
}
