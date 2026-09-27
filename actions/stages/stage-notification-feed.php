<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/chef-service-scope.php';
require_once __DIR__.'/../../includes/stagia-role-detect.php';

function nf_count(PDO $pdo,string $sql,array $p=[]):int{try{$s=$pdo->prepare($sql);$s->execute($p);return (int)$s->fetchColumn();}catch(Throwable $e){error_log('[NOTIF COUNT] '.$e->getMessage().' SQL='.$sql);return 0;}}
function nf_rows(PDO $pdo,string $sql,array $p=[]):array{try{$s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){error_log('[NOTIF ROWS] '.$e->getMessage().' SQL='.$sql);return [];}}
function nf_url(string $u):string{return BASE_URL.$u;}
function nf_norm(string $v):string{return strtoupper(str_replace(['É','È','Ê','Ë','À','Â','Î','Ï','Ô','Û','Ù','Ç',' '],['E','E','E','E','A','A','I','I','O','U','U','C','_'],trim($v)));}
function nf_host(PDO $pdo,int $eid,string $role):bool{return $eid>0&&(function_exists('contextHostEnabled')?contextHostEnabled():in_array($role,['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE','ENCADREUR','ENCADREUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','EVALUATEUR_CLINIQUE','POINTEUR','AUTORITE_HOSPITALIERE','GESTIONNAIRE_FINANCIER_HOSPITALIER'],true));}
function nf_role_ids_for_coord(PDO $pdo,int $eid,int $uid):array{
    $scope=chefServiceScope($pdo,$eid,$uid);
    if(!empty($scope['all']))return ['all'=>true,'ids'=>[]];
    $ids=array_values(array_unique(array_filter(array_map('intval',$scope['ids']??[]))));
    if(!$ids)return ['all'=>false,'ids'=>[]];
    try{
        $in=implode(',',array_fill(0,count($ids),'?'));
        $s=$pdo->prepare("SELECT DISTINCT parent_id FROM host_units WHERE host_etablissement_id=? AND actif=1 AND id IN($in) AND parent_id IS NOT NULL");
        $s->execute(array_merge([$eid],$ids));
        foreach($s->fetchAll(PDO::FETCH_COLUMN) as $pid)$ids[]=(int)$pid;
        $ids=array_values(array_unique(array_filter($ids)));
    }catch(Throwable $e){error_log('[NOTIF CHEF PARENT SCOPE] '.$e->getMessage());}
    return ['all'=>false,'ids'=>$ids];
}

