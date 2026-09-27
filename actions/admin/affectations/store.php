<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$ctx=exigerAdministrationRolesAjax($pdo);
verifyAjaxCsrf();

if(function_exists('contextPermission')&&!contextPermission('user.role.assign'))
    jsonResponse(false,'Permission insuffisante.',[],403);

function postInt(array $names):int{
    foreach($names as $n)if(isset($_POST[$n])&&(int)$_POST[$n]>0)return (int)$_POST[$n];
    return 0;
}

function hostUnit(PDO $pdo,int $id,int $eid,array $types=[]):?array{
    if(!$id||!$eid)return null;

    $sql="SELECT id,code,nom,type,parent_id FROM host_units WHERE id=? AND host_etablissement_id=? AND actif=1";
    $p=[$id,$eid];

    if($types){
        $sql.=" AND UPPER(type) IN(".implode(',',array_fill(0,count($types),'?')).")";
        $p=array_merge($p,$types);
    }

    $s=$pdo->prepare($sql." LIMIT 1");
    $s->execute($p);
    $r=$s->fetch(PDO::FETCH_ASSOC);

    return $r?:null;
}

function requireHostUnit(PDO $pdo,int $id,int $eid,string $msg,array $types=[]):array{
    $u=hostUnit($pdo,$id,$eid,$types);
    if(!$u)throw new RuntimeException($msg);
    return $u;
}

$actor=$_SESSION['role_code']??'';
$super=(bool)$ctx['super'];
$localEid=$super?0:(int)$ctx['etablissement_id'];

$userId=(int)($_POST['user_id']??0);
$roleId=(int)($_POST['role_id']??0);
$scope=trim($_POST['scope_type']??'');
$entity=trim($_POST['scope_entity']??'');
$scopeId=(int)($_POST['scope_id']??0)?:null;
$etabId=(int)($_POST['etablissement_id']??0)?:null;

$departementId=postInt(['departement_id','department_id','host_department_id']);
$serviceId=postInt(['service_id','host_service_id','host_unit_id']);

$principal=isset($_POST['principal'])?1:0;
$startsAt=trim($_POST['starts_at']??'')?:null;
$endsAt=trim($_POST['ends_at']??'')?:null;

if($startsAt)$startsAt=str_replace('T',' ',$startsAt).(strlen($startsAt)===16?':00':'');
if($endsAt)$endsAt=str_replace('T',' ',$endsAt).(strlen($endsAt)===16?':00':'');

if(!$super&&!$localEid)jsonResponse(false,'Aucun établissement associé.',[],403);
if(!$userId||!$roleId)jsonResponse(false,'Utilisateur et rôle sont obligatoires.',[],422);
if($startsAt&&$endsAt&&strtotime($endsAt)<strtotime($startsAt))
    jsonResponse(false,'La date de fin doit être postérieure à la date de début.',[],422);

