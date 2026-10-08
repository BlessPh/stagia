<?php
declare(strict_types=1);

require_once __DIR__.'/maishapay-client.php';
require_once dirname(__DIR__).'/communication-native.php';

function financialUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}
function financialReference(string $prefix):string{return strtoupper($prefix).'-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));}

function financialNotificationAlreadySent(PDO $pdo,int $userId,string $eventKey):bool{
    $stmt=$pdo->prepare("SELECT 1 FROM notifications WHERE utilisateur_id=? AND JSON_UNQUOTE(JSON_EXTRACT(donnees,'$.event_key'))=? LIMIT 1");
    $stmt->execute([$userId,$eventKey]);
    return (bool)$stmt->fetchColumn();
}

/** Notifie le payeur sans faire échouer l'opération financière si la messagerie est indisponible. */
function notifyFinancialObligationCreated(PDO $pdo,array $obligation):void{
    try{
        $userId=(int)($obligation['user_id']??0);
        if($userId<1)return;
        $eventKey='financial.obligation.created:'.(string)$obligation['uuid'];
        if(financialNotificationAlreadySent($pdo,$userId,$eventKey))return;
        $amount=number_format((float)$obligation['amount'],2,',',' ');
        $currency=strtoupper((string)$obligation['currency']);
        $dueAt=trim((string)($obligation['due_at']??''));
        $content="Une obligation financière « {$obligation['label']} » d’un montant de {$amount} {$currency} a été créée. Veuillez effectuer le paiement pour poursuivre.";
        if($dueAt!=='')$content.=' Échéance : '.$dueAt.'.';
        $organizationId=(int)($obligation['organization_id']??0);
        communicationNotifier(
            $pdo,$userId,$organizationId>0?$organizationId:null,
            'financial.obligation.created','Paiement requis',$content,
            '/views/paiements/index.php?obligation='.rawurlencode((string)$obligation['uuid']),
            [
                'event_key'=>$eventKey,
                'obligation_uuid'=>$obligation['uuid'],
                'obligation_reference'=>$obligation['reference'],
                'obligation_type'=>$obligation['obligation_type'],
                'amount'=>(float)$obligation['amount'],'currency'=>$currency,'due_at'=>$obligation['due_at']??null,
                'action'=>[
                    'type'=>'payment','target_id'=>$obligation['uuid'],'label'=>'Payer maintenant',
                    'title'=>$obligation['label'],'metadata'=>['status'=>$obligation['status']]
                ]
            ]
        );
    }catch(Throwable $error){
        error_log('[FINANCIAL OBLIGATION NOTIFICATION] '.($obligation['uuid']??'?').' | '.$error->getMessage());
    }
}

function notifyFinancialPaymentResult(PDO $pdo,int $obligationId,string $paymentStatus,int $paymentId=0):void{
    try{
        $stmt=$pdo->prepare('SELECT * FROM financial_obligations WHERE id=? LIMIT 1');
        $stmt->execute([$obligationId]);
        $obligation=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$obligation)return;
        $event=$paymentStatus==='SUCCEEDED'?'financial.payment.succeeded':'financial.payment.failed';
        $eventKey=$event.':'.$obligation['uuid'].':'.$paymentId;
        if(financialNotificationAlreadySent($pdo,(int)$obligation['user_id'],$eventKey))return;
        $success=$paymentStatus==='SUCCEEDED';
        $organizationId=(int)($obligation['organization_id']??0);
        communicationNotifier(
            $pdo,(int)$obligation['user_id'],$organizationId>0?$organizationId:null,
            $event,$success?'Paiement confirmé':'Paiement non abouti',
            $success
                ?"Votre paiement pour « {$obligation['label']} » a été confirmé."
                :"Le paiement pour « {$obligation['label']} » n’a pas abouti. Vous pouvez effectuer une nouvelle tentative.",
            '/views/paiements/index.php?obligation='.rawurlencode((string)$obligation['uuid']),
            ['event_key'=>$eventKey,'obligation_uuid'=>$obligation['uuid'],'obligation_reference'=>$obligation['reference'],
                'payment_status'=>$paymentStatus,'obligation_status'=>$obligation['status'],
                'action'=>['type'=>'payment','target_id'=>$obligation['uuid'],'label'=>'Voir le paiement','title'=>$obligation['label'],'metadata'=>[]]]
        );
    }catch(Throwable $error){
        error_log('[FINANCIAL PAYMENT NOTIFICATION] obligation='.$obligationId.' | '.$error->getMessage());
    }
}

