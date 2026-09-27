<?php

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

$token=trim(
    (string)($_GET['token']??'')
);

if($token===''){
    http_response_code(400);
    exit('Convention invalide.');
}

try{

    $data=conventionData(
        $pdo,
        $token
    );

    if(!$data){
        http_response_code(404);
        exit('Convention introuvable.');
    }


    /* =====================================================
       AUTORISATION
    ====================================================== */

    $role=$_SESSION['role_code']??'';
    $userId=(int)($_SESSION['user_id']??0);


    /* STAGIAIRE */
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


    /* ÉTABLISSEMENTS */
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
       GÉNÉRATION
    ====================================================== */

    $pdf=conventionEnsurePdf(
        $pdo,
        $data
    );

    if(!is_file($pdf['path'])){
        throw new RuntimeException(
            'Fichier de convention introuvable.'
        );
    }


    $download=
        isset($_GET['download']) &&
        $_GET['download']==='1';


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
        ($download?'attachment':'inline').
        '; filename="'.
        $data['reference'].
        '.pdf"'
    );

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