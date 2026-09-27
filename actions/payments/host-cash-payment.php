<?php
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);
verifyAjaxCsrf();

function stgPayUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}
function stgPayRef():string{return 'PAY-STG-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));}
function stgCols(PDO $pdo,string $table):array{
    static $cache=[];if(isset($cache[$table]))return $cache[$table];
    $rows=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
    $cols=[];foreach($rows as $r)$cols[(string)$r['Field']]=true;return $cache[$table]=$cols;
}
function stgHas(PDO $pdo,string $table,string $col):bool{$c=stgCols($pdo,$table);return isset($c[$col]);}
function stgAdd(array &$cols,array &$vals,array &$params,string $col,$val,bool $raw=false):void{
    $cols[]='`'.$col.'`';$vals[]=$raw?$val:'?';if(!$raw)$params[]=$val;
}
function stgMoney($v):float{return round((float)str_replace(',','.',(string)$v),2);}

try{
    $hostId=(int)currentEtablissementId($pdo);
    $userId=(int)($_SESSION['user_id']??0);
    $invoiceId=(int)($_POST['invoice_id']??0);
    $amount=stgMoney($_POST['amount']??0);
    $canal=trim((string)($_POST['canal']??'ESPECES'))?:'ESPECES';
    $reference=trim((string)($_POST['payment_reference']??''));
    $phone=trim((string)($_POST['phone_number']??''));
    $operateur=trim((string)($_POST['operateur']??''));
    $observation=trim((string)($_POST['observation']??''));

    if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);
    if(!$invoiceId)jsonResponse(false,'Facture invalide.',[],422);
    if($amount<=0)jsonResponse(false,'Montant de paiement invalide.',[],422);

    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT * FROM stage_invoices WHERE id=? AND host_etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$invoiceId,$hostId]);
    $invoice=$s->fetch(PDO::FETCH_ASSOC);
    if(!$invoice)throw new RuntimeException('Facture introuvable.');

    $s=$pdo->prepare('SELECT statut FROM stage_applications WHERE id=? LIMIT 1');
    $s->execute([(int)($invoice['application_id']??0)]);
    if($s->fetchColumn()!=='ACCEPTEE')
        throw new RuntimeException("La candidature doit être acceptée par l'université avant tout paiement.");

    $s=$pdo->prepare('SELECT statut FROM stage_reservations WHERE id=? LIMIT 1');
    $s->execute([(int)($invoice['reservation_id']??0)]);
    if(!in_array($s->fetchColumn(),['EN_ATTENTE_PAIEMENT','CONFIRMEE'],true))
        throw new RuntimeException("La réservation n'est pas à une étape payable.");

    $status=strtoupper((string)($invoice['statut']??''));
    if(in_array($status,['PAYEE','ANNULEE','EXPIREE'],true))
        throw new RuntimeException('Cette facture ne peut plus être encaissée.');

    $invoiceAmount=(float)($invoice['montant']??0);
    if($invoiceAmount<=0)throw new RuntimeException('Montant de facture invalide.');

    $payCols=stgCols($pdo,'stage_payments');
    $amountCol=isset($payCols['montant'])?'montant':(isset($payCols['amount'])?'amount':null);
    if(!$amountCol)throw new RuntimeException('Colonne montant introuvable dans stage_payments.');

    $s=$pdo->prepare("SELECT COALESCE(SUM(`$amountCol`),0) FROM stage_payments WHERE invoice_id=? AND statut='VALIDE'");
    $s->execute([$invoiceId]);
    $alreadyPaid=round((float)$s->fetchColumn(),2);
    $remaining=round(max(0,$invoiceAmount-$alreadyPaid),2);

    if($remaining<=0)throw new RuntimeException('Cette facture est déjà soldée.');
    if($amount>$remaining)throw new RuntimeException('Le montant dépasse le reste à payer.');

    $paymentRef=$reference!==''?$reference:stgPayRef();
    $now=date('Y-m-d H:i:s');
    $cols=[];$vals=[];$params=[];

    foreach([
        'uuid'=>stgPayUuid(),
        'invoice_id'=>$invoiceId,
        'reservation_id'=>(int)($invoice['reservation_id']??0)?:null,
        'application_id'=>(int)($invoice['application_id']??0)?:null,
        'participation_id'=>(int)($invoice['participation_id']??0)?:null,
        'student_id'=>(int)($invoice['student_id']??0)?:null,
        'host_etablissement_id'=>$hostId,
        'reference'=>$paymentRef,
        'payment_reference'=>$paymentRef,
        'transaction_reference'=>$paymentRef,
        'montant'=>$amount,
        'amount'=>$amount,
        'devise'=>$invoice['devise']??'USD',
        'currency'=>$invoice['devise']??'USD',
        'canal'=>$canal,
        'operateur'=>$operateur!==''?$operateur:null,
        'phone_number'=>$phone!==''?$phone:null,
        'observation'=>$observation!==''?$observation:null,
        'statut'=>'VALIDE',
        'created_by'=>$userId?:null,
        'created_by_user_id'=>$userId?:null,
        'validated_by'=>$userId?:null,
        'validated_by_user_id'=>$userId?:null,
        'created_at'=>$now,
        'updated_at'=>$now,
        'validated_at'=>$now,
        'paid_at'=>$now,
        'date_paiement'=>$now
    ] as $col=>$val){
        if(isset($payCols[$col]))stgAdd($cols,$vals,$params,$col,$val);
    }

    if(!isset($payCols['statut']))throw new RuntimeException('Colonne statut introuvable dans stage_payments.');
    if(!isset($payCols['invoice_id']))throw new RuntimeException('Colonne invoice_id introuvable dans stage_payments.');

    $pdo->prepare('INSERT INTO stage_payments('.implode(',',$cols).') VALUES('.implode(',',$vals).')')->execute($params);
    $paymentId=(int)$pdo->lastInsertId();

    $newPaid=round($alreadyPaid+$amount,2);
    $newStatus=$newPaid+0.001>=$invoiceAmount?'PAYEE':'PARTIELLEMENT_PAYEE';

    $invCols=stgCols($pdo,'stage_invoices');
    $set=['statut=?'];$up=[$newStatus];
    if($newStatus==='PAYEE'&&isset($invCols['paid_at'])){$set[]='paid_at=NOW()';}
    if($newStatus==='PAYEE'&&isset($invCols['date_paiement'])){$set[]='date_paiement=NOW()';}
    if(isset($invCols['updated_at'])){$set[]='updated_at=NOW()';}
    $up[]=$invoiceId;$up[]=$hostId;
    $pdo->prepare('UPDATE stage_invoices SET '.implode(',',$set).' WHERE id=? AND host_etablissement_id=?')->execute($up);

    if($newStatus==='PAYEE'&&!empty($invoice['reservation_id'])){
        $pdo->prepare("UPDATE stage_reservations SET statut='CONFIRMEE',expires_at=NULL,confirmed_at=COALESCE(confirmed_at,NOW()),cancelled_at=NULL WHERE id=? AND statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')")
            ->execute([(int)$invoice['reservation_id']]);
    }

    $pdo->commit();

    jsonResponse(true,$newStatus==='PAYEE'?'Paiement validé. Facture soldée.':'Paiement partiel enregistré.',[
        'payment_id'=>$paymentId,
        'invoice_id'=>$invoiceId,
        'invoice_status'=>$newStatus,
        'paid'=>$newPaid,
        'remaining'=>max(0,round($invoiceAmount-$newPaid,2))
    ]);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[HOST CASH PAYMENT] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,$e->getMessage(),[],422);
}
