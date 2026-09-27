<?php

function studentPaymentChannels():array{
    return [
        'MPESA'=>'M-Pesa',
        'ORANGE_MONEY'=>'Orange Money',
        'AIRTEL_MONEY'=>'Airtel Money',
        'AFRIMONEY'=>'Afrimoney',
        'BANQUE'=>'Banque',
        'CARTE'=>'Carte bancaire'
    ];
}

function studentPaymentMobileChannels():array{
    return ['MPESA','ORANGE_MONEY','AIRTEL_MONEY','AFRIMONEY'];
}

function studentPaymentUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

function studentPaymentReference():string{
    return 'PAY-STG-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));
}

function studentPaymentHasColumn(PDO $pdo,string $table,string $column):bool{
    static $cache=[];$key=$table.'.'.$column;
    if(array_key_exists($key,$cache))return $cache[$key];
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table,$column]);
    return $cache[$key]=(int)$stmt->fetchColumn()>0;
}

function studentPaymentPublicRow(array $row,?bool $created=null):array{
    $data=[
        'uuid'=>$row['uuid']??null,
        'reference'=>$row['reference']??null,
        'transaction_reference'=>$row['transaction_reference']??null,
        'amount'=>isset($row['montant'])?(float)$row['montant']:null,
        'currency'=>$row['devise']??null,
        'channel'=>$row['canal']??null,
        'operator'=>$row['operateur']??null,
        'status'=>$row['statut']??null,
        'phone_number'=>$row['phone_number']??null,
        'initiated_at'=>$row['initiated_at']??null,
        'paid_at'=>$row['paid_at']??null,
        'validated_at'=>$row['validated_at']??null
    ];
    if($created!==null){
        $data=['created'=>$created,'idempotent'=>!$created]+$data;
    }
    return $data;
}

