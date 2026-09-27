<?php
/**
 * STAGIA-RDC - Chargement du contexte RBAC de session.
 *
 * La base reste la source de vérité. La session ne contient qu'un
 * instantané utile pour l'interface et la compatibilité historique.
 */

/**
 * Charge les affectations valides, détermine le rôle principal et construit
 * le contexte de session compatible avec les anciens comptes.
 */
function loadUserAccessContext(PDO $pdo,int $userId):array{
    $stmt=$pdo->prepare("
        SELECT
            ra.id assignment_id,
            ra.role_id,
            ra.scope_type,
            ra.scope_entity,
            ra.scope_id,
            ra.etablissement_id,
            ra.principal,
            r.code role_code,
            r.nom role_nom
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=?
          AND ra.actif=1
          AND r.actif=1
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        ORDER BY ra.principal DESC,ra.id
    ");
    $stmt->execute([$userId]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    if(!$rows){
        /*
         * S'il existe des affectations mais qu'aucune n'est actuellement
         * valide, on NE retombe pas sur users.role_id : cela contournerait
         * une révocation, une date de début ou une date de fin.
         */
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM role_assignments WHERE user_id=?");
        $stmt->execute([$userId]);

        if((int)$stmt->fetchColumn()>0){
            return ['ok'=>false,'reason'=>'NO_ACTIVE_ASSIGNMENT'];
        }

        /*
         * Compatibilité pour un ancien compte qui n'aurait jamais été
         * migré vers role_assignments.
         */
        $stmt=$pdo->prepare("
            SELECT u.role_id,r.code role_code,r.nom role_nom
            FROM users u
            JOIN roles r ON r.id=u.role_id
            WHERE u.id=? AND r.actif=1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $legacy=$stmt->fetch(PDO::FETCH_ASSOC);

        if(!$legacy){
            return ['ok'=>false,'reason'=>'NO_ROLE'];
        }

        return [
            'ok'=>true,
            'legacy'=>true,
            'role_code'=>$legacy['role_code'],
            'role_nom'=>$legacy['role_nom'],
            'role_codes'=>[$legacy['role_code']],
            'role_names'=>[$legacy['role_nom']],
            'assignment_id'=>null,
            'scope_type'=>null,
            'scope_entity'=>null,
            'scope_id'=>null,
            'etablissement_id'=>null,
            'assignments'=>[]
        ];
    }

    /* La première affectation triée est le rôle principal, les autres restent disponibles. */
    $primary=$rows[0];
    $roleCodes=[];
    $roleNames=[];

    foreach($rows as $row){
        if(!in_array($row['role_code'],$roleCodes,true))$roleCodes[]=$row['role_code'];
        if(!in_array($row['role_nom'],$roleNames,true))$roleNames[]=$row['role_nom'];
    }

    $etablissementId=(int)($primary['etablissement_id']??0);
    if(!$etablissementId && $primary['scope_type']==='ORGANIZATION'){
        $etablissementId=(int)($primary['scope_id']??0);
    }

    /* Snapshot complet consommé ensuite par applyUserAccessContextToSession(). */
    return [
        'ok'=>true,
        'legacy'=>false,
        'role_code'=>$primary['role_code'],
        'role_nom'=>$primary['role_nom'],
        'role_codes'=>$roleCodes,
        'role_names'=>$roleNames,
        'assignment_id'=>(int)$primary['assignment_id'],
        'scope_type'=>$primary['scope_type'],
        'scope_entity'=>$primary['scope_entity'],
        'scope_id'=>$primary['scope_id']!==null?(int)$primary['scope_id']:null,
        'etablissement_id'=>$etablissementId?:null,
        'assignments'=>array_map(static fn($r)=>[
            'id'=>(int)$r['assignment_id'],
            'role_code'=>$r['role_code'],
            'scope_type'=>$r['scope_type'],
            'scope_entity'=>$r['scope_entity'],
            'scope_id'=>$r['scope_id']!==null?(int)$r['scope_id']:null,
            'etablissement_id'=>$r['etablissement_id']!==null?(int)$r['etablissement_id']:null,
            'principal'=>(int)$r['principal']
        ],$rows)
    ];
}

/** Copie dans $_SESSION uniquement les informations d'accès calculées par le chargeur RBAC. */
function applyUserAccessContextToSession(array $context):void{
    $_SESSION['role_code']=$context['role_code'];
    $_SESSION['role_nom']=$context['role_nom'];
    $_SESSION['role_codes']=$context['role_codes'];
    $_SESSION['role_names']=$context['role_names'];
    $_SESSION['role_assignment_id']=$context['assignment_id'];
    $_SESSION['scope_type']=$context['scope_type'];
    $_SESSION['scope_entity']=$context['scope_entity'];
    $_SESSION['scope_id']=$context['scope_id'];
    $_SESSION['etablissement_id']=$context['etablissement_id'];
    $_SESSION['access_assignments']=$context['assignments'];
}
