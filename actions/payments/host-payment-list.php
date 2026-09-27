<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);

try{

    $hostId=currentEtablissementId($pdo);

    if(!$hostId)
        jsonResponse(
            false,
            'Aucun établissement associé.',
            [],
            403
        );


    /* =====================================================
       FACTURES DE L'ÉTABLISSEMENT
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            i.id AS invoice_id,
            i.reference AS invoice_reference,
            i.montant AS invoice_amount,
            i.devise AS invoice_currency,
            i.statut AS invoice_status,
            i.date_emission,
            i.date_echeance,
            i.paid_at AS invoice_paid_at,

            sp.id AS student_id,
            sp.stagia_code,
            sp.nom,
            sp.postnom,
            sp.prenom,

            c.id AS campaign_id,
            c.code AS campaign_code,
            c.titre AS campaign_title,

            u.id AS university_id,
            u.code AS university_code,
            u.nom AS university_name,

            pay.id AS payment_id,
            pay.reference AS payment_reference,
            pay.transaction_reference,
            pay.montant AS payment_amount,
            pay.devise AS payment_currency,
            pay.canal,
            pay.operateur,
            pay.statut AS payment_status,
            pay.phone_number,
            pay.initiated_at,
            pay.paid_at,
            pay.validated_at,

            (
                SELECT COALESCE(SUM(pv.montant),0)

                FROM stage_payments pv

                WHERE pv.invoice_id=i.id
                  AND pv.statut='VALIDE'
                  AND pv.devise=i.devise
            ) AS paid_amount,

            (
                SELECT COUNT(*)

                FROM stage_payments pc

                WHERE pc.invoice_id=i.id
            ) AS payment_attempts

        FROM stage_invoices i

        INNER JOIN student_profiles sp
            ON sp.id=i.student_id

        INNER JOIN stage_applications sa
            ON sa.id=i.application_id

        INNER JOIN stage_campaigns c
            ON c.id=sa.campaign_id

        INNER JOIN etablissements u
            ON u.id=c.owner_etablissement_id

        LEFT JOIN stage_payments pay
            ON pay.id=(
                SELECT p2.id

                FROM stage_payments p2

                WHERE p2.invoice_id=i.id

                ORDER BY p2.id DESC

                LIMIT 1
            )

        WHERE i.host_etablissement_id=?

        ORDER BY
            i.date_emission DESC,
            i.id DESC
    ");

    $stmt->execute([$hostId]);

    $items=$stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    /* =====================================================
       KPI
    ====================================================== */
    $stats=[
        'total'=>count($items),
        'a_encaisser'=>0,
        'payees'=>0,
        'en_attente'=>0
    ];


    foreach($items as &$x){

        $x['invoice_id']=
            (int)$x['invoice_id'];

        $x['student_id']=
            (int)$x['student_id'];

        $x['invoice_amount']=
            (float)$x['invoice_amount'];

        $x['paid_amount']=
            (float)$x['paid_amount'];

        $x['remaining']=
            max(
                0,
                $x['invoice_amount']-
                $x['paid_amount']
            );

        $x['payment_attempts']=
            (int)$x['payment_attempts'];

        $x['payment_id']=
            $x['payment_id']!==null
                ?(int)$x['payment_id']
                :null;


        if(
            in_array(
                $x['invoice_status'],
                [
                    'EMISE',
                    'PARTIELLEMENT_PAYEE'
                ],
                true
            )
        ){
            $stats['a_encaisser']++;
        }


        if($x['invoice_status']==='PAYEE')
            $stats['payees']++;


        if($x['payment_status']==='EN_ATTENTE')
            $stats['en_attente']++;
    }

    unset($x);


    jsonResponse(
        true,
        '',
        [
            'items'=>$items,
            'stats'=>$stats
        ]
    );


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur chargement paiements : '.
        $e->getMessage(),
        [],
        500
    );
}