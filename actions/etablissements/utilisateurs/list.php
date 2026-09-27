<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/establishment-user-admin.php';

requireEstablishmentAjaxPermission('user.view');

try{
    $ctx=establishmentUserAdminContext($pdo);
    $eid=(int)$ctx['id'];

    $q=trim((string)($_GET['q']??''));
    $status=trim((string)($_GET['status']??''));
    $roleId=(int)($_GET['role_id']??0);

    $where=[
        'eu.etablissement_id=?'
    ];
    $params=[$eid];

    if($q!==''){
        $where[]="
            (
                u.nom LIKE ?
                OR u.postnom LIKE ?
                OR u.prenom LIKE ?
                OR u.email LIKE ?
                OR u.identifiant LIKE ?
                OR eu.fonction LIKE ?
            )
        ";
        $like='%'.$q.'%';
        array_push(
            $params,
            $like,$like,$like,$like,$like,$like
        );
    }

    if(in_array($status,['ACTIF','A_ACTIVER','SUSPENDU'],true)){
        $where[]='u.statut_compte=?';
        $params[]=$status;
    }

    if($roleId>0){
        $where[]="
            (
                EXISTS(
                    SELECT 1
                    FROM role_assignments raf
                    WHERE raf.user_id=u.id
                      AND raf.etablissement_id=eu.etablissement_id
                      AND raf.role_id=?
                      AND raf.actif=1
                      AND raf.revoked_at IS NULL
                )
                OR (
                    u.role_id=?
                    AND NOT EXISTS(
                        SELECT 1
                        FROM role_assignments rax
                        WHERE rax.user_id=u.id
                          AND rax.etablissement_id=eu.etablissement_id
                          AND rax.actif=1
                          AND rax.revoked_at IS NULL
                    )
                )
            )
        ";
        $params[]=$roleId;
        $params[]=$roleId;
    }

    $sql="
        SELECT
            u.id,
            u.nom,
            u.postnom,
            u.prenom,
            u.email,
            u.identifiant,
            u.telephone,
            u.actif,
            u.statut_compte,
            u.derniere_connexion,

            eu.fonction,
            eu.principal,

            COALESCE(
                NULLIF(
                    GROUP_CONCAT(
                        DISTINCT CASE
                            WHEN ra.actif=1
                             AND ra.revoked_at IS NULL
                            THEN r.nom
                        END
                        ORDER BY ra.principal DESC,r.nom
                        SEPARATOR ', '
                    ),
                    ''
                ),
                legacy.nom
            ) AS roles,

            COALESCE(
                NULLIF(
                    GROUP_CONCAT(
                        DISTINCT CASE
                            WHEN ra.actif=1
                             AND ra.revoked_at IS NULL
                            THEN r.code
                        END
                        ORDER BY ra.principal DESC,r.code
                        SEPARATOR ','
                    ),
                    ''
                ),
                legacy.code
            ) AS role_codes,

            (
                SELECT COUNT(DISTINCT eu2.etablissement_id)
                FROM etablissement_users eu2
                WHERE eu2.user_id=u.id
            ) AS establishment_count

        FROM etablissement_users eu

        JOIN users u
          ON u.id=eu.user_id

        LEFT JOIN role_assignments ra
          ON ra.user_id=u.id
         AND ra.etablissement_id=eu.etablissement_id

        LEFT JOIN roles r
          ON r.id=ra.role_id

        LEFT JOIN roles legacy
          ON legacy.id=u.role_id

        WHERE ".implode(' AND ',$where)."

        GROUP BY
            u.id,u.nom,u.postnom,u.prenom,u.email,
            u.identifiant,u.telephone,u.actif,
            u.statut_compte,u.derniere_connexion,
            eu.fonction,eu.principal,
            legacy.nom,legacy.code

        ORDER BY
            eu.principal DESC,
            u.nom,u.postnom,u.prenom
    ";

    $s=$pdo->prepare($sql);
    $s->execute($params);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($items as &$item){
        $item['id']=(int)$item['id'];
        $item['principal']=(int)$item['principal'];
        $item['shared']=(int)$item['establishment_count']>1;
        unset($item['establishment_count']);
    }
    unset($item);

    $s=$pdo->prepare("
        SELECT
            COUNT(*) total,
            SUM(u.statut_compte='ACTIF') actifs,
            SUM(u.statut_compte='A_ACTIVER') a_activer,
            SUM(u.statut_compte='SUSPENDU') suspendus
        FROM etablissement_users eu
        JOIN users u ON u.id=eu.user_id
        WHERE eu.etablissement_id=?
    ");
    $s->execute([$eid]);

    $stats=$s->fetch(PDO::FETCH_ASSOC)?:[
        'total'=>0,
        'actifs'=>0,
        'a_activer'=>0,
        'suspendus'=>0
    ];

    $roles=establishmentAssignableRoles($pdo,$ctx);

    jsonResponse(
        true,
        '',
        [
            'establishment'=>[
                'id'=>$eid,
                'code'=>$ctx['code'],
                'nom'=>$ctx['nom'],
                'type_code'=>$ctx['type_etablissement'],
                'type_nom'=>$ctx['type_nom']
            ],
            'items'=>$items,
            'roles'=>$roles,
            'stats'=>[
                'total'=>(int)($stats['total']??0),
                'actifs'=>(int)($stats['actifs']??0),
                'a_activer'=>(int)($stats['a_activer']??0),
                'suspendus'=>(int)($stats['suspendus']??0)
            ],
            'permissions'=>[
                'create'=>contextPermission('user.create'),
                'update'=>contextPermission('user.update'),
                'disable'=>contextPermission('user.disable'),
                'role_assign'=>contextPermission('user.role.assign')
            ]
        ]
    );

}catch(Throwable $e){
    jsonResponse(
        false,
        'Erreur Utilisateurs établissement : '.$e->getMessage(),
        [],
        500
    );
}
