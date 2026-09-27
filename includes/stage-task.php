<?php
require_once __DIR__.'/stage-execution.php';
/** Gestion des tâches cliniques rattachées aux affectations de stage. */
if(!function_exists('contextHostEnabled')){
    /** Compatibilité : indique si un établissement d'accueil actif est résolu. */
    function contextHostEnabled():bool{global $pdo;return function_exists('currentEtablissementId')&&(int)currentEtablissementId($pdo)>0;}
}
if(!function_exists('taskHostCanView')){
    /** Définit les rôles autorisés à consulter les tâches hospitalières. */
    function taskHostCanView():bool{return in_array($_SESSION['role_code']??'',['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','EVALUATEUR_CLINIQUE','AUTORITE_HOSPITALIERE'],true);}
}
if(!function_exists('taskHostCanManage')){
    /** Définit les rôles autorisés à créer ou modifier les tâches hospitalières. */
    function taskHostCanManage():bool{return in_array($_SESSION['role_code']??'',['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','EVALUATEUR_CLINIQUE'],true);}
}
if(!function_exists('stageTaskClinicalRole')){
    /** Indique si un rôle est un rôle clinique limité à son périmètre de supervision. */
    function stageTaskClinicalRole(?string $role=null):bool{return in_array($role??($_SESSION['role_code']??''),['ENCADREUR','EVALUATEUR_CLINIQUE'],true);}
}
if(!function_exists('stageTaskTableExists')){
    /** Vérifie la présence d'une table de tâches pour préserver la compatibilité de migration. */
    function stageTaskTableExists(PDO $pdo,string $table):bool{
        $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$s->execute([$table]);return (int)$s->fetchColumn()>0;
    }
}

