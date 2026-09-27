<?php
require_once __DIR__.'/stage-payment.php';
require_once __DIR__.'/communication-native.php';

/** Génère un UUID v4 pour une nouvelle affectation de stage. */
function stageAssignmentUuidV4():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

/** Vérifie strictement le format calendaire YYYY-MM-DD. */
function stageAssignmentValidDate(string $v):bool{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    return $d&&$d->format('Y-m-d')===$v;
}

/** Détecte la colonne de code rôle compatible avec le schéma de base courant. */
function stageAssignmentRoleExpr(PDO $pdo):string{
    static $expr=null;
    if($expr!==null)return $expr;
    try{
        $cols=$pdo->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_ASSOC);
        $c=[];foreach($cols as $x)$c[$x['Field']]=true;
        if(isset($c['code'],$c['role_code']))return $expr="COALESCE(NULLIF(r.code,''),NULLIF(r.role_code,''))";
        if(isset($c['code']))return $expr='r.code';
        if(isset($c['role_code']))return $expr='r.role_code';
    }catch(Throwable $e){}
    return $expr="''";
}

/** Construit les coordinations accessibles par l'acteur à partir de ses affectations actives. */
function stageAssignmentActorCoordScope(PDO $pdo,int $hostId,int $actorId,array $codes):array{
    if(!$codes)return [];

    $codes=array_values(array_unique(array_map(fn($v)=>strtoupper(trim((string)$v)),$codes)));
    $expr=stageAssignmentRoleExpr($pdo);
    $in=implode(',',array_fill(0,count($codes),'?'));

    $s=$pdo->prepare("
        SELECT ra.scope_type,ra.scope_id
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=?
          AND UPPER($expr) IN($in)
          AND ra.actif=1
          AND (ra.revoked_at IS NULL)
          AND (ra.etablissement_id=? OR ra.etablissement_id IS NULL)
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
    ");
    $s->execute(array_merge([$actorId],$codes,[$hostId]));
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);

    /* Périmètre établissement : le rôle couvre toutes les coordinations de cet hôpital. */
    foreach($rows as $r)
        if(strtoupper(trim((string)($r['scope_type']??'')))==='ORGANIZATION')
            return [-1];

    $ids=[];
    foreach($rows as $r){
        if(strtoupper(trim((string)($r['scope_type']??'')))!=='UNIT')continue;
        $id=(int)($r['scope_id']??0);
        if($id>0)$ids[$id]=$id;
    }
    if(!$ids)return [];

    /*
     * Un CHEF_SERVICE peut être rattaché au département/coordination lui-même
     * ou à un service descendant. On remonte donc la hiérarchie afin de retrouver
     * la coordination d'origine sans dépendre d'un seul niveau parent.
     */
    $coordIds=[];
    foreach(array_values($ids) as $id){
        $current=$id;
        for($depth=0;$depth<8 && $current>0;$depth++){
            $s=$pdo->prepare("
                SELECT id,parent_id
                FROM host_units
                WHERE id=? AND host_etablissement_id=? AND actif=1
                LIMIT 1
            ");
            $s->execute([$current,$hostId]);
            $u=$s->fetch(PDO::FETCH_ASSOC);
            if(!$u)break;

            $coordIds[(int)$u['id']]=(int)$u['id'];
            $parent=(int)($u['parent_id']??0);
            if($parent<=0)break;
            $current=$parent;
        }
    }
    return array_values($coordIds);
}

/** Vérifie si l'acteur peut affecter un stagiaire dans la coordination demandée. */
function stageAssignmentActorCanUseCoordination(PDO $pdo,int $hostId,int $actorId,int $coordId):bool{
    if(!$hostId||!$actorId||!$coordId)return false;

    /*
     * Ne dépend pas de $_SESSION['role_code'] : la source de vérité est
     * role_assignments. Cela évite qu'un compte CHEF_SERVICE valide en base
     * soit refusé parce que la session contient un ancien code de rôle.
     */
    $expr=stageAssignmentRoleExpr($pdo);
    $s=$pdo->prepare("
        SELECT DISTINCT UPPER($expr) role_code
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=?
          AND ra.actif=1
          AND (ra.revoked_at IS NULL)
          AND (ra.etablissement_id=? OR ra.etablissement_id IS NULL)
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
    ");
    $s->execute([$actorId,$hostId]);
    $roles=array_values(array_filter(array_map(fn($v)=>strtoupper(trim((string)$v)),$s->fetchAll(PDO::FETCH_COLUMN))));

    if(in_array('ADMIN_ACCUEIL',$roles,true))return true;

    $codes=[];
    if(in_array('COORDINATEUR_STAGES',$roles,true))$codes[]='COORDINATEUR_STAGES';

    $chefCodes=['CHEF_SERVICE','CHEF_DE_SERVICE','ROLE_CHEF_SERVICE','ROLE_CHEF_DE_SERVICE','ENCADREUR_CHEF_SERVICE','RESPONSABLE_SERVICE'];
    foreach($roles as $r){
        if(in_array($r,$chefCodes,true)||preg_match('/(?:ROLE_)?CHEF_(?:DE_)?SERVICE$/',$r)){
            $codes=array_merge($codes,$chefCodes);
            break;
        }
    }
    if(!$codes)return false;

    $coordIds=stageAssignmentActorCoordScope($pdo,$hostId,$actorId,array_values(array_unique($codes)));
    return in_array(-1,$coordIds,true)||in_array($coordId,$coordIds,true);
}

/** Interroge la règle de paiement lorsque le module financier est disponible. */
function stageAssignmentPaymentCheck(PDO $pdo,int $reservationId):array{
    if(!function_exists('stagePaymentAllowed'))
        return ['allowed'=>true,'required'=>false,'status'=>'NON_REQUIS'];

    $p=stagePaymentAllowed($pdo,$reservationId);
    if(!is_array($p))return ['allowed'=>false,'required'=>true,'status'=>'INCONNU'];
    return $p;
}

/** Construit un message utilisateur détaillant le solde restant qui bloque une affectation. */
function stageAssignmentPaymentError(array $payment):string{
    $msg='Affectation impossible : paiement requis.';
    $invoice=$payment['invoice']??null;

    if($invoice){
        $remaining=max(0,(float)($payment['remaining']??0));
        $msg.=' Facture '.$invoice['reference'].' — reste à payer : '.
            number_format($remaining,2,',',' ').' '.($invoice['devise']??'USD').'.';
    }

    return $msg;
}

/**
 * Crée ou déplace une affectation dans une transaction : périmètre, paiement,
 * dates, service, chevauchement et capacité sont validés avant l'écriture.
 */
function stageAssignmentSaveOne(PDO $pdo,int $hostId,int $actorId,array $data):array{
    $id=(int)($data['id']??0);
    $admissionId=(int)($data['admission_id']??0);
    $unitId=(int)($data['host_unit_id']??0);
    $dateDebut=trim((string)($data['date_debut']??''));
    $dateFin=trim((string)($data['date_fin']??''));
    $observation=trim((string)($data['observation']??''));

    if(!$hostId||!$actorId)throw new RuntimeException('Contexte utilisateur invalide.');
    if(!$admissionId||!$unitId)throw new RuntimeException('Stagiaire ou service invalide.');
    if(!stageAssignmentValidDate($dateDebut)||!stageAssignmentValidDate($dateFin))throw new RuntimeException('Les dates sont invalides.');
    if($dateDebut>$dateFin)throw new RuntimeException('La date de fin doit suivre la date de début.');

    $pdo->beginTransaction();

    try{
        $s=$pdo->prepare("
            SELECT ad.id,ad.statut,ad.coordination_unit_id,ad.host_etablissement_id,
                   sr.id reservation_id,
                   c.id campaign_id,c.date_debut campaign_start,c.date_fin campaign_end,
                   cu.nom coordination_name,sp.user_id student_user_id
            FROM stage_admissions ad
            JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'
            JOIN stage_applications sa ON sa.id=sr.application_id
                AND sa.host_etablissement_id=ad.host_etablissement_id
                AND sa.statut='ACCEPTEE'
            JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
            JOIN student_enrollments se ON se.id=sae.enrollment_id
            JOIN student_profiles sp ON sp.id=se.student_id
            JOIN stage_placements pl ON pl.id=ad.placement_id
                AND pl.reservation_id=sr.id
                AND pl.statut='CONFIRME'
            JOIN stage_campaigns c ON c.id=sa.campaign_id
            LEFT JOIN host_units cu ON cu.id=ad.coordination_unit_id
            WHERE ad.id=? AND ad.host_etablissement_id=?
              AND ad.statut IN('ADMIS','EN_COURS')
            LIMIT 1 FOR UPDATE
        ");
        $s->execute([$admissionId,$hostId]);
        $admission=$s->fetch(PDO::FETCH_ASSOC);

        if(!$admission)throw new RuntimeException('Stagiaire non admissible à une affectation.');
        if(empty($admission['coordination_unit_id']))throw new RuntimeException("Envoyez d'abord ce stagiaire vers une coordination.");

        $coordId=(int)$admission['coordination_unit_id'];

        if(!stageAssignmentActorCanUseCoordination($pdo,$hostId,$actorId,$coordId))
            throw new RuntimeException("Vous n'êtes pas autorisé à affecter les stagiaires de cette coordination.");

        $payment=stageAssignmentPaymentCheck($pdo,(int)$admission['reservation_id']);
        if(empty($payment['allowed']))throw new RuntimeException(stageAssignmentPaymentError($payment));

        if(!empty($admission['campaign_start'])&&$dateDebut<$admission['campaign_start'])
            throw new RuntimeException('La date de début précède la session.');
        if(!empty($admission['campaign_end'])&&$dateFin>$admission['campaign_end'])
            throw new RuntimeException('La date de fin dépasse la session.');

                    $s=$pdo->prepare("
                SELECT id,code,nom,type,capacite,parent_id
                FROM host_units
                WHERE id=? AND host_etablissement_id=? AND actif=1
                AND parent_id=?
                AND UPPER(type) IN('SERVICE','UNITE','UNITÉ')
                LIMIT 1 FOR UPDATE
            ");
            $s->execute([$unitId,$hostId,$coordId]);
            $unit=$s->fetch(PDO::FETCH_ASSOC);

            if(!$unit)
                throw new RuntimeException('Service invalide : sélectionnez un service actif rattaché à cette coordination/département.');

        if($id){
            $s=$pdo->prepare("
                SELECT id,uuid,statut
                FROM stage_assignments
                WHERE id=? AND admission_id=? AND host_etablissement_id=?
                LIMIT 1 FOR UPDATE
            ");
            $s->execute([$id,$admissionId,$hostId]);
            $existing=$s->fetch(PDO::FETCH_ASSOC);

            if(!$existing)throw new RuntimeException('Affectation introuvable.');
            if($existing['statut']==='ACTIVE')throw new RuntimeException('Une affectation active ne peut plus être déplacée.');
            $assignmentUuid=(string)$existing['uuid'];
        }

        $s=$pdo->prepare("
            SELECT id
            FROM stage_assignments
            WHERE admission_id=? AND host_etablissement_id=? AND id<>?
              AND statut IN('PLANIFIEE','ACTIVE')
              AND date_debut<=?
              AND COALESCE(date_fin,'9999-12-31')>=?
            LIMIT 1
        ");
        $s->execute([$admissionId,$hostId,$id,$dateFin,$dateDebut]);

        if($s->fetchColumn())throw new RuntimeException('Ce stagiaire possède déjà une affectation sur cette période.');

        if($unit['capacite']!==null&&(int)$unit['capacite']>0){
            $s=$pdo->prepare("
                SELECT COUNT(*)
                FROM stage_assignments
                WHERE host_unit_id=? AND host_etablissement_id=? AND id<>?
                  AND statut IN('PLANIFIEE','ACTIVE')
                  AND date_debut<=?
                  AND COALESCE(date_fin,'9999-12-31')>=?
            ");
            $s->execute([$unitId,$hostId,$id,$dateFin,$dateDebut]);

            if((int)$s->fetchColumn()>=(int)$unit['capacite'])
                throw new RuntimeException('La capacité de ce service est atteinte.');
        }

        $today=(string)$pdo->query("SELECT CURDATE()")->fetchColumn();
        $statut=$dateDebut>$today?'PLANIFIEE':'ACTIVE';

        if($id){
            $s=$pdo->prepare("
                UPDATE stage_assignments
                SET host_unit_id=?,statut=?,date_debut=?,date_fin=?,observation=?
                WHERE id=? AND host_etablissement_id=?
            ");
            $s->execute([$unitId,$statut,$dateDebut,$dateFin,$observation?:null,$id,$hostId]);
            $assignmentId=$id;
        }else{
            $assignmentUuid=stageAssignmentUuidV4();
            $s=$pdo->prepare("
                INSERT INTO stage_assignments(
                    uuid,admission_id,host_unit_id,host_etablissement_id,statut,
                    date_debut,date_fin,observation,assigned_by,assigned_at
                )VALUES(?,?,?,?,?,?,?,?,?,NOW())
            ");
            $s->execute([
                $assignmentUuid,$admissionId,$unitId,$hostId,$statut,
                $dateDebut,$dateFin,$observation?:null,$actorId
            ]);

            $assignmentId=(int)$pdo->lastInsertId();
            if($assignmentId<=0)throw new RuntimeException("stage_assignments.id n'a pas généré d'identifiant valide.");
        }

        if($statut==='ACTIVE'){
            $s=$pdo->prepare("
                UPDATE stage_admissions
                SET statut='EN_COURS'
                WHERE id=? AND host_etablissement_id=? AND statut='ADMIS'
            ");
            $s->execute([$admissionId,$hostId]);
        }

        try{
            $notificationType=$id?'stage.assignment.updated':'stage.assignment.created';
            $notificationTitle=$id?'Votre affectation a ete modifiee':'Votre affectation de stage est confirmee';
            communicationNotifier(
                $pdo,(int)$admission['student_user_id'],$hostId,$notificationType,$notificationTitle,
                'Service : '.$unit['nom'].' - du '.$dateDebut.' au '.$dateFin,
                '/views/espace-etudiant/mes-stages.php',
                ['assignment_uuid'=>$assignmentUuid,'action'=>[
                    'type'=>'internship_assignment','target_id'=>$assignmentUuid,
                    'label'=>'Voir mon affectation','title'=>$unit['nom'],
                    'metadata'=>['status'=>$statut]
                ]]
            );
        }catch(Throwable $notificationError){
            error_log('[STAGE ASSIGNMENT NOTIFICATION] '.$notificationError->getMessage());
        }

        $pdo->commit();

        return [
            'id'=>$assignmentId,
            'uuid'=>$assignmentUuid,
            'statut'=>$statut,
            'coordination'=>[
                'id'=>$coordId,
                'nom'=>$admission['coordination_name']
            ],
            'unit'=>[
                'id'=>(int)$unit['id'],
                'code'=>$unit['code'],
                'nom'=>$unit['nom'],
                'type'=>$unit['type']
            ],
            'payment'=>[
                'required'=>$payment['required']??false,
                'status'=>$payment['status']??'NON_REQUIS'
            ],
            'message'=>$statut==='ACTIVE'
                ?'Stagiaire affecté à '.$unit['nom'].'. Le stage est maintenant en cours.'
                :'Affectation planifiée avec succès.'
        ];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
