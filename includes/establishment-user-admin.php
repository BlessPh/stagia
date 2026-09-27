<?php
/**
 * STAGIA-RDC
 * Administration locale des utilisateurs d'un établissement.
 */

function establishmentUserAdminContext(PDO $pdo):array{
    $eid=(int)($_SESSION['etablissement_id']??0);
    if(!$eid)throw new RuntimeException("Aucun établissement n'est associé au contexte courant.");

    $s=$pdo->prepare("
        SELECT e.id,e.code,e.nom,e.type_etablissement,e.statut,
               et.libelle type_nom,et.academic_enabled,et.host_enabled,et.actif type_actif
        FROM etablissements e
        JOIN establishment_types et ON et.code=e.type_etablissement
        WHERE e.id=? LIMIT 1
    ");
    $s->execute([$eid]);
    $ctx=$s->fetch(PDO::FETCH_ASSOC);

    if(!$ctx)throw new RuntimeException("L'établissement du contexte est introuvable.");
    if(($ctx['statut']??'')!=='VALIDE'||!(int)$ctx['type_actif'])
        throw new RuntimeException("L'établissement n'est pas actuellement autorisé à administrer des comptes.");

    return $ctx;
}

function establishmentAssignableRoleCodes(array $ctx):array{
    $codes=[];

    if((int)($ctx['academic_enabled']??0)){
        $codes[]='ADMIN_ETABLISSEMENT';
        $codes[]='RESPONSABLE_PEDAGOGIQUE';
    }

    if((int)($ctx['host_enabled']??0)){
        $codes[]='ADMIN_ACCUEIL';
        $codes[]='COORDINATEUR_STAGES';
        $codes[]='CHEF_SERVICE';
        $codes[]='ENCADREUR';
        $codes[]='POINTEUR';
        $codes[]='EVALUATEUR_CLINIQUE';
        $codes[]='GESTIONNAIRE_FINANCIER_HOSPITALIER';
    }

    return array_values(array_unique($codes));
}

function establishmentAssignableRoles(PDO $pdo,array $ctx):array{
    $codes=establishmentAssignableRoleCodes($ctx);
    $eid=(int)($ctx['id']??0);

    if(!$codes)return [];

    $marks=implode(',',array_fill(0,count($codes),'?'));

    $s=$pdo->prepare("
        SELECT id,code,nom,description,systeme,etablissement_id
        FROM roles
        WHERE actif=1
          AND (
              code IN($marks)
              OR (systeme=0 AND etablissement_id=?)
          )
        ORDER BY
            FIELD(
                code,
                'ADMIN_ETABLISSEMENT',
                'ADMIN_ACCUEIL',
                'RESPONSABLE_PEDAGOGIQUE',
                'COORDINATEUR_STAGES',
                'CHEF_SERVICE',
                'ENCADREUR',
                'POINTEUR',
                'EVALUATEUR_CLINIQUE',
                'GESTIONNAIRE_FINANCIER_HOSPITALIER'
            ),
            nom
    ");
    $s->execute(array_merge($codes,[$eid]));

    return array_map(fn($r)=>[
        'id'=>(int)$r['id'],
        'code'=>$r['code'],
        'nom'=>$r['nom'],
        'description'=>$r['description']??'',
        'systeme'=>(int)($r['systeme']??1),
        'etablissement_id'=>$r['etablissement_id']!==null?(int)$r['etablissement_id']:null
    ],$s->fetchAll(PDO::FETCH_ASSOC));
}

function requireEstablishmentPermission(string $permission):void{
    if(!function_exists('contextPermission')||!contextPermission($permission)){
        http_response_code(403);
        exit('Accès refusé.');
    }
}

function requireEstablishmentAjaxPermission(string $permission):void{
    if(!function_exists('contextPermission')||!contextPermission($permission))
        jsonResponse(false,"Vous n'avez pas l'autorisation requise.",[],403);
}

function establishmentUserBelongs(PDO $pdo,int $userId,int $eid,bool $lock=false):?array{
    $sql="
        SELECT u.id,u.role_id,u.nom,u.postnom,u.prenom,u.email,u.identifiant,
               u.telephone,u.actif,u.statut_compte,eu.fonction,eu.principal,
               (
                   SELECT COUNT(DISTINCT eu2.etablissement_id)
                   FROM etablissement_users eu2
                   WHERE eu2.user_id=u.id
               ) establishment_count
        FROM users u
        JOIN etablissement_users eu ON eu.user_id=u.id AND eu.etablissement_id=?
        WHERE u.id=? LIMIT 1
    ";

    if($lock)$sql.=' FOR UPDATE';

    $s=$pdo->prepare($sql);
    $s->execute([$eid,$userId]);
    $row=$s->fetch(PDO::FETCH_ASSOC);

    return $row?:null;
}

function establishmentRoleAllowed(PDO $pdo,int $roleId,array $ctx):?array{
    $codes=establishmentAssignableRoleCodes($ctx);
    $eid=(int)($ctx['id']??0);

    if(!$codes)return null;

    $marks=implode(',',array_fill(0,count($codes),'?'));

    $s=$pdo->prepare("
        SELECT id,code,nom,description,systeme,etablissement_id
        FROM roles
        WHERE id=? AND actif=1
          AND (
              code IN($marks)
              OR (systeme=0 AND etablissement_id=?)
          )
        LIMIT 1
    ");
    $s->execute(array_merge([$roleId],$codes,[$eid]));

    $role=$s->fetch(PDO::FETCH_ASSOC);
    return $role?:null;
}

function generateEstablishmentUserIdentifier(PDO $pdo,string $establishmentCode):string{
    $base=strtolower(preg_replace('/[^a-z0-9]+/i','',$establishmentCode));
    if($base==='')$base='etb';

    $s=$pdo->prepare("SELECT 1 FROM users WHERE identifiant=? LIMIT 1");

    for($i=1;$i<=99999;$i++){
        $candidate=$base.'.u'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
        $s->execute([$candidate]);
        if(!$s->fetchColumn())return $candidate;
    }

    throw new RuntimeException("Impossible de générer un identifiant utilisateur.");
}

function activationExpiryHours(PDO $pdo):int{
    try{
        $s=$pdo->prepare("
            SELECT setting_value
            FROM system_settings
            WHERE setting_key='security.activation_expiry_hours'
            LIMIT 1
        ");
        $s->execute();
        $hours=(int)$s->fetchColumn();
        if($hours>=1&&$hours<=720)return $hours;
    }catch(Throwable $ignored){}

    return 48;
}

function establishmentAdminRoleCodes(array $ctx):array{
    $codes=[];

    if((int)($ctx['academic_enabled']??0))$codes[]='ADMIN_ETABLISSEMENT';
    if((int)($ctx['host_enabled']??0))$codes[]='ADMIN_ACCUEIL';

    return $codes;
}

function isLastActiveEstablishmentAdmin(PDO $pdo,int $targetUserId,array $ctx):bool{
    $adminCodes=establishmentAdminRoleCodes($ctx);
    if(!$adminCodes)return false;

    $marks=implode(',',array_fill(0,count($adminCodes),'?'));

    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=?
          AND ra.etablissement_id=?
          AND ra.scope_type='ORGANIZATION'
          AND ra.actif=1
          AND ra.revoked_at IS NULL
          AND r.code IN($marks)
    ");
    $s->execute(array_merge([$targetUserId,(int)$ctx['id']],$adminCodes));

    if((int)$s->fetchColumn()===0)return false;

    $s=$pdo->prepare("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        JOIN role_assignments ra ON ra.user_id=u.id
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.etablissement_id=?
          AND ra.scope_type='ORGANIZATION'
          AND ra.actif=1
          AND ra.revoked_at IS NULL
          AND u.id<>?
          AND u.actif=1
          AND u.statut_compte='ACTIF'
          AND r.code IN($marks)
    ");
    $s->execute(array_merge([(int)$ctx['id'],$targetUserId],$adminCodes));

    return (int)$s->fetchColumn()===0;
}