<?php
/**
 * STAGIA-RDC - Contexte établissement courant.
 *
 * Charge une seule fois par requête :
 * - établissement courant
 * - type d'établissement
 * - capacités ACADEMIQUE / ACCUEIL
 * - configuration académique réellement appliquée
 * - permissions effectives dans ce contexte
 */

/** Détermine l'établissement actif à partir de l'affectation prioritaire et de son périmètre. */
function resolveUserEstablishmentId(PDO $pdo,int $userId,?int $preferredId=null):?int{
    if(hasRole('SUPER_ADMIN'))return null;

    if($preferredId){
        $s=$pdo->prepare("
            SELECT 1
            FROM role_assignments
            WHERE user_id=? AND actif=1
              AND (
                    etablissement_id=?
                    OR (scope_type='ORGANIZATION' AND scope_id=?)
              )
              AND (starts_at IS NULL OR starts_at<=NOW())
              AND (ends_at IS NULL OR ends_at>=NOW())
            LIMIT 1
        ");
        $s->execute([$userId,$preferredId,$preferredId]);
        if($s->fetchColumn())return $preferredId;
    }

    $s=$pdo->prepare("
        SELECT COALESCE(
            etablissement_id,
            CASE WHEN scope_type='ORGANIZATION' THEN scope_id END
        ) etablissement_id
        FROM role_assignments
        WHERE user_id=? AND actif=1
          AND (starts_at IS NULL OR starts_at<=NOW())
          AND (ends_at IS NULL OR ends_at>=NOW())
          AND (etablissement_id IS NOT NULL OR scope_type='ORGANIZATION')
        ORDER BY principal DESC,id
        LIMIT 1
    ");
    $s->execute([$userId]);
    $id=(int)$s->fetchColumn();
    if($id)return $id;

    /* Compatibilité des anciens comptes. */
    $s=$pdo->prepare("
        SELECT etablissement_id
        FROM etablissement_users
        WHERE user_id=?
        ORDER BY principal DESC,id
        LIMIT 1
    ");
    $s->execute([$userId]);
    return ($id=$s->fetchColumn())?(int)$id:null;
}

/** Charge identité, capacités et règles académiques de l'établissement courant. */
function loadEstablishmentContext(PDO $pdo,?int $establishmentId):array{
    $empty=[
        'id'=>null,'code'=>null,'nom'=>null,'type_code'=>null,'type_nom'=>null,
        'categorie'=>null,'academic_enabled'=>false,'host_enabled'=>false,
        'statut'=>null,'logo'=>null,'academic_settings'=>[]
    ];
    if(!$establishmentId)return $empty;

    $s=$pdo->prepare("
        SELECT
            e.id,e.code,e.nom,e.type_etablissement,e.statut,e.logo,
            t.libelle type_nom,t.categorie,t.academic_enabled,t.host_enabled,
            eas.source_template_id,eas.configuration_statut,
            eas.unite_academique_active,eas.unite_academique_obligatoire,
            eas.unite_parentale_autorisee,
            eas.departement_active,eas.departement_obligatoire,
            eas.filiere_active,eas.filiere_obligatoire,
            eas.filiere_directe_etablissement_autorisee,
            eas.filiere_directe_unite_autorisee,
            eas.option_specialite_active,eas.option_specialite_obligatoire,
            eas.promotion_active,eas.promotion_obligatoire
        FROM etablissements e
        LEFT JOIN establishment_types t ON t.code=e.type_etablissement AND t.actif=1
        LEFT JOIN etablissement_academic_settings eas ON eas.etablissement_id=e.id
        WHERE e.id=?
        LIMIT 1
    ");
    $s->execute([$establishmentId]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    if(!$r)return $empty;

    /* Valeurs de repli utilisées lorsque le type ne fournit pas explicitement ses capacités. */
    $academicCodes=['UNIVERSITE','INSTITUT_SUPERIEUR','ECOLE_PROFESSIONNELLE','CENTRE_FORMATION'];
    $hostCodes=['HOPITAL','ENTREPRISE','MINISTERE','ONG','SOCIETE_PRIVEE'];

    $academic=$r['academic_enabled']!==null
        ? (bool)(int)$r['academic_enabled']
        : in_array($r['type_etablissement'],$academicCodes,true);

    $host=$r['host_enabled']!==null
        ? (bool)(int)$r['host_enabled']
        : in_array($r['type_etablissement'],$hostCodes,true);

    return [
        'id'=>(int)$r['id'],
        'code'=>$r['code'],
        'nom'=>$r['nom'],
        'type_code'=>$r['type_etablissement'],
        'type_nom'=>$r['type_nom']?:$r['type_etablissement'],
        'categorie'=>$r['categorie'],
        'academic_enabled'=>$academic,
        'host_enabled'=>$host,
        'statut'=>$r['statut'],
        'logo'=>$r['logo'],
        'academic_settings'=>[
            'source_template_id'=>$r['source_template_id']!==null?(int)$r['source_template_id']:null,
            'configuration_statut'=>$r['configuration_statut']??null,
            'unite_academique_active'=>$r['unite_academique_active']!==null?(bool)(int)$r['unite_academique_active']:$academic,
            'unite_academique_obligatoire'=>(bool)(int)($r['unite_academique_obligatoire']??0),
            'unite_parentale_autorisee'=>(bool)(int)($r['unite_parentale_autorisee']??0),
            'departement_active'=>$r['departement_active']!==null?(bool)(int)$r['departement_active']:$academic,
            'departement_obligatoire'=>(bool)(int)($r['departement_obligatoire']??0),
            'filiere_active'=>$r['filiere_active']!==null?(bool)(int)$r['filiere_active']:$academic,
            'filiere_obligatoire'=>(bool)(int)($r['filiere_obligatoire']??0),
            'filiere_directe_etablissement_autorisee'=>(bool)(int)($r['filiere_directe_etablissement_autorisee']??0),
            'filiere_directe_unite_autorisee'=>(bool)(int)($r['filiere_directe_unite_autorisee']??0),
            'option_specialite_active'=>(bool)(int)($r['option_specialite_active']??0),
            'option_specialite_obligatoire'=>(bool)(int)($r['option_specialite_obligatoire']??0),
            'promotion_active'=>$r['promotion_active']!==null?(bool)(int)$r['promotion_active']:$academic,
            'promotion_obligatoire'=>(bool)(int)($r['promotion_obligatoire']??0)
        ]
    ];
}

/** Retourne les codes de permission effectivement valables dans l'établissement sélectionné. */
function loadContextPermissionCodes(PDO $pdo,int $userId,?int $establishmentId):array{
    if(hasRole('SUPER_ADMIN')){
        $s=$pdo->query("SELECT code FROM permissions WHERE actif=1 ORDER BY code");
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    $sql="
        SELECT DISTINCT p.code
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id AND r.actif=1
        JOIN role_permissions rp ON rp.role_id=ra.role_id
        JOIN permissions p ON p.id=rp.permission_id AND p.actif=1
        WHERE ra.user_id=? AND ra.actif=1
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
          AND (
                ra.scope_type IN('PLATFORM','SELF')
    ";
    $params=[$userId];

    if($establishmentId){
        $sql.="
                OR ra.etablissement_id=?
                OR (ra.scope_type='ORGANIZATION' AND ra.scope_id=?)
        ";
        $params[]=$establishmentId;
        $params[]=$establishmentId;
    }

    $sql.=")";
    $s=$pdo->prepare($sql);
    $s->execute($params);
    return array_values(array_unique(array_map('strval',$s->fetchAll(PDO::FETCH_COLUMN))));
}

/** Enregistre le contexte établissement et ses permissions pour les contrôles rapides de l'interface. */
function applyEstablishmentContextToSession(array $context,array $permissions):void{
    $_SESSION['etablissement_id']=$context['id'];
    $_SESSION['etablissement_code']=$context['code'];
    $_SESSION['etablissement_nom']=$context['nom'];

    $_SESSION['type_etablissement']=$context['type_code'];
    $_SESSION['type_etablissement_nom']=$context['type_nom'];
    $_SESSION['establishment_category']=$context['categorie'];

    $_SESSION['academic_enabled']=$context['academic_enabled'];
    $_SESSION['host_enabled']=$context['host_enabled'];
    $_SESSION['academic_settings']=$context['academic_settings'];
    $_SESSION['permission_codes']=$permissions;
    $_SESSION['establishment_context']=$context;
}

/** Vérifie une ou plusieurs permissions déjà chargées en session pour le contexte courant. */
function contextPermission(string|array $permissions):bool{
    if(hasRole('SUPER_ADMIN'))return true;
    $current=$_SESSION['permission_codes']??[];
    if(!is_array($current))$current=[];
    foreach((array)$permissions as $permission){
        if(in_array($permission,$current,true))return true;
    }
    return false;
}

/** Indique si l'établissement courant peut utiliser les fonctions académiques. */
function contextAcademicEnabled():bool{
    return !empty($_SESSION['academic_enabled']);
}

/** Indique si l'établissement courant peut utiliser les fonctions d'accueil. */
function contextHostEnabled():bool{
    return !empty($_SESSION['host_enabled']);
}

/** Retourne le snapshot établissement mémorisé, ou un tableau vide hors contexte. */
function currentEstablishmentContext():array{
    return is_array($_SESSION['establishment_context']??null)
        ? $_SESSION['establishment_context']
        : [];
}
