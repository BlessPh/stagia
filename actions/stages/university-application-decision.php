<?php
/**
 * Endpoint AJAX de décision académique sur une candidature : acceptation ou refus.
 * L'acceptation orchestre capacité, réservation et facture. Le placement
 * universitaire reste une étape distincte et crée ensuite l'admission.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

/** Génère un UUID v4 pour les objets créés au cours de la décision. */
function appUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}
/** Produit une référence lisible et unique pour une facture de stage. */
function invoiceRef():string{
    return 'FAC-STG-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));
}
try{
    /* La candidature est verrouillée avec ses objets associés avant toute décision. */
    $eid=currentEtablissementId($pdo);
    $id=(int)($_POST['application_id']??0);
    $decision=strtoupper(trim((string)($_POST['decision']??'')));
    $motif=trim((string)($_POST['motif_refus']??''));

    if(!$eid||!$id||!in_array($decision,['ACCEPTEE','REFUSEE'],true))
        jsonResponse(false,'Décision invalide.',[],422);
    if($decision==='REFUSEE'&&$motif==='')
        jsonResponse(false,'Le motif du refus est obligatoire.',[],422);

    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT
            a.id,a.statut,a.campaign_id,a.academic_enrollment_id,a.host_etablissement_id,a.participation_id,
            c.owner_etablissement_id,
            se.student_id,
            r.id reservation_id,r.statut reservation_statut,
            p.statut participation_statut,p.frais_requis,p.montant_frais,p.devise,
            COALESCE(NULLIF(p.capacite_acceptee,0),NULLIF(p.capacite_allouee,0)) capacite_retenue
        FROM stage_applications a
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN stage_reservations r ON r.application_id=a.id
        LEFT JOIN stage_campaign_participations p ON p.id=a.participation_id
        WHERE a.id=? AND c.owner_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id,$eid]);$a=$s->fetch(PDO::FETCH_ASSOC);

    if(!$a)throw new RuntimeException('Candidature introuvable.');
    if(!in_array($a['statut'],['SOUMISE','EN_ETUDE'],true))
        throw new RuntimeException('Cette candidature a déjà été traitée.');
    if(!$a['reservation_id'])throw new RuntimeException('Réservation liée introuvable.');

    $reservationId=(int)$a['reservation_id'];

    if($a['reservation_statut']==='RESERVEE_TEMPORAIREMENT'){
        $s=$pdo->prepare('SELECT expires_at FROM stage_reservations WHERE id=?');
        $s->execute([$reservationId]);
        $expiresAt=$s->fetchColumn();
        if($expiresAt!==false&&$expiresAt!==null&&strtotime((string)$expiresAt)<=time()){
            $pdo->prepare("UPDATE stage_reservations SET statut='EXPIREE' WHERE id=?")->execute([$reservationId]);
            $pdo->commit();
            jsonResponse(false,'La réservation temporaire a expiré. La candidature doit être soumise à nouveau.',[],409);
        }
    }
    if(in_array($a['reservation_statut'],['EXPIREE','ANNULEE'],true))
        throw new RuntimeException("La réservation liée n'est plus active.");

    /* Le refus libère la place, annule la réservation et invalide les factures non réglées. */
    if($decision==='REFUSEE'){
        $pdo->prepare("UPDATE stage_applications SET statut='REFUSEE',motif_refus=?,responded_at=NOW() WHERE id=?")
            ->execute([$motif,$id]);
        $pdo->prepare("UPDATE stage_reservations SET statut='ANNULEE',expires_at=NULL,cancelled_at=NOW() WHERE id=?")
            ->execute([$reservationId]);
        $pdo->prepare("UPDATE stage_invoices SET statut='ANNULEE' WHERE reservation_id=? AND statut IN('EMISE','PARTIELLEMENT_PAYEE','EXPIREE')")
            ->execute([$reservationId]);
        $pdo->commit();
        jsonResponse(true,'Candidature refusée. La place a été libérée.');
    }

    if(!$a['participation_id']||$a['participation_statut']!=='ACCEPTEE')
        throw new RuntimeException("L'hôpital n'est plus retenu pour cette campagne.");

    $capacite=(int)$a['capacite_retenue'];
    if($capacite<=0)throw new RuntimeException('Aucune capacité retenue pour cet hôpital.');

    /* Verrou capacité : les anciennes réservations temporaires expirées ne comptent plus. */
    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM stage_reservations
        WHERE participation_id=? AND id<>?
          AND (
            statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
            OR (statut='RESERVEE_TEMPORAIREMENT' AND (expires_at IS NULL OR expires_at>NOW()))
          )
    ");
    $s->execute([(int)$a['participation_id'],$reservationId]);
    if((int)$s->fetchColumn()>=$capacite)
        throw new RuntimeException("Capacité atteinte : aucune place ne peut être confirmée.");

    $pdo->prepare("UPDATE stage_applications SET statut='ACCEPTEE',motif_refus=NULL,responded_at=NOW() WHERE id=?")
        ->execute([$id]);

    $frais=(int)$a['frais_requis']===1;
    $montant=(float)($a['montant_frais']??0);
    $devise=trim((string)($a['devise']??'USD'))?:'USD';

    /* Un stage payant génère ou réactualise une facture avant l'admission en attente. */
    if($frais){
        if($montant<=0)throw new RuntimeException('Le montant du stage payant est invalide.');

        $s=$pdo->prepare("SELECT id,statut FROM stage_invoices WHERE reservation_id=? LIMIT 1");
        $s->execute([$reservationId]);$invoice=$s->fetch(PDO::FETCH_ASSOC);

        /* Si une facture était déjà payée, on confirme immédiatement. */
        /* Un paiement déjà validé confirme la réservation, sans créer le placement. */
        if($invoice&&$invoice['statut']==='PAYEE'){
            $pdo->prepare("UPDATE stage_reservations SET statut='CONFIRMEE',expires_at=NULL,confirmed_at=COALESCE(confirmed_at,NOW()),cancelled_at=NULL WHERE id=?")
                ->execute([$reservationId]);
            $pdo->commit();
            jsonResponse(true,'Candidature acceptée. Paiement déjà validé : la réservation est prête pour le placement universitaire.');
        }

        if($invoice){
            $pdo->prepare("
                UPDATE stage_invoices
                SET participation_id=?,student_id=?,host_etablissement_id=?,montant=?,devise=?,
                    statut='EMISE',date_emission=NOW(),date_echeance=NULL,paid_at=NULL
                WHERE id=?
            ")->execute([(int)$a['participation_id'],(int)$a['student_id'],(int)$a['host_etablissement_id'],$montant,$devise,(int)$invoice['id']]);
        }else{
            $pdo->prepare("
                INSERT INTO stage_invoices(
                    uuid,reservation_id,application_id,participation_id,student_id,host_etablissement_id,
                    reference,montant,devise,statut,date_emission
                ) VALUES(?,?,?,?,?,?,?,?,?,'EMISE',NOW())
            ")->execute([
                appUuid(),$reservationId,$id,(int)$a['participation_id'],(int)$a['student_id'],
                (int)$a['host_etablissement_id'],invoiceRef(),$montant,$devise
            ]);
        }

        $pdo->prepare("UPDATE stage_reservations SET statut='EN_ATTENTE_PAIEMENT',expires_at=NULL,confirmed_at=NULL,cancelled_at=NULL WHERE id=?")
            ->execute([$reservationId]);

        $pdo->commit();
        jsonResponse(true,"Candidature acceptée. Paiement de ".number_format($montant,2,',',' ')." {$devise} attendu avant le placement universitaire.");
    }

    /* Un stage sans frais passe à la confirmation, puis attend le placement universitaire. */
    $pdo->prepare("UPDATE stage_reservations SET statut='CONFIRMEE',expires_at=NULL,confirmed_at=NOW(),cancelled_at=NULL WHERE id=?")
        ->execute([$reservationId]);
    $pdo->prepare("UPDATE stage_invoices SET statut='ANNULEE' WHERE reservation_id=? AND statut<>'PAYEE'")
        ->execute([$reservationId]);
    $pdo->commit();
    jsonResponse(true,"Candidature acceptée. La réservation est confirmée et prête pour le placement universitaire.");
}catch(Throwable $e){
    /* Toute erreur annule les changements liés pour ne pas dissocier candidature, facture et admission. */
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[UNIVERSITY APPLICATION DECISION] '.$e->getMessage());
    jsonResponse(false,$e->getMessage(),[],422);
}
