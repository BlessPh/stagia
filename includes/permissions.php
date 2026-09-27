<?php
/**
 * STAGIA-RDC - Autorisation RBAC
 * Compatibilité conservée avec users.role_id et $_SESSION['role_code'].
 */

/** Retourne l'identifiant utilisateur mémorisé en session, ou null hors session. */
function currentUserId():?int{
    return isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null;
}

/** Fusionne le rôle principal historique et les rôles multiples de la session. */
function sessionRoleCodes():array{
    $codes=[];
    if(!empty($_SESSION['role_codes'])&&is_array($_SESSION['role_codes']))
        $codes=array_merge($codes,$_SESSION['role_codes']);
    if(!empty($_SESSION['role_code']))$codes[]=$_SESSION['role_code'];
    return array_values(array_unique(array_filter(array_map('strval',$codes))));
}

/** Indique si au moins un rôle demandé appartient à l'utilisateur courant. */
function hasRole(string|array $roles):bool{
    return (bool)array_intersect(sessionRoleCodes(),(array)$roles);
}

/** Interrompt la requête HTTP avec 403 si aucun rôle demandé n'est détenu. */
function requireRole(array $roles):void{
    if(!hasRole($roles)){
        http_response_code(403);
        exit('Accès refusé.');
    }
}

/** Recharge les affectations de rôles actives dans la session depuis la base. */
function refreshAccessSession(PDO $pdo,?int $userId=null):array{
    $userId=$userId??currentUserId();
    if(!$userId)return [];

    $stmt=$pdo->prepare("
        SELECT DISTINCT r.code,r.nom,ra.principal
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=? AND ra.actif=1 AND r.actif=1
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        ORDER BY ra.principal DESC,ra.id
    ");
    $stmt->execute([$userId]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $codes=array_values(array_unique(array_column($rows,'code')));
    if(!$codes&&!empty($_SESSION['role_code']))$codes=[(string)$_SESSION['role_code']];
    $_SESSION['role_codes']=$codes;

    if($rows){
        $_SESSION['role_code']=$rows[0]['code'];
        $_SESSION['role_nom']=$rows[0]['nom'];
    }
    return $codes;
}

/** Résout l'établissement courant pour un utilisateur, sauf pour le super-administrateur global. */
function currentEtablissementId(PDO $pdo):?int{
    if(hasRole('SUPER_ADMIN'))return null;
    $userId=currentUserId();
    if(!$userId)return null;

    $sessionId=(int)($_SESSION['etablissement_id']??0);
    if($sessionId){
        $stmt=$pdo->prepare("
            SELECT 1 FROM role_assignments
            WHERE user_id=? AND actif=1 AND etablissement_id=?
              AND (starts_at IS NULL OR starts_at<=NOW())
              AND (ends_at IS NULL OR ends_at>=NOW())
            LIMIT 1
        ");
        $stmt->execute([$userId,$sessionId]);
        if($stmt->fetchColumn())return $sessionId;
    }

    try{
        $stmt=$pdo->prepare("
            SELECT COALESCE(
                etablissement_id,
                CASE WHEN scope_type='ORGANIZATION' THEN scope_id END
            )
            FROM role_assignments
            WHERE user_id=? AND actif=1
              AND (starts_at IS NULL OR starts_at<=NOW())
              AND (ends_at IS NULL OR ends_at>=NOW())
              AND (etablissement_id IS NOT NULL OR scope_type='ORGANIZATION')
            ORDER BY principal DESC,id
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $id=(int)$stmt->fetchColumn();
        if($id)return $id;
    }catch(Throwable $e){}

    $stmt=$pdo->prepare("
        SELECT etablissement_id
        FROM etablissement_users
        WHERE user_id=?
        ORDER BY principal DESC,id
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return ($id=$stmt->fetchColumn())?(int)$id:null;
}

/** Vérifie une permission et, si fourni, son périmètre plateforme, organisation, entité ou utilisateur. */
function hasPermission(PDO $pdo,string $permission,array $context=[]):bool{
    if(hasRole('SUPER_ADMIN'))return true;

    $userId=currentUserId();
    if(!$userId||$permission==='')return false;

    $stmt=$pdo->prepare("
        SELECT ra.scope_type,ra.scope_entity,ra.scope_id,ra.etablissement_id
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id AND r.actif=1
        JOIN role_permissions rp ON rp.role_id=ra.role_id
        JOIN permissions p ON p.id=rp.permission_id AND p.actif=1
        WHERE ra.user_id=? AND ra.actif=1 AND p.code=?
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
    ");
    $stmt->execute([$userId,$permission]);
    $assignments=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(!$assignments)return false;
    if(!$context)return true;

    $ctxType=strtoupper((string)($context['scope_type']??''));
    $ctxEntity=strtoupper((string)($context['scope_entity']??''));
    $ctxId=(int)($context['scope_id']??0);
    $ctxEtab=(int)($context['etablissement_id']??0);
    $ctxUser=(int)($context['user_id']??0);

    foreach($assignments as $a){
        $type=strtoupper((string)$a['scope_type']);
        $entity=strtoupper((string)($a['scope_entity']??''));
        $scopeId=(int)($a['scope_id']??0);
        $etab=(int)($a['etablissement_id']??0);

        if($type==='PLATFORM')return true;
        if($type==='SELF'&&$ctxUser===$userId)return true;

        if($type==='ORGANIZATION'){
            $orgId=$etab?:$scopeId;
            if($ctxEtab&&$orgId===$ctxEtab)return true;
            if($ctxType==='ORGANIZATION'&&$ctxId&&$orgId===$ctxId)return true;
        }

        if($ctxType!==''&&$type===$ctxType&&$ctxId&&$scopeId===$ctxId){
            if($ctxEntity!==''&&$entity!==''&&$ctxEntity!==$entity)continue;
            if(!$ctxEtab||!$etab||$ctxEtab===$etab)return true;
        }
    }
    return false;
}

/** Exige une permission précise et retourne HTTP 403 lorsque le contrôle échoue. */
function requirePermission(PDO $pdo,string $permission,array $context=[]):void{
    if(!hasPermission($pdo,$permission,$context)){
        http_response_code(403);
        exit('Accès refusé.');
    }
}
