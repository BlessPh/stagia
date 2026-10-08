<?php

require_once __DIR__.'/communication-native.php';

/** Empêche de recréer exactement la même notification métier. */
function stageStudentNotificationAlreadySent(PDO $pdo,int $userId,string $eventKey):bool{
    $stmt=$pdo->prepare("
        SELECT 1
        FROM notifications
        WHERE utilisateur_id=?
          AND JSON_UNQUOTE(JSON_EXTRACT(donnees,'$.event_key'))=?
        LIMIT 1
    ");
    $stmt->execute([$userId,$eventKey]);
    return (bool)$stmt->fetchColumn();
}

/** Contexte commun aux notifications du parcours de réservation. */
function stageStudentReservationNotificationContext(PDO $pdo,int $reservationId):array{
    $stmt=$pdo->prepare("
        SELECT r.id,r.uuid reservation_uuid,r.statut reservation_status,
               a.uuid application_uuid,a.statut application_status,
               c.id campaign_id,c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
               c.owner_etablissement_id university_id,uni.nom university_name,
               h.id hospital_id,h.code hospital_code,h.nom hospital_name,
               sp.user_id student_user_id,
               COALESCE(p.frais_requis,0) fees_required,p.montant_frais fee_amount,p.devise fee_currency
        FROM stage_reservations r
        JOIN stage_applications a ON a.id=r.application_id
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN etablissements uni ON uni.id=c.owner_etablissement_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN student_profiles sp ON sp.id=se.student_id
        LEFT JOIN stage_campaign_participations p ON p.id=r.participation_id
        WHERE r.id=? LIMIT 1
    ");
    $stmt->execute([$reservationId]);
    return $stmt->fetch(PDO::FETCH_ASSOC)?:[];
}

/** Envoie une notification contextualisée sans compromettre la transaction métier. */
function stageNotifyStudentReservation(PDO $pdo,int $reservationId,string $event,array $details=[]):bool{
    try{
        $context=stageStudentReservationNotificationContext($pdo,$reservationId);
        if(!$context||(int)$context['student_user_id']<1)return false;

        $hospital=(string)$context['hospital_name'];
        $campaign=(string)$context['campaign_title'];
        $reason=trim((string)($details['reason']??''));
        $amount=(float)($details['amount']??$context['fee_amount']??0);
        $currency=trim((string)($details['currency']??$context['fee_currency']??'USD'))?:'USD';
        $messages=[
            'stage.reservation.approved'=>[
                'Réservation de stage approuvée',
                "Votre réservation pour la campagne « {$campaign} » à l’hôpital « {$hospital} » a été approuvée par votre université. Elle est maintenant en attente de votre placement."
            ],
            'stage.reservation.payment_required'=>[
                'Réservation approuvée — paiement requis',
                "Votre réservation pour la campagne « {$campaign} » à l’hôpital « {$hospital} » a été approuvée. Un paiement de ".number_format($amount,2,',',' ')." {$currency} est requis avant votre placement."
            ],
            'stage.reservation.rejected'=>[
                'Réservation de stage refusée',
                "Votre réservation pour la campagne « {$campaign} » à l’hôpital « {$hospital} » a été refusée par votre université.".($reason!==''?" Motif : {$reason}":' Vous pouvez consulter les autres places disponibles.')
            ],
            'stage.reservation.expired'=>[
                'Réservation temporaire expirée',
                "Votre réservation temporaire pour la campagne « {$campaign} » à l’hôpital « {$hospital} » a expiré avant sa validation. La place a été libérée et vous pouvez effectuer un nouveau choix."
            ],
            'stage.placement.confirmed'=>[
                'Placement de stage confirmé',
                "Votre placement pour la campagne « {$campaign} » à l’hôpital « {$hospital} » est confirmé".
                    (!empty($details['start_date'])&&!empty($details['end_date'])
                        ?" du {$details['start_date']} au {$details['end_date']}"
                        :'').". L’hôpital doit maintenant procéder à votre admission et à votre affectation dans un service."
            ],
            'stage.placement.cancelled'=>[
                'Placement de stage annulé',
                "Votre placement pour la campagne « {$campaign} » à l’hôpital « {$hospital} » a été annulé.".
                    ($reason!==''?" Motif : {$reason}. ":' ').
                    "Votre réservation a également été annulée. Veuillez choisir un autre hôpital et effectuer une nouvelle réservation."
            ]
        ];
        if(!isset($messages[$event]))throw new InvalidArgumentException('Type de notification de stage inconnu.');

        [$title,$content]=$messages[$event];
        $isPlacementEvent=str_starts_with($event,'stage.placement.');
        $isPlacementCancelled=$event==='stage.placement.cancelled';
        $placementUuid=trim((string)($details['placement_uuid']??''));
        $placementId=(int)($details['placement_id']??0);
        $placementStatus=(string)($details['placement_status']??($event==='stage.placement.cancelled'?'ANNULE':'CONFIRME'));
        $data=[
            'reservation_uuid'=>$context['reservation_uuid'],
            'application_uuid'=>$context['application_uuid'],
            'campaign'=>[
                'id'=>(int)$context['campaign_id'],'uuid'=>$context['campaign_uuid'],
                'code'=>$context['campaign_code'],'title'=>$context['campaign_title']
            ],
            'hospital'=>[
                'id'=>(int)$context['hospital_id'],'code'=>$context['hospital_code'],'name'=>$context['hospital_name']
            ],
            'reservation_status'=>$details['reservation_status']??$context['reservation_status'],
            'workflow_status'=>$details['workflow_status']??null,
            'action'=>[
                'type'=>$isPlacementCancelled?'campaign':($isPlacementEvent?'internship_placement':'internship_reservation'),
                'target_id'=>$isPlacementCancelled?$context['campaign_uuid']:
                    ($isPlacementEvent&&$placementUuid!==''?$placementUuid:$context['reservation_uuid']),
                'label'=>$isPlacementCancelled?'Choisir un autre hôpital':
                    ($isPlacementEvent?'Voir mon placement':'Voir ma réservation'),
                'title'=>$context['campaign_title'],'metadata'=>[
                    'event'=>$event,'reservation_status'=>$details['reservation_status']??$context['reservation_status'],
                    'placement_id'=>$placementId?:null,'placement_uuid'=>$placementUuid?:null,
                    'placement_status'=>$isPlacementEvent?$placementStatus:null
                ]
            ]
        ];
        if($isPlacementEvent){
            $data['placement']=[
                'id'=>$placementId?:null,'uuid'=>$placementUuid?:null,'status'=>$placementStatus,
                'start_date'=>$details['start_date']??null,'end_date'=>$details['end_date']??null,
                'cancelled_at'=>$details['cancelled_at']??null
            ];
        }
        if($reason!=='')$data['reason']=$reason;
        if($event==='stage.reservation.payment_required')$data['payment']=['amount'=>$amount,'currency'=>$currency];

        communicationNotifier(
            $pdo,(int)$context['student_user_id'],(int)$context['university_id'],
            $event,$title,$content,
            $isPlacementEvent&&!$isPlacementCancelled?'/views/espace-etudiant/mes-stages.php':'/views/espace-etudiant/reservations.php',
            $data
        );
        return true;
    }catch(Throwable $error){
        error_log('[STUDENT STAGE NOTIFICATION] '.$event.' reservation='.$reservationId.' | '.$error->getMessage());
        return false;
    }
}

/** Envoie une notification riche lorsqu'une affectation hospitalière est créée ou modifiée. */
function stageNotifyStudentAssignment(PDO $pdo,int $assignmentId,string $event='stage.assignment.created'):bool{
    try{
        $stmt=$pdo->prepare("
            SELECT ass.id,ass.uuid,ass.statut,ass.date_debut,ass.date_fin,ass.assigned_at,
                   ad.uuid admission_uuid,ad.coordination_unit_id,
                   r.uuid reservation_uuid,a.uuid application_uuid,
                   c.id campaign_id,c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
                   h.id hospital_id,h.code hospital_code,h.nom hospital_name,
                   department.id department_id,department.code department_code,
                   department.nom department_name,department.type department_type,
                   u.id unit_id,u.code unit_code,u.nom unit_name,u.type unit_type,
                   sp.user_id student_user_id
            FROM stage_assignments ass
            JOIN stage_admissions ad ON ad.id=ass.admission_id
            JOIN stage_reservations r ON r.id=ad.reservation_id
            JOIN stage_applications a ON a.id=r.application_id
            JOIN stage_campaigns c ON c.id=a.campaign_id
            JOIN etablissements h ON h.id=ass.host_etablissement_id
            LEFT JOIN host_units department
              ON department.id=ad.coordination_unit_id
             AND department.host_etablissement_id=ass.host_etablissement_id
            JOIN host_units u ON u.id=ass.host_unit_id
            JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
            JOIN student_enrollments se ON se.id=ae.enrollment_id
            JOIN student_profiles sp ON sp.id=se.student_id
            WHERE ass.id=? LIMIT 1
        ");
        $stmt->execute([$assignmentId]);
        $x=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$x||(int)$x['student_user_id']<1)return false;

        $updated=$event==='stage.assignment.updated';
        $title=$updated?'Affectation de stage modifiée':'Nouvelle affectation de stage';
        $departmentName=trim((string)($x['department_name']??''));
        if($departmentName==='')$departmentName=(string)$x['unit_name'];
        $content=($updated?'Votre affectation a été mise à jour':'Vous avez été affecté').
            " au département « {$departmentName} » de l’hôpital « {$x['hospital_name']} », du {$x['date_debut']} au {$x['date_fin']}.";
        $workflow=$x['statut']==='ACTIVE'?'STAGE_EN_COURS':'STAGE_PLANIFIE';
        $eventKey=$event.':'.$x['uuid'].':'.hash('sha256',implode('|',[
            $x['statut'],$x['date_debut'],$x['date_fin'],(string)($x['department_id']??''),(string)$x['unit_id']
        ]));
        if(stageStudentNotificationAlreadySent($pdo,(int)$x['student_user_id'],$eventKey))return true;
        $data=[
            'event_key'=>$eventKey,
            'assignment_id'=>(int)$x['id'],'assignment_uuid'=>$x['uuid'],
            'assignment'=>[
                'id'=>(int)$x['id'],'uuid'=>$x['uuid'],'status'=>$x['statut'],
                'start_date'=>$x['date_debut'],'end_date'=>$x['date_fin'],'assigned_at'=>$x['assigned_at']
            ],
            'admission_uuid'=>$x['admission_uuid'],'reservation_uuid'=>$x['reservation_uuid'],
            'application_uuid'=>$x['application_uuid'],'workflow_status'=>$workflow,
            'campaign'=>[
                'id'=>(int)$x['campaign_id'],'uuid'=>$x['campaign_uuid'],
                'code'=>$x['campaign_code'],'title'=>$x['campaign_title']
            ],
            'hospital'=>[
                'id'=>(int)$x['hospital_id'],'code'=>$x['hospital_code'],'name'=>$x['hospital_name']
            ],
            'department'=>[
                'id'=>$x['department_id']!==null?(int)$x['department_id']:null,
                'code'=>$x['department_code'],'name'=>$departmentName,'type'=>$x['department_type']
            ],
            /* Unité opérationnelle conservée pour compatibilité avec le workflow hospitalier. */
            'unit'=>[
                'id'=>(int)$x['unit_id'],'code'=>$x['unit_code'],'name'=>$x['unit_name'],'type'=>$x['unit_type']
            ],
            'action'=>[
                'type'=>'internship_assignment','target_id'=>$x['uuid'],'label'=>'Voir mon affectation',
                'title'=>$departmentName,'metadata'=>[
                    'assignment_id'=>(int)$x['id'],'assignment_uuid'=>$x['uuid'],'status'=>$x['statut']
                ]
            ]
        ];
        communicationNotifier(
            $pdo,(int)$x['student_user_id'],(int)$x['hospital_id'],$event,$title,$content,
            '/views/espace-etudiant/mes-stages.php',$data
        );
        return true;
    }catch(Throwable $error){
        error_log('[STUDENT ASSIGNMENT NOTIFICATION] '.$event.' assignment='.$assignmentId.' | '.$error->getMessage());
        return false;
    }
}

/** Notifie l'étudiant lorsqu'une rotation individuelle est créée ou réellement modifiée. */
function stageNotifyStudentRotation(PDO $pdo,int $rotationId,string $event='stage.rotation.created'):bool{
    try{
        $stmt=$pdo->prepare("
            SELECT rot.id,rot.uuid,rot.sequence_no,rot.statut,rot.date_debut,rot.date_fin,
                   rot.objectifs,rot.host_unit_id,
                   ass.id assignment_id,ass.uuid assignment_uuid,
                   h.id hospital_id,h.code hospital_code,h.nom hospital_name,
                   target.code target_code,target.nom target_name,target.type target_type,
                   parent.id parent_id,parent.code parent_code,parent.nom parent_name,parent.type parent_type,
                   department.id department_id,department.code department_code,
                   department.nom department_name,department.type department_type,
                   supervisor.id supervisor_user_id,
                   CONCAT_WS(' ',supervisor.prenom,supervisor.nom,supervisor.postnom) supervisor_name,
                   sp.user_id student_user_id
            FROM stage_rotations rot
            JOIN stage_assignments ass ON ass.id=rot.assignment_id
            JOIN stage_admissions ad ON ad.id=ass.admission_id
            JOIN stage_reservations reservation ON reservation.id=ad.reservation_id
            JOIN stage_applications application ON application.id=reservation.application_id
            JOIN student_academic_enrollments ae ON ae.id=application.academic_enrollment_id
            JOIN student_enrollments se ON se.id=ae.enrollment_id
            JOIN student_profiles sp ON sp.id=se.student_id
            JOIN etablissements h ON h.id=rot.host_etablissement_id
            JOIN host_units target ON target.id=rot.host_unit_id
            LEFT JOIN host_units parent ON parent.id=target.parent_id
            LEFT JOIN host_units department ON department.id=ad.coordination_unit_id
            LEFT JOIN stage_rotation_supervisors rs
              ON rs.id=(
                  SELECT rs2.id FROM stage_rotation_supervisors rs2
                  WHERE rs2.rotation_id=rot.id AND rs2.actif=1
                  ORDER BY rs2.principal DESC,rs2.id LIMIT 1
              )
            LEFT JOIN users supervisor ON supervisor.id=rs.user_id
            WHERE rot.id=? LIMIT 1
        ");
        $stmt->execute([$rotationId]);
        $x=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$x||(int)$x['student_user_id']<1)return false;

        $targetType=strtoupper(strtr(trim((string)$x['target_type']),[
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'E','è'=>'E','ê'=>'E','ë'=>'E'
        ]));
        $isUnit=in_array($targetType,['UNITE','UNIT'],true);
        $service=[
            'id'=>$isUnit&&$x['parent_id']!==null?(int)$x['parent_id']:(int)$x['host_unit_id'],
            'code'=>$isUnit?$x['parent_code']:$x['target_code'],
            'name'=>$isUnit?$x['parent_name']:$x['target_name']
        ];
        $unit=$isUnit?[
            'id'=>(int)$x['host_unit_id'],'code'=>$x['target_code'],'name'=>$x['target_name']
        ]:null;
        $updated=$event==='stage.rotation.updated';
        $title=$updated?'Planification de rotation modifiée':'Nouvelle rotation de stage';
        $content=($updated?'Votre rotation a été mise à jour':'Une nouvelle rotation a été planifiée').
            " dans le service « {$service['name']} »".
            ($unit?" — unité « {$unit['name']} »":'').
            " de l’hôpital « {$x['hospital_name']} », du {$x['date_debut']} au {$x['date_fin']}.";
        $eventKey=$event.':'.$x['uuid'].':'.hash('sha256',implode('|',[
            $x['statut'],$x['sequence_no'],$x['date_debut'],$x['date_fin'],$x['host_unit_id'],
            (string)($x['supervisor_user_id']??'')
        ]));
        if(stageStudentNotificationAlreadySent($pdo,(int)$x['student_user_id'],$eventKey))return true;

        $data=[
            'event_key'=>$eventKey,
            'rotation_id'=>(int)$x['id'],'rotation_uuid'=>$x['uuid'],
            'assignment_id'=>(int)$x['assignment_id'],'assignment_uuid'=>$x['assignment_uuid'],
            'rotation'=>[
                'id'=>(int)$x['id'],'uuid'=>$x['uuid'],'sequence'=>(int)$x['sequence_no'],
                'status'=>$x['statut'],'start_date'=>$x['date_debut'],'end_date'=>$x['date_fin'],
                'objectives'=>$x['objectifs']
            ],
            'department'=>[
                'id'=>$x['department_id']!==null?(int)$x['department_id']:null,
                'code'=>$x['department_code'],'name'=>$x['department_name'],'type'=>$x['department_type']
            ],
            'service'=>$service,'unit'=>$unit,
            'hospital'=>[
                'id'=>(int)$x['hospital_id'],'code'=>$x['hospital_code'],'name'=>$x['hospital_name']
            ],
            'supervisor'=>$x['supervisor_user_id']!==null?[
                'user_id'=>(int)$x['supervisor_user_id'],'name'=>trim((string)$x['supervisor_name'])
            ]:null,
            'action'=>[
                'type'=>'internship_rotation','target_id'=>$x['uuid'],'label'=>'Voir ma planification',
                'title'=>(string)$service['name'],'metadata'=>[
                    'rotation_id'=>(int)$x['id'],'rotation_uuid'=>$x['uuid'],
                    'assignment_uuid'=>$x['assignment_uuid'],'status'=>$x['statut']
                ]
            ]
        ];
        communicationNotifier(
            $pdo,(int)$x['student_user_id'],(int)$x['hospital_id'],$event,$title,$content,
            '/views/espace-etudiant/mes-stages.php',$data
        );
        return true;
    }catch(Throwable $error){
        error_log('[STUDENT ROTATION NOTIFICATION] '.$event.' rotation='.$rotationId.' | '.$error->getMessage());
        return false;
    }
}
