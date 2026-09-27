<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$host=strtolower((string)($_SERVER['HTTP_HOST']??''));
$remote=(string)($_SERVER['REMOTE_ADDR']??'');
$isLocal=str_contains($host,'localhost')
    ||str_starts_with($host,'127.0.0.1')
    ||in_array($remote,['127.0.0.1','::1'],true);

if(!$isLocal)
    jsonResponse(false,'Simulation disponible uniquement en environnement local.',[],403);

try{
    $eid=currentEtablissementId($pdo);
    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);

    $stmt=$pdo->prepare("
        SELECT
            pay.id payment_id,
            pay.reference payment_reference,
            pay.montant amount,
            pay.devise currency,
            pay.canal channel,
            pay.operateur operator,
            pay.statut status,
            pay.initiated_at,
            pay.validated_at,

            i.reference invoice_reference,

            c.code campaign_code,
            c.titre campaign_title,

            h.nom hospital_name,

            sp.stagia_code,
            COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),
                CONCAT('Étudiant #',se.student_id)
            ) student_name

        FROM stage_payments pay
        JOIN stage_invoices i ON i.id=pay.invoice_id
        JOIN stage_applications a ON a.id=i.application_id
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id
        JOIN etablissements h ON h.id=i.host_etablissement_id

        WHERE c.owner_etablissement_id=?
          AND pay.statut IN('EN_ATTENTE','VALIDE')

        ORDER BY
            CASE WHEN pay.statut='EN_ATTENTE' THEN 0 ELSE 1 END,
            pay.id DESC
        LIMIT 100
    ");
    $stmt->execute([$eid]);
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $pending=0;$validated=0;
    foreach($items as $x){
        if($x['status']==='EN_ATTENTE')$pending++;
        if($x['status']==='VALIDE')$validated++;
    }

    jsonResponse(true,'',[
        'items'=>$items,
        'pending'=>$pending,
        'validated'=>$validated
    ]);

}catch(Throwable $e){
    error_log('[PAYMENT SIMULATE LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur : '.$e->getMessage(),[],500);
}
