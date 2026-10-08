<?php
declare(strict_types=1);

require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/payment/payment-workflow.php';

function paymentDiagnostic(string $label,callable $check):void{
    try{
        $result=$check();
        echo '[OK] '.$label;
        if(is_int($result))echo ' : '.$result.' ligne(s)';
        echo PHP_EOL;
    }catch(Throwable $error){
        echo '[ERREUR] '.$label.' : '.$error->getMessage().PHP_EOL;
    }
}

$userId=(int)$pdo->query("SELECT user_id FROM student_profiles ORDER BY id LIMIT 1")->fetchColumn();
if($userId<1){
    echo "Aucun profil etudiant : test effectue avec un utilisateur fictif sans donnees.".PHP_EOL;
    $userId=-1;
}

paymentDiagnostic('Collection des obligations',static function()use($pdo,$userId):int{
    return count(listUserFinancialObligations($pdo,$userId)['items']);
});

paymentDiagnostic('Collection historique des paiements de stage',static function()use($pdo,$userId):int{
    $stmt=$pdo->prepare("SELECT a.uuid application_uuid,a.statut application_status,
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
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN student_profiles sp ON sp.id=se.student_id AND sp.user_id=?
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN stage_reservations r ON r.application_id=a.id
        LEFT JOIN stage_campaign_participations p ON p.id=a.participation_id
        LEFT JOIN stage_invoices i ON i.reservation_id=r.id
        LEFT JOIN financial_obligations fo ON fo.obligation_type='STAGE_RESERVATION'
             AND fo.subject_type='STAGE_RESERVATION' AND CAST(fo.subject_key AS UNSIGNED)=r.id
        WHERE a.statut='ACCEPTEE'
        ORDER BY COALESCE(i.date_emission,a.responded_at,a.created_at) DESC,a.id DESC");
    $stmt->execute([$userId]);
    return count($stmt->fetchAll(PDO::FETCH_ASSOC));
});

paymentDiagnostic('Colonnes des transactions financieres',static function()use($pdo):int{
    $required=['uuid','merchant_reference','provider','provider_transaction_id','channel','wallet_phone',
        'amount','currency','status','initiated_at','paid_at','failed_at','created_at','updated_at'];
    $columns=$pdo->query('SHOW COLUMNS FROM financial_payments')->fetchAll(PDO::FETCH_COLUMN);
    $missing=array_values(array_diff($required,$columns));
    if($missing)throw new RuntimeException('colonnes absentes : '.implode(', ',$missing));
    return count($columns);
});
