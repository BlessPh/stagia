<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-payment.php';
require_once __DIR__.'/../../../includes/payment/payment-workflow.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$studentId=(int)$student['student_id'];

try{
    ensurePayableFinancialObligationsForUser($pdo,(int)$student['user_id']);
    $rawStatuses=$_GET['status']??$_GET['statuses']??[];
    if(!is_array($rawStatuses))$rawStatuses=explode(',',(string)$rawStatuses);
    $obligations=listUserFinancialObligations($pdo,(int)$student['user_id'],[
        'statuses'=>$rawStatuses,'type'=>$_GET['type']??''
    ]);
    $stmt=$pdo->prepare("
        SELECT a.uuid application_uuid,a.statut application_status,
               c.code campaign_code,c.titre campaign_title,
               h.code hospital_code,h.nom hospital_name,
               r.uuid reservation_uuid,r.statut reservation_status,
               COALESCE(p.frais_requis,0) payment_required,p.montant_frais,p.devise participation_currency,
               i.id invoice_id,i.uuid invoice_uuid,i.reference invoice_reference,
               i.montant invoice_amount,i.devise invoice_currency,i.statut invoice_status,
               i.date_emission,i.date_echeance,i.paid_at,
               fo.uuid obligation_uuid,fo.reference obligation_reference,fo.status obligation_status,
               fo.amount obligation_amount,fo.currency obligation_currency,fo.due_at obligation_due_at
        FROM stage_applications a
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id AND se.student_id=?
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN stage_reservations r ON r.application_id=a.id
        LEFT JOIN stage_campaign_participations p ON p.id=a.participation_id
        LEFT JOIN stage_invoices i ON i.reservation_id=r.id
        LEFT JOIN financial_obligations fo ON fo.obligation_type='STAGE_RESERVATION'
             AND fo.subject_type='STAGE_RESERVATION' AND fo.subject_key=CAST(r.id AS CHAR)
        WHERE a.statut='ACCEPTEE'
        ORDER BY COALESCE(i.date_emission,a.responded_at,a.created_at) DESC,a.id DESC
    ");
    $stmt->execute([$studentId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $items=[];$stats=['total'=>0,'payable'=>0,'paid'=>0,'pending'=>0,'failed'=>0,'free'=>0];

    $paymentStmt=$pdo->prepare('SELECT * FROM stage_payments WHERE invoice_id=? ORDER BY id DESC');
    foreach($rows as $row){
        $required=(bool)$row['payment_required'];$payments=[];$paid=0.0;$pending=false;$failed=false;
        if($row['invoice_id']){
            $paymentStmt->execute([(int)$row['invoice_id']]);
            foreach($paymentStmt->fetchAll(PDO::FETCH_ASSOC) as $payment){
                if($payment['statut']==='VALIDE'&&$payment['devise']===$row['invoice_currency'])$paid+=(float)$payment['montant'];
                if(in_array($payment['statut'],['INITIE','EN_ATTENTE'],true))$pending=true;
                if($payment['statut']==='ECHOUE')$failed=true;
                $payments[]=studentPaymentPublicRow($payment);
            }
        }
        $amount=$row['invoice_amount']!==null?(float)$row['invoice_amount']:(float)($row['montant_frais']??0);
        $remaining=max(0,round($amount-$paid,2));
        $allowed=$required&&$row['reservation_status']==='EN_ATTENTE_PAIEMENT'
            &&in_array($row['invoice_status'],['EMISE','PARTIELLEMENT_PAYEE'],true)&&!$pending&&$remaining>0;
        $paymentStatus=!$required?'NOT_REQUIRED':(
            $row['invoice_status']==='PAYEE'||$remaining<=0?'PAID':(
                $paid>0?'PARTIAL':($pending?'PENDING':($failed?'FAILED':'NOT_STARTED'))
            )
        );
        $items[]=[
            'application_uuid'=>$row['application_uuid'],
            'reservation_uuid'=>$row['reservation_uuid'],
            'reservation_status'=>$row['reservation_status'],
            'campaign'=>['code'=>$row['campaign_code'],'title'=>$row['campaign_title']],
            'hospital'=>['code'=>$row['hospital_code'],'name'=>$row['hospital_name']],
            'payment_required'=>$required,'payment_allowed'=>$allowed,'payment_status'=>$paymentStatus,
            'amount_required'=>$amount,'amount_validated'=>round($paid,2),'amount_remaining'=>$remaining,
            'currency'=>$row['invoice_currency']?:$row['participation_currency'],
            'invoice'=>$row['invoice_id']?[
                'uuid'=>$row['invoice_uuid'],'reference'=>$row['invoice_reference'],'status'=>$row['invoice_status'],
                'issued_at'=>$row['date_emission'],'due_at'=>$row['date_echeance'],'paid_at'=>$row['paid_at']
            ]:null,
            'obligation'=>$row['obligation_uuid']?[
                'uuid'=>$row['obligation_uuid'],'reference'=>$row['obligation_reference'],
                'status'=>$row['obligation_status'],'amount'=>(float)$row['obligation_amount'],
                'currency'=>$row['obligation_currency'],'due_at'=>$row['obligation_due_at'],
                'blocks_action'=>$row['obligation_status']!=='PAID'
            ]:null,
            'payments'=>$payments
        ];
        $stats['total']++;
        if(!$required)$stats['free']++;
        elseif($paymentStatus==='PAID')$stats['paid']++;
        elseif($paymentStatus==='PENDING')$stats['pending']++;
        elseif($paymentStatus==='FAILED')$stats['failed']++;
        if($allowed)$stats['payable']++;
    }
    apiResponse(true,'',[
        /* Collection historique des paiements de stage. */
        'items'=>$items,'stats'=>$stats,
        /* Collection canonique de toutes les obligations financières du payeur. */
        'obligations'=>$obligations['items'],'obligation_stats'=>$obligations['stats'],
        'filters'=>$obligations['filters'],
        'available_filters'=>['statuses'=>['PENDING','PARTIALLY_PAID','PAID','CANCELLED','EXPIRED']],
        'channels'=>studentPaymentChannels()
    ]);
}catch(Throwable $e){
    error_log('[API STUDENT PAYMENTS] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche le chargement des paiements.',[],500);
}
