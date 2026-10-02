<?php
declare(strict_types=1);

/**
 * Supprime les donnees metier et tous les comptes sauf un SUPER_ADMIN.
 *
 * Par securite, le mode par defaut est une simulation. L'execution reelle
 * exige --execute puis une confirmation saisie au clavier.
 */
if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

$options=getopt('',['execute','keep-user:']);
$execute=array_key_exists('execute',$options);
$requestedUserId=isset($options['keep-user'])?(int)$options['keep-user']:0;

require __DIR__.'/../config/database.php';

function quotedIdentifier(string $name):string{
    return '`'.str_replace('`','``',$name).'`';
}

function tableExists(PDO $pdo,string $table):bool{
    $s=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE='BASE TABLE'");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}

function columnExists(PDO $pdo,string $table,string $column):bool{
    $s=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$table,$column]);
    return (bool)$s->fetchColumn();
}

try{
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if($database==='')throw new RuntimeException('Aucune base de donnees active.');

    $superAdmins=$pdo->query("
        SELECT DISTINCT u.id,u.identifiant,u.email,u.nom,u.postnom,u.prenom
        FROM users u
        JOIN roles legacy_role ON legacy_role.id=u.role_id
        WHERE legacy_role.code='SUPER_ADMIN'
        UNION
        SELECT DISTINCT u.id,u.identifiant,u.email,u.nom,u.postnom,u.prenom
        FROM users u
        JOIN role_assignments ra ON ra.user_id=u.id
        JOIN roles assigned_role ON assigned_role.id=ra.role_id
        WHERE assigned_role.code='SUPER_ADMIN'
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    if(!$superAdmins)
        throw new RuntimeException('Aucun compte SUPER_ADMIN detecte. Nettoyage annule.');

    $selected=null;
    if($requestedUserId>0){
        foreach($superAdmins as $candidate){
            if((int)$candidate['id']===$requestedUserId){$selected=$candidate;break;}
        }
        if(!$selected)
            throw new RuntimeException("L'utilisateur #{$requestedUserId} n'est pas un SUPER_ADMIN.");
    }elseif(count($superAdmins)===1){
        $selected=$superAdmins[0];
    }else{
        fwrite(STDERR,"Plusieurs comptes SUPER_ADMIN existent. Choisissez celui a conserver :\n");
        foreach($superAdmins as $candidate){
            $name=trim(implode(' ',array_filter([$candidate['prenom'],$candidate['nom'],$candidate['postnom']])));
            fwrite(STDERR,sprintf(
                "  #%d - %s - %s - %s\n",
                $candidate['id'],$candidate['identifiant'],$candidate['email']?:'sans e-mail',$name
            ));
        }
        fwrite(STDERR,"Relancez avec --keep-user=ID.\n");
        exit(2);
    }

    $keepUserId=(int)$selected['id'];
    $superRoleId=(int)$pdo->query("SELECT id FROM roles WHERE code='SUPER_ADMIN' LIMIT 1")->fetchColumn();
    if($superRoleId<1)throw new RuntimeException('Role SUPER_ADMIN introuvable.');

    /* Referentiels indispensables qui ne sont pas des donnees de test. */
    $preserved=[
        'academic_cycles',
        'academic_levels',
        'academic_structure_templates',
        'academic_structure_template_unit_types',
        'academic_unit_types',
        'administration_migrations',
        'bibliotheque_categories',
        'communication_migrations',
        'curriculum_references',
        'establishment_types',
        'geographie_localisations',
        'geographie_unites',
        'geographie_version',
        'host_unit_templates',
        'permissions',
        'role_permissions',
        'roles',
        'stage_type_policies',
        'stage_types',
        'system_settings',
        'users',
        'role_assignments',
    ];

    $s=$pdo->query("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'
        ORDER BY TABLE_NAME
    ");
    $allTables=$s->fetchAll(PDO::FETCH_COLUMN);
    $purgedTables=array_values(array_filter(
        array_diff($allTables,$preserved),
        static fn(string $table):bool=>$table!=='migrations'&&!str_ends_with($table,'_migrations')
    ));

    $tableCounts=[];
    $totalRows=0;
    foreach($purgedTables as $table){
        $count=(int)$pdo->query('SELECT COUNT(*) FROM '.quotedIdentifier($table))->fetchColumn();
        if($count>0){$tableCounts[$table]=$count;$totalRows+=$count;}
    }
    $otherUsers=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE id<>$keepUserId")->fetchColumn();

    echo "Base cible : {$database}\n";
    echo "Compte conserve : #{$keepUserId} - {$selected['identifiant']} - ".($selected['email']?:'sans e-mail')."\n";
    echo "Comptes utilisateurs supprimes : {$otherUsers}\n";
    echo 'Tables metier a vider : '.count($purgedTables)." (environ {$totalRows} ligne(s))\n";
    foreach($tableCounts as $table=>$count)echo "  - {$table}: {$count}\n";

    if(!$execute){
        echo "\nSIMULATION UNIQUEMENT : aucune donnee n'a ete modifiee.\n";
        echo "Apres sauvegarde, executez :\n";
        echo "  php bin/reset-database.php --execute --keep-user={$keepUserId}\n";
        exit(0);
    }

    $confirmation="PURGER {$database} EN GARDANT {$keepUserId}";
    echo "\nCette operation est irreversible et peut toucher des tables MyISAM.\n";
    echo "Saisissez exactement : {$confirmation}\n> ";
    $answer=trim((string)fgets(STDIN));
    if(!hash_equals($confirmation,$answer)){
        fwrite(STDERR,"Confirmation incorrecte. Nettoyage annule.\n");
        exit(3);
    }

    $foreignKeyChecks=(int)$pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
    $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=0');

    try{
        $pdo->beginTransaction();

        foreach($purgedTables as $table)
            $pdo->exec('DELETE FROM '.quotedIdentifier($table));

        /* Les types de stage propres aux anciens etablissements sont des
         * donnees metier; seuls les types globaux restent disponibles. */
        if(tableExists($pdo,'stage_type_policies')&&tableExists($pdo,'stage_types'))
            $pdo->exec('DELETE p FROM stage_type_policies p LEFT JOIN stage_types st ON st.id=p.stage_type_id WHERE st.id IS NULL OR st.owner_etablissement_id IS NOT NULL');
        if(tableExists($pdo,'stage_types'))
            $pdo->exec('DELETE FROM stage_types WHERE owner_etablissement_id IS NOT NULL');

        /* Les affectations sont recréées ensuite pour éviter de conserver
         * une portée, un établissement ou un auteur devenu inexistant. */
        if(tableExists($pdo,'role_assignments'))
            $pdo->exec('DELETE FROM role_assignments');

        $deleteUsers=$pdo->prepare('DELETE FROM users WHERE id<>?');
        $deleteUsers->execute([$keepUserId]);

        $activateAdmin=$pdo->prepare("
            UPDATE users
            SET role_id=?,actif=1,statut_compte='ACTIF',
                activation_token_hash=NULL,activation_expire_at=NULL,activation_token=NULL
            WHERE id=?
        ");
        $activateAdmin->execute([$superRoleId,$keepUserId]);

        if(tableExists($pdo,'role_assignments'))
            $pdo->prepare("
                INSERT INTO role_assignments(
                    user_id,role_id,scope_type,scope_entity,scope_id,
                    etablissement_id,principal,actif
                ) VALUES(?,?,'PLATFORM','PLATFORM',NULL,NULL,1,1)
            ")->execute([$keepUserId,$superRoleId]);

        /* Supprimer les roles locaux lies aux etablissements effaces. */
        if(columnExists($pdo,'roles','systeme')){
            $pdo->exec('DELETE rp FROM role_permissions rp LEFT JOIN roles r ON r.id=rp.role_id WHERE r.id IS NULL OR r.systeme=0');
            $pdo->exec('DELETE FROM roles WHERE systeme=0');
        }

        foreach([
            ['system_settings','updated_by'],
            ['establishment_types','created_by_user_id'],
            ['academic_structure_templates','created_by_user_id'],
            ['academic_unit_types','created_by_user_id'],
            ['stage_types','created_by_user_id'],
        ] as [$table,$column]){
            if(tableExists($pdo,$table)&&columnExists($pdo,$table,$column))
                $pdo->exec('UPDATE '.quotedIdentifier($table).' SET '.quotedIdentifier($column).'=NULL WHERE '.quotedIdentifier($column).' IS NOT NULL AND '.quotedIdentifier($column).'<>'.$keepUserId);
        }

        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }finally{
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS='.$foreignKeyChecks);
    }

    $remainingUsers=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $remainingEstablishments=tableExists($pdo,'etablissements')
        ?(int)$pdo->query('SELECT COUNT(*) FROM etablissements')->fetchColumn()
        :0;

    if($remainingUsers!==1||$remainingEstablishments!==0)
        throw new RuntimeException("Verification finale echouee : {$remainingUsers} utilisateur(s), {$remainingEstablishments} etablissement(s).");

    echo "\nNettoyage termine.\n";
    echo "Utilisateurs restants : {$remainingUsers}\n";
    echo "Etablissements restants : {$remainingEstablishments}\n";
    echo "Compte SUPER_ADMIN conserve : #{$keepUserId} ({$selected['identifiant']})\n";
}catch(Throwable $e){
    fwrite(STDERR,"Echec du nettoyage : {$e->getMessage()}\n");
    exit(1);
}