/** Cree ou retourne l'obligation unique liee a une operation metier. */
function ensureFinancialObligation(PDO $pdo,array $data):array{
    foreach(['user_id','obligation_type','subject_type','subject_key','label','amount','currency'] as $key){
        if(!isset($data[$key])||$data[$key]==='')throw new InvalidArgumentException('Obligation financiere incomplete : '.$key.'.');
    }
    $amount=round((float)$data['amount'],2);
    if($amount<=0)throw new InvalidArgumentException("Le montant de l'obligation doit etre positif.");
    $find=$pdo->prepare('SELECT * FROM financial_obligations WHERE obligation_type=? AND subject_type=? AND subject_key=? LIMIT 1');
    $find->execute([$data['obligation_type'],$data['subject_type'],(string)$data['subject_key']]);
    if($row=$find->fetch(PDO::FETCH_ASSOC)){
        if((float)$row['amount']!==$amount||strtoupper($row['currency'])!==strtoupper((string)$data['currency'])){
            if($row['status']!=='PENDING')throw new DomainException("L'obligation existante ne peut plus etre modifiee.");
            $pdo->prepare('UPDATE financial_obligations SET amount=?,currency=?,label=?,due_at=?,metadata=? WHERE id=?')->execute([$amount,strtoupper((string)$data['currency']),$data['label'],$data['due_at']??null,json_encode($data['metadata']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$row['id']]);
            $find->execute([$data['obligation_type'],$data['subject_type'],(string)$data['subject_key']]);$row=$find->fetch(PDO::FETCH_ASSOC);
        }
        return $row;
    }
    $uuid=financialUuid();$reference=financialReference('OBL');
    $pdo->prepare('INSERT INTO financial_obligations(uuid,reference,user_id,organization_id,obligation_type,subject_type,subject_key,label,amount,currency,status,due_at,metadata) VALUES(?,?,?,?,?,?,?,?,?,?,\'PENDING\',?,?)')->execute([$uuid,$reference,(int)$data['user_id'],$data['organization_id']??null,$data['obligation_type'],$data['subject_type'],(string)$data['subject_key'],$data['label'],$amount,strtoupper((string)$data['currency']),$data['due_at']??null,json_encode($data['metadata']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $find=$pdo->prepare('SELECT * FROM financial_obligations WHERE id=?');$find->execute([(int)$pdo->lastInsertId()]);
    $created=$find->fetch(PDO::FETCH_ASSOC);
    if(($data['notify']??true)!==false)notifyFinancialObligationCreated($pdo,$created);
    return $created;
}

function financialObligationPaid(PDO $pdo,int $obligationId):float{
    $s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM financial_payments WHERE obligation_id=? AND status='SUCCEEDED'");$s->execute([$obligationId]);
    return round((float)$s->fetchColumn(),2);
}

function requireFinancialObligationPaid(PDO $pdo,string $type,string $subjectType,string $subjectKey):array{
    $s=$pdo->prepare('SELECT * FROM financial_obligations WHERE obligation_type=? AND subject_type=? AND subject_key=? LIMIT 1');$s->execute([$type,$subjectType,$subjectKey]);$obligation=$s->fetch(PDO::FETCH_ASSOC);
    if(!$obligation||$obligation['status']!=='PAID')throw new DomainException("Cette action reste bloquee jusqu'au paiement de l'obligation financiere.");
    return $obligation;
}

/** Initiation reutilisable par le Web et l'API mobile. */
function initiateFinancialPayment(PDO $pdo,int $obligationId,int $userId,array $input,array $customer):array{
    $channel=strtoupper(trim((string)($input['channel']??$input['provider']??'')));
    $phone=maishapayPhone((string)($input['phone_number']??$input['wallet_phone']??''));
    maishapayProvider($channel);
    $idempotency=trim((string)($input['idempotency_key']??''));
    if($idempotency===''||strlen($idempotency)>100)throw new InvalidArgumentException("Une cle d'idempotence de 1 a 100 caracteres est obligatoire.");
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM financial_obligations WHERE id=? AND user_id=? LIMIT 1 FOR UPDATE');$s->execute([$obligationId,$userId]);$obligation=$s->fetch(PDO::FETCH_ASSOC);
        if(!$obligation)throw new OutOfBoundsException('Obligation financiere introuvable.');
        if($obligation['status']==='PAID'){$pdo->commit();return ['created'=>false,'already_paid'=>true,'obligation'=>$obligation];}
        if($obligation['status']!=='PENDING'&&$obligation['status']!=='PARTIALLY_PAID')throw new DomainException("Cette obligation n'est plus payable.");
        $s=$pdo->prepare('SELECT * FROM financial_payments WHERE obligation_id=? AND idempotency_key=? LIMIT 1');$s->execute([$obligationId,$idempotency]);
        if($existing=$s->fetch(PDO::FETCH_ASSOC)){
            $pdo->commit();
            if(in_array($existing['status'],['FAILED','CANCELLED'],true))throw new DomainException("Cette tentative a échoué. Relancez le paiement avec une nouvelle clé d'idempotence.");
            return ['created'=>false,'payment'=>$existing,'obligation'=>$obligation];
        }
        $remaining=max(0,round((float)$obligation['amount']-financialObligationPaid($pdo,$obligationId),2));
        if($remaining<=0)throw new DomainException('Cette obligation est deja reglee.');
        $uuid=financialUuid();$reference=financialReference('PAY');
        $pdo->prepare("INSERT INTO financial_payments(uuid,obligation_id,merchant_reference,provider,channel,wallet_phone,amount,currency,status,idempotency_key,initiated_at) VALUES(?,?,?,'MAISHAPAY',?,?,?,?, 'INITIATED',?,NOW())")->execute([$uuid,$obligationId,$reference,$channel,$phone,$remaining,$obligation['currency'],$idempotency]);
        $paymentId=(int)$pdo->lastInsertId();$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    try{
        $response=maishapayCollect(['merchant_reference'=>$reference,'amount'=>$remaining,'currency'=>$obligation['currency'],'channel'=>$channel,'wallet_phone'=>$phone],$customer);
        $status=$response['accepted']?'PENDING':'FAILED';
        $pdo->prepare('UPDATE financial_payments SET status=?,provider_transaction_id=NULLIF(?,\'\'),request_payload=?,provider_response=?,failed_at=IF(?=\'FAILED\',NOW(),NULL) WHERE id=?')->execute([$status,$response['transaction_id'],json_encode($response['request'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($response['response'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$status,$paymentId]);
        if(!$response['accepted'])throw new RuntimeException('MaishaPay a refuse la demande de paiement.');
    }catch(Throwable $e){
        $pdo->prepare("UPDATE financial_payments SET status='FAILED',failed_at=NOW(),provider_response=? WHERE id=? AND status='INITIATED'")->execute([json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$paymentId]);
        throw $e;
    }
    $s=$pdo->prepare('SELECT * FROM financial_payments WHERE id=?');$s->execute([$paymentId]);
    return ['created'=>true,'payment'=>$s->fetch(PDO::FETCH_ASSOC),'obligation'=>$obligation];
}

/** Finalise le paiement et recalcule l'obligation de maniere idempotente. */
function finalizeFinancialPayment(PDO $pdo,string $merchantReference,string $result,?string $providerTransactionId,array $payload=[]):array{
    $result=strtoupper(trim($result));$status=match($result){'200','SUCCESS','SUCCESSFUL','SUCCEEDED','COMPLETED','APPROVED','VALIDE'=>'SUCCEEDED','FAILED','FAILURE','REJECTED','ECHOUE'=>'FAILED','CANCELLED','CANCELED','ANNULE'=>'CANCELLED',default=>throw new InvalidArgumentException('Statut MaishaPay invalide.')};
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT p.*,o.amount obligation_amount,o.status obligation_status FROM financial_payments p JOIN financial_obligations o ON o.id=p.obligation_id WHERE p.merchant_reference=? LIMIT 1 FOR UPDATE');$s->execute([$merchantReference]);$payment=$s->fetch(PDO::FETCH_ASSOC);
        if(!$payment)throw new OutOfBoundsException('Paiement introuvable.');
        if(in_array($payment['status'],['SUCCEEDED','FAILED','CANCELLED','REFUNDED'],true)){
            if($payment['status']!==$status)throw new DomainException('Ce paiement a deja ete finalise avec un autre statut.');
        }else{
            $pdo->prepare('UPDATE financial_payments SET status=?,provider_transaction_id=COALESCE(NULLIF(?,\'\'),provider_transaction_id),callback_payload=?,paid_at=IF(?=\'SUCCEEDED\',NOW(),paid_at),failed_at=IF(?=\'FAILED\',NOW(),failed_at) WHERE id=?')->execute([$status,$providerTransactionId,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$status,$status,(int)$payment['id']]);
        }
        $paid=financialObligationPaid($pdo,(int)$payment['obligation_id']);$required=(float)$payment['obligation_amount'];
        $obligationStatus=$paid>=$required?'PAID':($paid>0?'PARTIALLY_PAID':'PENDING');
        $pdo->prepare('UPDATE financial_obligations SET status=?,paid_at=IF(?=\'PAID\',COALESCE(paid_at,NOW()),NULL) WHERE id=? AND status NOT IN(\'CANCELLED\',\'EXPIRED\')')->execute([$obligationStatus,$obligationStatus,(int)$payment['obligation_id']]);
        $pdo->commit();
        notifyFinancialPaymentResult($pdo,(int)$payment['obligation_id'],$status,(int)$payment['id']);
        return ['payment_id'=>(int)$payment['id'],'payment_status'=>$status,'obligation_id'=>(int)$payment['obligation_id'],'obligation_status'=>$obligationStatus,'amount_paid'=>$paid,'amount_remaining'=>max(0,round($required-$paid,2))];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function financialPublicPayment(array $row):array{
    return [
        'uuid'=>$row['uuid'],'reference'=>$row['merchant_reference'],'provider'=>$row['provider'],
        'provider_transaction_id'=>$row['provider_transaction_id'],'channel'=>$row['channel'],
        'phone_number'=>$row['wallet_phone'],'amount'=>(float)$row['amount'],'currency'=>$row['currency'],
        'status'=>$row['status'],'initiated_at'=>$row['initiated_at'],'paid_at'=>$row['paid_at'],
        'failed_at'=>$row['failed_at'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']
    ];
}

function financialPublicObligation(PDO $pdo,array $row,bool $withPayments=true):array{
    $paid=financialObligationPaid($pdo,(int)$row['id']);
    $amount=(float)$row['amount'];
    $payments=[];$pending=false;$latestStatus=null;
    if($withPayments){
        $stmt=$pdo->prepare('SELECT * FROM financial_payments WHERE obligation_id=? ORDER BY id DESC');
        $stmt->execute([(int)$row['id']]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment){
            $payments[]=financialPublicPayment($payment);
            $latestStatus??=$payment['status'];
            if(in_array($payment['status'],['INITIATED','PENDING'],true))$pending=true;
        }
    }
    $metadata=json_decode((string)($row['metadata']??''),true);
    if(!is_array($metadata))$metadata=[];
    return [
        'uuid'=>$row['uuid'],'reference'=>$row['reference'],'type'=>$row['obligation_type'],
        'subject'=>['type'=>$row['subject_type'],'key'=>$row['subject_key']],
        'label'=>$row['label'],'amount'=>$amount,'currency'=>$row['currency'],'status'=>$row['status'],
        'amount_paid'=>$paid,'amount_remaining'=>max(0,round($amount-$paid,2)),
        'payment_status'=>$row['status']==='PAID'?'SUCCEEDED':($pending?'PENDING':($latestStatus??'NOT_STARTED')),
        'payable'=>in_array($row['status'],['PENDING','PARTIALLY_PAID'],true)&&$paid<$amount&&!$pending,
        'payment_pending'=>$pending,'due_at'=>$row['due_at'],'paid_at'=>$row['paid_at'],
        'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at'],'metadata'=>$metadata,
        'payments'=>$payments
    ];
}

/** Collection générique utilisée par la page Web et l'API mobile. */
function listUserFinancialObligations(PDO $pdo,int $userId,array $filters=[]):array{
    $where=['user_id=?'];$params=[$userId];
    $statuses=array_values(array_intersect(
        array_filter(array_map(static fn($v)=>strtoupper(trim((string)$v)),(array)($filters['statuses']??[]))),
        ['PENDING','PARTIALLY_PAID','PAID','CANCELLED','EXPIRED']
    ));
    if($statuses){$where[]='status IN('.implode(',',array_fill(0,count($statuses),'?')).')';array_push($params,...$statuses);}
    $type=strtoupper(trim((string)($filters['type']??'')));
    if($type!==''){$where[]='obligation_type=?';$params[]=$type;}
    $stmt=$pdo->prepare('SELECT * FROM financial_obligations WHERE '.implode(' AND ',$where).' ORDER BY created_at DESC,id DESC');
    $stmt->execute($params);
    $items=[];$stats=['total'=>0,'payable'=>0,'pending'=>0,'paid'=>0,'failed'=>0,'cancelled'=>0,'expired'=>0];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $item=financialPublicObligation($pdo,$row,true);$items[]=$item;$stats['total']++;
        if($item['payable'])$stats['payable']++;
        if($item['payment_status']==='PENDING')$stats['pending']++;
        if($item['status']==='PAID')$stats['paid']++;
        if($item['payment_status']==='FAILED')$stats['failed']++;
        if($item['status']==='CANCELLED')$stats['cancelled']++;
        if($item['status']==='EXPIRED')$stats['expired']++;
    }
    return ['items'=>$items,'stats'=>$stats,'filters'=>['statuses'=>$statuses,'type'=>$type?:null]];
}

function findUserFinancialObligation(PDO $pdo,int $userId,string $uuid,bool $forUpdate=false):array{
    if($uuid==='')throw new InvalidArgumentException("L'obligation financière est obligatoire.");
    $stmt=$pdo->prepare('SELECT * FROM financial_obligations WHERE uuid=? AND user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$uuid,$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new OutOfBoundsException('Obligation financière introuvable.');
    return $row;
}

/** Relit l'état enregistré par le webhook; cette fonction ne déclare jamais seule un paiement réussi. */
function synchronizeFinancialObligation(PDO $pdo,int $userId,string $uuid):array{
    $obligation=findUserFinancialObligation($pdo,$userId,$uuid);
    $paid=financialObligationPaid($pdo,(int)$obligation['id']);
    $required=(float)$obligation['amount'];
    if(!in_array($obligation['status'],['CANCELLED','EXPIRED'],true)){
        $status=$paid>=$required?'PAID':($paid>0?'PARTIALLY_PAID':'PENDING');
        $pdo->prepare("UPDATE financial_obligations SET status=?,paid_at=IF(?='PAID',COALESCE(paid_at,NOW()),NULL) WHERE id=?")
            ->execute([$status,$status,(int)$obligation['id']]);
    }
    $stmt=$pdo->prepare('SELECT * FROM financial_obligations WHERE id=?');$stmt->execute([(int)$obligation['id']]);
    return financialPublicObligation($pdo,$stmt->fetch(PDO::FETCH_ASSOC),true);
}
