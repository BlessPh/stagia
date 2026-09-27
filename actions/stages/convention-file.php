<?php
/**
 * Endpoint de visualisation ou téléchargement du PDF officiel d'une convention de stage.
 * Il contrôle l'appartenance du document avant de générer le PDF et d'envoyer les en-têtes adaptés.
 */

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/convention-service.php';

requireRole([
    'SUPER_ADMIN',
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE',
    'ADMIN_ACCUEIL',
    'STAGIAIRE'
]);


/* =========================================================
   PARAMÈTRES
========================================================= */

$token=trim(
    (string)($_GET['token']??'')
);

$download=
    isset($_GET['download']) &&
    $_GET['download']==='1';


if($token===''){
    http_response_code(400);
    exit('Convention invalide.');
}


try{
    /* La convention est résolue par jeton avant les vérifications propres au rôle connecté. */

    /* =====================================================
       RÉCUPÉRER LA CONVENTION
    ====================================================== */

    $data=conventionData(
        $pdo,
        $token
    );


    if(!$data){
        http_response_code(404);
        exit('Convention introuvable.');
    }


    /* =====================================================
       AUTORISATIONS
    ====================================================== */

    $role=
        $_SESSION['role_code']??'';

    $userId=
        (int)($_SESSION['user_id']??0);


    /* -----------------------------------------------------
       STAGIAIRE
    ----------------------------------------------------- */

    /* Un stagiaire ne peut accéder qu'à une convention qui lui appartient. */
    if($role==='STAGIAIRE'){

        if(
            !$userId ||
            !$data['student_user_id'] ||
            $userId!==(int)$data['student_user_id']
        ){
            http_response_code(403);
            exit('Accès refusé.');
        }
    }


    /* -----------------------------------------------------
       UNIVERSITÉ / RESPONSABLE / HÔPITAL
    ----------------------------------------------------- */

    if(
        in_array(
            $role,
            [
                'ADMIN_ETABLISSEMENT',
                'RESPONSABLE_PEDAGOGIQUE',
                'ADMIN_ACCUEIL'
            ],
            true
        )
    ){

        $etablissementId=
            currentEtablissementId($pdo);


        if(
            !$etablissementId ||
            (
                $etablissementId!==
                (int)$data['university_id']
                &&
                $etablissementId!==
                (int)$data['host_etablissement_id']
            )
        ){
            http_response_code(403);
            exit('Accès refusé.');
        }
    }


    /* =====================================================
       GÉNÉRER LE VRAI PDF STAGIA-RDC
    ====================================================== */

    /* Le service génère ou réemploie le PDF officiel stocké pour la convention. */
    $pdf=conventionEnsurePdf(
        $pdo,
        $data
    );


    if(
        empty($pdf['path']) ||
        !is_file($pdf['path'])
    ){
        throw new RuntimeException(
            'Fichier de convention introuvable.'
        );
    }


    /* =====================================================
       EMPÊCHER LE CACHE DE L'ANCIEN PDF
    ====================================================== */

    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    header(
        'Cache-Control: post-check=0, pre-check=0',
        false
    );

    header(
        'Pragma: no-cache'
    );

    header(
        'Expires: 0'
    );


    /* =====================================================
       ENVOYER LE PDF
    ====================================================== */

    $fileName=preg_replace(
        '/[\r\n"]+/',
        '',
        $data['reference'].'.pdf'
    );


    header(
        'Content-Type: application/pdf'
    );

    header(
        'Content-Length: '.
        filesize($pdf['path'])
    );

    header(
        'X-Content-Type-Options: nosniff'
    );

    header(
        'Content-Disposition: '.
        ($download
            ?'attachment'
            :'inline'
        ).
        '; filename="'.
        $fileName.
        '"'
    );


    /* Transmission binaire du fichier au navigateur en affichage intégré ou en téléchargement. */
    readfile(
        $pdf['path']
    );

    exit;


}catch(Throwable $e){

    http_response_code(500);

    exit(
        'Erreur convention : '.
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}
