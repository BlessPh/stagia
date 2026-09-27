<?php
if(session_status()!==PHP_SESSION_ACTIVE){
    session_start();
}

require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/establishment-user-admin.php';

requireEstablishmentAjaxPermission('user.update');

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
    $nom=trim((string)($_POST['nom']??''));
    $postnom=trim((string)($_POST['postnom']??''));
    $prenom=trim((string)($_POST['prenom']??''));
    $email=strtolower(trim((string)($_POST['email']??'')));
    $telephone=trim((string)($_POST['telephone']??''));
    $fonction=trim((string)($_POST['fonction']??''));

    if(!$id){
        throw new RuntimeException('Utilisateur invalide.');
    }

    if($nom===''){
        throw new RuntimeException('Le nom est obligatoire.');
    }

    if(
        $email==='' ||
        !filter_var($email,FILTER_VALIDATE_EMAIL)
    ){
        throw new RuntimeException(
            'Une adresse e-mail valide est obligatoire.'
        );
    }

    $pdo->beginTransaction();

    $user=establishmentUserBelongs(
        $pdo,
        $id,
        $eid,
        true
    );

    if(!$user){
        throw new RuntimeException(
            "Cet utilisateur n'appartient pas à votre établissement."
        );
    }

    if((int)$user['establishment_count']>1){
        /*
         * Un compte partagé peut être rattaché à plusieurs organisations.
         * L'établissement courant ne modifie alors que sa fonction locale.
         */
        $s=$pdo->prepare("
            UPDATE etablissement_users
            SET fonction=?
            WHERE etablissement_id=?
              AND user_id=?
        ");
        $s->execute([
            $fonction?:null,
            $eid,
            $id
        ]);

        $pdo->commit();

        jsonResponse(
            true,
            'Fonction locale mise à jour. L’identité globale de ce compte partagé reste protégée.'
        );
    }

    $s=$pdo->prepare("
        SELECT id
        FROM users
        WHERE LOWER(TRIM(email))=LOWER(TRIM(?))
          AND id<>?
        LIMIT 1
    ");
    $s->execute([$email,$id]);

    if($s->fetchColumn()){
        throw new RuntimeException(
            'Cette adresse e-mail est déjà utilisée.'
        );
    }

    $s=$pdo->prepare("
        UPDATE users
        SET
            nom=?,
            postnom=?,
            prenom=?,
            email=?,
            telephone=?
        WHERE id=?
    ");
    $s->execute([
        $nom,
        $postnom?:null,
        $prenom?:null,
        $email,
        $telephone?:null,
        $id
    ]);

    $s=$pdo->prepare("
        UPDATE etablissement_users
        SET fonction=?
        WHERE etablissement_id=?
          AND user_id=?
    ");
    $s->execute([
        $fonction?:null,
        $eid,
        $id
    ]);

    $pdo->commit();

    jsonResponse(
        true,
        'Utilisateur mis à jour.'
    );

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