try{
    $eid=(int)($_SESSION['etablissement_id']??0);
    $uid=(int)($_SESSION['user_id']??0);
    $roleRaw=(string)($_SESSION['role_code']??'');
    $role=stagiaRoleNormalize($roleRaw);
    $rolePool=stagiaUserRolePool($pdo,$uid,$eid,[$roleRaw,(string)($_SESSION['role_nom']??'')]);
    $isChef=stagiaRolePoolContains($rolePool,['CHEF_SERVICE','CHEF_DE_SERVICE','RESPONSABLE_SERVICE']);
    $isEnc=stagiaRolePoolContains($rolePool,['ENCADREUR','ENCADREUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','MAITRE_DE_STAGE_CLINIQUE','EVALUATEUR_CLINIQUE']);
    $isAdmin=stagiaRolePoolContains($rolePool,['ADMIN_ACCUEIL','ADMINISTRATEUR_ETABLISSEMENT_DE_SANTE']);
    $isCoord=stagiaRolePoolContains($rolePool,['COORDINATEUR_STAGES','COORDINATEUR_DES_STAGES','COORDINATEUR_STAGES_HOSPITALIERS']);
    $host=nf_host($pdo,$eid,$role)||$isAdmin||$isCoord||$isChef||$isEnc;
    $academic=function_exists('contextAcademicEnabled')?contextAcademicEnabled():in_array($role,['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'],true);
    $student=$role==='STAGIAIRE';

    $items=[];
    $hostCount=$expectedCount=$readyCount=$hostResultReadyCount=0;
    $chefCount=$chefEvaluationCount=0;
    $encadreurEvaluationCount=$encadreurRotationsCount=0;
    $logbookCount=$attendanceCount=$taskCount=$feedbackCount=0;
    $academicCount=$applicationCount=$universityResultCount=$studentOfferCount=0;
    $primary='#';

    if($eid>0&&$host&&contextPermission('campaign.hosting.respond')){
        $hostCount=nf_count($pdo,"SELECT COUNT(*) FROM stage_campaign_participations WHERE host_etablissement_id=? AND statut='SOLLICITEE'",[$eid]);
        $items=array_merge($items,nf_rows($pdo,"SELECT CONCAT('H:',p.id) `key`,CONCAT('Nouvelle sollicitation — ',u.nom) title,CONCAT(c.code,' — ',c.titre) subtitle,CONCAT(st.libelle,' · ',COALESCE(DATE_FORMAT(COALESCE(p.requested_at,p.created_at),'%d/%m/%Y %H:%i'),'')) meta,? href,COALESCE(p.requested_at,p.created_at) notification_at FROM stage_campaign_participations p JOIN stage_campaigns c ON c.id=p.university_campaign_id JOIN stage_types st ON st.id=c.stage_type_id JOIN etablissements u ON u.id=c.owner_etablissement_id WHERE p.host_etablissement_id=? AND p.statut='SOLLICITEE' ORDER BY notification_at DESC,p.id DESC LIMIT 6",[nf_url('/views/espace-hopital/sollicitations-d4.php'),$eid]));
    }

    if($eid>0&&$host&&($isAdmin||$isCoord||contextPermission(['admission.hosting.view','stage.manage']))){
        $expectedCount=nf_count($pdo,"SELECT COUNT(*) FROM stage_admissions a JOIN stage_reservations sr ON sr.id=a.reservation_id AND sr.statut='CONFIRMEE' JOIN stage_applications app ON app.id=sr.application_id AND app.statut='ACCEPTEE' JOIN stage_placements pl ON pl.id=a.placement_id AND pl.reservation_id=sr.id AND pl.statut='CONFIRME' WHERE a.host_etablissement_id=? AND a.statut='ATTENDU'",[$eid]);
        $items=array_merge($items,nf_rows($pdo,"SELECT CONCAT('E:',a.id) `key`,'Nouveau stagiaire attendu' title,CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Étudiant #',COALESCE(se.student_id,app.academic_enrollment_id))),' · ',c.code) subtitle,CONCAT(c.titre,' · ',COALESCE(DATE_FORMAT(a.created_at,'%d/%m/%Y %H:%i'),'')) meta,? href,a.created_at notification_at FROM stage_admissions a JOIN stage_reservations sr ON sr.id=a.reservation_id AND sr.statut='CONFIRMEE' JOIN stage_applications app ON app.id=sr.application_id AND app.statut='ACCEPTEE' JOIN stage_placements pl ON pl.id=a.placement_id AND pl.reservation_id=sr.id AND pl.statut='CONFIRME' JOIN stage_campaigns c ON c.id=app.campaign_id LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id LEFT JOIN student_profiles sp ON sp.id=se.student_id WHERE a.host_etablissement_id=? AND a.statut='ATTENDU' ORDER BY a.created_at DESC,a.id DESC LIMIT 6",[nf_url('/views/espace-hopital/stagiaires-attendus.php'),$eid]));
    }

    if($eid>0&&$host&&($isAdmin||$isCoord||$isChef||contextPermission('stage.manage'))){
        $readyParams=[$eid];$readyWhere='';
        if($isChef){
            $sc=nf_role_ids_for_coord($pdo,$eid,$uid);
            if(empty($sc['all'])){
                $ids=$sc['ids'];
                if($ids){$readyWhere=' AND a.coordination_unit_id IN('.implode(',',array_fill(0,count($ids),'?')).')';$readyParams=array_merge($readyParams,$ids);}else $readyWhere=' AND 1=0';
            }
        }
        $readyBase="FROM stage_admissions a JOIN stage_reservations sr ON sr.id=a.reservation_id AND sr.statut='CONFIRMEE' JOIN stage_applications app ON app.id=sr.application_id AND app.statut='ACCEPTEE' JOIN stage_placements pl ON pl.id=a.placement_id AND pl.reservation_id=sr.id AND pl.statut='CONFIRME' WHERE a.host_etablissement_id=? AND a.statut IN('ADMIS','EN_COURS') AND a.coordination_unit_id IS NOT NULL $readyWhere AND NOT EXISTS(SELECT 1 FROM stage_assignments ass WHERE ass.admission_id=a.id AND ass.statut<>'ANNULEE')";
        $readyCount=nf_count($pdo,"SELECT COUNT(*) $readyBase",$readyParams);
        $items=array_merge($items,nf_rows($pdo,"SELECT CONCAT('AF:',a.id) `key`,'Stagiaire prêt à affecter' title,CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Étudiant #',COALESCE(se.student_id,app.academic_enrollment_id))),' · ',COALESCE(cu.nom,'Coordination')) subtitle,CONCAT(c.code,' — ',c.titre,' · ',COALESCE(DATE_FORMAT(COALESCE(a.coordination_sent_at,a.created_at),'%d/%m/%Y %H:%i'),'')) meta,? href,COALESCE(a.coordination_sent_at,a.created_at) notification_at FROM stage_admissions a JOIN stage_reservations sr ON sr.id=a.reservation_id AND sr.statut='CONFIRMEE' JOIN stage_applications app ON app.id=sr.application_id AND app.statut='ACCEPTEE' JOIN stage_placements pl ON pl.id=a.placement_id AND pl.reservation_id=sr.id AND pl.statut='CONFIRME' JOIN stage_campaigns c ON c.id=app.campaign_id LEFT JOIN host_units cu ON cu.id=a.coordination_unit_id LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id LEFT JOIN student_profiles sp ON sp.id=se.student_id WHERE a.host_etablissement_id=? AND a.statut IN('ADMIS','EN_COURS') AND a.coordination_unit_id IS NOT NULL $readyWhere AND NOT EXISTS(SELECT 1 FROM stage_assignments ass WHERE ass.admission_id=a.id AND ass.statut<>'ANNULEE') ORDER BY notification_at DESC,a.id DESC LIMIT 6",array_merge([nf_url('/views/espace-hopital/affectations.php')],$readyParams)));
    }

    if($eid>0&&$host&&($isChef||$isAdmin||$isCoord||contextPermission('evaluation.manage'))){
        if($isChef){
            try{
                $scope=chefServiceScope($pdo,$eid,$uid);
                $p=[$eid];$w=chefServiceWhere('a',$scope,$p);
                $chefCount=nf_count($pdo,"SELECT COUNT(DISTINCT a.id) FROM stage_assignments a LEFT JOIN stage_admissions ad ON ad.id=a.admission_id WHERE a.host_etablissement_id=? $w AND a.statut IN('PLANIFIEE','ACTIVE') AND (ad.id IS NULL OR ad.statut IN('ADMIS','EN_COURS'))",$p);
                $p=[$eid];$w=chefServiceWhere('a',$scope,$p);
                $chefEvaluationCount=nf_count($pdo,"SELECT COUNT(DISTINCT e.id) FROM stage_evaluations e JOIN stage_rotations r ON r.id=e.rotation_id JOIN stage_assignments a ON a.id=r.assignment_id WHERE a.host_etablissement_id=? $w AND e.statut='SOUMISE'",$p);
            }catch(Throwable $e){error_log('[NOTIF CHEF] '.$e->getMessage());}
        }
    }

    if($eid>0&&$host&&$isEnc){
        $encadreurRotationsCount=nf_count($pdo,"SELECT COUNT(DISTINCT r.id) FROM stage_rotations r JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 WHERE r.host_etablissement_id=? AND r.statut IN('PLANIFIEE','ACTIVE')",[$uid,$eid]);
        $items=array_merge($items,nf_rows($pdo,"SELECT DISTINCT CONCAT('MR:',r.id) `key`,'Rotation à suivre' title,CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Stagiaire #',a.id)),' · ',COALESCE(hu.nom,'Service')) subtitle,CONCAT(COALESCE(c.code,'Session'),' · ',DATE_FORMAT(r.date_debut,'%d/%m/%Y'),' → ',DATE_FORMAT(r.date_fin,'%d/%m/%Y')) meta,? href,r.date_debut notification_at FROM stage_rotations r JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 JOIN stage_assignments a ON a.id=r.assignment_id LEFT JOIN host_units hu ON hu.id=a.host_unit_id LEFT JOIN stage_admissions ad ON ad.id=a.admission_id LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id LEFT JOIN stage_applications app ON app.id=sr.application_id LEFT JOIN stage_campaigns c ON c.id=app.campaign_id LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id LEFT JOIN student_profiles sp ON sp.id=se.student_id WHERE r.host_etablissement_id=? AND r.statut IN('PLANIFIEE','ACTIVE') ORDER BY r.date_debut ASC,r.id DESC LIMIT 6",[nf_url('/views/espace-hopital/mes-rotations.php'),$uid,$eid]));
        $encadreurEvaluationCount=nf_count($pdo,"SELECT COUNT(DISTINCT e.id) FROM stage_evaluations e JOIN stage_rotations r ON r.id=e.rotation_id JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 WHERE r.host_etablissement_id=? AND e.evaluator_user_id=? AND e.statut='BROUILLON'",[$uid,$eid,$uid]);
        $logbookCount=nf_count($pdo,"SELECT COUNT(DISTINCT le.id) FROM stage_logbook_entries le JOIN stage_rotation_supervisors rs ON rs.rotation_id=le.rotation_id AND rs.user_id=? AND rs.actif=1 WHERE le.host_etablissement_id=? AND le.statut='SOUMIS'",[$uid,$eid]);
        $today=date('Y-m-d');
        $attendanceCount=nf_count($pdo,"SELECT COUNT(DISTINCT r.id) FROM stage_rotations r JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 WHERE r.host_etablissement_id=? AND r.statut='ACTIVE' AND ? BETWEEN r.date_debut AND r.date_fin AND NOT EXISTS(SELECT 1 FROM stage_attendances att WHERE att.rotation_id=r.id AND att.date_presence=?)",[$uid,$eid,$today,$today]);
        $taskCount=nf_count($pdo,"SELECT COUNT(DISTINCT t.id) FROM stage_tasks t JOIN stage_assignments a ON a.id=t.assignment_id JOIN stage_rotations r ON r.assignment_id=a.id JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 WHERE a.host_etablissement_id=? AND t.statut='TERMINEE'",[$uid,$eid]);
    }

    if($eid>0&&$host&&contextPermission('stage.manage')&&!$isChef&&!$isEnc){
        $hostResultReadyCount=nf_count($pdo,"SELECT COUNT(DISTINCT c.id) FROM stage_evaluations ev JOIN stage_rotations r ON r.id=ev.rotation_id JOIN stage_assignments a ON a.id=r.assignment_id JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id JOIN stage_applications app ON app.id=sr.application_id JOIN stage_campaigns c ON c.id=app.campaign_id WHERE r.host_etablissement_id=? AND ev.statut IN('VALIDEE','FINALISEE') AND NOT EXISTS(SELECT 1 FROM stage_result_transmissions t WHERE t.campaign_id=c.id AND t.host_etablissement_id=? AND t.statut IN('ENVOYE','RECU','VALIDE','ARCHIVE'))",[$eid,$eid]);
    }

    if($eid>0&&$academic&&contextPermission('campaign.university.view')){
        $academicCount=nf_count($pdo,"SELECT COUNT(*) FROM stage_campaign_participations p JOIN stage_campaigns c ON c.id=p.university_campaign_id WHERE c.owner_etablissement_id=? AND p.statut='ACCEPTEE' AND p.capacite_proposee IS NOT NULL AND p.capacite_acceptee IS NULL",[$eid]);
        $items=array_merge($items,nf_rows($pdo,"SELECT CONCAT('A:',p.id) `key`,CONCAT('Nouvelle offre — ',h.nom) title,CONCAT(COALESCE(p.capacite_proposee,0),' place(s) proposées · ',c.code) subtitle,CONCAT(st.libelle,' · ',COALESCE(DATE_FORMAT(p.responded_at,'%d/%m/%Y %H:%i'),'')) meta,CONCAT(?,c.id) href,COALESCE(p.responded_at,p.created_at) notification_at FROM stage_campaign_participations p JOIN stage_campaigns c ON c.id=p.university_campaign_id JOIN stage_types st ON st.id=c.stage_type_id JOIN etablissements h ON h.id=p.host_etablissement_id WHERE c.owner_etablissement_id=? AND p.statut='ACCEPTEE' AND p.capacite_proposee IS NOT NULL AND p.capacite_acceptee IS NULL ORDER BY notification_at DESC,p.id DESC LIMIT 6",[nf_url('/views/stages/d4-partenaires.php?campaign_id='),$eid]));
    }

    if($eid>0&&$academic&&contextPermission(['stage.view','stage.manage'])){
        $applicationCount=nf_count($pdo,"SELECT COUNT(*) FROM stage_applications a JOIN stage_campaigns c ON c.id=a.campaign_id WHERE c.owner_etablissement_id=? AND a.statut='SOUMISE'",[$eid]);
        $items=array_merge($items,nf_rows($pdo,"SELECT CONCAT('C:',a.id) `key`,'Nouvelle candidature' title,CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Étudiant #',COALESCE(se.student_id,a.academic_enrollment_id))),' · ',COALESCE(h.nom,'Hôpital')) subtitle,CONCAT(c.code,' — ',c.titre,' · ',COALESCE(DATE_FORMAT(COALESCE(a.submitted_at,a.created_at),'%d/%m/%Y %H:%i'),'')) meta,? href,COALESCE(a.submitted_at,a.created_at) notification_at FROM stage_applications a JOIN stage_campaigns c ON c.id=a.campaign_id LEFT JOIN etablissements h ON h.id=a.host_etablissement_id LEFT JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id LEFT JOIN student_profiles sp ON sp.id=se.student_id WHERE c.owner_etablissement_id=? AND a.statut='SOUMISE' ORDER BY notification_at DESC LIMIT 6",[nf_url('/views/stages/candidatures.php'),$eid]));
    }

    if($eid>0&&$academic&&contextPermission(['stage.view','stage.manage','campaign.university.view'])){
        $universityResultCount=nf_count($pdo,"SELECT COUNT(*) FROM stage_result_transmissions WHERE university_etablissement_id=? AND statut='ENVOYE'",[$eid]);
        $items=array_merge($items,nf_rows($pdo,"SELECT CONCAT('UR:',t.id) `key`,'Résultats de stage reçus' title,CONCAT(COALESCE(h.nom,'Hôpital'),' · ',c.code,' — ',c.titre) subtitle,CONCAT('Envoyé le ',COALESCE(DATE_FORMAT(t.transmitted_at,'%d/%m/%Y %H:%i'),'')) meta,? href,COALESCE(t.transmitted_at,t.created_at) notification_at FROM stage_result_transmissions t JOIN stage_campaigns c ON c.id=t.campaign_id LEFT JOIN etablissements h ON h.id=t.host_etablissement_id WHERE t.university_etablissement_id=? AND t.statut='ENVOYE' ORDER BY notification_at DESC,t.id DESC LIMIT 6",[nf_url('/views/espace-etablissement/resultats-stage.php'),$eid]));
    }

    if($student&&$uid>0){
        $sid=nf_count($pdo,"SELECT id FROM student_profiles WHERE user_id=? AND statut='ACTIF' LIMIT 1",[$uid]);
        if($sid){
            $studentEligibility="FROM student_enrollments se JOIN student_academic_enrollments ae ON ae.enrollment_id=se.id AND ae.statut='EN_COURS' JOIN stage_campaigns c ON c.owner_etablissement_id=se.etablissement_id AND c.annee_academique_id=ae.annee_academique_id AND c.type_campagne='UNIVERSITAIRE' AND c.statut='OUVERTE' JOIN stage_types st ON st.id=c.stage_type_id AND st.actif=1 JOIN stage_campaign_promotions cp ON cp.campaign_id=c.id AND cp.promotion_id=ae.promotion_id WHERE se.student_id=? AND se.statut='ACTIF' AND EXISTS(SELECT 1 FROM stage_campaign_participations sp JOIN etablissements h ON h.id=sp.host_etablissement_id WHERE sp.university_campaign_id=c.id AND sp.statut='ACCEPTEE' AND COALESCE(sp.capacite_acceptee,0)>0 AND h.type_etablissement='HOPITAL' AND h.statut IN('VALIDE','ACTIF')) AND NOT EXISTS(SELECT 1 FROM stage_applications sa LEFT JOIN stage_reservations sr ON sr.application_id=sa.id LEFT JOIN stage_admissions ad ON ad.reservation_id=sr.id LEFT JOIN stage_assignments ass ON ass.admission_id=ad.id WHERE sa.campaign_id=c.id AND sa.academic_enrollment_id=ae.id AND ((sr.statut='RESERVEE_TEMPORAIREMENT' AND (sr.expires_at IS NULL OR sr.expires_at>NOW())) OR sr.statut='EN_ATTENTE_PAIEMENT' OR sr.statut='CONFIRMEE' OR ad.id IS NOT NULL OR (ass.id IS NOT NULL AND ass.statut<>'ANNULEE'))) AND NOT EXISTS(SELECT 1 FROM stage_completions sc WHERE sc.student_id=se.student_id AND sc.campaign_id=c.id AND sc.statut IN('EN_PREPARATION','PRET','VALIDE'))";
            $studentOfferCount=nf_count($pdo,"SELECT COUNT(DISTINCT c.id) $studentEligibility",[$sid]);
            $rows=nf_rows($pdo,"SELECT DISTINCT c.id,c.code,c.titre,c.date_debut notification_at,st.libelle $studentEligibility ORDER BY c.date_debut DESC,c.id DESC LIMIT 8",[$sid]);
            foreach($rows as $x)$items[]=['key'=>'S:'.$x['id'],'title'=>'Nouvelle offre de stage disponible','subtitle'=>$x['code'].' — '.$x['titre'],'meta'=>$x['libelle'].' · Début '.date('d/m/Y',strtotime($x['notification_at'])),'href'=>nf_url('/views/espace-etudiant/stages.php'),'notification_at'=>$x['notification_at']];
        }
    }

    usort($items,fn($a,$b)=>strcmp((string)($b['notification_at']??''),(string)($a['notification_at']??'')));
    $items=array_slice($items,0,8);
    $count=$hostCount+$expectedCount+$readyCount+$hostResultReadyCount+$chefCount+$chefEvaluationCount+$encadreurEvaluationCount+$encadreurRotationsCount+$logbookCount+$attendanceCount+$taskCount+$feedbackCount+$academicCount+$applicationCount+$universityResultCount+$studentOfferCount;

    if($student)$primary=nf_url('/views/espace-etudiant/stages.php');
    elseif($readyCount>0)$primary=nf_url('/views/espace-hopital/affectations.php');
    elseif($encadreurRotationsCount>0)$primary=nf_url('/views/espace-hopital/mes-rotations.php');
    elseif(($chefEvaluationCount+$encadreurEvaluationCount)>0)$primary=nf_url('/views/espace-hopital/evaluations.php');
    elseif($chefCount>0)$primary=nf_url('/views/espace-hopital/chef-service-stagiaires.php');
    elseif($logbookCount>0)$primary=nf_url('/views/espace-hopital/journaux-stage.php');
    elseif($attendanceCount>0)$primary=nf_url('/views/espace-hopital/presences.php');
    elseif($taskCount>0)$primary=nf_url('/views/espace-hopital/taches-progression.php');
    elseif($feedbackCount>0)$primary=nf_url('/views/espace-hopital/feedbacks.php');
    elseif($hostResultReadyCount>0)$primary=nf_url('/views/espace-hopital/resultats-stage.php');
    elseif($expectedCount>0)$primary=nf_url('/views/espace-hopital/stagiaires-attendus.php');
    elseif($hostCount>0)$primary=nf_url('/views/espace-hopital/sollicitations-d4.php');
    elseif($universityResultCount>0)$primary=nf_url('/views/espace-etablissement/resultats-stage.php');
    elseif($applicationCount>0)$primary=nf_url('/views/stages/candidatures.php');
    elseif($academicCount>0)$primary=nf_url('/views/stages/d4-partenaires.php');
    elseif($academic)$primary=nf_url('/views/stages/candidatures.php');
    elseif($host)$primary=nf_url('/views/espace-hopital/sollicitations-d4.php');

    jsonResponse(true,'',[
        'count'=>$count,'host_count'=>$hostCount,'expected_count'=>$expectedCount,'ready_assignment_count'=>$readyCount,
        'host_result_ready_count'=>$hostResultReadyCount,'chef_service_count'=>$chefCount,'chef_evaluation_count'=>$chefEvaluationCount,
        'encadreur_evaluation_count'=>$encadreurEvaluationCount,'evaluation_count'=>$chefEvaluationCount+$encadreurEvaluationCount,
        'encadreur_rotations_count'=>$encadreurRotationsCount,'logbook_count'=>$logbookCount,'attendance_count'=>$attendanceCount,
        'task_count'=>$taskCount,'feedback_count'=>$feedbackCount,'academic_count'=>$academicCount,'application_count'=>$applicationCount,
        'university_result_count'=>$universityResultCount,'student_offer_count'=>$studentOfferCount,'primary_href'=>$primary,'items'=>$items,
        'debug'=>['role'=>$role,'is_chef'=>$isChef,'is_encadreur'=>$isEnc]
    ]);
}catch(Throwable $e){
    error_log('[STAGE NOTIFICATION FEED] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur notifications.',[],500);
}
