<?php
/*
|--------------------------------------------------------------------------
| STAGIA-RDC — MINI-JURYS / LISTE
| Version V6 — session AJAX alignée sur STAGIA
|--------------------------------------------------------------------------
| Initialisation identique aux endpoints AJAX hospitaliers STAGIA :
| session-security → config → database → ajax → permissions → périmètre chef.
| auth.php n'est pas chargé ici pour éviter toute redirection HTML dans l'AJAX.
|
| Lecture uniquement : aucune donnée n'est modifiée.
*/

require_once __DIR__.'/../../includes/session-security.php';
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/chef-service-scope.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');


function mjTable(PDO $pdo,string $table):bool{
    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name=?
    ");
    $s->execute([$table]);
    return (int)$s->fetchColumn()>0;
}

function mjColumn(PDO $pdo,string $table,string $column):bool{
    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema=DATABASE()
          AND table_name=?
          AND column_name=?
    ");
    $s->execute([$table,$column]);
    return (int)$s->fetchColumn()>0;
}

function mjLower(string $value):string{
    return function_exists('mb_strtolower')
        ?mb_strtolower($value,'UTF-8')
        :strtolower($value);
}

try{
    $uid=(int)($_SESSION['user_id']??0);
    $eid=(int)currentEtablissementId($pdo);
    $sessionRole=strtoupper(trim((string)($_SESSION['role_code']??'')));

    if(!$uid)
        jsonResponse(false,'Utilisateur absent de la session STAGIA.',[],401);

    if(!$eid)
        jsonResponse(false,'Aucun établissement associé à la session STAGIA.',[],403);

    /*
     * Rôles actifs : on garde le rôle courant + les affectations actives.
     */
    $roles=$sessionRole!==''?[$sessionRole]:[];

    if(mjTable($pdo,'role_assignments')&&mjTable($pdo,'roles')){
        $hasRevoked=mjColumn($pdo,'role_assignments','revoked_at');

        $sql="
            SELECT DISTINCT UPPER(r.code)
            FROM role_assignments ra
            JOIN roles r ON r.id=ra.role_id
            WHERE ra.user_id=?
              AND ra.actif=1
              AND (ra.etablissement_id=? OR ra.etablissement_id IS NULL)
              AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
              AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        ";

        if($hasRevoked)$sql.=" AND ra.revoked_at IS NULL";

        $s=$pdo->prepare($sql);
        $s->execute([$uid,$eid]);

        foreach($s->fetchAll(PDO::FETCH_COLUMN) as $r){
            $r=strtoupper(trim((string)$r));
            if($r!=='')$roles[]=$r;
        }
    }

    $roles=array_values(array_unique($roles));

    $chefCodes=[
        'CHEF_SERVICE',
        'ENCADREUR_CHEF_SERVICE',
        'RESPONSABLE_SERVICE'
    ];

    $isChef=(bool)array_intersect($chefCodes,$roles);
    $isAdmin=
        in_array('ADMIN_ACCUEIL',$roles,true)
        ||in_array('COORDINATEUR_STAGES',$roles,true);
    $isAuthority=in_array('AUTORITE_HOSPITALIERE',$roles,true);

    if(!$isChef&&!$isAdmin&&!$isAuthority){
        jsonResponse(
            false,
            'Accès Mini-jurys non autorisé pour : '.implode(', ',$roles),
            [],
            403
        );
    }

    /*
     * Vérification claire des tables indispensables.
     */
    $required=[
        'stage_rotations',
        'stage_assignments',
        'stage_admissions',
        'stage_reservations',
        'stage_applications',
        'stage_campaigns',
        'student_academic_enrollments',
        'student_enrollments',
        'student_profiles',
        'etablissements',
        'host_units',
        'stage_evaluations',
        'stage_mini_juries'
    ];

    foreach($required as $table){
        if(!mjTable($pdo,$table))
            throw new RuntimeException('Table manquante : '.$table);
    }

    /*
     * Département :
     * 1) coordination/département de l'admission si disponible ;
     * 2) parent du service ;
     * 3) service lui-même.
     */
    $hasCoord=mjColumn(
        $pdo,
        'stage_admissions',
        'coordination_unit_id'
    );

    $coordJoin=$hasCoord
        ?"LEFT JOIN host_units coord
            ON coord.id=ad.coordination_unit_id
           AND coord.host_etablissement_id=r.host_etablissement_id"
        :"";

    $deptId=$hasCoord
        ?"COALESCE(coord.id,parent.id,hu.id)"
        :"COALESCE(parent.id,hu.id)";

    $deptCode=$hasCoord
        ?"COALESCE(coord.code,parent.code,hu.code)"
        :"COALESCE(parent.code,hu.code)";

    $deptName=$hasCoord
        ?"COALESCE(coord.nom,parent.nom,hu.nom)"
        :"COALESCE(parent.nom,hu.nom)";

    /*
     * Périmètre Chef de service.
     * Les administrateurs / coordinateurs / autorités voient tout l'hôpital.
     */
    $scopeAll=true;
    $scopeIds=[];

    if($isChef&&!$isAdmin&&!$isAuthority){
        $scopeAll=false;

        $hasScopeEntity=mjColumn(
            $pdo,
            'role_assignments',
            'scope_entity'
        );

        $hasRevoked=mjColumn(
            $pdo,
            'role_assignments',
            'revoked_at'
        );

        $sql="
            SELECT ra.scope_type,ra.scope_id
            FROM role_assignments ra
            JOIN roles rr ON rr.id=ra.role_id
            WHERE ra.user_id=?
              AND rr.code IN('CHEF_SERVICE','ENCADREUR_CHEF_SERVICE','RESPONSABLE_SERVICE')
              AND ra.actif=1
              AND (ra.etablissement_id=? OR ra.etablissement_id IS NULL)
              AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
              AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        ";

        if($hasRevoked)$sql.=" AND ra.revoked_at IS NULL";

        $s=$pdo->prepare($sql);
        $s->execute([$uid,$eid]);

        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $x){
            if(($x['scope_type']??'')==='ORGANIZATION'){
                $scopeAll=true;
                $scopeIds=[];
                break;
            }

            if(
                ($x['scope_type']??'')==='UNIT'
                &&(int)($x['scope_id']??0)>0
            ){
                $scopeIds[]=(int)$x['scope_id'];
            }
        }

        $scopeIds=array_values(array_unique($scopeIds));

        /*
         * Étendre vers les services enfants.
         */
        if(!$scopeAll&&$scopeIds){
            $in=implode(',',array_fill(0,count($scopeIds),'?'));

            $s=$pdo->prepare("
                SELECT id
                FROM host_units
                WHERE host_etablissement_id=?
                  AND parent_id IN($in)
                  AND actif=1
            ");
            $s->execute(array_merge([$eid],$scopeIds));

            foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id)
                $scopeIds[]=(int)$id;

            $scopeIds=array_values(array_unique($scopeIds));
        }
    }

    /*
     * Toutes les rotations de l'établissement.
     */
    $sql="
        SELECT
            r.id rotation_id,
            r.assignment_id,
            r.sequence_no,
            r.date_debut,
            r.date_fin,
            r.statut rotation_statut,

            sp.id student_id,
            sp.stagia_code,
            sp.nom,
            sp.postnom,
            sp.prenom,

            uni.nom university_name,

            hu.id service_id,
            hu.nom service_name,

            {$deptId} department_unit_id,
            {$deptCode} department_code,
            {$deptName} department_name,

            mj.id mini_jury_id,
            mj.statut mini_jury_status,
            mj.note_finale mini_jury_score,
            mj.decision mini_jury_decision,
            mj.appreciation mini_jury_appreciation,
            mj.date_jury,
            mj.validated_at

        FROM stage_rotations r

        JOIN stage_assignments a
          ON a.id=r.assignment_id

        JOIN stage_admissions ad
          ON ad.id=a.admission_id

        JOIN stage_reservations sr
          ON sr.id=ad.reservation_id

        JOIN stage_applications app
          ON app.id=sr.application_id

        JOIN stage_campaigns c
          ON c.id=app.campaign_id

        JOIN student_academic_enrollments sae
          ON sae.id=app.academic_enrollment_id

        JOIN student_enrollments se
          ON se.id=sae.enrollment_id

        JOIN student_profiles sp
          ON sp.id=se.student_id

        JOIN etablissements uni
          ON uni.id=c.owner_etablissement_id

        JOIN host_units hu
          ON hu.id=r.host_unit_id

        LEFT JOIN host_units parent
          ON parent.id=hu.parent_id
         AND parent.host_etablissement_id=r.host_etablissement_id

        {$coordJoin}

        LEFT JOIN stage_mini_juries mj
          ON mj.assignment_id=r.assignment_id
         AND mj.department_unit_id={$deptId}
         AND mj.host_etablissement_id=r.host_etablissement_id

        WHERE r.host_etablissement_id=?
          AND a.host_etablissement_id=?
          AND r.statut IN('PLANIFIEE','ACTIVE','TERMINEE')
          AND a.statut<>'ANNULEE'

        ORDER BY
            sp.nom,
            sp.postnom,
            sp.prenom,
            {$deptName},
            r.sequence_no,
            r.id
    ";

    $s=$pdo->prepare($sql);
    $s->execute([$eid,$eid]);
    $rotations=$s->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Évaluations FIN_ROTATION finalisées.
     * Compatibilité : les anciennes bases peuvent ne pas encore avoir
     * FINALISEE dans l'ENUM, mais la comparaison reste sans danger.
     */
    $s=$pdo->prepare("
        SELECT
            e.rotation_id,
            ROUND(AVG(e.note_finale),2) note_finale
        FROM stage_evaluations e
        JOIN stage_rotations r ON r.id=e.rotation_id
        WHERE r.host_etablissement_id=?
          AND e.type_evaluation='FIN_ROTATION'
          AND e.statut='FINALISEE'
        GROUP BY e.rotation_id
    ");
    $s->execute([$eid]);

    $finalEvaluations=[];

    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $e)
        $finalEvaluations[(int)$e['rotation_id']]=(float)$e['note_finale'];

    /*
     * Regroupement stagiaire + département.
     */
    $groups=[];

    foreach($rotations as $r){
        $serviceId=(int)$r['service_id'];
        $departmentId=(int)$r['department_unit_id'];

        if(
            !$scopeAll
            &&!in_array($serviceId,$scopeIds,true)
            &&!in_array($departmentId,$scopeIds,true)
        ){
            continue;
        }

        $assignmentId=(int)$r['assignment_id'];
        $key=$assignmentId.'-'.$departmentId;

        if(!isset($groups[$key])){
            $studentName=trim(implode(' ',array_filter([
                $r['nom']??'',
                $r['postnom']??'',
                $r['prenom']??''
            ])));

            $groups[$key]=[
                'assignment_id'=>$assignmentId,
                'student_id'=>(int)$r['student_id'],
                'student_name'=>$studentName!==''?$studentName:'Stagiaire #'.(int)$r['student_id'],
                'stagia_code'=>$r['stagia_code']??'',
                'university_name'=>$r['university_name']??'',

                'department_unit_id'=>$departmentId,
                'department_code'=>$r['department_code']??'',
                'department_name'=>$r['department_name']??'Département',

                'services_map'=>[],
                'rotation_total'=>0,
                'rotation_done'=>0,
                'final_eval_count'=>0,
                'evaluation_scores'=>[],

                'date_debut'=>$r['date_debut']??null,
                'date_fin'=>$r['date_fin']??null,

                'mini_jury_id'=>$r['mini_jury_id']!==null?(int)$r['mini_jury_id']:null,
                'mini_jury_status'=>$r['mini_jury_status']??null,
                'mini_jury_score'=>$r['mini_jury_score']!==null?(float)$r['mini_jury_score']:null,
                'mini_jury_decision'=>$r['mini_jury_decision']??null,
                'mini_jury_appreciation'=>$r['mini_jury_appreciation']??null,
                'jury_date'=>$r['date_jury']??null,
                'validated_at'=>$r['validated_at']??null,

                'can_manage'=>($isAdmin||$isChef)?1:0
            ];
        }

        $g=&$groups[$key];

        $g['services_map'][$serviceId]=
            $r['service_name']??('Service #'.$serviceId);

        $g['rotation_total']++;

        if(($r['rotation_statut']??'')==='TERMINEE')
            $g['rotation_done']++;

        $rotationId=(int)$r['rotation_id'];

        if(array_key_exists($rotationId,$finalEvaluations)){
            $g['final_eval_count']++;
            $g['evaluation_scores'][]=$finalEvaluations[$rotationId];
        }

        if(
            !empty($r['date_debut'])
            &&(
                empty($g['date_debut'])
                ||$r['date_debut']<$g['date_debut']
            )
        ){
            $g['date_debut']=$r['date_debut'];
        }

        if(
            !empty($r['date_fin'])
            &&(
                empty($g['date_fin'])
                ||$r['date_fin']>$g['date_fin']
            )
        ){
            $g['date_fin']=$r['date_fin'];
        }

        unset($g);
    }

    $items=[];

    foreach($groups as $g){
        $g['services']=implode(', ',array_values($g['services_map']));
        unset($g['services_map']);

        $scores=$g['evaluation_scores'];

        $g['evaluation_average']=$scores
            ?round(array_sum($scores)/count($scores),2)
            :null;

        unset($g['evaluation_scores']);

        $g['ready']=
            $g['rotation_total']>0
            &&$g['rotation_done']===$g['rotation_total']
            &&$g['final_eval_count']===$g['rotation_total']
                ?1
                :0;

        $items[]=$g;
    }

    /*
     * Recherche.
     */
    $needle=mjLower(trim((string)($_GET['q']??'')));

    if($needle!==''){
        $items=array_values(array_filter(
            $items,
            function(array $x)use($needle):bool{
                $hay=mjLower(implode(' ',[
                    $x['student_name']??'',
                    $x['stagia_code']??'',
                    $x['university_name']??'',
                    $x['department_name']??'',
                    $x['department_code']??'',
                    $x['services']??''
                ]));

                return strpos($hay,$needle)!==false;
            }
        ));
    }

    /*
     * KPI.
     */
    $stats=[
        'todo'=>0,
        'drafts'=>0,
        'validated'=>0,
        'waiting'=>0
    ];

    foreach($items as $x){
        if(($x['mini_jury_status']??'')==='VALIDEE'){
            $stats['validated']++;
        }elseif(($x['mini_jury_status']??'')==='BROUILLON'){
            $stats['drafts']++;
        }elseif(!empty($x['ready'])){
            $stats['todo']++;
        }else{
            $stats['waiting']++;
        }
    }

    /*
     * Pagination.
     */
    $perPage=15;
    $total=count($items);
    $pages=max(1,(int)ceil($total/$perPage));
    $page=max(1,min((int)($_GET['page']??1),$pages));
    $offset=($page-1)*$perPage;

    jsonResponse(true,'',[
        'items'=>array_slice($items,$offset,$perPage),
        'stats'=>$stats,
        'pagination'=>[
            'page'=>$page,
            'pages'=>$pages,
            'per_page'=>$perPage,
            'total'=>$total
        ],
        'diagnostic'=>[
            'version'=>'V6',
            'database'=>$pdo->query('SELECT DATABASE()')->fetchColumn(),
            'user_id'=>$uid,
            'etablissement_id'=>$eid,
            'roles'=>$roles,
            'coordination_column'=>$hasCoord?1:0,
            'rotations_found'=>count($rotations)
        ]
    ]);

}catch(Throwable $e){
    error_log(
        '[HOST MINI JURY LIST V4] '.
        $e->getMessage().
        ' | '.
        $e->getFile().
        ':'.
        $e->getLine()
    );

    jsonResponse(
        false,
        'Mini-jurys V6 : '.$e->getMessage().' [ligne '.$e->getLine().']',
        [],
        200
    );
}