if(!function_exists('stageTaskColumnExists')){
    /** Vérifie la présence d'une colonne de tâches. */
    function stageTaskColumnExists(PDO $pdo,string $table,string $column):bool{
        $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $s->execute([$table,$column]);
        return (int)$s->fetchColumn()>0;
    }
}
if(!function_exists('stageTaskEnsureSchema')){
    /** Crée ou complète le schéma de tâches lorsque les migrations ne sont pas encore appliquées. */
    function stageTaskEnsureSchema(PDO $pdo):void{
        $pdo->exec("CREATE TABLE IF NOT EXISTS stage_tasks(
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL,
            host_etablissement_id BIGINT NOT NULL,
            assignment_id BIGINT NOT NULL,
            rotation_id BIGINT NULL,
            training_plan_item_id BIGINT NULL,
            student_id BIGINT NOT NULL,
            created_by BIGINT NOT NULL,
            updated_by BIGINT NULL,
            titre VARCHAR(200) NOT NULL,
            description TEXT NULL,
            priorite ENUM('BASSE','NORMALE','HAUTE','URGENTE') NOT NULL DEFAULT 'NORMALE',
            statut ENUM('A_FAIRE','EN_COURS','TERMINEE','A_REVOIR','VALIDEE','ANNULEE') NOT NULL DEFAULT 'A_FAIRE',
            date_echeance DATE NULL,
            commentaire_stagiaire TEXT NULL,
            commentaire_encadreur TEXT NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            validated_at DATETIME NULL,
            validated_by BIGINT NULL,
            cancelled_at DATETIME NULL,
            cancelled_by BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_stage_tasks_uuid(uuid),
            KEY idx_stage_tasks_host(host_etablissement_id),
            KEY idx_stage_tasks_assignment(assignment_id),
            KEY idx_stage_tasks_rotation(rotation_id),
            KEY idx_stage_tasks_student(student_id),
            KEY idx_stage_tasks_status(statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
if(!function_exists('stageTaskUuid')){
    /** Génère un UUID v4 pour une tâche. */
    function stageTaskUuid():string{return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff),random_int(0,0x0fff)|0x4000,random_int(0,0x3fff)|0x8000,random_int(0,0xffff),random_int(0,0xffff));}
}
if(!function_exists('stageTaskAssignmentRow')){
    /** Charge une affectation visible par l'acteur courant avant toute opération sur ses tâches. */
    function stageTaskAssignmentRow(PDO $pdo,int $hostId,int $assignmentId,int $userId,string $role):?array{
        $where="a.id=? AND a.host_etablissement_id=?";$p=[$assignmentId,$hostId];
        if(stageTaskClinicalRole($role)){$where.=" AND EXISTS(SELECT 1 FROM stage_rotations r INNER JOIN stage_rotation_supervisors s ON s.rotation_id=r.id AND s.user_id=? AND s.actif=1 WHERE r.assignment_id=a.id)";$p[]=$userId;}
        $q=$pdo->prepare("SELECT a.id AS assignment_id,a.host_unit_id,a.host_etablissement_id,sp.id AS student_id,sp.stagia_code,CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom) AS student_name
            FROM stage_assignments a
            INNER JOIN stage_admissions ad ON ad.id=a.admission_id
            INNER JOIN stage_reservations sr ON sr.id=ad.reservation_id
            INNER JOIN stage_applications sa ON sa.id=sr.application_id
            INNER JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
            INNER JOIN student_enrollments se ON se.id=sae.enrollment_id
            INNER JOIN student_profiles sp ON sp.id=se.student_id
            WHERE $where LIMIT 1");
        $q->execute($p);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
    }
}
if(!function_exists('stageTaskRow')){
    /** Charge une tâche visible dans le périmètre de l'acteur. */
    function stageTaskRow(PDO $pdo,int $hostId,int $taskId,int $userId,string $role):?array{
        $where="t.id=? AND t.host_etablissement_id=?";$p=[$taskId,$hostId];
        if(stageTaskClinicalRole($role)){$where.=" AND (t.created_by=? OR EXISTS(SELECT 1 FROM stage_rotations r INNER JOIN stage_rotation_supervisors s ON s.rotation_id=r.id AND s.user_id=? AND s.actif=1 WHERE r.id=t.rotation_id))";$p[]=$userId;$p[]=$userId;}
        $s=$pdo->prepare("SELECT t.* FROM stage_tasks t WHERE $where LIMIT 1");$s->execute($p);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:null;
    }
}
if(!function_exists('stageTaskStudentId')){
    /** Résout le profil étudiant associé à un compte. */
    function stageTaskStudentId(PDO $pdo,int $userId):int{$s=$pdo->prepare("SELECT id FROM student_profiles WHERE user_id=? LIMIT 1");$s->execute([$userId]);return (int)$s->fetchColumn();}
}

if(!function_exists('stageTaskStudentList')){
    function stageTaskStudentList(PDO $pdo,int $studentId,array $filters=[]):array{
        stageTaskEnsureSchema($pdo);
        $planTitle='NULL AS plan_item_title';$planJoin='';
        if(stageTaskTableExists($pdo,'stage_training_plan_items')&&stageTaskColumnExists($pdo,'stage_training_plan_items','titre')){
            $planTitle='pi.titre AS plan_item_title';
            $planJoin=' LEFT JOIN stage_training_plan_items pi ON pi.id=t.training_plan_item_id';
        }
        $where=['t.student_id=?'];$params=[$studentId];
        $status=strtoupper(trim((string)($filters['status']??'')));
        if($status!==''){$where[]='t.statut=?';$params[]=$status;}
        $assignmentUuid=trim((string)($filters['assignment_uuid']??''));
        if($assignmentUuid!==''){$where[]='a.uuid=?';$params[]=$assignmentUuid;}

        $s=$pdo->prepare("SELECT t.*,a.uuid assignment_uuid,a.statut assignment_status,r.uuid rotation_uuid,r.sequence_no,r.date_debut rotation_start,r.date_fin rotation_end,e.nom host_name,COALESCE(hur.nom,hua.nom) unit_name,$planTitle
            FROM stage_tasks t JOIN stage_assignments a ON a.id=t.assignment_id JOIN etablissements e ON e.id=t.host_etablissement_id
            LEFT JOIN stage_rotations r ON r.id=t.rotation_id LEFT JOIN host_units hur ON hur.id=r.host_unit_id LEFT JOIN host_units hua ON hua.id=a.host_unit_id
            $planJoin WHERE ".implode(' AND ',$where)."
            ORDER BY FIELD(t.statut,'A_REVOIR','A_FAIRE','EN_COURS','TERMINEE','VALIDEE','ANNULEE'),t.date_echeance IS NULL,t.date_echeance,t.id DESC");
        $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);
        $stats=['total'=>0,'a_faire'=>0,'en_cours'=>0,'terminees'=>0,'a_revoir'=>0,'validees'=>0,'annulees'=>0];$eligible=0;$validated=0;
        $map=['A_FAIRE'=>'a_faire','EN_COURS'=>'en_cours','TERMINEE'=>'terminees','A_REVOIR'=>'a_revoir','VALIDEE'=>'validees','ANNULEE'=>'annulees'];
        foreach($items as &$item){
            foreach(['id','assignment_id','student_id'] as $key)$item[$key]=(int)$item[$key];
            $item['rotation_id']=$item['rotation_id']!==null?(int)$item['rotation_id']:null;
            $item['training_plan_item_id']=$item['training_plan_item_id']!==null?(int)$item['training_plan_item_id']:null;
            $stats['total']++;if(isset($map[$item['statut']]))$stats[$map[$item['statut']]]++;
            if($item['statut']!=='ANNULEE'){$eligible++;if($item['statut']==='VALIDEE')$validated++;}
        }unset($item);
        return ['items'=>$items,'stats'=>$stats,'progress'=>$eligible?round($validated*100/$eligible):0];
    }
}

if(!function_exists('stageTaskStudentChange')){
    function stageTaskStudentChange(PDO $pdo,int $studentId,int $userId,string $identifier,string $action,string $comment=''):array{
        stageTaskEnsureSchema($pdo);$action=strtoupper(trim($action));$comment=trim($comment);$byId=ctype_digit($identifier);
        $s=$pdo->prepare('SELECT t.*,a.statut assignment_status FROM stage_tasks t JOIN stage_assignments a ON a.id=t.assignment_id WHERE '.($byId?'t.id=?':'t.uuid=?').' AND t.student_id=? LIMIT 1');
        $s->execute([$byId?(int)$identifier:$identifier,$studentId]);$task=$s->fetch(PDO::FETCH_ASSOC);
        if(!$task)throw new OutOfBoundsException('Tache introuvable.');
        if($task['assignment_status']==='ANNULEE')throw new RuntimeException('Ce stage a ete annule.');

        $rotation=stageExecutionCurrentRotation($pdo,(int)$task['assignment_id']);
        if(!$rotation)throw new RuntimeException("Aucune rotation active aujourd'hui pour cette tache.");
        if($task['rotation_id']!==null&&(int)$task['rotation_id']!==(int)$rotation['rotation_id'])throw new RuntimeException("Cette tache n'appartient pas a la rotation active.");

        if($action==='START'){
            if(!in_array($task['statut'],['A_FAIRE','A_REVOIR'],true))throw new RuntimeException('Cette tache ne peut pas etre demarree.');
            $s=$pdo->prepare("UPDATE stage_tasks SET statut='EN_COURS',commentaire_stagiaire=?,started_at=COALESCE(started_at,NOW()) WHERE id=? AND student_id=?");
            $s->execute([$comment,(int)$task['id'],$studentId]);$message='Tache demarree.';$status='EN_COURS';
        }elseif($action==='COMPLETE'){
            if($task['statut']!=='EN_COURS')throw new RuntimeException("Demarrez d'abord la tache.");
            $s=$pdo->prepare("UPDATE stage_tasks SET statut='TERMINEE',commentaire_stagiaire=?,completed_at=NOW() WHERE id=? AND student_id=?");
            $s->execute([$comment,(int)$task['id'],$studentId]);$message="Tache envoyee a l'encadreur.";$status='TERMINEE';
        }elseif($action==='COMMENT'){
            if(in_array($task['statut'],['VALIDEE','ANNULEE'],true))throw new RuntimeException('Cette tache ne peut plus etre modifiee.');
            if($comment==='')throw new RuntimeException('Le commentaire est obligatoire.');
            $s=$pdo->prepare('UPDATE stage_tasks SET commentaire_stagiaire=? WHERE id=? AND student_id=?');
            $s->execute([$comment,(int)$task['id'],$studentId]);$message='Commentaire enregistre.';$status=$task['statut'];
        }else throw new InvalidArgumentException('Action inconnue.');
        return ['message'=>$message,'task_uuid'=>$task['uuid'],'status'=>$status];
    }
}
