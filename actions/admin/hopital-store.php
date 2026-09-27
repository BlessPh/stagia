<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);
verifyAjaxCsrf();

try{
    $nom=trim($_POST['nom']??'');
    $numeroAgrement=trim($_POST['numero_agrement']??'');
    $email=trim($_POST['email']??'');
    $telephone=trim($_POST['telephone']??'');
    $adresse=trim($_POST['adresse']??'');
    $province=trim($_POST['province']??'');
    $ville=trim($_POST['ville']??'');
    $statut=$_POST['statut']??'EXTERNE';

    if(!$nom)
        jsonResponse(false,"Le nom de l'hôpital est obligatoire.",[],422);

    if(!in_array($statut,['EXTERNE','EN_ATTENTE'],true))
        jsonResponse(false,'Statut initial invalide.',[],422);

    if($email && !filter_var($email,FILTER_VALIDATE_EMAIL))
        jsonResponse(false,'Adresse e-mail invalide.',[],422);

    /* Éviter les doublons */
    if($numeroAgrement!==''){
        $stmt=$pdo->prepare("
            SELECT id,nom
            FROM etablissements
            WHERE type_etablissement='HOPITAL'
              AND numero_agrement=?
            LIMIT 1
        ");

        $stmt->execute([$numeroAgrement]);

        if($stmt->fetch())
            jsonResponse(
                false,
                "Un hôpital utilisant ce numéro d'agrément existe déjà.",
                [],
                409
            );
    }

    $stmt=$pdo->prepare("
        SELECT id
        FROM etablissements
        WHERE type_etablissement='HOPITAL'
          AND nom=?
          AND COALESCE(ville,'')=?
        LIMIT 1
    ");

    $stmt->execute([$nom,$ville]);

    if($stmt->fetch())
        jsonResponse(
            false,
            'Un hôpital portant ce nom existe déjà dans cette ville.',
            [],
            409
        );

    $pdo->beginTransaction();

    /*
     * Code temporaire pour fonctionner même si
     * la colonne code est NOT NULL.
     */
    $temporaryCode=
        'TMP-HOP-'.bin2hex(random_bytes(6));

    $stmt=$pdo->prepare("
        INSERT INTO etablissements(
            code,nom,type_etablissement,
            email,telephone,adresse,
            province,ville,numero_agrement,
            statut
        )
        VALUES(
            ?,?,'HOPITAL',
            ?,?,?,?,
            ?,?,?
        )
    ");

    $stmt->execute([
        $temporaryCode,
        $nom,
        $email?:null,
        $telephone?:null,
        $adresse?:null,
        $province?:null,
        $ville?:null,
        $numeroAgrement?:null,
        $statut
    ]);

    $id=(int)$pdo->lastInsertId();

    $code='HOP-'.str_pad(
        $id,
        6,
        '0',
        STR_PAD_LEFT
    );

    $pdo->prepare("
        UPDATE etablissements
        SET code=?
        WHERE id=?
    ")->execute([$code,$id]);

    $pdo->commit();

    jsonResponse(
        true,
        'Hôpital enregistré avec succès.',
        [
            'id'=>$id,
            'code'=>$code
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