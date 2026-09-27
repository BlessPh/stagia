<?php

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['STAGIAIRE']);


/* =========================================================
   VARIABLES
========================================================= */

$absolutePath=null;


try{

    /* =====================================================
       1. CSRF
    ====================================================== */

    $csrf=$_POST['csrf']??'';


    if(
        empty($_SESSION['csrf']) ||
        !$csrf ||
        !hash_equals(
            $_SESSION['csrf'],
            $csrf
        )
    ){

        jsonResponse(
            false,
            'Jeton de sécurité invalide.',
            [],
            419
        );
    }


    /* =====================================================
       2. UTILISATEUR
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
       3. ÉTUDIANT
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
       4. TITRE
    ====================================================== */

    $titre=
        trim(
            $_POST['titre']
            ??''
        );


    if($titre===''){

        jsonResponse(
            false,
            'Le titre du document est obligatoire.',
            [],
            422
        );
    }


    if(
        mb_strlen(
            $titre
        )>180
    ){

        jsonResponse(
            false,
            'Le titre du document est trop long.',
            [],
            422
        );
    }


    /* =====================================================
       5. CATÉGORIE
    ====================================================== */

    $categorie=
        strtoupper(
            trim(
                $_POST['categorie']
                ??'AUTRE'
            )
        );


    $categories=[
        'IDENTITE',
        'ACADEMIQUE',
        'STAGE',
        'ADMINISTRATIF',
        'AUTRE'
    ];


    if(
        !in_array(
            $categorie,
            $categories,
            true
        )
    ){

        $categorie='AUTRE';
    }


    /* =====================================================
       6. FICHIER
    ====================================================== */

    if(
        !isset(
            $_FILES['document']
        )
    ){

        jsonResponse(
            false,
            'Veuillez sélectionner un document.',
            [],
            422
        );
    }


    $file=
        $_FILES['document'];


    /* =====================================================
       7. ERREUR UPLOAD
    ====================================================== */

    if(
        $file['error']
        !==UPLOAD_ERR_OK
    ){

        $message=
            'Erreur lors du transfert du fichier.';


        if(
            in_array(
                $file['error'],
                [
                    UPLOAD_ERR_INI_SIZE,
                    UPLOAD_ERR_FORM_SIZE
                ],
                true
            )
        ){

            $message=
                'Le fichier est trop volumineux.';
        }


        if(
            $file['error']
            ===UPLOAD_ERR_PARTIAL
        ){

            $message=
                'Le fichier n’a été transféré que partiellement.';
        }


        if(
            $file['error']
            ===UPLOAD_ERR_NO_FILE
        ){

            $message=
                'Aucun fichier sélectionné.';
        }


        jsonResponse(
            false,
            $message,
            [],
            422
        );
    }


    /* =====================================================
       8. VÉRIFIER FICHIER UPLOADÉ
    ====================================================== */

    if(
        !is_uploaded_file(
            $file['tmp_name']
        )
    ){

        jsonResponse(
            false,
            'Le fichier reçu est invalide.',
            [],
            422
        );
    }


    /* =====================================================
       9. TAILLE MAX 8 Mo
    ====================================================== */

    $size=
        (int)$file['size'];


    $maxSize=
        8*1024*1024;


    if($size<=0){

        jsonResponse(
            false,
            'Le fichier est vide.',
            [],
            422
        );
    }


    if($size>$maxSize){

        jsonResponse(
            false,
            'Le fichier ne peut pas dépasser 8 Mo.',
            [],
            422
        );
    }


    /* =====================================================
       10. NOM ORIGINAL
    ====================================================== */

    $originalName=
        basename(
            (string)$file['name']
        );


    $clientExtension=
        strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );


    /* =====================================================
       11. MIME DÉTECTÉ
    ====================================================== */

    $detectedMime=
        'inconnu';


    if(
        class_exists(
            'finfo'
        )
    ){

        $finfo=
            new finfo(
                FILEINFO_MIME_TYPE
            );


        $tmpMime=
            $finfo->file(
                $file['tmp_name']
            );


        if($tmpMime){

            $detectedMime=
                $tmpMime;
        }
    }


    /* =====================================================
       12. SIGNATURE DU FICHIER
    ====================================================== */

    $handle=
        fopen(
            $file['tmp_name'],
            'rb'
        );


    $signature=
        '';


    if($handle){

        $signature=
            fread(
                $handle,
                16
            );


        fclose(
            $handle
        );
    }


    /* =====================================================
       13. IDENTIFIER LE FORMAT RÉEL
    ====================================================== */

    $mime=null;

    $extension=null;


    /* =====================================================
       PDF
    ====================================================== */

    if(
        $clientExtension==='pdf' &&
        substr(
            $signature,
            0,
            5
        )==='%PDF-'
    ){

        $mime=
            'application/pdf';

        $extension=
            'pdf';
    }


    /* =====================================================
       JPEG
    ====================================================== */

    elseif(
        in_array(
            $clientExtension,
            [
                'jpg',
                'jpeg'
            ],
            true
        )
        &&
        substr(
            $signature,
            0,
            3
        )==="\xFF\xD8\xFF"
    ){

        $mime=
            'image/jpeg';

        $extension=
            'jpg';
    }


    /* =====================================================
       PNG
    ====================================================== */

    elseif(
        $clientExtension==='png'
        &&
        substr(
            $signature,
            0,
            8
        )==="\x89PNG\x0D\x0A\x1A\x0A"
    ){

        $mime=
            'image/png';

        $extension=
            'png';
    }


    /* =====================================================
       DOC
    ====================================================== */

    elseif(
        $clientExtension==='doc'
        &&
        substr(
            $signature,
            0,
            8
        )==="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
    ){

        $mime=
            'application/msword';

        $extension=
            'doc';
    }


    /* =====================================================
       DOCX
    ====================================================== */

    elseif(
        $clientExtension==='docx'
    ){

        if(
            class_exists(
                'ZipArchive'
            )
        ){

            $zip=
                new ZipArchive();


            $opened=
                $zip->open(
                    $file['tmp_name']
                );


            if(
                $opened===true
                &&
                $zip->locateName(
                    '[Content_Types].xml'
                )!==false
                &&
                $zip->locateName(
                    'word/document.xml'
                )!==false
            ){

                $mime=
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

                $extension=
                    'docx';
            }


            if(
                $opened===true
            ){

                $zip->close();
            }
        }
    }


    /* =====================================================
       14. REFUSER FORMAT INVALIDE
    ====================================================== */

    if(
        !$mime ||
        !$extension
    ){

        jsonResponse(
            false,
            'Format non autorisé ou fichier invalide. '.
            'Utilisez PDF, JPG, PNG, DOC ou DOCX. '.
            'Type détecté : '.
            $detectedMime,
            [],
            422
        );
    }


    /* =====================================================
       15. NETTOYER NOM ORIGINAL
    ====================================================== */

    $originalName=
        preg_replace(
            '/[\x00-\x1F\x7F]+/u',
            '',
            $originalName
        );


    if(!$originalName){

        $originalName=
            'document.'.
            $extension;
    }


    /* =====================================================
       16. UUID
    ====================================================== */

    $data=
        random_bytes(
            16
        );


    $data[6]=
        chr(
            (
                ord(
                    $data[6]
                )
                &0x0f
            )
            |0x40
        );


    $data[8]=
        chr(
            (
                ord(
                    $data[8]
                )
                &0x3f
            )
            |0x80
        );


    $uuid=
        vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(
                bin2hex(
                    $data
                ),
                4
            )
        );


    /* =====================================================
       17. DOSSIER STOCKAGE
    ====================================================== */

    $storageRoot=
        __DIR__.
        '/../../storage/student-documents';


    $studentDirectory=
        $storageRoot.
        '/'.
        $studentId;


    if(
        !is_dir(
            $studentDirectory
        )
    ){

        if(
            !mkdir(
                $studentDirectory,
                0775,
                true
            )
            &&
            !is_dir(
                $studentDirectory
            )
        ){

            throw new RuntimeException(
                'Impossible de créer le dossier de stockage.'
            );
        }
    }


    /* =====================================================
       18. PROTECTION APACHE
    ====================================================== */

    $htaccess=
        $storageRoot.
        '/.htaccess';


    if(
        !file_exists(
            $htaccess
        )
    ){

        @file_put_contents(
            $htaccess,
            "Require all denied\n"
        );
    }


    /* =====================================================
       19. NOM INTERNE
    ====================================================== */

    $storedName=
        $uuid.
        '.'.
        $extension;


    $absolutePath=
        $studentDirectory.
        '/'.
        $storedName;


    /* =====================================================
       20. DÉPLACER LE FICHIER
    ====================================================== */

    if(
        !move_uploaded_file(
            $file['tmp_name'],
            $absolutePath
        )
    ){

        throw new RuntimeException(
            'Impossible d’enregistrer le fichier.'
        );
    }


    /* =====================================================
       21. CHEMIN RELATIF
    ====================================================== */

    $relativePath=
        'storage/student-documents/'.
        $studentId.
        '/'.
        $storedName;


    /* =====================================================
       22. INSERTION BDD
    ====================================================== */

    $stmt=
        $pdo->prepare("
            INSERT INTO student_personal_documents(
                uuid,
                student_id,
                titre,
                categorie,
                nom_original,
                nom_stockage,
                mime_type,
                extension,
                taille,
                chemin
            )

            VALUES(
                ?,?,?,?,?,?,?,?,?,?
            )
        ");


    $stmt->execute([

        $uuid,

        $studentId,

        $titre,

        $categorie,

        $originalName,

        $storedName,

        $mime,

        $extension,

        $size,

        $relativePath
    ]);


    /* =====================================================
       23. SUCCÈS
    ====================================================== */

    jsonResponse(
        true,
        'Document ajouté avec succès.',
        [
            'id'=>
                (int)$pdo->lastInsertId(),

            'uuid'=>
                $uuid,

            'titre'=>
                $titre,

            'categorie'=>
                $categorie,

            'nom_original'=>
                $originalName,

            'extension'=>
                $extension,

            'taille'=>
                $size
        ]
    );


}catch(Throwable $e){


    /* =====================================================
       SUPPRIMER LE FICHIER SI ERREUR SQL
    ====================================================== */

    if(
        $absolutePath &&
        is_file(
            $absolutePath
        )
    ){

        @unlink(
            $absolutePath
        );
    }


    jsonResponse(
        false,
        'Erreur upload : '.
        $e->getMessage(),
        [],
        500
    );
}