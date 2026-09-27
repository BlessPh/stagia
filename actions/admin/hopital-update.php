<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);
verifyAjaxCsrf();

try{
    $id=(int)($_POST['id']??0);

    $nom=trim($_POST['nom']??'');
    $numeroAgrement=trim($_POST['numero_agrement']??'');
    $email=trim($_POST['email']??'');
    $telephone=trim($_POST['telephone']??'');
    $adresse=trim($_POST['adresse']??'');
    $province=trim($_POST['province']??'');
    $ville=trim($_POST['ville']??'');

    if(!$id || !$nom)
        jsonResponse(false,'Informations obligatoires manquantes.',[],422);

    if($email && !filter_var($email,FILTER_VALIDATE_EMAIL))
        jsonResponse(false,'Adresse e-mail invalide.',[],422);

    /* Vérifier hôpital */
    $stmt=$pdo->prepare("
        SELECT id
        FROM etablissements
        WHERE id=?
          AND type_etablissement='HOPITAL'
    ");

    $stmt->execute([$id]);

    if(!$stmt->fetch())
        jsonResponse(false,'Hôpital introuvable.',[],404);

    /* Agrément unique */
    if($numeroAgrement!==''){

        $stmt=$pdo->prepare("
            SELECT id
            FROM etablissements
            WHERE type_etablissement='HOPITAL'
              AND numero_agrement=?
              AND id<>?
            LIMIT 1
        ");

        $stmt->execute([
            $numeroAgrement,
            $id
        ]);

        if($stmt->fetch())
            jsonResponse(
                false,
                "Ce numéro d'agrément est déjà utilisé.",
                [],
                409
            );
    }

    $stmt=$pdo->prepare("
        UPDATE etablissements
        SET nom=?,
            email=?,
            telephone=?,
            adresse=?,
            province=?,
            ville=?,
            numero_agrement=?
        WHERE id=?
          AND type_etablissement='HOPITAL'
    ");

    $stmt->execute([
        $nom,
        $email?:null,
        $telephone?:null,
        $adresse?:null,
        $province?:null,
        $ville?:null,
        $numeroAgrement?:null,
        $id
    ]);

    jsonResponse(
        true,
        'Hôpital modifié avec succès.'
    );

}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur serveur : '.$e->getMessage(),
        [],
        500
    );
}