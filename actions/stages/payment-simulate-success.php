<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

$host=strtolower((string)($_SERVER['HTTP_HOST']??''));
$remote=(string)($_SERVER['REMOTE_ADDR']??'');
$isLocal=str_contains($host,'localhost')
    ||str_starts_with($host,'127.0.0.1')
    ||in_array($remote,['127.0.0.1','::1'],true);

if(!$isLocal)
    jsonResponse(false,'Simulation disponible uniquement en environnement local.',[],403);

try{
    $eid=currentEtablissementId($pdo);
    $paymentId=(int)($_POST['payment_id']??0);
    $userId=(int)($_SESSION['user_id']??0);

    if(!$eid||!$paymentId)
        jsonResponse(false,'Paiement invalide.',[],422);

    /*
     * Les tables financières historiques sont en MyISAM.
     * L'action est donc volontairement IDEMPOTENTE :
     * si elle est relancée après une coupure, elle termine le flux
     * sans créer de doublon.
     */
    $stmt=$pdo->prepare("
        SELECT
            pay.*,

            i.id invoice_id,
            i.reference invoice_reference,
            i.montant invoice_amount,
            i.devise invoice_currency,
            i.statut invoice_status,
            i.reservation_id,
            i.application_id,
            i.host_etablissement_id,

            r.statut reservation_status,

            a.statut application_status,

            c.owner_etablissement_id

        FROM stage_payments pay
        JOIN stage_invoices i ON i.id=pay.invoice_id
        JOIN stage_reservations r ON r.id=i.reservation_id
        JOIN stage_applications a ON a.id=i.application_id
        JOIN stage_campaigns c ON c.id=a.campaign_id

        WHERE pay.id=?
          AND c.owner_etablissement_id=?

        LIMIT 1
    ");
    $stmt->execute([$paymentId,$eid]);
    $p=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$p)
        throw new RuntimeException('Paiement introuvable dans votre établissement.');

    if($p['application_status']!=='ACCEPTEE')
        throw new RuntimeException("La candidature n'est pas acceptée.");

    if(!in_array($p['statut'],['EN_ATTENTE','VALIDE'],true))
        throw new RuntimeException('Ce paiement ne peut pas être simulé.');

    if(!in_array($p['invoice_status'],['EMISE','PARTIELLEMENT_PAYEE','PAYEE'],true))
        throw new RuntimeException('La facture ne peut plus être réglée.');

    if(!in_array($p['reservation_status'],['EN_ATTENTE_PAIEMENT','CONFIRMEE'],true))
        throw new RuntimeException("La réservation n'est pas en attente de paiement.");

    /* 1. Valider le paiement s'il ne l'est pas encore. */
    if($p['statut']==='EN_ATTENTE'){
        $transaction=$p['transaction_reference']
            ?:('SIM-'.date('YmdHis').'-'.str_pad((string)$paymentId,6,'0',STR_PAD_LEFT));

        $metadata=[];
        if(!empty($p['metadata'])){
            $decoded=json_decode((string)$p['metadata'],true);
            if(is_array($decoded))$metadata=$decoded;
        }

        $metadata['callback']=[
            'result'=>'VALIDE',
            'payload'=>[
                'provider'=>'SIMULATOR',
                'environment'=>'LOCAL',
                'simulated_by'=>$userId
            ],
            'received_at'=>date(DATE_ATOM),
            'transaction_reference'=>$transaction
        ];

        $stmt=$pdo->prepare("
            UPDATE stage_payments
            SET
                statut='VALIDE',
                transaction_reference=?,
                paid_at=COALESCE(paid_at,NOW()),
                validated_at=COALESCE(validated_at,NOW()),
                validated_by=?,
                metadata=?
            WHERE id=?
              AND statut='EN_ATTENTE'
        ");
        $stmt->execute([
            $transaction,
            $userId,
            json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $paymentId
        ]);
    }

    /* 2. Vérifier le total réellement validé sur la facture. */
    $stmt=$pdo->prepare("
        SELECT COALESCE(SUM(montant),0)
        FROM stage_payments
        WHERE invoice_id=?
          AND statut='VALIDE'
          AND devise=?
    ");
    $stmt->execute([(int)$p['invoice_id'],$p['invoice_currency']]);
    $paid=(float)$stmt->fetchColumn();
    $required=(float)$p['invoice_amount'];

    if($paid+0.00001<$required){
        $pdo->prepare("
            UPDATE stage_invoices
            SET statut='PARTIELLEMENT_PAYEE'
            WHERE id=?
        ")->execute([(int)$p['invoice_id']]);

        jsonResponse(
            true,
            'Paiement test validé partiellement. La réservation reste en attente.',
            [
                'paid'=>$paid,
                'required'=>$required,
                'remaining'=>max(0,$required-$paid)
            ]
        );
    }

    /* 3. Facture réglée. */
    $pdo->prepare("
        UPDATE stage_invoices
        SET
            statut='PAYEE',
            paid_at=COALESCE(paid_at,NOW())
        WHERE id=?
    ")->execute([(int)$p['invoice_id']]);

    /* 4. Place confirmée. */
    $pdo->prepare("
        UPDATE stage_reservations
        SET
            statut='CONFIRMEE',
            confirmed_at=COALESCE(confirmed_at,NOW()),
            expires_at=NULL,
            cancelled_at=NULL
        WHERE id=?
    ")->execute([(int)$p['reservation_id']]);

    jsonResponse(
        true,
        "Paiement test validé. Facture payée et réservation prête pour le placement universitaire.",
        [
            'payment_id'=>$paymentId,
            'invoice_id'=>(int)$p['invoice_id'],
            'reservation_id'=>(int)$p['reservation_id'],
            'reservation_status'=>'CONFIRMEE',
            'placement_pending'=>true
        ]
    );

}catch(Throwable $e){
    error_log('[PAYMENT SIMULATE SUCCESS] '.$e->getMessage());
    jsonResponse(false,$e->getMessage(),[],422);
}
