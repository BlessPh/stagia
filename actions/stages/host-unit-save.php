<?php
/**
 * Endpoint AJAX de création ou modification d'un service ou d'une unité d'accueil.
 * Il maintient la hiérarchie, la capacité et le code lisible associé à l'identifiant de la ligne.
 */
if(session_status()!==PHP_SESSION_ACTIVE)
    session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* La structure organisationnelle est administrée par l'accueil habilité. */
requireAjaxRole(['ADMIN_ACCUEIL']);


/* =========================================================
   CSRF
========================================================= */
$csrf=$_POST['csrf']??'';

if(
    empty($_SESSION['csrf']) ||
    !$csrf ||
    !hash_equals($_SESSION['csrf'],$csrf)
){
    jsonResponse(
        false,
        'Jeton de sécurité invalide.',
        [],
        419
    );
}


try{
    /* Normalisation des données de structure reçues depuis le formulaire. */

    $hopitalId=currentEtablissementId($pdo);

    if(!$hopitalId)
        jsonResponse(
            false,
            'Aucun établissement associé.',
            [],
            403
        );


    $id=(int)($_POST['id']??0);

    $nom=trim(
        $_POST['nom']??''
    );

    $type=strtoupper(
        trim($_POST['type']??'SERVICE')
    );

    $description=trim(
        $_POST['description']??''
    );

    $capacite=
        ($_POST['capacite']??'')!==''
            ?(int)$_POST['capacite']
            :null;

    $parentId=
        !empty($_POST['parent_id'])
            ?(int)$_POST['parent_id']
            :null;


    /* =====================================================
       VALIDATIONS
    ====================================================== */
    if($nom==='')
        jsonResponse(
            false,
            'Le nom est obligatoire.',
            [],
            422
        );


    if(!in_array(
        $type,
        ['SERVICE','UNITE'],
        true
    )){
        jsonResponse(
            false,
            'Type invalide.',
            [],
            422
        );
    }


    if($capacite!==null && $capacite<0)
        jsonResponse(
            false,
            'Capacité invalide.',
            [],
            422
        );


    /* Un service est une racine de hiérarchie et ne possède jamais de parent. */
    if($type==='SERVICE')
        $parentId=null;


    /* =====================================================
       UNITÉ : SERVICE PARENT OBLIGATOIRE
    ====================================================== */
    if($type==='UNITE'){

        if(!$parentId)
            jsonResponse(
                false,
                'Sélectionnez le service parent.',
                [],
                422
            );


        $stmt=$pdo->prepare("
            SELECT id

            FROM host_units

            WHERE id=?
              AND host_etablissement_id=?
              AND type='SERVICE'
              AND actif=1

            LIMIT 1
        ");

        $stmt->execute([
            $parentId,
            $hopitalId
        ]);


        if(!$stmt->fetchColumn())
            jsonResponse(
                false,
                'Service parent invalide.',
                [],
                422
            );
    }


    /* =====================================================
       MODIFICATION
    ====================================================== */
    /* Une modification conserve l'identifiant et recalcule son code selon le type courant. */
    if($id){

        /* Vérifier l'existence */
        $stmt=$pdo->prepare("
            SELECT id,type

            FROM host_units

            WHERE id=?
              AND host_etablissement_id=?

            LIMIT 1
        ");

        $stmt->execute([
            $id,
            $hopitalId
        ]);

        $existing=$stmt->fetch(
            PDO::FETCH_ASSOC
        );


        if(!$existing)
            jsonResponse(
                false,
                'Service / unité introuvable.',
                [],
                404
            );


        /*
         * Le code reste basé sur l'ID.
         * Si le type change, le préfixe change aussi.
         */
        $prefix=
            $type==='SERVICE'
                ?'SRV'
                :'UNT';

        $code=
            $prefix.'-'.
            str_pad(
                (string)$id,
                6,
                '0',
                STR_PAD_LEFT
            );


        $stmt=$pdo->prepare("
            UPDATE host_units

            SET code=?,
                nom=?,
                type=?,
                parent_id=?,
                description=?,
                capacite=?

            WHERE id=?
              AND host_etablissement_id=?
        ");

        $stmt->execute([
            $code,
            $nom,
            $type,
            $parentId,
            $description?:null,
            $capacite,
            $id,
            $hopitalId
        ]);


        jsonResponse(
            true,
            'Service / unité modifié avec succès.',
            [
                'id'=>$id,
                'code'=>$code
            ]
        );
    }


    /* =====================================================
       CRÉATION
    ====================================================== */
    /* La création temporaire puis la génération du code final sont regroupées dans une transaction. */
    $pdo->beginTransaction();


    /*
     * Code temporaire obligatoire car la colonne code
     * est NOT NULL.
     */
    /* La colonne code étant obligatoire, une valeur temporaire est utilisée avant l'identifiant final. */
    $temporaryCode=
        'TMP-'.
        bin2hex(
            random_bytes(8)
        );


    $stmt=$pdo->prepare("
        INSERT INTO host_units(
            host_etablissement_id,
            parent_id,
            code,
            nom,
            type,
            description,
            capacite,
            actif
        )

        VALUES(
            ?,?,?,?,?,?,?,1
        )
    ");

    $stmt->execute([
        $hopitalId,
        $parentId,
        $temporaryCode,
        $nom,
        $type,
        $description?:null,
        $capacite
    ]);


    $newId=
        (int)$pdo->lastInsertId();


    /* =====================================================
       CODE AUTOMATIQUE
    ====================================================== */
    /* Le code définitif distingue explicitement un service d'une unité. */
    $prefix=
        $type==='SERVICE'
            ?'SRV'
            :'UNT';


    $code=
        $prefix.'-'.
        str_pad(
            (string)$newId,
            6,
            '0',
            STR_PAD_LEFT
        );


    $stmt=$pdo->prepare("
        UPDATE host_units

        SET code=?

        WHERE id=?
          AND host_etablissement_id=?
    ");

    $stmt->execute([
        $code,
        $newId,
        $hopitalId
    ]);


    $pdo->commit();


    jsonResponse(
        true,
        $type==='SERVICE'
            ?'Service créé avec succès.'
            :'Unité créée avec succès.',
        [
            'id'=>$newId,
            'code'=>$code
        ]
    );


}catch(Throwable $e){
    /* En cas d'échec, le rollback évite toute unité créée sans son code définitif. */

    if(
        isset($pdo) &&
        $pdo->inTransaction()
    ){
        $pdo->rollBack();
    }


    jsonResponse(
        false,
        'Erreur : '.$e->getMessage(),
        [],
        500
    );
}
