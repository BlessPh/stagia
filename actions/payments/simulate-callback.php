<?php
if(session_status()!==PHP_SESSION_ACTIVE)
    session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/payment/stage-payment-callback.php';


/* =========================================================
   UNIQUEMENT EN LOCAL
========================================================= */
$server=
    strtolower(
        $_SERVER['SERVER_NAME']??''
    );

if(
    !in_array(
        $server,
        ['localhost','127.0.0.1'],
        true
    )
){
    jsonResponse(
        false,
        'Simulation désactivée.',
        [],
        403
    );
}


/*
 * Un étudiant ne doit JAMAIS pouvoir
 * valider lui-même son paiement.
 */
requireAjaxRole([
    'SUPER_ADMIN',
    'ADMIN_ACCUEIL'
]);


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

    $paymentId=
        (int)($_POST['payment_id']??0);

    $result=
        strtoupper(
            trim($_POST['result']??'')
        );


    if(!$paymentId)
        jsonResponse(
            false,
            'Paiement invalide.',
            [],
            422
        );


    if(
        !in_array(
            $result,
            ['VALIDE','ECHOUE'],
            true
        )
    ){
        jsonResponse(
            false,
            'Résultat invalide.',
            [],
            422
        );
    }


    /* =====================================================
       ADMIN HÔPITAL :
       uniquement ses propres factures
    ====================================================== */
    if(
        ($_SESSION['role_code']??'')
        ==='ADMIN_ACCUEIL'
    ){

        $hostId=
            currentEtablissementId($pdo);


        $stmt=$pdo->prepare("
            SELECT p.id

            FROM stage_payments p

            INNER JOIN stage_invoices i
                ON i.id=p.invoice_id

            WHERE p.id=?
              AND i.host_etablissement_id=?

            LIMIT 1
        ");

        $stmt->execute([
            $paymentId,
            $hostId
        ]);


        if(!$stmt->fetchColumn())
            jsonResponse(
                false,
                'Paiement inaccessible.',
                [],
                403
            );
    }


    /* Référence opérateur simulée */
    $transactionReference=
        'SIM-'.
        date('YmdHis').
        '-'.
        str_pad(
            (string)$paymentId,
            6,
            '0',
            STR_PAD_LEFT
        );


    $data=finalizeStagePayment(
        $pdo,
        $paymentId,
        $result,
        $transactionReference,
        [
            'provider'=>'SIMULATOR',
            'environment'=>'LOCAL',
            'simulated_by'=>
                (int)$_SESSION['user_id']
        ]
    );


    jsonResponse(
        true,

        $result==='VALIDE'
            ?'Paiement simulé et validé.'
            :'Paiement simulé comme échoué.',

        $data
    );


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur callback : '.
        $e->getMessage(),
        [],
        500
    );
}