<?php

/* =========================================================
   STAGIA - FINALISATION D'UN PAIEMENT
   Utilisé par :
   - M-Pesa
   - Orange Money
   - Airtel Money
   - Afrimoney
   - Banque
   - simulateur local
========================================================= */

function finalizeStagePayment(
    PDO $pdo,
    int $paymentId,
    string $result,
    ?string $transactionReference=null,
    array $payload=[]
): array {

    $result=strtoupper(trim($result));

    if(!in_array($result,['VALIDE','ECHOUE'],true))
        throw new InvalidArgumentException(
            'Résultat de paiement invalide.'
        );

    if($paymentId<=0)
        throw new InvalidArgumentException(
            'Paiement invalide.'
        );


    $ownTransaction=
        !$pdo->inTransaction();

    try{

        if($ownTransaction)
            $pdo->beginTransaction();


        /* =================================================
           PAIEMENT + FACTURE
        ================================================= */
        $stmt=$pdo->prepare("
            SELECT
                p.id,
                p.reference,
                p.transaction_reference,
                p.montant,
                p.devise,
                p.canal,
                p.operateur,
                p.statut,
                p.metadata,

                i.id AS invoice_id,
                i.reference AS invoice_reference,
                i.montant AS invoice_amount,
                i.devise AS invoice_currency,
                i.statut AS invoice_status,

                r.id AS reservation_id,
                r.statut AS reservation_status,
                a.statut AS application_status

            FROM stage_payments p

            INNER JOIN stage_invoices i
                ON i.id=p.invoice_id

            INNER JOIN stage_reservations r
                ON r.id=i.reservation_id

            INNER JOIN stage_applications a
                ON a.id=r.application_id
               AND a.id=i.application_id

            WHERE p.id=?

            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([$paymentId]);

        $payment=$stmt->fetch(
            PDO::FETCH_ASSOC
        );


        if(!$payment)
            throw new RuntimeException(
                'Paiement introuvable.'
            );

        if($payment['application_status']!=='ACCEPTEE')
            throw new RuntimeException(
                "La candidature n'est pas encore acceptée."
            );

        if(!in_array($payment['reservation_status'],['EN_ATTENTE_PAIEMENT','CONFIRMEE'],true))
            throw new RuntimeException(
                "La réservation n'est pas à une étape payable."
            );


        /* =================================================
           CALLBACK IDEMPOTENT
        ================================================= */
        if(
            in_array($payment['statut'],['VALIDE','ECHOUE'],true) &&
            $payment['statut']===$result
        ){

            if($payment['invoice_status']==='PAYEE'){
                $stmt=$pdo->prepare("
                    UPDATE stage_reservations
                    SET statut='CONFIRMEE',
                        confirmed_at=COALESCE(confirmed_at,NOW()),
                        expires_at=NULL,
                        cancelled_at=NULL
                    WHERE id=? AND statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
                ");
                $stmt->execute([(int)$payment['reservation_id']]);
            }

            if($ownTransaction)
                $pdo->commit();

            return [
                'payment_id'=>$paymentId,
                'payment_status'=>$payment['statut'],
                'invoice_id'=>(int)$payment['invoice_id'],
                'invoice_status'=>$payment['invoice_status'],
                'already_processed'=>true
            ];
        }


        /* Un paiement terminal ne change plus */
        if(
            in_array(
                $payment['statut'],
                [
                    'VALIDE',
                    'ECHOUE',
                    'ANNULE',
                    'REMBOURSE'
                ],
                true
            )
        ){
            throw new RuntimeException(
                'Ce paiement a déjà été finalisé.'
            );
        }


        if(
            in_array(
                $payment['invoice_status'],
                ['ANNULEE','EXPIREE'],
                true
            )
        ){
            throw new RuntimeException(
                'La facture n’est plus payable.'
            );
        }


        /* =================================================
           MÉTADONNÉES CALLBACK
        ================================================= */
        $metadata=[];

        if(!empty($payment['metadata'])){

            $decoded=json_decode(
                $payment['metadata'],
                true
            );

            if(is_array($decoded))
                $metadata=$decoded;
        }


        $metadata['callback']=[
            'result'=>$result,
            'transaction_reference'=>
                $transactionReference,

            'received_at'=>
                date('c'),

            'payload'=>$payload
        ];


        $metadataJson=json_encode(
            $metadata,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );


        /* =================================================
           PAIEMENT VALIDÉ
        ================================================= */
        if($result==='VALIDE'){

            /*
             * Empêcher un deuxième paiement d'une facture
             * déjà entièrement réglée.
             */
            if($payment['invoice_status']==='PAYEE')
                throw new RuntimeException(
                    'Cette facture est déjà payée.'
                );


            $stmt=$pdo->prepare("
                UPDATE stage_payments

                SET statut='VALIDE',
                    transaction_reference=
                        COALESCE(
                            NULLIF(?, ''),
                            transaction_reference
                        ),
                    paid_at=COALESCE(
                        paid_at,
                        NOW()
                    ),
                    validated_at=COALESCE(
                        validated_at,
                        NOW()
                    ),
                    metadata=?

                WHERE id=?
            ");

            $stmt->execute([
                $transactionReference,
                $metadataJson,
                $paymentId
            ]);


        }else{

            /* =============================================
               PAIEMENT ÉCHOUÉ
            ============================================= */
            $stmt=$pdo->prepare("
                UPDATE stage_payments

                SET statut='ECHOUE',
                    transaction_reference=
                        COALESCE(
                            NULLIF(?, ''),
                            transaction_reference
                        ),
                    metadata=?

                WHERE id=?
            ");

            $stmt->execute([
                $transactionReference,
                $metadataJson,
                $paymentId
            ]);
        }


        /* =================================================
           TOTAL VALIDÉ SUR LA FACTURE
        ================================================= */
        $stmt=$pdo->prepare("
            SELECT COALESCE(
                SUM(montant),
                0
            )

            FROM stage_payments

            WHERE invoice_id=?
              AND statut='VALIDE'
              AND devise=?
        ");

        $stmt->execute([
            $payment['invoice_id'],
            $payment['invoice_currency']
        ]);


        $paid=
            (float)$stmt->fetchColumn();

        $invoiceAmount=
            (float)$payment['invoice_amount'];


        /* =================================================
           STATUT FACTURE
        ================================================= */
        if($paid >= $invoiceAmount){

            $invoiceStatus='PAYEE';

            $stmt=$pdo->prepare("
                UPDATE stage_invoices

                SET statut='PAYEE',
                    paid_at=COALESCE(
                        paid_at,
                        NOW()
                    )

                WHERE id=?
            ");

            $stmt->execute([
                $payment['invoice_id']
            ]);

            $stmt=$pdo->prepare("
                UPDATE stage_reservations
                SET statut='CONFIRMEE',
                    confirmed_at=COALESCE(confirmed_at,NOW()),
                    expires_at=NULL,
                    cancelled_at=NULL
                WHERE id=? AND statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
            ");
            $stmt->execute([(int)$payment['reservation_id']]);


        }elseif($paid>0){

            $invoiceStatus=
                'PARTIELLEMENT_PAYEE';

            $stmt=$pdo->prepare("
                UPDATE stage_invoices

                SET statut='PARTIELLEMENT_PAYEE',
                    paid_at=NULL

                WHERE id=?
                  AND statut NOT IN(
                        'ANNULEE',
                        'EXPIREE'
                  )
            ");

            $stmt->execute([
                $payment['invoice_id']
            ]);


        }else{

            $invoiceStatus='EMISE';

            $stmt=$pdo->prepare("
                UPDATE stage_invoices

                SET statut='EMISE',
                    paid_at=NULL

                WHERE id=?
                  AND statut NOT IN(
                        'ANNULEE',
                        'EXPIREE'
                  )
            ");

            $stmt->execute([
                $payment['invoice_id']
            ]);
        }


        if($ownTransaction)
            $pdo->commit();


        return [
            'payment_id'=>$paymentId,

            'payment_reference'=>
                $payment['reference'],

            'payment_status'=>$result,

            'transaction_reference'=>
                $transactionReference,

            'invoice_id'=>
                (int)$payment['invoice_id'],

            'invoice_reference'=>
                $payment['invoice_reference'],

            'invoice_status'=>
                $invoiceStatus,

            'amount'=>
                (float)$payment['montant'],

            'paid'=>$paid,

            'remaining'=>
                max(
                    0,
                    $invoiceAmount-$paid
                ),

            'currency'=>
                $payment['invoice_currency'],

            'already_processed'=>false
        ];


    }catch(Throwable $e){

        if(
            $ownTransaction &&
            $pdo->inTransaction()
        ){
            $pdo->rollBack();
        }

        throw $e;
    }
}
