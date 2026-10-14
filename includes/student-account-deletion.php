<?php

function studentAccountDeletionTableExists(PDO $pdo):bool{
    $s=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');$s->execute(['account_deletion_requests']);return (bool)$s->fetchColumn();
}

function studentAccountDeletionUuid():string{
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));
}

function studentAccountPendingDeletion(PDO $pdo,int $userId,bool $lock=false):?array{
    if(!studentAccountDeletionTableExists($pdo))return null;
    $sql="SELECT * FROM account_deletion_requests WHERE user_id=? AND statut='PENDING' ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':'');
    $s=$pdo->prepare($sql);$s->execute([$userId]);$row=$s->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function studentAccountScheduleDeletion(PDO $pdo,int $userId,int $studentId,string $password,string $confirmation,string $ip=''):array{
    if(!studentAccountDeletionTableExists($pdo))throw new RuntimeException('Migration de suppression differee requise.');
    if($confirmation!=='DESACTIVER')throw new InvalidArgumentException('Saisissez DESACTIVER pour confirmer.');
    $s=$pdo->prepare('SELECT id,password,actif,statut_compte FROM users WHERE id=? LIMIT 1');$s->execute([$userId]);$user=$s->fetch(PDO::FETCH_ASSOC);
    if(!$user||empty($user['password'])||!password_verify($password,(string)$user['password']))throw new InvalidArgumentException('Mot de passe incorrect.');
    $pdo->beginTransaction();
    try{
        $pending=studentAccountPendingDeletion($pdo,$userId,true);
        if(!$pending){
            $uuid=studentAccountDeletionUuid();
            $pdo->prepare("INSERT INTO account_deletion_requests(uuid,user_id,student_id,statut,requested_at,delete_after,request_ip) VALUES(?,?,?,'PENDING',NOW(),DATE_ADD(NOW(),INTERVAL 30 DAY),?)")
                ->execute([$uuid,$userId,$studentId,$ip?:null]);
            $pending=studentAccountPendingDeletion($pdo,$userId,true);
        }
        $pdo->prepare("UPDATE users SET actif=0,statut_compte='SUSPENDU' WHERE id=?")->execute([$userId]);
        $pdo->prepare("UPDATE student_profiles SET statut='SUSPENDU' WHERE id=? AND user_id=?")->execute([$studentId,$userId]);
        $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('UPDATE mobile_notification_devices SET actif=0 WHERE utilisateur_id=?')->execute([$userId]);
        $pdo->commit();
        return ['uuid'=>$pending['uuid'],'status'=>'PENDING','requested_at'=>$pending['requested_at'],'delete_after'=>$pending['delete_after'],'grace_period_days'=>30,'reactivation'=>'Reconnectez-vous avant delete_after avec votre mot de passe pour annuler la suppression.'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function studentAccountCancelDeletionOnLogin(PDO $pdo,int $userId):array{
    $pending=studentAccountPendingDeletion($pdo,$userId,false);
    if(!$pending)return ['pending'=>false,'reactivated'=>false,'expired'=>false];
    if(strtotime((string)$pending['delete_after'])<=time()){
        studentAccountProcessDeletion($pdo,(int)$pending['id']);
        return ['pending'=>true,'reactivated'=>false,'expired'=>true];
    }
    $pdo->beginTransaction();
    try{
        $locked=studentAccountPendingDeletion($pdo,$userId,true);
        if(!$locked){$pdo->commit();return ['pending'=>false,'reactivated'=>false,'expired'=>false];}
        $pdo->prepare("UPDATE account_deletion_requests SET statut='CANCELLED',cancelled_at=NOW() WHERE id=? AND statut='PENDING'")->execute([(int)$locked['id']]);
        $pdo->prepare("UPDATE users SET actif=1,statut_compte='ACTIF' WHERE id=?")->execute([$userId]);
        $pdo->prepare("UPDATE student_profiles SET statut='ACTIF' WHERE user_id=? AND statut='SUSPENDU'")->execute([$userId]);
        $pdo->commit();return ['pending'=>true,'reactivated'=>true,'expired'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function studentAccountProcessDeletion(PDO $pdo,int $requestId):bool{
    if(!studentAccountDeletionTableExists($pdo))return false;
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare("SELECT * FROM account_deletion_requests WHERE id=? AND statut='PENDING' AND delete_after<=NOW() LIMIT 1 FOR UPDATE");$s->execute([$requestId]);$request=$s->fetch(PDO::FETCH_ASSOC);
        if(!$request){$pdo->commit();return false;}
        $userId=(int)$request['user_id'];$studentId=(int)($request['student_id']??0);
        $identifier='deleted-'.$userId.'-'.substr(bin2hex(random_bytes(8)),0,12);
        $pdo->prepare("UPDATE users SET nom='Compte',postnom='supprime',prenom=NULL,email=NULL,identifiant=?,password=NULL,telephone=NULL,photo=NULL,adresse=NULL,ville=NULL,province=NULL,matricule=NULL,actif=0,statut_compte='SUSPENDU',activation_token_hash=NULL,activation_expire_at=NULL,activation_token=NULL WHERE id=?")
            ->execute([$identifier,$userId]);
        if($studentId>0)$pdo->prepare("UPDATE student_profiles SET user_id=NULL,statut='ARCHIVE' WHERE id=? AND user_id=?")->execute([$studentId,$userId]);
        $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([$userId]);
        $deviceToken='deleted-'.bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE mobile_notification_devices SET actif=0,jeton=?,jeton_hash=? WHERE utilisateur_id=?')->execute([$deviceToken,hash('sha256',$deviceToken),$userId]);
        $pdo->prepare("UPDATE role_assignments SET actif=0,revoked_at=COALESCE(revoked_at,NOW()),revocation_reason=COALESCE(revocation_reason,'Compte supprime a la demande de l utilisateur') WHERE user_id=?")->execute([$userId]);
        $pdo->prepare("UPDATE account_deletion_requests SET statut='PROCESSED',processed_at=NOW() WHERE id=?")->execute([$requestId]);
        $pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function studentAccountProcessDueDeletions(PDO $pdo,int $limit=100):array{
    if(!studentAccountDeletionTableExists($pdo))return ['processed'=>0,'failed'=>0];
    $limit=max(1,min(1000,$limit));$ids=$pdo->query("SELECT id FROM account_deletion_requests WHERE statut='PENDING' AND delete_after<=NOW() ORDER BY delete_after,id LIMIT {$limit}")->fetchAll(PDO::FETCH_COLUMN);
    $processed=0;$failed=0;
    foreach($ids as $id){try{if(studentAccountProcessDeletion($pdo,(int)$id))$processed++;}catch(Throwable $e){$failed++;error_log('[ACCOUNT DELETION] request #'.$id.' '.$e->getMessage());}}
    return ['processed'=>$processed,'failed'=>$failed];
}
