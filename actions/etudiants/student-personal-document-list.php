<?php

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['STAGIAIRE']);


try{

    /* =====================================================
       UTILISATEUR CONNECTÉ
    ====================================================== */

    $userId=
        (int)(
            $_SESSION['user_id']
            ??0
        );


    if(!$userId){

        jsonResponse(
            false,
            'Utilisateur non identifié.',
            [],
            401
        );
    }


    /* =====================================================
       PROFIL ÉTUDIANT
    ====================================================== */

    $stmt=
        $pdo->prepare("
            SELECT id

            FROM student_profiles

            WHERE user_id=?

            LIMIT 1
        ");


    $stmt->execute([
        $userId
    ]);


    $studentId=
        (int)$stmt->fetchColumn();


    if(!$studentId){

        jsonResponse(
            false,
            'Profil étudiant introuvable.',
            [],
            404
        );
    }


    /* =====================================================
       DOCUMENTS PERSONNELS
    ====================================================== */

    $stmt=
        $pdo->prepare("
            SELECT
                id,
                uuid,
                titre,
                categorie,
                nom_original,
                mime_type,
                extension,
                taille,
                created_at

            FROM student_personal_documents

            WHERE student_id=?

            ORDER BY
                created_at DESC,
                id DESC
        ");


    $stmt->execute([
        $studentId
    ]);


    $items=
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /* =====================================================
       NORMALISATION
    ====================================================== */

    foreach(
        $items as &$item
    ){

        $item['id']=
            (int)$item['id'];


        $item['taille']=
            (int)$item['taille'];

    }


    unset(
        $item
    );


    /* =====================================================
       RÉPONSE
    ====================================================== */

    jsonResponse(
        true,
        '',
        [
            'items'=>$items,
            'total'=>count($items)
        ]
    );


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur documents personnels : '.
        $e->getMessage(),
        [],
        500
    );

}