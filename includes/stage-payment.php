<?php

/* =========================================================
   STAGIA - CONTRÔLE FACTURATION / PAIEMENT
========================================================= */


/* UUID */
/** Génère un UUID pour une facture ou une transaction de stage. */
function stagePaymentUuid(){

    $d=random_bytes(16);

    $d[6]=chr(
        (ord($d[6])&0x0f)|0x40
    );

    $d[8]=chr(
        (ord($d[8])&0x3f)|0x80
    );

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(
            bin2hex($d),
            4
        )
    );
}


/* =========================================================
   GÉNÉRER / RÉCUPÉRER LA FACTURE
========================================================= */
/** Crée ou retrouve la facture correspondant à une réservation et à la politique financière en vigueur. */
function ensureStageInvoice(
    PDO $pdo,
    int $reservationId
){

    /* Informations réservation */
    $stmt=$pdo->prepare("
        SELECT
            sr.id AS reservation_id,
            sr.statut AS reservation_statut,

            sa.id AS application_id,
            sa.participation_id,
            sa.host_etablissement_id,

            se.student_id,

            p.frais_requis,
            p.montant_frais,
            p.devise

        FROM stage_reservations sr

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        INNER JOIN student_academic_enrollments sae
            ON sae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=sae.enrollment_id

        LEFT JOIN stage_campaign_participations p
            ON p.id=sa.participation_id

        WHERE sr.id=?

        LIMIT 1
    ");

    $stmt->execute([
        $reservationId
    ]);

    $stage=$stmt->fetch(
        PDO::FETCH_ASSOC
    );


    if(!$stage){

        throw new RuntimeException(
            'Réservation de stage introuvable.'
        );
    }


    /* Aucun frais */
    if((int)$stage['frais_requis']!==1){

        return [
            'required'=>false,
            'allowed'=>true,
            'status'=>'NON_REQUIS',
            'invoice'=>null
        ];
    }


    $montant=
        (float)$stage['montant_frais'];


    if($montant<=0){

        throw new RuntimeException(
            'Les frais de stage sont requis mais le montant n’est pas configuré.'
        );
    }


    /* Facture existante */
    $stmt=$pdo->prepare("
        SELECT *
        FROM stage_invoices
        WHERE reservation_id=?
        LIMIT 1
    ");

    $stmt->execute([
        $reservationId
    ]);

    $invoice=$stmt->fetch(
        PDO::FETCH_ASSOC
    );


    /* Création automatique */
    if(!$invoice){

        $temporaryReference=
            'TMP-'.bin2hex(
                random_bytes(8)
            );


        $stmt=$pdo->prepare("
            INSERT INTO stage_invoices(
                uuid,
                reservation_id,
                application_id,
                participation_id,
                student_id,
                host_etablissement_id,
                reference,
                montant,
                devise,
                statut,
                date_emission
            )
            VALUES(
                ?,?,?,?,?,?,?,
                ?,?,
                'EMISE',
                NOW()
            )
        ");

        $stmt->execute([
            stagePaymentUuid(),
            $reservationId,
            $stage['application_id'],
            $stage['participation_id'],
            $stage['student_id'],
            $stage['host_etablissement_id'],
            $temporaryReference,
            $montant,
            $stage['devise']?:'USD'
        ]);


        $invoiceId=
            (int)$pdo->lastInsertId();


        $reference=
            'FAC-STG-'.
            str_pad(
                (string)$invoiceId,
                8,
                '0',
                STR_PAD_LEFT
            );


        $stmt=$pdo->prepare("
            UPDATE stage_invoices
            SET reference=?
            WHERE id=?
        ");

        $stmt->execute([
            $reference,
            $invoiceId
        ]);


        $stmt=$pdo->prepare("
            SELECT *
            FROM stage_invoices
            WHERE id=?
        ");

        $stmt->execute([
            $invoiceId
        ]);

        $invoice=$stmt->fetch(
            PDO::FETCH_ASSOC
        );
    }


    /* =====================================================
       RECALCULER LES PAIEMENTS VALIDÉS
    ====================================================== */
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
        $invoice['id'],
        $invoice['devise']
    ]);


    $paid=
        (float)$stmt->fetchColumn();


    $invoiceAmount=
        (float)$invoice['montant'];


    /* =====================================================
       SYNCHRONISER FACTURE
    ====================================================== */
    if($paid >= $invoiceAmount){

        $newStatus='PAYEE';


        if($invoice['statut']!=='PAYEE'){

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
                $invoice['id']
            ]);
        }


    }elseif($paid>0){

        $newStatus=
            'PARTIELLEMENT_PAYEE';


        if(
            !in_array(
                $invoice['statut'],
                [
                    'ANNULEE',
                    'EXPIREE'
                ],
                true
            )
        ){

            $stmt=$pdo->prepare("
                UPDATE stage_invoices
                SET statut='PARTIELLEMENT_PAYEE'
                WHERE id=?
            ");

            $stmt->execute([
                $invoice['id']
            ]);
        }


    }else{

        $newStatus=
            $invoice['statut'];
    }


    /* Actualiser */
    $stmt=$pdo->prepare("
        SELECT *
        FROM stage_invoices
        WHERE id=?
    ");

    $stmt->execute([
        $invoice['id']
    ]);

    $invoice=$stmt->fetch(
        PDO::FETCH_ASSOC
    );


    return [
        'required'=>true,

        'allowed'=>
            $invoice['statut']==='PAYEE',

        'status'=>
            $invoice['statut'],

        'paid'=>$paid,

        'remaining'=>
            max(
                0,
                $invoiceAmount-$paid
            ),

        'invoice'=>$invoice
    ];
}


/* =========================================================
   VÉRIFICATION SIMPLE
========================================================= */
/** Détermine si une réservation peut continuer selon l'état de sa facture et de ses paiements. */
function stagePaymentAllowed(
    PDO $pdo,
    int $reservationId
){

    $result=
        ensureStageInvoice(
            $pdo,
            $reservationId
        );

    return $result;
}
