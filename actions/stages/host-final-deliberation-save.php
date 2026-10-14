<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))jsonResponse(false,'Jeton de sécurité invalide.',[],419);

function fdUuid():string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}

try{
    requireRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE']);
    $eid=(int)currentEtablissementId($pdo);$uid=(int)($_SESSION['user_id']??0);$aid=(int)($_POST['assignment_id']??0);$action=strtoupper(trim((string)($_POST['action']??'')));
    $date=trim((string)($_POST['date_deliberation']??''));$scoreRaw=trim((string)($_POST['final_score']??''));$decision=strtoupper(trim((string)($_POST['decision']??'')));$app=trim((string)($_POST['appreciation']??''));
    if(!$eid||!$uid||!$aid)throw new RuntimeException('Contexte de délibération invalide.');
    if(!in_array($action,['SAVE','VALIDATE'],true))throw new RuntimeException('Action invalide.');

    $s=$pdo->prepare("SELECT a.id FROM stage_assignments a WHERE a.id=? AND a.host_etablissement_id=? AND a.statut<>'ANNULEE' LIMIT 1");
    $s->execute([$aid,$eid]);if(!$s->fetchColumn())throw new RuntimeException('Affectation introuvable.');

    /* Le périmètre est dérivé des rotations réelles, comme celui des mini-jurys. */
    $s=$pdo->prepare("
        SELECT DISTINCT COALESCE(coord.id,parent.id,hu.id)
        FROM stage_rotations r
        JOIN stage_assignments a ON a.id=r.assignment_id
        JOIN stage_admissions ad ON ad.id=a.admission_id
        JOIN host_units hu ON hu.id=r.host_unit_id
        LEFT JOIN host_units parent ON parent.id=hu.parent_id AND parent.host_etablissement_id=r.host_etablissement_id
        LEFT JOIN host_units coord ON coord.id=ad.coordination_unit_id AND coord.host_etablissement_id=r.host_etablissement_id
        WHERE r.assignment_id=?
          AND r.host_etablissement_id=?
          AND r.statut<>'ANNULEE'
    ");
    $s->execute([$aid,$eid]);$deps=array_values(array_unique(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN))));if(!$deps)throw new RuntimeException('Aucun département requis.');

    $ph=implode(',',array_fill(0,count($deps),'?'));$params=array_merge([$aid,$eid],$deps);
    $s=$pdo->prepare("SELECT COUNT(DISTINCT department_unit_id) FROM stage_mini_juries WHERE assignment_id=? AND host_etablissement_id=? AND statut='VALIDEE' AND department_unit_id IN($ph)");
    $s->execute($params);$valid=(int)$s->fetchColumn();$total=count($deps);if($valid!==$total)throw new RuntimeException("Tous les mini-jurys doivent être validés ($valid/$total).");

    $score=null;if($scoreRaw!==''){if(!is_numeric($scoreRaw))throw new RuntimeException('Note invalide.');$score=round((float)$scoreRaw,2);if($score<0||$score>100)throw new RuntimeException('La note doit être comprise entre 0 et 100.');}
    if($decision!==''&&!in_array($decision,['VALIDE','A_REPRENDRE','AJOURNE'],true))throw new RuntimeException('Décision invalide.');
    if($date!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))))throw new RuntimeException('Date de délibération invalide.');
    if($date!==''&&$date>date('Y-m-d'))throw new RuntimeException('La date de délibération ne peut pas être future.');
    if($action==='VALIDATE'&&($score===null||$decision===''||$date===''))throw new RuntimeException('Date, note finale et décision sont obligatoires.');

    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT id,statut FROM stage_final_deliberations WHERE assignment_id=? AND host_etablissement_id=? LIMIT 1 FOR UPDATE");$s->execute([$aid,$eid]);$old=$s->fetch(PDO::FETCH_ASSOC);
    if($old&&$old['statut']==='VALIDEE')throw new RuntimeException('Cette délibération est déjà validée.');
    $status=$action==='VALIDATE'?'VALIDEE':'BROUILLON';

    if($old){$id=(int)$old['id'];$pdo->prepare("UPDATE stage_final_deliberations SET statut=?,mini_juries_total=?,mini_juries_valides=?,note_finale=?,decision=?,appreciation=?,date_deliberation=?,validated_by=?,validated_at=? WHERE id=?")->execute([$status,$total,$valid,$score,$decision?:null,$app?:null,$date?:null,$status==='VALIDEE'?$uid:null,$status==='VALIDEE'?date('Y-m-d H:i:s'):null,$id]);}
    else{$pdo->prepare("INSERT INTO stage_final_deliberations(uuid,assignment_id,host_etablissement_id,statut,mini_juries_total,mini_juries_valides,note_finale,decision,appreciation,date_deliberation,created_by,validated_by,validated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([fdUuid(),$aid,$eid,$status,$total,$valid,$score,$decision?:null,$app?:null,$date?:null,$uid,$status==='VALIDEE'?$uid:null,$status==='VALIDEE'?date('Y-m-d H:i:s'):null]);$id=(int)$pdo->lastInsertId();}

    $pdo->commit();jsonResponse(true,$status==='VALIDEE'?'Délibération finale validée avec succès.':'Brouillon enregistré.',['id'=>$id,'statut'=>$status]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}