$allowed=$actor==='ADMIN_ACCUEIL'
    ?['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','POINTEUR','CHEF_SERVICE','EVALUATEUR_CLINIQUE','GESTIONNAIRE_FINANCIER_HOSPITALIER']
    :['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'];

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT id FROM users WHERE id=? AND statut_compte<>'SUSPENDU' LIMIT 1 FOR UPDATE");
    $s->execute([$userId]);
    if(!$s->fetchColumn())throw new RuntimeException('Utilisateur invalide ou suspendu.');

    $s=$pdo->prepare("SELECT id,code,actif,systeme,etablissement_id FROM roles WHERE id=? LIMIT 1");
    $s->execute([$roleId]);
    $role=$s->fetch(PDO::FETCH_ASSOC);

    if(!$role||!(int)$role['actif'])throw new RuntimeException('Rôle invalide ou inactif.');

    $roleCode=(string)$role['code'];

    if(!$super){
        $roleLocal=(int)$role['systeme']===0&&(int)$role['etablissement_id']===$localEid;

        if(!$roleLocal&&!in_array($roleCode,$allowed,true))
            throw new RuntimeException('Ce rôle ne peut pas être attribué dans votre établissement.');

        $s=$pdo->prepare("SELECT 1 FROM etablissement_users WHERE user_id=? AND etablissement_id=? LIMIT 1");
        $s->execute([$userId,$localEid]);
        if(!$s->fetchColumn())throw new RuntimeException('Utilisateur hors de votre établissement.');

        $etabId=$localEid;

        if($actor==='ADMIN_ACCUEIL'&&$roleCode==='CHEF_SERVICE'){
            if(!$serviceId&&$scope==='UNIT'&&$entity==='HOST_UNIT'&&$scopeId){
                $u=hostUnit($pdo,(int)$scopeId,$localEid);
                if($u){
                    $t=strtoupper((string)$u['type']);
                    if(in_array($t,['SERVICE','UNITE','UNITÉ'],true))$serviceId=(int)$scopeId;
                    else $departementId=(int)$scopeId;
                }
            }

            if($serviceId){
                $service=requireHostUnit(
                    $pdo,
                    $serviceId,
                    $localEid,
                    'Service / unité invalide ou hors de votre établissement.',
                    ['SERVICE','UNITE','UNITÉ']
                );

                if($departementId&&((int)$service['parent_id']!==$departementId))
                    throw new RuntimeException('Le service sélectionné n’appartient pas au département choisi.');

                $scope='UNIT';
                $entity='HOST_UNIT';
                $scopeId=$serviceId;
            }elseif($departementId){
                requireHostUnit(
                    $pdo,
                    $departementId,
                    $localEid,
                    'Département / coordination invalide ou hors de votre établissement.',
                    ['DEPARTEMENT','DÉPARTEMENT','DEPARTMENT','COORDINATION','DIRECTION']
                );

                $scope='UNIT';
                $entity='HOST_UNIT';
                $scopeId=$departementId;
            }else{
                $scope='ORGANIZATION';
                $entity='ESTABLISHMENT';
                $scopeId=$localEid;
            }
        }elseif($actor==='ADMIN_ACCUEIL'&&$roleCode==='EVALUATEUR_CLINIQUE'){
            if(!$serviceId&&$scopeId)$serviceId=(int)$scopeId;
            if(!$serviceId)throw new RuntimeException('Sélectionnez le service / l’unité de ce rôle clinique.');

            requireHostUnit(
                $pdo,
                $serviceId,
                $localEid,
                'Service / unité invalide ou hors de votre établissement.',
                ['SERVICE','UNITE','UNITÉ']
            );

            $scope='UNIT';
            $entity='HOST_UNIT';
            $scopeId=$serviceId;
            $principal=0;

            $s=$pdo->prepare("
                SELECT 1
                FROM role_assignments ra
                JOIN roles rr ON rr.id=ra.role_id
                WHERE ra.user_id=? AND rr.code='ENCADREUR'
                  AND ra.etablissement_id=? AND ra.actif=1
                LIMIT 1
            ");
            $s->execute([$userId,$localEid]);

            if(!$s->fetchColumn())
                throw new RuntimeException('Attribuez d’abord le rôle principal ENCADREUR à cet utilisateur.');
        }elseif($actor==='ADMIN_ACCUEIL'&&$roleCode==='POINTEUR'&&$scope==='UNIT'){
            if(!$serviceId&&$scopeId)$serviceId=(int)$scopeId;
            if(!$serviceId)throw new RuntimeException('Sélectionnez le service / l’unité du pointeur.');

            requireHostUnit(
                $pdo,
                $serviceId,
                $localEid,
                'Service / unité invalide ou hors de votre établissement.',
                ['SERVICE','UNITE','UNITÉ']
            );

            $scope='UNIT';
            $entity='HOST_UNIT';
            $scopeId=$serviceId;
        }else{
            $scope='ORGANIZATION';
            $entity='ESTABLISHMENT';
            $scopeId=$localEid;
        }
    }else{
        if($roleCode==='SUPER_ADMIN'){
            $scope='PLATFORM';
            $entity='PLATFORM';
            $scopeId=null;
            $etabId=null;
        }elseif($roleCode==='MINISTERE'){
            if(!$etabId)throw new RuntimeException('Sélectionnez obligatoirement le ministère représenté.');

            $s=$pdo->prepare("
                SELECT id FROM etablissements
                WHERE id=? AND type_etablissement='MINISTERE'
                  AND statut IN('VALIDE','ACTIF')
                LIMIT 1
            ");
            $s->execute([$etabId]);

            if(!$s->fetchColumn())throw new RuntimeException('Le ministère sélectionné est invalide ou inactif.');

            $scope='ORGANIZATION';
            $entity='ESTABLISHMENT';
            $scopeId=$etabId;
        }

        if(!in_array($scope,['PLATFORM','ORGANIZATION','UNIT','CAMPAIGN','INTERNSHIP','SELF'],true))
            throw new RuntimeException('Périmètre invalide.');

        if($scope==='PLATFORM'){
            $entity='PLATFORM';
            $scopeId=null;
            $etabId=null;
        }elseif($scope==='SELF'){
            $entity='USER';
            $scopeId=$userId;
            $etabId=null;
        }elseif($scope==='ORGANIZATION'){
            if(!$etabId)throw new RuntimeException('Sélectionnez un établissement.');
            $entity='ESTABLISHMENT';
            $scopeId=$etabId;
        }elseif($scope==='UNIT'){
            if(!$etabId||!$scopeId)throw new RuntimeException('Établissement et unité obligatoires.');

            $map=[
                'ACADEMIC_UNIT'=>['facultes','etablissement_id'],
                'DEPARTMENT'=>['departements','etablissement_id'],
                'PROGRAM'=>['filieres','etablissement_id'],
                'HOST_UNIT'=>['host_units','host_etablissement_id']
            ];

            if(!isset($map[$entity]))throw new RuntimeException('Type d’unité invalide.');

            [$t,$c]=$map[$entity];
            $s=$pdo->prepare("SELECT 1 FROM $t WHERE id=? AND $c=? AND actif=1 LIMIT 1");
            $s->execute([$scopeId,$etabId]);

            if(!$s->fetchColumn())throw new RuntimeException('Unité ou contexte invalide.');
        }elseif($scope==='CAMPAIGN'){
            if(!$scopeId)throw new RuntimeException('Sélectionnez une campagne.');

            $entity='STAGE_CAMPAIGN';
            $s=$pdo->prepare("SELECT owner_etablissement_id FROM stage_campaigns WHERE id=? LIMIT 1");
            $s->execute([$scopeId]);
            $etabId=(int)$s->fetchColumn();

            if(!$etabId)throw new RuntimeException('Campagne introuvable.');
        }elseif($scope==='INTERNSHIP'){
            if(!$scopeId)throw new RuntimeException('Sélectionnez un stage.');

            $entity='STAGE_ASSIGNMENT';
            $s=$pdo->prepare("SELECT host_etablissement_id FROM stage_assignments WHERE id=? LIMIT 1");
            $s->execute([$scopeId]);
            $etabId=(int)$s->fetchColumn();

            if(!$etabId)throw new RuntimeException('Stage introuvable.');
        }
    }

    $s=$pdo->prepare("
        SELECT id FROM role_assignments
        WHERE user_id=? AND role_id=? AND scope_type=?
          AND COALESCE(scope_entity,'')=COALESCE(?,'')
          AND COALESCE(scope_id,0)=COALESCE(?,0)
          AND actif=1
        LIMIT 1
    ");
    $s->execute([$userId,$roleId,$scope,$entity,$scopeId]);

    if($s->fetchColumn())throw new RuntimeException('Cette affectation active existe déjà.');

    if(!$super&&$roleCode==='POINTEUR'&&$scope==='UNIT'){
        $pdo->prepare("
            UPDATE role_assignments
            SET actif=0,principal=0,revoked_at=NOW(),revoked_by=?,
                revocation_reason='Remplacée par une affectation POINTEUR limitée à une unité.'
            WHERE user_id=? AND role_id=? AND etablissement_id=?
              AND scope_type='ORGANIZATION' AND actif=1
        ")->execute([$_SESSION['user_id']??null,$userId,$roleId,$localEid]);
    }

    $whereP=$super?'user_id=?':'user_id=? AND etablissement_id=?';
    $pp=$super?[$userId]:[$userId,$localEid];

    $s=$pdo->prepare("SELECT COUNT(*) FROM role_assignments WHERE $whereP AND actif=1 AND principal=1");
    $s->execute($pp);

    if(!(int)$s->fetchColumn())$principal=1;

    if($principal){
        $s=$pdo->prepare("UPDATE role_assignments SET principal=0 WHERE $whereP");
        $s->execute($pp);
    }

    $pdo->prepare("
        INSERT INTO role_assignments(
            user_id,role_id,scope_type,scope_entity,scope_id,
            etablissement_id,principal,actif,starts_at,ends_at,assigned_by
        ) VALUES(?,?,?,?,?,?,?,1,?,?,?)
    ")->execute([
        $userId,
        $roleId,
        $scope,
        $entity,
        $scopeId,
        $etabId,
        $principal,
        $startsAt,
        $endsAt,
        $_SESSION['user_id']??null
    ]);

    $id=(int)$pdo->lastInsertId();

    if($principal)
        $pdo->prepare("UPDATE users SET role_id=? WHERE id=?")->execute([$roleId,$userId]);

    if($super&&$etabId){
        $appartenancePrincipale=$roleCode==='MINISTERE'?1:0;
        $fonction=$roleCode==='MINISTERE'?'Responsable ministériel':null;

        $pdo->prepare("
            INSERT INTO etablissement_users(etablissement_id,user_id,fonction,principal)
            VALUES(?,?,?,?)
            ON DUPLICATE KEY UPDATE
                fonction=COALESCE(fonction,VALUES(fonction)),
                principal=GREATEST(COALESCE(principal,0),VALUES(principal))
        ")->execute([$etabId,$userId,$fonction,$appartenancePrincipale]);
    }

    $pdo->commit();

    jsonResponse(true,'Affectation de rôle enregistrée.',[
        'id'=>$id,
        'scope_type'=>$scope,
        'scope_entity'=>$entity,
        'scope_id'=>$scopeId,
        'etablissement_id'=>$etabId
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}