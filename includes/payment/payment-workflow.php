<?php
declare(strict_types=1);

require_once __DIR__.'/financial-obligation.php';
require_once __DIR__.'/activation-subscription.php';
require_once __DIR__.'/stage-payment-callback.php';

function paymentWorkflowHasColumn(PDO $pdo,string $table,string $column):bool{
    static $cache=[];$key=$table.'.'.$column;
    if(array_key_exists($key,$cache))return $cache[$key];
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table,$column]);
    return $cache[$key]=(int)$stmt->fetchColumn()>0;
}

function paymentWorkflowCustomer(PDO $pdo,int $userId):array{
    $stmt=$pdo->prepare("SELECT TRIM(CONCAT_WS(' ',prenom,nom,postnom)) full_name,email FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC)?:[];
}

/** Construit la fiche de paiement consommable par les interfaces Web et mobile. */
function userFinancialObligationDetail(PDO $pdo,int $userId,string $uuid):array{
    $row=findUserFinancialObligation($pdo,$userId,$uuid);
    $obligation=financialPublicObligation($pdo,$row,true);
    $context=null;

    if($row['obligation_type']==='STAGE_RESERVATION'&&$row['subject_type']==='STAGE_RESERVATION'){
        $stmt=$pdo->prepare("SELECT
                r.uuid reservation_uuid,r.statut reservation_status,r.expires_at reservation_expires_at,
                a.uuid application_uuid,a.statut application_status,
                c.id campaign_id,c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
                c.date_debut campaign_start_date,c.date_fin campaign_end_date,
                h.id hospital_id,h.code hospital_code,h.nom hospital_name,h.telephone hospital_phone,
                h.email hospital_email,h.adresse hospital_address,h.ville hospital_city,h.province hospital_province,
                i.uuid invoice_uuid,i.reference invoice_reference,i.montant invoice_amount,
                i.devise invoice_currency,i.statut invoice_status,i.date_emission invoice_issued_at,
                i.date_echeance invoice_due_at,i.paid_at invoice_paid_at
            FROM stage_reservations r
            JOIN stage_applications a ON a.id=r.application_id
            JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
            JOIN student_enrollments se ON se.id=ae.enrollment_id
            JOIN student_profiles sp ON sp.id=se.student_id AND sp.user_id=?
            JOIN stage_campaigns c ON c.id=a.campaign_id
            JOIN etablissements h ON h.id=a.host_etablissement_id
            LEFT JOIN stage_invoices i ON i.reservation_id=r.id
            WHERE r.id=? LIMIT 1");
        $stmt->execute([$userId,(int)$row['subject_key']]);
        if($stage=$stmt->fetch(PDO::FETCH_ASSOC)){
            $context=[
                'type'=>'STAGE_RESERVATION',
                'reservation'=>[
                    'uuid'=>$stage['reservation_uuid'],'status'=>$stage['reservation_status'],
                    'expires_at'=>$stage['reservation_expires_at']
                ],
                'application'=>['uuid'=>$stage['application_uuid'],'status'=>$stage['application_status']],
                'campaign'=>[
                    'id'=>(int)$stage['campaign_id'],'uuid'=>$stage['campaign_uuid'],
                    'code'=>$stage['campaign_code'],'title'=>$stage['campaign_title'],
                    'start_date'=>$stage['campaign_start_date'],'end_date'=>$stage['campaign_end_date']
                ],
                'hospital'=>[
                    'id'=>(int)$stage['hospital_id'],'code'=>$stage['hospital_code'],'name'=>$stage['hospital_name'],
                    'phone'=>$stage['hospital_phone'],'email'=>$stage['hospital_email'],
                    'address'=>$stage['hospital_address'],'city'=>$stage['hospital_city'],'province'=>$stage['hospital_province']
                ],
                'invoice'=>$stage['invoice_uuid']!==null?[
                    'uuid'=>$stage['invoice_uuid'],'reference'=>$stage['invoice_reference'],
                    'amount'=>(float)$stage['invoice_amount'],'currency'=>$stage['invoice_currency'],
                    'status'=>$stage['invoice_status'],'issued_at'=>$stage['invoice_issued_at'],
                    'due_at'=>$stage['invoice_due_at'],'paid_at'=>$stage['invoice_paid_at']
                ]:null
            ];
        }
    }

    $obligation['context']=$context;
    $obligation['actions']=[
        'initiate'=>[
            'allowed'=>(bool)$obligation['payable'],'method'=>'POST',
            'path'=>'/api/v1/student/payments/initiate',
            'required_body'=>['obligation_uuid','channel','phone_number'],
            'required_header'=>'Idempotency-Key'
        ],
        'sync'=>[
            'allowed'=>(bool)$obligation['payment_pending'],'method'=>'POST',
            'path'=>'/api/v1/student/payments/sync',
            'body'=>['obligation_uuid'=>$obligation['uuid']]
        ]
    ];
    return $obligation;
}

/** Rattache les anciennes factures de stage encore payables au registre financier unifié. */
function ensurePayableFinancialObligationsForUser(PDO $pdo,int $userId):void{
    try{
        $stmt=$pdo->prepare("SELECT i.reservation_id,i.application_id,i.montant,i.devise,i.date_echeance,
                   c.owner_etablissement_id organization_id,sp.id student_id
            FROM stage_invoices i
            JOIN stage_reservations r ON r.id=i.reservation_id
            JOIN stage_applications a ON a.id=r.application_id AND a.id=i.application_id
            JOIN stage_campaigns c ON c.id=a.campaign_id
            JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
            JOIN student_enrollments se ON se.id=ae.enrollment_id
            JOIN student_profiles sp ON sp.id=se.student_id
            WHERE sp.user_id=? AND i.statut IN('EMISE','PARTIELLEMENT_PAYEE')
              AND NOT EXISTS(SELECT 1 FROM financial_obligations fo
                  WHERE fo.obligation_type='STAGE_RESERVATION' AND fo.subject_type='STAGE_RESERVATION'
                    AND fo.subject_key=CAST(i.reservation_id AS CHAR))");
        $stmt->execute([$userId]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice){
            ensureFinancialObligation($pdo,[
                'user_id'=>$userId,'organization_id'=>(int)$invoice['organization_id'],
                'obligation_type'=>'STAGE_RESERVATION','subject_type'=>'STAGE_RESERVATION',
                'subject_key'=>(string)$invoice['reservation_id'],'label'=>'Frais de réservation de stage',
                'amount'=>(float)$invoice['montant'],'currency'=>$invoice['devise'],'due_at'=>$invoice['date_echeance'],
                'metadata'=>['reservation_id'=>(int)$invoice['reservation_id'],'application_id'=>(int)$invoice['application_id'],
                    'student_id'=>(int)$invoice['student_id'],'backfilled'=>true]
            ]);
        }
    }catch(Throwable $error){
        /* La compatibilité historique ne doit jamais empêcher l'affichage des obligations déjà enregistrées. */
        error_log('[PAYMENT OBLIGATION BACKFILL] user='.$userId.' | '.$error->getMessage());
    }
}

/** Maintient la projection historique stage_payments utilisée par le workflow des stages. */
function projectFinancialStagePayment(PDO $pdo,string $merchantReference):?int{
    $stmt=$pdo->prepare("SELECT fp.*,fo.subject_key,i.id invoice_id
        FROM financial_payments fp
        JOIN financial_obligations fo ON fo.id=fp.obligation_id
        JOIN stage_invoices i ON i.reservation_id=CAST(fo.subject_key AS UNSIGNED)
        WHERE fp.merchant_reference=?
          AND fo.obligation_type='STAGE_RESERVATION'
          AND fo.subject_type='STAGE_RESERVATION'
        LIMIT 1");
    $stmt->execute([$merchantReference]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $find=$pdo->prepare('SELECT id FROM stage_payments WHERE reference=? AND invoice_id=? LIMIT 1');
    $find->execute([$merchantReference,(int)$row['invoice_id']]);
    if($id=(int)$find->fetchColumn())return $id;

    $fields=['uuid','invoice_id','reference','montant','devise','canal','operateur','statut','phone_number','transaction_reference','initiated_at','metadata'];
    $values=['?','?','?','?','?','?','?',"'EN_ATTENTE'",'?','?','COALESCE(?,NOW())','?'];
    $params=[financialUuid(),(int)$row['invoice_id'],$merchantReference,(float)$row['amount'],$row['currency'],
        $row['channel'],maishapayProvider((string)$row['channel']),$row['wallet_phone'],$row['provider_transaction_id']?:null,
        $row['initiated_at'],json_encode(['source'=>'FINANCIAL_OBLIGATION','financial_payment_id'=>(int)$row['id'],
            'obligation_id'=>(int)$row['obligation_id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    if(paymentWorkflowHasColumn($pdo,'stage_payments','idempotency_key')){
        array_splice($fields,2,0,['idempotency_key']);array_splice($values,2,0,['?']);array_splice($params,2,0,[$row['idempotency_key']]);
    }
    try{
        $pdo->prepare('INSERT INTO stage_payments('.implode(',',$fields).') VALUES('.implode(',',$values).')')->execute($params);
        return (int)$pdo->lastInsertId();
    }catch(PDOException $error){
        $find->execute([$merchantReference,(int)$row['invoice_id']]);
        if($id=(int)$find->fetchColumn())return $id;
        throw $error;
    }
}

function initiateUserFinancialPayment(PDO $pdo,int $userId,array $input):array{
    $uuid=trim((string)($input['obligation_uuid']??''));
    $obligation=findUserFinancialObligation($pdo,$userId,$uuid);
    $result=initiateFinancialPayment($pdo,(int)$obligation['id'],$userId,$input,paymentWorkflowCustomer($pdo,$userId));
    if(!empty($result['payment']['merchant_reference']))projectFinancialStagePayment($pdo,(string)$result['payment']['merchant_reference']);
    $current=synchronizeFinancialObligation($pdo,$userId,$uuid);
    return [
        'created'=>(bool)($result['created']??false),'already_paid'=>(bool)($result['already_paid']??false),
        'payment'=>isset($result['payment'])?financialPublicPayment($result['payment']):null,
        'obligation'=>$current
    ];
}

/** Finalise l'obligation générique puis ses projections métier. */
function finalizeFinancialPaymentWorkflow(PDO $pdo,string $merchantReference,string $status,?string $providerTransactionId,array $payload=[]):array{
    $stmt=$pdo->prepare('SELECT o.* FROM financial_obligations o JOIN financial_payments p ON p.obligation_id=o.id WHERE p.merchant_reference=? LIMIT 1');
    $stmt->execute([$merchantReference]);$obligation=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$obligation)throw new OutOfBoundsException('Paiement introuvable.');
    $result=$obligation['obligation_type']==='STUDENT_ACTIVATION'
        ?finalizeActivationSubscription($pdo,$merchantReference,$status,$providerTransactionId,$payload)
        :finalizeFinancialPayment($pdo,$merchantReference,$status,$providerTransactionId,$payload);

    if($obligation['obligation_type']==='STAGE_RESERVATION'&&$obligation['subject_type']==='STAGE_RESERVATION'){
        $stagePaymentId=projectFinancialStagePayment($pdo,$merchantReference);
        if($stagePaymentId){
            $stageResult=finalizeStagePayment(
                $pdo,$stagePaymentId,$result['payment_status']==='SUCCEEDED'?'VALIDE':'ECHOUE',$providerTransactionId,$payload
            );
            $result['stage']=$stageResult;
        }
    }
    return $result;
}

/** Retourne l'état local écrit par le callback et répare si nécessaire la projection de stage. */
function synchronizeFinancialPaymentWorkflow(PDO $pdo,int $userId,string $obligationUuid):array{
    $obligation=findUserFinancialObligation($pdo,$userId,$obligationUuid);
    if($obligation['obligation_type']==='STAGE_RESERVATION'&&$obligation['subject_type']==='STAGE_RESERVATION'){
        $stmt=$pdo->prepare("SELECT merchant_reference,status,provider_transaction_id,callback_payload
            FROM financial_payments WHERE obligation_id=? AND status IN('SUCCEEDED','FAILED') ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int)$obligation['id']]);$payment=$stmt->fetch(PDO::FETCH_ASSOC);
        if($payment){
            $stageId=projectFinancialStagePayment($pdo,(string)$payment['merchant_reference']);
            if($stageId){
                $payload=json_decode((string)($payment['callback_payload']??''),true);
                finalizeStagePayment($pdo,$stageId,$payment['status']==='SUCCEEDED'?'VALIDE':'ECHOUE',
                    $payment['provider_transaction_id'],is_array($payload)?$payload:[]);
            }
        }
    }
    return synchronizeFinancialObligation($pdo,$userId,$obligationUuid);
}

