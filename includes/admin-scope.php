<?php
declare(strict_types=1);

/** Règles centrales d'administration institutionnelle. */

/** Vérifie qu'un super-administrateur est également le rôle principal de la session. */
function estSuperAdminPrincipal():bool{
    return hasRole('SUPER_ADMIN')&&($_SESSION['role_code']??'')==='SUPER_ADMIN';
}

/**
 * Un responsable ministériel est défini par son affectation active à une
 * organisation de type MINISTERE. Le code/libellé du rôle peut être système
 * ou personnalisé par l'organisation.
 */
/** Retourne les ministères auxquels l'utilisateur possède une affectation organisationnelle active. */
function ministeresRepresentes(PDO $pdo,?int $userId=null):array{
    $userId=$userId??currentUserId();
    if(!$userId)return [];

    $s=$pdo->prepare("SELECT DISTINCT e.id,e.code,e.nom,e.province,e.ville,e.email,e.telephone
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id AND r.actif=1
        JOIN etablissements e ON e.id=COALESCE(
            ra.etablissement_id,
            CASE WHEN ra.scope_type='ORGANIZATION' THEN ra.scope_id END
        )
        WHERE ra.user_id=? AND ra.actif=1
          AND ra.scope_type='ORGANIZATION'
          AND e.type_etablissement='MINISTERE'
          AND e.statut IN('VALIDE','ACTIF')
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        ORDER BY e.nom");
    $s->execute([$userId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Indique si l'utilisateur représente au moins un ministère actif. */
function estResponsableMinisteriel(PDO $pdo,?int $userId=null):bool{
    return ministeresRepresentes($pdo,$userId)!==[];
}

/** Bloque une page HTML si l'utilisateur n'est pas responsable ministériel. */
function exigerResponsableMinisteriel(PDO $pdo):void{
    if(!estResponsableMinisteriel($pdo)){
        http_response_code(403);
        exit('Accès réservé aux responsables rattachés à un ministère.');
    }
}

/** Variante JSON du contrôle ministériel pour les endpoints AJAX. */
function exigerResponsableMinisterielAjax(PDO $pdo):void{
    if(!estResponsableMinisteriel($pdo))
        jsonResponse(false,'Accès réservé aux responsables rattachés à un ministère.',[],403);
}

/** Trouve l'établissement de l'affectation administrative principale de l'utilisateur. */
function etablissementPrincipalAdmin(PDO $pdo,?int $userId=null):?int{
    $userId=$userId??currentUserId();
    if(!$userId)return null;

    $s=$pdo->prepare("SELECT ra.etablissement_id
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=? AND ra.actif=1 AND ra.principal=1
          AND r.actif=1 AND r.code IN('ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL','ADMIN_PRINCIPAL')
          AND ra.etablissement_id IS NOT NULL
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        ORDER BY ra.id LIMIT 1");
    $s->execute([$userId]);
    $id=(int)$s->fetchColumn();
    return $id?:null;
}

/** Retourne le contexte d'administration autorisé, ou coupe la page avec HTTP 403. */
function exigerAdministrationRoles(PDO $pdo):array{
    if(estSuperAdminPrincipal())return ['super'=>true,'etablissement_id'=>null];
    $id=etablissementPrincipalAdmin($pdo);
    if(!$id){
        http_response_code(403);
        exit('Accès réservé à l’administrateur principal de l’organisation.');
    }
    return ['super'=>false,'etablissement_id'=>$id];
}

/** Variante AJAX qui renvoie une erreur JSON lorsque l'administration n'est pas autorisée. */
function exigerAdministrationRolesAjax(PDO $pdo):array{
    if(function_exists('requireAjaxAuth'))requireAjaxAuth();
    if(estSuperAdminPrincipal())return ['super'=>true,'etablissement_id'=>null];
    $id=etablissementPrincipalAdmin($pdo);
    if(!$id)jsonResponse(false,'Accès réservé à l’administrateur principal de l’organisation.',[],403);
    return ['super'=>false,'etablissement_id'=>$id];
}

/** Détermine si un rôle peut être vu dans le contexte d'administration sélectionné. */
function roleVisibleDansContexte(array $role,array $contexte):bool{
    if((int)($role['systeme']??0)===1)return true;
    return !$contexte['super']
        && (int)($role['etablissement_id']??0)===(int)$contexte['etablissement_id'];
}

/** Détermine si un rôle peut être modifié dans le contexte d'administration sélectionné. */
function roleModifiableDansContexte(array $role,array $contexte):bool{
    if($contexte['super'])return (int)($role['systeme']??0)===1;
    return (int)($role['systeme']??0)===0
        && (int)($role['etablissement_id']??0)===(int)$contexte['etablissement_id'];
}

/** Liste les établissements visibles selon un périmètre super-admin, ministère ou établissement principal. */
function etablissementsVisiblesAdministration(PDO $pdo):array{
    if(estSuperAdminPrincipal()){
        return array_map('intval',$pdo->query("SELECT id FROM etablissements WHERE statut IN('VALIDE','ACTIF')")->fetchAll(PDO::FETCH_COLUMN));
    }

    if(estResponsableMinisteriel($pdo)){
        $userId=currentUserId();
        $s=$pdo->prepare("SELECT DISTINCT om.organisation_etablissement_id
            FROM organisations_ministeres om
            JOIN role_assignments ra ON ra.etablissement_id=om.ministere_etablissement_id
            JOIN etablissements m ON m.id=ra.etablissement_id AND m.type_etablissement='MINISTERE'
            WHERE ra.user_id=? AND ra.actif=1 AND ra.scope_type='ORGANIZATION'
              AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
              AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
              AND om.actif=1");
        $s->execute([$userId]);
        return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
    }

    $principal=etablissementPrincipalAdmin($pdo);
    if($principal)return [$principal];
    return [];
}
