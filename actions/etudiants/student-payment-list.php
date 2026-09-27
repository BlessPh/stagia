<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['STAGIAIRE']);

try{
    $userId=(int)($_SESSION['user_id']??0);
    if(!$userId)jsonResponse(false,'Session étudiant invalide.',[],401);

    $stmt=$pdo->prepare("
        SELECT id
        FROM student_profiles
        WHERE user_id=? AND statut='ACTIF'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $studentId=(int)$stmt->fetchColumn();

    if(!$studentId)
        jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $stmt=$pdo->prepare("
        SELECT
            a.id application_id,a.statut application_status,
            a.campaign_id,a.host_etablissement_id,a.participation_id,
            c.code campaign_code,c.titre campaign_title,
            h.nom host_name,
            r.id reservation_id,r.statut reservation_status,
            COALESCE(p.frais_requis,0) frais_requis,
            p.montant_frais,
            COALESCE(NULLIF(p.devise,''),'USD') participation_devise,
            i.id invoice_id,i.reference invoice_reference,
            i.montant invoice_amount,i.devise invoice_currency,
            i.statut invoice_status,i.date_emission,i.date_echeance,i.paid_at,
            COALESCE((
                SELECT SUM(sp.montant)
                FROM stage_payments sp
                WHERE sp.invoice_id=i.id
                  AND sp.statut='VALIDE'
                  AND sp.devise=i.devise
            ),0) paid_amount,
            (
                SELECT sp2.reference
                FROM stage_payments sp2
                WHERE sp2.invoice_id=i.id
                  AND sp2.statut IN('INITIE','EN_ATTENTE')
                ORDER BY sp2.id DESC
                LIMIT 1
            ) pending_reference
        FROM stage_applications a
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id AND se.student_id=?
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN stage_reservations r ON r.application_id=a.id
        LEFT JOIN stage_campaign_participations p ON p.id=a.participation_id
        LEFT JOIN stage_invoices i ON i.reservation_id=r.id
        WHERE a.statut='ACCEPTEE'
        ORDER BY COALESCE(i.date_emission,a.responded_at,a.created_at) DESC,a.id DESC
    ");
    $stmt->execute([$studentId]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $items=[];
    $stats=['total'=>0,'a_payer'=>0,'payees'=>0,'sans_frais'=>0];

    foreach($rows as $row){
        $required=(int)$row['frais_requis']===1;
        $invoice=null;
        $remaining=0.0;
        $paymentStatus=null;
        $pending=!empty($row['pending_reference']);
        $allowed=false;

        if($required&&$row['invoice_id']){
            $amount=(float)$row['invoice_amount'];
            $paid=(float)$row['paid_amount'];
            $remaining=max(0,round($amount-$paid,2));
            $paymentStatus=(string)$row['invoice_status'];

            $allowed=
                !$pending &&
                $row['application_status']==='ACCEPTEE' &&
                $row['reservation_status']==='EN_ATTENTE_PAIEMENT' &&
                in_array($paymentStatus,['EMISE','PARTIELLEMENT_PAYEE'],true) &&
                $remaining>0;

            $invoice=[
                'id'=>(int)$row['invoice_id'],
                'reference'=>$row['invoice_reference'],
                'montant'=>$amount,
                'devise'=>$row['invoice_currency']?:$row['participation_devise'],
                'statut'=>$paymentStatus,
                'date_emission'=>$row['date_emission'],
                'date_echeance'=>$row['date_echeance'],
                'paid_at'=>$row['paid_at'],
                'paid_amount'=>$paid
            ];
        }

        $items[]=[
            'application_id'=>(int)$row['application_id'],
            'campaign_id'=>(int)$row['campaign_id'],
            'campaign_code'=>$row['campaign_code'],
            'campaign_title'=>$row['campaign_title'],
            'host_name'=>$row['host_name'],
            'reservation_id'=>$row['reservation_id']?(int)$row['reservation_id']:null,
            'reservation_status'=>$row['reservation_status'],
            'payment_required'=>$required,
            'payment_allowed'=>$allowed,
            'payment_pending'=>$pending,
            'pending_reference'=>$row['pending_reference'],
            'payment_status'=>$required?($paymentStatus?:'EMISE'):'NON_REQUIS',
            'remaining'=>$remaining,
            'invoice'=>$invoice
        ];

        $stats['total']++;

        if(!$required)$stats['sans_frais']++;
        elseif($invoice&&($paymentStatus==='PAYEE'||$remaining<=0))$stats['payees']++;
        elseif($invoice&&in_array($paymentStatus,['EMISE','PARTIELLEMENT_PAYEE'],true)&&$remaining>0)
            $stats['a_payer']++;
    }

    jsonResponse(true,'',['items'=>$items,'stats'=>$stats]);

}catch(Throwable $e){
    error_log('[STUDENT PAYMENT LIST] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur chargement paiements : '.$e->getMessage(),[],500);
}