/** Initiation commune au Web et au mobile, réutilisant strictement les canaux existants. */
function initiateStudentStagePayment(PDO $pdo,int $studentId,int $userId,array $input,string $source):array{
    $invoiceId=(int)($input['invoice_id']??0);
    $invoiceUuid=trim((string)($input['invoice_uuid']??''));
    $reservationUuid=trim((string)($input['reservation_uuid']??''));
    $channel=strtoupper(trim((string)($input['channel']??$input['canal']??'')));
    $phone=trim((string)($input['phone_number']??''));
    $idempotencyKey=trim((string)($input['idempotency_key']??''));
    if(!$invoiceId&&$invoiceUuid===''&&$reservationUuid==='')throw new InvalidArgumentException('La facture ou la réservation est obligatoire.');
    $channels=studentPaymentChannels();
    if(!isset($channels[$channel]))throw new InvalidArgumentException('Canal de paiement invalide.');
    if(in_array($channel,studentPaymentMobileChannels(),true)&&$phone==='')throw new InvalidArgumentException('Le numéro de téléphone est obligatoire pour ce canal.');
    if(strlen($idempotencyKey)>100)throw new InvalidArgumentException("La clé d'idempotence ne peut pas dépasser 100 caractères.");

    $pdo->beginTransaction();
    try{
        $conditions=[];$params=[];
        if($invoiceId){$conditions[]='i.id=?';$params[]=$invoiceId;}
        elseif($invoiceUuid!==''){$conditions[]='i.uuid=?';$params[]=$invoiceUuid;}
        else{$conditions[]='r.uuid=?';$params[]=$reservationUuid;}
        $params[]=$studentId;
        $stmt=$pdo->prepare("
            SELECT i.*,r.uuid reservation_uuid,r.statut reservation_status,
                   a.statut application_status,p.frais_requis
            FROM stage_invoices i
            JOIN stage_reservations r ON r.id=i.reservation_id
            JOIN stage_applications a ON a.id=r.application_id AND a.id=i.application_id
            JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
            JOIN student_enrollments se ON se.id=ae.enrollment_id
            JOIN stage_campaign_participations p ON p.id=a.participation_id
            WHERE ".implode(' AND ',$conditions)." AND se.student_id=?
            LIMIT 1 FOR UPDATE
        ");
        $stmt->execute($params);$invoice=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$invoice)throw new OutOfBoundsException('Facture introuvable.');
        if($invoice['application_status']!=='ACCEPTEE')throw new DomainException("La candidature n'est pas encore acceptée.");
        if($invoice['reservation_status']!=='EN_ATTENTE_PAIEMENT'){
            if($invoice['reservation_status']==='CONFIRMEE'&&$invoice['statut']==='PAYEE'){
                $pdo->commit();
                return ['created'=>false,'already_paid'=>true,'reservation_uuid'=>$invoice['reservation_uuid'],'reservation_status'=>'CONFIRMEE','invoice_status'=>'PAYEE'];
            }
            throw new DomainException("Cette réservation n'est pas en attente de paiement.");
        }
        if((int)$invoice['frais_requis']!==1)throw new DomainException('Aucun paiement n’est requis pour ce stage.');
        if(!in_array($invoice['statut'],['EMISE','PARTIELLEMENT_PAYEE'],true))throw new DomainException('Cette facture ne peut plus recevoir de paiement.');

        $hasKey=studentPaymentHasColumn($pdo,'stage_payments','idempotency_key');
        if($hasKey&&$idempotencyKey!==''){
            $stmt=$pdo->prepare('SELECT * FROM stage_payments WHERE invoice_id=? AND idempotency_key=? LIMIT 1');
            $stmt->execute([(int)$invoice['id'],$idempotencyKey]);
            if($existing=$stmt->fetch(PDO::FETCH_ASSOC)){
                $pdo->commit();
                return ['payment'=>studentPaymentPublicRow($existing,false),'reservation_uuid'=>$invoice['reservation_uuid']];
            }
        }

        $stmt=$pdo->prepare("SELECT * FROM stage_payments WHERE invoice_id=? AND statut IN('INITIE','EN_ATTENTE') ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int)$invoice['id']]);
        if($pending=$stmt->fetch(PDO::FETCH_ASSOC)){
            $pdo->commit();
            return ['payment'=>studentPaymentPublicRow($pending,false),'reservation_uuid'=>$invoice['reservation_uuid']];
        }

        $stmt=$pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM stage_payments WHERE invoice_id=? AND statut='VALIDE' AND devise=?");
        $stmt->execute([(int)$invoice['id'],$invoice['devise']]);
        $paid=(float)$stmt->fetchColumn();
        $remaining=max(0,round((float)$invoice['montant']-$paid,2));
        if($remaining<=0)throw new DomainException('Cette facture est déjà réglée. Synchronisez son état.');

        $uuid=studentPaymentUuid();$reference=studentPaymentReference();
        $fields=['uuid','invoice_id','reference','montant','devise','canal','operateur','statut','phone_number','initiated_at','metadata'];
        $values=['?','?','?','?','?','?','?',"'EN_ATTENTE'",'?','NOW()','?'];
        $params=[$uuid,(int)$invoice['id'],$reference,$remaining,$invoice['devise'],$channel,$channels[$channel],$phone?:null,json_encode([
            'source'=>$source,'user_id'=>$userId,'invoice_reference'=>$invoice['reference']
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        if($hasKey){array_splice($fields,2,0,['idempotency_key']);array_splice($values,2,0,['?']);array_splice($params,2,0,[$idempotencyKey!==''?$idempotencyKey:null]);}
        $pdo->prepare('INSERT INTO stage_payments('.implode(',',$fields).') VALUES('.implode(',',$values).')')->execute($params);
        $paymentId=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('SELECT * FROM stage_payments WHERE id=?');$stmt->execute([$paymentId]);
        $payment=$stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        return ['payment'=>studentPaymentPublicRow($payment,true),'reservation_uuid'=>$invoice['reservation_uuid']];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException&&$idempotencyKey!==''&&studentPaymentHasColumn($pdo,'stage_payments','idempotency_key')){
            $stmt=$pdo->prepare('SELECT pay.* FROM stage_payments pay JOIN stage_invoices i ON i.id=pay.invoice_id WHERE pay.idempotency_key=? AND i.student_id=? ORDER BY pay.id DESC LIMIT 1');
            $stmt->execute([$idempotencyKey,$studentId]);
            if($existing=$stmt->fetch(PDO::FETCH_ASSOC))return ['payment'=>studentPaymentPublicRow($existing,false)];
        }
        throw $e;
    }
}

/** Synchronisation purement déterministe : aucune nouvelle transaction de paiement n'est créée. */
function synchronizeStudentStagePayment(PDO $pdo,int $studentId,string $reservationUuid):array{
    if($reservationUuid==='')throw new InvalidArgumentException('La réservation est obligatoire.');
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("
            SELECT r.id,r.uuid,r.statut reservation_status,r.expires_at,
                   a.statut application_status,p.frais_requis,p.montant_frais,p.devise,
                   i.id invoice_id,i.statut invoice_status,i.montant invoice_amount
            FROM stage_reservations r
            JOIN stage_applications a ON a.id=r.application_id
            JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
            JOIN student_enrollments se ON se.id=ae.enrollment_id
            JOIN stage_campaign_participations p ON p.id=r.participation_id
            LEFT JOIN stage_invoices i ON i.reservation_id=r.id
            WHERE r.uuid=? AND se.student_id=? LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$reservationUuid,$studentId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Réservation introuvable.');
        if($row['application_status']!=='ACCEPTEE')throw new DomainException('La décision universitaire doit être favorable avant tout paiement.');

        if(!(bool)$row['frais_requis']){
            $pdo->prepare("UPDATE stage_reservations SET statut='CONFIRMEE',confirmed_at=COALESCE(confirmed_at,NOW()),expires_at=NULL,cancelled_at=NULL WHERE id=?")
                ->execute([(int)$row['id']]);
            $pdo->commit();
            return ['reservation_uuid'=>$reservationUuid,'reservation_status'=>'CONFIRMEE','payment_status'=>'NOT_REQUIRED','placement_pending'=>true];
        }
        if(!$row['invoice_id'])throw new DomainException('Aucune facture n’est associée à cette réservation.');

        $stmt=$pdo->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN statut='VALIDE' AND devise=? THEN montant ELSE 0 END),0) paid,
              COALESCE(SUM(statut IN('INITIE','EN_ATTENTE')),0) pending_count,
              COALESCE(SUM(statut='ECHOUE'),0) failed_count
            FROM stage_payments WHERE invoice_id=?
        ");
        $stmt->execute([$row['devise'],(int)$row['invoice_id']]);$totals=$stmt->fetch(PDO::FETCH_ASSOC);
        $paid=round((float)$totals['paid'],2);$required=(float)($row['invoice_amount']??$row['montant_frais']);

        if($required>0&&$paid>=$required){
            $pdo->prepare("UPDATE stage_invoices SET statut='PAYEE',paid_at=COALESCE(paid_at,NOW()) WHERE id=?")->execute([(int)$row['invoice_id']]);
            $pdo->prepare("UPDATE stage_reservations SET statut='CONFIRMEE',confirmed_at=COALESCE(confirmed_at,NOW()),expires_at=NULL,cancelled_at=NULL WHERE id=?")
                ->execute([(int)$row['id']]);
            $pdo->commit();
            return ['reservation_uuid'=>$reservationUuid,'reservation_status'=>'CONFIRMEE','payment_status'=>'PAID','invoice_status'=>'PAYEE','amount_required'=>$required,'amount_validated'=>$paid,'amount_remaining'=>0.0,'currency'=>$row['devise'],'placement_pending'=>true];
        }

        $invoiceStatus=$paid>0?'PARTIELLEMENT_PAYEE':'EMISE';
        if($row['invoice_status']!=='ANNULEE')$pdo->prepare('UPDATE stage_invoices SET statut=? WHERE id=?')->execute([$invoiceStatus,(int)$row['invoice_id']]);
        if($row['reservation_status']==='RESERVEE_TEMPORAIREMENT'&&!empty($row['expires_at'])&&strtotime((string)$row['expires_at'])<=time()){
            $pdo->prepare("UPDATE stage_reservations SET statut='EXPIREE' WHERE id=?")->execute([(int)$row['id']]);
            $pdo->commit();
            return ['reservation_uuid'=>$reservationUuid,'reservation_status'=>'EXPIREE','payment_status'=>'NOT_CONFIRMED'];
        }
        $status=$paid>0?'PARTIAL':((int)$totals['pending_count']>0?'PENDING':((int)$totals['failed_count']>0?'FAILED':'NOT_STARTED'));
        $pdo->commit();
        return ['reservation_uuid'=>$reservationUuid,'reservation_status'=>$row['reservation_status'],'payment_status'=>$status,'invoice_status'=>$invoiceStatus,'amount_required'=>$required,'amount_validated'=>$paid,'amount_remaining'=>max(0,round($required-$paid,2)),'currency'=>$row['devise']];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
