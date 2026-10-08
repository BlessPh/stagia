<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-student-notifications.php';
require_once __DIR__.'/../../includes/payment/financial-obligation.php';

requirePermission($pdo,'assignment.hosting.manage');
verifyAjaxCsrf();

$eid=currentEtablissementId($pdo);
$admissionId=(int)($_POST['admission_id']??0);
$unitId=(int)($_POST['host_unit_id']??0);
$dateStart=trim((string)($_POST['date_debut']??''));
$dateEnd=trim((string)($_POST['date_fin']??''));
$observation=trim((string)($_POST['observation']??''));

if(!$eid||!$admissionId||!$unitId||$dateStart===''||$dateEnd==='')
    jsonResponse(false,'Admission, service et période sont obligatoires.',[],422);

if(strtotime($dateStart)>strtotime($dateEnd))
    jsonResponse(false,'La date de fin doit être postérieure ou égale à la date de début.',[],422);

function assignmentUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT ad.id,ad.statut,ad.coordination_unit_id,sr.id reservation_id,sr.statut reservation_status,
               app.id application_id,app.host_etablissement_id,app.participation_id,
               app.statut application_status,pl.statut placement_status,
               se.student_id,pr.academic_level_id,
               c.date_debut campaign_start,c.date_fin campaign_end,
               COALESCE(p.frais_requis,0) frais_stage_requis,COALESCE(p.montant_frais,0) frais_stage_montant,
               COALESCE(sf.amount,0) frais_stagia_montant
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id
        JOIN stage_placements pl ON pl.id=ad.placement_id
             AND pl.reservation_id=sr.id
             AND pl.host_etablissement_id=ad.host_etablissement_id
        JOIN stage_campaigns c ON c.id=app.campaign_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN promotions pr ON pr.id=ae.promotion_id
        LEFT JOIN stage_campaign_participations p ON p.id=app.participation_id
        LEFT JOIN stagia_hospital_level_fees sf ON sf.host_etablissement_id=ad.host_etablissement_id
             AND sf.academic_level_id=pr.academic_level_id AND sf.actif=1
        WHERE ad.id=? AND ad.host_etablissement_id=? AND app.host_etablissement_id=?
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$admissionId,$eid,$eid]);$a=$s->fetch(PDO::FETCH_ASSOC);

    if(!$a)throw new RuntimeException('Admission introuvable dans cet établissement.');
    if($a['application_status']!=='ACCEPTEE')throw new RuntimeException("La candidature n'a pas reçu de décision universitaire favorable.");
    if($a['placement_status']!=='CONFIRME')throw new RuntimeException("Le placement universitaire doit être confirmé avant l'affectation.");
    if($a['reservation_status']!=='CONFIRMEE')throw new RuntimeException("La réservation doit être confirmée avant l'affectation.");
    if(!in_array($a['statut'],['ADMIS','EN_COURS'],true))throw new RuntimeException("Le stagiaire doit être admis avant son affectation.");
    if(empty($a['coordination_unit_id']))throw new RuntimeException("Envoyez d'abord le stagiaire vers une coordination.");
    if(!empty($a['campaign_start'])&&$dateStart<$a['campaign_start'])throw new RuntimeException("L'affectation ne peut pas commencer avant le début de la session.");
    if(!empty($a['campaign_end'])&&$dateEnd>$a['campaign_end'])throw new RuntimeException("L'affectation ne peut pas dépasser la fin de la session.");

    $requiresPayment=((int)$a['frais_stage_requis']===1&&(float)$a['frais_stage_montant']>0)||((float)$a['frais_stagia_montant']>0);

    if($requiresPayment){
        if((int)$a['frais_stage_requis']===1)
            requireFinancialObligationPaid($pdo,'STAGE_RESERVATION','STAGE_RESERVATION',(string)$a['reservation_id']);
        $s=$pdo->prepare("
            SELECT id,reference,montant,devise,statut,paid_at
            FROM stage_invoices
            WHERE reservation_id=? AND host_etablissement_id=?
            LIMIT 1 FOR UPDATE
        ");
        $s->execute([(int)$a['reservation_id'],$eid]);$invoice=$s->fetch(PDO::FETCH_ASSOC);

        if(!$invoice)throw new RuntimeException("Paiement requis : aucune facture n'a encore été générée pour ce stagiaire.");
        if($invoice['statut']!=='PAYEE')
            throw new RuntimeException("Affectation bloquée : la facture {$invoice['reference']} est encore « {$invoice['statut']} ». Validez le paiement avant l'affectation au service.");

        $s=$pdo->prepare("
            SELECT COUNT(*) FROM stage_payments
            WHERE invoice_id=? AND statut='VALIDE'
        ");
        $s->execute([(int)$invoice['id']]);
        if((int)$s->fetchColumn()<1)
            throw new RuntimeException("Affectation bloquée : aucun paiement validé n'est lié à la facture {$invoice['reference']}.");
    }

    $s=$pdo->prepare("
        SELECT id,nom,type,capacite,parent_id
        FROM host_units
        WHERE id=? AND host_etablissement_id=? AND actif=1
          AND UPPER(type)='SERVICE' AND parent_id=?
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$unitId,$eid,(int)$a['coordination_unit_id']]);$unit=$s->fetch(PDO::FETCH_ASSOC);

    if(!$unit)throw new RuntimeException('Service invalide pour cette coordination.');

    $s=$pdo->prepare("
        SELECT id FROM stage_assignments
        WHERE admission_id=? AND statut IN('PLANIFIEE','ACTIVE')
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$admissionId]);
    if($s->fetchColumn())throw new RuntimeException("Ce stagiaire possède déjà une affectation active ou planifiée.");

    if($unit['capacite']!==null&&(int)$unit['capacite']>0){
        $s=$pdo->prepare("
            SELECT COUNT(*) FROM stage_assignments
            WHERE host_etablissement_id=? AND host_unit_id=? AND statut IN('PLANIFIEE','ACTIVE')
              AND date_debut<=? AND COALESCE(date_fin,'9999-12-31')>=?
        ");
        $s->execute([$eid,$unitId,$dateEnd,$dateStart]);
        if((int)$s->fetchColumn()>=(int)$unit['capacite'])
            throw new RuntimeException("La capacité de « {$unit['nom']} » est atteinte pour cette période.");
    }

    $actor=(int)($_SESSION['user_id']??0)?:null;
    $assignmentUuid=assignmentUuid();

    $pdo->prepare("
        INSERT INTO stage_assignments(uuid,admission_id,host_unit_id,host_etablissement_id,statut,date_debut,date_fin,observation,assigned_by,assigned_at)
        VALUES(?,?,?,?,'ACTIVE',?,?,?,?,NOW())
    ")->execute([$assignmentUuid,$admissionId,$unitId,$eid,$dateStart,$dateEnd,$observation?:null,$actor]);

    $assignmentId=(int)$pdo->lastInsertId();
    $previous=$a['statut'];

    $pdo->prepare("UPDATE stage_admissions SET statut='EN_COURS' WHERE id=? AND host_etablissement_id=?")->execute([$admissionId,$eid]);

    $pdo->prepare("
        INSERT INTO stage_assignment_history(assignment_id,event_code,previous_status,new_status,details,actor_user_id,created_at)
        VALUES(?,'ASSIGNMENT_CREATED',NULL,'ACTIVE',?,?,NOW())
    ")->execute([$assignmentId,json_encode([
        'admission_id'=>$admissionId,
        'coordination_unit_id'=>(int)$a['coordination_unit_id'],
        'host_unit_id'=>$unitId,
        'host_unit_name'=>$unit['nom'],
        'payment_checked'=>$requiresPayment,
        'date_debut'=>$dateStart,
        'date_fin'=>$dateEnd,
        'source'=>'COORDINATION'
    ],JSON_UNESCAPED_UNICODE),$actor]);

    $pdo->prepare("
        INSERT INTO stage_admission_history(admission_id,event_code,previous_status,new_status,details,actor_user_id,created_at)
        VALUES(?,'FIRST_SERVICE_ASSIGNMENT',?,'EN_COURS',?,?,NOW())
    ")->execute([$admissionId,$previous,json_encode([
        'assignment_id'=>$assignmentId,
        'coordination_unit_id'=>(int)$a['coordination_unit_id'],
        'host_unit_id'=>$unitId,
        'payment_checked'=>$requiresPayment,
        'source'=>'COORDINATION'
    ],JSON_UNESCAPED_UNICODE),$actor]);

    $pdo->commit();

    stageNotifyStudentAssignment($pdo,$assignmentId,'stage.assignment.created');

    jsonResponse(true,"Stagiaire affecté au service « {$unit['nom']} ». Le stage passe en EN_COURS.",[
        'assignment_id'=>$assignmentId,
        'assignment_uuid'=>$assignmentUuid,
        'admission_id'=>$admissionId,
        'host_unit_id'=>$unitId
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}
