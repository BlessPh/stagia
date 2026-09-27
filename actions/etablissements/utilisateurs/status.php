<?php
if(session_status()!==PHP_SESSION_ACTIVE){
    session_start();
}

require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/establishment-user-admin.php';

requireEstablishmentAjaxPermission('user.disable');

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

    $targetId=(int)($_POST['id']??0);
    $actorId=(int)($_SESSION['user_id']??0);

    if(!$targetId){
        throw new RuntimeException('Utilisateur invalide.');
    }

    if($targetId===$actorId){
        throw new RuntimeException(
            'Vous ne pouvez pas suspendre votre propre compte.'
        );
    }

    $pdo->beginTransaction();

    $user=establishmentUserBelongs(
        $pdo,
        $targetId,
        $eid,
        true
    );

    if(!$user){
        throw new RuntimeException(
            "Cet utilisateur n'appartient pas à votre établissement."
        );
    }

    if($user['statut_compte']==='A_ACTIVER'){
        throw new RuntimeException(
            "Un compte en attente d'activation doit être traité par l'invitation."
        );
    }

    if($user['statut_compte']==='ACTIF'){

        if(
            isLastActiveEstablishmentAdmin(
                $pdo,
                $targetId,
                $ctx
            )
        ){
            throw new RuntimeException(
                "Impossible de suspendre le dernier administrateur actif de l'établissement."
            );
        }

        $s=$pdo->prepare("
            UPDATE users
            SET
                actif=0,
                statut_compte='SUSPENDU'
            WHERE id=?
        ");
        $s->execute([$targetId]);

        $message='Utilisateur suspendu.';

    }else{
        $s=$pdo->prepare("
            UPDATE users
            SET
                actif=1,
                statut_compte='ACTIF'
            WHERE id=?
        ");
        $s->execute([$targetId]);

        $message='Utilisateur réactivé.';
    }

    $pdo->commit();

    jsonResponse(true,$message);

}catch(Throwable $e){
    if(isset($pdo) && $pdo->inTransaction()){
        $pdo->rollBack();
    }

    jsonResponse(
        false,
        $e->getMessage(),
        [],
        422
    );
}
