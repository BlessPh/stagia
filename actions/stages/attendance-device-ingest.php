<?php
/**
 * API d'intégration appelée par un appareil de pointage externe.
 * Elle authentifie l'appareil, dédoublonne l'événement puis crée ou complète une présence.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/attendance-devices.php';

/* Ce point d'entrée n'utilise pas la session : il répond directement en JSON. */
header('Content-Type: application/json; charset=utf-8');
/** Envoie une réponse API uniforme et arrête l'exécution. */
function out(bool $ok,string $message,array $data=[],int $status=200):never{
    http_response_code($status);
    echo json_encode(['success'=>$ok,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
/** Marque un événement reçu comme rejeté tout en conservant la raison de traitement. */
function eventReject(PDO $pdo,int $eventId,string $reason,int $status=422):never{
    $s=$pdo->prepare("UPDATE attendance_device_events
        SET status='REJECTED',processed_at=NOW(),rejection_reason=?
        WHERE id=?");
    $s->execute([mb_substr($reason,0,500),$eventId]);
    out(false,$reason,['event_id'=>$eventId],$status);
}
/** Calcule le retard en minutes entre l'horaire limite et l'heure de l'événement. */
function minutesBetween(string $limit,string $actual):int{
    $a=strtotime('1970-01-01 '.$limit);$b=strtotime('1970-01-01 '.$actual);
    return max(0,(int)floor(($b-$a)/60));
}

try{
    /* Le corps JSON est privilégié, avec repli sur POST pour les intégrations compatibles. */
    $raw=file_get_contents('php://input');
    $payload=json_decode($raw,true);
    if(!is_array($payload))$payload=$_POST;

    $deviceCode=strtoupper(trim((string)($payload['device_code']??'')));
    $externalId=trim((string)($payload['external_event_id']??''));
    $credential=trim((string)($payload['credential']??''));
    $eventType=strtoupper(trim((string)($payload['event_type']??'POINTAGE')));
    $occurredAt=trim((string)($payload['occurred_at']??''));
    $apiKey=$_SERVER['HTTP_X_STAGIA_DEVICE_KEY']??($payload['api_key']??'');

    /* Les champs d'identification et la clé de l'appareil sont obligatoires. */
    if($deviceCode===''||$externalId===''||$credential===''||$occurredAt===''||$apiKey==='')
        out(false,'Payload incomplet.',[],422);
    if(!in_array($eventType,['ARRIVEE','DEPART','POINTAGE'],true))
        out(false,'event_type invalide.',[],422);

    $dt=DateTime::createFromFormat('Y-m-d H:i:s',$occurredAt);
    if(!$dt||$dt->format('Y-m-d H:i:s')!==$occurredAt)
        out(false,'occurred_at doit être au format YYYY-MM-DD HH:MM:SS.',[],422);
    if($dt>new DateTime('+5 minutes'))
        out(false,'Événement futur refusé.',[],422);

    /* L'appareil actif est authentifié par comparaison d'empreintes de clé API. */
    $s=$pdo->prepare("SELECT * FROM attendance_devices WHERE code=? AND actif=1 LIMIT 1");
    $s->execute([$deviceCode]);
    $device=$s->fetch(PDO::FETCH_ASSOC);
    if(!$device||!hash_equals($device['api_key_hash'],hash('sha256',(string)$apiKey)))
        out(false,'Appareil non autorisé.',[],401);

    $deviceId=(int)$device['id'];
    /* Toutes les étapes de réception et de création de présence sont atomiques. */
    $pdo->beginTransaction();

    try{
        /* L'identifiant externe rend l'appel idempotent : un doublon ne crée rien. */
        $s=$pdo->prepare("INSERT INTO attendance_device_events(
            device_id,external_event_id,credential_ref,event_type,occurred_at,status,raw_payload
        ) VALUES(?,?,?,?,?,'RECEIVED',?)");
        $s->execute([$deviceId,$externalId,$credential,$eventType,$occurredAt,$raw?:json_encode($payload)]);
        $eventId=(int)$pdo->lastInsertId();
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000'){
            $pdo->rollBack();
            out(true,'Événement déjà reçu : aucun doublon créé.',['duplicate'=>1]);
        }
        throw $e;
    }

    $s=$pdo->prepare("UPDATE attendance_devices SET last_seen_at=NOW() WHERE id=?");
    $s->execute([$deviceId]);

    /* L'identifiant présenté par l'appareil est résolu vers le stagiaire associé. */
    $s=$pdo->prepare("SELECT student_id
        FROM attendance_device_credentials
        WHERE device_id=? AND credential_ref=? AND actif=1
        LIMIT 1");
    $s->execute([$deviceId,$credential]);
    $studentId=(int)$s->fetchColumn();
    if(!$studentId){$pdo->commit();eventReject($pdo,$eventId,'Identifiant non associé à un stagiaire.',422);}

    $date=$dt->format('Y-m-d');$time=$dt->format('H:i:s');
    $params=[(int)$device['host_etablissement_id'],$studentId,$date];
    $unitSql='';
    if(!empty($device['host_unit_id'])){$unitSql=' AND r.host_unit_id=?';$params[]=(int)$device['host_unit_id'];}

    /* Une seule rotation active doit correspondre à l'établissement et à l'unité éventuelle. */
    $s=$pdo->prepare("SELECT r.id rotation_id,r.assignment_id,r.host_unit_id
        FROM stage_rotations r
        INNER JOIN stage_assignments a ON a.id=r.assignment_id
        INNER JOIN stage_admissions ad ON ad.id=a.admission_id
        INNER JOIN stage_reservations sr ON sr.id=ad.reservation_id
        INNER JOIN stage_applications sa ON sa.id=sr.application_id
        INNER JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
        INNER JOIN student_enrollments se ON se.id=sae.enrollment_id
        WHERE r.host_etablissement_id=?
          AND se.student_id=?
          AND ? BETWEEN r.date_debut AND r.date_fin
          AND r.statut<>'ANNULEE'
          $unitSql
        ORDER BY r.id");
    $s->execute($params);
    $rotations=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rotations)!==1){
        $pdo->commit();
        eventReject($pdo,$eventId,count($rotations)===0?'Aucune rotation active correspondante.':'Plusieurs rotations correspondent : traitement manuel requis.',422);
    }
    $rotation=$rotations[0];
    $rotationId=(int)$rotation['rotation_id'];

    /* Verrouillage de la présence journalière avant de déterminer arrivée ou départ. */
    $s=$pdo->prepare("SELECT * FROM stage_attendances
        WHERE rotation_id=? AND student_id=? AND date_presence=?
        LIMIT 1 FOR UPDATE");
    $s->execute([$rotationId,$studentId,$date]);
    $att=$s->fetch(PDO::FETCH_ASSOC);

    /* Un POINTAGE générique devient arrivée ou départ selon l'état déjà enregistré. */
    $effective=$eventType;
    if($eventType==='POINTAGE'){
        $effective=(!$att||empty($att['heure_arrivee']))?'ARRIVEE':(empty($att['heure_depart'])?'DEPART':'POINTAGE');
    }
    if($effective==='POINTAGE'){
        $pdo->commit();
        eventReject($pdo,$eventId,'Arrivée et départ déjà enregistrés pour cette journée.',409);
    }

    $source=attendanceDeviceSource($device['type']);
    $statut=$att['statut']??'PRESENT';$retard=(int)($att['minutes_retard']??0);

    /* Le retard est calculé au moment de l'arrivée si l'appareil définit une limite. */
    if($effective==='ARRIVEE'&&!empty($device['heure_limite_arrivee'])){
        $late=minutesBetween($device['heure_limite_arrivee'],$time);
        if($late>0){$statut='RETARD';$retard=$late;}
    }

    /* Une présence existante est complétée ; sinon une nouvelle ligne est créée. */
    if($att){
        $attendanceId=(int)$att['id'];
        if($effective==='ARRIVEE'){
            $s=$pdo->prepare("UPDATE stage_attendances
                SET heure_arrivee=COALESCE(heure_arrivee,?),statut=?,minutes_retard=?,
                    source=?,device_id=?,validated_by=NULL,validated_at=NULL
                WHERE id=?");
            $s->execute([$time,$statut,$retard,$source,$deviceId,$attendanceId]);
        }else{
            $s=$pdo->prepare("UPDATE stage_attendances
                SET heure_depart=?,source=?,device_id=?,validated_by=NULL,validated_at=NULL
                WHERE id=?");
            $s->execute([$time,$source,$deviceId,$attendanceId]);
        }
    }else{
        $uuid=attendanceDeviceUuid();
        $arrival=$effective==='ARRIVEE'?$time:null;
        $departure=$effective==='DEPART'?$time:null;
        $s=$pdo->prepare("INSERT INTO stage_attendances(
            uuid,rotation_id,assignment_id,student_id,host_etablissement_id,date_presence,
            heure_arrivee,heure_depart,statut,minutes_retard,source,device_id,
            justification,observation,recorded_by,validated_by,validated_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL,NULL)");
        $s->execute([
            $uuid,$rotationId,(int)$rotation['assignment_id'],$studentId,(int)$device['host_etablissement_id'],
            $date,$arrival,$departure,$statut,$retard,$source,$deviceId
        ]);
        $attendanceId=(int)$pdo->lastInsertId();
    }

    /* L'événement brut est marqué traité et relié à la présence produite. */
    $s=$pdo->prepare("UPDATE attendance_device_events
        SET status='PROCESSED',processed_at=NOW(),attendance_id=?
        WHERE id=?");
    $s->execute([$attendanceId,$eventId]);

    $pdo->commit();
    out(true,'Pointage appareil traité. Validation humaine requise.',[
        'event_id'=>$eventId,
        'attendance_id'=>$attendanceId,
        'rotation_id'=>$rotationId,
        'effective_event'=>$effective,
        'source'=>$source,
        'validated'=>0
    ]);
}catch(Throwable $e){
    /* Toute erreur invalide la transaction et ne divulgue pas les détails internes. */
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[ATTENDANCE DEVICE INGEST] '.$e->getMessage());
    out(false,'Erreur de traitement appareil.',[],500);
}
